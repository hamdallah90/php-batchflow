# BatchFlow benchmarks

The harness for the release benchmark plan (the benchmark plan). Everything measured runs in
Docker on a host that gives the harness cores to itself. `harness.py` is the only entry point.

```sh
python3 harness.py image                      # the benchmark image: the test PHP, DuckDB CLI and pdo_duckdb (pinned), the scripts
python3 harness.py variant release ..         # the extension of a source tree, built with PHP's own flags, as variant "release"
python3 harness.py fixtures --sizes 100k,1m,10m [--record]   # seed 20261109, sha256-verified against fixtures.sha256
python3 harness.py list                       # every workload of the plan
python3 harness.py verify --sizes 100k --cpuset 0-7   # every implementation once per workload, results compared: no timing
python3 harness.py cell gs-mixed-lo-c1k-1m --core 26 --variant release --out raw.jsonl
python3 harness.py ab BASE CAND --aa BASE2 --cores 26,27 --cells gs-mixed-hi-c100k-1m,scan-wide-lo-1m --named gs-mixed-hi-c100k-1m
python3 harness.py report raw.jsonl > table.md
```

## What a sample is

- `fresh`: the first call of a new PHP process: wall is the timed call itself (the process's extension load and start-up are
  not in it, and are recorded separately as `proc_wall_ns`), CPU is user+sys of that call, RSS is the peak of the process. JIT warm-up
  and cold caches of that first call are included, so fresh ratios can be lower than warm ones. Both are published for every cell.
- `warm`: after 3 untimed calls in the same process, 10 timed calls, each with its own wall and CPU (`getrusage`).
- `native` is the auto SIMD function, `native_scalar` forces `simd => scalar`, so every cell has both.
- JIT, opcache and NTS are asserted from inside PHP before anything is timed; a mismatch exits with status 3 and the
  harness records no sample. Every result is hashed (floats as raw bits): native and the PHP loops must have the same
  digest, SQLite and DuckDB must match count and sum within 1e-6 relative (`report` prints the check).

## The isolation rule

A timed job gets its core exclusively: `docker run --cpuset-cpus=N --cpus=1`, a per-core lock file shared by every harness
process on the host, and a check that the core is below 3% busy before the job starts. A single-threaded sample whose
wall/CPU is above 1.05 is excluded: it stays in the raw log marked `excluded` and the series is run again, up to three
times, after which the series is reported as not measured. Multi-threaded DuckDB runs (`duckdb`, `duckdb_forced`,
`pdo_duckdb`) get a set of cores (`--mt-cpuset`) and are exempt from the ratio. The harness never chooses a core: the
caller names it, and cores reserved for other timed work are not used.

`BF_BENCH_SMOKE=1` skips the core check and the ratio rule (a laptop has no `/proc/stat` and a VM has no clean ratio) and
marks every record `smoke`; those records only show that the harness works and are never results.

## Comparing two commits

`variant` builds each commit into `variants/<tag>/batchflow.so`. `ab` (or `session` for any list of builds) then runs the method of
the earlier regression checks: per cell, one process per build per round (a warm-up call, then one timed call), the order of
the builds rotated per round, 21 rounds, one core per cell on which nothing else runs. The statistic is the median wall of the
new build over the median wall of the baseline, per cell; a second build of the baseline (`--aa`) goes through the same
statistic as the control that shows the noise of that run. `ab` applies the adoption rule: a gain of at least 10% on a named
cell and no cell more than 2% slower (`--limit`). The correctness checks must also be unchanged; the harness does not judge those.

## Files

- `gen.php`: the fixture generator (`numeric`, `mixed`, `mixnq`, `wide`), deterministic from its seed.
- `lib.php`: workload definitions, the BatchFlow adapters, the PHP, SQLite and DuckDB baselines, digests, engine checks.
- `run_one.php`, `run_duck.php`: one measured process each; `duck_verify.php`: DuckDB output reduced to count and sum.
- `table_equal.php`: the Table equality check.
