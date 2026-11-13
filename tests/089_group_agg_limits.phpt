--TEST--
batchflow: groupAgg limits: maxGroups exactly and past the limit on composite keys with the all-NULL tuple counted, maxGroupBytes from the SPEC 6.3/6.4 capacities under memory_limit=-1, per-group overflow, seed independence of order, and a differential against the reference on random inputs
--EXTENSIONS--
batchflow
--INI--
memory_limit=-1
--FILE--
<?php
require __DIR__ . '/bf_helper.inc';
require __DIR__ . '/bf_reference_m2b.inc';

use BatchFlow\Csv;

function g(string $label, string $csv, array $S, $keys, array $aggs, int $max = 1000000, array $opts = []): void
{
    $x = ['keys' => $keys, 'aggs' => $aggs, 'maxGroups' => $max];
    $r = bf_gexec($csv, $S, $opts, [], 'groupAgg', $x);
    $w = bf_gref($csv, $S, $opts, [], 'groupAgg', $x);
    echo str_pad($label, 54), $r, $r === $w ? '' : "   [DIFFERS FROM REFERENCE: $w]", "\n";
}

$K = ['a' => 'string', 'b' => 'int64', 'v' => 'int64'];
echo "-- maxGroups exactly at and one past the limit; the all-NULL tuple counts\n";
$three = "a,b,v\nx,1,1\nx,2,1\n,,1\nx,1,1\n,,1\n";
g('3 tuples, maxGroups 3', $three, $K, ['a', 'b'], [['sum', 'v']], 3);
g('3 tuples, maxGroups 2: the all-NULL tuple is #3', $three, $K, ['a', 'b'], [['sum', 'v']], 2);
g('4th tuple, maxGroups 3', $three . "y,1,1\n", $K, ['a', 'b'], [], 3);
g('(NULL, 1) and (\'\', 1) are two', "a,b,v\n,1,1\n\"\",1,1\n", $K, ['a', 'b'], [], 1);
g('repeats past the limit are fine', "a,b,v\nx,1,1\ny,1,1\nx,1,1\ny,1,1\n", $K, ['a', 'b'], [], 2);
g('only matching rows count', "a,b,v\nx,1,1\ny,1,0\nz,1,1\n", $K, ['a', 'b'], [], 2);
g('the limit row comes after an earlier bad row', "a,b,v\nx,1,1\nx,zz,1\ny,1,1\n", $K, ['a', 'b'], [], 1);
g('rows after the limit row are not read', "a,b,v\nx,1,1\ny,1,1\n\"never closed", $K, ['a', 'b'], [], 1);
g('three keys, maxGroups 2', "a,b,v\nx,1,1\nx,1,2\nx,1,3\nx,2,4\n", $K, ['a', 'b', 'v'], [], 2);
echo "-- no partial result\n";
$r = 'unset';
try {
    $r = Csv::scan(bf_file($three), $K)->groupAgg(['a', 'b'], [], 2);
} catch (BatchFlow\ResultTooLargeException $e) {
    echo get_class($e), ' is a ', get_parent_class($e), "\n";
}
var_dump($r);
echo "-- with a predicate the limit applies to groups actually produced\n";
$p = Csv::scan(bf_file("a,b,v\nx,1,1\ny,1,0\nz,1,1\nw,1,0\n"), $K)->whereEq('v', 1);
echo bf_fmt_lists($p->groupAgg(['a', 'b'], [], 2)), "\n";

echo "-- per-group overflow: int64 at the offending row of that group and pair\n";
$O = ['k' => 'string', 'x' => 'int64', 'y' => 'int64'];
$ov = "k,x,y\na,9223372036854775807,1\nb,9223372036854775807,1\nb,0,5\na,1,0\n";
g('a overflows x on row 4', $ov, $O, 'k', [['sum', 'x'], ['sum', 'y']]);
g('b overflows y on row 3 first', "k,x,y\na,9223372036854775807,1\nb,1,9223372036854775807\nb,0,1\na,1,0\n", $O, 'k', [['sum', 'x'], ['sum', 'y']]);
g('pairs in list order on one row', "k,x,y\na,9223372036854775807,9223372036854775807\na,1,1\n", $O, 'k', [['sum', 'y'], ['avg', 'x']]);
g('no overflow across groups', "k,x,y\na,9223372036854775807,1\nb,9223372036854775807,1\n", $O, 'k', [['sum', 'x']]);
g('negative overflow', "k,x,y\na,-9223372036854775808,1\na,-1,1\n", $O, 'k', [['avg', 'x']]);
echo "-- non-finite float64 sums after the scan: groups in result order, pairs in list order\n";
$F = ['k' => 'string', 'x' => 'float64', 'y' => 'float64'];
$inf = "k,x,y\na,1,1e308\nb,1e308,1\nb,1e308,1\na,1,1e308\n";
g('a (y) and b (x) are not finite: a comes first', $inf, $F, 'k', [['sum', 'x'], ['sum', 'y']]);
g('b first in result order', "k,x,y\nb,1e308,1\na,1,1e308\nb,1e308,1\na,1,1e308\n", $F, 'k', [['sum', 'x'], ['sum', 'y']]);
g('within a group, pairs in list order', "k,x,y\na,1e308,1e308\na,1e308,1e308\n", $F, 'k', [['avg', 'y'], ['sum', 'x']]);
g('min and max are never checked', "k,x,y\na,1e308,1\na,1e308,1\n", $F, 'k', [['max', 'x'], ['min', 'x'], ['count', 'x']]);
g('an int64 overflow wins over a later non-finite sum', "k,x,n\na,1e308,9223372036854775807\na,1e308,1\n", ['k' => 'string', 'x' => 'float64', 'n' => 'int64'], 'k', [['sum', 'x'], ['sum', 'n']]);

echo "-- maxGroupBytes: the SPEC 6.4 capacities over the 6.3 groupAgg arrays\n";
const LIM = 1048576;
/**
 * The row of the first group whose growth exceeds $lim: slots 8 bytes from 1024 doubling at load 3/4 over every
 * group, arrays 28 + 16 * M bytes per group from 256 doubling (clamped to maxGroups), the tuple arena from
 * 65,536 doubling until the tuple fits; 0 when all $n groups fit.
 */
function first_refused(int $n, int $tupleBytes, int $m, int $lim, int $maxGroups = 1000000): int
{
    $slots = $cap = $arena = $used = 0;
    for ($g = 1; $g <= $n; $g++) {
        $have = $g - 1;
        if ($slots === 0 || ($have + 1) * 4 > $slots * 3) {
            $slots = $slots === 0 ? 1024 : $slots * 2;
            if ($slots * 8 + $cap * (28 + 16 * $m) + $arena > $lim) {
                return $g;
            }
        }
        if ($have === $cap) {
            $cap = min($cap === 0 ? 256 : $cap * 2, $maxGroups);
            if ($slots * 8 + $cap * (28 + 16 * $m) + $arena > $lim) {
                return $g;
            }
        }
        if ($used + $tupleBytes > $arena) {
            $arena = $arena === 0 ? 65536 : $arena;
            while ($arena < $used + $tupleBytes) {
                $arena *= 2;
            }
            if ($slots * 8 + $cap * (28 + 16 * $m) + $arena > $lim) {
                return $g;
            }
        }
        $used += $tupleBytes;
    }
    return 0;
}

/** CSV of $n rows "i,i*7,1": two int64 keys, so every tuple is 2 * 9 = 18 bytes. */
function pairs(int $n): string
{
    $s = "a,b,v\n";
    for ($i = 1; $i <= $n; $i++) {
        $s .= $i . ',' . ($i * 7) . ",1\n";
    }
    return $s;
}

function row_of(string $r): int
{
    return preg_match('/row (\d+) \(/', $r, $m) ? (int)$m[1] : 0;
}

$II = ['a' => 'int64', 'b' => 'int64', 'v' => 'int64'];
foreach ([[0, LIM], [1, LIM], [3, LIM], [16, LIM], [0, 4 * LIM], [2, 3 * LIM]] as [$m, $lim]) {
    $aggs = array_fill(0, $m, ['sum', 'v']);
    $want = first_refused(200000, 18, $m, $lim);
    $p = bf_file(pairs($want + 10));
    $r = bf_try(fn() => Csv::scan($p, $II, ['maxGroupBytes' => $lim])->groupAgg(['a', 'b'], $aggs));
    $fits = bf_try(fn() => count(Csv::scan($p, $II, ['maxGroupBytes' => $lim])->whereLt('a', $want)->groupAgg(['a', 'b'], $aggs)));
    echo "M=$m, maxGroupBytes=$lim: model refuses group ", $want, '; extension: ', row_of($r) === $want ? 'same row' : 'row ' . row_of($r),
        ', ', str_contains($r, 'group state exceeds maxGroupBytes') ? 'LimitException group state exceeds maxGroupBytes' : $r,
        '; the groups before it fit: ', $fits === 'int(' . ($want - 1) . ')' ? 'yes' : $fits, "\n";
}
echo "-- string keys: the arena holds the encoded tuple (tag, 4-byte length, bytes)\n";
// One string key of 500,000 bytes is a 500,005-byte tuple: the arena grows 65,536 -> 524,288 at the first
// group; slots 8,192 + arrays 256 * 28 = 7,168 + 524,288 fits 1 MiB. A second such key needs 1,000,010
// bytes, so the arena doubles to 1,048,576 and the total is refused.
$K1 = str_repeat('a', 500000);
$K2 = str_repeat('b', 500000);
echo bf_try(fn() => count(bf_q("k\n$K1\n$K1\n", ['k' => 'string'], ['maxGroupBytes' => LIM])->groupAgg('k', []))), "\n";
echo bf_try(fn() => bf_q("k\n$K1\n$K2\n", ['k' => 'string'], ['maxGroupBytes' => LIM])->groupAgg('k', [])), "\n";
echo bf_try(fn() => count(bf_q("k\n$K1\n$K2\n", ['k' => 'string'], ['maxGroupBytes' => 4 * LIM])->groupAgg('k', []))), "\n";
// A key of 1,048,571 bytes is a tuple of exactly 1,048,576: the arena is exactly that, and the state is
// 8,192 + 7,168 + 1,048,576 = 1,063,936 bytes.
$KX = str_repeat('x', 1048571);
echo bf_try(fn() => count(bf_q("k\n$KX\n", ['k' => 'string'], ['maxGroupBytes' => 1063936])->groupAgg('k', []))), "\n";
echo bf_try(fn() => bf_q("k\n$KX\n", ['k' => 'string'], ['maxGroupBytes' => 1063935])->groupAgg('k', [])), "\n";
unset($KX);
echo "-- the arrays clamp to maxGroups: 3,000 groups of 16 pairs fit 1 MiB only with the clamp\n";
// Unclamped, group 2,049 doubles the arrays to 4,096 entries of 284 bytes (1,163,264 bytes); clamped to
// maxGroups 3,000 they hold 3,000 entries: 32,768 slot bytes + 852,000 + a 65,536-byte arena = 950,304.
$unclamped = first_refused(3000, 18, 16, LIM);
$clamped = first_refused(3000, 18, 16, LIM, 3000);
echo "model: unclamped refuses group $unclamped, clamped fits all: ", $clamped === 0 ? 'yes' : "NO ($clamped)", "\n";
$p = bf_file(pairs(3000));
$aggs16 = array_fill(0, 16, ['sum', 'v']);
echo 'maxGroups 3000: ', bf_try(fn() => count(Csv::scan($p, $II, ['maxGroupBytes' => LIM])->groupAgg(['a', 'b'], $aggs16, 3000))),
    '; default maxGroups refused at row ', row_of(bf_try(fn() => Csv::scan($p, $II, ['maxGroupBytes' => LIM])->groupAgg(['a', 'b'], $aggs16))), "\n";
echo "-- memory_limit is -1 here, so maxGroupBytes is the bound\n";
echo ini_get('memory_limit'), "\n";

echo "-- seed independence: repeated runs and every simd value give identical output\n";
mt_srand(89);
$s = "k1,k2,v\n";
for ($i = 0; $i < 5000; $i++) {
    $s .= 'k' . mt_rand(0, 300) . ',' . (mt_rand(0, 20) === 0 ? '' : mt_rand(-50, 50)) . ',' . mt_rand(-9, 9) . "\n";
}
$S2 = ['k1' => 'string', 'k2' => 'int64', 'v' => 'int64'];
$p = bf_file($s);
$digests = [];
foreach (array_merge(['auto'], bf_simd_available()) as $simd) {
    for ($run = 0; $run < 10; $run++) {
        $digests[md5(serialize(Csv::scan($p, $S2, ['simd' => $simd])->groupAgg(['k1', 'k2'], [['sum', 'v'], ['min', 'v']])))] = 1;
    }
}
$groups = count(Csv::scan($p, $S2)->groupAgg(['k1', 'k2'], []));
echo 'distinct outputs: ', count($digests), ', groups: ', $groups > 1000 ? 'over 1000' : $groups, "\n";
echo bf_gcmp($s, $S2, [], [], 'groupAgg', ['keys' => ['k1', 'k2'], 'aggs' => [['sum', 'v'], ['min', 'v']]]), "\n";

echo "-- differential against the reference on random inputs\n";
mt_srand(8989);
const POOL = ['a', 'b', 'ab', 'A', 'é', '日本', 'x y', 'a,b', 'p;q', "t\tu", 'v|w', "l\nf", 'q"t', '0', '00', '7', ' ', '', 'k1'];

function rs(): string
{
    return POOL[mt_rand(0, count(POOL) - 1)];
}

function cell(?string $v, string $d, bool $quote): string
{
    if ($v === null) {
        return '';
    }
    return ($quote || $v === '' || strpbrk($v, "\"\r\n,;\t|") !== false) ? '"' . str_replace('"', '""', $v) . '"' : $v;
}

/** One random scenario: [csv, schema, opts, preds, term, x]. */
function scenario(int $n): array
{
    $d = [',', ';', "\t", '|'][mt_rand(0, 3)];
    $vt = mt_rand(0, 1) ? 'int64' : 'float64';
    $S = ['id' => 'int64', 'k1' => 'string', 'k2' => 'int64', 'k3' => 'string', 'v' => $vt, 'f' => 'bool', 'w' => 'float64'];
    $order = array_keys($S);
    shuffle($order);
    $eol = mt_rand(0, 1) ? "\n" : "\r\n";
    $csv = implode($d, $order) . $eol;
    $rows = mt_rand(0, 50);
    $corrupt = $n % 7 === 0 ? mt_rand(0, max(0, $rows - 1)) : -1;
    for ($r = 0; $r < $rows; $r++) {
        $null = fn() => mt_rand(0, 7) === 0;
        $c = [
            'id' => (string)$r,
            'k1' => $null() ? null : rs(),
            'k2' => $null() ? null : (string)(mt_rand(-2, 2) * 1000003),
            'k3' => $null() ? null : ['x', 'y', ''][mt_rand(0, 2)],
            'v' => $null() ? null : ($vt === 'int64' ? (string)(mt_rand(0, 40) === 0 ? 9223372036854775000 : mt_rand(-1000, 1000)) : (mt_rand(0, 60) === 0 ? '1e308' : sprintf('%.2f', mt_rand(-5000, 5000) / 100))),
            'f' => $null() ? null : (mt_rand(0, 1) ? 'true' : 'false'),
            'w' => $null() ? null : (mt_rand(0, 9) === 0 ? '-0.0' : sprintf('%.1f', mt_rand(-50, 50) / 10)),
        ];
        $line = [];
        foreach ($order as $col) {
            $line[] = cell($c[$col], $d, mt_rand(0, 9) === 0);
        }
        if ($r === $corrupt) {
            $line[mt_rand(0, count($line) - 1)] = ['zz"', '"open', '1.5.5', 'nan'][mt_rand(0, 3)];
        }
        $csv .= implode($d, $line) . $eol;
    }
    if (mt_rand(0, 3) === 0) {
        $csv = rtrim($csv, "\r\n");
    }
    $preds = [];
    for ($p = mt_rand(0, 3); $p > 0; $p--) {
        $preds[] = match (mt_rand(0, 9)) {
            0 => ['eq', 'f', mt_rand(0, 1) === 1],
            1 => ['ne', 'k1', mt_rand(0, 3) === 0 ? null : rs()],
            2 => ['gt', 'id', mt_rand(0, 40)],
            3 => ['gte', 'w', mt_rand(-50, 50) / 10],
            4 => ['lt', 'k2', mt_rand(-2, 2) * 1000003],
            5 => ['lte', 'v', $vt === 'int64' ? mt_rand(-500, 500) : mt_rand(-500, 500) / 10],
            6 => ['in', 'k1', array_map(fn() => mt_rand(0, 4) === 0 ? null : rs(), range(0, mt_rand(0, 4)))],
            7 => ['in', 'k2', array_map(fn() => mt_rand(0, 4) === 0 ? null : mt_rand(-2, 2) * 1000003, range(0, mt_rand(0, 3)))],
            8 => ['in', 'w', array_map(fn() => mt_rand(0, 5) === 0 ? -0.0 : mt_rand(-50, 50) / 10, range(0, mt_rand(0, 6)))],
            9 => ['ne', 'f', mt_rand(0, 2) === 0 ? null : mt_rand(0, 1) === 1],
        };
    }
    $pairs = [];
    for ($a = mt_rand(0, 5); $a > 0; $a--) {
        $op = ['count', 'sum', 'min', 'max', 'avg'][mt_rand(0, 4)];
        $col = $op === 'count' ? ['v', 'w', 'f', 'k1', 'id'][mt_rand(0, 4)] : ['v', 'w', 'id', 'k2'][mt_rand(0, 3)];
        $pairs[] = [$op, $col];
    }
    $max = [1000000, 1000000, 1000000, 1, 2, 3, 5, 10][mt_rand(0, 7)];
    $t = mt_rand(0, 9);
    if ($t < 6) {
        $keys = array_slice(['k1', 'k2', 'k3', 'id', 'k1'], 0, 4);
        shuffle($keys);
        $keys = array_slice(array_values(array_unique($keys)), 0, mt_rand(1, 4));
        return [$csv, $S, ['delimiter' => $d], $preds, 'groupAgg', ['keys' => mt_rand(0, 4) === 0 ? $keys[0] : $keys, 'aggs' => $pairs, 'maxGroups' => $max]];
    }
    if ($t < 8) {
        return [$csv, $S, ['delimiter' => $d], $preds, 'aggregate', ['aggs' => $pairs ?: [['count', 'id']]]];
    }
    return [$csv, $S, ['delimiter' => $d], $preds, ['min', 'max', 'avg'][mt_rand(0, 2)], ['col' => ['v', 'w', 'id'][mt_rand(0, 2)]]];
}

$diffs = [];
$kinds = ['ok' => 0, 'error' => 0, 'empty' => 0, 'limit' => 0, 'overflow' => 0];
for ($n = 1; $n <= 150; $n++) {
    [$csv, $S, $opts, $preds, $term, $x] = scenario($n);
    $a = bf_gexec($csv, $S, $opts, $preds, $term, $x);
    $b = bf_gref($csv, $S, $opts, $preds, $term, $x);
    if ($a !== $b) {
        $diffs[] = "#$n $term ext=[$a] ref=[$b]";
    } elseif (str_contains($a, 'ResultTooLargeException')) {
        $kinds['limit']++;
    } elseif (str_contains($a, 'SumOverflowException')) {
        $kinds['overflow']++;
    } elseif (str_contains($a, 'Exception')) {
        $kinds['error']++;
    } elseif ($a === '[]' || $a === 'NULL') {
        $kinds['empty']++;
    } else {
        $kinds['ok']++;
    }
}
echo 'scenarios: 150, differences: ', count($diffs), "\n";
foreach (array_slice($diffs, 0, 5) as $d) {
    echo "  $d\n";
}
foreach ($kinds as $k => $v) {
    echo "$k: ", $v > 0 ? 'some' : 'NONE', "\n";
}
?>
--EXPECT--
-- maxGroups exactly at and one past the limit; the all-NULL tuple counts
3 tuples, maxGroups 3                                 [s"x",int(1),int(2),int(2)]; [s"x",int(2),int(1),int(1)]; [NULL,NULL,int(2),int(2)]
3 tuples, maxGroups 2: the all-NULL tuple is #3       ResultTooLargeException: row 3 (line 4, offset 18): result exceeds maxGroups (2)
4th tuple, maxGroups 3                                ResultTooLargeException: row 6 (line 7, offset 32): result exceeds maxGroups (3)
(NULL, 1) and ('', 1) are two                         ResultTooLargeException: row 2 (line 3, offset 11): result exceeds maxGroups (1)
repeats past the limit are fine                       [s"x",int(1),int(2)]; [s"y",int(1),int(2)]
only matching rows count                              ResultTooLargeException: row 3 (line 4, offset 18): result exceeds maxGroups (2)
the limit row comes after an earlier bad row          CsvException: row 2 (line 3, offset 12): column "b": expected int64, got malformed value
rows after the limit row are not read                 ResultTooLargeException: row 2 (line 3, offset 12): result exceeds maxGroups (1)
three keys, maxGroups 2                               ResultTooLargeException: row 3 (line 4, offset 18): result exceeds maxGroups (2)
-- no partial result
BatchFlow\ResultTooLargeException is a BatchFlow\LimitException
string(5) "unset"
-- with a predicate the limit applies to groups actually produced
[s"x",int(1),int(1)]; [s"z",int(1),int(1)]
-- per-group overflow: int64 at the offending row of that group and pair
a overflows x on row 4                                SumOverflowException: row 4 (line 5, offset 60): int64 sum overflow in column "x"
b overflows y on row 3 first                          SumOverflowException: row 3 (line 4, offset 54): int64 sum overflow in column "y"
pairs in list order on one row                        SumOverflowException: row 2 (line 3, offset 48): int64 sum overflow in column "y"
no overflow across groups                             [s"a",int(1),int(9223372036854775807)]; [s"b",int(1),int(9223372036854775807)]
negative overflow                                     SumOverflowException: row 2 (line 3, offset 31): int64 sum overflow in column "x"
-- non-finite float64 sums after the scan: groups in result order, pairs in list order
a (y) and b (x) are not finite: a comes first         SumOverflowException: float64 sum of column "y" is not finite
b first in result order                               SumOverflowException: float64 sum of column "x" is not finite
within a group, pairs in list order                   SumOverflowException: float64 sum of column "y" is not finite
min and max are never checked                         [s"a",int(2),float(1.0E+308),float(1.0E+308),int(2)]
an int64 overflow wins over a later non-finite sum    SumOverflowException: row 2 (line 3, offset 34): int64 sum overflow in column "n"
-- maxGroupBytes: the SPEC 6.4 capacities over the 6.3 groupAgg arrays
M=0, maxGroupBytes=1048576: model refuses group 14564; extension: same row, LimitException group state exceeds maxGroupBytes; the groups before it fit: yes
M=1, maxGroupBytes=1048576: model refuses group 8193; extension: same row, LimitException group state exceeds maxGroupBytes; the groups before it fit: yes
M=3, maxGroupBytes=1048576: model refuses group 8193; extension: same row, LimitException group state exceeds maxGroupBytes; the groups before it fit: yes
M=16, maxGroupBytes=1048576: model refuses group 2049; extension: same row, LimitException group state exceeds maxGroupBytes; the groups before it fit: yes
M=0, maxGroupBytes=4194304: model refuses group 58255; extension: same row, LimitException group state exceeds maxGroupBytes; the groups before it fit: yes
M=2, maxGroupBytes=3145728: model refuses group 29128; extension: same row, LimitException group state exceeds maxGroupBytes; the groups before it fit: yes
-- string keys: the arena holds the encoded tuple (tag, 4-byte length, bytes)
int(1)
LimitException: row 2 (line 3, offset 500003): group state exceeds maxGroupBytes
int(2)
int(1)
LimitException: row 1 (line 2, offset 2): group state exceeds maxGroupBytes
-- the arrays clamp to maxGroups: 3,000 groups of 16 pairs fit 1 MiB only with the clamp
model: unclamped refuses group 2049, clamped fits all: yes
maxGroups 3000: int(3000); default maxGroups refused at row 2049
-- memory_limit is -1 here, so maxGroupBytes is the bound
-1
-- seed independence: repeated runs and every simd value give identical output
distinct outputs: 1, groups: over 1000
same
-- differential against the reference on random inputs
scenarios: 150, differences: 0
ok: some
error: some
empty: some
limit: some
overflow: some
