<?php
/**
 * Media Library : ドラッグ＆ドロップで取り込むときの受け口
 *
 * action.php と違い、こちらは JSON を返す。かけらを1つ送るたびに
 * 画面を切り替えていては進み具合が見せられないため、
 * ブラウザ側から呼んで、返ってきた内容で表示を書き換える。
 */

$config = require __DIR__ . '/config.php';
require __DIR__ . '/lib/functions.php';
require __DIR__ . '/lib/actions.php';
require __DIR__ . '/lib/upload.php';

pv_session_start();

/**
 * JSON を返して終わる。
 */
function pv_upload_reply(array $body, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store');

    echo json_encode($body, JSON_UNESCAPED_UNICODE);
    exit;
}

// ---- 受け付けてよい要求かを確かめる --------------------------------

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    pv_upload_reply(['ok' => false, 'message' => '受け付けられない要求です。'], 405);
}

// POST が丸ごと捨てられている（post_max_size 超え）と $_POST は空になる。
// そのときはトークンも読めないので、先に見分けて理由を返す。
if ($_POST === [] && $_FILES === []) {
    pv_upload_reply([
        'ok'      => false,
        'message' => '一度に送る大きさがサーバーの上限を超えました。画面を読み込み直してから、もう一度お試しください。',
    ], 413);
}

if (!empty($config['read_only'])) {
    pv_upload_reply(['ok' => false, 'message' => 'このツールは閲覧専用の設定になっています。'], 403);
}

if (!pv_csrf_check($_POST['token'] ?? null)) {
    pv_upload_reply([
        'ok'      => false,
        'message' => '操作を受け付けられませんでした。画面を読み込み直してから、もう一度お試しください。',
    ], 403);
}

// 取り込む先のルート（写真／動画）。受け付ける拡張子もここで決まる。
$config = pv_apply_root($config, pv_root_key($config, $_POST['root'] ?? null));

// ---- 受け取る ------------------------------------------------------

$result = pv_do_upload(
    $config,
    (string) ($_POST['dir'] ?? ''),
    (string) ($_POST['id'] ?? ''),
    (int) ($_POST['index'] ?? -1),
    (int) ($_POST['total'] ?? 0),
    (string) ($_POST['name'] ?? ''),
    (string) ($_POST['sub'] ?? ''),
    $_FILES['chunk'] ?? []
);

pv_upload_reply($result, $result['ok'] ? 200 : 400);
