--TEST--
batchflow: for each delimiter, every available simd value gives the reference's results over the 040 fixture set (each structural byte at every offset of a 64-byte block) and over the other three delimiter bytes as data at every offset
--EXTENSIONS--
batchflow
--FILE--
<?php
require __DIR__ . '/bf_helper.inc';
require __DIR__ . '/bf_reference_m2b.inc';

$modes = array_merge(['auto'], bf_simd_available());
echo 'modes under test include scalar: ', in_array('scalar', $modes, true) ? 'yes' : 'NO', "\n";

$S = ['a' => 'string', 'b' => 'int64'];
const DELIMS = [',' => 'comma', ';' => 'semicolon', "\t" => 'tab', '|' => 'pipe'];

/** The 040 fixtures with {D} for the delimiter, plus the three other delimiter bytes as data at each offset. */
function fixtures(string $d): array
{
    $fx = [];
    $others = array_values(array_diff(array_keys(DELIMS), [$d]));
    for ($pos = 0; $pos <= 130; $pos++) {
        $pad = str_repeat('x', $pos);
        $fx["delimiter at $pos"] = "a{D}b\n{$pad}{D}1\n{$pad}y{D}2\n";
        $fx["quoted delimiter at $pos"] = "a{D}b\n\"{$pad}{D}z\"{D}1\nq{D}2\n";
        $fx["quoted LF at $pos"] = "a{D}b\n\"{$pad}\nz\"{D}1\nq{D}2\n";
        $fx["quoted CRLF at $pos"] = "a{D}b\n\"{$pad}\r\nz\"{D}1\nq{D}2\n";
        $fx["doubled quote at $pos"] = "a{D}b\n\"{$pad}\"\"z\"{D}1\nq{D}2\n";
        $fx["CRLF terminator at $pos"] = "a{D}b\r\n{$pad}{D}1\r\n{$pad}{D}2\r\n";
        $fx["bare CR at $pos"] = "a{D}b\n{$pad}\rz{D}1\n";
        $fx["bare quote at $pos"] = "a{D}b\n{$pad}\"z{D}1\n";
        $fx["closing quote then text at $pos"] = "a{D}b\n\"{$pad}\"z{D}1\n";
        $fx["unterminated quote at $pos"] = "a{D}b\n\"{$pad}";
        $fx["short row at $pos"] = "a{D}b\n{$pad}\n";
        $fx["long row at $pos"] = "a{D}b\n{$pad}{D}1{D}2\n";
        $fx["lone CR at end, offset $pos"] = "a{D}b\n{$pad}{D}1\r";
        foreach ($others as $k => $o) {
            $fx["other delimiter $k at $pos"] = "a{D}b\n{$pad}{$o}w{D}1\n\"{$pad}{$o}\"{D}2\n";
        }
    }
    for ($h = 0; $h < 64; $h++) {
        $rows = '';
        for ($r = 0; $r < 12; $r++) {
            $rows .= str_repeat('v', $r * 5) . "{D}$r\n\"q{D}$r\"{D}$r\n";
        }
        $fx["many rows, header pad $h"] = 'h' . str_repeat('n', $h) . "{D}b\n$rows";
    }
    foreach ($fx as $k => $v) {
        $fx[$k] = str_replace('{D}', $d, $v);
    }
    return $fx;
}

$queries = [['count', [], null], ['summary', [['eq', 'a', 'y']], 'b'], ['sum', [['ne', 'b', null]], 'b']];
foreach (DELIMS as $d => $dn) {
    $total = 0;
    $bad = [];
    foreach (fixtures($d) as $label => $csv) {
        $schema = str_starts_with($label, 'many rows') ? [explode($d, $csv, 2)[0] => 'string', 'b' => 'int64'] : $S;
        $opts = ['delimiter' => $d];
        foreach ($queries as [$term, $preds, $col]) {
            $preds = str_starts_with($label, 'many rows') ? [] : $preds;
            $want = bf_ref($csv, $schema, $opts, $preds, $term, $col);
            foreach ($modes as $m) {
                $total++;
                if (bf_exec($csv, $schema, $opts + ['simd' => $m], $preds, $term, $col) !== $want) {
                    $bad[] = "$label / $term / $m";
                }
            }
        }
        if (str_starts_with($label, 'other delimiter') || str_starts_with($label, 'quoted delimiter')) {
            $want = bf_gref($csv, $schema, ['delimiter' => $d], [], 'groupCount', ['key' => 'a']);
            foreach ($modes as $m) {
                $total++;
                if (bf_gexec($csv, $schema, ['delimiter' => $d, 'simd' => $m], [], 'groupCount', ['key' => 'a']) !== $want) {
                    $bad[] = "$label / groupCount / $m";
                }
            }
        }
    }
    echo str_pad($dn, 10), 'checks: ', $total > 10000 ? 'over 10,000' : $total, ', disagreements with the reference: ', count($bad), "\n";
    foreach (array_slice($bad, 0, 10) as $b) {
        echo "  $b\n";
    }
}

echo "-- byte-identical error messages across modes and delimiters for one fixed bad input per class\n";
$errs = [
    "a{D}b\n" . str_repeat('x', 61) . "\"z{D}1\n",
    "a{D}b\n\"" . str_repeat('x', 62) . "\"z{D}1\n",
    "a{D}b\n" . str_repeat('x', 62) . "\rz{D}1\n",
    "a{D}b\n1{D}2{D}3\n",
];
foreach ($errs as $i => $tpl) {
    $r = [];
    foreach (DELIMS as $d => $dn) {
        foreach ($modes as $m) {
            $r[bf_exec(str_replace('{D}', $d, $tpl), $S, ['simd' => $m, 'delimiter' => $d])] = 1;
        }
    }
    echo "error fixture $i: ", count($r) === 1 ? 'identical in all modes and delimiters: ' . array_key_first($r) : 'DIFFERENT', "\n";
}
?>
--EXPECT--
modes under test include scalar: yes
comma     checks: over 10,000, disagreements with the reference: 0
semicolon checks: over 10,000, disagreements with the reference: 0
tab       checks: over 10,000, disagreements with the reference: 0
pipe      checks: over 10,000, disagreements with the reference: 0
-- byte-identical error messages across modes and delimiters for one fixed bad input per class
error fixture 0: identical in all modes and delimiters: CsvException: row 1 (line 2, offset 4): bare quote in unquoted field
error fixture 1: identical in all modes and delimiters: CsvException: row 1 (line 2, offset 4): text after closing quote
error fixture 2: identical in all modes and delimiters: CsvException: row 1 (line 2, offset 4): bare CR
error fixture 3: identical in all modes and delimiters: CsvException: row 1 (line 2, offset 4): expected 2 fields, got more
