--TEST--
batchflow: every predicate rejection reason, TypeError for array/object literals in both strict modes, unknown column before any I/O
--EXTENSIONS--
batchflow
--FILE--
<?php
require __DIR__ . '/bf_helper.inc';

use BatchFlow\Csv;

$q = Csv::scan('/nonexistent/batchflow/none.csv', ['i' => 'int64', 'f' => 'float64', 'b' => 'bool', 's' => 'string', 'a"b' => 'int64']);

function e(string $label, callable $fn): void
{
    echo str_pad($label, 34), bf_try(fn() => $fn() instanceof BatchFlow\Query ? 'Query' : 'other'), "\n";
}

echo "-- unknown column, before any I/O (the path does not exist)\n";
e('whereEq unknown', fn() => $q->whereEq('zz', 1));
e('whereGt unknown', fn() => $q->whereGt('zz', 1));
e('whereEq empty name', fn() => $q->whereEq('', 1));
e('whereEq case differs', fn() => $q->whereEq('I', 1));
e('whereEq escaped name', fn() => $q->whereEq("x\"\\\xff\n", 1));
e('whereEq 70-byte name', fn() => $q->whereEq(str_repeat('c', 70), 1));
e('unknown column wins over type', fn() => $q->whereEq('zz', 'text'));

echo "-- literal type against column type\n";
e('int64 <- float 1.0', fn() => $q->whereEq('i', 1.0));
e('int64 <- float 1.5', fn() => $q->whereEq('i', 1.5));
e('int64 <- string', fn() => $q->whereEq('i', '1'));
e('int64 <- bool', fn() => $q->whereEq('i', true));
e('int64 gt <- float', fn() => $q->whereGt('i', 1.5));
e('float64 <- string', fn() => $q->whereEq('f', '1.5'));
e('float64 <- bool', fn() => $q->whereEq('f', false));
e('float64 gt <- string', fn() => $q->whereGt('f', '1'));
e('bool <- int', fn() => $q->whereEq('b', 1));
e('bool <- int 0', fn() => $q->whereEq('b', 0));
e('bool <- float', fn() => $q->whereEq('b', 1.0));
e('bool <- string', fn() => $q->whereEq('b', 'true'));
e('string <- int', fn() => $q->whereEq('s', 1));
e('string <- float', fn() => $q->whereEq('s', 1.5));
e('string <- bool', fn() => $q->whereEq('s', false));
e('escaped column in message', fn() => $q->whereEq('a"b', 'x'));
e('type wins over invalid UTF-8', fn() => $q->whereEq('i', "\xff"));
e('type wins over NaN', fn() => $q->whereEq('s', NAN));
e('type wins over value', fn() => $q->whereEq('i', NAN));

echo "-- whereGt needs a numeric column\n";
e('whereGt bool <- int', fn() => $q->whereGt('b', 1));
e('whereGt bool <- float', fn() => $q->whereGt('b', 1.5));
e('whereGt string <- int', fn() => $q->whereGt('s', 1));
e('whereGt string <- float', fn() => $q->whereGt('s', 0.5));

echo "-- literal value\n";
e('float64 <- NAN', fn() => $q->whereEq('f', NAN));
e('float64 <- INF', fn() => $q->whereEq('f', INF));
e('float64 <- -INF', fn() => $q->whereEq('f', -INF));
e('float64 gt <- NAN', fn() => $q->whereGt('f', NAN));
e('float64 gt <- INF', fn() => $q->whereGt('f', INF));
e('float64 <- 1.8e308 literal (INF)', fn() => $q->whereEq('f', 1.8e308));
e('float64 <- int 2^53+1', fn() => $q->whereEq('f', 9007199254740993));
e('float64 gt <- int 2^53+1', fn() => $q->whereGt('f', 9007199254740993));
e('float64 <- PHP_INT_MAX', fn() => $q->whereEq('f', PHP_INT_MAX));
e('float64 gt <- PHP_INT_MAX', fn() => $q->whereGt('f', PHP_INT_MAX));
e('float64 <- -(2^53)-1', fn() => $q->whereEq('f', -9007199254740993));
e('string <- invalid UTF-8', fn() => $q->whereEq('s', "ab\xffcd"));
e('string <- overlong', fn() => $q->whereEq('s', "\xC0\x80"));
e('string <- surrogate', fn() => $q->whereEq('s', "\xED\xA0\x80"));
e('string <- truncated', fn() => $q->whereEq('s', "x\xE2\x82"));
e('string <- 1048577 bytes', fn() => $q->whereEq('s', str_repeat('x', 1048577)));
e('string <- 1048577 bytes (2-byte)', fn() => $q->whereEq('s', str_repeat("\u{e9}", 524288) . 'x'));
$secret = 'SECRETLITERAL' . "\xff";
$r = bf_try(fn() => $q->whereEq('s', $secret));
echo 'literal not echoed: ', str_contains($r, 'SECRET') ? 'LEAK' : 'ok', "\n";

echo "-- too many predicates\n";
$c = $q;
for ($n = 1; $n <= 64; $n++) {
    $c = $c->whereGt('i', $n);
}
e('65th predicate', fn() => $c->whereGt('i', 65));
e('65th, whereEq', fn() => $c->whereEq('s', 'x'));
e('65th wins over unknown column', fn() => $c->whereEq('zz', 1));
e('65th wins over type mismatch', fn() => $c->whereEq('i', 'x'));
e('64th still fine', fn() => $c);

function ev(string $decl, $q, string $code)
{
    return eval("$decl return $code;");
}
echo "-- arrays and objects are TypeErrors in both strict modes\n";
$modes = ['weak' => '', 'strict' => 'declare(strict_types=1);'];
$lits = ['array' => '[]', 'nonempty array' => '[1]', 'stdClass' => 'new stdClass', 'closure' => 'fn() => 1',
         'resource' => 'fopen("php://memory", "r")'];
foreach ($modes as $mode => $decl) {
    foreach (['whereEq', 'whereGt'] as $meth) {
        foreach ($lits as $l => $code) {
            foreach (['i', 'f', 's', 'b'] as $col) {
                $r = bf_try(fn() => ev($decl, $q, "\$q->$meth('$col', $code)"));
                if (!preg_match('/^TypeError: BatchFlow\\\\Query::' . $meth . '\(\): Argument #2 \(\$value\) must be of type [a-z|]+, (array|stdClass|Closure|resource \(stream\)|resource) given(, called in .*)?$/', $r)) {
                    echo "$mode $meth $col <- $l: $r\n";
                }
            }
        }
    }
    echo "$mode: array/object/resource literals checked\n";
}
function kind(string $r): string
{
    return str_starts_with($r, 'TypeError: ') ? 'TypeError' : $r;
}
foreach ($modes as $mode => $decl) {
    echo "$mode whereGt string literal: ", kind(bf_try(fn() => ev($decl, $q, "\$q->whereGt('i', '5')"))), "\n";
    echo "$mode whereGt bool literal: ", kind(bf_try(fn() => ev($decl, $q, "\$q->whereGt('i', true)"))), "\n";
    echo "$mode whereGt null literal: ", kind(bf_try(fn() => ev($decl, $q, "\$q->whereGt('i', null)"))), "\n";
    echo "$mode int literal for float column: ", bf_try(fn() => ev($decl, $q, "\$q->whereEq('f', 2) instanceof BatchFlow\\Query") ? 'Query' : 'other'), "\n";
    echo "$mode string literal for int column: ", bf_try(fn() => ev($decl, $q, "\$q->whereEq('i', '2')")), "\n";
}
echo "-- non-string column name\n";
echo bf_try(fn() => $q->whereEq([], 1)), "\n";
?>
--EXPECT--
-- unknown column, before any I/O (the path does not exist)
whereEq unknown                   SchemaException: unknown column "zz"
whereGt unknown                   SchemaException: unknown column "zz"
whereEq empty name                SchemaException: unknown column ""
whereEq case differs              SchemaException: unknown column "I"
whereEq escaped name              SchemaException: unknown column "x\x22\x5C\xFF\x0A"
whereEq 70-byte name              SchemaException: unknown column "cccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccc..."
unknown column wins over type     SchemaException: unknown column "zz"
-- literal type against column type
int64 <- float 1.0                SchemaException: column "i": value of type float cannot be compared with int64 column
int64 <- float 1.5                SchemaException: column "i": value of type float cannot be compared with int64 column
int64 <- string                   SchemaException: column "i": value of type string cannot be compared with int64 column
int64 <- bool                     SchemaException: column "i": value of type bool cannot be compared with int64 column
int64 gt <- float                 SchemaException: column "i": value of type float cannot be compared with int64 column
float64 <- string                 SchemaException: column "f": value of type string cannot be compared with float64 column
float64 <- bool                   SchemaException: column "f": value of type bool cannot be compared with float64 column
float64 gt <- string              TypeError: BatchFlow\Query::whereGt(): Argument #2 ($value) must be of type int|float, string given
bool <- int                       SchemaException: column "b": value of type int cannot be compared with bool column
bool <- int 0                     SchemaException: column "b": value of type int cannot be compared with bool column
bool <- float                     SchemaException: column "b": value of type float cannot be compared with bool column
bool <- string                    SchemaException: column "b": value of type string cannot be compared with bool column
string <- int                     SchemaException: column "s": value of type int cannot be compared with string column
string <- float                   SchemaException: column "s": value of type float cannot be compared with string column
string <- bool                    SchemaException: column "s": value of type bool cannot be compared with string column
escaped column in message         SchemaException: column "a\x22b": value of type string cannot be compared with int64 column
type wins over invalid UTF-8      SchemaException: column "i": value of type string cannot be compared with int64 column
type wins over NaN                SchemaException: column "s": value of type float cannot be compared with string column
type wins over value              SchemaException: column "i": value of type float cannot be compared with int64 column
-- whereGt needs a numeric column
whereGt bool <- int               SchemaException: column "b": whereGt requires a numeric column
whereGt bool <- float             SchemaException: column "b": whereGt requires a numeric column
whereGt string <- int             SchemaException: column "s": whereGt requires a numeric column
whereGt string <- float           SchemaException: column "s": whereGt requires a numeric column
-- literal value
float64 <- NAN                    SchemaException: column "f": float literal must be finite
float64 <- INF                    SchemaException: column "f": float literal must be finite
float64 <- -INF                   SchemaException: column "f": float literal must be finite
float64 gt <- NAN                 SchemaException: column "f": float literal must be finite
float64 gt <- INF                 SchemaException: column "f": float literal must be finite
float64 <- 1.8e308 literal (INF)  SchemaException: column "f": float literal must be finite
float64 <- int 2^53+1             SchemaException: column "f": integer literal is not exactly representable as float64
float64 gt <- int 2^53+1          SchemaException: column "f": integer literal is not exactly representable as float64
float64 <- PHP_INT_MAX            SchemaException: column "f": integer literal is not exactly representable as float64
float64 gt <- PHP_INT_MAX         SchemaException: column "f": integer literal is not exactly representable as float64
float64 <- -(2^53)-1              SchemaException: column "f": integer literal is not exactly representable as float64
string <- invalid UTF-8           SchemaException: column "s": string literal is not valid UTF-8
string <- overlong                SchemaException: column "s": string literal is not valid UTF-8
string <- surrogate               SchemaException: column "s": string literal is not valid UTF-8
string <- truncated               SchemaException: column "s": string literal is not valid UTF-8
string <- 1048577 bytes           SchemaException: column "s": string literal exceeds 1048576 bytes
string <- 1048577 bytes (2-byte)  SchemaException: column "s": string literal exceeds 1048576 bytes
literal not echoed: ok
-- too many predicates
65th predicate                    SchemaException: too many predicates (limit 64)
65th, whereEq                     SchemaException: too many predicates (limit 64)
65th wins over unknown column     SchemaException: too many predicates (limit 64)
65th wins over type mismatch      SchemaException: too many predicates (limit 64)
64th still fine                   Query
-- arrays and objects are TypeErrors in both strict modes
weak: array/object/resource literals checked
strict: array/object/resource literals checked
weak whereGt string literal: TypeError
weak whereGt bool literal: TypeError
weak whereGt null literal: TypeError
weak int literal for float column: Query
weak string literal for int column: SchemaException: column "i": value of type string cannot be compared with int64 column
strict whereGt string literal: TypeError
strict whereGt bool literal: TypeError
strict whereGt null literal: TypeError
strict int literal for float column: Query
strict string literal for int column: SchemaException: column "i": value of type string cannot be compared with int64 column
-- non-string column name
TypeError: BatchFlow\Query::whereEq(): Argument #1 ($column) must be of type string, array given
