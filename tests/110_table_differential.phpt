--TEST--
batchflow: Table terminals agree with the independent pure-PHP reference on 160 seeded random scenarios (seed 11010): plain and dictionary columns, delimiters, extra columns, every predicate kind, every terminal, valid and corrupted inputs
--EXTENSIONS--
batchflow
--FILE--
<?php
// Seed 11010 (mt_srand), 160 scenarios, 11 terminals per valid scenario. The reference is tests/bf_reference.inc and
// bf_reference_m2b.inc: byte-at-a-time, written from SPEC 5.4 to 5.9, sharing no code with the extension.
require __DIR__ . '/bf_table.inc';

mt_srand(11010);

function rnd_str(): string
{
    static $pool = ['a', 'b', 'ab', 'abc', 'A', 'é', '日本', 'x y', 'a,b', 'a;b', "l\nf", 'q"t', '0', '00', '7', ' ', 'k1', 'k2', 'k3'];
    return $pool[mt_rand(0, count($pool) - 1)];
}

function cell(string $s, string $dl, bool $force = false): string
{
    return ($force || $s === '' || strpbrk($s, "$dl\"\r\n") !== false) ? '"' . str_replace('"', '""', $s) . '"' : $s;
}

/** [csv, schema, tableOpts, preds, nOpts] for scenario $n. Every fifth scenario carries one corrupt cell (anywhere, any column). */
function scenario(int $n): array
{
    $dl = [',', ',', ';', "\t", '|'][mt_rand(0, 4)];
    $kt = mt_rand(0, 1) ? 'string' : 'int64';
    $vt = mt_rand(0, 1) ? 'int64' : 'float64';
    $S = ['id' => 'int64', 'k' => $kt, 'g' => 'int64', 'v' => $vt, 'f' => 'float64', 'b' => 'bool', 'x' => 'string'];
    $cols = array_keys($S);
    $extra = mt_rand(0, 3) === 0;
    $order = $cols;
    if ($extra) {
        array_splice($order, mt_rand(0, count($order)), 0, ['z']);
    }
    if (mt_rand(0, 1)) {
        $order = array_reverse($order);
    }
    $eol = mt_rand(0, 1) ? "\n" : "\r\n";
    $big = mt_rand(0, 9) === 0;
    $csv = implode($dl, array_map(fn($c) => cell($c, $dl), $order)) . $eol;
    $rows = mt_rand(0, 60);
    $corrupt = $n % 5 === 0 && $rows > 0 ? mt_rand(0, $rows - 1) : -1;
    for ($r = 0; $r < $rows; $r++) {
        $nul = fn() => mt_rand(0, 9) === 0;
        $cell = [
            'id' => (string)($r + 1),
            'k' => $nul() ? '' : ($kt === 'string' ? cell(rnd_str(), $dl, mt_rand(0, 4) === 0) : (string)(mt_rand(-3, 3) * 1000003)),
            'g' => $nul() ? '' : (string)mt_rand(-3, 3),
            'v' => $nul() ? '' : ($vt === 'int64' ? ($big && mt_rand(0, 3) === 0 ? '9223372036854775807' : (string)mt_rand(-1000, 1000)) : ($big && mt_rand(0, 3) === 0 ? '1e308' : sprintf('%.2f', mt_rand(-5000, 5000) / 100))),
            'f' => $nul() ? '' : (mt_rand(0, 30) === 0 ? '-0.0' : sprintf('%.2f', mt_rand(-5000, 5000) / 100)),
            'b' => $nul() ? '' : (mt_rand(0, 1) ? 'true' : 'false'),
            'x' => $nul() ? '' : cell(rnd_str(), $dl),
            'z' => 'junk',
        ];
        if ($r === $corrupt) {
            $col = $cols[mt_rand(0, count($cols) - 1)];
            $cell[$col] = ['zz"', '1.5', 'TRUE', "\xff", '""'][mt_rand(0, 4)];
        }
        $csv .= implode($dl, array_map(fn($c) => $cell[$c], $order)) . $eol;
    }
    if (mt_rand(0, 3) === 0) {
        $csv = rtrim($csv, "\r\n");
    }
    $dict = [];
    if (mt_rand(0, 1)) {
        foreach (['k', 'g'] as $c) {
            if (mt_rand(0, 1)) {
                $dict[] = $c;
            }
        }
    }
    $opts = ($dl !== ',' ? ['delimiter' => $dl] : []) + ($extra ? ['allowExtraColumns' => true] : []) + ($dict ? ['dictionary' => $dict] : []);
    $lit = fn(string $c) => match ($c) {
        'k' => mt_rand(0, 3) === 0 ? null : ($kt === 'string' ? rnd_str() : mt_rand(-3, 3) * 1000003),
        'g' => mt_rand(0, 4) === 0 ? null : mt_rand(-4, 4),
        'b' => mt_rand(0, 4) === 0 ? null : mt_rand(0, 1) === 1,
        'x' => mt_rand(0, 4) === 0 ? null : rnd_str(),
    };
    $preds = [];
    for ($p = mt_rand(0, 3); $p > 0; $p--) {
        $preds[] = match (mt_rand(0, 9)) {
            0 => ['eq', 'b', $lit('b')],
            1 => ['gt', 'id', mt_rand(0, 40)],
            2 => ['eq', 'x', $lit('x')],
            3 => ['eq', 'k', $lit('k')],
            4 => ['ne', 'k', $lit('k')],
            5 => ['ne', 'g', $lit('g')],
            6 => ['lt', 'g', mt_rand(-3, 3)],
            7 => ['gte', 'id', mt_rand(0, 40)],
            8 => ['lte', 'f', mt_rand(-20, 20) / 2],
            9 => ['in', mt_rand(0, 1) ? 'k' : 'g', []],
        };
        if ($preds[count($preds) - 1][0] === 'in') {
            $c = $preds[count($preds) - 1][1];
            $preds[count($preds) - 1][2] = array_values(array_map(fn() => $lit($c), range(1, mt_rand(1, 4))));
        }
    }
    return [$csv, $S, $opts, $preds, $vt, $kt];
}

$stats = ['valid' => 0, 'loadError' => 0, 'ok' => 0, 'err' => 0, 'maxGroups' => 0, 'overflow' => 0, 'empty' => 0, 'dict' => 0, 'plain' => 0, 'compared' => 0];
$diffs = [];
for ($n = 1; $n <= 160; $n++) {
    [$csv, $S, $opts, $preds, $vt, $kt] = scenario($n);
    $ropts = tb_stream_opts($opts);
    // SPEC 5.5: a table load references every column, so its error is the reference's toArray error on the same bytes
    $loaded = null;
    $load = tb_try(function () use (&$loaded, $csv, $S, $opts) {
        $loaded = tb_load($csv, $S, $opts);
        return 'LOADED';
    });
    $refAll = bf_gref($csv, $S, $ropts, [], 'toArray', ['maxRows' => 100000000]);
    $refLoads = str_starts_with($refAll, '{') || $refAll === '[]';
    $stats['compared']++;
    if ($refLoads !== ($load === 'LOADED') || (!$refLoads && $load !== $refAll)) {
        $diffs[] = "#$n load: table=[$load] ref=[" . substr($refAll, 0, 120) . ']';
        continue;
    }
    if (!$refLoads) {
        $stats['loadError']++;
        continue;
    }
    $stats['valid']++;
    $stats[isset($opts['dictionary']) ? 'dict' : 'plain']++;
    $maxG = [1000000, 1000000, 1, 2, 3, 5][mt_rand(0, 5)];
    $kc = mt_rand(0, 1) ? 'k' : 'g';
    $aggs = [['count', 'x'], ['sum', 'v'], ['min', 'f'], ['max', 'v'], ['avg', 'v'], ['count', 'k'], ['sum', 'g'], ['min', 'g'], ['max', 'f'], ['avg', 'f']];
    shuffle($aggs);
    $aggs = array_slice($aggs, 0, mt_rand(1, 5));
    $keys = [['k'], ['g'], ['k', 'g'], ['g', 'k', 'x'], ['x']][mt_rand(0, 4)];
    $cases = [
        ['count', [], ['sum' => null]],
        ['sum', [], ['sum' => 'v']],
        ['summary', [], ['sum' => mt_rand(0, 1) ? 'v' : null]],
        ['groupCount', ['key' => $kc, 'maxGroups' => $maxG], ['key' => $kc, 'maxGroups' => $maxG]],
        ['groupSum', ['key' => $kc, 'sum' => 'v', 'maxGroups' => $maxG], ['key' => $kc, 'sum' => 'v', 'maxGroups' => $maxG]],
        ['toArray', ['maxRows' => [10000, 10000, 1, 3, 7][mt_rand(0, 4)]], null],
        ['min', ['col' => 'f'], ['col' => 'f']],
        ['max', ['col' => 'v'], ['col' => 'v']],
        ['avg', ['col' => 'v'], ['col' => 'v']],
        ['aggregate', ['aggs' => $aggs], ['aggs' => $aggs]],
        ['groupAgg', ['keys' => $keys, 'aggs' => $aggs, 'maxGroups' => $maxG], ['keys' => $keys, 'aggs' => $aggs, 'maxGroups' => $maxG]],
    ];
    foreach ($cases as [$term, $x, $rx]) {
        $rx ??= $x;
        $tx = $x;
        if ($term === 'count') {
            $tx = [];
        }
        if ($term === 'sum') {
            $tx = ['col' => 'v'];
        }
        if ($term === 'summary') {
            $tx = ['col' => $rx['sum']];
        }
        $a = tb_try(fn() => tb_term(tb_apply($loaded, $preds), $term, $tx));
        $b = tb_norm(in_array($term, ['count', 'sum', 'summary'], true) ? BfRef::runStr($csv, $S, $ropts, $preds, $term, $rx['sum'] ?? null) : bf_gref($csv, $S, $ropts, $preds, $term, $rx));
        $stats['compared']++;
        if ($a !== $b) {
            $diffs[] = "#$n $term " . json_encode($opts) . " ext=[" . substr($a, 0, 160) . "] ref=[" . substr($b, 0, 160) . ']';
            continue;
        }
        if (str_contains($a, 'maxGroups')) {
            $stats['maxGroups']++;
        } elseif (str_contains($a, 'overflow') || str_contains($a, 'not finite')) {
            $stats['overflow']++;
        } elseif (str_contains($a, 'Exception')) {
            $stats['err']++;
        } elseif ($a === '[]' || $a === 'int(0)') {
            $stats['empty']++;
        } else {
            $stats['ok']++;
        }
    }
}
echo 'differences: ', count($diffs), "\n";
foreach (array_slice($diffs, 0, 3) as $d) {
    echo $d, "\n";
}
echo 'valid scenarios >= 100: ', $stats['valid'] >= 100 ? 'yes' : "NO ({$stats['valid']})", ', load errors >= 20: ', $stats['loadError'] >= 20 ? 'yes' : "NO ({$stats['loadError']})", "\n";
echo 'dictionary scenarios >= 40 and plain >= 40: ', $stats['dict'] >= 40 && $stats['plain'] >= 40 ? 'yes' : "NO ({$stats['dict']}, {$stats['plain']})", "\n";
echo 'successes >= 600: ', $stats['ok'] >= 600 ? 'yes' : "NO ({$stats['ok']})", ', maxGroups refusals >= 20: ', $stats['maxGroups'] >= 20 ? 'yes' : "NO ({$stats['maxGroups']})", ', empty results >= 30: ', $stats['empty'] >= 30 ? 'yes' : "NO ({$stats['empty']})", ', other query errors >= 5: ', $stats['err'] + $stats['overflow'] >= 5 ? 'yes' : "NO ({$stats['err']}, {$stats['overflow']})", "\n";
?>
--EXPECT--
differences: 0
valid scenarios >= 100: yes, load errors >= 20: yes
dictionary scenarios >= 40 and plain >= 40: yes
successes >= 600: yes, maxGroups refusals >= 20: yes, empty results >= 30: yes, other query errors >= 5: yes
