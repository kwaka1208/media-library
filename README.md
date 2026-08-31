# Media Library

サーバー上の写真・動画を、ブラウザから一覧・再生するためのPHPツールです。
写真は `photos/`、動画は `movies/` に置き、画面上部のタブで切り替えます。

閲覧できる人を限定したいときは、Basic認証をかけられます。
既定では無効なので、必要なときだけ設定してください（→ [Basic認証をかける](#4-basic認証をかける任意)）。

このREADMEでは、ツールの概要と設置手順を説明します。
画面での操作は [使い方](docs/usage.md)、内部の作りは [内部のしくみ](docs/tech.md) にまとめています。

![グリッド表示](docs/screenshots/grid.jpg)

## できること

- 写真と動画のタブ切り替え（`photos/` と `movies/`）
- サムネイルのグリッド表示（写真はクリックで拡大、動画はクリックで再生）
- 1行ずつ並べるリスト表示（右上のボタンで切り替え）
  - 項目をクリックすると、グリッドと同じように拡大表示・再生
- プロパティの表示（右クリック、スマホは長押しのメニューから）
  - サムネイル・ファイル名・種類・サイズ・更新日時・場所を画面全体に出す
  - グリッド・リストのどちらでも、また閲覧のみの設定でも使える
- 動画の再生（ブラウザ内蔵のプレーヤー。再生・一時停止・シーク・全画面・音量）
- サブフォルダの階層移動（パンくずリスト付き）
- 名前順／更新日時順／サイズ順の並び替え、昇順・降順の切り替え
- ファイル名での絞り込み（入力して `Enter` で確定）
- キーボード操作
  - `←` `→` … 拡大表示中に前後の写真へ
  - `Esc` … 拡大表示・動画ビューワーを閉じる
  - `Backspace` … 上のフォルダへ移動（拡大表示中は閉じる）
- スマホでのスワイプ操作（写真のみ）
- 件数が多いフォルダのページ送り
  - 枚数・ページ番号とまとめて一覧の上に置き、ヘッダーの下に貼り付く
  - どこまでスクロールしても、同じ場所でページを切り替えられる
- 動画の見え方を画面から切り替える設定（右上の「⚙ 設定」）
  - 再生し始めるときに音を消すかどうか
  - 再生するときの大きさ（動画のオリジナルサイズ／画面に合わせて最大化）
- フォルダに `info.json` を置くと、そのフォルダ情報を一覧の横に表示（→ [使い方](docs/usage.md#フォルダ情報を付けるinfojson)）
  - フォルダ情報があるときは、枚数とページ送りもその下にそろえて出る
  - タイトル・サムネイル・「見出し : 中身」の一覧を表示し、右クリックから画面上で編集できる
  - サムネイルに `random` と書くと、そのフォルダ以下の画像から毎回1枚を選ぶ
- 写真・動画・フォルダの整理（右クリック、スマホは長押しでメニュー）
  - プロパティの表示
  - 名前の変更
  - 別のフォルダへの移動（複数まとめて可、ドラッグ＆ドロップにも対応）
  - まとめて選ぶ（`⌘`／`Ctrl` ＋クリック、`Shift` ＋クリック、枠で囲むドラッグ、`Shift` ＋矢印キー）
  - ゴミ箱への移動（すぐには消さず `photos/.trash/`・`movies/.trash/` へ退避）
  - 新しいフォルダの作成
  - フォルダ情報（`info.json`）の作成・編集
  - `config.php` の `read_only` で、整理機能をまとめてオフにできる
- ドラッグ＆ドロップでの取り込み（→ [使い方](docs/usage.md#ドラッグドロップで取り込む)）
  - パソコンから写真・動画を落とすと、開いているフォルダに入る
  - フォルダごと落とすと、中の構成をそのまま作り直す
  - ファイルを小さく切って送るので、共有サーバーの上限に左右されず大きな動画も上げられる

## 動作環境

- PHP 7.4 以上（PHP 8.3 で動作確認済み）
- Apache（`.htaccess` が有効であること）
- 整理機能を使う場合は、`photos/` `movies/` に書き込み権限があること
- Basic認証を使う場合は、Apache の `mod_auth_basic` が有効であること
  （多くのレンタルサーバーでは既定で有効です）
- 動画の再生には、サーバーが範囲リクエスト（HTTP Range）に応じること
  （Apache の静的ファイル配信では既定で有効です。シークができない場合はここを確認してください）
- 整理機能はCSRF対策にセッションを使うため、ブラウザ側でCookieが有効であること
  （閲覧するだけならCookieは不要です）

## インストール

Apache が動くサーバーにファイルを置けば、そのまま動きます。データベースも外部ライブラリも使いません。

以下は、`https://example.com/` に `/home/user/www/media-library` を割り当てた場合の例です。
ドメインとパスは、自分の環境に読み替えてください。

| | 例 |
| --- | --- |
| 公開URL | `https://example.com/` |
| 設置パス | `/home/user/www/media-library/` |
| 写真フォルダ | `/home/user/www/media-library/photos/` |
| 動画フォルダ | `/home/user/www/media-library/movies/` |
| SSH接続先 | `user@example.com` |

### 1. ファイルを配置する

リポジトリを取得し、中身をサーバーの設置先（例: `/home/user/www/media-library/`）にアップロードします。

```
git clone https://github.com/<ユーザー名>/media-library.git
```

ZIPでダウンロードして展開しても構いません。

次に、同梱の `.htaccess.example` を `.htaccess` という名前でコピーします。
Apache の設定は環境ごとに書き換えることがあるため、見本ファイルとして配布しています。

```
cp .htaccess.example .htaccess
```

そのままでも動きます。書き換えが必要なのは、Basic認証を使う場合（→手順4）と、
HTTPSリダイレクトを有効にする場合だけです。

`.htaccess` は先頭がドットで始まる隠しファイルなので、FTPクライアントの設定で「隠しファイルを表示する」を
有効にしておかないと転送されないことがあります。

### 2. 写真と動画を置く

写真は `photos/`、動画は `movies/` にアップロードします。
サブフォルダを作れば、そのまま階層として表示されます。

```
photos/
├── 2026-01_旅行/
│   ├── kyoto-01.jpg
│   └── 2日目/
│       └── day2-a.jpg
└── landscape/
    └── mountain.jpg

movies/
└── 2026-01_旅行/
    ├── kyoto-01.mp4
    └── kyoto-02.mp4
```

動画は、ブラウザがそのまま再生できる形式にしてください（`mp4` / `m4v` / `mov` / `webm` / `ogv`）。
`mp4`（H.264 + AAC）がもっとも確実です。デジタルカメラやスマートフォンの `mov` は、
中身によっては再生できないことがあります。その場合は `mp4` に変換してからアップロードしてください。

### 3. ブラウザで開く

`https://example.com/`（設置したURL）にアクセスすると、一覧が表示されます。
ここまでで基本的な設置は完了です。

> **HTTPSでアクセスできるようにしておくことをおすすめします**
> サーバーの管理画面などからSSL証明書（Let's Encrypt など）を有効にし、発行が終わったら
> `.htaccess` の末尾にあるHTTPSリダイレクトのコメントを外してください。
> 次のBasic認証を使う場合は、HTTPSが**必須**です（理由は下に書いています）。

### 4. Basic認証をかける（任意）

このツール自体はログイン機能を持ちません。URLを知っている人なら誰でも見られる状態です。
閲覧できる人を限定したいときは、Apache の Basic認証をかけてください。

**見本ファイル（`.htaccess.example`）では、Basic認証は無効にしてあります。**
使う場合だけ、以下の 4-1 と 4-2 の両方を行ってください。

共有のIDとパスワードではなく、Googleアカウント単位で閲覧者を絞りたい場合は、
[Googleアカウントで閲覧できる人を限定する](docs/google-auth.md) を参照してください。

#### 4-1. `.htpasswd` を作る

以下のいずれかの方法で作成し、`/home/user/www/media-library/.htpasswd` として設置します。

**A. サーバーにSSHでログインできる場合**

```
htpasswd -c /home/user/www/media-library/.htpasswd ユーザー名
```

パスワードの入力を2回求められます。ユーザーを追加するときは `-c`（新規作成）を外して実行してください。

**B. 手元のPCで作る場合**

```
htpasswd -nbB ユーザー名 パスワード
```

出力された1行を `.htpasswd` という名前のテキストファイルに保存し、アップロードします。

**C. 同梱のスクリプトを使う場合**

```
php make-htpasswd.php ユーザー名 パスワード > .htpasswd
```

`htpasswd` コマンドが手元にない場合に使えます。このスクリプトはコマンドラインからのみ実行でき、
ブラウザからアクセスしても動きません。

> **うまく認証できないとき**
> 上記はいずれも bcrypt 形式のハッシュを生成します。Apache 2.4 以降なら問題ありませんが、
> 古いサーバーで認証が通らない場合は、サーバーの管理画面（cPanelなど）が持つ
> 「ディレクトリのパスワード保護」機能で `.htpasswd` を生成してください。

#### 4-2. `.htaccess` の認証設定を有効にする

手順1でコピーした `.htaccess` の先頭にある次の4行は、見本の時点ではコメント（`#`）になっています。
**行頭の `#` を外し**、`AuthUserFile` を**サーバー上の絶対パス**に書き換えてください。

```
#AuthType Basic                                  ← コピーした直後（無効）
#AuthName "Media Library"
#AuthUserFile /path/to/media-library/.htpasswd
#Require valid-user
```

```
AuthType Basic                                        ← 有効にした状態
AuthName "Media Library"
AuthUserFile /home/user/www/media-library/.htpasswd    ← 実際のパスに書き換える
Require valid-user
```

パスを書き換えないまま有効にすると認証が動かず、サーバーエラー（500）になります。

絶対パスがわからないときは、以下の内容の `path.php` を一時的に置いてブラウザでアクセスすると確認できます
（**確認したら必ず削除してください**）。

```php
<?php echo __DIR__;
```

設定できたら、もう一度 `https://example.com/` を開きます。ユーザー名とパスワードを求められれば成功です。

> **必ずHTTPSでアクセスしてください**
> Basic認証はユーザー名とパスワードをほぼそのままの形で送信するため、`http://` で使うと
> 通信経路上で読み取られる可能性があります。SSL証明書を有効にし、`.htaccess` の
> 末尾にあるHTTPSリダイレクトのコメントを外してから使ってください。

### 5. サーバーへの反映（Makefile）（任意）

2回目以降の更新は、`make` コマンドで差分だけを転送できます。使わなくても運用できます。

接続先はサーバーごとに違うため、`Makefile` はリポジトリに含めていません。
`.htaccess` と同じく、同梱の `Makefile.example` をコピーして作成してください。

```
cp Makefile.example Makefile
```

コピーした `Makefile` の先頭にある2行を、自分のサーバーに合わせて書き換えます。

```
SSH_HOST   := user@example.com
REMOTE_DIR := /home/user/www/media-library
```

`Makefile` は `.htaccess` とともに `.gitignore` に入れてあるので、書き換えた接続先が
リポジトリに入ることはありません。

#### コマンド一覧

```
make help             使えるコマンドの一覧を表示
make lint             PHPの構文チェック
make serve            ローカルで動作確認（http://127.0.0.1:8765/）

make diff             サーバーとの差分を確認（転送はしない）
make deploy           サーバーへ反映
make deploy-media     photos/ movies/ の中身もサーバーへ転送する

make remote-init      サーバー側に photos/ movies/ を作る（初回のみ）
make htpasswd         .htpasswd を作る（対話入力）
make deploy-htpasswd  .htpasswd をサーバーへ転送
make ssh              サーバーにSSHでログイン
```

いきなり `make deploy` せず、まず `make diff` で何が転送されるか確かめることをおすすめします。

#### 転送の安全策

事故を防ぐため、以下のようにしています。

- **`rsync --delete` は使いません。** サーバー上にしかないファイルが消えることはありません。
- **`photos/` `movies/` の中身は `make deploy` では転送しません。**
  サーバー上の写真・動画には触れません。
  送りたいときだけ `make deploy-media` を実行してください
  （これも `--delete` なしなので、サーバー側のファイルは消えません）。
  ただし各フォルダの `.htaccess` はツールの一部なので `make deploy` で転送されます。
- **ゴミ箱（`photos/.trash/`・`movies/.trash/`）は転送しません。**
  手元とサーバーで中身が違って当然のためです。
- **`.htpasswd` は `make deploy` では転送しません。** サーバー上の認証情報を
  うっかり上書きしないためです。更新したいときは `make deploy-htpasswd` を使ってください。
- **`.htaccess` は `make deploy` で転送します。** 手元で書き換えた内容がそのまま反映されます。
  手元に `.htaccess` がないときは、転送せずにその場で止まります。
  見本ファイル（`*.example`）はサーバーへ送りません。
- **`make deploy` は転送前に `make lint` を実行します。** 構文エラーのあるPHPは送られません。

#### 初回のセットアップ手順

```
cp .htaccess.example .htaccess # Apache の設定（認証を使うならここで有効化）
cp Makefile.example Makefile   # 接続先を書き換えてから
make remote-init      # サーバー側に photos/ movies/ を作る
make deploy           # ツール本体を転送
make htpasswd         # 認証情報を作る（Basic認証を使う場合のみ）
make deploy-htpasswd  # 認証情報を転送（同上）
make deploy-media     # 写真・動画を転送（サーバー側に直接置く場合は不要）
```

## 設定

`config.php` を編集して調整できます。書き換えたら、ファイルを保存するだけで反映されます。

| 項目 | 説明 | 初期値 |
| --- | --- | --- |
| `roots` | 表示するフォルダの一覧（タブの中身） | `photos` と `movies` |
| `default_root` | 最初に開くフォルダ（`roots` のキー） | `photos` |
| `title` | 画面上部に表示するタイトル | `Media Library` |
| `per_page` | 1ページあたりの表示件数（`0` で全件表示） | `60` |
| `thumb_size` | サムネイルの表示サイズ（px） | `200` |
| `default_view` | 一覧の見せ方の初期値（`grid` / `list`。画面の「設定」の初期値） | `grid` |
| `default_sort` | 並び順の初期値（`name` / `date` / `size`） | `name` |
| `default_order` | 並び方向の初期値（`asc` / `desc`） | `asc` |
| `read_only` | `true` にすると整理機能を使えなくする | `false` |
| `video_muted` | 動画を音を消した状態で再生し始める（画面の「設定」の初期値） | `true` |
| `video_size` | 動画を再生するときの大きさ（`original` / `fit`。画面の「設定」の初期値） | `original` |
| `trash_dir` | ゴミ箱の場所（各フォルダからの相対パス） | `.trash` |
| `max_name_length` | 名前として受け付ける最大文字数 | `100` |
| `max_upload_size` | 取り込むファイル1つあたりの上限（バイト。`0` で上限なし） | `2GB` |
| `upload_chunk_size` | 取り込むときに切り分ける大きさ（バイト） | `4MB` |
| `upload_temp_dir` | 取り込み中の作業場所（各フォルダからの相対パス） | `.upload` |

`roots` の各項目は、次の形で書きます。フォルダを増やしたいときは、ここに足せばタブも増えます。

| 項目 | 説明 | 例 |
| --- | --- | --- |
| `label` | タブに表示する名前 | `写真` |
| `dir` | 実際のフォルダ | `__DIR__ . '/photos'` |
| `url` | 参照するURL（`index.php` からの相対パス） | `photos` |
| `extensions` | 表示対象の拡張子 | `jpg, jpeg, png, gif, webp, avif, bmp` |
| `unit` | 件数の単位 | `枚` |
| `kind` | `image` か `video`。`video` は再生できる形で表示する | `image` |


### 画面から変えられる設定

一覧の見せ方・動画の音・動画の大きさは、画面右上の「⚙ 設定」からも切り替えられます。
そちらで変えた内容は、そのブラウザだけに残ります（→ [使い方](docs/usage.md#画面から変えられる設定)）。

`config.php` の `default_view`・`video_muted`・`video_size` は、この設定の初期値にあたります。

## ドキュメント

| 文書 | 内容 |
| --- | --- |
| [使い方](docs/usage.md) | 画面での操作、写真とフォルダの整理、フォルダ情報（`info.json`） |
| [内部のしくみ](docs/tech.md) | ファイル構成、セキュリティ上の配慮、表示の重さ、旧 `info.yml` からの移行 |
| [Googleアカウントで閲覧できる人を限定する](docs/google-auth.md) | Cloudflare Access を使った閲覧制限の手順 |

## ライセンス

MIT License

Copyright (c) 2026 Kenichi Wakabayashi

詳しくは [LICENSE](LICENSE) を参照してください。
