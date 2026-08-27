<?php
/**
 * Media Library : info.yml を読むための、小さなYAMLの読み取り
 *
 * フォルダ情報は info.json に変わったため、これを使うのは
 * tools/convert-info.php（info.yml から info.json への一括変換）だけ。
 * アプリ本体からは読み込まれない。
 *
 * 扱うのは info.yml に必要な書き方だけに絞っている。
 *
 *     title: 2026年 春の遠足
 *     thumbnail: cover.jpg
 *     items:
 *       - item: 開催日
 *         value: 2026-04-05
 *       - item: 場所
 *         value: 井の頭公園
 *         url: https://example.com/park
 *
 * 対応しているもの
 *   - 「キー: 値」の並び
 *   - キーの下に「- 」で並べるリスト（要素は「キー: 値」の集まり）
 *   - # から行末までのコメント、空行、--- の区切り
 *   - "…" と '…' の引用符
 *
 * 対応していないもの（書いても読み飛ばす）
 *   - さらに深い入れ子、| や > で続ける複数行、& や * の参照
 *   - { } や [ ] を使った書き方
 *
 * 値はすべて文字列として読む。2026-04-05 のような日付や 24 のような数も、
 * 書いたとおりの見た目で表示したいため。
 *
 * PHP の yaml 拡張は使わない。入っているサーバーと入っていないサーバーで
 * 書ける記法が変わると、ファイルを書く人が困るため。
 */

/**
 * YAML の文字列を配列にする。読めない行は読み飛ばす。
 */
function pv_yaml_parse(string $yaml): array
{
    $data     = [];
    $listKey  = null; // いま組み立てているリストのキー
    $list     = [];   // その中身
    $item     = null; // 組み立てている途中のリストの要素
    $lastKey  = null; // 直前に出てきた、値が空のキー

    foreach (pv_yaml_lines($yaml) as $line) {
        $indent = $line['indent'];
        $text   = $line['text'];

        // 「- 」で始まる行。リストの要素が1つ始まる。
        if (preg_match('/^-(?:\s+(.*))?$/u', $text, $matched)) {
            // 受け皿になるキーがなければ、置き場所がないので読み飛ばす
            if ($lastKey === null) {
                continue;
            }

            if ($listKey !== $lastKey) {
                $listKey = $lastKey;
                $list    = [];
            }

            if ($item !== null) {
                $list[] = $item;
            }

            $item = [];

            $rest = isset($matched[1]) ? trim($matched[1]) : '';
            $pair = $rest === '' ? null : pv_yaml_pair($rest);

            if ($pair !== null) {
                $item[$pair['key']] = $pair['value'];
            }

            continue;
        }

        $pair = pv_yaml_pair($text);

        if ($pair === null) {
            continue;
        }

        // リストの要素の中の「キー: 値」（「- 」の行より深い位置にある）
        if ($item !== null && $indent > 0) {
            $item[$pair['key']] = $pair['value'];
            continue;
        }

        // ここから上の段に戻るので、組み立てていたリストを片付ける
        if ($listKey !== null) {
            if ($item !== null) {
                $list[] = $item;
            }

            $data[$listKey] = $list;

            $listKey = null;
            $list    = [];
            $item    = null;
        }

        if ($pair['value'] === '') {
            // 値が空のキー。次の行から始まるリストの受け皿になる。
            $lastKey = $pair['key'];
            $data[$pair['key']] = '';
            continue;
        }

        $lastKey = null;
        $data[$pair['key']] = $pair['value'];
    }

    if ($listKey !== null) {
        if ($item !== null) {
            $list[] = $item;
        }

        $data[$listKey] = $list;
    }

    return $data;
}

/**
 * 読む必要のある行だけを、字下げの深さと中身に分けて返す。
 * 空行・コメント行・--- などの区切りは落とす。
 */
function pv_yaml_lines(string $yaml): array
{
    $yaml = str_replace(["\r\n", "\r"], "\n", $yaml);
    $yaml = preg_replace('/^\xEF\xBB\xBF/', '', $yaml); // BOM

    $lines = [];

    foreach (explode("\n", $yaml) as $raw) {
        // タブでの字下げは YAML では認められていないが、
        // 書き間違いで崩れないよう、空白に直してから読む。
        $raw = str_replace("\t", '    ', $raw);

        $trimmed = trim($raw);

        if ($trimmed === '' || $trimmed[0] === '#' ||
            $trimmed === '---' || $trimmed === '...') {
            continue;
        }

        $lines[] = [
            'indent' => strlen($raw) - strlen(ltrim($raw, ' ')),
            'text'   => $trimmed,
        ];
    }

    return $lines;
}

/**
 * 「キー: 値」の行を分ける。その形でなければ null。
 *
 * キーに使えるのは : と # を含まない文字。値は行の残り全部なので、
 * 「url: https://example.com/」のように値の中に : があってもよい。
 */
function pv_yaml_pair(string $text): ?array
{
    if (!preg_match('/^([^:#]+):(?:\s+(.*))?$/u', $text, $matched)) {
        return null;
    }

    $key = trim($matched[1]);

    if ($key === '') {
        return null;
    }

    return [
        'key'   => $key,
        'value' => pv_yaml_scalar($matched[2] ?? ''),
    ];
}

/**
 * 値を読む。引用符を外し、後ろに付いたコメントを落とす。
 */
function pv_yaml_scalar(string $value): string
{
    $value = trim($value);

    if ($value === '') {
        return '';
    }

    $first = $value[0];
    $last  = substr($value, -1);

    if (strlen($value) >= 2 && $first === '"' && $last === '"') {
        $inner = substr($value, 1, -1);

        return str_replace(
            ['\\"', '\\n', '\\t', '\\\\'],
            ['"', "\n", "\t", '\\'],
            $inner
        );
    }

    if (strlen($value) >= 2 && $first === "'" && $last === "'") {
        return str_replace("''", "'", substr($value, 1, -1));
    }

    // 引用符で囲っていない値は、空白のあとの # から先をコメントとして落とす。
    // URL の #ページ内の位置 は空白を挟まないので、消えない。
    $value = preg_replace('/\s+#.*$/u', '', $value);

    return rtrim($value);
}
