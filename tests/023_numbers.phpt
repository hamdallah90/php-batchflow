--TEST--
batchflow: int64 and float64 grammar tables, boundaries, correct rounding, overflow to infinity, quoted forms
--EXTENSIONS--
batchflow
--FILE--
<?php
require __DIR__ . '/bf_helper.inc';

$diffs = 0;
/** One-column file holding $field (verbatim, may be quoted), summed. */
function num(string $type, string $field): string
{
    global $diffs;
    $csv = "v\n$field\n";
    $r = bf_exec($csv, ['v' => $type], [], [], 'sum', 'v');
    if ($r !== bf_ref($csv, ['v' => $type], [], [], 'sum', 'v')) {
        $diffs++;
        $r .= '   [DIFFERS FROM REFERENCE]';
    }
    return $r;
}
function show(string $type, array $fields): void
{
    foreach ($fields as $f) {
        echo str_pad($type . ' ' . json_encode($f, JSON_UNESCAPED_SLASHES), 48), num($type, $f), "\n";
    }
}

echo "-- int64 accepted\n";
show('int64', ['0', '1', '-1', '7', '123', '1000000', '9223372036854775807', '-9223372036854775808',
               '9223372036854775806', '-9223372036854775807', '"5"', '"-5"', '"0"']);
echo "-- int64 rejected: malformed\n";
show('int64', ['+5', '007', '00', '-0', '1.0', '1e3', ' 5', '5 ', '--1', '-', '+', 'abc', '0x10', '1_0', '1,5' === '' ? '' : '5a',
               '1.', '.5', '-.5', "\xd9\xa5", '5-', '5e0', 'true', 'NaN', '- 5']);
echo "-- int64 rejected: out of range\n";
show('int64', ['9223372036854775808', '-9223372036854775809', '99999999999999999999', '-99999999999999999999',
               '18446744073709551616', '92233720368547758070', '"9223372036854775808"']);
echo "-- int64 quoted\n";
show('int64', ['""', '"5"""', '"""5"', '"5""5"', '" 5"', '"5 "', '"+5"']);

echo "-- float64 accepted (shortest round-trip rendering of the sum)\n";
show('float64', ['0', '1', '-1', '1.', '1.5', '.5', '-.5', '+1', '+.5', '+1.5', '1e3', '1E3', '1e+3', '1e-3', '.5e1', '1.e2',
                 '00012', '007.5', '-0', '-0.0', '0.0', '1e0', '5e-1', '1.5e300', '"1.5"', '"-2.5e2"', '".5"', '0.1', '0.3',
                 '123456789012345678901234567890', '0.000000000000000000000000000001']);
echo "-- float64 rejected: malformed\n";
show('float64', ['nan', 'NaN', 'inf', '-inf', 'Infinity', '0x1p3', '0x10', '1e', 'e5', '.', '+', '-', '1e+', '1e-', '1 ', ' 1', '1_0',
                 '1.5.5', '--1', '1e3.5', '"1,5"', '1d3', '1f', '+-1', '1e5e5', "\xd9\xa5"]);
echo "-- float64 out of range / underflow\n";
show('float64', ['1e400', '-1e400', '1e309', '1.7976931348623159e308', '1.7976931348623158e308', '1.7976931348623157e308',
                 '1e-400', '-1e-400', '4.9e-324', '2.4703282292062327e-324', '2.4703282292062328e-324', '1e-324', '"1e400"']);
echo "-- float64 quoted\n";
show('float64', ['""', '"1""5"', '"""1"', '"1.5"""']);
echo "-- correct rounding\n";
show('float64', ['9007199254740993', '9007199254740995', '9007199254740992.5', '9007199254740993.0000000000000001',
                 '0.1000000000000000055511151231257827', '1e22', '1e23', '8.5e-5', '123456789.123456789',
                 '2.2250738585072011e-308', '2.2250738585072014e-308', '1.00000000000000011102230246251565404236316680908203125',
                 '1.00000000000000011102230246251565404236316680908203126', '0.30000000000000004']);
echo "-- very long digit strings are linear and correct\n";
echo num('float64', '1' . str_repeat('0', 400)), "\n";
echo num('float64', '0.' . str_repeat('0', 400) . '1'), "\n";
echo num('float64', '1.' . str_repeat('0', 100000) . '1'), "\n";
echo num('float64', str_repeat('9', 100000)), "\n";
echo num('float64', '0.' . str_repeat('3', 100000)), "\n";
echo num('int64', str_repeat('9', 100000)), "\n";

echo "-- random decimal strings against PHP's own strtod\n";
mt_srand(20261109);
$bad = 0;
$n = 0;
for ($i = 0; $i < 1500; $i++) {
    $d = '';
    for ($k = mt_rand(1, 22); $k > 0; $k--) {
        $d .= mt_rand(0, 9);
    }
    $s = (mt_rand(0, 3) === 0 ? '-' : '') . $d;
    if (mt_rand(0, 1)) {
        $s = substr($s, 0, mt_rand(0, strlen($d))) . '.' . substr($s, mt_rand(0, strlen($d)));
    }
    if (mt_rand(0, 2) === 0) {
        $s .= 'e' . mt_rand(-330, 310);
    }
    if (!preg_match('/\A[+-]?([0-9]+(\.[0-9]*)?|\.[0-9]+)([eE][+-]?[0-9]+)?\z/', $s)) {
        continue;
    }
    $want = (float)$s;
    $n++;
    $got = bf_try(fn() => bf_q("v\n$s\n", ['v' => 'float64'])->sum('v'));
    // 0.0 + (-0.0) is +0.0, so a lone negative zero sums to 0.0
    $exp = is_infinite($want) ? null : bf_fmt($want == 0 ? 0.0 : $want);
    if ($exp === null ? !str_contains($got, 'out-of-range number') : $got !== $exp) {
        $bad++;
        echo "MISMATCH $s => $got (expected " . ($exp ?? 'out-of-range') . ")\n";
    }
}
echo "random strings checked: $n, mismatches: $bad\n";
echo "reference disagreements: $diffs\n";
?>
--EXPECT--
-- int64 accepted
int64 "0"                                       int(0)
int64 "1"                                       int(1)
int64 "-1"                                      int(-1)
int64 "7"                                       int(7)
int64 "123"                                     int(123)
int64 "1000000"                                 int(1000000)
int64 "9223372036854775807"                     int(9223372036854775807)
int64 "-9223372036854775808"                    int(-9223372036854775808)
int64 "9223372036854775806"                     int(9223372036854775806)
int64 "-9223372036854775807"                    int(-9223372036854775807)
int64 "\"5\""                                   int(5)
int64 "\"-5\""                                  int(-5)
int64 "\"0\""                                   int(0)
-- int64 rejected: malformed
int64 "+5"                                      CsvException: row 1 (line 2, offset 2): column "v": expected int64, got malformed value
int64 "007"                                     CsvException: row 1 (line 2, offset 2): column "v": expected int64, got malformed value
int64 "00"                                      CsvException: row 1 (line 2, offset 2): column "v": expected int64, got malformed value
int64 "-0"                                      CsvException: row 1 (line 2, offset 2): column "v": expected int64, got malformed value
int64 "1.0"                                     CsvException: row 1 (line 2, offset 2): column "v": expected int64, got malformed value
int64 "1e3"                                     CsvException: row 1 (line 2, offset 2): column "v": expected int64, got malformed value
int64 " 5"                                      CsvException: row 1 (line 2, offset 2): column "v": expected int64, got malformed value
int64 "5 "                                      CsvException: row 1 (line 2, offset 2): column "v": expected int64, got malformed value
int64 "--1"                                     CsvException: row 1 (line 2, offset 2): column "v": expected int64, got malformed value
int64 "-"                                       CsvException: row 1 (line 2, offset 2): column "v": expected int64, got malformed value
int64 "+"                                       CsvException: row 1 (line 2, offset 2): column "v": expected int64, got malformed value
int64 "abc"                                     CsvException: row 1 (line 2, offset 2): column "v": expected int64, got malformed value
int64 "0x10"                                    CsvException: row 1 (line 2, offset 2): column "v": expected int64, got malformed value
int64 "1_0"                                     CsvException: row 1 (line 2, offset 2): column "v": expected int64, got malformed value
int64 "5a"                                      CsvException: row 1 (line 2, offset 2): column "v": expected int64, got malformed value
int64 "1."                                      CsvException: row 1 (line 2, offset 2): column "v": expected int64, got malformed value
int64 ".5"                                      CsvException: row 1 (line 2, offset 2): column "v": expected int64, got malformed value
int64 "-.5"                                     CsvException: row 1 (line 2, offset 2): column "v": expected int64, got malformed value
int64 "\u0665"                                  CsvException: row 1 (line 2, offset 2): column "v": expected int64, got malformed value
int64 "5-"                                      CsvException: row 1 (line 2, offset 2): column "v": expected int64, got malformed value
int64 "5e0"                                     CsvException: row 1 (line 2, offset 2): column "v": expected int64, got malformed value
int64 "true"                                    CsvException: row 1 (line 2, offset 2): column "v": expected int64, got malformed value
int64 "NaN"                                     CsvException: row 1 (line 2, offset 2): column "v": expected int64, got malformed value
int64 "- 5"                                     CsvException: row 1 (line 2, offset 2): column "v": expected int64, got malformed value
-- int64 rejected: out of range
int64 "9223372036854775808"                     CsvException: row 1 (line 2, offset 2): column "v": expected int64, got out-of-range number
int64 "-9223372036854775809"                    CsvException: row 1 (line 2, offset 2): column "v": expected int64, got out-of-range number
int64 "99999999999999999999"                    CsvException: row 1 (line 2, offset 2): column "v": expected int64, got out-of-range number
int64 "-99999999999999999999"                   CsvException: row 1 (line 2, offset 2): column "v": expected int64, got out-of-range number
int64 "18446744073709551616"                    CsvException: row 1 (line 2, offset 2): column "v": expected int64, got out-of-range number
int64 "92233720368547758070"                    CsvException: row 1 (line 2, offset 2): column "v": expected int64, got out-of-range number
int64 "\"9223372036854775808\""                 CsvException: row 1 (line 2, offset 2): column "v": expected int64, got out-of-range number
-- int64 quoted
int64 "\"\""                                    CsvException: row 1 (line 2, offset 2): column "v": expected int64, got quoted empty field
int64 "\"5\"\"\""                               CsvException: row 1 (line 2, offset 2): column "v": expected int64, got malformed value
int64 "\"\"\"5\""                               CsvException: row 1 (line 2, offset 2): column "v": expected int64, got malformed value
int64 "\"5\"\"5\""                              CsvException: row 1 (line 2, offset 2): column "v": expected int64, got malformed value
int64 "\" 5\""                                  CsvException: row 1 (line 2, offset 2): column "v": expected int64, got malformed value
int64 "\"5 \""                                  CsvException: row 1 (line 2, offset 2): column "v": expected int64, got malformed value
int64 "\"+5\""                                  CsvException: row 1 (line 2, offset 2): column "v": expected int64, got malformed value
-- float64 accepted (shortest round-trip rendering of the sum)
float64 "0"                                     float(0.0)
float64 "1"                                     float(1.0)
float64 "-1"                                    float(-1.0)
float64 "1."                                    float(1.0)
float64 "1.5"                                   float(1.5)
float64 ".5"                                    float(0.5)
float64 "-.5"                                   float(-0.5)
float64 "+1"                                    float(1.0)
float64 "+.5"                                   float(0.5)
float64 "+1.5"                                  float(1.5)
float64 "1e3"                                   float(1000.0)
float64 "1E3"                                   float(1000.0)
float64 "1e+3"                                  float(1000.0)
float64 "1e-3"                                  float(0.001)
float64 ".5e1"                                  float(5.0)
float64 "1.e2"                                  float(100.0)
float64 "00012"                                 float(12.0)
float64 "007.5"                                 float(7.5)
float64 "-0"                                    float(0.0)
float64 "-0.0"                                  float(0.0)
float64 "0.0"                                   float(0.0)
float64 "1e0"                                   float(1.0)
float64 "5e-1"                                  float(0.5)
float64 "1.5e300"                               float(1.5E+300)
float64 "\"1.5\""                               float(1.5)
float64 "\"-2.5e2\""                            float(-250.0)
float64 "\".5\""                                float(0.5)
float64 "0.1"                                   float(0.1)
float64 "0.3"                                   float(0.3)
float64 "123456789012345678901234567890"        float(1.2345678901234568E+29)
float64 "0.000000000000000000000000000001"      float(1.0E-30)
-- float64 rejected: malformed
float64 "nan"                                   CsvException: row 1 (line 2, offset 2): column "v": expected float64, got malformed value
float64 "NaN"                                   CsvException: row 1 (line 2, offset 2): column "v": expected float64, got malformed value
float64 "inf"                                   CsvException: row 1 (line 2, offset 2): column "v": expected float64, got malformed value
float64 "-inf"                                  CsvException: row 1 (line 2, offset 2): column "v": expected float64, got malformed value
float64 "Infinity"                              CsvException: row 1 (line 2, offset 2): column "v": expected float64, got malformed value
float64 "0x1p3"                                 CsvException: row 1 (line 2, offset 2): column "v": expected float64, got malformed value
float64 "0x10"                                  CsvException: row 1 (line 2, offset 2): column "v": expected float64, got malformed value
float64 "1e"                                    CsvException: row 1 (line 2, offset 2): column "v": expected float64, got malformed value
float64 "e5"                                    CsvException: row 1 (line 2, offset 2): column "v": expected float64, got malformed value
float64 "."                                     CsvException: row 1 (line 2, offset 2): column "v": expected float64, got malformed value
float64 "+"                                     CsvException: row 1 (line 2, offset 2): column "v": expected float64, got malformed value
float64 "-"                                     CsvException: row 1 (line 2, offset 2): column "v": expected float64, got malformed value
float64 "1e+"                                   CsvException: row 1 (line 2, offset 2): column "v": expected float64, got malformed value
float64 "1e-"                                   CsvException: row 1 (line 2, offset 2): column "v": expected float64, got malformed value
float64 "1 "                                    CsvException: row 1 (line 2, offset 2): column "v": expected float64, got malformed value
float64 " 1"                                    CsvException: row 1 (line 2, offset 2): column "v": expected float64, got malformed value
float64 "1_0"                                   CsvException: row 1 (line 2, offset 2): column "v": expected float64, got malformed value
float64 "1.5.5"                                 CsvException: row 1 (line 2, offset 2): column "v": expected float64, got malformed value
float64 "--1"                                   CsvException: row 1 (line 2, offset 2): column "v": expected float64, got malformed value
float64 "1e3.5"                                 CsvException: row 1 (line 2, offset 2): column "v": expected float64, got malformed value
float64 "\"1,5\""                               CsvException: row 1 (line 2, offset 2): column "v": expected float64, got malformed value
float64 "1d3"                                   CsvException: row 1 (line 2, offset 2): column "v": expected float64, got malformed value
float64 "1f"                                    CsvException: row 1 (line 2, offset 2): column "v": expected float64, got malformed value
float64 "+-1"                                   CsvException: row 1 (line 2, offset 2): column "v": expected float64, got malformed value
float64 "1e5e5"                                 CsvException: row 1 (line 2, offset 2): column "v": expected float64, got malformed value
float64 "\u0665"                                CsvException: row 1 (line 2, offset 2): column "v": expected float64, got malformed value
-- float64 out of range / underflow
float64 "1e400"                                 CsvException: row 1 (line 2, offset 2): column "v": expected float64, got out-of-range number
float64 "-1e400"                                CsvException: row 1 (line 2, offset 2): column "v": expected float64, got out-of-range number
float64 "1e309"                                 CsvException: row 1 (line 2, offset 2): column "v": expected float64, got out-of-range number
float64 "1.7976931348623159e308"                CsvException: row 1 (line 2, offset 2): column "v": expected float64, got out-of-range number
float64 "1.7976931348623158e308"                float(1.7976931348623157E+308)
float64 "1.7976931348623157e308"                float(1.7976931348623157E+308)
float64 "1e-400"                                float(0.0)
float64 "-1e-400"                               float(0.0)
float64 "4.9e-324"                              float(5.0E-324)
float64 "2.4703282292062327e-324"               float(0.0)
float64 "2.4703282292062328e-324"               float(5.0E-324)
float64 "1e-324"                                float(0.0)
float64 "\"1e400\""                             CsvException: row 1 (line 2, offset 2): column "v": expected float64, got out-of-range number
-- float64 quoted
float64 "\"\""                                  CsvException: row 1 (line 2, offset 2): column "v": expected float64, got quoted empty field
float64 "\"1\"\"5\""                            CsvException: row 1 (line 2, offset 2): column "v": expected float64, got malformed value
float64 "\"\"\"1\""                             CsvException: row 1 (line 2, offset 2): column "v": expected float64, got malformed value
float64 "\"1.5\"\"\""                           CsvException: row 1 (line 2, offset 2): column "v": expected float64, got malformed value
-- correct rounding
float64 "9007199254740993"                      float(9007199254740992.0)
float64 "9007199254740995"                      float(9007199254740996.0)
float64 "9007199254740992.5"                    float(9007199254740992.0)
float64 "9007199254740993.0000000000000001"     float(9007199254740994.0)
float64 "0.1000000000000000055511151231257827"  float(0.1)
float64 "1e22"                                  float(1.0E+22)
float64 "1e23"                                  float(1.0E+23)
float64 "8.5e-5"                                float(8.5E-5)
float64 "123456789.123456789"                   float(123456789.12345679)
float64 "2.2250738585072011e-308"               float(2.225073858507201E-308)
float64 "2.2250738585072014e-308"               float(2.2250738585072014E-308)
float64 "1.00000000000000011102230246251565404236316680908203125"float(1.0)
float64 "1.00000000000000011102230246251565404236316680908203126"float(1.0000000000000002)
float64 "0.30000000000000004"                   float(0.30000000000000004)
-- very long digit strings are linear and correct
CsvException: row 1 (line 2, offset 2): column "v": expected float64, got out-of-range number
float(0.0)
float(1.0)
CsvException: row 1 (line 2, offset 2): column "v": expected float64, got out-of-range number
float(0.3333333333333333)
CsvException: row 1 (line 2, offset 2): column "v": expected int64, got out-of-range number
-- random decimal strings against PHP's own strtod
random strings checked: 1456, mismatches: 0
reference disagreements: 0
