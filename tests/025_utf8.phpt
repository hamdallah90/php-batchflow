--TEST--
batchflow: UTF-8 validation of string columns - overlong, surrogates, above U+10FFFF, truncation, read and chunk boundaries
--EXTENSIONS--
batchflow
--FILE--
<?php
require __DIR__ . '/bf_helper.inc';

use BatchFlow\Csv;

$diffs = 0;
$S = ['s' => 'string'];
// Referencing the column makes the extension validate it: IS NULL never matches these rows.
$P = [['eq', 's', null]];

function u(string $label, string $csv): void
{
    global $diffs, $S, $P;
    $r = bf_exec($csv, $S, [], $P);
    if ($r !== bf_ref($csv, $S, [], $P)) {
        $diffs++;
        $r .= '   [DIFFERS FROM REFERENCE]';
    }
    echo str_pad($label, 40), $r, "\n";
}

echo "-- valid sequences\n";
$valid = [
    'ASCII' => 'abc', '2-byte U+00E9' => "\xC3\xA9", 'U+0080' => "\xC2\x80", 'U+07FF' => "\xDF\xBF", '3-byte U+20AC' => "\xE2\x82\xAC",
    'U+0800' => "\xE0\xA0\x80", 'U+D7FF' => "\xED\x9F\xBF", 'U+E000' => "\xEE\x80\x80", 'U+FFFD' => "\xEF\xBF\xBD",
    'U+FFFF' => "\xEF\xBF\xBF", '4-byte U+1F600' => "\xF0\x9F\x98\x80", 'U+10000' => "\xF0\x90\x80\x80", 'U+10FFFF' => "\xF4\x8F\xBF\xBF",
    'mixed' => "a\xC3\xA9\xE2\x82\xAC\xF0\x9F\x98\x80z",
];
foreach ($valid as $l => $v) {
    u("valid $l", "s\n$v\n");
}
u('valid in quotes', "s\n\"\xC3\xA9,\xF0\x9F\x98\x80\"\n");

echo "-- invalid sequences\n";
$invalid = [
    'lone continuation 80' => "\x80", 'lone continuation BF' => "\xBF", 'FF' => "\xFF", 'FE' => "\xFE",
    'overlong C0 80' => "\xC0\x80", 'overlong C1 BF' => "\xC1\xBF", 'overlong E0 80 80' => "\xE0\x80\x80", 'overlong E0 9F BF' => "\xE0\x9F\xBF",
    'overlong F0 80 80 80' => "\xF0\x80\x80\x80", 'overlong F0 8F BF BF' => "\xF0\x8F\xBF\xBF",
    'surrogate ED A0 80' => "\xED\xA0\x80", 'surrogate ED BF BF' => "\xED\xBF\xBF",
    'above U+10FFFF F4 90 80 80' => "\xF4\x90\x80\x80", 'F5 80 80 80' => "\xF5\x80\x80\x80", 'F8 88 80 80 80' => "\xF8\x88\x80\x80\x80",
    'truncated 2-byte C3' => "\xC3", 'truncated 3-byte E2 82' => "\xE2\x82", 'truncated 3-byte E2' => "\xE2",
    'truncated 4-byte F0 9F 98' => "\xF0\x9F\x98", 'truncated 4-byte F0' => "\xF0",
    'bad continuation C3 28' => "\xC3\x28", 'bad continuation E2 28 A1' => "\xE2\x28\xA1", 'bad continuation E2 82 28' => "\xE2\x82\x28",
    'bad continuation F0 9F 98 28' => "\xF0\x9F\x98\x28", 'C3 followed by ASCII' => "\xC3" . 'a',
];
foreach ($invalid as $l => $v) {
    u("invalid $l", "s\n$v\n");
    u("invalid $l (quoted)", "s\n\"$v\"\n");
}
u('invalid at the end, no terminator', "s\n\xE2\x82");
u('invalid between valid rows', "s\nok\n\xff\nok\n");
$S2 = ['a' => 'int64', 's' => 'string'];
echo str_pad('invalid in the second field', 40), bf_exec("a,s\n1,\xC0\x80\n", $S2, [], $P), "\n";
echo str_pad('invalid in row 3 of 3', 40), bf_exec("a,s\n1,x\n2,y\n3,\xED\xA0\x80\n", $S2, [], [['eq', 's', 'x']]), "\n";

echo "-- across a read boundary (userland wrapper, reads of 1..7 bytes)\n";
$cases = ["s\n\xF0\x9F\x98\x80\n", "s\n\xE2\x82\xAC\n", "s\n\xE2\x82\n", "s\nab\xC0\x80cd\n", "s\n\xF4\x8F\xBF\xBF\n", "s\n\xF4\x90\x80\x80\n"];
foreach ($cases as $i => $csv) {
    $want = bf_ref($csv, $S, [], $P);
    $same = 0;
    foreach (range(1, 7) as $max) {
        $got = bf_try(fn() => Csv::scanStream(bf_wrap_stream($csv, $max), $S)->whereEq('s', null)->count());
        $same += $got === $want ? 1 : 0;
    }
    echo "case $i: $same of 7 read sizes agree with the reference ($want)\n";
}

echo "-- a multi-byte character straddling a 65536-byte chunk boundary\n";
$bad = 0;
$total = 0;
foreach ([65536, 65536 * 2] as $edge) {
    foreach (["\xF0\x9F\x98\x80", "\xE2\x82\xAC", "\xC3\xA9", "\xF0\x9F\x98", "\xE2\x82", "\xC3"] as $ch) {
        for ($pad = 1; $pad <= 5; $pad++) {
            // header "s\n" (2 bytes) + the value starting $pad bytes before the edge
            $start = $edge - $pad;
            $value = str_repeat('x', $start - 2) . $ch;
            foreach (['', 'tail'] as $tail) {
                $csv = "s\n" . $value . $tail . "\n";
                $total++;
                if (bf_exec($csv, $S, [], $P) !== bf_ref($csv, $S, [], $P)) {
                    $bad++;
                }
            }
        }
    }
}
echo "boundary fixtures: $total, disagreements: $bad\n";
echo "reference disagreements: $diffs\n";
?>
--EXPECT--
-- valid sequences
valid ASCII                             int(0)
valid 2-byte U+00E9                     int(0)
valid U+0080                            int(0)
valid U+07FF                            int(0)
valid 3-byte U+20AC                     int(0)
valid U+0800                            int(0)
valid U+D7FF                            int(0)
valid U+E000                            int(0)
valid U+FFFD                            int(0)
valid U+FFFF                            int(0)
valid 4-byte U+1F600                    int(0)
valid U+10000                           int(0)
valid U+10FFFF                          int(0)
valid mixed                             int(0)
valid in quotes                         int(0)
-- invalid sequences
invalid lone continuation 80            CsvException: row 1 (line 2, offset 2): column "s": invalid UTF-8
invalid lone continuation 80 (quoted)   CsvException: row 1 (line 2, offset 2): column "s": invalid UTF-8
invalid lone continuation BF            CsvException: row 1 (line 2, offset 2): column "s": invalid UTF-8
invalid lone continuation BF (quoted)   CsvException: row 1 (line 2, offset 2): column "s": invalid UTF-8
invalid FF                              CsvException: row 1 (line 2, offset 2): column "s": invalid UTF-8
invalid FF (quoted)                     CsvException: row 1 (line 2, offset 2): column "s": invalid UTF-8
invalid FE                              CsvException: row 1 (line 2, offset 2): column "s": invalid UTF-8
invalid FE (quoted)                     CsvException: row 1 (line 2, offset 2): column "s": invalid UTF-8
invalid overlong C0 80                  CsvException: row 1 (line 2, offset 2): column "s": invalid UTF-8
invalid overlong C0 80 (quoted)         CsvException: row 1 (line 2, offset 2): column "s": invalid UTF-8
invalid overlong C1 BF                  CsvException: row 1 (line 2, offset 2): column "s": invalid UTF-8
invalid overlong C1 BF (quoted)         CsvException: row 1 (line 2, offset 2): column "s": invalid UTF-8
invalid overlong E0 80 80               CsvException: row 1 (line 2, offset 2): column "s": invalid UTF-8
invalid overlong E0 80 80 (quoted)      CsvException: row 1 (line 2, offset 2): column "s": invalid UTF-8
invalid overlong E0 9F BF               CsvException: row 1 (line 2, offset 2): column "s": invalid UTF-8
invalid overlong E0 9F BF (quoted)      CsvException: row 1 (line 2, offset 2): column "s": invalid UTF-8
invalid overlong F0 80 80 80            CsvException: row 1 (line 2, offset 2): column "s": invalid UTF-8
invalid overlong F0 80 80 80 (quoted)   CsvException: row 1 (line 2, offset 2): column "s": invalid UTF-8
invalid overlong F0 8F BF BF            CsvException: row 1 (line 2, offset 2): column "s": invalid UTF-8
invalid overlong F0 8F BF BF (quoted)   CsvException: row 1 (line 2, offset 2): column "s": invalid UTF-8
invalid surrogate ED A0 80              CsvException: row 1 (line 2, offset 2): column "s": invalid UTF-8
invalid surrogate ED A0 80 (quoted)     CsvException: row 1 (line 2, offset 2): column "s": invalid UTF-8
invalid surrogate ED BF BF              CsvException: row 1 (line 2, offset 2): column "s": invalid UTF-8
invalid surrogate ED BF BF (quoted)     CsvException: row 1 (line 2, offset 2): column "s": invalid UTF-8
invalid above U+10FFFF F4 90 80 80      CsvException: row 1 (line 2, offset 2): column "s": invalid UTF-8
invalid above U+10FFFF F4 90 80 80 (quoted)CsvException: row 1 (line 2, offset 2): column "s": invalid UTF-8
invalid F5 80 80 80                     CsvException: row 1 (line 2, offset 2): column "s": invalid UTF-8
invalid F5 80 80 80 (quoted)            CsvException: row 1 (line 2, offset 2): column "s": invalid UTF-8
invalid F8 88 80 80 80                  CsvException: row 1 (line 2, offset 2): column "s": invalid UTF-8
invalid F8 88 80 80 80 (quoted)         CsvException: row 1 (line 2, offset 2): column "s": invalid UTF-8
invalid truncated 2-byte C3             CsvException: row 1 (line 2, offset 2): column "s": invalid UTF-8
invalid truncated 2-byte C3 (quoted)    CsvException: row 1 (line 2, offset 2): column "s": invalid UTF-8
invalid truncated 3-byte E2 82          CsvException: row 1 (line 2, offset 2): column "s": invalid UTF-8
invalid truncated 3-byte E2 82 (quoted) CsvException: row 1 (line 2, offset 2): column "s": invalid UTF-8
invalid truncated 3-byte E2             CsvException: row 1 (line 2, offset 2): column "s": invalid UTF-8
invalid truncated 3-byte E2 (quoted)    CsvException: row 1 (line 2, offset 2): column "s": invalid UTF-8
invalid truncated 4-byte F0 9F 98       CsvException: row 1 (line 2, offset 2): column "s": invalid UTF-8
invalid truncated 4-byte F0 9F 98 (quoted)CsvException: row 1 (line 2, offset 2): column "s": invalid UTF-8
invalid truncated 4-byte F0             CsvException: row 1 (line 2, offset 2): column "s": invalid UTF-8
invalid truncated 4-byte F0 (quoted)    CsvException: row 1 (line 2, offset 2): column "s": invalid UTF-8
invalid bad continuation C3 28          CsvException: row 1 (line 2, offset 2): column "s": invalid UTF-8
invalid bad continuation C3 28 (quoted) CsvException: row 1 (line 2, offset 2): column "s": invalid UTF-8
invalid bad continuation E2 28 A1       CsvException: row 1 (line 2, offset 2): column "s": invalid UTF-8
invalid bad continuation E2 28 A1 (quoted)CsvException: row 1 (line 2, offset 2): column "s": invalid UTF-8
invalid bad continuation E2 82 28       CsvException: row 1 (line 2, offset 2): column "s": invalid UTF-8
invalid bad continuation E2 82 28 (quoted)CsvException: row 1 (line 2, offset 2): column "s": invalid UTF-8
invalid bad continuation F0 9F 98 28    CsvException: row 1 (line 2, offset 2): column "s": invalid UTF-8
invalid bad continuation F0 9F 98 28 (quoted)CsvException: row 1 (line 2, offset 2): column "s": invalid UTF-8
invalid C3 followed by ASCII            CsvException: row 1 (line 2, offset 2): column "s": invalid UTF-8
invalid C3 followed by ASCII (quoted)   CsvException: row 1 (line 2, offset 2): column "s": invalid UTF-8
invalid at the end, no terminator       CsvException: row 1 (line 2, offset 2): column "s": invalid UTF-8
invalid between valid rows              CsvException: row 2 (line 3, offset 5): column "s": invalid UTF-8
invalid in the second field             CsvException: row 1 (line 2, offset 4): column "s": invalid UTF-8
invalid in row 3 of 3                   CsvException: row 3 (line 4, offset 12): column "s": invalid UTF-8
-- across a read boundary (userland wrapper, reads of 1..7 bytes)
case 0: 7 of 7 read sizes agree with the reference (int(0))
case 1: 7 of 7 read sizes agree with the reference (int(0))
case 2: 7 of 7 read sizes agree with the reference (CsvException: row 1 (line 2, offset 2): column "s": invalid UTF-8)
case 3: 7 of 7 read sizes agree with the reference (CsvException: row 1 (line 2, offset 2): column "s": invalid UTF-8)
case 4: 7 of 7 read sizes agree with the reference (int(0))
case 5: 7 of 7 read sizes agree with the reference (CsvException: row 1 (line 2, offset 2): column "s": invalid UTF-8)
-- a multi-byte character straddling a 65536-byte chunk boundary
boundary fixtures: 120, disagreements: 0
reference disagreements: 0
