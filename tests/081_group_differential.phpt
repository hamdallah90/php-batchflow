--TEST--
batchflow: groupCount and groupSum agree with the independent reference on 150 seeded random inputs, valid and corrupted, with and without predicates
--EXTENSIONS--
batchflow
--FILE--
<?php
require __DIR__ . '/bf_helper.inc';

mt_srand(8128);

function rnd_str(): string
{
    static $pool = ['a', 'b', 'ab', 'abc', 'A', 'é', '日本', 'x y', 'a,b', "l\nf", 'q"t', '0', '00', '7', ' ', 'k1', 'k2', 'k3'];
    return $pool[mt_rand(0, count($pool) - 1)];
}

function quote(string $s, bool $force): string
{
    return ($force || strpbrk($s, ",\"\r\n") !== false || $s === '') ? '"' . str_replace('"', '""', $s) . '"' : $s;
}

/** One random scenario: [csv, schema, preds, key, sum, maxGroups]. */
function scenario(int $n): array
{
    $keyType = mt_rand(0, 1) ? 'string' : 'int64';
    $sumType = mt_rand(0, 1) ? 'int64' : 'float64';
    $S = ['id' => 'int64', 'k' => $keyType, 'v' => $sumType, 'f' => 'bool', 'x' => 'string'];
    $order = ['id', 'k', 'v', 'f', 'x'];
    if (mt_rand(0, 1)) {
        $order = array_reverse($order);
    }
    $eol = mt_rand(0, 1) ? "\n" : "\r\n";
    $csv = implode(',', $order) . $eol;
    $rows = mt_rand(0, 60);
    $corrupt = $n % 5 === 0 ? mt_rand(0, max(0, $rows - 1)) : -1;
    for ($r = 0; $r < $rows; $r++) {
        $cell = [
            'id' => (string)$r,
            'k' => mt_rand(0, 9) === 0 ? '' : ($keyType === 'string' ? quote(rnd_str(), mt_rand(0, 4) === 0) : (string)(mt_rand(-3, 3) * 1000003)),
            'v' => mt_rand(0, 9) === 0 ? '' : ($sumType === 'int64' ? (string)mt_rand(-1000, 1000) : sprintf('%.2f', mt_rand(-5000, 5000) / 100)),
            'f' => mt_rand(0, 9) === 0 ? '' : (mt_rand(0, 1) ? 'true' : 'false'),
            'x' => mt_rand(0, 4) === 0 ? '' : quote(rnd_str(), false),
        ];
        if ($r === $corrupt) {
            $cell[['v', 'k', 'f'][mt_rand(0, 2)]] = 'zz"';
        }
        $csv .= implode(',', array_map(fn($c) => $cell[$c], $order)) . $eol;
    }
    if (mt_rand(0, 3) === 0) {
        $csv = rtrim($csv, "\r\n");
    }
    $preds = [];
    for ($p = mt_rand(0, 2); $p > 0; $p--) {
        $preds[] = match (mt_rand(0, 3)) {
            0 => ['eq', 'f', mt_rand(0, 1) === 1],
            1 => ['gt', 'id', mt_rand(0, 40)],
            2 => ['eq', 'x', rnd_str()],
            3 => ['eq', 'k', mt_rand(0, 3) === 0 ? null : ($keyType === 'string' ? rnd_str() : mt_rand(-3, 3) * 1000003)],
        };
    }
    return [$csv, $S, $preds, 'k', 'v', [1000000, 1000000, 1, 2, 3, 5, 10][mt_rand(0, 6)]];
}

$diffs = [];
$ok = $err = $empty = $limit = 0;
for ($n = 1; $n <= 150; $n++) {
    [$csv, $S, $preds, $key, $sum, $max] = scenario($n);
    foreach (['groupCount', 'groupSum'] as $term) {
        // groupCount takes no sum column: given one, the reference would also check and sum it.
        $x = ['key' => $key, 'maxGroups' => $max] + ($term === 'groupSum' ? ['sum' => $sum] : []);
        $a = bf_gexec($csv, $S, [], $preds, $term, $x);
        $b = bf_gref($csv, $S, [], $preds, $term, $x);
        if ($a !== $b) {
            $diffs[] = "#$n $term ext=[$a] ref=[$b]";
        } elseif (str_contains($a, 'ResultTooLargeException')) {
            $limit++;
        } elseif (str_contains($a, 'Exception')) {
            $err++;
        } elseif ($a === '[]') {
            $empty++;
        } else {
            $ok++;
        }
    }
}
echo 'differences: ', count($diffs), "\n";
foreach (array_slice($diffs, 0, 3) as $d) {
    echo $d, "\n";
}
echo 'scenarios covered both ways: successes>=60: ', $ok >= 60 ? 'yes' : "NO ($ok)", ', errors>=10: ', $err >= 10 ? 'yes' : "NO ($err)", ', maxGroups refusals>=5: ', $limit >= 5 ? 'yes' : "NO ($limit)", ', empty results>=3: ', $empty >= 3 ? 'yes' : "NO ($empty)", "\n";
?>
--EXPECT--
differences: 0
scenarios covered both ways: successes>=60: yes, errors>=10: yes, maxGroups refusals>=5: yes, empty results>=3: yes
