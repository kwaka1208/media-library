<?php
/**
 * Media Library : ログイン画面・Googleからの戻り先・ログアウト
 *
 * この1つのファイルで3つの役割をこなす。
 *
 *   ログイン画面   … そのまま開いたとき
 *   Googleの戻り先 … code（または error）が付いて戻ってきたとき
 *   ログアウト     … POST で action=logout が送られたとき
 *
 * Google Cloud Console に登録するリダイレクトURIは、このファイルのURL1本で済む。
 * 設定の手順は docs/google-auth.md を参照。
 */

$config = require __DIR__ . '/config.php';
require __DIR__ . '/lib/auth.php';

pv_session_start();

$authConfig = pv_auth_config();

// 認証を使わない設定のときは、この画面に用はない
if ($authConfig === null) {
    header('Location: ./', true, 303);
    exit;
}

// ---- ログインの開始・ログアウト（POST）----------------------------
// 他所のサイトに置いたフォームから勝手に開始・終了させられないよう、
// 一覧の操作と同じCSRFトークンを通す。

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!pv_csrf_check($_POST['token'] ?? null)) {
        pv_flash('error', '操作を受け付けられませんでした。画面を読み込み直してから、もう一度お試しください。');
        header('Location: ./login.php', true, 303);
        exit;
    }

    switch ((string) ($_POST['action'] ?? '')) {
        case 'start':
            // Googleのログイン画面へ送り出す（この中で終わる）
            pv_auth_begin($authConfig);
            break;

        case 'logout':
            pv_auth_logout();
            pv_flash('ok', 'ログアウトしました。');
            break;
    }

    header('Location: ./login.php', true, 303);
    exit;
}

// ---- Googleからの戻り ---------------------------------------------

if (isset($_GET['code']) || isset($_GET['error'])) {
    $result = pv_auth_callback($authConfig);

    if ($result['ok']) {
        // 開こうとしていた画面へ戻す（無ければ一覧のトップ）
        header('Location: ' . pv_auth_take_return(), true, 303);
        exit;
    }

    // 失敗したときは、認可コードがURLに残らないよう、この画面へ戻してから知らせる
    pv_flash('error', $result['message']);
    header('Location: ./login.php', true, 303);
    exit;
}

// ---- ログイン済みなら、もとの画面へ --------------------------------

if (pv_auth_user() !== null) {
    header('Location: ' . pv_auth_take_return(), true, 303);
    exit;
}

// ---- ログイン画面 --------------------------------------------------

$token    = pv_csrf_token();
$messages = pv_take_flash();

// ログイン画面は検索結果に出したくないので、キャッシュもさせない
header('Cache-Control: no-store');
?>
<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= h('ログイン | ' . $config['title']) ?></title>
<link rel="icon" href="assets/favicon.svg?v=1" type="image/svg+xml">
<link rel="icon" href="assets/favicon-32.png?v=1" sizes="32x32" type="image/png">
<link rel="apple-touch-icon" href="assets/apple-touch-icon.png?v=1">
<link rel="stylesheet" href="assets/style.css?v=31">
</head>
<body>

<div class="page-header">
    <header class="header">
        <h1 class="site-title"><?= h($config['title']) ?></h1>
    </header>
</div>

<main class="content">
<div class="login">

<?php foreach ($messages as $message): ?>
    <p class="notice <?= $message['type'] === 'ok' ? 'ok' : 'error' ?>"><?= h($message['message']) ?></p>
<?php endforeach; ?>

    <p class="login-lead">
        このライブラリを見るには、登録されたGoogleアカウントでのログインが必要です。
    </p>

    <form method="post" action="login.php">
        <input type="hidden" name="token" value="<?= h($token) ?>">
        <input type="hidden" name="action" value="start">

        <button type="submit" class="button primary login-button">Googleでログイン</button>
    </form>

    <p class="login-note">
        ログインできない場合は、このライブラリの管理者にアカウントの登録を依頼してください。
    </p>

</div><!-- /.login -->
</main>

</body>
</html>
