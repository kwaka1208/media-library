<?php
/**
 * Media Library : info.json の読み書き
 *
 * フォルダ情報は info.json に、次の形で書く。
 *
 *     {
 *       "title": "2026年 春の遠足",
 *       "thumbnail": "cover.jpg",
 *       "items": [
 *         { "item": "開催日", "value": "2026-04-05" },
 *         { "item": "場所", "value": "井の頭公園", "url": "https://example.com/park" }
 *       ]
 *     }
 *
 * 読み書きは PHP に元から入っている json_decode / json_encode だけを使う。
 * 値は文字ならびとして書く。2026-04-05 のような日付や 24 のような数も、
 * 書いたとおりの見た目で表示したいため。数で書かれていても読めるようにはしてある。
 */

/**
 * info.json の中身を配列にする。読めないときは空の配列を返す。
 *
 * JSON は1文字でも書き間違えるとファイル全体が読めなくなる。そのときは
 * 情報が無いのと同じ扱いになり、画面は止まらない。
 */
function pv_info_decode(string $raw): array
{
    $raw = preg_replace('/^\xEF\xBB\xBF/', '', $raw); // BOM

    $data = json_decode($raw, true);

    if (!is_array($data)) {
        return [];
    }

    // 一番外側が [ … ] のものは、フォルダ情報として扱わない。
    // その場合はキーが 0, 1, 2 … の並びになる。
    if ($data !== [] && array_keys($data) === range(0, count($data) - 1)) {
        return [];
    }

    return $data;
}

/**
 * info.json を書き出す。編集画面から保存するときに使う。
 *
 * 書き出すのは title / thumbnail / items だけ。手で書き足したほかのキーは
 * 残らないので、そのことは README に断っている。
 */
function pv_info_encode(string $title, string $thumbnail, array $items): string
{
    $data = [];

    if ($title !== '') {
        $data['title'] = $title;
    }

    if ($thumbnail !== '') {
        $data['thumbnail'] = $thumbnail;
    }

    if ($items !== []) {
        $rows = [];

        foreach ($items as $one) {
            $row = [
                'item'  => (string) ($one['item'] ?? ''),
                'value' => (string) ($one['value'] ?? ''),
            ];

            if (($one['url'] ?? '') !== '') {
                $row['url'] = (string) $one['url'];
            }

            $rows[] = $row;
        }

        $data['items'] = $rows;
    }

    if ($data === []) {
        return '';
    }

    // 日本語とパスの / は、そのままの見た目で書き出す。
    // テキストエディタで開いたときに読めるようにするため。
    $json = json_encode(
        $data,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );

    return $json === false ? '' : $json . "\n";
}
