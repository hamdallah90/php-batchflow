<?php
/*
 * Shared helpers for the BatchFlow PHPT suite. Derived from the behaviour rules only.
 * Not a test itself (no .phpt extension).
 */

require_once __DIR__ . '/bf_reference.inc';

use BatchFlow\Csv;
use BatchFlow\Query;

$GLOBALS['__bf_tmp'] = [];
register_shutdown_function(static function () {
    foreach ($GLOBALS['__bf_tmp'] as $f) {
        @unlink($f);
    }
});

/** Temp file holding $data; removed at shutdown. */
function bf_file(string $data): string
{
    $p = tempnam(sys_get_temp_dir(), 'batchflow_');
    if ($p === false || file_put_contents($p, $data) !== strlen($data)) {
        throw new RuntimeException('temp file failed');
    }
    $GLOBALS['__bf_tmp'][] = $p;
    return $p;
}

/** Memory stream positioned at 0 holding $data. */
function bf_mem(string $data)
{
    $f = fopen('php://memory', 'w+b');
    fwrite($f, $data);
    rewind($f);
    return $f;
}

/** Stable text rendering of a terminal result. */
function bf_fmt(mixed $v): string
{
    if (is_int($v)) {
        return "int($v)";
    }
    if (is_float($v)) {
        return 'float(' . var_export($v, true) . ')';
    }
    if ($v === null) {
        return 'NULL';
    }
    if (is_string($v)) {
        return $v;
    }
    if ($v instanceof BatchFlow\Summary) {
        return 'Summary{scanned=' . $v->rowsScanned . ';matched=' . $v->rowsMatched . ';sum=' . bf_fmt($v->sum) . '}';
    }
    if (is_array($v) && isset($v['scanned'])) {
        return 'Summary{scanned=' . $v['scanned'] . ';matched=' . $v['matched'] . ';sum=' . bf_fmt($v['sum']) . '}';
    }
    return var_export($v, true);
}

/** Run $fn; return the rendered result or "Class: message" (namespace stripped). */
function bf_try(callable $fn): string
{
    try {
        return bf_fmt($fn());
    } catch (Throwable $e) {
        return bf_cls($e) . ': ' . $e->getMessage();
    }
}

function bf_cls(Throwable $e): string
{
    return str_replace('BatchFlow\\', '', get_class($e));
}

/** [class, message] of what $fn throws, or null. */
function bf_thrown(callable $fn): ?array
{
    try {
        $fn();
        return null;
    } catch (Throwable $e) {
        return [get_class($e), $e->getMessage()];
    }
}

/** Query over a temp file holding $data. */
function bf_q(string $data, array $schema, array $opts = []): Query
{
    return Csv::scan(bf_file($data), $schema, $opts);
}

/** $preds: list of [ 'eq'|'gt', column, literal ]; the extended kinds through bf_where_m2b(). */
function bf_apply(Query $q, array $preds): Query
{
    foreach ($preds as [$k, $c, $v]) {
        $q = $k === 'eq' ? $q->whereEq($c, $v) : ($k === 'gt' ? $q->whereGt($c, $v) : bf_where_m2b($q, $k, $c, $v));
    }
    return $q;
}

/** Run a terminal ('count' | 'sum' | 'summary') through the extension, rendered by bf_try. */
function bf_exec(string $data, array $schema, array $opts = [], array $preds = [], string $term = 'count', ?string $col = null): string
{
    return bf_try(function () use ($data, $schema, $opts, $preds, $term, $col) {
        $q = bf_apply(bf_q($data, $schema, $opts), $preds);
        return match ($term) {
            'count' => $q->count(),
            'sum' => $q->sum($col),
            'summary' => $q->summary($col),
        };
    });
}

/** Same terminal through the independent reference (tests/bf_reference.inc). */
function bf_ref(string $data, array $schema, array $opts = [], array $preds = [], string $term = 'count', ?string $col = null): string
{
    return BfRef::runStr($data, $schema, $opts, $preds, $term, $col);
}

/** "same" when extension and reference agree, else a diagnosis. */
function bf_cmp(string $data, array $schema, array $opts = [], array $preds = [], string $term = 'count', ?string $col = null): string
{
    $a = bf_exec($data, $schema, $opts, $preds, $term, $col);
    $b = bf_ref($data, $schema, $opts, $preds, $term, $col);
    return $a === $b ? 'same' : "DIFF ext=[$a] ref=[$b]";
}

/** Does this x86-64 CPU report AVX2? (oracle independent of the extension) */
function bf_cpu_has_avx2(): bool
{
    if (php_uname('m') !== 'x86_64') {
        return false;
    }
    $c = @file_get_contents('/proc/cpuinfo');
    return $c !== false && preg_match('/^flags\s*:.*\bavx2\b/m', $c) === 1;
}

/** simd option values that must be accepted on this machine. */
function bf_simd_available(): array
{
    $m = php_uname('m');
    $r = ['scalar'];
    if (bf_cpu_has_avx2()) {
        $r[] = 'avx2';
    }
    if ($m === 'aarch64' || $m === 'arm64') {
        $r[] = 'neon';
    }
    return $r;
}

/**
 * Userland stream wrapper "bfwrap" serving lazily generated input. Configuration is
 * static (BfWrap::$cfg) because Csv::scan opens paths without a context.
 *   segments   list of [string, repeat] pieces served in order
 *   max        max bytes returned per stream_read
 *   mode       normal | throw_read | throw_eof | false_read | empty_read
 *   after      bytes that must have been served before `mode` triggers
 *   exc        Throwable thrown by throw_* modes
 *   on_read    callable($wrapper) run at the start of every stream_read
 *   on_close   callable() run by stream_close before throw_close
 *   throw_open / throw_close   Throwable thrown by stream_open / stream_close
 * The class defines no stream_set_option on purpose.
 */
final class BfWrap
{
    public $context;
    public static array $cfg = [];
    public static int $opens = 0;
    public static int $closes = 0;
    public static ?BfWrap $last = null;

    public int $reads = 0;
    public int $served = 0;
    public int $maxRequest = 0;
    private array $c = [];
    private int $total = 0;

    public function stream_open($path, $mode, $options, &$opened)
    {
        $this->c = self::$cfg;
        if (isset($this->c['throw_open'])) {
            throw $this->c['throw_open'];
        }
        foreach ($this->c['segments'] ?? [] as [$s, $n]) {
            $this->total += strlen($s) * $n;
        }
        self::$opens++;
        self::$last = $this;
        return true;
    }

    private function slice(int $off, int $n): string
    {
        $out = '';
        $base = 0;
        foreach ($this->c['segments'] as [$s, $rep]) {
            $len = strlen($s) * $rep;
            if ($off < $base + $len && $n > 0) {
                $k = $off - $base;
                $take = min($n, $len - $k);
                $out .= substr(str_repeat($s, intdiv($k % strlen($s) + $take, strlen($s)) + 2), $k % strlen($s), $take);
                $off += $take;
                $n -= $take;
            }
            $base += $len;
        }
        return $out;
    }

    private function triggered(): bool
    {
        return $this->served >= ($this->c['after'] ?? 0);
    }

    public function stream_read($count)
    {
        $this->reads++;
        $this->maxRequest = max($this->maxRequest, $count);
        if (isset($this->c['on_read'])) {
            ($this->c['on_read'])($this);
        }
        $mode = $this->c['mode'] ?? 'normal';
        if ($this->triggered()) {
            if ($mode === 'throw_read') {
                throw $this->c['exc'];
            }
            if ($mode === 'false_read') {
                return false;
            }
            if ($mode === 'empty_read') {
                return '';
            }
        }
        $want = min($count, $this->c['max'] ?? $count, $this->total - $this->served);
        $out = $this->slice($this->served, $want);
        $this->served += strlen($out);
        return $out;
    }

    public function stream_eof()
    {
        $mode = $this->c['mode'] ?? 'normal';
        if ($this->triggered()) {
            if ($mode === 'throw_eof') {
                throw $this->c['exc'];
            }
            if ($mode === 'empty_read') {
                return false;
            }
        }
        return $this->served >= $this->total;
    }

    public function stream_stat()
    {
        return [];
    }

    public function stream_close()
    {
        self::$closes++;
        if (isset($this->c['on_close'])) {
            ($this->c['on_close'])();
        }
        if (isset($this->c['throw_close'])) {
            throw $this->c['throw_close'];
        }
    }
}

stream_wrapper_register('bfwrap', BfWrap::class);

/** Exception class used to prove identity is preserved. */
final class BfBoom extends Exception
{
}

/** Configure the wrapper and return the path to open. */
function bf_wrap_path(array $cfg): string
{
    BfWrap::$cfg = $cfg;
    BfWrap::$opens = 0;
    BfWrap::$closes = 0;
    return 'bfwrap://in';
}

/** Open a wrapper stream serving $data, at most $max bytes per read. */
function bf_wrap_stream(string $data, int $max = 7, array $cfg = [])
{
    BfWrap::$cfg = $cfg + ['segments' => [[$data, 1]], 'max' => $max];
    return fopen('bfwrap://in', 'rb');
}

/** Wrapper stream of generated segments. */
function bf_gen_stream(array $segments, array $cfg = [])
{
    BfWrap::$cfg = $cfg + ['segments' => $segments];
    return fopen('bfwrap://in', 'rb');
}
