#!/usr/bin/env python3
"""BatchFlow benchmark harness (the benchmark plan). Host side; every measured process runs in Docker.

  harness.py image                         build the benchmark image
  harness.py variant TAG TREE              build the extension of a source tree as variant TAG
  harness.py fixtures [--sizes 100k,1m]    generate the fixtures, verify them against fixtures.sha256
  harness.py list                          the workloads of section 10
  harness.py cell WORKLOAD --core N ...    every implementation of one workload, raw samples to a JSONL log
  harness.py ab BASE CAND --cores 26,27 ...  two builds round by round on one core per cell, with the adoption rule applied
  harness.py matrix --part single|multi ... the full benchmark run: every workload and implementation, warm and fresh
  harness.py session A,B[,A2] ...          the same for any list of builds (the speed-gate rechecks)
  harness.py verify [--sizes 100k]         every implementation of every workload once, results compared (no timing)
  harness.py scalar LOG...                 native against scalar, per cell, warm and fresh
  harness.py hypotheses LOG...             the section 10 hypotheses per cell, and every loss
  harness.py report LOG...                 medians, p95, ratios and the hypotheses, as Markdown

The isolation rule: a timed job gets its core exclusively (docker --cpuset-cpus, a per-core lock, and a check
that the core is idle before it starts); a single-threaded sample whose wall/CPU is above 1.05 is excluded, stays in the
raw log marked excluded, and is run again; a series that cannot produce clean samples is reported as not measured.
Nothing here picks cores 26-29: the core is always an argument.
"""
import argparse, fcntl, hashlib, json, math, os, statistics, subprocess, sys, time

HERE = os.path.dirname(os.path.abspath(__file__))
IMAGE = os.environ.get('BF_BENCH_IMAGE', 'bfm6bench:base')
SEED = 20261109
SIZES = {'100k': 100000, '1m': 1000000, '10m': 10000000}
MAX_RATIO = 1.05
# BF_BENCH_SMOKE=1: no core check (a laptop has no /proc/stat); every record is marked smoke and is never a result.
SMOKE = os.environ.get('BF_BENCH_SMOKE') == '1'
# BF_BENCH_NOPROC=1: a host without /proc/stat (the Mac's Docker VM): the core is not checked for load, the wall/CPU rule still applies,
# and the records are marked, so the claim says what was and was not isolated.
NOPROC = os.environ.get('BF_BENCH_NOPROC') == '1'
MODES = {
    'off': ['-d', 'opcache.enable_cli=0'],
    'nojit': ['-d', 'zend_extension=opcache', '-d', 'opcache.enable=1', '-d', 'opcache.enable_cli=1', '-d', 'opcache.jit=off',
              '-d', 'opcache.jit_buffer_size=0', '-d', 'opcache.file_update_protection=0'],
    'jit': ['-d', 'zend_extension=opcache', '-d', 'opcache.enable=1', '-d', 'opcache.enable_cli=1', '-d', 'opcache.jit=tracing',
            '-d', 'opcache.jit_buffer_size=64M', '-d', 'opcache.file_update_protection=0'],
}
NATIVE = ('native', 'native_scalar')
EXACT = NATIVE + ('stream', 'stream_fast', 'stream_hybrid', 'rows', 'rows_foreach', 'cols', 'cols_dict')
DUCK_CLI = ('duckdb', 'duckdb_1t', 'duckdb_forced', 'duckdb_forced_1t')
NO_OPCACHE = NATIVE + ('sqlite', 'sqlite_idx', 'pdo_duckdb', 'pdo_duckdb_1t')


def workloads():
    w = []
    for shape in ('numeric', 'mixed', 'mixnq', 'wide'):
        for sel in ('hi', 'lo'):
            for size in SIZES:
                w.append(f'scan-{shape}-{sel}-{size}')
    for shape in ('mixed', 'mixnq'):
        for sel in ('hi', 'lo'):
            for card in ('c10', 'c1k', 'c100k'):
                for size in SIZES:
                    w.append(f'gs-{shape}-{sel}-{card}-{size}')
    for sel in ('hi', 'lo'):
        for size in SIZES:
            w.append(f'gs-wide-{sel}-c1k-{size}')
    for sel in ('hi', 'lo'):
        for card in ('c10', 'c1k', 'c100k'):
            for size in SIZES:
                w.append(f'gi-numeric-{sel}-{card}-{size}')
    for sel in ('hi', 'lo'):
        for card in ('c10', 'c1k', 'c100k'):
            for size in SIZES:
                w.append(f'gt-mixed-{sel}-{card}-{size}')
    for card in ('c10', 'c1k', 'c100k'):
        for size in SIZES:
            w.append(f'gti-numeric-lo-{card}-{size}')
    for shape in ('numeric', 'mixed', 'wide'):
        for sel in ('hi', 'lo'):
            for size in SIZES:
                w.append(f'rows-{shape}-{sel}-{size}')
    return w


def impls_for(workload, duckdb=True):
    op, shape, _sel = workload.split('-')[:3]
    size = workload.split('-')[-1]
    fast = 'stream_hybrid' if shape == 'mixed' else 'stream_fast'
    if op in ('scan', 'gs', 'gi'):
        l = ['native', 'native_scalar', 'stream', fast, 'rows_foreach', 'cols', 'sqlite', 'sqlite_idx']
        if size != '10m':
            l.append('rows')
        if op != 'scan':
            l.append('cols_dict')
        if duckdb:
            l += ['duckdb', 'duckdb_1t', 'duckdb_forced', 'duckdb_forced_1t', 'pdo_duckdb', 'pdo_duckdb_1t']
    elif op in ('gt', 'gti'):
        l = ['native', 'native_scalar', 'rows_foreach', 'cols', 'cols_dict', 'sqlite', 'sqlite_idx']
    else:
        l = ['native', 'native_scalar', 'stream', fast, 'sqlite'] + (['pdo_duckdb', 'pdo_duckdb_1t'] if duckdb else [])
    return l


# ---- isolation ------------------------------------------------------------------------------------------------------

def core_busy(core, secs=0.5):
    """Percent of `secs` that CPU `core` was not idle, from /proc/stat."""
    def read():
        for line in open('/proc/stat'):
            if line.startswith(f'cpu{core} '):
                f = list(map(int, line.split()[1:9]))
                return sum(f) - f[3] - f[4], sum(f)
        raise SystemExit(f'no cpu{core} in /proc/stat')
    b0, t0 = read()
    time.sleep(secs)
    b1, t1 = read()
    return 100.0 * (b1 - b0) / max(1, t1 - t0)


class Core:
    """The exclusive use of one core: a lock file shared by every harness process on the host."""
    def __init__(self, core, idle_pct=3.0):
        self.core, self.idle_pct = core, idle_pct

    def __enter__(self):
        self.f = open(f'/tmp/bfbench-core-{self.core}.lock', 'w')
        fcntl.flock(self.f, fcntl.LOCK_EX)
        return self

    def wait_idle(self, tries=60):
        if SMOKE or NOPROC:
            return True
        for _ in range(tries):
            if core_busy(self.core) <= self.idle_pct:
                return True
        return False

    def __exit__(self, *a):
        fcntl.flock(self.f, fcntl.LOCK_UN)
        self.f.close()


def docker_php(variant_dir, core, impl, mode, workload, fixture, warmups, timed, fixtures_dir, threads=1, cpuset=None):
    """One measured process: its JSON line, or None with the reason on stderr."""
    cpus = cpuset if cpuset is not None else str(core)
    base = ['docker', 'run', '--rm', f'--cpuset-cpus={cpus}', f'--cpus={threads}', '-v', f'{os.path.abspath(fixtures_dir)}:/fixtures:ro']
    if variant_dir:
        base += ['-v', f'{os.path.abspath(variant_dir)}:/variant:ro']
    base += [IMAGE]
    if impl in DUCK_CLI:
        t = 1 if impl.endswith('_1t') else threads
        cmd = base + ['php', '-d', 'memory_limit=-1', '-d', 'serialize_precision=-1', 'run_duck.php', impl, workload, fixture, str(t)]
    else:
        ext = []
        if impl in NATIVE:
            ext += ['-d', 'extension=/variant/batchflow.so']
        if impl.startswith('pdo_duckdb'):
            ext += ['-d', 'extension=pdo_duckdb.so']
        flags = MODES['off' if impl in NO_OPCACHE else mode]
        cmd = base + ['php', '-d', 'memory_limit=-1', '-d', 'serialize_precision=-1'] + ext + flags + \
            ['run_one.php', impl, 'off' if impl in NO_OPCACHE else mode, workload, fixture, str(warmups), str(timed)]
    p = subprocess.run(cmd, capture_output=True, text=True)
    if p.returncode != 0:
        sys.stderr.write(f'FAILED {impl} {workload} rc={p.returncode}: {(p.stderr + p.stdout)[-400:]}\n')
        return None
    return json.loads(p.stdout.strip().splitlines()[-1])


def clean(sample):
    if SMOKE:
        return True
    return sample['cpu_ns'] > 0 and sample['wall_ns'] / sample['cpu_ns'] <= MAX_RATIO


def log(fp, rec):
    rec = rec | ({'smoke': True} if SMOKE else {}) | ({'noproc': True} if NOPROC else {})
    fp.write(json.dumps(rec, separators=(',', ':')) + '\n')
    fp.flush()


# ---- commands -------------------------------------------------------------------------------------------------------

def cmd_image(a):
    args = ['docker', 'build', '-t', IMAGE, '--build-arg', f'BASE={a.base}', '--build-arg', f'WITH_DUCKDB={a.duckdb}', HERE]
    sys.exit(subprocess.call(args))


def cmd_variant(a):
    out = os.path.abspath(a.out or f'variants/{a.tag}')
    os.makedirs(out, exist_ok=True)
    script = ('cp -a /src /w && cd /w && phpize >/dev/null && CFLAGS="$PHP_CFLAGS -Wall -Wextra" ./configure --enable-batchflow >/dev/null '
              '&& make -j8 2>&1 | tee /tmp/make.log >/dev/null && ! grep -E "warning:" /tmp/make.log && cp modules/batchflow.so /out/ '
              '&& php -d extension=/out/batchflow.so -r "echo json_encode(BatchFlow\\\\Runtime::info()), PHP_EOL;"')
    sys.exit(subprocess.call(['docker', 'run', '--rm'] + ([f'--cpuset-cpus={a.cpuset}'] if a.cpuset else []) + ['-v', f'{os.path.abspath(a.tree)}:/src:ro', '-v', f'{out}:/out', IMAGE, 'bash', '-c', script]))


def cmd_fixtures(a):
    manifest = {}
    mp = os.path.join(HERE, 'fixtures.sha256')
    if os.path.exists(mp):
        for line in open(mp):
            h, n = line.split()
            manifest[n] = h
    os.makedirs(a.dir, exist_ok=True)
    bad = 0
    for shape in ('numeric', 'mixed', 'mixnq', 'wide'):
        for size in a.sizes.split(','):
            name = f'{shape}-{size}.csv'
            path = os.path.join(a.dir, name)
            if not os.path.exists(path):
                subprocess.check_call(['docker', 'run', '--rm'] + ([f'--cpuset-cpus={a.gen_cpuset}'] if a.gen_cpuset else []) + ['-v', f'{os.path.abspath(a.dir)}:/fixtures', IMAGE, 'php', '-d', 'memory_limit=-1',
                                       'gen.php', shape, str(SIZES[size]), str(SEED), f'/fixtures/{name}'])
            h = hashlib.sha256()
            with open(path, 'rb') as f:
                for chunk in iter(lambda: f.read(1 << 22), b''):
                    h.update(chunk)
            d = h.hexdigest()
            status = 'recorded' if a.record else ('ok' if manifest.get(name) == d else ('MISMATCH' if name in manifest else 'unlisted'))
            bad += status in ('MISMATCH', 'unlisted')
            print(f'FIXTURE {name} {os.path.getsize(path)} bytes sha256={d} {status}')
            manifest[name] = d
    if a.record:
        with open(mp, 'w') as f:
            for n in sorted(manifest):
                f.write(f'{manifest[n]}  {n}\n')
    sys.exit(1 if bad else 0)


def cmd_list(a):
    print('\n'.join(workloads()))


def run_cell(a, variant_dir, workload, impl, fp, core, mode, warmups, timed, fresh_n, mt_cpuset=None):
    """Samples of one impl on one workload: fresh_n clean fresh processes and `timed` clean warm calls."""
    shape_size = workload.split('-')[1] + '-' + workload.split('-')[-1]
    fixture = f'/fixtures/{shape_size}.csv'
    multi = impl in ('duckdb', 'duckdb_forced', 'pdo_duckdb')
    cpuset = mt_cpuset if multi else None
    threads = len(mt_cpuset.split(',')) if multi and mt_cpuset and ',' in mt_cpuset else 1
    meas = {'fresh': [], 'warm': []}
    first = None
    if impl in DUCK_CLI:
        timed = 0
    for attempt in range(1, 4 + fresh_n):
        need_fresh = fresh_n - len(meas['fresh'])
        need_warm = timed - len(meas['warm'])
        if need_fresh <= 0 and need_warm <= 0:
            break
        with Core(core) as c:
            if not c.wait_idle():
                sys.stderr.write(f'core {core} is not idle; not measured\n')
                return None
            r = docker_php(variant_dir, core, impl, mode, workload, fixture, warmups if need_warm > 0 else 0,
                           (timed + 3) if need_warm > 0 else 0, a.fixtures, threads, cpuset)
        if r is None:
            return None
        first = first or r
        base = {'workload': workload, 'impl': impl, 'variant': a.variant, 'mode': r['mode'], 'php': r['php'], 'isa': r.get('isa'),
                'digest': r.get('digest'), 'count': r['count'], 'sum_total': r['sum_total'], 'ngroups': r['ngroups']}
        single = not multi
        f = r['fresh']
        ok = clean(f) or not single
        log(fp, base | {'series': 'fresh', **f, 'ratio': f['wall_ns'] / max(1, f['cpu_ns']), 'excluded': not ok})
        if ok and need_fresh > 0:
            meas['fresh'].append(f)
        for w in r['warm']:
            ok = clean(w) or not single
            log(fp, base | {'series': 'warm', **w, 'ratio': w['wall_ns'] / max(1, w['cpu_ns']), 'excluded': not ok})
            if ok and len(meas['warm']) < timed:
                meas['warm'].append(w)
    return first, meas


def cmd_cell(a):
    modes = a.modes.split(',')
    impls = a.impls.split(',') if a.impls else impls_for(a.workload, not a.no_duckdb)
    variant_dir = f'variants/{a.variant}'
    with open(a.out, 'a') as fp:
        for impl in impls:
            for mode in (['off'] if impl in NO_OPCACHE or impl in DUCK_CLI else modes):
                res = run_cell(a, variant_dir, a.workload, impl, fp, a.core, mode, a.warmups, a.samples, a.fresh, a.mt_cpuset)
                if res is None or len(res[1]['fresh']) < a.fresh or (impl not in DUCK_CLI and len(res[1]['warm']) < a.samples):
                    print(f'NOT MEASURED {a.workload} {impl} {mode}: no clean series')
                else:
                    w = [s['wall_ns'] for s in res[1]['warm']] or [s['wall_ns'] for s in res[1]['fresh']]
                    print(f'{a.workload} {impl} {mode}: median {statistics.median(w) / 1e6:.2f} ms')


def load_state():
    la = open('/proc/loadavg').read().split()[:3] if os.path.exists('/proc/loadavg') else []
    try:
        n = len(subprocess.run(['docker', 'ps', '-q'], capture_output=True, text=True).stdout.split())
    except OSError:
        n = -1
    return {'time': time.strftime('%Y-%m-%dT%H:%M:%SZ', time.gmtime()), 'load': la, 'containers': n}


def cmd_session(a):
    """Builds side by side, round by round, on the same core: the method of the earlier regression checks. Per cell:
    one process per build per round (one warm-up call, one timed call), the order of the builds rotated per round,
    21 rounds; the statistic is the median wall of the new build over the median wall of the baseline, and an A/A
    control (a second build of the baseline) goes through the same statistic. Cells are spread over the cores; the
    builds of one cell always share one core, which has nothing else on it (the isolation rule)."""
    from concurrent.futures import ThreadPoolExecutor
    names = a.variants.split(',')            # baseline first, the new build second, the A/A control third (optional)
    cells = a.cells.split(',')
    cores = [int(c) for c in a.cores.split(',')]
    results = {c: {v: [] for v in names} for c in cells}
    excl = {c: 0 for c in cells}
    fp = open(a.out, 'a')
    state0 = load_state()
    log(fp, {'event': 'start', **state0, 'cells': cells, 'variants': names, 'cores': cores, 'rounds': a.rounds})

    def one_cell(i):
        cell, core = cells[i], cores[i % len(cores)]
        shape_size = cell.split('-')[1] + '-' + cell.split('-')[-1]
        for rnd in range(a.rounds):
            order = names[rnd % len(names):] + names[:rnd % len(names)]
            for v in order:
                for attempt in range(4):
                    with Core(core) as c:
                        if not c.wait_idle():
                            break
                        r = docker_php(f'variants/{v}', core, 'native', 'off', cell, f'/fixtures/{shape_size}.csv', 0, 1, a.fixtures)
                    if r is None:
                        break
                    w = r['warm'][0]
                    ok = clean(w)
                    log(fp, {'workload': cell, 'impl': 'native', 'variant': v, 'round': rnd, 'core': core, 'series': 'warm', **w,
                             'ratio': w['wall_ns'] / max(1, w['cpu_ns']), 'excluded': not ok, 'digest': r['digest']})
                    if ok:
                        results[cell][v].append((w['wall_ns'], r['digest']))
                        break
                    excl[cell] += 1

    with ThreadPoolExecutor(max_workers=len(cores)) as ex:
        list(ex.map(one_cell, range(len(cells))))
    state1 = load_state()
    log(fp, {'event': 'end', **state1})
    base = names[0]
    print(f'session: {a.rounds} rounds, variants {names}, cores {cores}; load before {state0["load"]} after {state1["load"]}, '
          f'containers before {state0["containers"]} after {state1["containers"]}')
    print('| cell | ' + ' | '.join(f'{v}/{base}' for v in names[1:]) + ' | excluded | digests |')
    print('| --- | ' + ' | '.join('---:' for _ in names[1:]) + ' | ---: | --- |')
    table = {}
    for cell in cells:
        r = results[cell]
        meds = {v: statistics.median([x[0] for x in r[v]]) if len(r[v]) >= a.rounds * 2 // 3 else None for v in names}
        dig = {x[1] for v in names for x in r[v]}
        row = []
        for v in names[1:]:
            ratio = None if meds[v] is None or meds[base] is None else meds[v] / meds[base] - 1.0
            row.append(ratio)
        table[cell] = row
        print(f'| {cell} | ' + ' | '.join('not measured' if x is None else f'{100 * x:+.2f}%' for x in row) +
              f' | {excl[cell]} | {"identical" if len(dig) == 1 else "DIFFERENT"} |')
    return table


FULL10M = ('scan-mixed-lo-10m', 'gs-mixed-lo-c1k-10m', 'gi-numeric-lo-c1k-10m', 'gt-mixed-lo-c1k-10m', 'rows-mixed-hi-10m', 'rows-numeric-lo-10m')
MULTI = ('duckdb', 'duckdb_forced', 'pdo_duckdb')


def matrix_impls(workload, part):
    """The implementations of a workload in a matrix part. At 10M rows the PHP baselines that hold the whole file in
    arrays or take minutes are left out except on one workload per operation (FULL10M), as the earlier runs did."""
    size = workload.split('-')[-1]
    l = impls_for(workload, True)
    if size == '10m' and workload not in FULL10M:
        keep = ('native', 'native_scalar', 'stream', 'stream_fast', 'stream_hybrid', 'duckdb', 'duckdb_1t')
        l = [i for i in l if i in keep]
    return [i for i in l if (i in MULTI) == (part == 'multi')]


def cmd_matrix(a):
    """The full run: every workload of the sizes, every implementation, warm and fresh series. 'single' runs one job per
    core on the given cores, 'multi' runs the all-core DuckDB jobs one at a time on all of them."""
    from concurrent.futures import ThreadPoolExecutor
    cores = [int(c) for c in a.cores.split(',')]
    ws = [w for w in workloads() if w.split('-')[-1] in a.sizes.split(',')]
    if a.only:
        ws = [w for w in ws if any(w.startswith(p) for p in a.only.split(','))]
    state0 = load_state()
    fp = open(a.out, 'a')
    log(fp, {'event': 'start', **state0, 'sizes': a.sizes, 'part': a.part, 'cores': cores})
    jobs = [(w, i) for w in ws for i in matrix_impls(w, a.part) if not a.impls or i in a.impls.split(',')]
    samples = {'100k': (10, 10, 3), '1m': (10, 10, 3), '10m': (3, 3, 1)}

    def run(job):
        w, impl = job
        n_warm, n_fresh, warmups = samples[w.split('-')[-1]]
        n_warm, n_fresh = a.n_warm or n_warm, a.n_fresh or n_fresh
        a.variant = a.variant
        modes = ['off'] if impl in NO_OPCACHE or impl in DUCK_CLI else a.modes.split(',')
        core = cores[int(__import__('threading').current_thread().name.rsplit('_', 1)[-1]) % len(cores)] if a.part == 'single' else cores[0]
        for mode in modes:
            res = run_cell(a, f'variants/{a.variant}', w, impl, fp, core, mode, warmups, n_warm, n_fresh, ','.join(map(str, cores)))
            ok = res is not None and len(res[1]['fresh']) >= n_fresh and (impl in DUCK_CLI or len(res[1]['warm']) >= n_warm)
            print(f'{w} {impl} {mode}: {"ok" if ok else "NOT MEASURED"}', flush=True)

    with ThreadPoolExecutor(max_workers=len(cores) if a.part == 'single' else 1, thread_name_prefix='core') as ex:
        list(ex.map(run, jobs))
    log(fp, {'event': 'end', **load_state()})


def cmd_ab(a):
    a.variants = f'{a.base},{a.cand}' + (f',{a.aa}' if a.aa else '')
    table = cmd_session(a)
    named = set(a.named.split(',')) if a.named else set()
    ok = all(row[0] is not None and row[0] <= a.limit / 100 for row in table.values())
    gain = any(table[c][0] is not None and table[c][0] <= -0.10 for c in named if c in table)
    print(f'\nAdopt: {"yes" if ok and gain else "no"} (a gain of at least 10% on a named cell: {gain}; no cell more than '
          f'{a.limit:g}% slower: {ok}). The correctness gates must also be unchanged.')


def cmd_verify(a):
    """Correctness, not timing: every implementation of every workload of the given sizes once, results compared.
    No isolation rule applies, so any cores may be used; nothing it prints is a measurement."""
    bad = 0
    for w in workloads():
        if w.split('-')[-1] not in a.sizes.split(','):
            continue
        res = {}
        for impl in impls_for(w, not a.no_duckdb):
            shape_size = w.split('-')[1] + '-' + w.split('-')[-1]
            r = docker_php(f'variants/{a.variant}', 0, impl, 'jit' if impl not in NO_OPCACHE else 'off', w, f'/fixtures/{shape_size}.csv',
                           0, 0, a.fixtures, 1, a.cpuset)
            if r is None:
                bad += 1
                continue
            res[impl] = (r.get('digest'), r['count'], r['sum_total'], r['ngroups'])
        ref = next((i for i in res if i in EXACT), None)
        wrong = []
        for i, (dg, c, sm, g) in res.items():
            rd, rc, rs, rg = res[ref]
            if c != rc or (i in EXACT and dg != rd) or abs(sm - rs) > 1e-6 * max(1.0, abs(rs)):
                wrong.append(i)
        bad += len(wrong)
        print(f'{w}: {len(res)} implementations, {"all agree" if not wrong else "MISMATCH " + ",".join(wrong)}')
    sys.exit(1 if bad else 0)


def pct(v, p):
    s = sorted(v)
    return s[min(len(s) - 1, max(0, math.ceil(p / 100 * len(s)) - 1))]


def cmd_report(a):
    rows = {}
    excluded = {}
    digests = {}
    for path in a.logs:
        for line in open(path):
            r = json.loads(line)
            if r.get('event') or r.get('smoke'):
                continue
            key = (r['workload'], r['impl'], r['mode'], r.get('variant'))
            if r['excluded']:
                excluded[(key, r['series'])] = excluded.get((key, r['series']), 0) + 1
                continue
            rows.setdefault(key, {'warm': [], 'fresh': [], 'rss': [], 'ratio': []})
            rows[key][r['series']].append(r['wall_ns'])
            rows[key]['ratio'].append(r['ratio'])
            if r['series'] == 'fresh':
                rows[key]['rss'].append(r['maxrss_kb'])
            digests.setdefault(r['workload'], {})[r['impl']] = (r.get('digest'), r['count'], r['sum_total'], r['ngroups'])
    print('| workload | impl | mode | warm median ms | warm p95 ms | fresh median ms | fresh p95 ms | RSS MiB | wall/CPU median | excluded |')
    print('| --- | --- | --- | ---: | ---: | ---: | ---: | ---: | ---: | ---: |')
    for key in sorted(rows):
        r = rows[key]
        f = lambda v, p: f'{pct(v, p) / 1e6:.2f}' if v else ''
        med = lambda v: f'{statistics.median(v) / 1e6:.2f}' if v else ''
        ex = excluded.get((key, 'warm'), 0) + excluded.get((key, 'fresh'), 0)
        print(f'| {key[0]} | {key[1]} | {key[2]} | {med(r["warm"])} | {f(r["warm"], 95)} | {med(r["fresh"])} | {f(r["fresh"], 95)} | '
              f'{statistics.median(r["rss"]) / 1024:.0f} | {statistics.median(r["ratio"]):.3f} | {ex} |' if r['rss'] else
              f'| {key[0]} | {key[1]} | {key[2]} | {med(r["warm"])} | {f(r["warm"], 95)} | | | | {statistics.median(r["ratio"]):.3f} | {ex} |')
    print('\nResult checks (digest bit-exact among native and the PHP loops; count equal everywhere; sums within 1e-6 relative):')
    for w, d in sorted(digests.items()):
        ref_i = next((i for i in d if i in EXACT), None)
        if ref_i is None:
            continue
        bad = []
        for i, (dg, c, s, g) in d.items():
            rd, rc, rs, rg = d[ref_i]
            if c != rc or (i in EXACT and dg != rd) or abs(s - rs) > 1e-6 * max(1.0, abs(rs)):
                bad.append(i)
        print(f'- {w}: {"all agree" if not bad else "MISMATCH " + ",".join(bad)}')


PHP_IMPLS = ('stream', 'stream_fast', 'stream_hybrid', 'rows', 'rows_foreach', 'cols', 'cols_dict')


def cmd_hypotheses(a):
    """The three hypotheses of section 10 and every loss, per cell, from raw logs: warm and fresh, for the auto build.
    A cell's best PHP is the fastest PHP implementation in jit mode; duckdb_1t and the all-cores runs are listed as they are."""
    data = {}
    for path in a.logs:
        for line in open(path):
            r = json.loads(line)
            if r.get('event') or r['excluded'] or r.get('smoke'):
                continue
            d = data.setdefault(r['workload'], {}).setdefault((r['impl'], r['mode']), {'warm': [], 'fresh': [], 'rss': []})
            d[r['series']].append(r['wall_ns'])
            if r['series'] == 'fresh':
                d['rss'].append(r['maxrss_kb'])
    med = lambda v: statistics.median(v) if v else None
    out = {'group': [], 'scan': [], 'rows': [], 'loss': []}
    for w, impls in sorted(data.items()):
        op, size = w.split('-')[0], w.split('-')[-1]
        nat = impls.get(('native', 'off'))
        if not nat:
            continue
        for series in ('warm', 'fresh'):
            nv = med(nat[series])
            if nv is None:
                continue
            php = {i: med(v[series]) for (i, m), v in impls.items() if i in PHP_IMPLS and m == 'jit' and med(v[series])}
            best = min(php.items(), key=lambda x: x[1]) if php else (None, None)
            # DuckDB's CLI runs a fresh process each time: it has no warm series, so its fresh time is compared with both of native's
            others = {i: (med(v[series]) or med(v['fresh'])) for (i, m), v in impls.items() if i not in PHP_IMPLS and i != 'native'
                      and (med(v[series]) or (i in DUCK_CLI and med(v['fresh'])))}
            for i, t in others.items():
                if i != 'native_scalar' and t < nv:
                    out['loss'].append((w, series, i, t / nv))
            if best[0] and best[1] < nv:
                out['loss'].append((w, series, best[0], best[1] / nv))
            if not best[0]:
                continue
            ratio = best[1] / nv
            row = (w, series, ratio, best[0])
            if op in ('gs', 'gi') and size == a.size:
                d1 = others.get('duckdb_1t')
                out['group'].append(row + (None if d1 is None else d1 / nv,))
            elif op == 'scan' and size == a.size:
                srss = med(impls.get(('stream', 'jit'), {'rss': []})['rss']) if ('stream', 'jit') in impls else None
                nr = med(nat['rss'])
                out['scan'].append(row + (None if not srss or not nr else nr / srss,))
            elif op == 'rows' and size == a.size:
                out['rows'].append(row)
    def table(title, rows, bar, extra):
        print(f'\n### {title}\n')
        print('| cell | series | best PHP / native | best PHP | ' + extra + ' | verdict |')
        print('| --- | --- | ---: | --- | ---: | --- |')
        for r in rows:
            ok = r[2] >= bar and (title.startswith('Streaming') and r[4] is not None and r[4] <= 1.5 or not title.startswith('Streaming')) \
                and (not title.startswith('Group') or (r[4] is not None and r[4] > 1.0))
            print(f'| {r[0]} | {r[1]} | {r[2]:.2f} | {r[3]} | {"" if len(r) < 5 or r[4] is None else f"{r[4]:.2f}"} | {"pass" if ok else "FAIL"} |')
    table('Group-by: at least 3x the best JIT PHP and faster than duckdb_1t (last column: duckdb_1t / native)', out['group'], 3.0, 'duckdb_1t / native')
    table('Streaming sum: at least 5x the best JIT PHP, RSS within 1.5x (last column: native RSS / streaming PHP RSS)', out['scan'], 5.0, 'RSS ratio')
    table('Rows: at least 2x the best PHP loader', out['rows'], 2.0, '')
    print('\n### Cells where an implementation beat native (ratio: that implementation / native, below 1 is a loss)\n')
    print('| cell | series | implementation | its time / native |')
    print('| --- | --- | --- | ---: |')
    for w, series, i, r in out['loss']:
        print(f'| {w} | {series} | {i} | {r:.2f} |')


def cmd_scalar(a):
    """AVX2 and scalar side by side for every native cell, warm and fresh (the claims policy: no ratio without the scalar one)."""
    data = {}
    for path in a.logs:
        for line in open(path):
            r = json.loads(line)
            if r.get('event') or r['excluded'] or r.get('smoke') or r['impl'] not in NATIVE:
                continue
            data.setdefault(r['workload'], {}).setdefault(r['impl'], {'warm': [], 'fresh': []})[r['series']].append(r['wall_ns'])
    print('| cell | native warm ms | scalar warm ms | scalar / native warm | native fresh ms | scalar fresh ms | scalar / native fresh |')
    print('| --- | ---: | ---: | ---: | ---: | ---: | ---: |')
    for w, d in sorted(data.items()):
        if 'native' not in d or 'native_scalar' not in d:
            continue
        v = lambda i, k: statistics.median(d[i][k]) / 1e6 if d[i][k] else None
        row = [v('native', 'warm'), v('native_scalar', 'warm'), v('native', 'fresh'), v('native_scalar', 'fresh')]
        f = lambda x: '' if x is None else f'{x:.2f}'
        rw = None if None in row[:2] else row[1] / row[0]
        rf = None if None in row[2:] else row[3] / row[2]
        print(f'| {w} | {f(row[0])} | {f(row[1])} | {f(rw)} | {f(row[2])} | {f(row[3])} | {f(rf)} |')


def main():
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    sub = ap.add_subparsers(dest='cmd', required=True)
    p = sub.add_parser('image'); p.add_argument('--base', default='phplab:nts-8.3'); p.add_argument('--duckdb', default='1'); p.set_defaults(f=cmd_image)
    p = sub.add_parser('variant'); p.add_argument('tag'); p.add_argument('tree'); p.add_argument('--out'); p.add_argument('--cpuset', default=None); p.set_defaults(f=cmd_variant)
    p = sub.add_parser('fixtures'); p.add_argument('--dir', default='fixtures'); p.add_argument('--sizes', default='100k,1m,10m')
    p.add_argument('--record', action='store_true'); p.add_argument('--gen-cpuset', default=None); p.set_defaults(f=cmd_fixtures)
    p = sub.add_parser('list'); p.set_defaults(f=cmd_list)
    p = sub.add_parser('cell'); p.add_argument('workload'); p.add_argument('--core', type=int, required=True)
    p.add_argument('--variant', default='release'); p.add_argument('--fixtures', default='fixtures'); p.add_argument('--out', default='raw.jsonl')
    p.add_argument('--impls'); p.add_argument('--modes', default='off,nojit,jit'); p.add_argument('--warmups', type=int, default=3)
    p.add_argument('--samples', type=int, default=10); p.add_argument('--fresh', type=int, default=10)
    p.add_argument('--mt-cpuset', help='cores for the all-cores DuckDB runs, e.g. 27,28,29'); p.add_argument('--no-duckdb', action='store_true')
    p.set_defaults(f=cmd_cell)
    p = sub.add_parser('matrix'); p.add_argument('--part', choices=['single', 'multi'], required=True); p.add_argument('--sizes', default='100k,1m,10m')
    p.add_argument('--cores', required=True); p.add_argument('--variant', default='release'); p.add_argument('--fixtures', default='fixtures')
    p.add_argument('--out', default='matrix.jsonl'); p.add_argument('--modes', default='jit'); p.add_argument('--only', default=''); p.add_argument('--impls', default=''); p.add_argument('--n-warm', type=int, default=0); p.add_argument('--n-fresh', type=int, default=0)
    p.set_defaults(f=cmd_matrix)
    p = sub.add_parser('session'); p.add_argument('variants', help='comma list: baseline, new build, A/A control (a second build of the baseline)')
    p.add_argument('--cells', required=True); p.add_argument('--cores', required=True); p.add_argument('--rounds', type=int, default=21)
    p.add_argument('--fixtures', default='fixtures'); p.add_argument('--out', default='session.jsonl'); p.set_defaults(f=cmd_session, variant='')
    p = sub.add_parser('ab'); p.add_argument('base'); p.add_argument('cand'); p.add_argument('--aa', default='', help='the A/A control variant')
    p.add_argument('--cores', required=True); p.add_argument('--cells', required=True); p.add_argument('--named', default='')
    p.add_argument('--limit', type=float, default=2.0, help='the slowdown no cell may exceed, in percent')
    p.add_argument('--rounds', type=int, default=21); p.add_argument('--fixtures', default='fixtures'); p.add_argument('--out', default='ab.jsonl')
    p.set_defaults(f=cmd_ab, variant='')
    p = sub.add_parser('verify'); p.add_argument('--sizes', default='100k'); p.add_argument('--variant', default='release')
    p.add_argument('--fixtures', default='fixtures'); p.add_argument('--cpuset', default=None, help='cores to use, e.g. 0-7'); p.add_argument('--no-duckdb', action='store_true')
    p.set_defaults(f=cmd_verify)
    p = sub.add_parser('scalar'); p.add_argument('logs', nargs='+'); p.set_defaults(f=cmd_scalar)
    p = sub.add_parser('hypotheses'); p.add_argument('logs', nargs='+'); p.add_argument('--size', default='1m'); p.set_defaults(f=cmd_hypotheses)
    p = sub.add_parser('report'); p.add_argument('logs', nargs='+'); p.set_defaults(f=cmd_report)
    a = ap.parse_args()
    a.f(a)


if __name__ == '__main__':
    main()
