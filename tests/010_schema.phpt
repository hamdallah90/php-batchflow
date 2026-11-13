--TEST--
batchflow: valid schemas are accepted without I/O - all types, integer-like names, __proto__, 1024 columns
--EXTENSIONS--
batchflow
--FILE--
<?php
require __DIR__ . '/bf_helper.inc';

use BatchFlow\{Csv, Query};

function ok(string $label, array $schema, array $opts = []): void
{
    // The path does not exist: success proves schema validation does no I/O.
    $r = bf_try(fn() => Csv::scan('/nonexistent/batchflow/none.csv', $schema, $opts) instanceof Query ? 'Query' : 'other');
    echo str_pad($label, 34), $r, "\n";
}

ok('one string column', ['a' => 'string']);
ok('all four types', ['i' => 'int64', 'f' => 'float64', 'b' => 'bool', 's' => 'string']);
ok('integer-like name 2024', ['2024' => 'float64']);
ok('integer key 5', [5 => 'int64']);
ok('negative integer key', [-5 => 'int64']);
ok('mixed integer-like and text', ['2024' => 'float64', 'x' => 'string', 7 => 'bool']);
ok('__proto__', ['__proto__' => 'int64']);
ok('name with space and quote', ['say "hi" \\ now' => 'string']);
ok('name with NUL byte', ["a\0b" => 'string']);
ok('name with newline', ["a\nb" => 'string']);
ok('multibyte name', ["caf\u{e9} \u{1F600}" => 'string']);
ok('name of 1 byte', ['a' => 'string']);
ok('name of 1024 bytes', [str_repeat('n', 1024) => 'string']);
ok('name of 1024 bytes (multibyte)', [str_repeat("\u{e9}", 512) => 'string']);

$many = [];
for ($i = 0; $i < 1024; $i++) {
    $many["c$i"] = ['int64', 'float64', 'bool', 'string'][$i % 4];
}
ok('1024 columns', $many);

echo "-- empty options array and explicit defaults\n";
ok('empty options', ['a' => 'int64'], []);
ok('explicit defaults', ['a' => 'int64'], [
    'allowExtraColumns' => false, 'maxFieldBytes' => 1048576, 'maxRowBytes' => 16777216,
    'maxInputRows' => 100000000, 'maxGroupBytes' => 268435456, 'simd' => 'auto',
]);

echo "-- scanStream validates the same way\n";
$f = fopen('php://memory', 'w+b');
var_dump(Csv::scanStream($f, ['a' => 'int64', '2024' => 'string']) instanceof Query);
echo bf_try(fn() => Csv::scanStream($f, [])), "\n";

echo "-- every call returns a distinct Query\n";
$a = Csv::scan('/nonexistent/x.csv', ['a' => 'int64']);
$b = Csv::scan('/nonexistent/x.csv', ['a' => 'int64']);
var_dump($a !== $b);
?>
--EXPECT--
one string column                 Query
all four types                    Query
integer-like name 2024            Query
integer key 5                     Query
negative integer key              Query
mixed integer-like and text       Query
__proto__                         Query
name with space and quote         Query
name with NUL byte                Query
name with newline                 Query
multibyte name                    Query
name of 1 byte                    Query
name of 1024 bytes                Query
name of 1024 bytes (multibyte)    Query
1024 columns                      Query
-- empty options array and explicit defaults
empty options                     Query
explicit defaults                 Query
-- scanStream validates the same way
bool(true)
SchemaException: schema must not be empty
-- every call returns a distinct Query
bool(true)
