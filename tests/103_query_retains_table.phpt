--TEST--
batchflow: a Query from a Table keeps the table alive: Table::fromCsv(...)->whereEq(...), unset table then use the query, a query returned from a function scope
--EXTENSIONS--
batchflow
--FILE--
<?php
require __DIR__ . '/bf_table.inc';

use BatchFlow\Table;

[$csv, $S] = tb_fixture(500, 103);
$p = tb_file($csv);
$want = fn(array $preds, string $term, array $x = []) => tb_norm(tb_try(fn() => tb_term(bf_apply(Csv_scan($p, $S), $preds), $term, $x)));
function Csv_scan(string $p, array $S)
{
    return BatchFlow\Csv::scan($p, $S);
}
$terms = [['count', []], ['sum', ['col' => 'v']], ['summary', ['col' => 'f']], ['groupCount', ['key' => 'k']], ['groupSum', ['key' => 'g', 'sum' => 'v']], ['toArray', ['maxRows' => 500]], ['min', ['col' => 'v']], ['avg', ['col' => 'f']]];
$preds = [['eq', 'k', 'red']];

// SPEC 5.12: Table::fromCsv(...)->whereEq(...) keeps the table alive after the temporary is dropped
echo "-- temporary table\n";
$q = Table::fromCsv($p, $S)->whereEq('k', 'red');
gc_collect_cycles();
foreach ($terms as [$term, $x]) {
    echo str_pad($term, 11), tb_try(fn() => tb_term($q, $term, $x)) === $want($preds, $term, $x) ? 'same' : 'DIFFERENT', "\n";
}

// SPEC 5.12: unset the table, use the query
echo "-- unset table\n";
$t = Table::fromCsv($p, $S);
$q = $t->whereEq('k', 'red');
$all = $t->query();
unset($t);
gc_collect_cycles();
echo 'filtered: ', tb_try(fn() => tb_term($q, 'count')), ' of expected ', $want($preds, 'count'), "\n";
echo 'unfiltered: ', tb_try(fn() => tb_term($all, 'count')), ' of expected ', $want([], 'count'), "\n";
foreach ($terms as [$term, $x]) {
    echo str_pad($term, 11), tb_try(fn() => tb_term($q, $term, $x)) === $want($preds, $term, $x) ? 'same' : 'DIFFERENT', "\n";
}

// SPEC 5.12: the query outlives the scope that built the table
echo "-- query returned from a function\n";
function build(string $p, array $S)
{
    $t = Table::fromCsv($p, $S);
    return $t->whereGt('v', 0)->whereEq('b', true);
}
$q = build($p, $S);
gc_collect_cycles();
$preds2 = [['gt', 'v', 0], ['eq', 'b', true]];
foreach ($terms as [$term, $x]) {
    echo str_pad($term, 11), tb_try(fn() => tb_term($q, $term, $x)) === $want($preds2, $term, $x) ? 'same' : 'DIFFERENT', "\n";
}

// a chain: each derived query keeps the table; dropping the ones before it changes nothing
echo "-- chain\n";
$q1 = Table::fromCsv($p, $S)->query();
$q2 = $q1->whereGt('v', 0);
unset($q1);
$q3 = $q2->whereEq('b', false);
unset($q2);
gc_collect_cycles();
echo 'count: ', $q3->count(), ' expected ', $want([['gt', 'v', 0], ['eq', 'b', false]], 'count'), "\n";

// two tables do not mix
echo "-- two tables\n";
$a = Table::fromCsv(tb_file("n\n1\n2\n3\n"), ['n' => 'int64']);
$b = Table::fromCsv(tb_file("n\n10\n20\n"), ['n' => 'int64']);
$qa = $a->whereGt('n', 1);
$qb = $b->whereGt('n', 1);
unset($a, $b);
echo 'qa sum: ', $qa->sum('n'), ', qb sum: ', $qb->sum('n'), "\n";
?>
--EXPECT--
-- temporary table
count      same
sum        same
summary    same
groupCount same
groupSum   same
toArray    same
min        same
avg        same
-- unset table
filtered: int(33) of expected int(33)
unfiltered: int(500) of expected int(500)
count      same
sum        same
summary    same
groupCount same
groupSum   same
toArray    same
min        same
avg        same
-- query returned from a function
count      same
sum        same
summary    same
groupCount same
groupSum   same
toArray    same
min        same
avg        same
-- chain
count: 95 expected int(95)
-- two tables
qa sum: 5, qb sum: 30
