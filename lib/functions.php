<?php
/**
 * Media Library 共通関数
 */

require_once __DIR__ . '/yaml.php';

// フォルダに置くと、そのフォルダの情報として表示されるファイルの名前
const PV_INFO_FILE = 'info.yml';

// info.yml の thumbnail にこう書くと、そのフォルダ以下から1枚を選んで出す
const PV_INFO_RANDOM = 'random';

/**
 * URLで指定されたルート（写真・動画）の名前を確かめる。
 * 知らない名前が来たときは、既定のルートに落とす。
 */
function pv_root_key(array $config, $requested): string
{
    $roots = $config['roots'];

    if (is_string($requested) && isset($roots[$requested])) {
        return $requested;
    }

    $default = (string) ($config['default_root'] ?? '');

    return isset($roots[$default]) ? $default : (string) array_key_first($roots);
}

/**
 * 選んだルートの設定を、$config の album_dir / album_url / extensions に写して返す。
 * 以降の処理（一覧の走査も、名前の変更や移動も）は、この1件だけを見ればよくなる。
 */
function pv_apply_root(array $config, string $rootKey): array
{
    $root = $config['roots'][$rootKey];

    $config['root']       = $rootKey;
    $config['album_dir']  = $root['dir'];
    $config['album_url']  = $root['url'];
    $config['extensions'] = $root['extensions'];
    $config['root_label'] = $root['label'];
    $config['root_unit']  = $root['unit'] ?? '件';
    $config['root_kind']  = $root['kind'] ?? 'image';

    return $config;
}

/**
 * 動画として扱う拡張子の一覧。kind が video のルートの設定をまとめて返す。
 * 写真のフォルダに動画が混ざっていても、再生できる形で表示するために使う。
 */
function pv_video_extensions(array $config): array
{
    $extensions = [];

    foreach ($config['roots'] as $root) {
        if (($root['kind'] ?? 'image') === 'video') {
            $extensions = array_merge($extensions, $root['extensions']);
        }
    }

    return array_values(array_unique($extensions));
}

/**
 * 画像として扱う拡張子の一覧。kind が image のルートの設定をまとめて返す。
 * info.yml のサムネイルは、動画のフォルダでも画像を指すため、
 * いま開いているルートに関係なく、この一覧で確かめる。
 */
function pv_image_extensions(array $config): array
{
    $extensions = [];

    foreach ($config['roots'] as $root) {
        if (($root['kind'] ?? 'image') === 'image') {
            $extensions = array_merge($extensions, $root['extensions']);
        }
    }

    return array_values(array_unique($extensions));
}

/**
 * ファイル名を見て、動画かどうかを判定する。
 */
function pv_is_video(array $config, string $name): bool
{
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));

    return in_array($ext, pv_video_extensions($config), true);
}

/**
 * 画像ルートからの相対パスを検証し、実在するファイルまたはディレクトリの
 * 絶対パスを返す。次のいずれかに当たる場合は null を返す。
 *
 *   - ルート外を指している（../ による脱出、ルート外へのシンボリックリンク）
 *   - 実在しない
 *   - 途中に隠しファイル・隠しフォルダ（. で始まる名前）を含む
 *
 * 隠し名を弾いているのは、一覧に出ないもの（ゴミ箱や .htaccess）を
 * URLの打ち込みだけで開いたり操作したりできないようにするため。
 */
function pv_resolve_path(string $root, string $relative): ?string
{
    $realRoot = realpath($root);
    if ($realRoot === false || !is_dir($realRoot)) {
        return null;
    }

    $relative = str_replace('\\', '/', $relative);
    $relative = trim($relative, '/');

    if ($relative === '') {
        return $realRoot;
    }

    foreach (explode('/', $relative) as $segment) {
        if ($segment === '' || $segment[0] === '.') {
            return null;
        }
    }

    $target = realpath($realRoot . '/' . $relative);
    if ($target === false) {
        return null;
    }

    // realpath 後にルート配下であることを必ず確認する（シンボリックリンク対策も兼ねる）
    if ($target !== $realRoot && strpos($target, $realRoot . DIRECTORY_SEPARATOR) !== 0) {
        return null;
    }

    return $target;
}

/**
 * 相対パスを検証し、実在するディレクトリの絶対パスを返す。
 */
function pv_resolve_dir(string $root, string $relative): ?string
{
    $target = pv_resolve_path($root, $relative);

    return ($target !== null && is_dir($target)) ? $target : null;
}

/**
 * 相対パスを検証し、実在する画像ファイルの絶対パスを返す。
 * 一覧に表示されないファイル（対象外の拡張子）は操作できないようにするため、
 * 拡張子もここで確かめる。
 */
function pv_resolve_file(string $root, string $relative, array $extensions): ?string
{
    $target = pv_resolve_path($root, $relative);
    if ($target === null || !is_file($target)) {
        return null;
    }

    $ext = strtolower(pathinfo($target, PATHINFO_EXTENSION));

    return in_array($ext, $extensions, true) ? $target : null;
}

/**
 * 相対パスを正規化する。表示・リンク生成用。
 */
function pv_normalize_relative(string $relative): string
{
    $relative = str_replace('\\', '/', $relative);
    $segments = [];

    foreach (explode('/', $relative) as $segment) {
        if ($segment === '' || $segment === '.' || $segment === '..') {
            continue;
        }
        $segments[] = $segment;
    }

    return implode('/', $segments);
}

/**
 * ディレクトリを走査し、サブフォルダと画像ファイルの一覧を返す。
 * 隠しファイル（. で始まる名前）は除外する。
 */
function pv_scan(string $dir, array $extensions): array
{
    $dirs = [];
    $files = [];

    $handle = @opendir($dir);
    if ($handle === false) {
        return ['dirs' => $dirs, 'files' => $files];
    }

    while (($name = readdir($handle)) !== false) {
        if ($name === '.' || $name === '..' || $name[0] === '.') {
            continue;
        }

        $path = $dir . '/' . $name;

        if (is_dir($path)) {
            $dirs[] = [
                'name'  => $name,
                'mtime' => filemtime($path) ?: 0,
                'size'  => 0,
                'count' => pv_count_images($path, $extensions),
            ];
            continue;
        }

        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if (!in_array($ext, $extensions, true)) {
            continue;
        }

        $files[] = [
            'name'  => $name,
            'mtime' => filemtime($path) ?: 0,
            'size'  => filesize($path) ?: 0,
        ];
    }

    closedir($handle);

    return ['dirs' => $dirs, 'files' => $files];
}

/**
 * フォルダ直下にある画像とサブフォルダの数を数える（一覧のバッジ表示用）。
 */
function pv_count_images(string $dir, array $extensions): int
{
    $count = 0;
    $handle = @opendir($dir);
    if ($handle === false) {
        return 0;
    }

    while (($name = readdir($handle)) !== false) {
        if ($name === '.' || $name === '..' || $name[0] === '.') {
            continue;
        }

        if (is_dir($dir . '/' . $name)) {
            $count++;
            continue;
        }

        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if (in_array($ext, $extensions, true)) {
            $count++;
        }
    }

    closedir($handle);

    return $count;
}

/**
 * フォルダに置かれた info.yml を読み、表示に使う形に整えて返す。
 * ファイルがない・読めない・中身が空のときは null を返す。
 *
 * 返り値
 *   title … 見出しにする名前（無いこともある）
 *   thumb … ['path' => ルートからの相対パス, 'url' => 表示に使うURL,
 *            'random' => 毎回選び直したものか,
 *            'randomFrom' => 選ぶ範囲（null なら info.yml のあるフォルダ以下）] か null
 *   items … [['item' => 見出し, 'value' => 中身, 'url' => リンク先か null], …]
 */
function pv_read_info(array $config, string $relative): ?array
{
    $dir = pv_resolve_dir($config['album_dir'], $relative);

    if ($dir === null) {
        return null;
    }

    $path = $dir . '/' . PV_INFO_FILE;

    if (!is_file($path) || !is_readable($path)) {
        return null;
    }

    // 情報の書かれた小さなファイルのはずなので、大きすぎるものは読まない
    $size = filesize($path);

    if ($size === false || $size > 64 * 1024) {
        return null;
    }

    $raw = file_get_contents($path);

    if ($raw === false) {
        return null;
    }

    $data = pv_yaml_parse($raw);

    $info = [
        'title' => trim((string) ($data['title'] ?? '')),
        'thumb' => pv_info_thumb($config, $relative, (string) ($data['thumbnail'] ?? '')),
        'items' => pv_info_items($data['items'] ?? []),
    ];

    // 表示できるものが何も無いなら、ファイルが無いのと同じ扱いにする
    if ($info['title'] === '' && $info['thumb'] === null && $info['items'] === []) {
        return null;
    }

    return $info;
}

/**
 * info.yml の thumbnail を、実在する画像として確かめる。
 *
 * 書き方は2とおり。まず info.yml と同じフォルダにあるものとして探し、
 * 見つからなければ、写真（動画）ルートからの相対パスとして探す。
 * どちらの場合も pv_resolve_file() を通すので、ルートの外や隠しファイルは指せない。
 */
function pv_info_thumb(array $config, string $relative, string $name): ?array
{
    $name = trim($name);

    if ($name === '') {
        return null;
    }

    if (strtolower($name) === PV_INFO_RANDOM) {
        return pv_random_thumb($config, $relative, null);
    }

    // random:フォルダ … 選ぶ範囲を決めた書き方。
    // 「random:」だけならホーム全体から選ぶ。
    $prefix = PV_INFO_RANDOM . ':';

    if (strncasecmp($name, $prefix, strlen($prefix)) === 0) {
        return pv_random_thumb(
            $config,
            $relative,
            pv_normalize_relative(substr($name, strlen($prefix)))
        );
    }

    $extensions = pv_image_extensions($config);
    $candidates = [];

    if ($relative !== '') {
        $candidates[] = $relative . '/' . $name;
    }

    $candidates[] = $name;

    foreach ($candidates as $candidate) {
        $normalized = pv_normalize_relative($candidate);

        if ($normalized === '') {
            continue;
        }

        if (pv_resolve_file($config['album_dir'], $normalized, $extensions) === null) {
            continue;
        }

        return pv_thumb_of($config, $normalized);
    }

    return null;
}

/**
 * ルートからの相対パスを、サムネイルとして使う形にする。
 * random は「毎回選び直したもの」という印で、編集画面での選び直しに使う。
 * randomFrom は選ぶ範囲。null なら info.yml のあるフォルダ以下という意味。
 */
function pv_thumb_of(array $config, string $relative, bool $random = false, ?string $randomFrom = null): array
{
    $slash = strrpos($relative, '/');

    return [
        'path'       => $relative,
        'url'        => pv_image_url(
            $config['album_url'],
            $slash === false ? '' : substr($relative, 0, $slash),
            $slash === false ? $relative : substr($relative, $slash + 1)
        ),
        'random'     => $random,
        'randomFrom' => $randomFrom,
    ];
}

/**
 * thumbnail: random のときに使う、フォルダ以下の画像から1枚。
 * 表示するたびに選び直すので、開くたびに絵が変わる。
 *
 * $from に null を渡すと、info.yml のあるフォルダ（$relative）以下から選ぶ。
 * 文字列を渡すと、そのフォルダ以下から選ぶ（空文字はホーム全体）。
 * 指したフォルダが見つからないときは、絵なしとして扱う。
 *
 * 大きなフォルダでも重くならないよう、探す深さと枚数に上限を設けている。
 * 上限に達したら、そこまでに見つかった中から選ぶ。
 */
function pv_random_thumb(array $config, string $relative, ?string $from = null): ?array
{
    $target = $from ?? $relative;
    $dir    = pv_resolve_dir($config['album_dir'], $target);

    if ($dir === null) {
        return null;
    }

    $found = [];
    pv_collect_images($dir, $target, pv_image_extensions($config), 1, 5, 200, $found);

    if ($found === []) {
        return null;
    }

    return pv_thumb_of($config, $found[array_rand($found)], true, $from);
}

/**
 * pv_random_thumb() の下請け。フォルダ以下の画像を集める。
 * 隠しフォルダ（. で始まる名前。ゴミ箱もこれに当たる）には入らない。
 */
function pv_collect_images(string $dir, string $relative, array $extensions, int $depth, int $maxDepth, int $limit, array &$found): void
{
    if ($depth > $maxDepth || count($found) >= $limit) {
        return;
    }

    $handle = @opendir($dir);

    if ($handle === false) {
        return;
    }

    $subDirs = [];

    while (($name = readdir($handle)) !== false) {
        if ($name === '.' || $name === '..' || $name[0] === '.') {
            continue;
        }

        $path = $dir . '/' . $name;
        $child = $relative === '' ? $name : $relative . '/' . $name;

        if (is_dir($path)) {
            $subDirs[] = ['dir' => $path, 'relative' => $child];
            continue;
        }

        if (in_array(strtolower(pathinfo($name, PATHINFO_EXTENSION)), $extensions, true)) {
            $found[] = $child;

            if (count($found) >= $limit) {
                break;
            }
        }
    }

    closedir($handle);

    foreach ($subDirs as $sub) {
        pv_collect_images($sub['dir'], $sub['relative'], $extensions, $depth + 1, $maxDepth, $limit, $found);
    }
}

/**
 * info.yml の items を、item / value / url の組に整える。
 * item も value も空の行は、表示するものが無いので落とす。
 */
function pv_info_items($raw): array
{
    if (!is_array($raw)) {
        return [];
    }

    $items = [];

    foreach ($raw as $one) {
        if (!is_array($one)) {
            continue;
        }

        $item  = trim((string) ($one['item'] ?? ''));
        $value = trim((string) ($one['value'] ?? ''));

        if ($item === '' && $value === '') {
            continue;
        }

        $items[] = [
            'item'  => $item,
            'value' => $value,
            'url'   => pv_info_url((string) ($one['url'] ?? '')),
        ];
    }

    return $items;
}

/**
 * リンク先として使ってよいURLかを確かめる。
 * http:// と https://、それに「/」や「../」で始まる同じサイト内の道順だけを通す。
 * javascript: のような、押したときに何かが動く書き方を弾くため。
 */
function pv_info_url(string $url): ?string
{
    $url = trim($url);

    if ($url === '') {
        return null;
    }

    if (preg_match('#^https?://#i', $url)) {
        return $url;
    }

    // //example.com/ という書き方も外部への行き先なので通さない
    if (strpos($url, '//') === 0) {
        return null;
    }

    // 先頭に「なにか:」が付いているものは、すべて弾く
    if (preg_match('#^[a-z][a-z0-9+.\-]*:#i', $url)) {
        return null;
    }

    return $url;
}

/**
 * 一覧を並び替える。$sort は name | date | size、$order は asc | desc。
 */
function pv_sort(array $items, string $sort, string $order): array
{
    usort($items, function (array $a, array $b) use ($sort) {
        switch ($sort) {
            case 'date':
                $result = $a['mtime'] <=> $b['mtime'];
                break;
            case 'size':
                $result = $a['size'] <=> $b['size'];
                break;
            default:
                $result = 0;
                break;
        }

        // 日付・サイズが同じ場合は名前順で安定させる
        if ($result === 0) {
            $result = strnatcasecmp($a['name'], $b['name']);
        }

        return $result;
    });

    return $order === 'desc' ? array_reverse($items) : $items;
}

/**
 * ファイル名にキーワードが含まれるものだけに絞り込む（大文字小文字を区別しない）。
 */
function pv_filter(array $items, string $keyword): array
{
    if ($keyword === '') {
        return $items;
    }

    $needle = mb_strtolower($keyword, 'UTF-8');

    return array_values(array_filter($items, function (array $item) use ($needle) {
        // info.yml で見出しを付けたフォルダは、画面に出ている見出しでも探せるようにする
        $haystacks = [$item['name']];

        if (isset($item['title']) && $item['title'] !== '') {
            $haystacks[] = $item['title'];
        }

        foreach ($haystacks as $haystack) {
            if (mb_strpos(mb_strtolower($haystack, 'UTF-8'), $needle) !== false) {
                return true;
            }
        }

        return false;
    }));
}

/**
 * パンくずリスト用の配列を返す。
 */
function pv_breadcrumbs(string $relative): array
{
    $crumbs = [['name' => 'ホーム', 'path' => '']];

    if ($relative === '') {
        return $crumbs;
    }

    $accumulated = [];
    foreach (explode('/', $relative) as $segment) {
        $accumulated[] = $segment;
        $crumbs[] = [
            'name' => $segment,
            'path' => implode('/', $accumulated),
        ];
    }

    return $crumbs;
}

/**
 * 画像URLを組み立てる。パスの各セグメントを個別にエンコードする
 * （rawurlencode をパス全体にかけると / まで変換されてしまうため）。
 */
function pv_image_url(string $baseUrl, string $relativeDir, string $fileName): string
{
    $segments = [];

    if ($relativeDir !== '') {
        foreach (explode('/', $relativeDir) as $segment) {
            $segments[] = rawurlencode($segment);
        }
    }

    $segments[] = rawurlencode($fileName);

    return rtrim($baseUrl, '/') . '/' . implode('/', $segments);
}

/**
 * 現在の表示条件を引き継いだURLを生成する。
 */
function pv_url(array $params): string
{
    $params = array_filter($params, function ($value) {
        return $value !== '' && $value !== null;
    });

    return $params === [] ? './' : './?' . http_build_query($params);
}

/**
 * ページ送りに並べるページ番号を組み立てる。
 * 常に先頭と最終ページを入れ、現在ページの前後 $window ページ分を並べる。
 * 番号が飛ぶところには、区切りとして 0 を挟む（表示側で「…」にする）。
 *
 * 例）全20ページ・現在6ページ・$window = 2 のとき
 *     [1, 0, 4, 5, 6, 7, 8, 0, 20]
 */
function pv_page_numbers(int $current, int $total, int $window = 2): array
{
    if ($total < 1) {
        return [];
    }

    $current = max(1, min($current, $total));

    // まず出したい番号を集める。重複してもここでは気にしない。
    $wanted = [1, $total];
    for ($i = $current - $window; $i <= $current + $window; $i++) {
        if ($i >= 1 && $i <= $total) {
            $wanted[] = $i;
        }
    }

    $wanted = array_values(array_unique($wanted));
    sort($wanted);

    // 番号が1つだけ飛んでいるときは、「…」を出すより実際の番号を出したほうが
    // 押せる場所が増えて親切なので、そのまま埋める。
    $result = [];
    $previous = null;
    foreach ($wanted as $number) {
        if ($previous !== null) {
            $gap = $number - $previous;
            if ($gap === 2) {
                $result[] = $previous + 1;
            } elseif ($gap > 2) {
                $result[] = 0;
            }
        }
        $result[] = $number;
        $previous = $number;
    }

    return $result;
}

/**
 * 画像ルート配下のフォルダを、階層順に並べて返す。移動先の選択に使う。
 * 返り値は ['path' => 相対パス, 'depth' => 深さ, 'name' => 表示名] の配列で、
 * 先頭は画像ルート自身（path は空文字）。
 */
function pv_folder_tree(string $root, int $maxDepth = 10): array
{
    $realRoot = realpath($root);
    if ($realRoot === false || !is_dir($realRoot)) {
        return [];
    }

    $list = [['path' => '', 'depth' => 0, 'name' => 'ホーム']];
    pv_collect_folders($realRoot, '', 1, $maxDepth, $list);

    return $list;
}

/**
 * pv_folder_tree() の下請け。深さの上限を設けているのは、
 * フォルダへのシンボリックリンクが輪になっていても止まるようにするため。
 */
function pv_collect_folders(string $dir, string $relative, int $depth, int $maxDepth, array &$list): void
{
    if ($depth > $maxDepth) {
        return;
    }

    $handle = @opendir($dir);
    if ($handle === false) {
        return;
    }

    $names = [];
    while (($name = readdir($handle)) !== false) {
        if ($name[0] === '.' || !is_dir($dir . '/' . $name)) {
            continue;
        }
        $names[] = $name;
    }
    closedir($handle);

    usort($names, 'strnatcasecmp');

    foreach ($names as $name) {
        $childRelative = $relative === '' ? $name : $relative . '/' . $name;

        $list[] = ['path' => $childRelative, 'depth' => $depth, 'name' => $name];

        pv_collect_folders($dir . '/' . $name, $childRelative, $depth + 1, $maxDepth, $list);
    }
}

/**
 * 操作フォームに、いまの表示条件を持たせるための hidden 項目を組み立てる。
 * 処理が終わったあと、同じフォルダ・同じ並び順・同じページに戻すために使う。
 */
function pv_context_fields(string $root, string $relative, string $sort, string $order, string $keyword, int $page): string
{
    $fields = [
        'root'  => $root,
        'dir'   => $relative,
        'sort'  => $sort,
        'order' => $order,
        'q'     => $keyword,
        'page'  => (string) $page,
    ];

    $html = '';
    foreach ($fields as $name => $value) {
        $html .= '<input type="hidden" name="' . $name . '" value="' . h($value) . '">';
    }

    return $html;
}

/**
 * ファイルサイズを読みやすい単位に変換する。
 */
function pv_human_size(int $bytes): string
{
    if ($bytes < 1024) {
        return $bytes . ' B';
    }

    $units = ['KB', 'MB', 'GB'];
    $value = $bytes / 1024;

    foreach ($units as $unit) {
        if ($value < 1024 || $unit === 'GB') {
            return sprintf('%.1f %s', $value, $unit);
        }
        $value /= 1024;
    }

    return $bytes . ' B';
}

/**
 * HTMLエスケープの短縮形。
 */
function h(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * セッションを開始する（開始済みなら何もしない）。
 * CSRFトークンと、処理結果メッセージの受け渡しに使う。
 */
function pv_session_start(): void
{
    if (session_status() !== PHP_SESSION_NONE) {
        return;
    }

    session_set_cookie_params([
        'httponly' => true,
        'samesite' => 'Lax',
        'secure'   => !empty($_SERVER['HTTPS']),
    ]);
    session_start();
}

/**
 * CSRFトークンを取り出す（なければ作る）。
 *
 * Basic認証を通っていても、認証済みのブラウザが外部サイトのフォームから
 * action.php を叩かされる可能性は残る。それを防ぐためのもの。
 */
function pv_csrf_token(): string
{
    if (empty($_SESSION['pv_token'])) {
        $_SESSION['pv_token'] = bin2hex(random_bytes(16));
    }

    return $_SESSION['pv_token'];
}

/**
 * 送られてきたトークンが正しいかを確かめる。
 */
function pv_csrf_check(?string $token): bool
{
    return !empty($_SESSION['pv_token'])
        && is_string($token)
        && hash_equals($_SESSION['pv_token'], $token);
}

/**
 * 処理結果を次に表示する画面へ引き継ぐ。$type は 'ok' | 'error'。
 */
function pv_flash(string $type, string $message): void
{
    $_SESSION['pv_flash'][] = ['type' => $type, 'message' => $message];
}

/**
 * 引き継がれたメッセージを取り出す（取り出すと消える）。
 */
function pv_take_flash(): array
{
    $messages = $_SESSION['pv_flash'] ?? [];
    unset($_SESSION['pv_flash']);

    return is_array($messages) ? $messages : [];
}
