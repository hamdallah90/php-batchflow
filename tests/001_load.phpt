--TEST--
batchflow: extension is loaded, registers its classes and shows version and SIMD lines in phpinfo
--EXTENSIONS--
batchflow
--FILE--
<?php
var_dump(extension_loaded('batchflow'));

$v = phpversion('batchflow');
var_dump(is_string($v) && $v !== '');

$ext = new ReflectionExtension('batchflow');
var_dump($ext->getName());

$classes = $ext->getClassNames();
foreach ([
    'BatchFlow\Csv', 'BatchFlow\Table', 'BatchFlow\Query', 'BatchFlow\Summary', 'BatchFlow\Runtime',
    'BatchFlow\BatchFlowException', 'BatchFlow\SchemaException', 'BatchFlow\CsvException',
    'BatchFlow\LimitException', 'BatchFlow\ResultTooLargeException', 'BatchFlow\SumOverflowException',
    'BatchFlow\StreamException',
] as $c) {
    echo $c, ': ', in_array($c, $classes, true) ? 'registered' : 'MISSING', "\n";
}

// Same text as `php --ri batchflow`.
ob_start();
$ext->info();
$ri = ob_get_clean();
var_dump(str_contains($ri, 'batchflow'));
var_dump(str_contains($ri, $v));
var_dump(preg_match('/^.*simd.*$/mi', $ri) === 1);
?>
--EXPECT--
bool(true)
bool(true)
string(9) "batchflow"
BatchFlow\Csv: registered
BatchFlow\Table: registered
BatchFlow\Query: registered
BatchFlow\Summary: registered
BatchFlow\Runtime: registered
BatchFlow\BatchFlowException: registered
BatchFlow\SchemaException: registered
BatchFlow\CsvException: registered
BatchFlow\LimitException: registered
BatchFlow\ResultTooLargeException: registered
BatchFlow\SumOverflowException: registered
BatchFlow\StreamException: registered
bool(true)
bool(true)
bool(true)
