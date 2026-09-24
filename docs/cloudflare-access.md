# Cloudflare Access で、写真・動画のファイルまで守る

決めておいたGoogleアカウントの人だけがこのツールを開けるようにする方法のうち、
**写真・動画のファイルそのものまで守れる**やり方です。

Cloudflare の **Cloudflare Access**（Zero Trust）をサーバーの手前に置いて、
そこで認証を済ませてもらう形をとります。ツール本体のPHPには手を入れません。

- 無料の範囲（50ユーザーまで）で使えます
- 独自ドメインで運用していることが条件です
- 作業時間の目安は1時間ほど。うちDNSの反映待ちが大半です

> **この文書は手順書であり、作業記録でもあります。**
> 2026-09-21 にこの方法へ切り替え、動作を確認しました。
> 手順5の `.htaccess` は反映済みです。以下は、そのときに実際に通った手順に
> 気づいた点を書き足したものです。
>
> 切り替え後の構成は次のとおりです。ホスト名などの実際の値は
> `.htaccess` に書いてあります（このファイルはGit管理から外してあります）。
>
> | 項目 | 選んだもの |
> |---|---|
> | 保護する場所 | 専用のサブドメインを新設し、公開フォルダをこのツールに直接向けた（Path は空欄） |
> | 認証先 | Google のみ（ワンタイムPINは使わない） |
> | Basic認証 | 停止（`.htaccess` でコメントアウト） |
> | ツール内蔵のGoogleログイン | 停止（`auth-config.php` の `enabled` を `false`） |

## 目次

- [この方法と、ツールに組み込んだログインの違い](#この方法とツールに組み込んだログインの違い)
- [なぜこの方法だとファイルまで守れるか](#なぜこの方法だとファイルまで守れるか)
- [全体の流れ](#全体の流れ)
- [はじめる前に](#はじめる前に)
- [手順1 : ドメインを Cloudflare に載せる](#手順1--ドメインを-cloudflare-に載せる)
- [手順2 : SSL を設定する](#手順2--ssl-を設定する)
- [手順3 : Google を認証先として登録する](#手順3--google-を認証先として登録する)
- [手順4 : 保護するURLと、通す人を決める](#手順4--保護するurlと通す人を決める)
- [手順5 : 裏口を塞ぐ（重要）](#手順5--裏口を塞ぐ重要)
- [手順6 : 動作を確かめる](#手順6--動作を確かめる)
- [任意 : ログイン中のアカウントを画面に出す](#任意--ログイン中のアカウントを画面に出す)
- [うまくいかないとき](#うまくいかないとき)
- [知っておいてほしいこと](#知っておいてほしいこと)
- [付録 : 写真・動画の配信をPHP経由にする案](#付録--写真動画の配信をphp経由にする案)

## この方法と、ツールに組み込んだログインの違い

このツールには、Googleアカウントでログインする機能が組み込まれています
（→ [Googleアカウントでログインする](google-auth.md)）。
設定はそちらのほうが手軽ですが、守れるのは画面だけです。

| | ツールに組み込んだログイン | Cloudflare Access |
|---|---|---|
| 設定の手間 | 30分ほど | 1時間ほど＋DNSの反映待ち |
| 独自ドメイン | 要らない | 必須 |
| 外部サービス | 使わない | Cloudflare に依存する |
| 一覧・操作の画面 | 守れる | 守れる |
| 写真・動画の直接URL | **守れない** | 守れる |
| 動作が止まる要因 | 自分のサーバーだけ | Cloudflare の障害でも止まる |

写真のURLを知られること自体が困る場合は、こちらを選んでください。
身内で見るだけで、URLが漏れる心配が薄いなら、組み込みのログインで足ります。

## なぜこの方法だとファイルまで守れるか

このツールは、写真・動画のファイルを `media/` に置いて、
ブラウザから**直接URLで**読み込ませています。PHPを通っていません。

```
ブラウザ ──> index.php  （PHP。一覧の画面を組み立てる）
        └──> media/2024/IMG_0001.jpg  （PHPを通らない。ただのファイル）
```

そのため、PHPの中にログイン処理を書き足しても、写真そのものは守れません。
URLさえ知っていれば誰でも開けてしまいます。
ツールに組み込んだGoogleログインも、ここは同じです。

きちんと守ろうとすると、写真の配信もPHPを経由させる作りに変える必要があり、
動画のシーク（再生位置の移動）に必要な Range リクエストの処理まで
自分で書くことになります。作業量が大きいわりに、表示は今より遅くなります。

Cloudflare Access はサーバーの手前に立つので、PHPもファイルも区別なく、
まとめて後ろに隠せます。

```mermaid
flowchart LR
    U[閲覧する人] --> CF{Cloudflare Access}
    CF -->|許可したアカウント| S[さくらのサーバー<br/>index.php / media]
    CF -->|それ以外| X[Googleのログイン画面へ]
```

## 全体の流れ

| | やること | 作業する場所 |
|---|---|---|
| 手順1 | ドメインを Cloudflare に載せる | Cloudflare / さくらの会員メニュー |
| 手順2 | SSL を設定する | Cloudflare |
| 手順3 | Google を認証先として登録する | Google Cloud Console / Cloudflare |
| 手順4 | 保護するURLと、通す人を決める | Cloudflare Zero Trust |
| 手順5 | 裏口を塞ぐ | `.htaccess`（このツール） |
| 手順6 | 動作を確かめる | ブラウザ |

## はじめる前に

用意しておくもの、確認しておくことです。

- **独自ドメイン**。`pote2.sakura.ne.jp` のような、さくらから割り当てられた
  ドメインでは Cloudflare に載せられません
- **Cloudflare のアカウント**（無料で作れます）
- **Google アカウント**。認証の入口を作るのに使います
- **許可したい人のメールアドレス**。Googleアカウントのものである必要があります

そして、いちばん大事な確認です。

> **そのドメインでメールを送受信していませんか。**
>
> 手順1でネームサーバーを Cloudflare に変えると、DNSの設定は
> Cloudflare 側のものに切り替わります。メール用の MX レコードを
> 移し忘れると、**そのドメイン宛のメールが届かなくなります**。
>
> Cloudflare はドメインを追加するときに既存のDNSレコードを読み取って
> 引き継ごうとしますが、取りこぼすことがあります。手順1の途中で
> 必ず自分の目で確かめてください。確認箇所は手順1の中に書いています。

## 手順1 : ドメインを Cloudflare に載せる

### 1-1. Cloudflare にドメインを追加する

1. [Cloudflare](https://dash.cloudflare.com/) にログインする
2. **Add a domain** から、使っているドメイン（例 `example.com`）を入れる
3. プランは **Free** を選ぶ
4. Cloudflare が今のDNSレコードを読み取って一覧にする

### 1-2. DNSレコードを確かめる（ここが山場）

読み取られた一覧を、さくらの会員メニューにある今のDNS設定と見比べます。
とくに次のものが**抜けていないか**を確認してください。

| 種類 | 何のためのものか | 抜けたときに起きること |
|---|---|---|
| `MX` | メールの宛先 | **メールが届かなくなる** |
| `TXT`（SPF / DKIM / DMARC） | 送ったメールが本物だと示す | 送ったメールが迷惑メール扱いされる |
| `A` / `CNAME`（`www` など） | サイトの場所 | サイトが開かなくなる |
| `CNAME`（`_domainkey` など） | 各種サービスの所有確認 | 連携しているサービスが切れる |

足りないものは、この画面で手で足しておきます。

**プロキシの設定**（オレンジ色の雲のアイコン）は次のようにします。

- このツールを置いているホスト名（例 `media.example.com`）… **プロキシ有効（オレンジの雲）**
- `MX` レコードが指すホスト名 … **プロキシ無効（グレーの雲）**
- その他のメール関連 … **プロキシ無効（グレーの雲）**

メール系をオレンジにするとメールが止まります。ここは間違えないでください。

### 1-3. ネームサーバーを変更する

Cloudflare が2つのネームサーバー（`xxx.ns.cloudflare.com` のような形）を表示します。
これをさくら側に登録します。

1. [さくらインターネット会員メニュー](https://secure.sakura.ad.jp/) にログイン
2. **契約情報** → **契約ドメインの確認** から対象のドメインを選ぶ
3. **ネームサーバの変更** を開く
4. もともと入っている `NS1.DNS.NE.JP` `NS2.DNS.NE.JP` を消す
5. Cloudflare に表示された2つを、ネームサーバ1・ネームサーバ2に入れる
6. 保存する

登録したら Cloudflare の画面に戻り、**Check nameservers** を押します。

反映には数分から48時間かかります。たいていは1時間以内です。
`Active` になるまで待ちます。

反映されたかどうかは、手元のターミナルからも見られます。

```
dig NS example.com +short
```

`ns.cloudflare.com` を含む結果が返れば切り替わっています。

## 手順2 : SSL を設定する

Cloudflare の **SSL/TLS** → **Overview** で、暗号化モードを選びます。

- さくらの無料SSL（Let's Encrypt）を有効にしている → **Full (strict)**
- 有効にしていない → まず[さくらの無料SSLを有効にして](https://help.sakura.ad.jp/206053711/)から **Full (strict)**

> **Flexible は選ばないでください。**
> Cloudflare とサーバーの間が暗号化されないうえ、
> このツールの `.htaccess` にHTTPSへのリダイレクトを書いている場合、
> リダイレクトが無限に繰り返されてページが開かなくなります。

あわせて **SSL/TLS** → **Edge Certificates** で
**Always Use HTTPS** を有効にしておくと、`http://` で来た人も
自動的に `https://` に回されます。

こうしておけば、このツールの `.htaccess` に書いてある
HTTPSリダイレクトの4行は、コメントアウトしたままで構いません。

## 手順3 : Google を認証先として登録する

「Googleでログイン」を成立させるための下ごしらえです。
Google Cloud Console で鍵を作り、それを Cloudflare に渡します。

### 3-1. チーム名を決める

先に Cloudflare 側のチーム名が必要です。

1. Cloudflare のサイドバーから **Zero Trust** を開く
2. 初回はチーム名（team name）を聞かれるので決める（例 `kwaka`）
3. プランは **Free** を選ぶ

決めたチーム名から、次のURLができます。手順3-3で使います。

```
https://<チーム名>.cloudflareaccess.com
```

### 3-2. Google Cloud Console でクライアントIDを作る

1. [Google Cloud Console](https://console.cloud.google.com/) を開く
2. プロジェクトを新しく作る（名前は何でも構いません。例 `media-library-auth`）
3. **APIs & Services** → **OAuth consent screen** を開く
4. **Get started** を押し、アプリ名とサポート用のメールアドレスを入れる
5. Audience Type は **External** を選ぶ
6. 連絡先メールアドレスを入れて **Continue** → **Create**

続けて鍵を作ります。

7. **APIs & Services** → **Credentials** を開く
8. **Create Credentials** → **OAuth client ID**
9. Application type は **Web application**
10. **Authorized JavaScript origins** に次を入れる

    ```
    https://<チーム名>.cloudflareaccess.com
    ```

11. **Authorized redirect URIs** に次を入れる

    ```
    https://<チーム名>.cloudflareaccess.com/cdn-cgi/access/callback
    ```

12. **Create** を押すと、**クライアントID** と **クライアントシークレット** が出る

この2つは次で使います。シークレットは他人に見せないでください。
画面を閉じても、後から同じ場所で確認できます。

> 一般のGoogleアカウント（`@gmail.com` など）を使う場合、
> OAuth同意画面は「テスト」状態のままでも構いません。
> ただしテスト状態だと、Test users に登録した人しかログインできず、
> 有効期限も短くなります。継続して使うなら **Publish app**（本番公開）に
> しておくほうが手間がありません。外部に公開されるのはアプリ名だけで、
> 誰でも入れるようになるわけではありません。誰を通すかは手順4で決めます。

### 3-3. Cloudflare に登録する

1. **Zero Trust** → **Integrations** → **Identity providers**
2. **Add new identity provider** を押す
3. **Google** を選ぶ

    - Google Workspace をお使いで、組織のアカウント全体を対象にしたい場合は
      **Google Workspace** のほうを選びます。今回は個人のGoogleアカウントを
      想定して **Google** で説明します

4. さきほどのクライアントIDとクライアントシークレットを貼る
5. **Save** を押す
6. **Test** を押して、自分のGoogleアカウントでログインできることを確かめる

ここで失敗する場合、たいていはリダイレクトURIの打ち間違いです。
Google Cloud Console 側の綴りをもう一度見てください。

## 手順4 : 保護するURLと、通す人を決める

### 4-1. アプリケーションを作る

1. **Zero Trust** → **Access controls** → **Applications**
2. **Create new application** を押す
3. **Self-hosted and private** を選ぶ
4. **Add public hostname** を選ぶ
5. 保護する場所を指定する

    | 項目 | 入れるもの（例） |
    |---|---|
    | Subdomain | `media` |
    | Domain | `example.com` |
    | Path | （このツールがドメイン直下なら空欄） |

    サブディレクトリに置いている場合（例 `https://example.com/gallery/`）は、
    Path に `gallery` と入れます。

6. **Session Duration** を決める。既定は24時間です。
   毎日使うなら1週間や1か月にすると、ログインを求められる回数が減ります

### 4-2. 誰を通すかを決める

**Access policies** で **Create new policy** を押します。

1. ポリシー名を決める（例 `家族だけ`）
2. Action は **Allow**
3. ルールを次のように作る

    **決まった人だけ通す場合**

    | Action | Rule type | Selector | Value |
    |---|---|---|---|
    | Allow | Include | Emails | `you@gmail.com` |
    | Allow | Include | Emails | `family@gmail.com` |

    Value は続けて何件でも足せます。

    **ドメイン単位で通す場合**（Google Workspace 向け）

    | Action | Rule type | Selector | Value |
    |---|---|---|---|
    | Allow | Include | Emails ending in | `@example.com` |

    **両方を混ぜる場合**

    Include に並べたものは「どれかに当てはまれば通す」という扱いです。
    社内ドメイン全員と、外部の協力者数名、という指定ができます。

4. 保存して、アプリケーションに割り当てる

ポリシーを1つも作らないと**すべて拒否**になります。
逆に言えば、書き忘れて誰でも入れる状態になることはありません。

#### 画面で迷いやすいところ

ルールの条件は、「含める（Include）」の行にあるドロップダウンで選びます。
日本語表示では「セレクター」という言葉が出てこないので、どこを触るのか
分かりにくいのですが、**条件名が表示されているドロップダウンそのもの**が
セレクターです。

> **「ログイン方法 = Google」のままにしないこと。**
>
> 初期状態ではセレクターに「ログイン方法」が入っていることがあります。
> これは「Googleでログインできること」しか見ていないため、
> **Googleアカウントを持っている人なら誰でも通ってしまいます**。
> かならず「メール」に変えて、通すアドレスを列挙してください。

- 選択肢の一覧は長いので、ドロップダウンを開いた状態で `email` と打つと
  絞り込めます
- 「メール」が一覧に見当たらないときは、**アクションが「許可」になっているか**
  確かめてください。Bypass を選ぶと、身元にもとづくセレクターは選べなくなります
- Google以外の方法も塞ぐなら、`+ 必須を追加 (AND)` で「ログイン方法 = Google」を
  足します。ただし手順4-3で認証先をGoogleだけに絞れば、同じ結果になります

### 4-3. 認証先を絞る

アプリケーションの設定画面で、identity providers から
**Google** だけを選んでおきます。

こうしないと、ワンタイムPIN（メールに届く数字）でも入れる状態になります。
「Googleアカウントで認証されたユーザーだけ」という条件にするなら、
ここは絞っておいてください。

**Apply instant authentication** を有効にすると、
「どの方法でログインしますか」という選択画面が省かれ、
いきなりGoogleのログイン画面に飛びます。認証先が1つだけなら
有効にしておくほうが親切です。

## 手順5 : 裏口を塞ぐ（重要）

**ここを飛ばすと、ここまでの設定がほぼ意味を失います。**

Cloudflare Access が守れるのは「Cloudflare を通ってきたリクエスト」だけです。
ところが、さくらのレンタルサーバーは独自ドメインとは別に、
はじめから割り当てられているドメインでも同じ場所に届いてしまいます。

```
https://media.example.com/          ──> Cloudflare を通る ──> 認証あり
https://pote2.sakura.ne.jp/gallery/ ──> Cloudflare を通らない ──> 素通り
```

サーバーのIPアドレスを直接叩かれた場合も同じです。
この2つを `.htaccess` で塞ぎます。

### 塞ぎ方

このツールの `.htaccess` に、次の2つを足します。
`.htaccess` は `.htaccess.example` をコピーして作るものなので、
**両方に同じ内容を入れておく**と、次にサーバーを移すときに困りません。

```apache
# ---------------------------------------------------------------
# Cloudflare Access を通らないアクセスを拒否する
#
# Cloudflare Access は「Cloudflare を通ったリクエスト」しか守れない。
# さくらの初期ドメインやサーバーのIPを直接叩かれると素通りしてしまうため、
# ここで入口を独自ドメイン＋Cloudflare経由だけに絞る。
# ---------------------------------------------------------------

# (1) 独自ドメイン以外のホスト名で来たものを拒否する
#     media.example.com の部分は、実際に使っているホスト名に書き換えること。
<IfModule mod_rewrite.c>
    RewriteEngine On
    RewriteCond %{HTTP_HOST} !^media\.example\.com$ [NC]
    RewriteRule ^ - [F,L]
</IfModule>

# (2) Cloudflare のIPアドレス以外から来たものを拒否する
#     ホスト名は偽装できるので、こちらが本命の守り。
#     一覧は https://www.cloudflare.com/ips/ で公開されている。
#     年に数回更新されるため、つながらなくなったときは確認すること。
<IfModule mod_authz_core.c>
    <RequireAny>
        # IPv4
        Require ip 173.245.48.0/20
        Require ip 103.21.244.0/22
        Require ip 103.22.200.0/22
        Require ip 103.31.4.0/22
        Require ip 141.101.64.0/18
        Require ip 108.162.192.0/18
        Require ip 190.93.240.0/20
        Require ip 188.114.96.0/20
        Require ip 197.234.240.0/22
        Require ip 198.41.128.0/17
        Require ip 162.158.0.0/15
        Require ip 104.16.0.0/13
        Require ip 104.24.0.0/14
        Require ip 172.64.0.0/13
        Require ip 131.0.72.0/22
        # IPv6
        Require ip 2400:cb00::/32
        Require ip 2606:4700::/32
        Require ip 2803:f800::/32
        Require ip 2405:b500::/32
        Require ip 2405:8100::/32
        Require ip 2a06:98c0::/29
        Require ip 2c0f:f248::/32
    </RequireAny>
</IfModule>
```

> **Basic認証と併用するなら、囲み方に注意。**
>
> `Require valid-user`（Basic認証）と `Require ip`（IP制限）を、囲まずに
> 並べて書いてはいけません。Apache は同じ場所に並んだ `Require` を
> 「どちらか通れば可」（暗黙の `RequireAny`）と解釈するため、
> **Cloudflare経由のアクセスがBasic認証を素通りします**。
>
> 両方を必須にするなら、次のように `RequireAll` で囲みます。
>
> ```apache
> <RequireAll>
>     Require valid-user
>     <RequireAny>
>         Require ip 173.245.48.0/20
>         （以下同じ）
>     </RequireAny>
> </RequireAll>
> ```
>
> Basic認証をやめるなら、この心配は要りません（`RequireAny` だけを残す）。
> なお `Require` は Apache 2.4 以降のものです。`mod_authz_core` がない環境では
> このブロックがまるごと無視され、**誰でも開ける状態になったことに気づけません**。
> `<IfModule !mod_authz_core.c>` で全面拒否にしておくと、その取り違えを防げます。

上のIPアドレス一覧は2026年8月時点のものです（2026-09-21 に確認し、変更なし）。
最新の一覧は次のコマンドで取れます。

```
curl -s https://www.cloudflare.com/ips-v4; echo; curl -s https://www.cloudflare.com/ips-v6
```

### Basic認証・組み込みのログインはどうするか

Cloudflare Access が入れば、どちらも要りません。

- `.htaccess` の Basic認証 … `AuthType Basic` から `Require valid-user` までの
  4行はコメントアウトしたままで構いません
- ツールに組み込んだGoogleログイン … `auth-config.php` の `enabled` を
  `false` にするか、ファイルごと置かないでおきます

どちらも重ねて有効にできますが、閲覧するたびにログインを2回通ることになります。
Cloudflare Access のほうが広い範囲を守るので、重ねる意味はほとんどありません。

今回はどちらも止めました。`auth-config.php` はファイルごと消さずに
`enabled` を `false` にしてあるので、Cloudflare を使わない場所に置き直すときは
`true` に戻せば元に戻ります（`redirect_uri` の書き換えは必要です）。

`.htpasswd` と `make-htpasswd.php`、`auth-config.php` は、Cloudflare を使わない場所へ
置き直すときのために残しておくとよいでしょう。

## 手順6 : 動作を確かめる

順番に見ていきます。

**1. 許可したアカウントで入れるか**

シークレットウィンドウで `https://media.example.com/` を開きます。
Googleのログイン画面が出て、許可したアカウントでログインすると
一覧が表示されれば成功です。

**2. 許可していないアカウントが弾かれるか**

別のGoogleアカウントでログインしてみます。
「You do not have permission」のような画面が出れば成功です。

**3. 写真そのものも守られているか**

ログアウトした状態（別のシークレットウィンドウ）で、
写真のURLを直接開いてみます。

```
https://media.example.com/media/2024/IMG_0001.jpg
```

ログイン画面に飛べば成功です。写真が表示されてしまったら、
手順4のPath指定を見直してください。

**4. 裏口が塞がっているか**

```
curl -I https://pote2.sakura.ne.jp/gallery/
```

`403 Forbidden` が返れば成功です。`200 OK` が返ったら手順5ができていません。

サーバーのIPを直接叩く経路も確かめておきます。ホスト名を詐称しても
Cloudflare を通っていなければ拒否される、という確認です。

```
IP=$(dig +short あなたのサーバーのホスト名 | tail -1)
curl -sI --resolve media.example.com:80:$IP http://media.example.com/
```

**まとめて確かめる**

ブラウザを使わずに済ませるなら、次の3つが Cloudflare のログイン画面への
302 になっていることを見ます。**存在しないファイル名でも 302 になる**のが
大事な点です。404 が返るなら、そのパスは認証を通っていません。

```
curl -sI https://media.example.com/ | head -4
curl -sI https://media.example.com/media/__no_such_file__.jpg | head -4
curl -sI https://media.example.com/media/__no_such_file__.mp4 | head -4
```

```
location: https://<チーム名>.cloudflareaccess.com/cdn-cgi/access/login/...
```

**5. 動画が再生できるか、シークできるか**

動画を開いて、再生バーの途中をクリックしてみます。
そこから再生が続けば問題ありません。

**6. ドラッグ&ドロップで取り込めるか**

大きめの動画ファイルを落としてみます。
このツールは4MBずつに分けて送るので、Cloudflare 無料プランの
100MB制限には当たりません。

## 任意 : ログイン中のアカウントを画面に出す

Cloudflare Access は、認証を通したリクエストに
ログインした人のメールアドレスを添えてサーバーへ渡します。
これを読めば、画面に「誰として見ているか」を出せます。

**この機能は手順5が済んでいることが前提です。**
Cloudflare を通らないアクセスを拒否していないと、
このヘッダは偽装できてしまいます。

**これは実装済みです。** `lib/auth.php` の `pv_auth_access_email()` が
ヘッダを読み、`index.php` のヘッダー右端に、ツールに組み込んだログインと
同じ見た目のログアウトボタンを出します。押すと Cloudflare のログアウトURL
`/cdn-cgi/access/logout` に飛びます（このURLは Cloudflare が用意しているもので、
ツール側で作る必要はありません）。

ツールに組み込んだログインを使っているときは、そちらの表示が優先されます。
どちらも使っていなければ、何も出ません。

読み取った値は画面に出すだけで、通す・通さないの判断には使っていません。
判断は Cloudflare Access に任せ、ツール側では「表示のための情報」として
扱う、という切り分けです。あわせて、メールアドレスの形をしていない値は
無視します。

> より厳密にやるなら、`Cf-Access-Jwt-Assertion` ヘッダに入っている
> JWT を検証します。公開鍵は
> `https://<チーム名>.cloudflareaccess.com/cdn-cgi/access/certs` から取れ、
> アプリケーションごとの **Application Audience (AUD) Tag**
> （Zero Trust → Access controls → Applications → Configure →
> Additional settings で確認できます）と照合します。
>
> ただしこれはPHPでのRS256署名検証を自前で書くことになり、
> 手順5を済ませていれば得られるものはほとんどありません。
> 今回は必要ないと判断しています。

## うまくいかないとき

**ページが開かず、リダイレクトが繰り返される**

SSLモードが Flexible になっています。手順2に戻って **Full (strict)** にしてください。

**526 Invalid SSL certificate が出る**

Full (strict) にしたのに、さくら側の無料SSLが有効になっていません。
さくらのコントロールパネルで証明書を発行してください。
急ぐ場合は一時的に **Full**（strict なし）にすると通ります。

**Googleのログイン画面で redirect_uri_mismatch と出る**

Google Cloud Console に登録したリダイレクトURIが違っています。
末尾の `/cdn-cgi/access/callback` まで含めて、
チーム名の綴りも含めて見比べてください。

**ログインはできるのに「permission がない」と言われる**

まず、**ログイン画面のドメインを見てください。** `<チーム名>.cloudflareaccess.com`
の部分が、守りたいサイトのチーム名になっていますか。

Cloudflare のアカウントやチームを複数持っていると、別のチームで作った
同じ名前のアプリのログイン画面を開いてしまうことがあります。その画面で
弾かれても、本命のポリシーとは関係がありません。自分がどのチームにいるかは
**Zero Trust → Settings** の Team domain で確認できます。

`gallery.crssrds.jp` のようなホスト名を守れるのは、**そのドメインのゾーンを
持っているアカウント**の Zero Trust だけです。どのチームが効いているかは、
次のコマンドで確かめられます。

```
curl -sI https://media.example.com/ | grep -i ^location
```

`location:` に出てくるドメインが、実際に効いているチームです。
ブラウザで開いた画面がそれと違っていたら、見ている画面のほうが間違いです。
古いタブやブックマークを踏んだ場合も同じことが起こります。

チームが合っていれば、原因は手順4-2のポリシーにそのメールアドレスが
入っていないことです。Googleアカウントのメールアドレスと、ポリシーに
書いたものが一致しているか確かめてください（別名のアドレスだと一致しません）。
弾かれた記録は **Zero Trust → Logs → Access** に残るので、そこに出ている
メールアドレスを見るのが確実です。

**自分も 403 で入れなくなった**

手順5のIP制限が効きすぎています。
Cloudflare のDNS設定で、そのホスト名のプロキシがオレンジの雲に
なっているか確かめてください。グレーだと Cloudflare を経由しないため、
自分のIPから直接届いて拒否されます。

**アクセスログのIPアドレスが全部 Cloudflare のものになった**

仕様です。本来のIPアドレスは `CF-Connecting-IP` ヘッダに入っています。

**メールが届かなくなった**

手順1-2の MX レコードを取りこぼしています。
Cloudflare のDNS画面で MX レコードを確認し、
足りなければ足してください。またグレーの雲になっているかも確認してください。

## 知っておいてほしいこと

**Cloudflare の利用規約について**

Cloudflare の利用規約 Section 2.8 は、動画など大きなファイルを
CDN で大量に配信することを制限しています。
個人や家族で写真・動画を見る規模であれば、まず問題になりません。
不特定多数に向けた動画配信に育てる場合は、規約を読み直してください。

**無料プランの制限**

| | 制限 | このツールへの影響 |
|---|---|---|
| Access のユーザー数 | 50人まで | 通常は足ります |
| アップロード1回あたり | 100MB | 4MBずつ送るので影響なし |
| セッション | 既定24時間 | 手順4-1で延ばせます |

**外部サービスに依存することについて**

この方法は Cloudflare が動いていることが前提です。
Cloudflare に障害が起きると、このツールも開けなくなります。
また、無料プランの条件が将来変わる可能性もあります。

そうなったときは、次の付録の方法に切り替えることになります。

## 付録 : 写真・動画の配信をPHP経由にする案

Cloudflare を使わずに、ファイル本体まで守ろうとする場合の設計メモです。
**まだ実装していません。** 必要になったときの下敷きとして残します。

ログインの仕組みそのものは、すでにツールに入っています
（→ [Googleアカウントでログインする](google-auth.md)）。
足りないのは、写真・動画の配信をPHPに通す部分だけです。

### 必要になるもの

| ファイル | 役割 | いまの状態 |
|---|---|---|
| `auth-config.php` | クライアントIDなどの設定。Git管理から外してある | 実装済み |
| `auth-config.php.example` | その見本 | 実装済み |
| `lib/auth.php` | OAuth 2.0 のフロー、許可アカウントの判定 | 実装済み |
| `login.php` | ログイン画面、Googleからの戻り先、ログアウト | 実装済み |
| `media.php` | **写真・動画の配信をPHP経由にする** | **未実装。ここが最大の作業** |

### 手をつける必要がある既存のコード

- `lib/functions.php` の `pv_image_url()` … `media.php` 経由のURLを返すように変える
- `media/.htaccess` … 直接アクセスを全面的に禁止する
- `config.php` … ルートの `url` は使わなくなる

画面側の認証（`index.php` `action.php` `browse.php` `upload.php`）は、すでに入っています。

### `media.php` で必要になる処理

- パスを `realpath` で解決し、ルートの外を指していないか確かめる
- 拡張子が `kinds` に書いたものに含まれるか確かめる
- `Content-Type` を返す
- **`Range` リクエストへの対応**。これがないと動画のシークができない
- `Last-Modified` / `ETag` / `304 Not Modified`
- `Cache-Control: private`

### この方法の弱点

- 写真60枚の一覧で、PHPのプロセスが60本走る。共有サーバーでは詰まりやすい
- 現状はサムネイルを作らずフルサイズの画像を並べているため、
  PHP経由にすると初回表示がはっきり遅くなる
  （`media.php` を作るなら、同時にサムネイル生成を入れるのが自然）
- 許可アカウントを変えるたびにファイルを編集してデプロイする必要がある

---

**参考にした資料**

- [Cloudflare Zero Trust : Self-hosted アプリケーションの設定](https://developers.cloudflare.com/cloudflare-one/applications/configure-apps/self-hosted-public-app/)
- [Cloudflare Zero Trust : Google を IdP にする](https://developers.cloudflare.com/cloudflare-one/integrations/identity-providers/google/)
- [Cloudflare Zero Trust : ポリシーの書き方](https://developers.cloudflare.com/cloudflare-one/access-controls/policies/)
- [Cloudflare : IPアドレス一覧](https://www.cloudflare.com/ips/)
- [さくらインターネット : 無料SSLの設定](https://help.sakura.ad.jp/206053711/)
