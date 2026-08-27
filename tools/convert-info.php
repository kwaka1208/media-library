#!/usr/bin/env php
<?php
/**
 * Media Library : info.yml を info.json に一括変換する
 *
 * フォルダ情報の置き場所が info.yml から info.json に変わったため、
 * すでに置いてある info.yml を読んで、同じ内容の info.json を書き出す。
 *
 * 元の info.yml には手を触れない。変換したあと中身を確かめてから、
 * 手で消すなり残すなりしてほしい。アプリは info.json しか見ないので、
 * 残っていても表示には影響しない。
 *
 * 使い方（media-library のフォルダで実行する）
 *
 *   php tools/convert-info.php            … 何が起きるかだけを見る（既定）
 *   php tools/convert-info.php --apply    … 実際に info.json を書き出す
 *   php tools/convert-info.php --apply --force
 *                                         … すでにある info.json も上書きする
 *   php tools/convert-info.php --root=photos
 *                                         … 写真のフォルダだけを対象にする
 *
 * 変換されるのは title / thumbnail / items の3つ。
 * それ以外のキーと、手で書き足したコメントは引き継がれない
 * （アプリがもともとこの3つしか見ていないため）。書かれていた場合は知らせる。
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/yaml-read.php';
require_once __DIR__ . '/../lib/json.php';

exit(pv_convert_main($argv));

/**
 * 引数を読んで、ルートごとに変換して回る。
 * 1つでも書き出しに失敗したときだけ、終了コード 1 を返す。
 */
function pv_convert_main(array $argv): int
{
    $apply  = false;
    $force  = false;
    $onlyRoot = null;

    foreach (array_slice($argv, 1) as $arg) {
        if ($arg === '--apply') {
            $apply = true;
        } elseif ($arg === '--force') {
            $force = true;
        } elseif (strpos($arg, '--root=') === 0) {
            $onlyRoot = substr($arg, strlen('--root='));
        } elseif ($arg === '--help' || $arg === '-h') {
            pv_convert_usage();

            return 0;
        } else {
            fwrite(STDERR, "知らないオプションです: {$arg}\n\n");
            pv_convert_usage();

            return 1;
        }
    }

    $configPath = __DIR__ . '/../config.php';

    if (!is_file($configPath)) {
        fwrite(STDERR, "config.php が見つかりません: {$configPath}\n");

        return 1;
    }

    $config = require $configPath;
    $roots  = $config['roots'] ?? [];

    if ($onlyRoot !== null && !isset($roots[$onlyRoot])) {
        fwrite(STDERR, "config.php に、そのルートはありません: {$onlyRoot}\n");

        return 1;
    }

    // 一時ファイルやゴミ箱の中は対象にしない
    $skipDirs = [
        (string) ($config['trash_dir'] ?? '.trash'),
        (string) ($config['upload_temp_dir'] ?? '.upload'),
    ];

    if (!$apply) {
        echo "［下見］書き込みはしません。実際に変換するには --apply を付けてください。\n\n";
    }

    $total = ['found' => 0, 'written' => 0, 'skipped' => 0, 'failed' => 0];

    foreach ($roots as $key => $root) {
        if ($onlyRoot !== null && $key !== $onlyRoot) {
            continue;
        }

        $dir = (string) ($root['dir'] ?? '');

        if ($dir === '' || !is_dir($dir)) {
            echo "[{$key}] フォルダが見つかりません: {$dir}\n\n";
            continue;
        }

        echo "[{$key}] {$dir}\n";

        $count = pv_convert_walk($dir, $dir, $skipDirs, $apply, $force);

        foreach ($count as $name => $value) {
            $total[$name] += $value;
        }

        echo "\n";
    }

    printf(
        "info.yml %d件 / 変換 %d件 / とばした %d件 / 失敗 %d件\n",
        $total['found'],
        $total['written'],
        $total['skipped'],
        $total['failed']
    );

    if (!$apply && $total['found'] > 0) {
        echo "\n実際に変換するには --apply を付けて、もう一度実行してください。\n";
    }

    return $total['failed'] > 0 ? 1 : 0;
}

/**
 * フォルダを1つずつ降りながら info.yml を探して変換する。
 * 数えた結果を ['found' => …, 'written' => …, 'skipped' => …, 'failed' => …] で返す。
 */
function pv_convert_walk(string $dir, string $rootDir, array $skipDirs, bool $apply, bool $force): array
{
    $count = ['found' => 0, 'written' => 0, 'skipped' => 0, 'failed' => 0];

    $source = $dir . '/info.yml';

    if (is_file($source)) {
        $count['found']++;

        $result = pv_convert_one($dir, $rootDir, $apply, $force);
        $count[$result]++;
    }

    $handle = @opendir($dir);

    if ($handle === false) {
        return $count;
    }

    $names = [];

    while (($name = readdir($handle)) !== false) {
        if ($name === '.' || $name === '..') {
            continue;
        }

        $path = $dir . '/' . $name;

        // リンクは辿らない。同じ場所を何度も回らないため。
        if (!is_dir($path) || is_link($path)) {
            continue;
        }

        if (in_array($name, $skipDirs, true)) {
            continue;
        }

        $names[] = $name;
    }

    closedir($handle);

    sort($names, SORT_STRING);

    foreach ($names as $name) {
        $deeper = pv_convert_walk($dir . '/' . $name, $rootDir, $skipDirs, $apply, $force);

        foreach ($deeper as $key => $value) {
            $count[$key] += $value;
        }
    }

    return $count;
}

/**
 * フォルダ1つぶんの変換。'written' / 'skipped' / 'failed' のどれかを返す。
 */
function pv_convert_one(string $dir, string $rootDir, bool $apply, bool $force): string
{
    $source = $dir . '/info.yml';
    $target = $dir . '/info.json';

    // 画面に出すのは、ルートからの道順だけにしておく
    $label = substr($dir, strlen($rootDir));
    $label = $label === '' ? '.' : ltrim($label, '/');

    if (file_exists($target) && !$force) {
        echo "  とばす  {$label}  （info.json がすでにあります）\n";

        return 'skipped';
    }

    $raw = @file_get_contents($source);

    if ($raw === false) {
        echo "  失敗    {$label}  （info.yml を読めません）\n";

        return 'failed';
    }

    $data = pv_yaml_parse($raw);

    $title     = trim((string) ($data['title'] ?? ''));
    $thumbnail = trim((string) ($data['thumbnail'] ?? ''));
    $items     = pv_convert_items($data['items'] ?? []);

    if ($title === '' && $thumbnail === '' && $items === []) {
        echo "  とばす  {$label}  （中身が空です）\n";

        return 'skipped';
    }

    $json = pv_info_encode($title, $thumbnail, $items);

    if ($json === '') {
        echo "  失敗    {$label}  （書き出す内容を作れませんでした）\n";

        return 'failed';
    }

    if ($apply) {
        if (@file_put_contents($target, $json, LOCK_EX) === false) {
            echo "  失敗    {$label}  （info.json を書けません。書き込み権限を確かめてください）\n";

            return 'failed';
        }

        @chmod($target, 0644);
    }

    echo "  変換    {$label}\n";

    // 引き継がれないキーがあれば、変換したあとに知らせる
    $extra = array_diff(array_keys($data), ['title', 'thumbnail', 'items']);

    if ($extra !== []) {
        echo "          ↑ " . implode(' / ', $extra) . " は引き継がれません\n";
    }

    return 'written';
}

/**
 * YAML の items を、item / value / url の組に整える。
 * item も value も空の行は、表示するものが無いので落とす。
 */
function pv_convert_items($raw): array
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
            'url'   => trim((string) ($one['url'] ?? '')),
        ];
    }

    return $items;
}

/**
 * 使い方を出す。
 */
function pv_convert_usage(): void
{
    echo <<<TEXT
info.yml を info.json に一括変換します。元の info.yml には手を触れません。

  php tools/convert-info.php                 何が起きるかだけを見る（既定）
  php tools/convert-info.php --apply         実際に info.json を書き出す
  php tools/convert-info.php --apply --force すでにある info.json も上書きする
  php tools/convert-info.php --root=photos   指定したルートだけを対象にする

対象は config.php の roots に書いたフォルダです。
ゴミ箱（trash_dir）とアップロードの作業場所（upload_temp_dir）は対象外です。

TEXT;
}
