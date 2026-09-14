<?php
/**
 * Media Library : フォルダ情報のサムネイルを選ぶための受け口
 *
 * フォルダ情報の編集画面から呼ばれ、指定されたフォルダのサブフォルダと
 * 画像の一覧を JSON で返す。画面を切り替えずにフォルダを辿れるようにするため、
 * action.php ではなくこちらを使う。
 *
 * 読むだけで、何も書き換えない。返すのは一覧画面（index.php）で見えるものと
 * 同じ範囲なので、GET で受け、CSRFトークンは求めない。
 * パスの検証は一覧画面と同じ関数を通すので、ルートの外や隠しフォルダは開けない。
 */

$config = require __DIR__ . '/config.php';
require __DIR__ . '/lib/functions.php';

// 1回で返す画像の数の上限。多いフォルダでも表示が重くならないようにする。
const PV_BROWSE_LIMIT = 300;

/**
 * JSON を返して終わる。
 */
function pv_browse_reply(array $body, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store');

    echo json_encode($body, JSON_UNESCAPED_UNICODE);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    pv_browse_reply(['ok' => false, 'message' => '受け付けられない要求です。'], 405);
}

// 見に行くルート（写真／動画）。知らない名前が来たら既定のルートに落とす。
$config = pv_apply_root($config, pv_root_key($config, $_GET['root'] ?? null));

$relative = pv_normalize_relative((string) ($_GET['path'] ?? ''));
$dir      = pv_resolve_dir($config['album_dir'], $relative);

if ($dir === null) {
    pv_browse_reply(['ok' => false, 'message' => 'フォルダが見つかりませんでした。'], 404);
}

// サムネイルに使えるのは画像だけなので、動画のルートでも画像の拡張子で探す
$scanned = pv_scan($dir, pv_image_extensions($config));

$dirs  = pv_sort($scanned['dirs'], 'name', 'asc');
$files = pv_sort($scanned['files'], 'name', 'asc');

$cut = count($files) > PV_BROWSE_LIMIT;

if ($cut) {
    $files = array_slice($files, 0, PV_BROWSE_LIMIT);
}

// パス表示（パンくず）。先頭は一覧画面と同じく、ルートの名前にする。
$crumbs = pv_breadcrumbs($relative);
$crumbs[0]['name'] = $config['root_label'];

$parent = $relative === '' ? null : pv_normalize_relative(
    dirname($relative) === '.' ? '' : dirname($relative)
);

$folders = [];

foreach ($dirs as $one) {
    $folders[] = [
        'name'  => $one['name'],
        'path'  => $relative === '' ? $one['name'] : $relative . '/' . $one['name'],
        'count' => (int) $one['count'],
    ];
}

$images = [];

foreach ($files as $one) {
    $images[] = [
        'name' => $one['name'],
        'path' => $relative === '' ? $one['name'] : $relative . '/' . $one['name'],
        'url'  => pv_image_url($config['album_url'], $relative, $one['name']),
    ];
}

pv_browse_reply([
    'ok'     => true,
    'root'   => $config['root'],
    'path'   => $relative,
    'parent' => $parent,
    'crumbs' => $crumbs,
    'dirs'   => $folders,
    'files'  => $images,
    'cut'    => $cut,
]);
