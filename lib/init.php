<?php
/**
 * Media Library : 初期設定の画面（index.php に ?init を付けて開く）
 *
 * 一覧の代わりに出す、置いたばかりのライブラリを整えるための画面。
 * index.php から読み込まれるので、$config は使える状態になっている。
 *
 * ここに置くのは「一度やれば済む」種類の操作だけ。ふだんの閲覧では
 * 通らない場所なので、一覧のヘッダーからは辿れないようにしてある。
 */

// index.php から読み込まれる前提のファイル。
// 直接開かれると、読み込めない関数の名前とサーバー上の場所が表に出てしまうので、
// そのときは何も出さずに終わる。
if (!isset($config) || !function_exists('pv_csrf_token')) {
    http_response_code(404);
    exit;
}

$token    = pv_csrf_token();
$messages = pv_take_flash();
$readOnly = !empty($config['read_only']);

// ルートごとに、フォルダの数と info.yml の無い数を数えておく。
// 押す前に「あと何件なのか」がわかるようにするため。
$status       = [];
$totalMissing = 0;

foreach ($config['roots'] as $key => $rootConfig) {
    $counted = pv_count_info_status(pv_apply_root($config, $key));

    $status[] = [
        'label'   => $rootConfig['label'],
        'total'   => $counted['total'],
        'missing' => $counted['missing'],
    ];

    $totalMissing += $counted['missing'];
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= h('初期設定 | ' . $config['title']) ?></title>
<link rel="stylesheet" href="assets/style.css?v=25">
</head>
<body>

<div class="page-header">
    <header class="header">
        <h1 class="site-title"><a href="./"><?= h($config['title']) ?></a></h1>
        <span class="setup-badge">初期設定</span>
    </header>
</div>

<main class="content">
<div class="listing">

<?php foreach ($messages as $message): ?>
    <p class="notice <?= $message['type'] === 'ok' ? 'ok' : 'error' ?>"><?= h($message['message']) ?></p>
<?php endforeach; ?>

<?php if ($readOnly): ?>
    <p class="notice">
        このツールは閲覧専用の設定になっています。設定を変えるには
        <code>config.php</code> の <code>read_only</code> を <code>false</code> にしてください。
    </p>
<?php endif; ?>

<section class="setup-section">
    <h2 class="setup-title">フォルダ情報を作成する</h2>

    <p class="setup-text">
        <code>info.yml</code> のないフォルダに、まとめて作ります。見出しはフォルダ名、
        サムネイルはそのフォルダの画像をファイル名の昇順に並べた先頭の1枚です。
    </p>

    <p class="setup-text">
        すでに <code>info.yml</code> のあるフォルダには手を触れないので、
        何度実行しても、手で書いた内容が消えることはありません。
        写真・動画のトップは、フォルダ名がそのまま見出しになってしまうため対象外です。
    </p>

    <dl class="setup-status">
        <?php foreach ($status as $row): ?>
            <dt><?= h($row['label']) ?></dt>
            <dd>
                <?= (int) $row['total'] ?> フォルダ中
                <strong><?= (int) $row['missing'] ?></strong> 件が未作成
            </dd>
        <?php endforeach; ?>
    </dl>

    <form method="post" action="action.php">
        <input type="hidden" name="token" value="<?= h($token) ?>">
        <input type="hidden" name="action" value="init-info">

        <button type="submit" class="button primary"
                <?= $readOnly || $totalMissing === 0 ? 'disabled' : '' ?>>フォルダ情報を作成する</button>

        <?php if (!$readOnly && $totalMissing === 0): ?>
            <span class="setup-note">未作成のフォルダはありません。</span>
        <?php endif; ?>
    </form>
</section>

<p class="setup-back"><a href="./">一覧へ戻る</a></p>

</div><!-- /.listing -->
</main>

</body>
</html>
