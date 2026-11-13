--TEST--
batchflow: after a throwing read or eof a path stream's stream_close runs once; the original exception survives a catch inside close and is the previous of a close exception
--EXTENSIONS--
batchflow
--FILE--
<?php
require __DIR__ . '/bf_helper.inc';

use BatchFlow\Csv;

$S = ['a' => 'int64'];
$csv = "a\n1\n2\n";

function caught(callable $fn): ?Throwable
{
    try {
        $fn();
    } catch (Throwable $e) {
        return $e;
    }
    return null;
}

echo "-- read or eof threw, close completes: the original exception, unchanged\n";
foreach (['throw_read', 'throw_eof'] as $mode) {
    $boom = new BfBoom($mode);
    $p = bf_wrap_path(['segments' => [[$csv, 1]], 'mode' => $mode, 'exc' => $boom]);
    $t = caught(fn() => Csv::scan($p, $S)->count());
    echo str_pad($mode, 12), $t === $boom ? 'same object' : 'NOT THE SAME', ', previous ', $t && $t->getPrevious() === null ? 'null' : 'SET', ', closes=', BfWrap::$closes, "\n";
}

echo "-- close throws and catches internally, or catches and then throws, for every terminal\n";
$terminals = [
    'count' => fn($q) => $q->count(),
    'sum' => fn($q) => $q->sum('a'),
    'summary' => fn($q) => $q->summary('a'),
];
foreach (['throw_read', 'throw_eof'] as $mode) {
    foreach ($terminals as $tname => $term) {
        $orig = new BfBoom($mode);
        $p = bf_wrap_path(['segments' => [[$csv, 1]], 'mode' => $mode, 'exc' => $orig, 'on_close' => function () {
            try {
                throw new Exception('caught inside close');
            } catch (Exception $e) {
            }
        }]);
        $t = caught(fn() => $term(Csv::scan($p, $S)));
        echo str_pad("$mode $tname", 20), 'inner catch: ', $t === $orig ? 'original' : 'LOST (' . ($t ? get_class($t) : 'no exception') . ')',
            ', previous ', $t && $t->getPrevious() === null ? 'null' : 'SET', ', closes=', BfWrap::$closes, "\n";

        $orig = new BfBoom($mode);
        $close = new BfBoom('close');
        $p = bf_wrap_path(['segments' => [[$csv, 1]], 'mode' => $mode, 'exc' => $orig, 'on_close' => function () use ($close) {
            try {
                throw new Exception('caught inside close');
            } catch (Exception $e) {
            }
            throw $close;
        }]);
        $t = caught(fn() => $term(Csv::scan($p, $S)));
        echo str_pad("$mode $tname", 20), 'catch, then throw: ', $t === $close ? 'close exception' : 'OTHER', ', its previous ', $t && $t->getPrevious() === $orig ? 'the original' : 'NOT THE ORIGINAL',
            ', original previous ', $orig->getPrevious() === null ? 'null' : 'SET', ', closes=', BfWrap::$closes, "\n";
    }
}

echo "-- read and close both threw: the close exception surfaces with the read exception as previous\n";
$read = new BfBoom('read');
$close = new BfBoom('close');
$p = bf_wrap_path(['segments' => [[$csv, 1]], 'mode' => 'throw_read', 'exc' => $read, 'throw_close' => $close]);
$t = caught(fn() => Csv::scan($p, $S)->count());
echo $t === $close ? 'close exception' : 'OTHER', ', previous is the read exception: ', $t && $t->getPrevious() === $read ? 'yes' : 'NO', ', closes=', BfWrap::$closes, "\n";

echo "-- no exception before close, a CSV error in the data, close throws: the close exception is untouched\n";
$boom = new BfBoom('close');
$p = bf_wrap_path(['segments' => [["a\n1\nx\n", 1]], 'throw_close' => $boom]);
$t = caught(fn() => Csv::scan($p, $S)->whereEq('a', 1)->count());
echo $t === $boom ? 'same object' : 'NOT THE SAME (' . ($t ? get_class($t) : 'none') . ')', ', previous ', $t && $t->getPrevious() === null ? 'null' : 'SET', ', closes=', BfWrap::$closes, "\n";

echo "-- a caller's stream is never closed by the run\n";
$boom = new BfBoom('read');
$f = bf_wrap_stream($csv, 3, ['mode' => 'throw_read', 'exc' => $boom]);
$t = caught(fn() => Csv::scanStream($f, $S)->count());
echo $t === $boom ? 'same object' : 'NOT THE SAME', ', still open: ', is_resource($f) ? 'yes' : 'NO', "\n";
fclose($f);

echo "-- the read exception is freed once the caller drops it, whether close completes or throws\n";
foreach (['close completes' => false, 'close throws' => true] as $label => $throwClose) {
    $read = new BfBoom('read');
    $weak = WeakReference::create($read);
    $cfg = ['segments' => [[$csv, 1]], 'mode' => 'throw_read', 'exc' => $read];
    if ($throwClose) {
        $cfg['throw_close'] = new BfBoom('close');
    }
    $p = bf_wrap_path($cfg);
    unset($read, $cfg);
    $t = caught(fn() => Csv::scan($p, $S)->count());
    echo str_pad($label, 16), 'caught ', $t instanceof BfBoom ? $t->getMessage() : 'OTHER', ', ';
    BfWrap::$cfg = [];
    BfWrap::$last = null;
    unset($t);
    echo 'read exception freed: ', $weak->get() === null ? 'yes' : 'NO', "\n";
}

echo "-- 1,000 throwing reads on a path stream: no memory growth\n";
foreach (['close completes' => false, 'close throws' => true] as $label => $throwClose) {
    $run = function () use ($csv, $S, $throwClose) {
        $cfg = ['segments' => [[$csv, 1]], 'mode' => 'throw_read', 'exc' => new BfBoom('read')];
        if ($throwClose) {
            $cfg['throw_close'] = new BfBoom('close');
        }
        $p = bf_wrap_path($cfg);
        unset($cfg);
        caught(fn() => Csv::scan($p, $S)->count());
        BfWrap::$cfg = [];
        BfWrap::$last = null;
    };
    $run();
    $before = memory_get_usage();
    for ($i = 0; $i < 1000; $i++) {
        $run();
    }
    echo str_pad($label, 16), 'growth: ', memory_get_usage() - $before, " bytes\n";
}

echo "-- a fiber destroyed inside the read: stream_close does not run\n";
$p = bf_wrap_path(['segments' => [[$csv, 1]], 'on_read' => function () { Fiber::suspend(); }]);
$fiber = new Fiber(function () use ($p, $S) {
    Csv::scan($p, $S)->count();
    echo "not reached\n";
});
$fiber->start();
unset($fiber);
echo 'closes=', BfWrap::$closes, "\n";
?>
--EXPECT--
-- read or eof threw, close completes: the original exception, unchanged
throw_read  same object, previous null, closes=1
throw_eof   same object, previous null, closes=1
-- close throws and catches internally, or catches and then throws, for every terminal
throw_read count    inner catch: original, previous null, closes=1
throw_read count    catch, then throw: close exception, its previous the original, original previous null, closes=1
throw_read sum      inner catch: original, previous null, closes=1
throw_read sum      catch, then throw: close exception, its previous the original, original previous null, closes=1
throw_read summary  inner catch: original, previous null, closes=1
throw_read summary  catch, then throw: close exception, its previous the original, original previous null, closes=1
throw_eof count     inner catch: original, previous null, closes=1
throw_eof count     catch, then throw: close exception, its previous the original, original previous null, closes=1
throw_eof sum       inner catch: original, previous null, closes=1
throw_eof sum       catch, then throw: close exception, its previous the original, original previous null, closes=1
throw_eof summary   inner catch: original, previous null, closes=1
throw_eof summary   catch, then throw: close exception, its previous the original, original previous null, closes=1
-- read and close both threw: the close exception surfaces with the read exception as previous
close exception, previous is the read exception: yes, closes=1
-- no exception before close, a CSV error in the data, close throws: the close exception is untouched
same object, previous null, closes=1
-- a caller's stream is never closed by the run
same object, still open: yes
-- the read exception is freed once the caller drops it, whether close completes or throws
close completes caught read, read exception freed: yes
close throws    caught close, read exception freed: yes
-- 1,000 throwing reads on a path stream: no memory growth
close completes growth: 0 bytes
close throws    growth: 0 bytes
-- a fiber destroyed inside the read: stream_close does not run
closes=0
