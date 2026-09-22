<?php
/**
 * Media Library : Googleアカウントでのログイン
 *
 * OAuth 2.0 の認可コードフロー（PKCE付き）を、外部ライブラリなしで実装している。
 * クライアントIDなどの設定は auth-config.php に置く。
 * このファイルは .gitignore に入れてあるので、リポジトリには含まれない。
 * 設定ファイルが無いときは認証を使わない扱いになり、今までどおり動く。
 *
 * 【守れる範囲】
 * ここで守れるのは、PHPを通る画面だけ。写真・動画そのものは photos/ movies/ から
 * 直接配信されるため、URLを知っている人は未ログインでも開ける。
 * ファイル本体まで隠したい場合は docs/cloudflare-access.md を参照。
 */

require_once __DIR__ . '/functions.php';

// 設定ファイルの置き場所
const PV_AUTH_CONFIG_FILE = __DIR__ . '/../auth-config.php';

// Google のエンドポイント
const PV_AUTH_ENDPOINT_AUTH  = 'https://accounts.google.com/o/oauth2/v2/auth';
const PV_AUTH_ENDPOINT_TOKEN = 'https://oauth2.googleapis.com/token';

// id_token の発行元（iss）として認める値
const PV_AUTH_ISSUERS = ['https://accounts.google.com', 'accounts.google.com'];

// 時計のずれを見込んで、期限の判定に持たせる余裕（秒）
const PV_AUTH_LEEWAY = 60;

// ログインを保つ長さの既定値（秒）。設定に書かれていないときに使う。
const PV_AUTH_DEFAULT_LIFETIME = 30 * 24 * 60 * 60;

/**
 * 認証の設定を読む（1回だけ読んで、以降は同じものを返す）。
 *
 * 次のときは null を返し、「認証を使わない」という扱いにする。
 *   - auth-config.php が置かれていない
 *   - enabled が false
 *
 * 設定はあるのに中身が足りないときは、素通りさせずにその場で止める。
 * 「認証しているつもりで、実は誰でも入れる」状態がいちばん危ないため。
 */
function pv_auth_config(): ?array
{
    static $config = null;
    static $loaded = false;

    if ($loaded) {
        return $config;
    }

    $loaded = true;

    if (!is_file(PV_AUTH_CONFIG_FILE)) {
        return null;
    }

    $loadedConfig = require PV_AUTH_CONFIG_FILE;

    if (!is_array($loadedConfig) || empty($loadedConfig['enabled'])) {
        return null;
    }

    foreach (['client_id', 'client_secret', 'redirect_uri'] as $key) {
        if (trim((string) ($loadedConfig[$key] ?? '')) === '') {
            pv_auth_halt(
                'auth-config.php の ' . $key . ' が空のままです。'
                . 'Google Cloud Console で取得した値を書き込むか、enabled を false にしてください。'
            );
        }
    }

    $loadedConfig['client_id']     = trim((string) $loadedConfig['client_id']);
    $loadedConfig['client_secret'] = trim((string) $loadedConfig['client_secret']);
    $loadedConfig['redirect_uri']  = trim((string) $loadedConfig['redirect_uri']);

    $loadedConfig['allowed_emails']  = pv_auth_normalize_list($loadedConfig['allowed_emails'] ?? []);
    $loadedConfig['allowed_domains'] = pv_auth_normalize_list($loadedConfig['allowed_domains'] ?? []);

    $lifetime = (int) ($loadedConfig['session_lifetime'] ?? PV_AUTH_DEFAULT_LIFETIME);
    $loadedConfig['session_lifetime'] = $lifetime > 0 ? $lifetime : PV_AUTH_DEFAULT_LIFETIME;

    $config = $loadedConfig;

    return $config;
}

/**
 * 設定に書かれたメールアドレス・ドメインの一覧を、比較できる形にそろえる。
 * 前後の空白を落とし、小文字にし、空の行を捨てる。
 * ドメインは「@example.com」と書かれていても通るよう、先頭の @ を外す。
 */
function pv_auth_normalize_list($list): array
{
    if (!is_array($list)) {
        return [];
    }

    $normalized = [];

    foreach ($list as $one) {
        if (!is_string($one)) {
            continue;
        }

        $one = ltrim(strtolower(trim($one)), '@');

        if ($one !== '') {
            $normalized[] = $one;
        }
    }

    return array_values(array_unique($normalized));
}

/**
 * 認証を使う設定になっているか。
 */
function pv_auth_enabled(): bool
{
    return pv_auth_config() !== null;
}

/**
 * そのメールアドレスを通してよいか。
 *
 * allowed_emails と allowed_domains は「どちらかに当てはまれば通す」。
 * 両方とも空のときは、誰も通さない。設定を書き忘れたまま公開されるより、
 * 自分が入れなくなるほうが安全なため。
 */
function pv_auth_is_allowed(array $config, string $email): bool
{
    $email = strtolower(trim($email));

    if ($email === '') {
        return false;
    }

    $emails  = $config['allowed_emails'];
    $domains = $config['allowed_domains'];

    if ($emails === [] && $domains === []) {
        return false;
    }

    if (in_array($email, $emails, true)) {
        return true;
    }

    $at = strrpos($email, '@');

    if ($at === false) {
        return false;
    }

    $domain = substr($email, $at + 1);

    return $domain !== '' && in_array($domain, $domains, true);
}

/**
 * ログイン中の人を返す。ログインしていなければ null。
 *
 * 併せて次の2つをここで面倒みる。
 *   - 期限切れの追い出し（最後に開いてから session_lifetime が過ぎたもの）
 *   - 設定から外されたアカウントの追い出し（次に開いたときに効く）
 */
function pv_auth_user(): ?array
{
    $config = pv_auth_config();

    if ($config === null || session_status() !== PHP_SESSION_ACTIVE) {
        return null;
    }

    $user = $_SESSION['pv_auth'] ?? null;

    if (!is_array($user) || empty($user['email'])) {
        return null;
    }

    $seen = (int) ($user['seen'] ?? 0);
    $now  = time();

    if ($seen <= 0 || $seen + $config['session_lifetime'] < $now) {
        unset($_SESSION['pv_auth']);

        return null;
    }

    if (!pv_auth_is_allowed($config, (string) $user['email'])) {
        unset($_SESSION['pv_auth']);

        return null;
    }

    // 開いている間はログインが続くよう、最後に開いた時刻を更新する。
    // 毎回書き換える必要はないので、1分に1回までにしておく。
    if ($seen + 60 < $now) {
        $_SESSION['pv_auth']['seen'] = $now;
    }

    return $user;
}

/**
 * 画面を開くのにログインを必須にする。
 * ログインしていなければ、ログイン画面へ送る。
 */
function pv_auth_require(): void
{
    if (!pv_auth_enabled()) {
        return;
    }

    pv_session_start();

    if (pv_auth_user() !== null) {
        return;
    }

    // ログインが済んだら、開こうとしていた画面に戻れるようにしておく
    $_SESSION['pv_auth_return'] = pv_auth_safe_return((string) ($_SERVER['REQUEST_URI'] ?? ''));

    header('Location: ./login.php', true, 303);
    exit;
}

/**
 * JSON を返す受け口（browse.php・upload.php）でログインを必須にする。
 * 画面を切り替えるわけにはいかないので、401 を返して終わる。
 */
function pv_auth_require_json(): void
{
    if (!pv_auth_enabled()) {
        return;
    }

    pv_session_start();

    if (pv_auth_user() !== null) {
        return;
    }

    http_response_code(401);
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store');

    echo json_encode([
        'ok'      => false,
        'message' => 'ログインの有効期限が切れました。画面を読み込み直して、ログインし直してください。',
    ], JSON_UNESCAPED_UNICODE);

    exit;
}

/**
 * Cloudflare Access が渡してくる、ログイン中のメールアドレス。
 *
 * サーバーの手前に Cloudflare Access を置いている場合、認証を通した
 * リクエストにこのヘッダが付く（→ docs/cloudflare-access.md）。
 * ツールに組み込んだログインを使っていないときでも、これを読めば
 * 「誰として見ているか」を画面に出せる。
 *
 * このヘッダを信用してよいのは、.htaccess で Cloudflare 経由以外の
 * アクセスを拒否しているため（→ docs/cloudflare-access.md の手順5）。
 * その設定がない場所では、誰でも名乗れてしまう。そのため、ここで得た値は
 * 画面に出すだけに使い、通す・通さないの判断には使わない。
 */
function pv_auth_access_email(): string
{
    $email = (string) ($_SERVER['HTTP_CF_ACCESS_AUTHENTICATED_USER_EMAIL'] ?? '');

    // 妙な値が入っていても、画面に出すのはメールアドレスの形のものだけにする
    if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
        return '';
    }

    return $email;
}

/**
 * 戻り先として受け付けてよいURLかを確かめる。
 *
 * 受け付けるのは「/」で始まる自サイト内のパスだけ。
 * 「//example.com」や「/\example.com」は、ブラウザが別サイトとして解釈するため弾く。
 * 改行が混ざったものも、レスポンスヘッダーを壊されるので弾く。
 */
function pv_auth_safe_return(string $url): string
{
    if ($url === '' || $url[0] !== '/') {
        return '';
    }

    if (isset($url[1]) && ($url[1] === '/' || $url[1] === '\\')) {
        return '';
    }

    if (strpbrk($url, "\r\n") !== false) {
        return '';
    }

    return $url;
}

/**
 * 保存しておいた戻り先を取り出す（取り出すと消える）。
 * 無ければ一覧のトップを返す。
 */
function pv_auth_take_return(): string
{
    $url = (string) ($_SESSION['pv_auth_return'] ?? '');
    unset($_SESSION['pv_auth_return']);

    $url = pv_auth_safe_return($url);

    return $url === '' ? './' : $url;
}

/**
 * Googleのログイン画面へ送り出す。
 *
 * state は「戻ってきた要求が、自分が送り出したものか」を確かめるためのもの。
 * nonce は、受け取った id_token が今回のログインのものかを確かめるためのもの。
 * code_verifier（PKCE）は、認可コードを横取りされても使えないようにするためのもの。
 * いずれも作った値はセッションに控え、戻ってきたときに突き合わせる。
 */
function pv_auth_begin(array $config): void
{
    pv_session_start();

    $state    = bin2hex(random_bytes(16));
    $nonce    = bin2hex(random_bytes(16));
    $verifier = bin2hex(random_bytes(32));

    $_SESSION['pv_auth_state']    = $state;
    $_SESSION['pv_auth_nonce']    = $nonce;
    $_SESSION['pv_auth_verifier'] = $verifier;

    $params = [
        'client_id'             => $config['client_id'],
        'redirect_uri'          => $config['redirect_uri'],
        'response_type'         => 'code',
        'scope'                 => 'openid email profile',
        'state'                 => $state,
        'nonce'                 => $nonce,
        'code_challenge'        => pv_auth_base64url(hash('sha256', $verifier, true)),
        'code_challenge_method' => 'S256',
        // 閲覧のたびにGoogleへ問い合わせる必要はないので、更新用のトークンは受け取らない
        'access_type'           => 'online',
        // 複数のGoogleアカウントを使い分けている人のために、選ぶ画面を出す
        'prompt'                => 'select_account',
    ];

    header('Location: ' . PV_AUTH_ENDPOINT_AUTH . '?' . http_build_query($params), true, 303);
    exit;
}

/**
 * Googleから戻ってきたところを受け止める。
 * 成功すれば、その人をログイン済みとしてセッションに記録する。
 *
 * 返すのは ['ok' => bool, 'message' => string]。
 * 失敗の理由は、画面に出しても差し支えのない範囲にとどめる。
 */
function pv_auth_callback(array $config): array
{
    pv_session_start();

    $state    = (string) ($_SESSION['pv_auth_state'] ?? '');
    $nonce    = (string) ($_SESSION['pv_auth_nonce'] ?? '');
    $verifier = (string) ($_SESSION['pv_auth_verifier'] ?? '');

    // 1回のログインで1回だけ使う値なので、成否にかかわらずここで捨てる
    unset($_SESSION['pv_auth_state'], $_SESSION['pv_auth_nonce'], $_SESSION['pv_auth_verifier']);

    // Google側で中断された（同意しなかった、アカウントを選ばなかった など）
    if (isset($_GET['error'])) {
        return ['ok' => false, 'message' => 'ログインは完了しませんでした。もう一度お試しください。'];
    }

    $code = (string) ($_GET['code'] ?? '');

    if ($code === '' || $state === '' || !hash_equals($state, (string) ($_GET['state'] ?? ''))) {
        return [
            'ok'      => false,
            'message' => 'ログインの手続きを確認できませんでした。'
                . '時間がたちすぎたか、途中で別の画面を挟んだ可能性があります。もう一度お試しください。',
        ];
    }

    $token = pv_auth_post(PV_AUTH_ENDPOINT_TOKEN, [
        'code'          => $code,
        'client_id'     => $config['client_id'],
        'client_secret' => $config['client_secret'],
        'redirect_uri'  => $config['redirect_uri'],
        'grant_type'    => 'authorization_code',
        'code_verifier' => $verifier,
    ]);

    if ($token === null || empty($token['id_token']) || !is_string($token['id_token'])) {
        return [
            'ok'      => false,
            'message' => 'Googleとのやりとりに失敗しました。'
                . '時間をおいても直らない場合は、auth-config.php の設定を確認してください。',
        ];
    }

    $claims = pv_auth_read_id_token($token['id_token'], $config, $nonce);

    if ($claims === null) {
        return ['ok' => false, 'message' => 'ログイン情報を確認できませんでした。もう一度お試しください。'];
    }

    $email = strtolower(trim((string) ($claims['email'] ?? '')));

    // Googleが「本人のものだと確認できている」と言わないアドレスは受け付けない
    if ($email === '' || empty($claims['email_verified'])) {
        return ['ok' => false, 'message' => 'メールアドレスを確認できないアカウントでは、ログインできません。'];
    }

    if (!pv_auth_is_allowed($config, $email)) {
        return [
            'ok'      => false,
            'message' => $email . ' は、このライブラリを見られるアカウントとして登録されていません。',
        ];
    }

    // ログインの前後でセッションIDを変える（他人が用意したIDを引き継がせないため）
    session_regenerate_id(true);

    $_SESSION['pv_auth'] = [
        'email' => $email,
        'name'  => (string) ($claims['name'] ?? ''),
        'seen'  => time(),
    ];

    return ['ok' => true, 'message' => ''];
}

/**
 * ログアウトする。
 */
function pv_auth_logout(): void
{
    pv_session_start();

    unset(
        $_SESSION['pv_auth'],
        $_SESSION['pv_auth_return'],
        $_SESSION['pv_auth_state'],
        $_SESSION['pv_auth_nonce'],
        $_SESSION['pv_auth_verifier']
    );

    session_regenerate_id(true);
}

/**
 * id_token の中身を取り出して、確かめる。おかしければ null。
 *
 * ここで署名を検証していないのは、この id_token が「認可コードと引き換えに
 * GoogleからTLS越しに直接受け取ったもの」だから。
 * 途中で誰かが差し替えられる経路を通っていないので、iss / aud / exp / nonce の
 * 確認だけでよいと OpenID Connect Core 3.1.3.7 が認めている。
 * （ブラウザ経由で id_token を受け取る形にするなら、署名の検証が要る）
 */
function pv_auth_read_id_token(string $idToken, array $config, string $nonce): ?array
{
    $parts = explode('.', $idToken);

    if (count($parts) !== 3) {
        return null;
    }

    $payload = strtr($parts[1], '-_', '+/');
    $padding = strlen($payload) % 4;

    if ($padding > 0) {
        $payload .= str_repeat('=', 4 - $padding);
    }

    $json = base64_decode($payload, true);

    if ($json === false) {
        return null;
    }

    $claims = json_decode($json, true);

    if (!is_array($claims)) {
        return null;
    }

    // 発行元がGoogleであること
    if (!in_array((string) ($claims['iss'] ?? ''), PV_AUTH_ISSUERS, true)) {
        return null;
    }

    // 宛先が、自分のクライアントIDであること（他所のアプリ向けの使い回しを防ぐ）
    if (!hash_equals($config['client_id'], (string) ($claims['aud'] ?? ''))) {
        return null;
    }

    // 期限が切れていないこと
    $expires = (int) ($claims['exp'] ?? 0);

    if ($expires <= 0 || $expires + PV_AUTH_LEEWAY < time()) {
        return null;
    }

    // 今回のログインで送り出したものであること（使い回しを防ぐ）
    if ($nonce === '' || !hash_equals($nonce, (string) ($claims['nonce'] ?? ''))) {
        return null;
    }

    return $claims;
}

/**
 * Googleのエンドポイントへ POST して、返ってきた JSON を配列で返す。
 * 失敗したときは null。
 *
 * cURL が使えればそちらを、無ければ file_get_contents を使う。
 * レンタルサーバーによってどちらかが塞がれていることがあるため。
 */
function pv_auth_post(string $url, array $params): ?array
{
    $body = http_build_query($params);
    $raw  = null;

    if (function_exists('curl_init')) {
        $curl = curl_init($url);

        curl_setopt_array($curl, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/x-www-form-urlencoded',
                'Accept: application/json',
            ],
        ]);

        $result = curl_exec($curl);
        curl_close($curl);

        if (is_string($result)) {
            $raw = $result;
        }
    } elseif (ini_get('allow_url_fopen')) {
        $context = stream_context_create([
            'http' => [
                'method'        => 'POST',
                'header'        => "Content-Type: application/x-www-form-urlencoded\r\nAccept: application/json\r\n",
                'content'       => $body,
                'timeout'       => 15,
                // エラー応答（400番台）でも中身を読めるようにする
                'ignore_errors' => true,
            ],
            'ssl' => [
                'verify_peer'      => true,
                'verify_peer_name' => true,
            ],
        ]);

        // 失敗は戻り値で判断するので、警告は出させない
        $result = @file_get_contents($url, false, $context);

        if (is_string($result)) {
            $raw = $result;
        }
    }

    if ($raw === null) {
        return null;
    }

    $data = json_decode($raw, true);

    return is_array($data) ? $data : null;
}

/**
 * URLに混ぜても壊れない形（base64url）にする。
 */
function pv_auth_base64url(string $binary): string
{
    return rtrim(strtr(base64_encode($binary), '+/', '-_'), '=');
}

/**
 * 設定の不備を知らせて止める。
 *
 * 出すのは足りない項目の名前だけで、設定に書かれた値そのものは出さない。
 * クライアントシークレットが画面に出てしまうのを防ぐため。
 */
function pv_auth_halt(string $message): void
{
    http_response_code(500);
    header('Content-Type: text/html; charset=UTF-8');
    header('Cache-Control: no-store');

    echo '<!DOCTYPE html><html lang="ja"><head><meta charset="UTF-8">'
        , '<meta name="viewport" content="width=device-width, initial-scale=1">'
        , '<title>設定を確認してください</title></head><body>'
        , '<h1>設定を確認してください</h1><p>', h($message), '</p>'
        , '<p>詳しくは docs/google-auth.md をご覧ください。</p>'
        , '</body></html>';

    exit;
}
