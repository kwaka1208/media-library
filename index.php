<?php
/**
 * Media Library : 写真・動画フォルダの一覧表示
 */

$config = require __DIR__ . '/config.php';
require __DIR__ . '/lib/functions.php';

pv_session_start();

// ---- 表示するルート（写真／動画）--------------------------------
// 以降の処理は、選んだルート1件だけを見ればよいようにしておく。
$rootKey = pv_root_key($config, $_GET['root'] ?? null);
$config  = pv_apply_root($config, $rootKey);

$root       = $config['album_dir'];
$extensions = $config['extensions'];
$rootLabel  = $config['root_label'];
$unit       = $config['root_unit'];

// ---- リクエストパラメータ ----------------------------------------
$relative = pv_normalize_relative($_GET['path'] ?? '');
$sort     = in_array($_GET['sort'] ?? '', ['name', 'date', 'size'], true)
    ? $_GET['sort']
    : $config['default_sort'];
$order    = in_array($_GET['order'] ?? '', ['asc', 'desc'], true)
    ? $_GET['order']
    : $config['default_order'];
$keyword  = trim((string) ($_GET['q'] ?? ''));
$page     = max(1, (int) ($_GET['page'] ?? 1));

// ---- フォルダの解決 ----------------------------------------------
$error = null;
$dirs  = [];
$files = [];
$totalFiles = 0;
$totalPages = 1;

$dir = pv_resolve_dir($root, $relative);

if ($dir === null) {
    if (realpath($root) === false) {
        $error = $rootLabel . 'のフォルダが見つかりません。config.php の roots を確認してください。';
    } else {
        $error = '指定されたフォルダは存在しません。';
        $relative = '';
        $dir = pv_resolve_dir($root, '');
    }
}

if ($dir !== null && $error === null) {
    $scanned = pv_scan($dir, $extensions);

    // フォルダに置かれた情報（info.yml）を、ここで1回だけ読む。
    // 絞り込みでも一覧の表示でも使うため。
    foreach ($scanned['dirs'] as $index => $item) {
        $childPath = $relative === '' ? $item['name'] : $relative . '/' . $item['name'];
        $childInfo = pv_read_info($config, $childPath);

        $scanned['dirs'][$index]['info']  = $childInfo;
        $scanned['dirs'][$index]['title'] = $childInfo === null ? '' : $childInfo['title'];
    }

    $dirs  = pv_sort(pv_filter($scanned['dirs'], $keyword), $sort, $order);
    $files = pv_sort(pv_filter($scanned['files'], $keyword), $sort, $order);

    $totalFiles = count($files);

    // ページ送り（ファイルのみが対象。フォルダは常に先頭に表示する）
    $perPage = (int) $config['per_page'];
    if ($perPage > 0) {
        $totalPages = max(1, (int) ceil($totalFiles / $perPage));
        $page  = min($page, $totalPages);
        $files = array_slice($files, ($page - 1) * $perPage, $perPage);
    }
}

// ---- フォルダに置かれた情報（info.yml）---------------------------
// 開いているフォルダに info.yml があれば、その中身を一覧の横に出す。
$info = $error === null ? pv_read_info($config, $relative) : null;

// 編集画面でサムネイルに選べる画像。動画のフォルダでもサムネイルは画像なので、
// いま開いているルートの拡張子ではなく、画像の拡張子で数え直す。
// 枚数が多いフォルダで編集画面が重くならないよう、先頭から上限までにする。
$infoImages    = [];
$infoImagesCut = false;

if ($dir !== null && $error === null) {
    $infoImages = pv_sort(pv_scan($dir, pv_image_extensions($config))['files'], 'name', 'asc');

    if (count($infoImages) > 200) {
        $infoImages    = array_slice($infoImages, 0, 200);
        $infoImagesCut = true;
    }
}

// ---- 操作機能が使えるかどうか ------------------------------------
// read_only の設定だけでなく、フォルダに書き込めるかどうかも見ておく。
// 権限が足りないサーバーで、押しても何も起きないボタンを並べないため。
$writable  = $dir !== null && $error === null && is_writable($dir);
$canEdit   = empty($config['read_only']) && $writable;
$token     = pv_csrf_token();
$messages  = pv_take_flash();

// 移動先の候補（いま開いているルート配下のフォルダ）
$folderTree = $canEdit ? pv_folder_tree($root) : [];

$crumbs   = pv_breadcrumbs($relative);
$crumbs[0]['name'] = $rootLabel; // パンくずの先頭は、いま開いているルートの名前にする
$parent   = $relative === '' ? null : pv_normalize_relative(dirname($relative) === '.' ? '' : dirname($relative));

// パンくずにも、info.yml のタイトルがあればそれを出す。
// label は画面に出す名前、name は実際のフォルダ名で、こちらは吹き出しに使う。
// 先頭（写真・動画のルート）は、タブの名前と揃えたいのでそのままにする。
$lastIndex = count($crumbs) - 1;

foreach ($crumbs as $index => $crumb) {
    $crumbs[$index]['label'] = $crumb['name'];

    if ($index === 0) {
        continue;
    }

    // 現在地は、上ですでに読んだものを使い回す
    $crumbInfo = $index === $lastIndex ? $info : pv_read_info($config, $crumb['path']);

    if ($crumbInfo !== null && $crumbInfo['title'] !== '') {
        $crumbs[$index]['label'] = $crumbInfo['title'];
    }
}

// 階層が深いとヘッダーが1行に収まらないので、途中を「…」に畳む。
// 先頭（ホーム）と手前の1つ・現在地は残しておくと、今どこにいるか分かる。
$crumbsShown  = $crumbs;
$crumbsFolded = false;
if (count($crumbs) > 4) {
    $crumbsShown = [
        $crumbs[0],
        null, // 「…」の位置
        $crumbs[count($crumbs) - 2],
        $crumbs[count($crumbs) - 1],
    ];
    $crumbsFolded = true;
}
$baseUrl  = $config['album_url'];

// ---- 動画の見え方の既定値 ----------------------------------------
// 画面右上の「設定」で変更でき、変更後はブラウザ側（localStorage）に覚えさせる。
// ここで出すのは、まだ一度も設定を変えていない人に使う初期値。
$videoMuted = !empty($config['video_muted']) ? '1' : '0';
$videoSize  = ($config['video_size'] ?? 'original') === 'fit' ? 'fit' : 'original';

// ---- 一覧の見せ方の既定値 ----------------------------------------
// グリッド（サムネイルを並べる）か、リスト（1行ずつ並べる）か。
// こちらも設定と同じで、変更後はブラウザ側に覚えさせる。
$view = ($config['default_view'] ?? 'grid') === 'list' ? 'list' : 'grid';
$keepArgs = ['root' => $rootKey, 'sort' => $sort, 'order' => $order, 'q' => $keyword];

// フォルダを移動するリンク（フォルダ一覧・パンくず・上のフォルダへ）では、
// 検索での絞り込みを持ち越さず、q を空にしてクリアする。
$navArgs = ['q' => ''] + $keepArgs;
?>
<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<?php
// タブに出る名前。情報のあるフォルダでは、そのタイトルを使う。
$pageName = $relative === '' ? $rootLabel : $relative;

if ($info !== null && $info['title'] !== '') {
    $pageName = $info['title'];
}
?>
<title><?= h($pageName . ' | ' . $config['title']) ?></title>
<link rel="stylesheet" href="assets/style.css?v=21">
<style>:root { --thumb-size: <?= (int) $config['thumb_size'] ?>px; }</style>
</head>
<body data-video-muted="<?= h($videoMuted) ?>" data-video-size="<?= h($videoSize) ?>"
      data-view="<?= h($view) ?>">

<div class="page-header">

<header class="header">
    <h1 class="site-title"><a href="./"><?= h($config['title']) ?></a></h1>

    <nav class="root-tabs" aria-label="表示する種類">
        <?php foreach ($config['roots'] as $key => $rootConfig): ?>
            <?php if ($key === $rootKey): ?>
                <span class="root-tab current" aria-current="page"><?= h($rootConfig['label']) ?></span>
            <?php else: ?>
                <a class="root-tab"
                   href="<?= h(pv_url(['root' => $key, 'sort' => $sort, 'order' => $order])) ?>"><?= h($rootConfig['label']) ?></a>
            <?php endif; ?>
        <?php endforeach; ?>
    </nav>

    <nav class="breadcrumbs" aria-label="現在の場所">
        <?php $lastCrumb = count($crumbsShown) - 1; ?>
        <?php foreach ($crumbsShown as $index => $crumb): ?>
            <?php if ($crumb === null): ?>
                <span class="crumb-fold" title="<?= h($relative) ?>">…</span>
            <?php elseif ($index === $lastCrumb): ?>
                <span class="crumb current" title="<?= h($crumb['name']) ?>"><?= h($crumb['label']) ?></span>
            <?php else: ?>
                <a class="crumb" href="<?= h(pv_url(['path' => $crumb['path']] + $navArgs)) ?>"
                   <?= $canEdit ? 'data-drop-path="' . h($crumb['path']) . '"' : '' ?>
                   title="<?= h($crumb['name']) ?>"><?= h($crumb['label']) ?></a>
            <?php endif; ?>
            <?php if ($index !== $lastCrumb): ?><span class="crumb-sep">/</span><?php endif; ?>
        <?php endforeach; ?>
    </nav>

    <?php if ($parent !== null): ?>
        <div class="actions-bar">
            <a class="button up" href="<?= h(pv_url(['path' => $parent] + $navArgs)) ?>"
               <?= $canEdit ? 'data-drop-path="' . h($parent) . '"' : '' ?>>
                <span class="button-icon" aria-hidden="true">↩</span> 上のフォルダへ
            </a>
        </div>
    <?php endif; ?>

    <form class="toolbar" method="get" action="./">
        <input type="hidden" name="root" value="<?= h($rootKey) ?>">
        <input type="hidden" name="path" value="<?= h($relative) ?>">

        <label class="field search">
            <span class="visually-hidden">ファイル名で絞り込み</span>
            <input type="search" name="q" value="<?= h($keyword) ?>"
                   placeholder="ファイル名で絞り込み" autocomplete="off" data-autosubmit>
        </label>

        <label class="field">
            <span class="visually-hidden">並び替え</span>
            <select name="sort" data-autosubmit>
                <option value="name" <?= $sort === 'name' ? 'selected' : '' ?>>名前順</option>
                <option value="date" <?= $sort === 'date' ? 'selected' : '' ?>>更新日時順</option>
                <option value="size" <?= $sort === 'size' ? 'selected' : '' ?>>サイズ順</option>
            </select>
        </label>

        <label class="field">
            <span class="visually-hidden">並び方向</span>
            <select name="order" data-autosubmit>
                <option value="asc"  <?= $order === 'asc'  ? 'selected' : '' ?>>昇順</option>
                <option value="desc" <?= $order === 'desc' ? 'selected' : '' ?>>降順</option>
            </select>
        </label>

        <noscript><button type="submit" class="button">適用</button></noscript>
    </form>

    <?php // 押すたびにグリッドとリストが入れ替わる。いま押せる側だけを CSS で見せる。 ?>
    <button type="button" class="button view-button" data-act="view">
        <span class="view-option only-grid">
            <span class="button-icon" aria-hidden="true">☰</span>
            <span class="menu-button-label">リスト</span>
        </span>
        <span class="view-option only-list">
            <span class="button-icon" aria-hidden="true">⊞</span>
            <span class="menu-button-label">グリッド</span>
        </span>
    </button>

    <button type="button" class="button settings-button" data-act="settings" aria-label="設定">
        <span class="button-icon" aria-hidden="true">⚙</span>
        <span class="menu-button-label">設定</span>
    </button>

    <?php if ($canEdit): ?>
        <button type="button" class="button menu-button" data-act="more"
                aria-haspopup="menu" aria-expanded="false" aria-label="メニュー">
            <span class="button-icon" aria-hidden="true">⋯</span>
            <span class="menu-button-label">メニュー</span>
        </button>
    <?php endif; ?>
</header>

<?php if ($canEdit): ?>
    <div class="selection-bar" hidden>
        <span class="selection-count" data-selection-count>0件を選択中</span>
        <span class="selection-hint">Shift＋クリックで範囲選択 ・ 背景をドラッグして囲む ・ Esc で解除</span>
        <button type="button" class="button" data-act="select-all">すべて選択</button>
        <button type="button" class="button" data-act="move-selected" disabled>まとめて移動</button>
        <button type="button" class="button danger" data-act="delete-selected" disabled>まとめてゴミ箱へ</button>
        <button type="button" class="button" data-act="select-mode">選択をやめる</button>
    </div>
<?php endif; ?>

</div><!-- /.page-header -->

<?php
// ページ送りのリンクを組み立てるための小さな関数。
// path と並び順・検索語（$keepArgs）はそのままに、ページ番号だけを差し替える。
$pageUrl = function (int $target) use ($relative, $keepArgs) {
    return pv_url(['path' => $relative, 'page' => $target] + $keepArgs);
};

// 枚数とページ送りの帯。フォルダ情報があるときはその下に、
// ないときは一覧の上に置く。出す場所が2か所あるので、先に組み立てておく。
$listingBar = '';

if ($error === null) {
    ob_start();
    ?>
    <div class="listing-bar">
        <p class="summary">
            <?php if ($keyword !== ''): ?>
                「<?= h($keyword) ?>」に一致する<?= h($rootLabel) ?> <?= $totalFiles ?> <?= h($unit) ?>
            <?php else: ?>
                <?= h($rootLabel) ?> <?= $totalFiles ?> <?= h($unit) ?>
            <?php endif; ?>
            <?php if ($totalPages > 1): ?>
                <span class="page-indicator"><?= $page ?> / <?= $totalPages ?> ページ</span>
            <?php endif; ?>
        </p>

        <?php if ($totalPages > 1): ?>
            <nav class="pagination" aria-label="ページ送り">
                <?php if ($page > 1): ?>
                    <a class="button page-step" href="<?= h($pageUrl(1)) ?>" aria-label="先頭ページへ">&laquo; 先頭</a>
                    <a class="button page-step" href="<?= h($pageUrl($page - 1)) ?>" rel="prev">&lsaquo; 前へ</a>
                <?php else: ?>
                    <span class="button page-step" aria-disabled="true">&laquo; 先頭</span>
                    <span class="button page-step" aria-disabled="true">&lsaquo; 前へ</span>
                <?php endif; ?>

                <ol class="page-numbers">
                    <?php foreach (pv_page_numbers($page, $totalPages) as $number): ?>
                        <?php if ($number === 0): ?>
                            <li class="page-gap" aria-hidden="true">…</li>
                        <?php elseif ($number === $page): ?>
                            <li><span class="button page-number current" aria-current="page"><?= $number ?></span></li>
                        <?php else: ?>
                            <li>
                                <a class="button page-number" href="<?= h($pageUrl($number)) ?>"
                                   aria-label="<?= $number ?> ページ目へ"><?= $number ?></a>
                            </li>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </ol>

                <?php if ($page < $totalPages): ?>
                    <a class="button page-step" href="<?= h($pageUrl($page + 1)) ?>" rel="next">次へ &rsaquo;</a>
                    <a class="button page-step" href="<?= h($pageUrl($totalPages)) ?>" aria-label="最終ページへ">最終 &raquo;</a>
                <?php else: ?>
                    <span class="button page-step" aria-disabled="true">次へ &rsaquo;</span>
                    <span class="button page-step" aria-disabled="true">最終 &raquo;</span>
                <?php endif; ?>
            </nav>
        <?php endif; ?>
    </div>
    <?php
    $listingBar = ob_get_clean();
}
?>

<main class="content">

<?php if ($info !== null): ?>
<?php // info.yml のあるフォルダで出す情報。広い画面では一覧の左、狭い画面では上。
      // 枚数とページ送りは、その情報の下にそろえて置く。 ?>
<div class="side">
<aside class="info-panel" aria-label="このフォルダの情報">
    <?php if ($info['thumb'] !== null): ?>
        <div class="info-thumb">
            <img src="<?= h($info['thumb']['url']) ?>" alt="" loading="lazy" decoding="async">
        </div>
    <?php endif; ?>

    <?php if ($info['title'] !== ''): ?>
        <h2 class="info-title"><?= h($info['title']) ?></h2>
    <?php endif; ?>

    <?php if ($info['items'] !== []): ?>
        <dl class="info-list">
            <?php foreach ($info['items'] as $row): ?>
                <dt><?= h($row['item']) ?></dt>
                <dd>
                    <?php if ($row['url'] === null): ?>
                        <?= h($row['value']) ?>
                    <?php elseif (strpos($row['url'], 'http') === 0): ?>
                        <?php // 外の場所へのリンクは、別のタブで開き、来た場所を伝えない ?>
                        <a href="<?= h($row['url']) ?>" target="_blank"
                           rel="noopener noreferrer"><?= h($row['value']) ?></a>
                    <?php else: ?>
                        <a href="<?= h($row['url']) ?>"><?= h($row['value']) ?></a>
                    <?php endif; ?>
                </dd>
            <?php endforeach; ?>
        </dl>
    <?php endif; ?>
</aside>

<?= $listingBar ?>
</div><!-- /.side -->
<?php endif; ?>

<div class="listing">

<?php foreach ($messages as $message): ?>
    <p class="notice <?= $message['type'] === 'ok' ? 'ok' : 'error' ?>"><?= h($message['message']) ?></p>
<?php endforeach; ?>

<?php if (empty($config['read_only']) && $error === null && !$writable): ?>
    <p class="notice">
        このフォルダに書き込む権限がないため、名前の変更・移動・削除は行えません。
    </p>
<?php endif; ?>

<?php if ($error !== null): ?>
    <p class="notice error"><?= h($error) ?></p>
<?php endif; ?>

<?php if ($dirs !== []): ?>
    <ul class="folders">
        <?php foreach ($dirs as $item): ?>
            <?php $childPath = $relative === '' ? $item['name'] : $relative . '/' . $item['name']; ?>
            <?php // info.yml があるフォルダは、フォルダ名の代わりにその見出しとサムネイルで見せる ?>
            <?php $childInfo = $item['info']; ?>
            <?php $label = ($childInfo !== null && $childInfo['title'] !== '')
                ? $childInfo['title']
                : $item['name']; ?>
            <li class="folder-item"<?= $canEdit ? ' draggable="true"' : '' ?>
                data-type="dir" data-path="<?= h($childPath) ?>" data-name="<?= h($item['name']) ?>">
                <?php if ($canEdit): ?>
                    <label class="select-box">
                        <span class="visually-hidden"><?= h($item['name']) ?> を選ぶ</span>
                        <input type="checkbox" data-select>
                    </label>
                <?php endif; ?>
                <a class="folder<?= $childInfo !== null ? ' has-info' : '' ?>"
                   href="<?= h(pv_url(['path' => $childPath] + $navArgs)) ?>">
                    <?php if ($childInfo !== null && $childInfo['thumb'] !== null): ?>
                        <span class="folder-thumb">
                            <img src="<?= h($childInfo['thumb']['url']) ?>" alt=""
                                 loading="lazy" decoding="async">
                        </span>
                    <?php else: ?>
                        <span class="folder-icon" aria-hidden="true">📁</span>
                    <?php endif; ?>
                    <span class="folder-name" title="<?= h($item['name']) ?>"><?= h($label) ?></span>
                    <span class="folder-count"><?= (int) $item['count'] ?></span>
                </a>
            </li>
        <?php endforeach; ?>
    </ul>
<?php endif; ?>

<?php if ($info === null): ?>
    <?= $listingBar ?>
<?php endif; ?>

<?php if ($files === [] && $error === null): ?>
    <p class="notice">
        <?= $keyword !== ''
            ? '一致する' . h($rootLabel) . 'がありませんでした。'
            : 'このフォルダに表示できる' . h($rootLabel) . 'がありません。' ?>
    </p>
<?php endif; ?>

<div class="grid" id="grid">
    <?php foreach ($files as $index => $item): ?>
        <?php $src = pv_image_url($baseUrl, $relative, $item['name']); ?>
        <?php $filePath = $relative === '' ? $item['name'] : $relative . '/' . $item['name']; ?>
        <?php $isVideo = pv_is_video($config, $item['name']); ?>
        <figure class="card<?= $isVideo ? ' video' : '' ?>"<?= $canEdit ? ' draggable="true"' : '' ?>
                data-type="file" data-kind="<?= $isVideo ? 'video' : 'image' ?>"
                data-path="<?= h($filePath) ?>"
                data-name="<?= h($item['name']) ?>" data-src="<?= h($src) ?>"
                data-size="<?= h(pv_human_size((int) $item['size'])) ?>"
                data-date="<?= h(date('Y-m-d H:i', (int) $item['mtime'])) ?>">
            <?php if ($canEdit): ?>
                <label class="select-box">
                    <span class="visually-hidden"><?= h($item['name']) ?> を選ぶ</span>
                    <input type="checkbox" data-select>
                </label>
            <?php endif; ?>
            <a class="thumb" href="<?= h($src) ?>"
               data-index="<?= $index ?>"
               data-kind="<?= $isVideo ? 'video' : 'image' ?>"
               data-name="<?= h($item['name']) ?>"
               data-meta="<?= h(pv_human_size((int) $item['size']) . ' / ' . date('Y-m-d H:i', (int) $item['mtime'])) ?>">
                <?php if ($isVideo): ?>
                    <?php // サムネイル画像は作らず、動画の先頭付近の1コマをブラウザに描かせる。
                          // #t=0.1 を付けておくと、その位置のコマが表示される。 ?>
                    <video src="<?= h($src) ?>#t=0.1" preload="metadata"
                           muted playsinline tabindex="-1" aria-label="<?= h($item['name']) ?>"></video>
                    <span class="play-badge" aria-hidden="true">▶</span>
                <?php else: ?>
                    <img src="<?= h($src) ?>" alt="<?= h($item['name']) ?>" loading="lazy" decoding="async">
                <?php endif; ?>
            </a>
            <figcaption class="caption">
                <span class="file-name" title="<?= h($item['name']) ?>"><?= h($item['name']) ?></span>
                <span class="file-meta"><?= h(pv_human_size((int) $item['size'])) ?> ・ <?= date('Y-m-d', (int) $item['mtime']) ?></span>
            </figcaption>
        </figure>
    <?php endforeach; ?>
</div>

</div><!-- /.listing -->

</main>

<!-- 右クリック（スマホは長押し）で出る操作メニュー。
     出す項目は対象に応じて app.js が切り替える。
     「プロパティ」は見るだけの人にも要るので、$canEdit の外に置く。 -->
<nav class="context-menu" id="contextMenu" hidden aria-label="操作メニュー">
    <p class="context-target" data-context-target></p>
    <button type="button" class="context-item" data-menu="properties">プロパティ</button>
    <?php if ($canEdit): ?>
        <button type="button" class="context-item" data-menu="rename">名前を変更</button>
        <button type="button" class="context-item" data-menu="move">移動</button>
        <button type="button" class="context-item" data-menu="download">ダウンロード</button>
        <button type="button" class="context-item danger" data-menu="delete">ゴミ箱へ移動</button>
        <button type="button" class="context-item" data-menu="select">複数選択</button>
        <button type="button" class="context-item" data-menu="mkdir">新しいフォルダ</button>
        <button type="button" class="context-item" data-menu="info">フォルダ情報</button>
    <?php endif; ?>
</nav>

<?php // プロパティ。右クリックメニューから開き、画面全体に出す。
      // どこを押しても閉じるので、閉じるボタンは置かない。 ?>
<div class="props-view" id="propsView" hidden tabindex="-1" role="dialog" aria-label="プロパティ">
    <div class="props-body">
        <div class="props-thumb" data-props-thumb></div>

        <?php // 画面を横にしたときに、写真の横へ回せるようひとまとめにしておく ?>
        <div class="props-info">
            <p class="props-name" data-props-name></p>

            <dl class="props-list">
                <dt>種類</dt>
                <dd data-props-kind></dd>
                <dt>サイズ</dt>
                <dd data-props-size></dd>
                <dt>更新日時</dt>
                <dd data-props-date></dd>
                <dt>場所</dt>
                <dd data-props-path></dd>
            </dl>
        </div>
    </div>
</div>

<?php if ($canEdit): ?>

<!-- 名前の変更 -->
<div class="modal" id="renameModal" hidden>
    <form class="modal-panel" method="post" action="action.php">
        <h2 class="modal-title">名前を変更</h2>

        <input type="hidden" name="token" value="<?= h($token) ?>">
        <input type="hidden" name="action" value="rename">
        <input type="hidden" name="path" value="">
        <?= pv_context_fields($rootKey, $relative, $sort, $order, $keyword, $page) ?>

        <p class="modal-target" data-modal-target></p>

        <label class="modal-field">
            <span class="modal-label">新しい名前</span>
            <input type="text" name="name" autocomplete="off" required>
        </label>

        <p class="modal-note">
            ファイルの拡張子（<?= h($config['root_kind'] === 'video' ? '.mp4' : '.jpg') ?> など）は変更できません。
        </p>

        <div class="modal-actions">
            <button type="button" class="button" data-modal-close>キャンセル</button>
            <button type="submit" class="button primary">変更する</button>
        </div>
    </form>
</div>

<!-- 移動 -->
<div class="modal" id="moveModal" hidden>
    <form class="modal-panel" method="post" action="action.php">
        <h2 class="modal-title">移動</h2>

        <input type="hidden" name="token" value="<?= h($token) ?>">
        <input type="hidden" name="action" value="move">
        <span data-paths hidden></span>
        <?= pv_context_fields($rootKey, $relative, $sort, $order, $keyword, $page) ?>

        <p class="modal-target" data-modal-target></p>

        <label class="modal-field">
            <span class="modal-label">移動先のフォルダ</span>
            <select name="destination">
                <?php foreach ($folderTree as $folder): ?>
                    <option value="<?= h($folder['path']) ?>"><?= str_repeat('　', (int) $folder['depth']) . h($folder['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </label>

        <p class="modal-note">移動先に同じ名前のものがある場合は移動しません。</p>

        <div class="modal-actions">
            <button type="button" class="button" data-modal-close>キャンセル</button>
            <button type="submit" class="button primary">移動する</button>
        </div>
    </form>
</div>

<!-- 新しいフォルダ -->
<?php // フォルダ情報（info.yml）の編集。右クリックまたは「⋯ メニュー」から開く。 ?>
<div class="modal" id="infoModal" hidden>
    <form class="modal-panel wide" method="post" action="action.php">
        <h2 class="modal-title">フォルダ情報</h2>

        <input type="hidden" name="token" value="<?= h($token) ?>">
        <input type="hidden" name="action" value="info">
        <?= pv_context_fields($rootKey, $relative, $sort, $order, $keyword, $page) ?>

        <p class="modal-target">場所： <?= h($relative === '' ? 'ホーム' : $relative) ?></p>

        <label class="modal-field">
            <span class="modal-label">タイトル</span>
            <input type="text" name="title" autocomplete="off" maxlength="200"
                   value="<?= h($info === null ? '' : $info['title']) ?>">
        </label>

        <?php
        // いま選ばれているサムネイル。random のときは、選び直せるよう合図の文字を入れておく。
        $infoThumbName = '';
        if ($info !== null && $info['thumb'] !== null) {
            $infoThumbName = $info['thumb']['random']
                ? PV_INFO_RANDOM
                : basename($info['thumb']['path']);
        }

        // ランダムに選ぶ範囲。'self' はこのフォルダ以下、'root:<パス>' はそのフォルダ以下。
        // フォルダ名を変えても壊れないよう、このフォルダ以下は 'self' のままにしておく。
        $infoRandomFrom = 'self';
        if ($infoThumbName === PV_INFO_RANDOM && $info['thumb']['randomFrom'] !== null) {
            $infoRandomFrom = 'root:' . $info['thumb']['randomFrom'];
        }
        ?>

        <fieldset class="modal-choice">
            <legend class="modal-label">サムネイル</legend>

            <div class="thumb-picker">
                <label class="thumb-pick">
                    <input type="radio" name="thumbnail" value=""
                           <?= $infoThumbName === '' ? 'checked' : '' ?>>
                    <span class="thumb-pick-box">なし</span>
                </label>

                <label class="thumb-pick">
                    <input type="radio" name="thumbnail" value="<?= h(PV_INFO_RANDOM) ?>"
                           <?= $infoThumbName === PV_INFO_RANDOM ? 'checked' : '' ?>>
                    <span class="thumb-pick-box">ランダム</span>
                    <span class="thumb-pick-name">開くたびに変わります</span>
                </label>

                <?php foreach ($infoImages as $image): ?>
                    <label class="thumb-pick">
                        <input type="radio" name="thumbnail" value="<?= h($image['name']) ?>"
                               <?= $infoThumbName === $image['name'] ? 'checked' : '' ?>>
                        <span class="thumb-pick-box">
                            <img src="<?= h(pv_image_url($baseUrl, $relative, $image['name'])) ?>"
                                 alt="" loading="lazy" decoding="async">
                        </span>
                        <span class="thumb-pick-name" title="<?= h($image['name']) ?>"><?= h($image['name']) ?></span>
                    </label>
                <?php endforeach; ?>
            </div>

            <?php if ($infoImages === []): ?>
                <p class="modal-note">このフォルダに画像がないため、選べるサムネイルはありません。</p>
            <?php elseif ($infoImagesCut): ?>
                <p class="modal-note">画像が多いため、名前順の先頭 200 枚だけを並べています。</p>
            <?php endif; ?>

            <?php // 「ランダム」を選んだときだけ出す。切り替えは app.js が行う。 ?>
            <label class="modal-field random-from" data-random-from
                   <?= $infoThumbName === PV_INFO_RANDOM ? '' : 'hidden' ?>>
                <span class="modal-label">ランダムに選ぶ範囲</span>
                <select name="random_from">
                    <option value="self"<?= $infoRandomFrom === 'self' ? ' selected' : '' ?>>
                        このフォルダ以下
                    </option>
                    <?php foreach ($folderTree as $folder): ?>
                        <?php $value = 'root:' . $folder['path']; ?>
                        <option value="<?= h($value) ?>"<?= $infoRandomFrom === $value ? ' selected' : '' ?>>
                            <?= str_repeat('　', (int) $folder['depth']) . h($folder['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
        </fieldset>

        <div class="modal-field">
            <span class="modal-label">項目</span>

            <div class="info-rows" data-info-rows>
                <?php foreach (($info === null ? [] : $info['items']) as $index => $row): ?>
                    <div class="info-row">
                        <input type="text" name="items[<?= $index ?>][item]" maxlength="100"
                               placeholder="見出し" autocomplete="off" value="<?= h($row['item']) ?>">
                        <input type="text" name="items[<?= $index ?>][value]" maxlength="500"
                               placeholder="内容" autocomplete="off" value="<?= h($row['value']) ?>">
                        <input type="text" name="items[<?= $index ?>][url]" maxlength="500"
                               placeholder="リンク（任意）" autocomplete="off"
                               value="<?= h((string) $row['url']) ?>">
                        <span class="info-row-tools">
                            <button type="button" class="row-button" data-act="info-up" aria-label="上へ">↑</button>
                            <button type="button" class="row-button" data-act="info-down" aria-label="下へ">↓</button>
                            <button type="button" class="row-button" data-act="info-remove" aria-label="この項目を消す">×</button>
                        </span>
                    </div>
                <?php endforeach; ?>
            </div>

            <?php // 「項目を追加」で写して使う見本。name を持たないので、そのままでは送られない。 ?>
            <div class="info-row" data-info-template hidden>
                <input type="text" maxlength="100" placeholder="見出し" autocomplete="off">
                <input type="text" maxlength="500" placeholder="内容" autocomplete="off">
                <input type="text" maxlength="500" placeholder="リンク（任意）" autocomplete="off">
                <span class="info-row-tools">
                    <button type="button" class="row-button" data-act="info-up" aria-label="上へ">↑</button>
                    <button type="button" class="row-button" data-act="info-down" aria-label="下へ">↓</button>
                    <button type="button" class="row-button" data-act="info-remove" aria-label="この項目を消す">×</button>
                </span>
            </div>

            <button type="button" class="button" data-act="info-add">項目を追加</button>
        </div>

        <p class="modal-note">
            保存すると <code>info.yml</code> を書き換えます。手で書き足したコメントは残りません。
            タイトル・サムネイル・項目をすべて空にして保存すると、フォルダ情報はゴミ箱へ移動します。
        </p>

        <div class="modal-actions">
            <button type="button" class="button" data-modal-close>キャンセル</button>
            <button type="submit" class="button primary">保存する</button>
        </div>
    </form>
</div>

<div class="modal" id="mkdirModal" hidden>
    <form class="modal-panel" method="post" action="action.php">
        <h2 class="modal-title">新しいフォルダ</h2>

        <input type="hidden" name="token" value="<?= h($token) ?>">
        <input type="hidden" name="action" value="mkdir">
        <?= pv_context_fields($rootKey, $relative, $sort, $order, $keyword, $page) ?>

        <p class="modal-target">作る場所： <?= h($relative === '' ? 'ホーム' : $relative) ?></p>

        <label class="modal-field">
            <span class="modal-label">フォルダ名</span>
            <input type="text" name="name" autocomplete="off" required>
        </label>

        <div class="modal-actions">
            <button type="button" class="button" data-modal-close>キャンセル</button>
            <button type="submit" class="button primary">作成する</button>
        </div>
    </form>
</div>

<!-- ゴミ箱へ移動 -->
<div class="modal" id="deleteModal" hidden>
    <form class="modal-panel" method="post" action="action.php">
        <h2 class="modal-title">ゴミ箱へ移動</h2>

        <input type="hidden" name="token" value="<?= h($token) ?>">
        <input type="hidden" name="action" value="delete">
        <span data-paths hidden></span>
        <?= pv_context_fields($rootKey, $relative, $sort, $order, $keyword, $page) ?>

        <p class="modal-target" data-modal-target></p>

        <p class="modal-note">
            すぐには消さず、<code><?= h($config['album_url']) ?>/.trash/</code> に日時を付けて退避します。
            元に戻したいときは、サーバー上でファイルを移動してください。
        </p>

        <div class="modal-actions">
            <button type="button" class="button" data-modal-close>キャンセル</button>
            <button type="submit" class="button danger">ゴミ箱へ移動</button>
        </div>
    </form>
</div>
<?php endif; ?>

<!-- 設定。サーバーには送らず、このブラウザに覚えさせるだけの内容。 -->
<div class="modal" id="settingsModal" hidden>
    <div class="modal-panel">
        <h2 class="modal-title">設定</h2>

        <?php // 画面の狭い端末ではリスト固定にするので、この項目ごと隠す（CSS で切り替え） ?>
        <fieldset class="modal-choice view-choice">
            <legend class="modal-label">一覧の見せ方</legend>
            <label class="choice">
                <input type="radio" name="view" value="grid" data-setting="view"
                       <?= $view === 'grid' ? 'checked' : '' ?>>
                <span class="choice-text">グリッド
                    <span class="choice-note">サムネイルを並べます</span>
                </span>
            </label>
            <label class="choice">
                <input type="radio" name="view" value="list" data-setting="view"
                       <?= $view === 'list' ? 'checked' : '' ?>>
                <span class="choice-text">リスト
                    <span class="choice-note">1行ずつ並べ、押すと詳細が出ます</span>
                </span>
            </label>
        </fieldset>

        <fieldset class="modal-choice">
            <legend class="modal-label">動画を再生し始めるときの音</legend>
            <label class="choice">
                <input type="radio" name="video-muted" value="1" data-setting="videoMuted"
                       <?= $videoMuted === '1' ? 'checked' : '' ?>>
                <span class="choice-text">音を消して再生する</span>
            </label>
            <label class="choice">
                <input type="radio" name="video-muted" value="0" data-setting="videoMuted"
                       <?= $videoMuted === '0' ? 'checked' : '' ?>>
                <span class="choice-text">音を出して再生する</span>
            </label>
        </fieldset>

        <fieldset class="modal-choice">
            <legend class="modal-label">動画を再生するときの大きさ</legend>
            <label class="choice">
                <input type="radio" name="video-size" value="original" data-setting="videoSize"
                       <?= $videoSize === 'original' ? 'checked' : '' ?>>
                <span class="choice-text">動画のオリジナルサイズ
                    <span class="choice-note">画面に収まらないときだけ縮めます</span>
                </span>
            </label>
            <label class="choice">
                <input type="radio" name="video-size" value="fit" data-setting="videoSize"
                       <?= $videoSize === 'fit' ? 'checked' : '' ?>>
                <span class="choice-text">画面に合わせて最大化
                    <span class="choice-note">画面の幅いっぱいまで広げます</span>
                </span>
            </label>
        </fieldset>

        <p class="modal-note">
            この設定は、いま使っているブラウザだけに残ります。同じページを見ている他の人の画面は変わりません。
        </p>

        <div class="modal-actions">
            <button type="button" class="button" data-act="settings-reset">はじめの状態に戻す</button>
            <button type="button" class="button primary" data-modal-close>閉じる</button>
        </div>
    </div>
</div>

<!-- 拡大表示（写真）と再生（動画）を兼ねる画面。
     動画のときは viewer クラスが付き、うっかり閉じない作りに切り替わる。 -->
<div class="lightbox" id="lightbox" hidden>
    <button class="lb-close" type="button" aria-label="閉じる">
        <span class="lb-close-mark" aria-hidden="true">×</span>
        <span class="lb-label">閉じる</span>
    </button>
    <div class="lb-controls">
        <button class="lb-prev" type="button" aria-label="前へ">
            <span aria-hidden="true">‹</span><span class="lb-label">前へ</span>
        </button>
        <button class="lb-next" type="button" aria-label="次へ">
            <span class="lb-label">次へ</span><span aria-hidden="true">›</span>
        </button>
    </div>
    <figure class="lb-figure">
        <img class="lb-image" id="lbImage" src="" alt="" hidden>
        <video class="lb-video" id="lbVideo" controls playsinline preload="metadata" hidden></video>
        <figcaption class="lb-caption">
            <span class="lb-name"></span>
            <span class="lb-meta"></span>
        </figcaption>
    </figure>
</div>

<script src="assets/app.js?v=21"></script>
</body>
</html>
