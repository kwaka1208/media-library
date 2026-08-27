<?php
/**
 * Media Library : ドラッグ＆ドロップで取り込んだファイルを受け取る
 *
 * 大きな動画でも上げられるよう、ファイルはブラウザ側で小さく切り分けて送る。
 * ここではその「かけら」を1つずつ受け取り、置き場所で継ぎ足していき、
 * 最後のかけらが届いた時点で、開いているフォルダへ移す。
 *
 * こうしているのは、共有サーバーの upload_max_filesize / post_max_size /
 * max_execution_time がどれも小さめに設定されていることが多いため。
 * 1回のリクエストが小さいままなら、どの上限にも引っかからない。
 *
 * 組み立て途中のものは <ルート>/.upload/ に置く。ドットで始まる名前なので
 * 一覧には出ず、pv_resolve_path() もその下を指せない。
 */

/**
 * 受け取ってよいファイル名かを確かめる。
 * 問題がなければ整えた名前、問題があれば null を返す。
 *
 * ブラウザから届く名前は、そのままでは信用できない。フォルダ区切りを
 * 落としてから、名前としての決まり（pv_validate_name）と、
 * 表示できる拡張子かどうかを見る。
 */
function pv_upload_clean_name(array $config, string $name): ?string
{
    // 「dir/name.mp4」「dir\name.mp4」のような形で届いても、名前だけを取る
    $name = str_replace('\\', '/', $name);
    $parts = explode('/', $name);
    $name = trim((string) end($parts));

    if (pv_validate_name($name, (int) $config['max_name_length']) !== null) {
        return null;
    }

    $ext = strtolower((string) pathinfo($name, PATHINFO_EXTENSION));

    if (!in_array($ext, $config['extensions'], true)) {
        return null;
    }

    return $name;
}

/**
 * 落とした先の中に作る、入れ子のフォルダを用意する。
 *
 * フォルダごと落としたときに使う。$sub は「2026春/海」のような、
 * 落とした先からの道順。1つずつ名前を確かめてから作るので、
 * 「..」や隠し名で外へ出ることはない。
 *
 * 作れなかったときは null を返す。
 */
function pv_upload_prepare_dir(array $config, string $dir, string $sub): ?string
{
    $sub = pv_clean_relative($sub);

    if ($sub === '') {
        return $dir;
    }

    $target = $dir;

    foreach (explode('/', $sub) as $segment) {
        if (pv_validate_name($segment, (int) $config['max_name_length']) !== null) {
            return null;
        }

        $target .= '/' . $segment;

        if (is_link($target)) {
            return null;
        }

        if (!is_dir($target) && !@mkdir($target, 0755)) {
            return null;
        }
    }

    return is_dir($target) ? $target : null;
}

/**
 * 置き場所（<ルート>/.upload）を用意して返す。作れなければ null。
 */
function pv_upload_temp_dir(array $config): ?string
{
    $root = realpath($config['album_dir']);

    if ($root === false) {
        return null;
    }

    $temp = $root . '/' . trim(str_replace('\\', '/', (string) $config['upload_temp_dir']), '/');

    if (!is_dir($temp) && !@mkdir($temp, 0755, true)) {
        return null;
    }

    // 組み立て途中のものをブラウザから直接開けないようにしておく
    $guard = $temp . '/.htaccess';

    if (!file_exists($guard)) {
        @file_put_contents($guard, "# 組み立て途中のファイルはブラウザから参照させない\n"
            . "<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n"
            . "<IfModule !mod_authz_core.c>\n    Order allow,deny\n    Deny from all\n</IfModule>\n");
    }

    return $temp;
}

/**
 * 途中で放り出された組み立てかけのファイルを片付ける。
 *
 * 送っている最中にブラウザを閉じると、かけらの途中まで書いたものが残る。
 * 誰も続きを送ってこないので、1日たったものは消してよい。
 */
function pv_upload_sweep(string $temp, int $maxAge = 86400): void
{
    $handle = @opendir($temp);

    if ($handle === false) {
        return;
    }

    $limit = time() - $maxAge;

    while (($name = readdir($handle)) !== false) {
        if (substr($name, -5) !== '.part') {
            continue;
        }

        $path = $temp . '/' . $name;
        $mtime = @filemtime($path);

        if ($mtime !== false && $mtime < $limit) {
            @unlink($path);
        }
    }

    closedir($handle);
}

/**
 * 同じ名前がすでにあるときの、置き換わりの名前を決める。
 *
 * movie.mp4 がすでにあれば movie-2.mp4、それもあれば movie-3.mp4。
 * 既にあるものを消さずに済ませるための決まりで、
 * 番号が尽きるほど重なっているときは null を返す。
 */
function pv_upload_free_path(string $dir, string $name): ?string
{
    $path = $dir . '/' . $name;

    if (!file_exists($path) && !is_link($path)) {
        return $path;
    }

    $base = (string) pathinfo($name, PATHINFO_FILENAME);
    $ext  = (string) pathinfo($name, PATHINFO_EXTENSION);
    $tail = $ext === '' ? '' : '.' . $ext;

    for ($number = 2; $number <= 999; $number++) {
        $candidate = $dir . '/' . $base . '-' . $number . $tail;

        if (!file_exists($candidate) && !is_link($candidate)) {
            return $candidate;
        }
    }

    return null;
}

/**
 * かけらを1つ受け取る。
 *
 * $id    … ファイル1つに付けられた見分け（英数字）。組み立て中の名前に使う
 * $index … 何番目のかけらか（0 から）
 * $total … かけらの総数
 * $sub   … 落とした先から見た、入れ子のフォルダ（フォルダごと落としたとき）
 *
 * 返り値は ['ok' => bool, 'message' => string, 'done' => bool, 'name' => string]。
 * done が true なら、そのファイルは置き場所へ移し終えている。
 */
function pv_do_upload(array $config, string $dirRelative, string $id, int $index, int $total, string $name, string $sub, array $file): array
{
    if (!preg_match('/^[a-f0-9]{16,64}$/', $id)) {
        return ['ok' => false, 'message' => '受け付けられない要求です。'];
    }

    if ($total < 1 || $index < 0 || $index >= $total) {
        return ['ok' => false, 'message' => '受け付けられない要求です。'];
    }

    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK
        || !is_uploaded_file($file['tmp_name'] ?? '')) {
        return ['ok' => false, 'message' => '送られてきたファイルを受け取れませんでした。'];
    }

    $cleanName = pv_upload_clean_name($config, $name);

    if ($cleanName === null) {
        return [
            'ok'      => false,
            'message' => '「' . $name . '」はこの場所に置けない名前、または対象外の種類です。',
        ];
    }

    $dir = pv_resolve_dir($config['album_dir'], pv_clean_relative($dirRelative));

    if ($dir === null) {
        return ['ok' => false, 'message' => 'フォルダが見つかりませんでした。画面を読み込み直してください。'];
    }

    if (!is_writable($dir)) {
        return ['ok' => false, 'message' => 'このフォルダに書き込む権限がないため、取り込めませんでした。'];
    }

    $temp = pv_upload_temp_dir($config);

    if ($temp === null) {
        return [
            'ok'      => false,
            'message' => '作業用のフォルダを作れませんでした。' . $config['album_url'] . '/ の書き込み権限を確認してください。',
        ];
    }

    $part = $temp . '/' . $id . '.part';

    // 最初のかけらは作り直しから。ついでに、放り出された古いものを片付ける。
    if ($index === 0) {
        pv_upload_sweep($temp);
        @unlink($part);
    } elseif (!is_file($part)) {
        return ['ok' => false, 'message' => '送信が途中で切れました。もう一度お試しください。'];
    }

    // 上限を超えていないか、継ぎ足す前に見ておく
    $maxSize = (int) ($config['max_upload_size'] ?? 0);
    $current = $index === 0 ? 0 : (int) @filesize($part);

    if ($maxSize > 0 && $current + (int) $file['size'] > $maxSize) {
        @unlink($part);

        return [
            'ok'      => false,
            'message' => '「' . $cleanName . '」は大きすぎます（' . pv_human_size($maxSize) . 'まで）。',
        ];
    }

    $chunk = @file_get_contents($file['tmp_name']);

    if ($chunk === false) {
        @unlink($part);

        return ['ok' => false, 'message' => '送られてきたファイルを読み取れませんでした。'];
    }

    if (@file_put_contents($part, $chunk, FILE_APPEND | LOCK_EX) === false) {
        @unlink($part);

        return ['ok' => false, 'message' => '作業用のファイルに書き込めませんでした。'];
    }

    // まだ途中。次のかけらを待つ。
    if ($index < $total - 1) {
        return ['ok' => true, 'message' => '', 'done' => false, 'name' => $cleanName];
    }

    // ---- 最後のかけら。組み立て終わったので置き場所へ移す ----

    $destinationDir = pv_upload_prepare_dir($config, $dir, $sub);

    if ($destinationDir === null) {
        @unlink($part);

        return ['ok' => false, 'message' => '「' . $sub . '」のフォルダを作れませんでした。'];
    }

    $destination = pv_upload_free_path($destinationDir, $cleanName);

    if ($destination === null) {
        @unlink($part);

        return ['ok' => false, 'message' => '「' . $cleanName . '」と同じ名前が多すぎて、置き場所を決められませんでした。'];
    }

    if (!@rename($part, $destination)) {
        @unlink($part);

        return ['ok' => false, 'message' => '「' . $cleanName . '」を置けませんでした。書き込み権限を確認してください。'];
    }

    @chmod($destination, 0644);

    $savedName = basename($destination);

    return [
        'ok'      => true,
        'message' => '',
        'done'    => true,
        'name'    => $savedName,
        // 同じ名前があって付け替えたときは、画面で知らせたいので合図を返す
        'renamed' => $savedName !== $cleanName,
    ];
}
