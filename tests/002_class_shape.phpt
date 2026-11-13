--TEST--
batchflow: final classes, exception hierarchy, readonly Summary, private constructors, clone and serialize refusal, exact signatures
--EXTENSIONS--
batchflow
--FILE--
<?php
use BatchFlow\{Csv, Table, Query, Summary, Runtime};

foreach (['Csv', 'Table', 'Query', 'Summary', 'Runtime'] as $n) {
    $r = new ReflectionClass("BatchFlow\\$n");
    echo str_pad($n, 8), 'final=', var_export($r->isFinal(), true), ' internal=', var_export($r->isInternal(), true),
        ' ctor=', $r->getConstructor()->isPrivate() ? 'private' : 'PUBLIC', "\n";
}

echo "-- hierarchy\n";
foreach ([
    'BatchFlowException' => 'RuntimeException', 'SchemaException' => 'BatchFlow\BatchFlowException',
    'CsvException' => 'BatchFlow\BatchFlowException', 'LimitException' => 'BatchFlow\BatchFlowException',
    'ResultTooLargeException' => 'BatchFlow\LimitException', 'SumOverflowException' => 'BatchFlow\BatchFlowException',
    'StreamException' => 'BatchFlow\BatchFlowException',
] as $c => $p) {
    $r = new ReflectionClass("BatchFlow\\$c");
    echo str_pad($c, 25), $r->getParentClass()->getName() === $p ? 'ok' : 'WRONG PARENT ' . $r->getParentClass()->getName(),
        $r->isFinal() ? ' FINAL' : '', "\n";
}

echo "-- Summary properties\n";
foreach ((new ReflectionClass(Summary::class))->getProperties() as $p) {
    $t = (string)$p->getType();
    $parts = explode('|', $t);
    sort($parts);
    echo $p->getName(), ' public=', var_export($p->isPublic(), true), ' readonly=', var_export($p->isReadOnly(), true),
        ' type=', implode('|', $parts), "\n";
}

echo "-- signatures\n";
function sig(ReflectionMethod $m): string
{
    $ps = [];
    foreach ($m->getParameters() as $p) {
        $t = $p->getType();
        $s = $t === null ? '' : preg_replace_callback('/^[^?]*$/', function ($mm) {
            $x = explode('|', $mm[0]);
            sort($x);
            return implode('|', $x);
        }, (string)$t) . ' ';
        $s .= '$' . $p->getName();
        if ($p->isDefaultValueAvailable()) {
            $s .= ' = ' . json_encode($p->getDefaultValue());
        }
        $ps[] = $s;
    }
    $rt = $m->getReturnType();
    return implode(' ', Reflection::getModifierNames($m->getModifiers())) . ' ' . $m->getName() . '(' . implode(', ', $ps) . ')'
        . ($rt === null ? '' : ': ' . $rt);
}
foreach (['Csv', 'Table', 'Query', 'Summary', 'Runtime'] as $n) {
    echo "$n\n";
    $lines = [];
    foreach ((new ReflectionClass("BatchFlow\\$n"))->getMethods() as $m) {
        if ($m->getName() !== '__construct' && str_starts_with($m->getName(), '__')) {
            continue;
        }
        $lines[] = '  ' . sig($m);
    }
    sort($lines);
    echo implode("\n", $lines), "\n";
}

echo "-- clone, serialize, unserialize refused\n";
$q = Csv::scan('/nonexistent/x.csv', ['a' => 'int64']);
$objs = [
    'Query' => $q,
    'Csv' => (new ReflectionClass(Csv::class))->newInstanceWithoutConstructor(),
    'Runtime' => (new ReflectionClass(Runtime::class))->newInstanceWithoutConstructor(),
];
foreach ($objs as $n => $o) {
    foreach (['clone' => fn() => clone $o, 'serialize' => fn() => serialize($o)] as $what => $fn) {
        try {
            $fn();
            echo "$n $what: NOT REFUSED\n";
        } catch (Throwable $e) {
            echo "$n $what: ", get_class($e), ': ', $e->getMessage(), "\n";
        }
    }
}
foreach (['Table', 'Query', 'Csv', 'Runtime'] as $n) {
    $s = sprintf('O:%d:"BatchFlow\%s":0:{}', strlen("BatchFlow\\$n"), $n);
    try {
        unserialize($s);
        echo "$n unserialize: NOT REFUSED\n";
    } catch (Throwable $e) {
        echo "$n unserialize: ", get_class($e), ': ', $e->getMessage(), "\n";
    }
}

echo "-- private constructors\n";
foreach (['Csv', 'Table', 'Query', 'Summary', 'Runtime'] as $n) {
    try {
        $c = "BatchFlow\\$n";
        new $c();
        echo "$n: constructed\n";
    } catch (Throwable $e) {
        echo get_class($e), ': ', $e->getMessage(), "\n";
    }
}

echo "-- newInstanceWithoutConstructor refused on Table and Query\n";
foreach (['Table', 'Query'] as $n) {
    try {
        (new ReflectionClass("BatchFlow\\$n"))->newInstanceWithoutConstructor();
        echo "$n: instantiated\n";
    } catch (ReflectionException $e) {
        echo "$n: ReflectionException ", str_contains($e->getMessage(), 'cannot be instantiated without invoking its constructor') ? 'ok' : $e->getMessage(), "\n";
    }
}

echo "-- Summary clone and serialize succeed\n";
$s = unserialize('O:17:"BatchFlow\Summary":0:{}');
var_dump($s instanceof Summary);
var_dump(clone $s instanceof Summary);
var_dump(is_string(serialize($s)));
?>
--EXPECT--
Csv     final=true internal=true ctor=private
Table   final=true internal=true ctor=private
Query   final=true internal=true ctor=private
Summary final=true internal=true ctor=private
Runtime final=true internal=true ctor=private
-- hierarchy
BatchFlowException       ok
SchemaException          ok
CsvException             ok
LimitException           ok
ResultTooLargeException  ok
SumOverflowException     ok
StreamException          ok
-- Summary properties
rowsScanned public=true readonly=true type=int
rowsMatched public=true readonly=true type=int
sum public=true readonly=true type=float|int|null
-- signatures
Csv
  private __construct()
  public static scan(string $path, array $schema, array $options = []): BatchFlow\Query
  public static scanStream($stream, array $schema, array $options = []): BatchFlow\Query
Table
  private __construct()
  public columnNames(): array
  public memoryBytes(): int
  public query(): BatchFlow\Query
  public rowCount(): int
  public schema(): array
  public static fromCsv(string $path, array $schema, array $options = []): BatchFlow\Table
  public static fromStream($stream, array $schema, array $options = []): BatchFlow\Table
  public whereEq(string $column, bool|float|int|null|string $value): BatchFlow\Query
  public whereGt(string $column, float|int $value): BatchFlow\Query
  public whereGte(string $column, float|int $value): BatchFlow\Query
  public whereIn(string $column, array $values): BatchFlow\Query
  public whereLt(string $column, float|int $value): BatchFlow\Query
  public whereLte(string $column, float|int $value): BatchFlow\Query
  public whereNe(string $column, bool|float|int|null|string $value): BatchFlow\Query
Query
  private __construct()
  public aggregate(array $aggregates): array
  public avg(string $column): ?float
  public count(): int
  public cursor(): BatchFlow\Cursor
  public groupAgg(array|string $keys, array $aggregates, int $maxGroups = 1000000): array
  public groupCount(string $key, int $maxGroups = 1000000): array
  public groupSum(string $key, string $sum, int $maxGroups = 1000000): array
  public max(string $column): int|float|null
  public min(string $column): int|float|null
  public sum(string $column): int|float
  public summary(?string $sumColumn = null): BatchFlow\Summary
  public toArray(int $maxRows = 10000): array
  public whereEq(string $column, bool|float|int|null|string $value): BatchFlow\Query
  public whereGt(string $column, float|int $value): BatchFlow\Query
  public whereGte(string $column, float|int $value): BatchFlow\Query
  public whereIn(string $column, array $values): BatchFlow\Query
  public whereLt(string $column, float|int $value): BatchFlow\Query
  public whereLte(string $column, float|int $value): BatchFlow\Query
  public whereNe(string $column, bool|float|int|null|string $value): BatchFlow\Query
Summary
  private __construct()
Runtime
  private __construct()
  public static info(): array
-- clone, serialize, unserialize refused
Query clone: Error: Trying to clone an uncloneable object of class BatchFlow\Query
Query serialize: Exception: Serialization of 'BatchFlow\Query' is not allowed
Csv clone: Error: Trying to clone an uncloneable object of class BatchFlow\Csv
Csv serialize: Exception: Serialization of 'BatchFlow\Csv' is not allowed
Runtime clone: Error: Trying to clone an uncloneable object of class BatchFlow\Runtime
Runtime serialize: Exception: Serialization of 'BatchFlow\Runtime' is not allowed
Table unserialize: Exception: Unserialization of 'BatchFlow\Table' is not allowed
Query unserialize: Exception: Unserialization of 'BatchFlow\Query' is not allowed
Csv unserialize: Exception: Unserialization of 'BatchFlow\Csv' is not allowed
Runtime unserialize: Exception: Unserialization of 'BatchFlow\Runtime' is not allowed
-- private constructors
Error: Call to private BatchFlow\Csv::__construct() from global scope
Error: Call to private BatchFlow\Table::__construct() from global scope
Error: Call to private BatchFlow\Query::__construct() from global scope
Error: Call to private BatchFlow\Summary::__construct() from global scope
Error: Call to private BatchFlow\Runtime::__construct() from global scope
-- newInstanceWithoutConstructor refused on Table and Query
Table: ReflectionException ok
Query: ReflectionException ok
-- Summary clone and serialize succeed
bool(true)
bool(true)
bool(true)
