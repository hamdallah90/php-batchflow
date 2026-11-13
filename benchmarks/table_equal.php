<?php
// table_equal.php <bench dir> <fixture dir> <rows>: every terminal over each bench shape, streaming vs Table (plain and
// with dictionaries on the key and status/region columns); results compared byte for byte through serialize().
declare(strict_types=1);
use BatchFlow\{Csv, Table};
[, $bench, $dir, $rows] = $argv;
require "$bench/lib.php";
$bad = 0;
$n = 0;
foreach (['numeric', 'mixed', 'mixnq', 'wide'] as $shape) {
    $p = "$dir/$shape-$rows.csv";
    if (!is_file($p)) {
        passthru(PHP_BINARY . " $bench/gen.php $shape $rows 42 " . escapeshellarg($p), $rc);
        if ($rc !== 0) { exit(2); }
    }
    $S = bf2_schema($shape);
    $dictCols = array_values(array_filter(['k10', 'k1k', 'k100k', 'status', 'region'], fn($c) => isset($S[$c]) && in_array($S[$c], ['string', 'int64'], true)));
    $t0 = microtime(true);
    $tables = ['plain' => Table::fromCsv($p, $S), 'dict' => Table::fromCsv($p, $S, ['dictionary' => $dictCols])];
    printf("%s: loaded %d rows twice in %.1f s, memoryBytes plain %d dict %d\n", $shape, $tables['plain']->rowCount(), microtime(true) - $t0, $tables['plain']->memoryBytes(), $tables['dict']->memoryBytes());
    $num = $shape === 'numeric';
    $terms = [
        'count' => fn($q) => $q->count(),
        'sum amount' => fn($q) => $q->sum('amount'),
        'summary' => fn($q) => (array)$q->summary('amount'),
        'aggregate' => fn($q) => $q->aggregate([['count', 'id'], ['min', 'amount'], ['max', 'amount'], ['avg', 'amount'], ['sum', 'id']]),
        'gc k10' => fn($q) => $q->groupCount('k10'),
        'gs k1k' => fn($q) => $q->groupSum('k1k', 'amount'),
        'gs k100k' => fn($q) => $q->groupSum('k100k', 'amount'),
        'gagg' => fn($q) => $q->groupAgg(['k10', 'k1k'], [['sum', 'amount'], ['count', 'id'], ['max', 'id']]),
        'cursor md5' => function ($q) { $c = $q->cursor(); $h = hash_init('md5'); while ($b = $c->fetch(10000)) { hash_update($h, serialize($b)); } return hash_final($h); },
    ];
    $predSets = ['none' => [], 'hi' => bf2_preds($shape, 'hi'), 'lo' => bf2_preds($shape, 'lo'),
        'in+ne' => $num ? [['in', 'k10', [1, 2, 3, null]], ['ne', 'k1k', 7]] : [['in', 'k10', ['k10_1', 'k10_2', 'zzz', null]], ['ne', 'region', 'zzz']]];
    foreach ($predSets as $pname => $preds) {
        foreach ($terms as $tname => $fn) {
            $apply = function ($q) use ($preds) {
                foreach ($preds as [$op, $c, $v]) {
                    $q = match ($op) { 'eq' => $q->whereEq($c, $v), 'gt' => $q->whereGt($c, $v), 'ne' => $q->whereNe($c, $v), 'in' => $q->whereIn($c, $v) };
                }
                return $q;
            };
            $run = function ($q) use ($fn) { try { return serialize($fn($q)); } catch (Throwable $e) { return get_class($e) . ': ' . $e->getMessage(); } };
            $want = $run($apply(Csv::scan($p, $S)));
            foreach ($tables as $tn => $t) {
                $n++;
                $got = $run($apply($t->query()));
                if ($got !== $want) {
                    $bad++;
                    printf("DIFF %s %s %s %s: %s vs %s\n", $shape, $tn, $pname, $tname, substr($got, 0, 120), substr($want, 0, 120));
                }
            }
        }
    }
}
printf("RESULT comparisons=%d differences=%d\n", $n, $bad);
exit($bad === 0 ? 0 : 1);
