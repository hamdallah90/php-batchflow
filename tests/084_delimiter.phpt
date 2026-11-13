--TEST--
batchflow: the delimiter option over the framing, quoting and header cases for each of , ; tab |, against the reference; the other delimiter bytes are data; value grammars ignore the delimiter; error positions; option errors; scanStream; default unchanged
--EXTENSIONS--
batchflow
--FILE--
<?php
require __DIR__ . '/bf_helper.inc';
require __DIR__ . '/bf_reference_m2b.inc';

use BatchFlow\{Csv, Table};

const DELIMS = [',' => 'comma', ';' => 'semicolon', "\t" => 'tab', '|' => 'pipe'];

$S = ['n' => 'int64', 's' => 'string'];
$S1 = ['n' => 'int64'];
// {D} is the delimiter; no fixture holds any of the four delimiter bytes otherwise.
$fixtures = [
    'LF' => ["n{D}s\n1{D}x\n2{D}y\n", $S],
    'CRLF' => ["n{D}s\r\n1{D}x\r\n2{D}y\r\n", $S],
    'mixed LF and CRLF' => ["n{D}s\n1{D}x\r\n2{D}y\n3{D}z\r\n", $S],
    'no final terminator' => ["n{D}s\n1{D}x\n2{D}y", $S],
    'header only' => ["n{D}s\n", $S],
    'header only, no terminator' => ["n{D}s", $S],
    'empty input' => ["", $S],
    'BOM only' => ["\xEF\xBB\xBF", $S],
    'one column, blank line is NULL' => ["n\n1\n\n2\n", $S1],
    'blank line with two columns' => ["n{D}s\n1{D}x\n\n2{D}y\n", $S],
    'empty fields are NULL' => ["n{D}s\n{D}\n1{D}\n{D}x\n", $S],
    'final lone CR' => ["n{D}s\n1{D}x\r", $S],
    'interior bare CR' => ["n{D}s\n1{D}x\ry\n", $S],
    'too many fields' => ["n{D}s\n1{D}x{D}z\n", $S],
    'too few fields' => ["n{D}s\n1\n", $S],
    'trailing delimiter' => ["n{D}s\n1{D}x{D}\n", $S],
    'bare quote' => ["n{D}s\n1{D}x\"y\n", $S],
    'text after closing quote' => ["n{D}s\n1{D}\"x\"y\n", $S],
    'quote then delimiter' => ["n{D}s\n1{D}\"x\"{D}\n", $S],
    'unterminated quote' => ["n{D}s\n1{D}\"x\n", $S],
    'unterminated quote at EOF' => ["n{D}s\n1{D}\"", $S],
    'quoted delimiter' => ["n{D}s\n1{D}\"x{D}y\"\n2{D}\"{D}\"\n", $S],
    'quoted LF and CRLF, line count' => ["n{D}s\n1{D}\"x\ny\"\n2{D}\"p\r\nq\"\n3{D}bad\"\n", $S],
    'doubled quotes' => ["n{D}s\n1{D}\"say \"\"hi\"\"\"\n2{D}\"\"\"\"\n", $S],
    'quoted number' => ["n{D}s\n\"7\"{D}x\n", $S],
    'quoted empty number' => ["n{D}s\n\"\"{D}x\n", $S],
    'space in a number' => ["n{D}s\n 1{D}x\n", $S],
    'header in another order' => ["s{D}n\nx{D}1\ny{D}2\n", $S],
    'quoted header names' => ["\"n\"{D}\"s\"\n1{D}x\n", $S],
    'duplicate column' => ["n{D}n{D}s\n1{D}2{D}x\n", $S],
    'unexpected column' => ["n{D}s{D}t\n1{D}x{D}y\n", $S],
    'missing column' => ["n\n1\n", $S],
    'empty header field' => ["n{D}{D}s\n1{D}2{D}x\n", $S],
    'BOM then header' => ["\xEF\xBB\xBFn{D}s\n1{D}x\n", $S],
    'second BOM is data' => ["n{D}s\n1{D}\xEF\xBB\xBFx\n", $S],
    'BOM before a quoted name' => ["\xEF\xBB\xBF\"n\"{D}s\n1{D}x\n", $S],
];
$extra = [
    'extra columns, repeats and empty names' => ["z{D}n{D}{D}s{D}z\nq{D}1{D}{D}x{D}w\n", $S],
    'extra column with a quoted delimiter' => ["n{D}\"e{D}e\"{D}s\n1{D}\"a{D}b\"{D}x\n", $S],
];
$limits = [
    'row exactly at maxRowBytes' => ["n{D}s\n1{D}abcd\n", $S, ['maxRowBytes' => 6]],
    'row one past maxRowBytes' => ["n{D}s\n1{D}abcde\n", $S, ['maxRowBytes' => 6]],
    'field at maxFieldBytes and past' => ["n{D}s\n1{D}abc\n2{D}abcd\n", $S, ['maxFieldBytes' => 3]],
    'maxInputRows' => ["n{D}s\n1{D}x\n2{D}y\n", $S, ['maxInputRows' => 1]],
];

/** The rendered result of each term, ext and reference, for one delimiter. */
function run_all(string $csv, array $schema, array $opts): array
{
    $out = [];
    $n = 0;
    foreach ([['count', [], null], ['sum', [], 'n'], ['count', [['eq', 'n', 1]], null], ['summary', [['ne', 'n', null]], 'n']] as [$term, $preds, $col]) {
        if ($col !== null && !isset($schema[$col])) {
            continue;
        }
        $a = bf_exec($csv, $schema, $opts, $preds, $term, $col);
        $b = bf_ref($csv, $schema, $opts, $preds, $term, $col);
        $out[] = $a === $b ? $a : "DIFF ext=[$a] ref=[$b]";
    }
    if (isset($schema['s'])) {
        $x = ['key' => 's'];
        $a = bf_gexec($csv, $schema, $opts, [], 'groupCount', $x);
        $b = bf_gref($csv, $schema, $opts, [], 'groupCount', $x);
        $out[] = $a === $b ? $a : "DIFF ext=[$a] ref=[$b]";
        $x = ['keys' => ['s', 'n'], 'aggs' => [['count', 's']]];
        $a = bf_gexec($csv, $schema, $opts, [], 'groupAgg', $x);
        $b = bf_gref($csv, $schema, $opts, [], 'groupAgg', $x);
        $out[] = $a === $b ? $a : "DIFF ext=[$a] ref=[$b]";
    }
    return $out;
}

/** Shows the delimiter inside rendered string values as {D}, so the four runs of one fixture compare. */
function norm(string $s, string $d): string
{
    $j = substr(json_encode($d), 1, -1);
    return preg_replace_callback('/s"(?:[^"\\\\]|\\\\.)*"/', fn($m) => str_replace($j, '{D}', $m[0]), $s);
}

$all = $fixtures + $extra + $limits;
$comparisons = 0;
foreach ($all as $label => $f) {
    [$tpl, $schema] = $f;
    $opts0 = $f[2] ?? [];
    if (isset($extra[$label])) {
        $opts0['allowExtraColumns'] = true;
    }
    $per = [];
    foreach (DELIMS as $d => $dn) {
        $res = run_all(str_replace('{D}', $d, $tpl), $schema, $opts0 + ['delimiter' => $d]);
        $comparisons += count($res);
        $per[$dn] = array_map(fn($r) => norm($r, $d), $res);
    }
    $agree = count(array_unique(array_map('serialize', $per))) === 1;
    echo "== $label", $agree ? '' : '   [DELIMITERS DISAGREE]', "\n";
    foreach ($agree ? ['all' => $per['comma']] : $per as $dn => $res) {
        foreach ($res as $r) {
            echo $agree ? '' : "  $dn: ", "  $r\n";
        }
    }
}
echo "comparisons against the reference: $comparisons\n";

echo "-- 4096 fields fit a header, a 4097th is refused at its delimiter, for every delimiter\n";
foreach (DELIMS as $d => $dn) {
    $h4096 = 'n' . $d . 's' . str_repeat($d . 'e', 4094);
    $h4097 = $h4096 . $d . 'e';
    echo str_pad($dn, 10), bf_try(fn() => bf_q("$h4096\n", $S, ['delimiter' => $d, 'allowExtraColumns' => true])->count()), ' | ',
        bf_try(fn() => bf_q("$h4097\n", $S, ['delimiter' => $d, 'allowExtraColumns' => true])->count()), "\n";
}

echo "-- the other three delimiter bytes are data, unquoted and quoted\n";
foreach (DELIMS as $d => $dn) {
    $others = implode('', array_diff(array_keys(DELIMS), [$d]));
    $csv = "n{$d}s\n1{$d}a{$others}b\n2{$d}\"q{$others}\"\n";
    echo str_pad($dn, 10), bf_gexec($csv, $S, ['delimiter' => $d], [], 'groupCount', ['key' => 's']),
        '  ', bf_gcmp($csv, $S, ['delimiter' => $d], [], 'groupCount', ['key' => 's']), "\n";
}
echo "-- a comma is data in a ; file even in a header name\n";
echo bf_try(fn() => bf_q("a,b;c\n1;x\n", ['a,b' => 'int64', 'c' => 'string'], ['delimiter' => ';'])->sum('a,b')), "\n";

echo "-- value grammars do not depend on the delimiter\n";
$F = ['n' => 'int64', 'f' => 'float64'];
echo "1,5 in a ; file:    ", bf_exec("n;f\n1;1,5\n", $F, ['delimiter' => ';'], [], 'sum', 'f'), "\n";
echo "1.5 in a ; file:    ", bf_exec("n;f\n1;1.5\n", $F, ['delimiter' => ';'], [], 'sum', 'f'), "\n";
echo "space in a tab file: ", bf_exec("n\tf\n1\t 1.5\n", $F, ['delimiter' => "\t"], [], 'sum', 'f'), "\n";
echo "1|5 int in a , file: ", bf_exec("n,f\n1|5,1\n", $F, ['delimiter' => ','], [], 'sum', 'n'), "\n";
echo "tab in a | string:  ", bf_gexec("n|s\n1|a\tb\n", $S, ['delimiter' => '|'], [], 'groupCount', ['key' => 's']), "\n";

echo "-- error messages and offsets with each delimiter\n";
foreach (DELIMS as $d => $dn) {
    $csv = "n{$d}s\n1{$d}ok\n\"x\"\"\n2{$d}y\n";
    echo str_pad($dn, 10), bf_exec("n{$d}s\n1{$d}ok\n22{$d}\"x\"y\n", $S, ['delimiter' => $d]), "\n";
    echo str_pad('', 10), bf_exec("n{$d}s\n1{$d}ok\nzz{$d}y\n", $S, ['delimiter' => $d], [], 'sum', 'n'), "\n";
    echo str_pad('', 10), bf_exec("n{$d}s\n1{$d}ok\n2{$d}y{$d}z\n", $S, ['delimiter' => $d]), "\n";
    echo str_pad('', 10), bf_exec("n{$d}s{$d}n\n", $S, ['delimiter' => $d]), "\n";
}

echo "-- the option: exactly one of the four bytes (values shown in hex)\n";
foreach ([', ', "\x00", '\t', '', ';;', "\n", '"', ' ', "\r", 'a', ',,'] as $bad) {
    echo str_pad(bin2hex($bad) === '' ? '(empty)' : bin2hex($bad), 10), bf_try(fn() => Csv::scan('/nonexistent/x.csv', $S, ['delimiter' => $bad])), "\n";
}
foreach (['int 44' => 44, 'null' => null, 'true' => true, 'float 1.0' => 1.0, "[';']" => [';'], 'int 59' => ord(';')] as $label => $bad) {
    echo str_pad($label, 10), bf_try(fn() => Csv::scan('/nonexistent/x.csv', $S, ['delimiter' => $bad])), "\n";
}
echo "-- options are checked in array order\n";
echo bf_try(fn() => Csv::scan('/nonexistent/x.csv', $S, ['delimiter' => '', 'nope' => 1])), "\n";
echo bf_try(fn() => Csv::scan('/nonexistent/x.csv', $S, ['nope' => 1, 'delimiter' => ''])), "\n";
echo bf_try(fn() => Csv::scan('/nonexistent/x.csv', $S, ['maxRowBytes' => 0, 'delimiter' => 7])), "\n";
echo "-- the option is accepted by every source and the Table loaders\n";
foreach (DELIMS as $d => $dn) {
    $q = Csv::scanStream(bf_mem("n{$d}s\n1{$d}x\n2{$d}y\n"), $S, ['delimiter' => $d]);
    echo str_pad($dn, 10), 'scanStream sum ', bf_try(fn() => $q->sum('n')), ', scan count ', bf_try(fn() => bf_q("n{$d}s\n1{$d}x\n", $S, ['delimiter' => $d])->count()), "\n";
}
echo bf_try(fn() => Table::fromCsv(bf_file("n;s\n1;x\n"), $S, ['delimiter' => ';'])->rowCount()), "\n";
echo bf_try(fn() => Table::fromStream(bf_mem("n;s\n1;x\n"), $S, ['delimiter' => ':'])), "\n";
echo "-- the default is the comma, and an explicit comma is the same\n";
echo bf_try(fn() => bf_q("n;s\n1;x\n", ['n;s' => 'string'])->groupCount('n;s')[0][0]), "\n";
echo bf_try(fn() => bf_q("n,s\n1,x\n", $S)->sum('n')), ' ', bf_try(fn() => bf_q("n,s\n1,x\n", $S, ['delimiter' => ','])->sum('n')), "\n";
echo bf_try(fn() => bf_q("n;s\n1;x\n", $S)->count()), "\n";
?>
--EXPECT--
== LF
  int(2)
  int(3)
  int(1)
  Summary{scanned=2;matched=2;sum=int(3)}
  [s"x",int(1)]; [s"y",int(1)]
  [s"x",int(1),int(1),int(1)]; [s"y",int(2),int(1),int(1)]
== CRLF
  int(2)
  int(3)
  int(1)
  Summary{scanned=2;matched=2;sum=int(3)}
  [s"x",int(1)]; [s"y",int(1)]
  [s"x",int(1),int(1),int(1)]; [s"y",int(2),int(1),int(1)]
== mixed LF and CRLF
  int(3)
  int(6)
  int(1)
  Summary{scanned=3;matched=3;sum=int(6)}
  [s"x",int(1)]; [s"y",int(1)]; [s"z",int(1)]
  [s"x",int(1),int(1),int(1)]; [s"y",int(2),int(1),int(1)]; [s"z",int(3),int(1),int(1)]
== no final terminator
  int(2)
  int(3)
  int(1)
  Summary{scanned=2;matched=2;sum=int(3)}
  [s"x",int(1)]; [s"y",int(1)]
  [s"x",int(1),int(1),int(1)]; [s"y",int(2),int(1),int(1)]
== header only
  int(0)
  int(0)
  int(0)
  Summary{scanned=0;matched=0;sum=int(0)}
  []
  []
== header only, no terminator
  int(0)
  int(0)
  int(0)
  Summary{scanned=0;matched=0;sum=int(0)}
  []
  []
== empty input
  CsvException: missing header
  CsvException: missing header
  CsvException: missing header
  CsvException: missing header
  CsvException: missing header
  CsvException: missing header
== BOM only
  CsvException: missing header
  CsvException: missing header
  CsvException: missing header
  CsvException: missing header
  CsvException: missing header
  CsvException: missing header
== one column, blank line is NULL
  int(3)
  int(3)
  int(1)
  Summary{scanned=3;matched=2;sum=int(3)}
== blank line with two columns
  CsvException: row 2 (line 3, offset 8): expected 2 fields, got 1
  CsvException: row 2 (line 3, offset 8): expected 2 fields, got 1
  CsvException: row 2 (line 3, offset 8): expected 2 fields, got 1
  CsvException: row 2 (line 3, offset 8): expected 2 fields, got 1
  CsvException: row 2 (line 3, offset 8): expected 2 fields, got 1
  CsvException: row 2 (line 3, offset 8): expected 2 fields, got 1
== empty fields are NULL
  int(3)
  int(1)
  int(1)
  Summary{scanned=3;matched=1;sum=int(1)}
  [NULL,int(2)]; [s"x",int(1)]
  [NULL,NULL,int(1),int(0)]; [NULL,int(1),int(1),int(0)]; [s"x",NULL,int(1),int(1)]
== final lone CR
  CsvException: row 1 (line 2, offset 4): bare CR
  CsvException: row 1 (line 2, offset 4): bare CR
  CsvException: row 1 (line 2, offset 4): bare CR
  CsvException: row 1 (line 2, offset 4): bare CR
  CsvException: row 1 (line 2, offset 4): bare CR
  CsvException: row 1 (line 2, offset 4): bare CR
== interior bare CR
  CsvException: row 1 (line 2, offset 4): bare CR
  CsvException: row 1 (line 2, offset 4): bare CR
  CsvException: row 1 (line 2, offset 4): bare CR
  CsvException: row 1 (line 2, offset 4): bare CR
  CsvException: row 1 (line 2, offset 4): bare CR
  CsvException: row 1 (line 2, offset 4): bare CR
== too many fields
  CsvException: row 1 (line 2, offset 4): expected 2 fields, got more
  CsvException: row 1 (line 2, offset 4): expected 2 fields, got more
  CsvException: row 1 (line 2, offset 4): expected 2 fields, got more
  CsvException: row 1 (line 2, offset 4): expected 2 fields, got more
  CsvException: row 1 (line 2, offset 4): expected 2 fields, got more
  CsvException: row 1 (line 2, offset 4): expected 2 fields, got more
== too few fields
  CsvException: row 1 (line 2, offset 4): expected 2 fields, got 1
  CsvException: row 1 (line 2, offset 4): expected 2 fields, got 1
  CsvException: row 1 (line 2, offset 4): expected 2 fields, got 1
  CsvException: row 1 (line 2, offset 4): expected 2 fields, got 1
  CsvException: row 1 (line 2, offset 4): expected 2 fields, got 1
  CsvException: row 1 (line 2, offset 4): expected 2 fields, got 1
== trailing delimiter
  CsvException: row 1 (line 2, offset 4): expected 2 fields, got more
  CsvException: row 1 (line 2, offset 4): expected 2 fields, got more
  CsvException: row 1 (line 2, offset 4): expected 2 fields, got more
  CsvException: row 1 (line 2, offset 4): expected 2 fields, got more
  CsvException: row 1 (line 2, offset 4): expected 2 fields, got more
  CsvException: row 1 (line 2, offset 4): expected 2 fields, got more
== bare quote
  CsvException: row 1 (line 2, offset 4): bare quote in unquoted field
  CsvException: row 1 (line 2, offset 4): bare quote in unquoted field
  CsvException: row 1 (line 2, offset 4): bare quote in unquoted field
  CsvException: row 1 (line 2, offset 4): bare quote in unquoted field
  CsvException: row 1 (line 2, offset 4): bare quote in unquoted field
  CsvException: row 1 (line 2, offset 4): bare quote in unquoted field
== text after closing quote
  CsvException: row 1 (line 2, offset 4): text after closing quote
  CsvException: row 1 (line 2, offset 4): text after closing quote
  CsvException: row 1 (line 2, offset 4): text after closing quote
  CsvException: row 1 (line 2, offset 4): text after closing quote
  CsvException: row 1 (line 2, offset 4): text after closing quote
  CsvException: row 1 (line 2, offset 4): text after closing quote
== quote then delimiter
  CsvException: row 1 (line 2, offset 4): expected 2 fields, got more
  CsvException: row 1 (line 2, offset 4): expected 2 fields, got more
  CsvException: row 1 (line 2, offset 4): expected 2 fields, got more
  CsvException: row 1 (line 2, offset 4): expected 2 fields, got more
  CsvException: row 1 (line 2, offset 4): expected 2 fields, got more
  CsvException: row 1 (line 2, offset 4): expected 2 fields, got more
== unterminated quote
  CsvException: row 1 (line 2, offset 4): unterminated quoted field
  CsvException: row 1 (line 2, offset 4): unterminated quoted field
  CsvException: row 1 (line 2, offset 4): unterminated quoted field
  CsvException: row 1 (line 2, offset 4): unterminated quoted field
  CsvException: row 1 (line 2, offset 4): unterminated quoted field
  CsvException: row 1 (line 2, offset 4): unterminated quoted field
== unterminated quote at EOF
  CsvException: row 1 (line 2, offset 4): unterminated quoted field
  CsvException: row 1 (line 2, offset 4): unterminated quoted field
  CsvException: row 1 (line 2, offset 4): unterminated quoted field
  CsvException: row 1 (line 2, offset 4): unterminated quoted field
  CsvException: row 1 (line 2, offset 4): unterminated quoted field
  CsvException: row 1 (line 2, offset 4): unterminated quoted field
== quoted delimiter
  int(2)
  int(3)
  int(1)
  Summary{scanned=2;matched=2;sum=int(3)}
  [s"x{D}y",int(1)]; [s"{D}",int(1)]
  [s"x{D}y",int(1),int(1),int(1)]; [s"{D}",int(2),int(1),int(1)]
== quoted LF and CRLF, line count
  CsvException: row 3 (line 6, offset 21): bare quote in unquoted field
  CsvException: row 3 (line 6, offset 21): bare quote in unquoted field
  CsvException: row 3 (line 6, offset 21): bare quote in unquoted field
  CsvException: row 3 (line 6, offset 21): bare quote in unquoted field
  CsvException: row 3 (line 6, offset 21): bare quote in unquoted field
  CsvException: row 3 (line 6, offset 21): bare quote in unquoted field
== doubled quotes
  int(2)
  int(3)
  int(1)
  Summary{scanned=2;matched=2;sum=int(3)}
  [s"say \"hi\"",int(1)]; [s"\"",int(1)]
  [s"say \"hi\"",int(1),int(1),int(1)]; [s"\"",int(2),int(1),int(1)]
== quoted number
  int(1)
  int(7)
  int(0)
  Summary{scanned=1;matched=1;sum=int(7)}
  [s"x",int(1)]
  [s"x",int(7),int(1),int(1)]
== quoted empty number
  int(1)
  CsvException: row 1 (line 2, offset 4): column "n": expected int64, got quoted empty field
  CsvException: row 1 (line 2, offset 4): column "n": expected int64, got quoted empty field
  CsvException: row 1 (line 2, offset 4): column "n": expected int64, got quoted empty field
  [s"x",int(1)]
  CsvException: row 1 (line 2, offset 4): column "n": expected int64, got quoted empty field
== space in a number
  int(1)
  CsvException: row 1 (line 2, offset 4): column "n": expected int64, got malformed value
  CsvException: row 1 (line 2, offset 4): column "n": expected int64, got malformed value
  CsvException: row 1 (line 2, offset 4): column "n": expected int64, got malformed value
  [s"x",int(1)]
  CsvException: row 1 (line 2, offset 4): column "n": expected int64, got malformed value
== header in another order
  int(2)
  int(3)
  int(1)
  Summary{scanned=2;matched=2;sum=int(3)}
  [s"x",int(1)]; [s"y",int(1)]
  [s"x",int(1),int(1),int(1)]; [s"y",int(2),int(1),int(1)]
== quoted header names
  int(1)
  int(1)
  int(1)
  Summary{scanned=1;matched=1;sum=int(1)}
  [s"x",int(1)]
  [s"x",int(1),int(1),int(1)]
== duplicate column
  CsvException: header (line 1, offset 0): duplicate column "n"
  CsvException: header (line 1, offset 0): duplicate column "n"
  CsvException: header (line 1, offset 0): duplicate column "n"
  CsvException: header (line 1, offset 0): duplicate column "n"
  CsvException: header (line 1, offset 0): duplicate column "n"
  CsvException: header (line 1, offset 0): duplicate column "n"
== unexpected column
  CsvException: header (line 1, offset 0): unexpected column at position 3
  CsvException: header (line 1, offset 0): unexpected column at position 3
  CsvException: header (line 1, offset 0): unexpected column at position 3
  CsvException: header (line 1, offset 0): unexpected column at position 3
  CsvException: header (line 1, offset 0): unexpected column at position 3
  CsvException: header (line 1, offset 0): unexpected column at position 3
== missing column
  CsvException: header (line 1, offset 0): missing column "s"
  CsvException: header (line 1, offset 0): missing column "s"
  CsvException: header (line 1, offset 0): missing column "s"
  CsvException: header (line 1, offset 0): missing column "s"
  CsvException: header (line 1, offset 0): missing column "s"
  CsvException: header (line 1, offset 0): missing column "s"
== empty header field
  CsvException: header (line 1, offset 0): unexpected column at position 2
  CsvException: header (line 1, offset 0): unexpected column at position 2
  CsvException: header (line 1, offset 0): unexpected column at position 2
  CsvException: header (line 1, offset 0): unexpected column at position 2
  CsvException: header (line 1, offset 0): unexpected column at position 2
  CsvException: header (line 1, offset 0): unexpected column at position 2
== BOM then header
  int(1)
  int(1)
  int(1)
  Summary{scanned=1;matched=1;sum=int(1)}
  [s"x",int(1)]
  [s"x",int(1),int(1),int(1)]
== second BOM is data
  int(1)
  int(1)
  int(1)
  Summary{scanned=1;matched=1;sum=int(1)}
  [s"﻿x",int(1)]
  [s"﻿x",int(1),int(1),int(1)]
== BOM before a quoted name
  int(1)
  int(1)
  int(1)
  Summary{scanned=1;matched=1;sum=int(1)}
  [s"x",int(1)]
  [s"x",int(1),int(1),int(1)]
== extra columns, repeats and empty names
  int(1)
  int(1)
  int(1)
  Summary{scanned=1;matched=1;sum=int(1)}
  [s"x",int(1)]
  [s"x",int(1),int(1),int(1)]
== extra column with a quoted delimiter
  int(1)
  int(1)
  int(1)
  Summary{scanned=1;matched=1;sum=int(1)}
  [s"x",int(1)]
  [s"x",int(1),int(1),int(1)]
== row exactly at maxRowBytes
  int(1)
  int(1)
  int(1)
  Summary{scanned=1;matched=1;sum=int(1)}
  [s"abcd",int(1)]
  [s"abcd",int(1),int(1),int(1)]
== row one past maxRowBytes
  LimitException: row 1 (line 2, offset 4): row exceeds maxRowBytes
  LimitException: row 1 (line 2, offset 4): row exceeds maxRowBytes
  LimitException: row 1 (line 2, offset 4): row exceeds maxRowBytes
  LimitException: row 1 (line 2, offset 4): row exceeds maxRowBytes
  LimitException: row 1 (line 2, offset 4): row exceeds maxRowBytes
  LimitException: row 1 (line 2, offset 4): row exceeds maxRowBytes
== field at maxFieldBytes and past
  int(2)
  int(3)
  int(1)
  Summary{scanned=2;matched=2;sum=int(3)}
  LimitException: row 2 (line 3, offset 10): column "s": field exceeds maxFieldBytes
  LimitException: row 2 (line 3, offset 10): column "s": field exceeds maxFieldBytes
== maxInputRows
  LimitException: row 2 (line 3, offset 8): input exceeds maxInputRows
  LimitException: row 2 (line 3, offset 8): input exceeds maxInputRows
  LimitException: row 2 (line 3, offset 8): input exceeds maxInputRows
  LimitException: row 2 (line 3, offset 8): input exceeds maxInputRows
  LimitException: row 2 (line 3, offset 8): input exceeds maxInputRows
  LimitException: row 2 (line 3, offset 8): input exceeds maxInputRows
comparisons against the reference: 1000
-- 4096 fields fit a header, a 4097th is refused at its delimiter, for every delimiter
comma     int(0) | CsvException: header (line 1, offset 0): too many columns (limit 4096)
semicolon int(0) | CsvException: header (line 1, offset 0): too many columns (limit 4096)
tab       int(0) | CsvException: header (line 1, offset 0): too many columns (limit 4096)
pipe      int(0) | CsvException: header (line 1, offset 0): too many columns (limit 4096)
-- the other three delimiter bytes are data, unquoted and quoted
comma     [s"a;\t|b",int(1)]; [s"q;\t|",int(1)]  same
semicolon [s"a,\t|b",int(1)]; [s"q,\t|",int(1)]  same
tab       [s"a,;|b",int(1)]; [s"q,;|",int(1)]  same
pipe      [s"a,;\tb",int(1)]; [s"q,;\t",int(1)]  same
-- a comma is data in a ; file even in a header name
int(1)
-- value grammars do not depend on the delimiter
1,5 in a ; file:    CsvException: row 1 (line 2, offset 4): column "f": expected float64, got malformed value
1.5 in a ; file:    float(1.5)
space in a tab file: CsvException: row 1 (line 2, offset 4): column "f": expected float64, got malformed value
1|5 int in a , file: CsvException: row 1 (line 2, offset 4): column "n": expected int64, got malformed value
tab in a | string:  [s"a\tb",int(1)]
-- error messages and offsets with each delimiter
comma     CsvException: row 2 (line 3, offset 9): text after closing quote
          CsvException: row 2 (line 3, offset 9): column "n": expected int64, got malformed value
          CsvException: row 2 (line 3, offset 9): expected 2 fields, got more
          CsvException: header (line 1, offset 0): duplicate column "n"
semicolon CsvException: row 2 (line 3, offset 9): text after closing quote
          CsvException: row 2 (line 3, offset 9): column "n": expected int64, got malformed value
          CsvException: row 2 (line 3, offset 9): expected 2 fields, got more
          CsvException: header (line 1, offset 0): duplicate column "n"
tab       CsvException: row 2 (line 3, offset 9): text after closing quote
          CsvException: row 2 (line 3, offset 9): column "n": expected int64, got malformed value
          CsvException: row 2 (line 3, offset 9): expected 2 fields, got more
          CsvException: header (line 1, offset 0): duplicate column "n"
pipe      CsvException: row 2 (line 3, offset 9): text after closing quote
          CsvException: row 2 (line 3, offset 9): column "n": expected int64, got malformed value
          CsvException: row 2 (line 3, offset 9): expected 2 fields, got more
          CsvException: header (line 1, offset 0): duplicate column "n"
-- the option: exactly one of the four bytes (values shown in hex)
2c20      SchemaException: option "delimiter": out of range
00        SchemaException: option "delimiter": out of range
5c74      SchemaException: option "delimiter": out of range
(empty)   SchemaException: option "delimiter": out of range
3b3b      SchemaException: option "delimiter": out of range
0a        SchemaException: option "delimiter": out of range
22        SchemaException: option "delimiter": out of range
20        SchemaException: option "delimiter": out of range
0d        SchemaException: option "delimiter": out of range
61        SchemaException: option "delimiter": out of range
2c2c      SchemaException: option "delimiter": out of range
int 44    SchemaException: option "delimiter": wrong type
null      SchemaException: option "delimiter": wrong type
true      SchemaException: option "delimiter": wrong type
float 1.0 SchemaException: option "delimiter": wrong type
[';']     SchemaException: option "delimiter": wrong type
int 59    SchemaException: option "delimiter": wrong type
-- options are checked in array order
SchemaException: option "delimiter": out of range
SchemaException: option "nope": unknown option
SchemaException: option "maxRowBytes": out of range
-- the option is accepted by every source and the Table loaders
comma     scanStream sum int(3), scan count int(1)
semicolon scanStream sum int(3), scan count int(1)
tab       scanStream sum int(3), scan count int(1)
pipe      scanStream sum int(3), scan count int(1)
int(1)
SchemaException: option "delimiter": out of range
-- the default is the comma, and an explicit comma is the same
1;x
int(1) int(1)
CsvException: header (line 1, offset 0): unexpected column at position 1
