<?php
/**
 * Media Library 更新系の処理
 *
 * 写真・動画フォルダの中身を書き換える操作をここにまとめている。
 * 対象のフォルダ（写真か動画か）は、呼び出し元が $config に反映してから渡す。
 * どの関数も ['ok' => bool, 'message' => string] を返し、
 * 例外を投げる代わりに、利用者に見せられる日本語のメッセージを返す。
 *
 * パスの検証は lib/functions.php の pv_resolve_path() 系に任せている。
 * ルートの外を指す相対パスはそこで弾かれるため、この中では
 * 「操作してよい対象か」「新しい名前が妥当か」だけを見ればよい。
 */

/**
 * 新しい名前として使えるかを確かめる。
 * 問題がなければ null、問題があれば理由のメッセージを返す。
 */
function pv_validate_name(string $name, int $maxLength): ?string
{
    if ($name === '') {
        return '名前を入力してください。';
    }

    if (!mb_check_encoding($name, 'UTF-8')) {
        return '名前に使えない文字が含まれています。';
    }

    // パス区切りを含む名前を許すと、別のフォルダへ書き込めてしまう
    if (strpos($name, '/') !== false || strpos($name, '\\') !== false) {
        return '名前に「/」や「\」は使えません。';
    }

    // 制御文字（改行やタブを含む）は見た目で判別できないため禁止する
    if (preg_match('/[\x00-\x1F\x7F]/u', $name) === 1) {
        return '名前に使えない文字が含まれています。';
    }

    if ($name[0] === '.') {
        return 'ドットで始まる名前は使えません。';
    }

    if (mb_strlen($name, 'UTF-8') > $maxLength) {
        return '名前が長すぎます（' . $maxLength . '文字まで）。';
    }

    return null;
}

/**
 * ファイルの名前を変更するとき、拡張子は元のまま保つ。
 *
 * 拡張子まで自由に変えられると、写真や動画として表示できなくなるだけでなく、
 * .php などに付け替えられる余地が生まれるため。
 * 利用者が拡張子込みで入力した場合（例：sea.jpg）は重ねずに扱う。
 */
function pv_keep_extension(string $currentName, string $newName): string
{
    $extension = pathinfo($currentName, PATHINFO_EXTENSION);
    if ($extension === '') {
        return $newName;
    }

    $given = pathinfo($newName, PATHINFO_EXTENSION);
    if ($given !== '' && strcasecmp($given, $extension) === 0) {
        $newName = (string) pathinfo($newName, PATHINFO_FILENAME);
    }

    return $newName . '.' . $extension;
}

/**
 * 相対パスの前後のスラッシュだけを整える。
 *
 * 「..」や隠し名を取り除いたりはしない。それらは pv_resolve_path() で
 * 拒否する決まりにしてあり、ここで黙って別のパスに読み替えてしまうと、
 * 指定を間違えたときに思わぬ場所を操作してしまうため。
 */
function pv_clean_relative(string $relative): string
{
    return trim(str_replace('\\', '/', $relative), '/');
}

/**
 * 2つのパスが同じ実体を指しているかを調べる。
 *
 * 大文字小文字を区別しないファイルシステム（macOS など）では、
 * 名前の大小だけを変えようとしたときに「既に同じ名前がある」と
 * 誤って判定されてしまう。それを避けるために使う。
 */
function pv_same_file(string $a, string $b): bool
{
    $statA = @stat($a);
    $statB = @stat($b);

    if ($statA === false || $statB === false) {
        return false;
    }

    return $statA['dev'] === $statB['dev'] && $statA['ino'] === $statB['ino'];
}

/**
 * 操作対象を解決する。ファイルとフォルダのどちらでも受け取れる。
 * 返り値は ['path' => 絶対パス, 'type' => 'file'|'dir', 'name' => 名前]、
 * 対象が見つからなければ null。
 */
function pv_resolve_target(array $config, string $relative): ?array
{
    $relative = pv_clean_relative($relative);
    if ($relative === '') {
        // ルートのフォルダそのものは操作させない
        return null;
    }

    $path = pv_resolve_path($config['album_dir'], $relative);
    if ($path === null) {
        return null;
    }

    if (is_dir($path)) {
        return ['path' => $path, 'type' => 'dir', 'name' => basename($path), 'relative' => $relative];
    }

    if (pv_resolve_file($config['album_dir'], $relative, $config['extensions']) === null) {
        return null;
    }

    return ['path' => $path, 'type' => 'file', 'name' => basename($path), 'relative' => $relative];
}

/**
 * 対象の呼び名。メッセージの組み立てに使う。
 * ファイルの呼び名は、いま開いているルートに合わせる（写真／動画）。
 */
function pv_label(array $config, string $type): string
{
    return $type === 'dir' ? 'フォルダ' : (string) ($config['root_label'] ?? 'ファイル');
}

/**
 * 名前を変更する。
 */
function pv_do_rename(array $config, string $relative, string $newName): array
{
    $target = pv_resolve_target($config, $relative);
    if ($target === null) {
        return ['ok' => false, 'message' => '対象が見つかりませんでした。画面を読み込み直してください。'];
    }

    $label  = pv_label($config, $target['type']);
    $parent = dirname($target['path']);

    if (!is_writable($parent)) {
        return ['ok' => false, 'message' => 'フォルダに書き込む権限がないため、名前を変更できませんでした。'];
    }

    $newName = trim($newName);
    if ($newName === '') {
        return ['ok' => false, 'message' => '名前を入力してください。'];
    }

    if ($target['type'] === 'file') {
        $newName = pv_keep_extension($target['name'], $newName);
    }

    $error = pv_validate_name($newName, (int) $config['max_name_length']);
    if ($error !== null) {
        return ['ok' => false, 'message' => $error];
    }

    if ($newName === $target['name']) {
        return ['ok' => true, 'message' => '名前は変わっていません。'];
    }

    $destination = $parent . '/' . $newName;

    // 名前の大小だけを変える場合は、自分自身とぶつかったように見えるので通す
    if ((file_exists($destination) || is_link($destination))
        && !pv_same_file($destination, $target['path'])) {
        return ['ok' => false, 'message' => '「' . $newName . '」は既にあります。別の名前にしてください。'];
    }

    if (!@rename($target['path'], $destination)) {
        return ['ok' => false, 'message' => $label . 'の名前を変更できませんでした。'];
    }

    return ['ok' => true, 'message' => $label . 'の名前を「' . $newName . '」に変更しました。'];
}

/**
 * フォルダ情報（info.yml）を書き込む。
 *
 * 対象はいま開いているフォルダ1つだけ。書き換えるのは title / thumbnail / items で、
 * 手で書き足したコメントやほかのキーは残らない。
 * すべて空のときは、情報をやめる操作とみなして info.yml をゴミ箱へ移す。
 */
function pv_do_info(array $config, string $dirRelative, string $title, string $thumbnail, array $items, string $randomFrom = 'self'): array
{
    $relative = pv_clean_relative($dirRelative);
    $dir      = pv_resolve_dir($config['album_dir'], $relative);

    if ($dir === null) {
        return ['ok' => false, 'message' => 'フォルダが見つかりませんでした。画面を読み込み直してください。'];
    }

    if (!is_writable($dir)) {
        return ['ok' => false, 'message' => 'このフォルダに書き込む権限がないため、フォルダ情報を保存できませんでした。'];
    }

    $path = $dir . '/' . PV_INFO_FILE;

    // 同じ名前でフォルダやリンクが置かれている場合は触らない
    if (file_exists($path) && (!is_file($path) || is_link($path))) {
        return ['ok' => false, 'message' => PV_INFO_FILE . ' を書き換えられませんでした。'];
    }

    $title = pv_info_field($title, 200);

    $thumbnail = pv_info_input_thumb($config, $relative, $thumbnail, $randomFrom);
    if ($thumbnail === null) {
        return ['ok' => false, 'message' => '選ばれたサムネイル、またはランダムに選ぶフォルダが見つかりませんでした。'];
    }

    $rows = pv_info_input_items($items);
    if ($rows === null) {
        return ['ok' => false, 'message' => 'リンクに使えないURLが含まれています。http:// か https://、または同じサイトの中の道順を指定してください。'];
    }

    if ($title === '' && $thumbnail === '' && $rows === []) {
        return pv_info_remove($config, $relative, $path);
    }

    $yaml = pv_yaml_dump($title, $thumbnail, $rows);

    // 書きかけのファイルが残らないよう、別の名前で書いてから置き換える
    $temp = $dir . '/.' . PV_INFO_FILE . '.' . bin2hex(random_bytes(4)) . '.tmp';

    if (@file_put_contents($temp, $yaml, LOCK_EX) === false) {
        @unlink($temp);

        return ['ok' => false, 'message' => 'フォルダ情報を保存できませんでした。書き込み権限を確認してください。'];
    }

    if (!@rename($temp, $path)) {
        @unlink($temp);

        return ['ok' => false, 'message' => 'フォルダ情報を保存できませんでした。書き込み権限を確認してください。'];
    }

    @chmod($path, 0644);

    return ['ok' => true, 'message' => 'フォルダ情報を保存しました。'];
}

/**
 * 情報をやめる操作。info.yml をゴミ箱へ移す。
 */
function pv_info_remove(array $config, string $relative, string $path): array
{
    if (!file_exists($path)) {
        return ['ok' => true, 'message' => 'フォルダ情報はありません。'];
    }

    $bucket = pv_trash_bucket($config);

    if ($bucket === null) {
        return [
            'ok'      => false,
            'message' => 'ゴミ箱フォルダを作れませんでした。' . $config['album_url'] . '/ の書き込み権限を確認してください。',
        ];
    }

    $destination = $bucket;

    // 元のフォルダ構成をゴミ箱の中に作り直す
    if ($relative !== '') {
        $destination .= '/' . $relative;

        if (!is_dir($destination) && !@mkdir($destination, 0755, true)) {
            @rmdir($bucket);

            return ['ok' => false, 'message' => 'ゴミ箱へ移動できませんでした。書き込み権限を確認してください。'];
        }
    }

    if (!@rename($path, $destination . '/' . PV_INFO_FILE)) {
        return ['ok' => false, 'message' => 'ゴミ箱へ移動できませんでした。書き込み権限を確認してください。'];
    }

    return ['ok' => true, 'message' => 'フォルダ情報をゴミ箱へ移動しました。'];
}

/**
 * 入力された1行ぶんの文字を整える。改行や制御文字は入れさせない。
 */
function pv_info_field(string $value, int $maxLength): string
{
    $value = preg_replace('/[\x00-\x1F\x7F]/u', ' ', $value);
    $value = trim((string) $value);

    if (mb_strlen($value, 'UTF-8') > $maxLength) {
        $value = mb_substr($value, 0, $maxLength, 'UTF-8');
    }

    return $value;
}

/**
 * 選ばれたサムネイルを確かめる。
 * 使えるのは「空」「random」「そのフォルダにある画像のファイル名」のみ。
 * 見つからないものが指定されたときは null を返す。
 *
 * $randomFrom は「ランダム」を選んだときの範囲。編集画面から送られてくる。
 *   'self'        … info.yml のあるフォルダ以下（従来どおり。random とだけ書く）
 *   'root:<パス>' … そのフォルダ以下（空文字ならホーム全体。random:<パス> と書く）
 */
function pv_info_input_thumb(array $config, string $relative, string $thumbnail, string $randomFrom = 'self'): ?string
{
    $thumbnail = pv_info_field($thumbnail, 255);

    if ($thumbnail === '') {
        return '';
    }

    if (strtolower($thumbnail) === PV_INFO_RANDOM) {
        return pv_info_input_random($config, $randomFrom);
    }

    // フォルダをまたぐ指定は編集画面からは選べないので、ここでは受け付けない
    if (strpos($thumbnail, '/') !== false || strpos($thumbnail, '\\') !== false) {
        return null;
    }

    $target = $relative === '' ? $thumbnail : $relative . '/' . $thumbnail;

    if (pv_resolve_file($config['album_dir'], $target, pv_image_extensions($config)) === null) {
        return null;
    }

    return $thumbnail;
}

/**
 * 「ランダム」を選んだときの範囲を確かめ、info.yml に書く形にする。
 * 指されたフォルダが見つからないときは null を返す。
 */
function pv_info_input_random(array $config, string $randomFrom): ?string
{
    $prefix = 'root:';

    if (strncmp($randomFrom, $prefix, strlen($prefix)) !== 0) {
        // 'self'（と、それ以外の見慣れない値）は、従来どおり自分以下として扱う
        return PV_INFO_RANDOM;
    }

    $from = pv_clean_relative(substr($randomFrom, strlen($prefix)));

    if (pv_resolve_dir($config['album_dir'], $from) === null) {
        return null;
    }

    return PV_INFO_RANDOM . ':' . $from;
}

/**
 * 入力された項目を整える。
 * 見出しも中身も空の行は落とし、使えないURLが混じっていたら null を返す。
 */
function pv_info_input_items($items): ?array
{
    if (!is_array($items)) {
        return [];
    }

    $rows = [];

    foreach ($items as $one) {
        if (!is_array($one)) {
            continue;
        }

        $item  = pv_info_field((string) ($one['item'] ?? ''), 100);
        $value = pv_info_field((string) ($one['value'] ?? ''), 500);
        $url   = pv_info_field((string) ($one['url'] ?? ''), 500);

        if ($item === '' && $value === '') {
            continue;
        }

        if ($url !== '' && pv_info_url($url) === null) {
            return null;
        }

        $rows[] = ['item' => $item, 'value' => $value, 'url' => $url];

        // 増やしすぎて画面が壊れないよう、行数にも上限を設ける
        if (count($rows) >= 30) {
            break;
        }
    }

    return $rows;
}

/**
 * ゴミ箱の中に、今回の操作専用の退避先を作って返す。
 *
 * 「.trash/日時/元のフォルダ/…」という形で、消したときの場所ごと残す。
 * どこにあったファイルなのかが、あとから見てもわかるようにするため。
 * 作れなかった場合は null を返す。
 */
function pv_trash_bucket(array $config): ?string
{
    $root = realpath($config['album_dir']);
    if ($root === false) {
        return null;
    }

    $trash = $root . '/' . trim(str_replace('\\', '/', (string) $config['trash_dir']), '/');

    if (!is_dir($trash) && !@mkdir($trash, 0755, true)) {
        return null;
    }

    // ブラウザからゴミ箱の中身を直接開けないようにしておく
    $guard = $trash . '/.htaccess';
    if (!file_exists($guard)) {
        @file_put_contents($guard, "# ゴミ箱の中身はブラウザから参照させない\n"
            . "<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n"
            . "<IfModule !mod_authz_core.c>\n    Order allow,deny\n    Deny from all\n</IfModule>\n");
    }

    // 同じ秒に2回実行された場合に備えて、空いている名前を探す
    $stamp = date('Ymd-His');
    $bucket = $trash . '/' . $stamp;
    $suffix = 1;

    while (file_exists($bucket)) {
        $suffix++;
        $bucket = $trash . '/' . $stamp . '-' . $suffix;
    }

    return @mkdir($bucket, 0755, true) ? $bucket : null;
}

/**
 * ファイル・フォルダをゴミ箱へ移動する。複数まとめて渡せる。
 *
 * ファイルを消してしまうのではなく移動にとどめているので、
 * 間違えて実行しても、サーバー上で元の場所へ戻せる。
 */
function pv_do_delete(array $config, array $relatives): array
{
    $targets = [];
    foreach ($relatives as $relative) {
        $target = pv_resolve_target($config, (string) $relative);
        if ($target === null) {
            return ['ok' => false, 'message' => '対象が見つかりませんでした。画面を読み込み直してください。'];
        }
        $targets[] = $target;
    }

    if ($targets === []) {
        return ['ok' => false, 'message' => '対象が選ばれていません。'];
    }

    $bucket = pv_trash_bucket($config);
    if ($bucket === null) {
        return [
            'ok'      => false,
            'message' => 'ゴミ箱フォルダを作れませんでした。' . $config['album_url'] . '/ の書き込み権限を確認してください。',
        ];
    }

    $moved  = [];
    $failed = [];

    foreach ($targets as $target) {
        $destination = $bucket;

        // 元のフォルダ構成をゴミ箱の中に作り直す
        $parent = dirname($target['relative']);
        if ($parent !== '.' && $parent !== '') {
            $destination .= '/' . $parent;
            if (!is_dir($destination) && !@mkdir($destination, 0755, true)) {
                $failed[] = $target['name'];
                continue;
            }
        }

        if (!@rename($target['path'], $destination . '/' . $target['name'])) {
            $failed[] = $target['name'];
            continue;
        }

        $moved[] = $target;
    }

    if ($moved === []) {
        @rmdir($bucket);

        return ['ok' => false, 'message' => 'ゴミ箱へ移動できませんでした。書き込み権限を確認してください。'];
    }

    if ($failed !== []) {
        return [
            'ok'      => false,
            'message' => count($moved) . '件をゴミ箱へ移動しました。'
                . count($failed) . '件は移動できませんでした： ' . implode('、', $failed),
        ];
    }

    if (count($moved) === 1) {
        return [
            'ok'      => true,
            'message' => pv_label($config, $moved[0]['type']) . '「' . $moved[0]['name'] . '」をゴミ箱へ移動しました。',
        ];
    }

    return ['ok' => true, 'message' => count($moved) . '件をゴミ箱へ移動しました。'];
}

/**
 * ファイル・フォルダを別のフォルダへ移動する。複数まとめて渡せる。
 */
function pv_do_move(array $config, array $relatives, string $destinationRelative): array
{
    $destinationRelative = pv_clean_relative($destinationRelative);
    $destination = pv_resolve_dir($config['album_dir'], $destinationRelative);

    if ($destination === null) {
        return ['ok' => false, 'message' => '移動先のフォルダが見つかりませんでした。'];
    }

    if (!is_writable($destination)) {
        return ['ok' => false, 'message' => '移動先のフォルダに書き込む権限がありません。'];
    }

    $targets = [];
    foreach ($relatives as $relative) {
        $target = pv_resolve_target($config, (string) $relative);
        if ($target === null) {
            return ['ok' => false, 'message' => '対象が見つかりませんでした。画面を読み込み直してください。'];
        }
        $targets[] = $target;
    }

    if ($targets === []) {
        return ['ok' => false, 'message' => '対象が選ばれていません。'];
    }

    $label  = $destinationRelative === '' ? 'ホーム' : $destinationRelative;
    $moved  = 0;
    $failed = [];

    foreach ($targets as $target) {
        if (dirname($target['path']) === $destination) {
            $failed[] = $target['name'] . '（すでにそのフォルダにあります）';
            continue;
        }

        // 自分自身や、その中のフォルダへは移動できない（行き先ごと消えてしまうため）
        if ($target['type'] === 'dir'
            && ($destination === $target['path']
                || strpos($destination, $target['path'] . DIRECTORY_SEPARATOR) === 0)) {
            $failed[] = $target['name'] . '（自分自身の中へは移動できません）';
            continue;
        }

        $path = $destination . '/' . $target['name'];
        if (file_exists($path) || is_link($path)) {
            $failed[] = $target['name'] . '（移動先に同じ名前があります）';
            continue;
        }

        if (!@rename($target['path'], $path)) {
            $failed[] = $target['name'];
            continue;
        }

        $moved++;
    }

    if ($moved === 0) {
        return ['ok' => false, 'message' => '移動できませんでした： ' . implode('、', $failed)];
    }

    if ($failed !== []) {
        return [
            'ok'      => false,
            'message' => $moved . '件を「' . $label . '」へ移動しました。'
                . count($failed) . '件は移動できませんでした： ' . implode('、', $failed),
        ];
    }

    return ['ok' => true, 'message' => $moved . '件を「' . $label . '」へ移動しました。'];
}

/**
 * 表示中のフォルダの下に、新しいフォルダを作る。
 */
function pv_do_mkdir(array $config, string $parentRelative, string $name): array
{
    $parent = pv_resolve_dir($config['album_dir'], pv_clean_relative($parentRelative));
    if ($parent === null) {
        return ['ok' => false, 'message' => 'フォルダが見つかりませんでした。画面を読み込み直してください。'];
    }

    if (!is_writable($parent)) {
        return ['ok' => false, 'message' => 'このフォルダに書き込む権限がないため、フォルダを作れませんでした。'];
    }

    $name = trim($name);
    $error = pv_validate_name($name, (int) $config['max_name_length']);
    if ($error !== null) {
        return ['ok' => false, 'message' => $error];
    }

    $path = $parent . '/' . $name;
    if (file_exists($path) || is_link($path)) {
        return ['ok' => false, 'message' => '「' . $name . '」は既にあります。別の名前にしてください。'];
    }

    if (!@mkdir($path, 0755)) {
        return ['ok' => false, 'message' => 'フォルダを作れませんでした。'];
    }

    return ['ok' => true, 'message' => 'フォルダ「' . $name . '」を作りました。'];
}

/**
 * 初期設定の「フォルダ情報を作成する」。
 *
 * 写真・動画それぞれのルート配下にあるフォルダを一通り見て、
 * info.yml の無いところにだけ作る。すでにあるものには手を触れないので、
 * 何度押しても、手で書いた内容が消えることはない。
 *
 * ルート自身（写真・動画のトップ）は対象にしない。フォルダ名がそのまま
 * 見出しになってしまい、意味のある名前にならないため。
 */
function pv_do_init_info(array $config): array
{
    $created = 0;
    $kept    = 0;
    $failed  = 0;

    foreach (array_keys($config['roots']) as $rootKey) {
        $rootConfig = pv_apply_root($config, $rootKey);

        foreach (pv_folder_tree($rootConfig['album_dir']) as $folder) {
            if ($folder['path'] === '') {
                continue;
            }

            $dir = pv_resolve_dir($rootConfig['album_dir'], $folder['path']);

            if ($dir === null) {
                continue;
            }

            if (file_exists($dir . '/' . PV_INFO_FILE)) {
                $kept++;
                continue;
            }

            if (pv_create_default_info($rootConfig, $folder['path'])) {
                $created++;
            } else {
                $failed++;
            }
        }
    }

    if ($created === 0 && $kept === 0 && $failed === 0) {
        return ['ok' => true, 'message' => '対象になるフォルダがありませんでした。'];
    }

    $parts = [];

    if ($created > 0) {
        $parts[] = 'フォルダ情報を ' . $created . ' 件作成しました。';
    } else {
        $parts[] = '新しく作るフォルダはありませんでした。';
    }

    if ($kept > 0) {
        $parts[] = 'すでにあった ' . $kept . ' 件はそのままです。';
    }

    if ($failed > 0) {
        $parts[] = $failed . ' 件は作成できませんでした。書き込み権限を確認してください。';
    }

    return ['ok' => $failed === 0, 'message' => implode(' ', $parts)];
}
