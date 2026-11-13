<?php
// run_one.php <impl> <mode> <workload> <fixture> <warmups> <timed>  -- one PHP process, one JSON line.
//   fresh:  the process's first call of the job: wall from process start (REQUEST_TIME_FLOAT) to the end of the call,
//           user+sys CPU of the whole process, peak RSS, nothing warm (extension load, JIT and caches included).
//   warm:   after <warmups> untimed calls in the same process, <timed> calls, each with its own wall and user+sys CPU.
// The engine (opcache, JIT, NTS) is asserted from inside PHP before anything is timed; the harness checks wall/CPU.
declare(strict_types=1);
require __DIR__ . '/lib.php';

[, $impl, $mode, $workload, $fixture, $warmups, $timed] = $argv;
$engine = bf2_assert_engine($mode);
if (PHP_ZTS) {
    fwrite(STDERR, "ENGINE ASSERT FAILED: a ZTS build\n");
    exit(3);
}
$spec = bf2_spec($workload);
$repeats = 1;
if (in_array($spec['op'], ['gt', 'gti'], true)) {
    $repeats = match ($spec['size']) { '100k' => 20, '1m' => 5, default => 2 };
}
[$call, $file] = bf2_make_call($spec, $impl);

function cpu_ns(): int
{
    $r = getrusage();
    return ($r['ru_utime.tv_sec'] + $r['ru_stime.tv_sec']) * 1000000000 + ($r['ru_utime.tv_usec'] + $r['ru_stime.tv_usec']) * 1000;
}

$wall0 = (int)((float)$_SERVER['REQUEST_TIME_FLOAT'] * 1e9);
$t0 = hrtime(true);
$c0 = cpu_ns();
$r = $call($fixture, $repeats);
$t1 = hrtime(true);
$c1 = cpu_ns();
$rss = (int)getrusage()['ru_maxrss'];
$peakPhp = memory_get_peak_usage(true);
$fresh = [
    'wall_ns' => $t1 - $t0, 'cpu_ns' => $c1 - $c0,
    // the whole process: start to the end of the call, CPU of everything including startup
    'proc_wall_ns' => (int)(microtime(true) * 1e9) - $wall0, 'proc_cpu_ns' => $c1,
    'maxrss_kb' => $rss, 'peak_php' => $peakPhp,
    'load_ns' => $r['load_ns'], 'query_ns' => $r['query_ns'],
];
$d = bf2_digest($r);
$count = $r['count'];
$isa = $r['isa'] ?? 'na';
if (class_exists('BatchFlow\Runtime')) {
    $isa = BatchFlow\Runtime::info()['simd']['auto'] ?? 'na';
}
unset($r);

$warm = [];
for ($i = (int)$warmups; $i > 0; $i--) {
    $x = $call($fixture, $repeats);
    unset($x);
}
for ($i = 0; $i < (int)$timed; $i++) {
    $t0 = hrtime(true);
    $c0 = cpu_ns();
    $x = $call($fixture, $repeats);
    $t1 = hrtime(true);
    $c1 = cpu_ns();
    $warm[] = ['wall_ns' => $t1 - $t0, 'cpu_ns' => $c1 - $c0, 'load_ns' => $x['load_ns'], 'query_ns' => $x['query_ns']];
    if ($i === (int)$timed - 1) {
        // every timed call must give the first call's digest: checked once, outside the timed region
        // Native and the PHP loops add floats in row order: bit-exact. SQLite and DuckDB do not (Kahan, parallel order):
        // their calls must agree on the counts and the sums within 1e-6 relative.
        $x2 = bf2_digest($x);
        $exact = in_array($impl, ['native', 'native_scalar', 'stream', 'stream_fast', 'stream_hybrid', 'rows', 'rows_foreach', 'cols', 'cols_dict'], true);
        $close = abs($x2['sum_total'] - $d['sum_total']) <= 1e-6 * max(1.0, abs($d['sum_total']));
        if ($exact ? $x2['digest'] !== $d['digest'] : (!$close || $x2['ngroups'] !== $d['ngroups'])) {
            fwrite(STDERR, "RESULT CHANGED between calls of one process\n");
            exit(4);
        }
    }
    unset($x);
}

$s = bf2_engine_state();
if ($mode !== 'off' && $file !== null && !opcache_is_script_cached($file)) {
    fwrite(STDERR, "ENGINE ASSERT FAILED: baseline source was not served by opcache\n");
    exit(3);
}
$jitUsed = $s['jit_buffer'] - $s['jit_free'];
if ($mode === 'jit' && $file !== null && $jitUsed <= 0) {
    fwrite(STDERR, "ENGINE ASSERT FAILED: JIT on but nothing compiled\n");
    exit(3);
}
echo json_encode([
    'impl' => $impl, 'mode' => $mode, 'workload' => $workload, 'php' => PHP_VERSION, 'zts' => (bool)PHP_ZTS,
    'arch' => php_uname('m'), 'isa' => $isa, 'engine' => $engine + ['jit_used' => $jitUsed],
    'count' => $count, 'digest' => $d['digest'], 'sum_total' => $d['sum_total'],
    'ngroups' => $d['ngroups'], 'outrows' => $d['outrows'], 'repeats' => $repeats,
    'fresh' => $fresh, 'warm' => $warm,
], JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION), "\n";
