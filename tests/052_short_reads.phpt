--TEST--
batchflow: a wrapper returning at most 7 bytes per read gives identical results and offsets; a multi-MiB row in small reads is linear
--EXTENSIONS--
batchflow
--CONFLICTS--
all
--FILE--
<?php
require __DIR__ . '/bf_helper.inc';

use BatchFlow\Csv;

$S = ['a' => 'int64', 's' => 'string'];
$fixtures = [
    'plain' => "a,s\n1,x\n2,y\n3,z\n",
    'CRLF' => "a,s\r\n1,x\r\n2,y\r\n",
    'no final terminator' => "a,s\n1,x\n2,y",
    'BOM' => "\xEF\xBB\xBFa,s\n1,x\n",
    'quoted LF and quotes' => "a,s\n1,\"x\ny\"\"z\"\n2,\"\"\n3,w\n",
    'multibyte' => "a,s\n1,caf\u{e9}\n2,\u{1F600}\u{1F600}\n",
    'bad value, row 3' => "a,s\n1,x\n2,y\nbad,z\n",
    'bad UTF-8 in row 2' => "a,s\n1,x\n2,\xE2\x82\n",
    'bare quote' => "a,s\n1,x\n2,y\"y\n",
    'unterminated quote' => "a,s\n1,x\n2,\"yy\n",
    'bare CR at the end' => "a,s\n1,x\r",
    'too few fields' => "a,s\n1,x\n2\n",
    'header error' => "a,b\n1,x\n",
    'empty' => '',
    'int overflow' => "a,s\n9223372036854775807,x\n1,y\n",
];
$bad = 0;
foreach ($fixtures as $l => $csv) {
    foreach (['count' => [[], 'count', null], 'sum' => [[['eq', 's', 'zz']], 'sum', 'a'], 'summary' => [[], 'summary', 'a']] as $tl => [$preds, $term, $col]) {
        $want = bf_ref($csv, $S, [], $preds, $term, $col);
        foreach ([1, 2, 3, 7, 64] as $max) {
            $q = bf_apply(Csv::scanStream(bf_wrap_stream($csv, $max), $S), $preds);
            $got = bf_try(fn() => match ($term) { 'count' => $q->count(), 'sum' => $q->sum($col), 'summary' => $q->summary($col) });
            if ($got !== $want) {
                $bad++;
                echo "DIFF $l / $tl / $max: $got vs $want\n";
            }
        }
    }
}
echo 'fixtures x terminals x read sizes: ', count($fixtures) * 3 * 5, ", differences: $bad\n";
echo 'path source through the wrapper agrees: ';
$ok = true;
foreach ($fixtures as $csv) {
    $q = Csv::scan(bf_wrap_path(['segments' => [[$csv, 1]], 'max' => 7]), $S);
    $ok = $ok && bf_try(fn() => $q->count()) === bf_ref($csv, $S);
}
echo $ok ? "yes\n" : "NO\n";

echo "-- one row of several MiB in 7-byte reads\n";
$rowBytes = 3 * 1048576;
BfWrap::$cfg = ['segments' => [["a,s\n1,", 1], ['x', $rowBytes], ["\n2,y\n", 1]], 'max' => 7];
BfWrap::$opens = 0;
$t0 = microtime(true);
$r = bf_try(fn() => Csv::scan('bfwrap://in', $S, ['maxFieldBytes' => 8388608])->whereEq('s', 'y')->count());
$secs = microtime(true) - $t0;
$w = BfWrap::$last;
echo "result: $r\n";
echo 'all bytes read: ', $w->served === $rowBytes + strlen("a,s\n1,") + strlen("\n2,y\n") ? 'yes' : 'NO', "\n";
$minReads = intdiv($w->served, 7);
echo 'stream_read calls within 2% + 16 of the minimum: ', $w->reads >= $minReads && $w->reads <= $minReads * 1.02 + 16 ? 'yes' : "NO ({$w->reads} vs $minReads)", "\n";
echo 'finished within the time budget: ', $secs < 60 ? 'yes' : "NO ($secs s)", "\n";

echo "-- the same through a stream with whole-buffer reads\n";
BfWrap::$cfg = ['segments' => [["a,s\n1,", 1], ['x', $rowBytes], ["\n2,y\n", 1]]];
$t0 = microtime(true);
$r = bf_try(fn() => Csv::scan('bfwrap://in', $S, ['maxFieldBytes' => 8388608])->whereEq('s', 'y')->count());
echo "result: $r\n";
echo 'stream_read request sizes: ', BfWrap::$last->maxRequest <= $rowBytes + 65536 + 64 ? 'bounded' : 'UNBOUNDED', "\n";
?>
--EXPECT--
fixtures x terminals x read sizes: 225, differences: 0
path source through the wrapper agrees: yes
-- one row of several MiB in 7-byte reads
result: int(1)
all bytes read: yes
stream_read calls within 2% + 16 of the minimum: yes
finished within the time budget: yes
-- the same through a stream with whole-buffer reads
result: int(1)
stream_read request sizes: bounded
