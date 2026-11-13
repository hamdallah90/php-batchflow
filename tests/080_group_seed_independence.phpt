--TEST--
batchflow: group results are a deterministic function of the input: identical across repeated runs (each with a fresh hash key) and across every available simd choice
--EXTENSIONS--
batchflow
--FILE--
<?php
require __DIR__ . '/bf_helper.inc';

use BatchFlow\Csv;

mt_srand(20261109);
// 3,000 rows over about 700 string keys and 300 int keys, including NULL, in a fixed pseudo-random order.
$sk = [];
for ($i = 0; $i < 700; $i++) {
    $sk[] = substr(md5("key$i"), 0, 3 + $i % 9);
}
$rows = "s,i,v\n";
for ($r = 0; $r < 3000; $r++) {
    $s = mt_rand(0, 19) === 0 ? null : $sk[mt_rand(0, 699)];
    $i = mt_rand(0, 19) === 0 ? null : mt_rand(-150, 149) * 7919;
    $v = mt_rand(-50, 50);
    $rows .= ($s ?? '') . ',' . ($i ?? '') . ",$v\n";
}
$S = ['s' => 'string', 'i' => 'int64', 'v' => 'int64'];
$path = bf_file($rows);

echo "-- first-seen order from the independent reference\n";
$refS = bf_gref($rows, $S, [], [], 'groupSum', ['key' => 's', 'sum' => 'v']);
$refI = bf_gref($rows, $S, [], [], 'groupSum', ['key' => 'i', 'sum' => 'v']);
echo 'string groups: ', substr_count($refS, '['), ', int groups: ', substr_count($refI, '['), "\n";

echo "-- 25 repeated runs of one Query, each draws its own key\n";
$q = Csv::scan($path, $S);
$bad = 0;
for ($n = 0; $n < 25; $n++) {
    $bad += bf_fmt_groups($q->groupSum('s', 'v')) === $refS ? 0 : 1;
    $bad += bf_fmt_groups($q->groupSum('i', 'v')) === $refI ? 0 : 1;
    $bad += bf_fmt_groups($q->groupCount('s')) === bf_gref($rows, $S, [], [], 'groupCount', ['key' => 's']) ? 0 : 1;
}
echo "runs that differ from first-seen order: $bad\n";

echo "-- 25 fresh Query objects\n";
$bad = 0;
for ($n = 0; $n < 25; $n++) {
    $bad += bf_fmt_groups(Csv::scan($path, $S)->groupSum('s', 'v')) === $refS ? 0 : 1;
}
echo "differ: $bad\n";

echo "-- every simd choice available here gives the same bytes\n";
$bad = [];
foreach (array_merge(['auto'], bf_simd_available()) as $simd) {
    $o = ['simd' => $simd];
    if (bf_gexec($rows, $S, $o, [], 'groupSum', ['key' => 's', 'sum' => 'v']) !== $refS
        || bf_gexec($rows, $S, $o, [], 'groupSum', ['key' => 'i', 'sum' => 'v']) !== $refI) {
        $bad[] = $simd;
    }
}
echo 'simd choices that differ: ', $bad ? implode(',', $bad) : 'none', "\n";

echo "-- identical serialized results, run to run\n";
$h = [];
for ($n = 0; $n < 5; $n++) {
    $h[md5(serialize(Csv::scan($path, $S)->groupSum('s', 'v')))] = true;
}
echo 'distinct digests over 5 runs: ', count($h), "\n";

echo "-- predicates do not change the order contract\n";
$P = [['gt', 'v', 0]];
$a = bf_gexec($rows, $S, [], $P, 'groupCount', ['key' => 's']);
$b = bf_gref($rows, $S, [], $P, 'groupCount', ['key' => 's']);
echo $a === $b ? "same as reference\n" : "DIFFERS\n";
?>
--EXPECT--
-- first-seen order from the independent reference
string groups: 693, int groups: 301
-- 25 repeated runs of one Query, each draws its own key
runs that differ from first-seen order: 0
-- 25 fresh Query objects
differ: 0
-- every simd choice available here gives the same bytes
simd choices that differ: none
-- identical serialized results, run to run
distinct digests over 5 runs: 1
-- predicates do not change the order contract
same as reference
