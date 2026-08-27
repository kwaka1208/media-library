<?php
/**
 * Media Library : 写真・動画・フォルダを操作する受け口
 *
 * POST だけを受け付け、処理が終わったら元の一覧へリダイレクトする。
 * こうしておくと、処理後にブラウザを再読み込みしても同じ操作が
 * もう一度実行されることがない。
 */

$config = require __DIR__ . '/config.php';
require __DIR__ . '/lib/functions.php';
require __DIR__ . '/lib/actions.php';

pv_session_start();

// 操作の対象となるルート（写真／動画）。知らない名前が来たら既定のルートに落とす。
$config = pv_apply_root($config, pv_root_key($config, $_POST['root'] ?? null));

/**
 * 元の一覧へ戻る。送られてきた値をそのまま使わず、一覧画面と同じ規則で
 * URLを組み立て直す（外部のURLへ飛ばされるのを防ぐため）。
 */
function pv_back(array $config): string
{
    $sort = (string) ($_POST['sort'] ?? '');
    $order = (string) ($_POST['order'] ?? '');

    $params = [
        'root'  => $config['root'],
        'path'  => pv_normalize_relative((string) ($_POST['dir'] ?? '')),
        'sort'  => in_array($sort, ['name', 'date', 'size'], true) ? $sort : $config['default_sort'],
        'order' => in_array($order, ['asc', 'desc'], true) ? $order : $config['default_order'],
        'q'     => trim((string) ($_POST['q'] ?? '')),
    ];

    $page = max(1, (int) ($_POST['page'] ?? 1));
    if ($page > 1) {
        $params['page'] = $page;
    }

    return pv_url($params);
}

$back = pv_back($config);

// ---- 受け付けてよい要求かを確かめる --------------------------------

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Location: ' . $back, true, 303);
    exit;
}

if (!empty($config['read_only'])) {
    pv_flash('error', 'このツールは閲覧専用の設定になっています。');
    header('Location: ' . $back, true, 303);
    exit;
}

if (!pv_csrf_check($_POST['token'] ?? null)) {
    pv_flash('error', '操作を受け付けられませんでした。画面を読み込み直してから、もう一度お試しください。');
    header('Location: ' . $back, true, 303);
    exit;
}

// ---- 操作を実行する ------------------------------------------------

switch ((string) ($_POST['action'] ?? '')) {
    case 'rename':
        $result = pv_do_rename(
            $config,
            (string) ($_POST['path'] ?? ''),
            (string) ($_POST['name'] ?? '')
        );
        break;

    case 'delete':
        $paths = $_POST['paths'] ?? [];
        $result = pv_do_delete($config, is_array($paths) ? $paths : [$paths]);
        break;

    case 'move':
        $paths = $_POST['paths'] ?? [];
        $result = pv_do_move(
            $config,
            is_array($paths) ? $paths : [$paths],
            (string) ($_POST['destination'] ?? '')
        );
        break;

    case 'info':
        $items = $_POST['items'] ?? [];
        $result = pv_do_info(
            $config,
            (string) ($_POST['dir'] ?? ''),
            (string) ($_POST['title'] ?? ''),
            (string) ($_POST['thumbnail'] ?? ''),
            is_array($items) ? $items : [],
            (string) ($_POST['random_from'] ?? 'self')
        );
        break;

    case 'mkdir':
        $result = pv_do_mkdir(
            $config,
            (string) ($_POST['dir'] ?? ''),
            (string) ($_POST['name'] ?? '')
        );
        break;

    case 'init-info':
        // 初期設定の画面から実行する。終わったら一覧ではなく初期設定へ戻す。
        $result = pv_do_init_info($config);
        $back   = './?init';
        break;

    default:
        $result = ['ok' => false, 'message' => '不明な操作です。'];
        break;
}

pv_flash($result['ok'] ? 'ok' : 'error', $result['message']);

header('Location: ' . $back, true, 303);
exit;
