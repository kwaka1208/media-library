<?php
/**
 * .htpasswd の内容を生成するコマンドラインスクリプト。
 *
 *   php make-htpasswd.php ユーザー名 パスワード
 *   php make-htpasswd.php ユーザー名 パスワード > .htpasswd
 *
 * htpasswd コマンドが使えない環境向け。ブラウザからは実行できない。
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("このスクリプトはコマンドラインからのみ実行できます。\n");
}

if ($argc < 3) {
    fwrite(STDERR, "使い方: php make-htpasswd.php ユーザー名 パスワード\n");
    exit(1);
}

$user = $argv[1];
$password = $argv[2];

if (strpbrk($user, ":\r\n") !== false) {
    fwrite(STDERR, "ユーザー名にコロンや改行は使えません。\n");
    exit(1);
}

echo $user . ':' . password_hash($password, PASSWORD_BCRYPT) . PHP_EOL;
