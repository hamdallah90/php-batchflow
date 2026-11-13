--TEST--
batchflow: a close exception that already chains the read exception keeps its chain, and the read exception is released (no leak on 8.3)
--EXTENSIONS--
batchflow
--FILE--
<?php
require __DIR__ . '/bf_helper.inc';

use BatchFlow\Csv;

$S = ['a' => 'int64'];
$csv = "a\n1\n2\n";

/** Runs one throwing read whose stream_close throws what $close builds from the read exception. */
function one(string $label, callable $close, ?callable $wrapRead = null): void
{
    global $S, $csv;
    $inner = new BfBoom('inner');
    $read = $wrapRead ? $wrapRead($inner) : $inner;
    $weak = WeakReference::create($read);
    $p = bf_wrap_path(['segments' => [[$csv, 1]], 'mode' => 'throw_read', 'exc' => $read, 'on_close' => fn() => throw $close($read)]);
    unset($read);
    $t = null;
    try {
        Csv::scan($p, $S)->count();
    } catch (Throwable $e) {
        $t = $e;
    }
    unset($e);
    $chain = [];
    for ($x = $t; $x !== null && count($chain) < 5; $x = $x->getPrevious()) {
        $chain[] = get_class($x) . '(' . $x->getMessage() . ')';
    }
    BfWrap::$cfg = [];
    BfWrap::$last = null;
    unset($t, $x, $inner, $close, $wrapRead);
    echo str_pad($label, 34), implode(' <- ', $chain), ', closes=', BfWrap::$closes, ', read exception freed: ', $weak->get() === null ? 'yes' : 'NO', "\n";
}

echo "-- close throws new X('C', 0, \$read): the chain is the one close built, nothing doubled\n";
one('previous is the read exception', fn($read) => new RuntimeException('C', 0, $read));
echo "-- the read exception deeper in the close exception's chain\n";
one('two levels down', fn($read) => new RuntimeException('C', 0, new LogicException('mid', 0, $read)));
echo "-- close rethrows the read exception itself\n";
one('rethrown', fn($read) => $read);
echo "-- the read exception already chains the close exception: no cycle is formed\n";
$c = new RuntimeException('C');
one('read chains close', fn($read) => $c, fn($inner) => new BfBoom('read', 0, $c));
unset($c);
echo "-- an unrelated close exception still gets the read exception as previous\n";
one('unrelated', fn($read) => new RuntimeException('C'));

echo "-- 1,000 runs of the first case: no memory growth\n";
$run = function () use ($S, $csv) {
    $read = new BfBoom('read');
    $p = bf_wrap_path(['segments' => [[$csv, 1]], 'mode' => 'throw_read', 'exc' => $read,
        'on_close' => fn() => throw new RuntimeException('C', 0, $read)]);
    unset($read);
    try {
        Csv::scan($p, $S)->count();
    } catch (Throwable $e) {
    }
    BfWrap::$cfg = [];
    BfWrap::$last = null;
};
$run();
$before = memory_get_usage();
for ($i = 0; $i < 1000; $i++) {
    $run();
}
echo 'growth: ', memory_get_usage() - $before, " bytes\n";
?>
--EXPECT--
-- close throws new X('C', 0, $read): the chain is the one close built, nothing doubled
previous is the read exception    RuntimeException(C) <- BfBoom(inner), closes=1, read exception freed: yes
-- the read exception deeper in the close exception's chain
two levels down                   RuntimeException(C) <- LogicException(mid) <- BfBoom(inner), closes=1, read exception freed: yes
-- close rethrows the read exception itself
rethrown                          BfBoom(inner), closes=1, read exception freed: yes
-- the read exception already chains the close exception: no cycle is formed
read chains close                 RuntimeException(C), closes=1, read exception freed: yes
-- an unrelated close exception still gets the read exception as previous
unrelated                         RuntimeException(C) <- BfBoom(inner), closes=1, read exception freed: yes
-- 1,000 runs of the first case: no memory growth
growth: 0 bytes
