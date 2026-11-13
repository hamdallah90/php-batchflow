<?php
/*
 * Shared helpers for the preloaded Table PHPT suites. Written from the behaviour rules only. Not a test itself.
 * Loads tests/bf_helper.inc and tests/bf_reference_m2b.inc, from this directory.
 *
 * What the helpers rest on: the spec says table results equal streaming results on the same data, so most checks run
 * one terminal twice, on a Table and on Csv::scan over the same bytes, and compare the rendered strings. The only
 * allowed difference is the error prefix: a streaming data-row error starts "row R (line L, offset O): ", the same
 * error from a Table query starts "row R: " (SPEC 5.11).
 */

$__bf_dir = is_file(__DIR__ . '/bf_helper.inc') ? __DIR__ : dirname(__DIR__) . '/tests';
require_once $__bf_dir . '/bf_helper.inc';
require_once $__bf_dir . '/bf_reference_m2b.inc';
unset($__bf_dir);

/** Options that exist on Table::from* only (SPEC 5.3); Csv::scan refuses them. */
const TB_TABLE_ONLY = ['dictionary', 'maxDictionarySize', 'maxTableBytes'];

/** Temp file holding $data, named after the running test, removed at shutdown. */
function tb_file(string $data): string
{
    $p = tempnam(sys_get_temp_dir(), 'bf_' . preg_replace('/\W+/', '_', basename($_SERVER['SCRIPT_FILENAME'] ?? 'test', '.php')) . '_');
    if ($p === false || file_put_contents($p, $data) !== strlen($data)) {
        throw new RuntimeException('temp file failed');
    }
    $GLOBALS['__bf_tmp'][] = $p;
    return $p;
}

/** Table over a temp file holding $data. */
function tb_load(string $data, array $schema, array $opts = [])
{
    return BatchFlow\Table::fromCsv(tb_file($data), $schema, $opts);
}

/** $opts without the Table-only keys, for the streaming run over the same bytes. */
function tb_stream_opts(array $opts): array
{
    return array_diff_key($opts, array_flip(TB_TABLE_ONLY));
}

/** Apply [kind, column, literal] predicates to a Table or a Query (both have the same where* methods). */
function tb_apply($t, array $preds)
{
    foreach ($preds as [$k, $c, $v]) {
        $t = match ($k) {
            'eq' => $t->whereEq($c, $v),
            'gt' => $t->whereGt($c, $v),
            'ne' => $t->whereNe($c, $v),
            'lt' => $t->whereLt($c, $v),
            'gte' => $t->whereGte($c, $v),
            'lte' => $t->whereLte($c, $v),
            'in' => $t->whereIn($c, $v),
        };
    }
    return method_exists($t, 'rowCount') ? $t->query() : $t;
}

/**
 * Rendered result of one terminal on a Query. $term: count, sum, summary, min, max, avg, aggregate, groupCount, groupSum,
 * groupAgg, toArray, cursor. $x: col, key, sum, keys, aggs, maxGroups, maxRows, batch (cursor fetch size, default 7).
 */
function tb_term($q, string $term, array $x = []): string
{
    return match ($term) {
        'count' => bf_fmt($q->count()),
        'sum' => bf_fmt($q->sum($x['col'])),
        'summary' => bf_fmt($q->summary($x['col'] ?? null)),
        'min' => bf_fmt($q->min($x['col'])),
        'max' => bf_fmt($q->max($x['col'])),
        'avg' => bf_fmt($q->avg($x['col'])),
        'aggregate' => bf_fmt_list($q->aggregate($x['aggs'])),
        'groupCount' => bf_fmt_groups($q->groupCount($x['key'], $x['maxGroups'] ?? 1000000)),
        'groupSum' => bf_fmt_groups($q->groupSum($x['key'], $x['sum'], $x['maxGroups'] ?? 1000000)),
        'groupAgg' => bf_fmt_lists($q->groupAgg($x['keys'], $x['aggs'], $x['maxGroups'] ?? 1000000)),
        'toArray' => bf_fmt_rows($q->toArray($x['maxRows'] ?? 10000)),
        'cursor' => bf_fmt_rows(tb_drain($q->cursor(), $x['batch'] ?? 7)),
    };
}

/** All rows of a cursor, fetched $batch at a time until the first empty batch. */
function tb_drain($cursor, int $batch): array
{
    $all = [];
    while (($rows = $cursor->fetch($batch)) !== []) {
        array_push($all, ...$rows);
    }
    return $all;
}

/** Run $fn, a closure returning a rendered string; return it or "Class: message" with the namespace stripped. */
function tb_try(callable $fn): string
{
    try {
        return $fn();
    } catch (Throwable $e) {
        return bf_cls($e) . ': ' . $e->getMessage();
    }
}

/** A streaming data-row prefix "row R (line L, offset O): " reduced to the table form "row R: " (SPEC 5.11). */
function tb_norm(string $s): string
{
    return preg_replace('/: row (\d+) \(line \d+, offset \d+\): /', ': row $1: ', $s);
}

/** One terminal on the table's query (after $preds) and on the same bytes streamed; "same", or a diagnosis. */
function tb_cmp($table, string $csv, array $schema, array $opts, array $preds, string $term, array $x = []): string
{
    $a = tb_try(fn() => tb_term(tb_apply($table, $preds), $term, $x));
    $b = tb_norm(tb_try(fn() => tb_term(bf_apply(bf_q($csv, $schema, tb_stream_opts($opts)), $preds), $term, $x)));
    return $a === $b ? 'same' : "DIFF table=[$a] stream=[$b]";
}

/**
 * Deterministic fixture, $n rows, columns id int64, k string, g int64, v int64, f float64, b bool, s string. Low-cardinality k and
 * g (about 10% NULL, plus the empty string and tricky strings in k), random v and f, s distinct per row. Returns [csv, schema].
 */
function tb_fixture(int $n, int $seed, bool $nulls = true): array
{
    mt_srand($seed);
    $pool = ['red', 'green', 'blue', '', 'é', '日本', 'a,b', "l\nf", 'q"t', '0', '00', 'Red'];
    $q = fn(string $s) => ($s === '' || strpbrk($s, ",\"\r\n") !== false) ? '"' . str_replace('"', '""', $s) . '"' : $s;
    $out = "id,k,g,v,f,b,s\n";
    for ($i = 1; $i <= $n; $i++) {
        $nul = fn() => $nulls && mt_rand(0, 9) === 0;
        $k = $nul() ? '' : $q($pool[mt_rand(0, count($pool) - 1)]);
        $g = $nul() ? '' : (string)(mt_rand(-5, 5) * 1000003);
        $v = $nul() ? '' : (string)mt_rand(-1000, 1000);
        $f = $nul() ? '' : (mt_rand(0, 50) === 0 ? '-0.0' : sprintf('%.2f', mt_rand(-5000, 5000) / 100));
        $b = $nul() ? '' : (mt_rand(0, 1) ? 'true' : 'false');
        $s = $nul() ? '' : "u$i";
        $out .= "$i,$k,$g,$v,$f,$b,$s\n";
    }
    return [$out, ['id' => 'int64', 'k' => 'string', 'g' => 'int64', 'v' => 'int64', 'f' => 'float64', 'b' => 'bool', 's' => 'string']];
}

/** Write $rows data lines "$rowFn(i)" after $header to a temp file in chunks, so no copy of the file lives in memory (bounded by bytes, not rows, so it works under a small memory_limit). */
function tb_big_file(string $header, int $rows, callable $rowFn): string
{
    $p = tempnam(sys_get_temp_dir(), 'bf_' . preg_replace('/\W+/', '_', basename($_SERVER['SCRIPT_FILENAME'] ?? 'test', '.php')) . '_');
    $GLOBALS['__bf_tmp'][] = $p;
    $f = fopen($p, 'wb');
    fwrite($f, $header);
    $b = '';
    for ($i = 1; $i <= $rows; $i++) {
        $b .= $rowFn($i);
        if (strlen($b) >= 262144) {
            fwrite($f, $b);
            $b = '';
        }
    }
    fwrite($f, $b);
    fclose($f);
    return $p;
}
