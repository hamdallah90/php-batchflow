--TEST--
batchflow: a dictionary group-by's arrays, sized by the whole dictionary, are checked against maxGroupBytes at the exact boundary
--EXTENSIONS--
batchflow
--FILE--
<?php
use BatchFlow\{LimitException, Table};

// SPEC 6.6, ruling 1: a group-by on a dictionary column uses arrays indexed by id, entries + 1 of them (id 0 is NULL):
// count (8 bytes) and order (4), plus sum (8) for groupSum, checked against maxGroupBytes before the scan, with no
// row prefix. At the minimum maxGroupBytes, 1,048,576: groupCount allows 87,381 ids (1,048,572 bytes) and refuses
// 87,382; groupSum allows 52,428 ids (1,048,560 bytes) and refuses 52,429.
function load(int $n): Table
{
    $p = tempnam(sys_get_temp_dir(), 'bf');
    $b = "k,v\n";
    for ($i = 1; $i <= $n; $i++) {
        $b .= "$i,1\n";
    }
    file_put_contents($p, $b);
    $t = Table::fromCsv($p, ['k' => 'int64', 'v' => 'int64'], ['dictionary' => ['k'], 'maxGroupBytes' => 1048576]);
    unlink($p);
    return $t;
}
function try_it(callable $f): string
{
    try {
        return 'groups ' . count($f());
    } catch (LimitException $e) {
        return $e->getMessage();
    }
}
foreach ([87380, 87381] as $n) {
    $t = load($n);
    echo "groupCount, $n entries: ", try_it(fn() => $t->query()->groupCount('k')), "\n";
}
foreach ([52427, 52428] as $n) {
    $t = load($n);
    echo "groupSum, $n entries: ", try_it(fn() => $t->query()->groupSum('k', 'v')), "\n";
    echo "groupCount, $n entries: ", try_it(fn() => $t->query()->groupCount('k')), "\n";
}
?>
--EXPECT--
groupCount, 87380 entries: groups 87380
groupCount, 87381 entries: group state exceeds maxGroupBytes
groupSum, 52427 entries: groups 52427
groupCount, 52427 entries: groups 52427
groupSum, 52428 entries: group state exceeds maxGroupBytes
groupCount, 52428 entries: groups 52428
