--TEST--
batchflow: every terminal over a table equals streaming on the same data, for plain, partly and fully dictionary-encoded tables, errors included
--EXTENSIONS--
batchflow
--FILE--
<?php
use BatchFlow\{Csv, Table};

// SPEC 6.6: results on a table equal streaming results on the same data. Each predicate list runs against each
// terminal on Csv::scan and on three tables of the same file; results and errors must be the same. An error's row
// prefix differs by design (streaming "row R (line L, offset O): ", table "row R: "), so the line and offset are cut
// before comparing; R itself must match.
$S = ['i' => 'int64', 'f' => 'float64', 'b' => 'bool', 's' => 'string', 'k' => 'string', 'g' => 'int64', 'o' => 'int64'];
$csv = "i,f,b,s,k,g,o\n"
    . "1,1.5,true,x,a,1,1\n"
    . ",,,,,,\n"
    . "3,-2,false,\"\",b,2,9223372036854775000\n"
    . "4,0,true,yy,a,1,\n"
    . "5,2.5,false,zz,,,1000\n"
    . "6,1,true,x,c,3,-5\n"
    . "-7,-0.0,,\"\",\"\",-1,\n"
    . "1,1.5,true,x,a,1,1\n"
    . "9,,false,,b,,2\n";
$p = tempnam(sys_get_temp_dir(), 'bf');
file_put_contents($p, $csv);

function run_one(callable $fn, $q): string
{
    try {
        return var_export($fn($q), true);
    } catch (Throwable $e) {
        return get_class($e) . ': ' . preg_replace('/^row (\d+)( \(line \d+, offset \d+\))?: /', 'row $1: ', $e->getMessage());
    }
}

$qs = [
    'count' => fn($q) => $q->count(),
    'sum i' => fn($q) => $q->sum('i'),
    'sum f' => fn($q) => $q->sum('f'),
    'sum g' => fn($q) => $q->sum('g'),
    'sum o' => fn($q) => $q->sum('o'),
    'summary i' => fn($q) => (array)$q->summary('i'),
    'summary g' => fn($q) => (array)$q->summary('g'),
    'min f' => fn($q) => $q->min('f'),
    'max g' => fn($q) => $q->max('g'),
    'avg i' => fn($q) => $q->avg('i'),
    'aggregate' => fn($q) => $q->aggregate([['count', 's'], ['max', 'i'], ['sum', 'f'], ['min', 'g'], ['count', 'k']]),
    'gc k' => fn($q) => $q->groupCount('k'),
    'gc g' => fn($q) => $q->groupCount('g'),
    'gc s' => fn($q) => $q->groupCount('s'),
    'gc i max 2' => fn($q) => $q->groupCount('i', 2),
    'gc k max 1' => fn($q) => $q->groupCount('k', 1),
    'gs k i' => fn($q) => $q->groupSum('k', 'i'),
    'gs g f' => fn($q) => $q->groupSum('g', 'f'),
    'gs s o' => fn($q) => $q->groupSum('s', 'o'),
    'gagg k' => fn($q) => $q->groupAgg('k', [['sum', 'i'], ['count', 'k']]),
    'gagg g' => fn($q) => $q->groupAgg(['g'], [['min', 'f'], ['count', 'g']]),
    'gagg s i' => fn($q) => $q->groupAgg(['s', 'i'], []),
    'gagg k g' => fn($q) => $q->groupAgg(['k', 'g'], [['sum', 'i'], ['min', 'f']]),
    'gagg four' => fn($q) => $q->groupAgg(['g', 'k', 's', 'i'], [['avg', 'f'], ['max', 'o']]),
    'gagg max 2' => fn($q) => $q->groupAgg(['k', 'g'], [], 2),
    'gagg float key' => fn($q) => $q->groupAgg(['k', 'f'], []),
    'toArray' => fn($q) => $q->toArray(),
    'cursor' => fn($q) => ($c = $q->cursor())->fetch(2) + [99 => $c->fetch(100)],
];
$preds = [
    [],
    [['whereEq', 'k', 'a']], [['whereNe', 'k', 'a']], [['whereEq', 'k', 'zz']], [['whereNe', 'k', 'zz']],
    [['whereEq', 'k', '']], [['whereEq', 'k', null]], [['whereNe', 'k', null], ['whereGt', 'i', 1]],
    [['whereIn', 'k', ['c', 'q', null]]], [['whereIn', 'k', ['q', 'r']]], [['whereIn', 'k', [null]]], [['whereIn', 'k', [null, null]]],
    [['whereIn', 'k', []]],
    [['whereEq', 'g', 1]], [['whereIn', 'g', [3, 7]]], [['whereIn', 'g', [null]]], [['whereIn', 'g', [8, null]]], [['whereIn', 'g', []]],
    [['whereGt', 'g', 1]], [['whereLte', 'g', 1]], [['whereNe', 'g', 2]],
    [['whereIn', 's', [null]]], [['whereIn', 's', []]], [['whereIn', 's', ['x', '']]],
    [['whereIn', 'i', [null]]], [['whereIn', 'i', []]], [['whereIn', 'i', [1, 9, null]]],
    [['whereEq', 's', 'x'], ['whereIn', 'k', ['a', 'c']]],
    [['whereGte', 'f', 0.0], ['whereNe', 'g', null]],
    [['whereLt', 'o', 0]], [['whereEq', 'b', true], ['whereIn', 'g', [1, 3]]],
];
$opts = [[], ['dictionary' => ['k', 'g']], ['dictionary' => ['i', 's', 'k', 'g', 'o']]];
$bad = 0;
$n = 0;
foreach ($opts as $opt) {
    $t = Table::fromCsv($p, $S, $opt);
    foreach ($preds as $pl) {
        $a = Csv::scan($p, $S);
        $b = $t->query();
        $ea = $eb = null;
        foreach ($pl as [$m, $c, $v]) {
            try { $a = $a->$m($c, $v); } catch (Throwable $e) { $ea ??= get_class($e) . ': ' . $e->getMessage(); }
            try { $b = $b->$m($c, $v); } catch (Throwable $e) { $eb ??= get_class($e) . ': ' . $e->getMessage(); }
        }
        if ($ea !== null || $eb !== null) {
            $n++;
            if ($ea !== $eb) {
                $bad++;
                echo "DIFF ", json_encode($opt), " ", json_encode($pl), " predicates\n stream: $ea\n table: $eb\n";
            }
            continue;
        }
        foreach ($qs as $name => $fn) {
            $ra = run_one($fn, $a);
            $rb = run_one($fn, $b);
            $n++;
            if ($ra !== $rb) {
                $bad++;
                echo "DIFF ", json_encode($opt), " ", json_encode($pl), " $name\n stream: $ra\n table: $rb\n";
            }
        }
    }
}
echo "$n comparisons, $bad differences\n";

// Spot values, so that both paths agreeing on a wrong answer would still show.
$t = Table::fromCsv($p, $S, ['dictionary' => ['k', 'g']]);
echo "k in [null]: ", $t->whereIn('k', [null])->count(), "; g in [null]: ", $t->whereIn('g', [null])->count(),
    "; k in absent: ", $t->whereIn('k', ['q'])->count(), "; k ne absent: ", $t->whereNe('k', 'q')->count(), "\n";
echo run_one(fn($q) => $q->sum('o'), $t->query()), "\n";
echo run_one(fn($q) => $q->groupAgg(['k', 'g'], [], 2), $t->query()), "\n";
echo run_one(fn($q) => $q->whereIn('k', []), $t->query()), "\n";
echo run_one(fn($q) => $q->groupAgg(['k', 'f'], []), $t->query()), "\n";
unlink($p);
?>
--EXPECT--
2280 comparisons, 0 differences
k in [null]: 2; g in [null]: 3; k in absent: 0; k ne absent: 7
BatchFlow\SumOverflowException: row 5: int64 sum overflow in column "o"
BatchFlow\ResultTooLargeException: row 3: result exceeds maxGroups (2)
ValueError: BatchFlow\Query::whereIn(): Argument #2 ($values) must not be empty
BatchFlow\SchemaException: column "f": group key must be string or int64
