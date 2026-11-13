--TEST--
batchflow: option validation - unknown keys, Table-only keys on Csv::scan, no coercion, each bound and bound +/- 1, simd, dictionary
--EXTENSIONS--
batchflow
--FILE--
<?php
require __DIR__ . '/bf_helper.inc';

use BatchFlow\{Csv, Table, SchemaException};

const S = ['a' => 'int64', 'f' => 'float64', 'b' => 'bool', 's' => 'string'];

function scan(array $o): string
{
    $r = bf_try(fn() => Csv::scan('/nonexistent/batchflow/none.csv', S, $o) instanceof BatchFlow\Query ? 'accepted' : 'other');
    return $r;
}

// Table-only options go through Table::fromCsv; "accepted" there means anything but a SchemaException
// (the build then fails on I/O or is not implemented yet).
function tbl(array $o): string
{
    try {
        Table::fromCsv('/nonexistent/batchflow/none.csv', S, $o);
        return 'accepted';
    } catch (SchemaException $e) {
        return 'SchemaException: ' . $e->getMessage();
    } catch (Throwable $e) {
        return 'accepted';
    }
}

echo "-- unknown keys\n";
echo scan(['nope' => 1]), "\n";
echo scan(['maxfieldbytes' => 10]), "\n";
echo scan([0 => true]), "\n";
echo scan(["a\"b\\" => 1]), "\n";
echo scan([str_repeat('k', 70) => 1]), "\n";
foreach (['dictionary' => ['s'], 'maxDictionarySize' => 10, 'maxTableBytes' => 1048576] as $k => $v) {
    echo "Csv::scan $k: ", scan([$k => $v]), "\n";
}
echo "first bad option wins: ", scan(['nope' => 1, 'maxFieldBytes' => 0]), "\n";
echo "first bad option wins: ", scan(['maxFieldBytes' => 0, 'nope' => 1]), "\n";
echo "schema is checked before options: ", bf_try(fn() => Csv::scan('/x', [], ['nope' => 1])), "\n";

echo "-- wrong types, no coercion\n";
foreach (['maxFieldBytes', 'maxRowBytes', 'maxInputRows', 'maxGroupBytes'] as $k) {
    foreach (['string 1' => '1', 'float 1.0' => 1.0, 'bool true' => true, 'array' => [1]] as $l => $v) {
        echo str_pad("$k $l", 28), scan([$k => $v]), "\n";
    }
}
foreach (['int 1' => 1, 'int 0' => 0, 'string true' => 'true', 'string 1' => '1', 'float' => 1.0] as $l => $v) {
    echo str_pad("allowExtraColumns $l", 28), scan(['allowExtraColumns' => $v]), "\n";
}
foreach (['int' => 1, 'bool' => true, 'array' => ['auto']] as $l => $v) {
    echo str_pad("simd $l", 28), scan(['simd' => $v]), "\n";
}
foreach (['maxDictionarySize', 'maxTableBytes'] as $k) {
    foreach (['string 1' => '1048576', 'float' => 1048576.0, 'bool true' => true] as $l => $v) {
        echo str_pad("$k $l", 28), tbl([$k => $v]), "\n";
    }
}

echo "-- bounds\n";
$bounds = [
    'maxFieldBytes' => [1, 67108864],
    'maxRowBytes' => [1, 268435456],
    'maxInputRows' => [1, PHP_INT_MAX],
    'maxGroupBytes' => [1048576, 1099511627776],
];
foreach ($bounds as $k => [$lo, $hi]) {
    echo str_pad("$k lo-1", 28), scan([$k => $lo - 1]), "\n";
    echo str_pad("$k lo", 28), scan([$k => $lo]), "\n";
    echo str_pad("$k lo+1", 28), scan([$k => $lo + 1]), "\n";
    echo str_pad("$k hi", 28), scan([$k => $hi]), "\n";
    if ($hi < PHP_INT_MAX) {
        echo str_pad("$k hi+1", 28), scan([$k => $hi + 1]), "\n";
    }
    echo str_pad("$k negative", 28), scan([$k => -1]), "\n";
    echo str_pad("$k PHP_INT_MIN", 28), scan([$k => PHP_INT_MIN]), "\n";
}
$tb = ['maxDictionarySize' => [1, 4294967294], 'maxTableBytes' => [1048576, 1099511627776]];
foreach ($tb as $k => [$lo, $hi]) {
    echo str_pad("Table $k lo-1", 34), tbl([$k => $lo - 1]), "\n";
    echo str_pad("Table $k lo", 34), tbl([$k => $lo]), "\n";
    echo str_pad("Table $k hi", 34), tbl([$k => $hi]), "\n";
    echo str_pad("Table $k hi+1", 34), tbl([$k => $hi + 1]), "\n";
    echo str_pad("Table $k PHP_INT_MAX", 34), tbl([$k => PHP_INT_MAX]), "\n";
}
echo "Table also takes common options: ", tbl(['maxRowBytes' => 0]), "\n";
echo "Table takes common options: ", tbl(['maxRowBytes' => 10, 'simd' => 'scalar', 'allowExtraColumns' => true, 'maxGroupBytes' => 1048576]), "\n";
echo "bool options: ", scan(['allowExtraColumns' => true]), ' ', scan(['allowExtraColumns' => false]), "\n";

echo "-- simd\n";
foreach (['auto', 'scalar'] as $v) {
    echo "simd $v: ", scan(['simd' => $v]), "\n";
}
foreach (['AVX2', 'Scalar', '', 'sse', 'avx512', ' auto', "auto\0"] as $v) {
    echo 'simd ', var_export($v, true), ': ', scan(['simd' => $v]), "\n";
}
$other = php_uname('m') === 'x86_64' ? 'neon' : 'avx2';   // the ISA this machine can never have
echo "simd ISA of the other architecture: ", str_replace($other, '<isa>', scan(['simd' => $other])), "\n";

echo "-- dictionary (Table only)\n";
echo 'valid names: ', tbl(['dictionary' => ['s', 'a']]), "\n";
echo 'empty list: ', tbl(['dictionary' => []]), "\n";
foreach (['string' => 's', 'true' => true, 'int' => 1, 'null' => null, 'map' => ['x' => 's'], 'non-list keys' => [1 => 's'],
          'int element' => [1], 'null element' => [null], 'array element' => [['s']], 'bool element' => [true]] as $l => $v) {
    echo str_pad("dictionary $l", 28), tbl(['dictionary' => $v]), "\n";
}
echo str_pad('repeated name', 28), tbl(['dictionary' => ['s', 'a', 's']]), "\n";
echo str_pad('absent from schema', 28), tbl(['dictionary' => ['zz']]), "\n";
echo str_pad('absent name escaped', 28), tbl(['dictionary' => ["z\"\xff"]]), "\n";
foreach (['f', 'b'] as $c) {
    echo str_pad("column $c is not string/int64", 28), tbl(['dictionary' => [$c]]), "\n";
}
echo str_pad('wrong type before absent', 28), tbl(['dictionary' => ['s', 1, 'zz']]), "\n";
?>
--EXPECT--
-- unknown keys
SchemaException: option "nope": unknown option
SchemaException: option "maxfieldbytes": unknown option
SchemaException: option "0": unknown option
SchemaException: option "a\x22b\x5C": unknown option
SchemaException: option "kkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkk...": unknown option
Csv::scan dictionary: SchemaException: option "dictionary": unknown option
Csv::scan maxDictionarySize: SchemaException: option "maxDictionarySize": unknown option
Csv::scan maxTableBytes: SchemaException: option "maxTableBytes": unknown option
first bad option wins: SchemaException: option "nope": unknown option
first bad option wins: SchemaException: option "maxFieldBytes": out of range
schema is checked before options: SchemaException: schema must not be empty
-- wrong types, no coercion
maxFieldBytes string 1      SchemaException: option "maxFieldBytes": wrong type
maxFieldBytes float 1.0     SchemaException: option "maxFieldBytes": wrong type
maxFieldBytes bool true     SchemaException: option "maxFieldBytes": wrong type
maxFieldBytes array         SchemaException: option "maxFieldBytes": wrong type
maxRowBytes string 1        SchemaException: option "maxRowBytes": wrong type
maxRowBytes float 1.0       SchemaException: option "maxRowBytes": wrong type
maxRowBytes bool true       SchemaException: option "maxRowBytes": wrong type
maxRowBytes array           SchemaException: option "maxRowBytes": wrong type
maxInputRows string 1       SchemaException: option "maxInputRows": wrong type
maxInputRows float 1.0      SchemaException: option "maxInputRows": wrong type
maxInputRows bool true      SchemaException: option "maxInputRows": wrong type
maxInputRows array          SchemaException: option "maxInputRows": wrong type
maxGroupBytes string 1      SchemaException: option "maxGroupBytes": wrong type
maxGroupBytes float 1.0     SchemaException: option "maxGroupBytes": wrong type
maxGroupBytes bool true     SchemaException: option "maxGroupBytes": wrong type
maxGroupBytes array         SchemaException: option "maxGroupBytes": wrong type
allowExtraColumns int 1     SchemaException: option "allowExtraColumns": wrong type
allowExtraColumns int 0     SchemaException: option "allowExtraColumns": wrong type
allowExtraColumns string trueSchemaException: option "allowExtraColumns": wrong type
allowExtraColumns string 1  SchemaException: option "allowExtraColumns": wrong type
allowExtraColumns float     SchemaException: option "allowExtraColumns": wrong type
simd int                    SchemaException: option "simd": wrong type
simd bool                   SchemaException: option "simd": wrong type
simd array                  SchemaException: option "simd": wrong type
maxDictionarySize string 1  SchemaException: option "maxDictionarySize": wrong type
maxDictionarySize float     SchemaException: option "maxDictionarySize": wrong type
maxDictionarySize bool true SchemaException: option "maxDictionarySize": wrong type
maxTableBytes string 1      SchemaException: option "maxTableBytes": wrong type
maxTableBytes float         SchemaException: option "maxTableBytes": wrong type
maxTableBytes bool true     SchemaException: option "maxTableBytes": wrong type
-- bounds
maxFieldBytes lo-1          SchemaException: option "maxFieldBytes": out of range
maxFieldBytes lo            accepted
maxFieldBytes lo+1          accepted
maxFieldBytes hi            accepted
maxFieldBytes hi+1          SchemaException: option "maxFieldBytes": out of range
maxFieldBytes negative      SchemaException: option "maxFieldBytes": out of range
maxFieldBytes PHP_INT_MIN   SchemaException: option "maxFieldBytes": out of range
maxRowBytes lo-1            SchemaException: option "maxRowBytes": out of range
maxRowBytes lo              accepted
maxRowBytes lo+1            accepted
maxRowBytes hi              accepted
maxRowBytes hi+1            SchemaException: option "maxRowBytes": out of range
maxRowBytes negative        SchemaException: option "maxRowBytes": out of range
maxRowBytes PHP_INT_MIN     SchemaException: option "maxRowBytes": out of range
maxInputRows lo-1           SchemaException: option "maxInputRows": out of range
maxInputRows lo             accepted
maxInputRows lo+1           accepted
maxInputRows hi             accepted
maxInputRows negative       SchemaException: option "maxInputRows": out of range
maxInputRows PHP_INT_MIN    SchemaException: option "maxInputRows": out of range
maxGroupBytes lo-1          SchemaException: option "maxGroupBytes": out of range
maxGroupBytes lo            accepted
maxGroupBytes lo+1          accepted
maxGroupBytes hi            accepted
maxGroupBytes hi+1          SchemaException: option "maxGroupBytes": out of range
maxGroupBytes negative      SchemaException: option "maxGroupBytes": out of range
maxGroupBytes PHP_INT_MIN   SchemaException: option "maxGroupBytes": out of range
Table maxDictionarySize lo-1      SchemaException: option "maxDictionarySize": out of range
Table maxDictionarySize lo        accepted
Table maxDictionarySize hi        accepted
Table maxDictionarySize hi+1      SchemaException: option "maxDictionarySize": out of range
Table maxDictionarySize PHP_INT_MAXSchemaException: option "maxDictionarySize": out of range
Table maxTableBytes lo-1          SchemaException: option "maxTableBytes": out of range
Table maxTableBytes lo            accepted
Table maxTableBytes hi            accepted
Table maxTableBytes hi+1          SchemaException: option "maxTableBytes": out of range
Table maxTableBytes PHP_INT_MAX   SchemaException: option "maxTableBytes": out of range
Table also takes common options: SchemaException: option "maxRowBytes": out of range
Table takes common options: accepted
bool options: accepted accepted
-- simd
simd auto: accepted
simd scalar: accepted
simd 'AVX2': SchemaException: option "simd": out of range
simd 'Scalar': SchemaException: option "simd": out of range
simd '': SchemaException: option "simd": out of range
simd 'sse': SchemaException: option "simd": out of range
simd 'avx512': SchemaException: option "simd": out of range
simd ' auto': SchemaException: option "simd": out of range
simd 'auto' . "\0" . '': SchemaException: option "simd": out of range
simd ISA of the other architecture: SchemaException: option "simd": <isa> is not available on this CPU
-- dictionary (Table only)
valid names: accepted
empty list: accepted
dictionary string           SchemaException: option "dictionary": wrong type
dictionary true             SchemaException: option "dictionary": wrong type
dictionary int              SchemaException: option "dictionary": wrong type
dictionary null             SchemaException: option "dictionary": wrong type
dictionary map              SchemaException: option "dictionary": wrong type
dictionary non-list keys    SchemaException: option "dictionary": wrong type
dictionary int element      SchemaException: option "dictionary": wrong type
dictionary null element     SchemaException: option "dictionary": wrong type
dictionary array element    SchemaException: option "dictionary": wrong type
dictionary bool element     SchemaException: option "dictionary": wrong type
repeated name               SchemaException: option "dictionary": out of range
absent from schema          SchemaException: unknown column "zz"
absent name escaped         SchemaException: unknown column "z\x22\xFF"
column f is not string/int64SchemaException: option "dictionary": column "f" must be string or int64
column b is not string/int64SchemaException: option "dictionary": column "b" must be string or int64
wrong type before absent    SchemaException: option "dictionary": wrong type
