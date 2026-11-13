--TEST--
batchflow: a single-key group-by probes its matched rows in batches; the first error is still the one of the earliest row, whichever row of a batch fails, and results and group order are those of one row at a time
--EXTENSIONS--
batchflow
--FILE--
<?php
require __DIR__ . '/bf_helper.inc';

use BatchFlow\Csv;

// One group per row until maxGroups, then the row that would be the next group; a bad row somewhere.
// Expected: the error of the smaller row; at the same row the decode error comes first, because a row is decoded before it is counted.
$S = ['k' => 'string', 'v' => 'int64', 'x' => 'int64'];
function build(int $rows, int $bad, string $kind): string
{
    $csv = "k,v,x\n";
    for ($i = 0; $i < $rows; $i++) {
        if ($i === $bad) {
            $csv .= match ($kind) {
                'decode' => "g$i,notanint,2\n",
                'quote' => "g$i,1,\"x\"y\n",
                'short' => "g$i,1\n",
                'long' => "g$i,1,2,3\n",
            };
        } else {
            $csv .= "g$i,1,2\n";
        }
    }
    return $csv;
}

$wrong = [];
$n = 0;
foreach ([5, 20, 37] as $rows) {
    foreach (['decode', 'quote', 'short', 'long'] as $kind) {
        foreach ([1, 2, 5, 15, 16, 17, 18] as $limit) {
            for ($bad = 0; $bad < $rows; $bad++) {
                $csv = build($rows, $bad, $kind);
                $n++;
                try {
                    bf_q($csv, $S)->groupSum('k', 'v', maxGroups: $limit);
                    $got = 'no error';
                } catch (Throwable $e) {
                    preg_match('/^row (\d+)/', $e->getMessage(), $m);
                    $got = get_class($e) . ' row ' . ($m[1] ?? '?');
                }
                // the group of the row at index $limit is the first past the limit: row number $limit + 1
                $limitRow = $limit < $rows ? $limit + 1 : null;
                if ($bad <= $limit || $limitRow === null) {
                    $want = 'BatchFlow\CsvException row ' . ($bad + 1);
                } else {
                    $want = 'BatchFlow\ResultTooLargeException row ' . $limitRow;
                }
                if ($got !== $want) {
                    $wrong[] = "rows=$rows kind=$kind limit=$limit bad=$bad: got $got want $want";
                }
            }
        }
    }
}
echo "scenarios: $n, wrong: ", count($wrong), "\n";
foreach (array_slice($wrong, 0, 8) as $w) {
    echo "  $w\n";
}

// A key longer than a queue entry flushes the queue behind it, and the run goes on counting rows from where it was.
$csv = "k,v\n";
for ($i = 0; $i < 30; $i++) {
    $csv .= ($i === 5 || $i === 9 ? str_repeat('L', 60) . $i : "g$i") . ",1\n";
}
foreach ([['maxInputRows', 20, 'row 21 (line 22,'], ['maxInputRows', 8, 'row 9 (line 10,']] as [$opt, $val, $want]) {
    try {
        bf_q($csv, ['k' => 'string', 'v' => 'int64'], [$opt => $val])->groupSum('k', 'v');
        echo "$opt=$val: no error\n";
    } catch (Throwable $e) {
        echo "$opt=$val: ", str_starts_with($e->getMessage(), $want) ? 'the error names ' . $want : $e->getMessage(), "\n";
    }
}

// An int64 sum overflow at one row, with a framing error just after it, and a maxGroups refusal just after it.
$MAX = PHP_INT_MAX;
$wrong = [];
$checked = 0;
for ($at = 1; $at < 36; $at++) {
    $csv = "k,v\n";
    for ($i = 0; $i < 40; $i++) {
        $csv .= 'k' . ($i % 3) . ',' . ($i === $at ? $MAX : ($i < $at ? 0 : 1)) . "\n";
    }
    $csv = str_replace("k0,0\n", "k0,$MAX\n", $csv);   // every k0 row before $at carries MAX, so the first k0 row after it overflows
    $first = null;
    $lines = explode("\n", rtrim($csv));
    $acc = [];
    foreach (array_slice($lines, 1) as $i => $l) {
        [$k, $v] = explode(',', $l);
        $acc[$k] = ($acc[$k] ?? 0);
        if ($v > 0 && $acc[$k] > PHP_INT_MAX - $v) {
            $first = $i + 1;
            break;
        }
        $acc[$k] += $v;
    }
    $bad = $first === null ? 'none' : (string)$first;
    // a bad row two rows after the overflow row must not hide it
    if ($first !== null) {
        $rows = array_slice($lines, 1);
        $rows[$first + 1] = 'k1,"x"y';
        $csv2 = "k,v\n" . implode("\n", $rows) . "\n";
        try {
            bf_q($csv2, ['k' => 'string', 'v' => 'int64'])->groupSum('k', 'v');
            $got = 'none';
        } catch (Throwable $e) {
            preg_match('/^row (\d+)/', $e->getMessage(), $m);
            $got = get_class($e) . ' ' . ($m[1] ?? '?');
        }
        $checked++;
        $want = 'BatchFlow\SumOverflowException ' . $first;
        if ($got !== $want) {
            $wrong[] = "at=$at: got $got want $want";
        }
    }
}
echo "overflow scenarios: ", $checked > 20 ? 'many' : "only $checked", ", wrong: ", count($wrong), "\n";
foreach (array_slice($wrong, 0, 5) as $w) {
    echo "  $w\n";
}

// Results and first-seen order with keys of every kind: NULL, empty, long (over the batch's 48-byte entry), repeated, and more than one batch.
mt_srand(33);
$keys = ['', 'a', 'b', str_repeat('L', 47), str_repeat('M', 48), str_repeat('N', 49), str_repeat('O', 300), 'é', "q\"t", null];
$csv = "k,v\n";
$exp = [];
for ($i = 0; $i < 500; $i++) {
    $k = $keys[mt_rand(0, count($keys) - 1)];
    $v = mt_rand(0, 4) === 0 ? '' : (string)mt_rand(-5, 9);
    $cell = $k === null ? '' : ($k === '' ? '""' : '"' . str_replace('"', '""', $k) . '"');
    $csv .= $cell . ',' . $v . "\n";
    $ek = $k === null ? "\0null" : $k;
    $exp[$ek] ??= [$k, 0, 0];
    $exp[$ek][1]++;
    if ($v !== '') {
        $exp[$ek][2] += (int)$v;
    }
}
$want = array_map(fn ($e) => $e, array_values($exp));
$got = bf_q($csv, ['k' => 'string', 'v' => 'int64'])->groupSum('k', 'v');
echo 'groupSum equals the PHP computation, groups ', count($got), ': ', $got === $want ? 'yes' : 'NO', "\n";
$gc = bf_q($csv, ['k' => 'string', 'v' => 'int64'])->groupCount('k');
echo 'groupCount equals: ', $gc === array_map(fn ($e) => [$e[0], $e[1]], $want) ? 'yes' : 'NO', "\n";
?>
--EXPECT--
scenarios: 1736, wrong: 0
maxInputRows=20: the error names row 21 (line 22,
maxInputRows=8: the error names row 9 (line 10,
overflow scenarios: many, wrong: 0
groupSum equals the PHP computation, groups 10: yes
groupCount equals: yes
