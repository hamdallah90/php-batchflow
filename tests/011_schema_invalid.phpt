--TEST--
batchflow: every invalid-schema reason, exact message, no value echoed, argument failure order
--EXTENSIONS--
batchflow
--FILE--
<?php
require __DIR__ . '/bf_helper.inc';

use BatchFlow\Csv;

function bad(string $label, mixed $schema): void
{
    echo str_pad($label, 30), bf_try(fn() => Csv::scan('/nonexistent/batchflow/none.csv', $schema)), "\n";
}

echo "-- empty and too large\n";
bad('empty', []);
$many = [];
for ($i = 0; $i < 1025; $i++) {
    $many["c$i"] = 'int64';
}
bad('1025 columns', $many);
$many2 = ["\xff" => 'bogus'] + $many;
bad('1025 columns, first entry bad', $many2);

echo "-- unknown type\n";
bad('INT64', ['x' => 'INT64']);
bad('int', ['x' => 'int']);
bad('empty type', ['x' => '']);
bad('float', ['x' => 'float']);
bad('Bool', ['x' => 'Bool']);
bad('padded int64', ['x' => ' int64']);
bad('NUL in type', ['x' => "int64\0"]);
bad('secret type text', ['x' => 'SECRETTYPE']);
bad('type int', ['x' => 1]);
bad('type null', ['x' => null]);
bad('type true', ['x' => true]);
bad('type array', ['x' => ['int64']]);
bad('type float', ['x' => 1.5]);
bad('integer key name', [5 => 'nope']);

echo "-- invalid name\n";
bad('empty name', ['' => 'int64']);
bad('1025-byte name', [str_repeat('n', 1025) => 'int64']);
bad('invalid UTF-8 name', ["a\xffb" => 'int64']);
bad('overlong NUL name', ["\xC0\x80" => 'int64']);
bad('surrogate name', ["\xED\xA0\x80" => 'int64']);
bad('name above U+10FFFF', ["\xF4\x90\x80\x80" => 'int64']);
bad('truncated sequence name', ["ab\xE2\x82" => 'int64']);
bad('invalid name 70 bytes', [str_repeat('z', 69) . "\xff" => 'int64']);
bad('invalid name 64 bytes', [str_repeat('z', 63) . "\xff" => 'int64']);
bad('invalid name, quote', ["a\"\\\xff" => 'int64']);

echo "-- name is checked before type, entries in array order\n";
bad('bad name and bad type', ["\xff" => 'bogus']);
bad('first entry wins (type)', ['a' => 'bogus', '' => 'int64']);
bad('first entry wins (name)', ['' => 'int64', 'a' => 'bogus']);
bad('valid then invalid', ['ok' => 'int64', 'b' => 'nope']);

echo "-- argument failure order\n";
echo bf_try(fn() => Csv::scan('', [])), "\n";
echo bf_try(fn() => Csv::scan('', ['a' => 'int64'], ['nope' => 1])), "\n";
echo bf_try(fn() => Csv::scan("a\0b", ['a' => 'int64'])), "\n";
echo bf_try(fn() => Csv::scan([], [])), "\n";
echo bf_try(fn() => Csv::scan('/x', 'a')), "\n";
echo bf_try(fn() => Csv::scan('/x', ['a' => 'int64'], 'a')), "\n";
echo bf_try(fn() => Csv::scan('/x', [], ['nope' => 1])), "\n";
echo bf_try(fn() => Csv::scan('/x', ['a' => 'bogus'], ['nope' => 1])), "\n";
echo bf_try(fn() => Csv::scan('/x', ['a' => 'int64'], ['nope' => 1])), "\n";
?>
--EXPECT--
-- empty and too large
empty                         SchemaException: schema must not be empty
1025 columns                  SchemaException: too many columns (limit 1024)
1025 columns, first entry bad SchemaException: too many columns (limit 1024)
-- unknown type
INT64                         SchemaException: column "x": unknown type
int                           SchemaException: column "x": unknown type
empty type                    SchemaException: column "x": unknown type
float                         SchemaException: column "x": unknown type
Bool                          SchemaException: column "x": unknown type
padded int64                  SchemaException: column "x": unknown type
NUL in type                   SchemaException: column "x": unknown type
secret type text              SchemaException: column "x": unknown type
type int                      SchemaException: column "x": unknown type
type null                     SchemaException: column "x": unknown type
type true                     SchemaException: column "x": unknown type
type array                    SchemaException: column "x": unknown type
type float                    SchemaException: column "x": unknown type
integer key name              SchemaException: column "5": unknown type
-- invalid name
empty name                    SchemaException: column "": invalid name
1025-byte name                SchemaException: column "nnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnn...": invalid name
invalid UTF-8 name            SchemaException: column "a\xFFb": invalid name
overlong NUL name             SchemaException: column "\xC0\x80": invalid name
surrogate name                SchemaException: column "\xED\xA0\x80": invalid name
name above U+10FFFF           SchemaException: column "\xF4\x90\x80\x80": invalid name
truncated sequence name       SchemaException: column "ab\xE2\x82": invalid name
invalid name 70 bytes         SchemaException: column "zzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzz...": invalid name
invalid name 64 bytes         SchemaException: column "zzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzz\xFF": invalid name
invalid name, quote           SchemaException: column "a\x22\x5C\xFF": invalid name
-- name is checked before type, entries in array order
bad name and bad type         SchemaException: column "\xFF": invalid name
first entry wins (type)       SchemaException: column "a": unknown type
first entry wins (name)       SchemaException: column "": invalid name
valid then invalid            SchemaException: column "b": unknown type
-- argument failure order
ValueError: BatchFlow\Csv::scan(): Argument #1 ($path) must not be empty
ValueError: BatchFlow\Csv::scan(): Argument #1 ($path) must not be empty
ValueError: BatchFlow\Csv::scan(): Argument #1 ($path) must not contain any null bytes
TypeError: BatchFlow\Csv::scan(): Argument #1 ($path) must be of type string, array given
TypeError: BatchFlow\Csv::scan(): Argument #2 ($schema) must be of type array, string given
TypeError: BatchFlow\Csv::scan(): Argument #3 ($options) must be of type array, string given
SchemaException: schema must not be empty
SchemaException: column "a": unknown type
SchemaException: option "nope": unknown option
