--TEST--
batchflow: a float64 field of 19,683 bytes or more with an exponent magnitude above 19999 is out of range; zend_strtod would clamp it (SPEC 5.6)
--EXTENSIONS--
batchflow
--FILE--
<?php
require __DIR__ . '/bf_helper.inc';

$S = ['x' => 'float64'];
function t(string $label, string $f): void
{
    global $S;
    echo str_pad($label, 44), str_pad((string)strlen($f), 7), bf_exec("x\n$f\n", $S, [], [], 'sum', 'x'), "\n";
}

echo "-- at the bound the clamp could change the value: rejected\n";
$z = str_repeat('0', 19675);
t('9e19675 x 1e-20000 (true value 0)', "9{$z}e-20000");
t('with a sign', "-9{$z}e-20000");
t('huge exponent literal', "9{$z}e-99999999");
t('.0...01 x 1e20000 (true value overflows)', '.' . str_repeat('0', 19690) . '1e20000');

echo "-- one byte shorter or the exponent within 19999: correct, not rejected\n";
t('one byte shorter', '9' . substr($z, 1) . 'e-20000');
t('exponent exactly -19999', "9{$z}e-19999");
t('leading zeros in the exponent do not count', "9{$z}e-00019999");
t('exponent exactly 19999', '.' . str_repeat('0', 19690) . '1e19999');
t('long field, exponent under the clamp', str_repeat('1', 19700) . 'e-19700');

echo "-- short fields with huge exponents keep their ordinary results\n";
t('1e30000', '1e30000');
t('1e-30000', '1e-30000');
t('long digits, no exponent', str_repeat('1', 400));
?>
--EXPECT--
-- at the bound the clamp could change the value: rejected
9e19675 x 1e-20000 (true value 0)           19683  CsvException: row 1 (line 2, offset 2): column "x": expected float64, got out-of-range number
with a sign                                 19684  CsvException: row 1 (line 2, offset 2): column "x": expected float64, got out-of-range number
huge exponent literal                       19686  CsvException: row 1 (line 2, offset 2): column "x": expected float64, got out-of-range number
.0...01 x 1e20000 (true value overflows)    19698  CsvException: row 1 (line 2, offset 2): column "x": expected float64, got out-of-range number
-- one byte shorter or the exponent within 19999: correct, not rejected
one byte shorter                            19682  float(0.0)
exponent exactly -19999                     19683  float(1.0E-323)
leading zeros in the exponent do not count  19686  float(1.0E-323)
exponent exactly 19999                      19698  float(1.0E+308)
long field, exponent under the clamp        19707  float(0.1111111111111111)
-- short fields with huge exponents keep their ordinary results
1e30000                                     7      CsvException: row 1 (line 2, offset 2): column "x": expected float64, got out-of-range number
1e-30000                                    8      float(0.0)
long digits, no exponent                    400    CsvException: row 1 (line 2, offset 2): column "x": expected float64, got out-of-range number
