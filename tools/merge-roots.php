#!/usr/bin/env php
<?php
/**
 * Media Library : 写真と動画のフォルダを1つにまとめる
 *
 * photos/ と movies/ のように分かれているフォルダを、1つのフォルダ（既定では
 * media/）にまとめる。同じ名前のフォルダは合流させ、同じ名前のファイルが
 * ぶつかったときは、何もせずに知らせる。
 *
 * 何もオプションを付けなければ、書き込みは一切せずに「何が起きるか」だけを出す。
 * まず下見をして、ぶつかっているものを確かめてから --apply を付けてほしい。
 *
 * 使い方（media-library のフォルダで実行する）
 *
 *   php tools/merge-roots.php                 … 何が起きるかだけを見る（既定）
 *   php tools/merge-roots.php --apply         … 実際にまとめる
 *   php tools/merge-roots.php --into=photos   … まとめ先を変える（既定: media）
 *   php tools/merge-roots.php --from=movies   … まとめるルートを絞る
 *   php tools/merge-roots.php --apply --on-conflict=skip
 *                                             … ぶつかったものは元の場所に残す
 *   php tools/merge-roots.php --apply --on-conflict=rename
 *                                             … ぶつかったものに元のルート名を付けて移す
 *
 * ファイルは移動（rename）する。同じディスクの中であれば中身は読み書きされないので、
 * 量が多くても待たされない。コピーではないので、元のフォルダからは無くなる。
 *
 * 消すことは一切しない。動かさなかったものと、空になったフォルダは元のルートに
 * 残るので、中身を確かめてから手で片付けてほしい。
 *
 * 「.」で始まる名前（.trash / .upload / .htaccess / .gitkeep など）は動かさない。
 * ゴミ箱や取り込み中のファイルまで持ち込まないため。ただし、まとめ先に .htaccess が
 * 無いときだけは、元のルートのものを写す（そのフォルダでのスクリプトの実行を
 * 禁止する設定が抜けたままになるのを防ぐため）。
 * なお、まるごと移すフォルダの中は開かずに動かすので、その中の隠しファイルは
 * 一緒に移る。ゴミ箱と取り込みの作業場所はルート直下にしかできないため、
 * そこが持ち込まれることはない。
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../lib/functions.php';

exit(pv_merge_main($argv));

/**
 * 引数を読んで、ルートを順にまとめ先へ移していく。
 * ぶつかったものがあって中止したとき、または移せなかったものがあったときだけ、
 * 終了コード 1 を返す。
 */
function pv_merge_main(array $argv): int
{
    $apply    = false;
    $into     = 'media';
    $from     = null;
    $conflict = 'stop';
    $onInfo   = 'merge';

    foreach (array_slice($argv, 1) as $arg) {
        if ($arg === '--apply') {
            $apply = true;
        } elseif (strpos($arg, '--into=') === 0) {
            $into = substr($arg, strlen('--into='));
        } elseif (strpos($arg, '--from=') === 0) {
            $from = array_values(array_filter(array_map(
                'trim',
                explode(',', substr($arg, strlen('--from=')))
            ), 'strlen'));
        } elseif (strpos($arg, '--on-conflict=') === 0) {
            $conflict = substr($arg, strlen('--on-conflict='));
        } elseif (strpos($arg, '--info=') === 0) {
            $onInfo = substr($arg, strlen('--info='));
        } elseif ($arg === '--help' || $arg === '-h') {
            pv_merge_usage();

            return 0;
        } else {
            fwrite(STDERR, "知らないオプションです: {$arg}\n\n");
            pv_merge_usage();

            return 1;
        }
    }

    if (!in_array($conflict, ['stop', 'skip', 'rename'], true)) {
        fwrite(STDERR, "--on-conflict には stop / skip / rename のどれかを指定してください。\n");

        return 1;
    }

    if (!in_array($onInfo, ['merge', 'stop'], true)) {
        fwrite(STDERR, "--info には merge / stop のどちらかを指定してください。\n");

        return 1;
    }

    $configPath = __DIR__ . '/../config.php';

    if (!is_file($configPath)) {
        fwrite(STDERR, "config.php が見つかりません: {$configPath}\n");

        return 1;
    }

    $config = require $configPath;
    $roots  = $config['roots'] ?? [];

    if (!is_array($roots) || $roots === []) {
        fwrite(STDERR, "config.php の roots が空です。\n");

        return 1;
    }

    // まとめ先。roots のキーならそのフォルダ、そうでなければ config.php と同じ場所に作る。
    if (isset($roots[$into])) {
        $targetDir = (string) ($roots[$into]['dir'] ?? '');
    } else {
        if ($into === '' || $into[0] === '.' || strpbrk($into, '/\\') !== false) {
            fwrite(STDERR, "--into には roots のキーか、フォルダ名を1つ指定してください: {$into}\n");

            return 1;
        }

        $targetDir = dirname(__DIR__) . '/' . $into;
    }

    if ($targetDir === '') {
        fwrite(STDERR, "まとめ先のフォルダを決められませんでした。\n");

        return 1;
    }

    if ($from !== null) {
        $unknown = array_diff($from, array_keys($roots));

        if ($unknown !== []) {
            fwrite(STDERR, "config.php に、そのルートはありません: " . implode(', ', $unknown) . "\n");

            return 1;
        }
    }

    // まとめるルート。まとめ先そのものは、移す必要がないので外す。
    $targetReal = realpath($targetDir);
    $sources    = [];

    foreach ($roots as $key => $root) {
        if ($from !== null && !in_array($key, $from, true)) {
            continue;
        }

        $dir = (string) ($root['dir'] ?? '');

        if ($dir === '') {
            continue;
        }

        if ($targetReal !== false && realpath($dir) === $targetReal) {
            continue;
        }

        $sources[$key] = $dir;
    }

    if ($sources === []) {
        fwrite(STDERR, "まとめるルートがありません。--from と --into を確かめてください。\n");

        return 1;
    }

    $opts = [
        'conflict' => $conflict,
        'info'     => $onInfo,
        'trash'    => (string) ($config['trash_dir'] ?? '.trash'),
        'upload'   => (string) ($config['upload_temp_dir'] ?? '.upload'),
    ];

    // まず黙って下見をして、ぶつかっているものが無いかを数える。
    // --on-conflict=stop のときは、1件でもあれば何も動かさずに終わりたいため。
    $probe   = pv_merge_run($sources, $targetDir, ['apply' => false, 'quiet' => true] + $opts);
    $blocked = $apply && $conflict === 'stop' && $probe['conflicts'] > 0;

    if ($blocked) {
        echo "［中止］同じ名前のものがぶつかっています。何も動かしていません。\n";
        echo "　　　　下に出すものを片付けてから、もう一度実行してください。\n";
        echo "　　　　ぶつかったものを元の場所に残して先に進むには --on-conflict=skip、\n";
        echo "　　　　元のルート名を付けて移すには --on-conflict=rename を付けてください。\n\n";
    } elseif (!$apply) {
        echo "［下見］書き込みはしません。実際にまとめるには --apply を付けてください。\n\n";
    }

    $total = pv_merge_run(
        $sources,
        $targetDir,
        ['apply' => $apply && !$blocked, 'quiet' => false] + $opts
    );

    printf(
        "\nフォルダ %d / ファイル %d を移動、衝突 %d 件、改名 %d 件、フォルダ情報の統合 %d 件、失敗 %d 件\n",
        $total['dirs'],
        $total['files'],
        $total['conflicts'],
        $total['renamed'],
        $total['info'],
        $total['failed']
    );

    if ($blocked) {
        return 1;
    }

    if (!$apply) {
        echo "\n実際にまとめるには --apply を付けて、もう一度実行してください。\n";

        return 0;
    }

    echo "\n動かさなかったもの（隠しファイルなど）と、空になったフォルダは元のルートに残ります。\n";
    echo "中身を確かめてから、手で片付けてください。\n";
    echo "このあと config.php の roots を、まとめ先の1つだけに書き換えてください。\n";

    return $total['failed'] > 0 ? 1 : 0;
}

/**
 * ルートを順に、まとめ先へ移していく。
 * 数えた結果を ['dirs' => …, 'files' => …, 'conflicts' => …,
 * 'renamed' => …, 'info' => …, 'failed' => …] で返す。
 */
function pv_merge_run(array $sources, string $targetDir, array $opts): array
{
    $totals = [
        'dirs' => 0, 'files' => 0, 'conflicts' => 0,
        'renamed' => 0, 'info' => 0, 'failed' => 0,
    ];

    // 下見のときは、移したつもりのものを覚えておく。
    // 2つめ以降のルートが、そこにぶつかるかどうかを見るため。
    $state = [];
    $quiet = !empty($opts['quiet']);

    if (!$quiet) {
        echo "まとめ先： {$targetDir}\n\n";
    }

    if (!is_dir($targetDir)) {
        if (!$quiet) {
            echo "  作成    " . basename($targetDir) . "/    （まだありません）\n\n";
        }

        if (!empty($opts['apply']) && !@mkdir($targetDir, 0755, true)) {
            fwrite(STDERR, "まとめ先のフォルダを作れません: {$targetDir}\n");
            $totals['failed']++;

            return $totals;
        }
    }

    foreach ($sources as $key => $dir) {
        if (!is_dir($dir)) {
            if (!$quiet) {
                echo "[{$key}] フォルダが見つかりません: {$dir}\n\n";
            }

            continue;
        }

        if (!$quiet) {
            echo "[{$key}] {$dir}\n";
        }

        pv_merge_walk($dir, $targetDir, '', ['root' => $key] + $opts, $state, $totals);

        if (!$quiet) {
            echo "\n";
        }
    }

    return $totals;
}

/**
 * まとめ先に .htaccess が無ければ、元のルートのものを写す。
 * そのフォルダでのスクリプトの実行を禁止する設定なので、抜けたままにしない。
 * 写したかどうかを返す（写したときは「残す」を出さない）。
 */
function pv_merge_htaccess(string $src, string $dst, string $rel, array $opts, array &$state, array &$totals): bool
{
    if (!is_file($src) || pv_merge_kind($dst, $rel, $state) !== null) {
        return false;
    }

    $quiet = !empty($opts['quiet']);

    if (!$quiet) {
        echo "  写す    {$rel}    （まとめ先にまだありません）\n";
    }

    if (empty($opts['apply'])) {
        $state[$rel] = ['kind' => 'file', 'path' => $src, 'root' => $opts['root']];

        return true;
    }

    if (!@copy($src, $dst)) {
        if (!$quiet) {
            echo "  失敗    {$rel}    （写せません。書き込み権限を確かめてください）\n";
        }

        $totals['failed']++;

        return true;
    }

    @chmod($dst, 0644);

    return true;
}

/**
 * フォルダを1つずつ降りながら、中身をまとめ先へ移す。
 */
function pv_merge_walk(string $srcDir, string $dstDir, string $relative, array $opts, array &$state, array &$totals): void
{
    $entries = pv_merge_entries($srcDir);
    $quiet   = !empty($opts['quiet']);

    foreach ($entries['hidden'] as $name) {
        $rel = pv_merge_path($relative, $name);

        // ルート直下の .htaccess だけは、まとめ先に無ければ写す
        if ($name === '.htaccess' && $relative === ''
            && pv_merge_htaccess($srcDir . '/' . $name, $dstDir . '/' . $name, $rel, $opts, $state, $totals)) {
            continue;
        }

        if (!$quiet) {
            $mark = is_dir($srcDir . '/' . $name) ? '/' : '';
            echo "  残す    {$rel}{$mark}    （「.」で始まる名前は動かしません）\n";
        }
    }

    foreach ($entries['visible'] as $name) {
        $src = $srcDir . '/' . $name;
        $dst = $dstDir . '/' . $name;
        $rel = pv_merge_path($relative, $name);

        // リンクは辿らない。指し先ごと動かすと、どこを指していたのかが分からなくなる。
        if (is_link($src)) {
            if (!$quiet) {
                echo "  残す    {$rel}    （リンクは動かしません）\n";
            }

            continue;
        }

        $dstKind = pv_merge_kind($dst, $rel, $state);

        if (is_dir($src)) {
            if ($dstKind === null) {
                // まとめ先に同じ名前が無いので、開かずにまるごと動かす
                $before = $totals['dirs'] + $totals['files'];
                pv_merge_register($src, $rel, $opts, $state, $totals);
                $inside = $totals['dirs'] + $totals['files'] - $before - 1;

                if (!$quiet) {
                    echo "  移動    {$rel}/    （中の {$inside} 件ごと）\n";
                }

                if (!empty($opts['apply']) && !@rename($src, $dst)) {
                    if (!$quiet) {
                        echo "  失敗    {$rel}/    （動かせません。書き込み権限を確かめてください）\n";
                    }

                    $totals['failed']++;
                }

                continue;
            }

            if ($dstKind === 'dir') {
                if (!$quiet) {
                    echo "  合流    {$rel}/\n";
                }

                pv_merge_walk($src, $dst, $rel, $opts, $state, $totals);

                continue;
            }

            pv_merge_conflict($src, $dst, $rel, true, $opts, $state, $totals);

            continue;
        }

        if ($dstKind === null) {
            if (!$quiet) {
                echo "  移動    {$rel}\n";
            }

            if (!empty($opts['apply']) && !@rename($src, $dst)) {
                if (!$quiet) {
                    echo "  失敗    {$rel}    （動かせません。書き込み権限を確かめてください）\n";
                }

                $totals['failed']++;

                continue;
            }

            if (empty($opts['apply'])) {
                $state[$rel] = ['kind' => 'file', 'path' => $src, 'root' => $opts['root']];
            }

            $totals['files']++;

            continue;
        }

        // 両方にフォルダ情報があるときは、ぶつかりではなく1つにまとめる
        if ($name === PV_INFO_FILE && $dstKind === 'file' && $opts['info'] === 'merge') {
            pv_merge_info($src, $dst, $rel, $opts, $state, $totals);

            continue;
        }

        pv_merge_conflict($src, $dst, $rel, is_dir($src), $opts, $state, $totals);
    }
}

/**
 * 同じ名前のものがぶつかったときの始末。
 * --on-conflict=rename のときだけ、元のルート名を付けた名前で移す。
 */
function pv_merge_conflict(string $src, string $dst, string $rel, bool $srcIsDir, array $opts, array &$state, array &$totals): void
{
    $quiet = !empty($opts['quiet']);
    $totals['conflicts']++;

    if ($quiet) {
        return;
    }

    $there = pv_merge_at($dst, $rel, $state);

    echo "  衝突    {$rel}\n";
    echo "          " . pv_merge_about($src, (string) $opts['root']) . "\n";
    echo "          " . pv_merge_about($there['path'], $there['label']) . "\n";

    if (!$srcIsDir && is_file($src) && is_file($there['path'])
        && filesize($src) === filesize($there['path'])
        && filemtime($src) === filemtime($there['path'])) {
        echo "          ↑ 大きさも日時も同じです。同じファイルかもしれません\n";
    }

    if ($opts['conflict'] !== 'rename' || $srcIsDir) {
        echo "          元の場所に残します\n";

        return;
    }

    // ぶつかった名前に、元のルート名を足して空いている名前を探す
    $name = pv_merge_free_name(dirname($dst), basename($rel), (string) $opts['root'], $rel, $state);
    $to   = pv_merge_path(dirname($rel) === '.' ? '' : dirname($rel), $name);

    echo "          {$name} という名前で移します\n";

    if (!empty($opts['apply']) && !@rename($src, dirname($dst) . '/' . $name)) {
        echo "  失敗    {$rel}    （動かせません。書き込み権限を確かめてください）\n";
        $totals['failed']++;

        return;
    }

    if (empty($opts['apply'])) {
        $state[$to] = ['kind' => 'file', 'path' => $src, 'root' => $opts['root']];
    }

    $totals['renamed']++;
    $totals['files']++;
}

/**
 * 両方のフォルダに info.json があるときに、1つにまとめる。
 *
 * 見出し・サムネイルは、先に入っていたほう（まとめ先）を残す。項目は、
 * まとめ先に無い見出しのものだけを後ろに足す。ピン留めは両方を並べる。
 * 元の info.json には手を触れないので、気に入らなければ手で直せる。
 */
function pv_merge_info(string $src, string $dst, string $rel, array $opts, array &$state, array &$totals): void
{
    $quiet = !empty($opts['quiet']);
    $totals['info']++;

    // 下見では、まとめ先にはまだ何も無いので、そこへ移すことにしたほうを読む
    $there = pv_merge_at($dst, $rel, $state);

    $kept  = pv_info_decode((string) @file_get_contents($there['path']));
    $added = pv_info_decode((string) @file_get_contents($src));

    $title     = pv_merge_pick($kept, $added, 'title');
    $thumbnail = pv_merge_pick($kept, $added, 'thumbnail');

    // 項目は、見出しが重なっていないものだけを足す
    $items = pv_merge_items($kept['items'] ?? []);
    $seen  = [];

    foreach ($items as $row) {
        $seen[$row['item']] = true;
    }

    $extra = 0;

    foreach (pv_merge_items($added['items'] ?? []) as $row) {
        if (isset($seen[$row['item']])) {
            continue;
        }

        $items[] = $row;
        $extra++;
    }

    // ピン留めは、まとめ先のものを先に、重なりを除いて並べる
    $pinned = [];

    foreach (array_merge(pv_info_names($kept['pinned'] ?? []), pv_info_names($added['pinned'] ?? [])) as $one) {
        if (!in_array($one, $pinned, true)) {
            $pinned[] = $one;
        }
    }

    $pinned = array_slice($pinned, 0, PV_PIN_LIMIT);

    if (!$quiet) {
        echo "  情報    {$rel}    （両方にあります）\n";

        $keptTitle  = trim((string) ($kept['title'] ?? ''));
        $addedTitle = trim((string) ($added['title'] ?? ''));

        if ($keptTitle !== '' && $addedTitle !== '' && $keptTitle !== $addedTitle) {
            echo "          見出しは「{$keptTitle}」を残します（「{$addedTitle}」は使いません）\n";
        }

        $keptThumb  = trim((string) ($kept['thumbnail'] ?? ''));
        $addedThumb = trim((string) ($added['thumbnail'] ?? ''));

        if ($keptThumb !== '' && $addedThumb !== '' && $keptThumb !== $addedThumb) {
            echo "          サムネイルは {$keptThumb} を残します（{$addedThumb} は使いません）\n";
        }

        if ($extra > 0) {
            echo "          項目を {$extra} 件足します\n";
        }

        echo "          元の info.json は {$opts['root']} に残します\n";
    }

    if (empty($opts['apply'])) {
        return;
    }

    $json = pv_info_encode($title, $thumbnail, $items, $pinned);

    if ($json === '' || @file_put_contents($dst, $json, LOCK_EX) === false) {
        if (!$quiet) {
            echo "  失敗    {$rel}    （まとめた info.json を書けません）\n";
        }

        $totals['failed']++;

        return;
    }

    @chmod($dst, 0644);
}

/**
 * info.json の値を、まとめ先を先に、空のときだけもう一方から取る。
 */
function pv_merge_pick(array $kept, array $added, string $key): string
{
    $value = trim((string) ($kept[$key] ?? ''));

    return $value !== '' ? $value : trim((string) ($added[$key] ?? ''));
}

/**
 * info.json の items を、item / value / url の組に整える。
 */
function pv_merge_items($raw): array
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
 * ぶつかったファイルに付ける、空いている名前を探す。
 * 「b.mp4」は「b-movies.mp4」に、それも埋まっていれば「b-movies-2.mp4」にする。
 */
function pv_merge_free_name(string $dstDir, string $name, string $root, string $rel, array $state): string
{
    $dot  = strrpos($name, '.');
    $base = $dot === false || $dot === 0 ? $name : substr($name, 0, $dot);
    $ext  = $dot === false || $dot === 0 ? '' : substr($name, $dot);
    $dir  = dirname($rel) === '.' ? '' : dirname($rel);

    for ($i = 1; $i < 1000; $i++) {
        $try = $base . '-' . $root . ($i === 1 ? '' : '-' . $i) . $ext;

        if (pv_merge_kind($dstDir . '/' . $try, pv_merge_path($dir, $try), $state) === null) {
            return $try;
        }
    }

    return $base . '-' . $root . '-' . bin2hex(random_bytes(4)) . $ext;
}

/**
 * まとめ先にすでにあるもの（または下見で移したことにしたもの）の種類を返す。
 * まだ何も無いときは null。
 */
function pv_merge_kind(string $path, string $relative, array $state): ?string
{
    if (isset($state[$relative])) {
        return (string) $state[$relative]['kind'];
    }

    if (is_dir($path)) {
        return 'dir';
    }

    return file_exists($path) ? 'file' : null;
}

/**
 * まとめ先にあるものの在りかと、それがどこから来たのかを返す。
 * 下見では、まだ移していないので、移す前の場所をそのまま指す。
 */
function pv_merge_at(string $path, string $relative, array $state): array
{
    if (isset($state[$relative])) {
        return [
            'path'  => (string) $state[$relative]['path'],
            'label' => (string) $state[$relative]['root'],
        ];
    }

    return ['path' => $path, 'label' => 'まとめ先'];
}

/**
 * まるごと移すフォルダの中身を数え、まとめ先に現れる道順を控える。
 */
function pv_merge_register(string $dir, string $relative, array $opts, array &$state, array &$totals): void
{
    // 実際に動かすときは、動かしたあとの場所が本当のことを教えてくれるので控えない
    $record = empty($opts['apply']);

    if ($record) {
        $state[$relative] = ['kind' => 'dir', 'path' => $dir, 'root' => $opts['root']];
    }

    $totals['dirs']++;

    foreach (pv_merge_entries($dir)['visible'] as $name) {
        $path = $dir . '/' . $name;
        $rel  = pv_merge_path($relative, $name);

        if (is_dir($path) && !is_link($path)) {
            pv_merge_register($path, $rel, $opts, $state, $totals);

            continue;
        }

        if ($record) {
            $state[$rel] = ['kind' => 'file', 'path' => $path, 'root' => $opts['root']];
        }

        $totals['files']++;
    }
}

/**
 * フォルダの中身を名前順に並べ、見える名前と「.」で始まる名前に分けて返す。
 */
function pv_merge_entries(string $dir): array
{
    $visible = [];
    $hidden  = [];

    $handle = @opendir($dir);

    if ($handle === false) {
        return ['visible' => $visible, 'hidden' => $hidden];
    }

    while (($name = readdir($handle)) !== false) {
        if ($name === '.' || $name === '..') {
            continue;
        }

        if ($name[0] === '.') {
            $hidden[] = $name;

            continue;
        }

        $visible[] = $name;
    }

    closedir($handle);

    sort($visible, SORT_STRING);
    sort($hidden, SORT_STRING);

    return ['visible' => $visible, 'hidden' => $hidden];
}

/**
 * ルートからの道順をつなぐ。
 */
function pv_merge_path(string $relative, string $name): string
{
    return $relative === '' ? $name : $relative . '/' . $name;
}

/**
 * 衝突したものの大きさと日時を、1行にして返す。
 */
function pv_merge_about(string $path, string $label): string
{
    if (is_dir($path)) {
        return $label . '： フォルダ';
    }

    if (!is_file($path)) {
        return $label . '： 見つかりません';
    }

    return $label . '： ' . pv_human_size((int) filesize($path))
        . ' / ' . date('Y-m-d H:i', (int) filemtime($path));
}

/**
 * 使い方を出す。
 */
function pv_merge_usage(): void
{
    echo <<<TEXT
写真と動画のフォルダを1つにまとめます。消すことは一切しません。

  php tools/merge-roots.php                  何が起きるかだけを見る（既定）
  php tools/merge-roots.php --apply          実際にまとめる
  php tools/merge-roots.php --into=media     まとめ先（既定: media）
  php tools/merge-roots.php --from=photos,movies
                                             まとめるルート（既定: roots のすべて）

同じ名前のものがぶつかったときの始末（--on-conflict）

  stop    1件でもあれば、何も動かさずに知らせる（既定）
  skip    ぶつかったものは元の場所に残し、ほかを移す
  rename  ぶつかったものに元のルート名を付けて移す（b.mp4 → b-movies.mp4）

両方のフォルダに info.json があったときの始末（--info）

  merge   1つにまとめる。見出しとサムネイルはまとめ先を残す（既定）
  stop    ぶつかったものとして扱う

--into には roots のキーか、config.php と同じ場所に作るフォルダ名を指定します。
「.」で始まる名前（.trash / .upload / .htaccess など）は動かしません。

TEXT;
}
