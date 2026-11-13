# BatchFlow benchmark results

Measured on the test machine (Intel Xeon Platinum 8358, Linux x86-64, KVM guest with one thread per core, 30 cores,
PHP 8.3.36 NTS, gcc 14.2, extension built with the PHP's own flags `-O2` plus `-falign-functions=64 -falign-loops=32`, opcache
off and asserted from inside PHP, fixtures from `benchmarks/gen.php` with seed 20261109 on tmpfs, sha256 in
`benchmarks/fixtures.sha256`). Timed jobs ran on cores 26 to 29, one job per core, by `benchmarks/harness.py`; other jobs on the shared
host ran on cores 0 to 25 during the comparison sessions, and each session's load average before and after is recorded below.
Nothing here is an arm64 claim: arm64 timings, where present, are in their own section.

## Optimisations tried

Method of every session: per cell, one process per build per round (a warm-up call and one timed call), the builds in a
rotated order per round, 21 rounds, one core per cell with nothing else on it; the statistic is the median wall of the new
build over the median wall of the baseline, and an A/A control (a second build of the baseline) goes through the same
statistic. A session is void if the median over its cells of |A/A| is above 0.5%; none was. 40 to 42 cells: the 28 comma
cells at 1,000,000 rows, the 6 `rows` cells, a table cell `gt-mixed-lo-c1k` and `gti-numeric-lo-c1k`, and `scan` cells
at 10,000,000 rows. Digests were identical in every cell of every session. An optimisation is adopted only if it gains at least
10% on a named cell and no cell is more than 2% slower, with every correctness check unchanged.

| optimisation | decision | named cells | worst cell | median abs(A/A) |
| --- | --- | --- | --- | --- |
| 1. mmap windows for plain files | **rejected, reverted** | best gain -3.5% (`gs-mixnq-lo-c100k`) | `rows-mixed-hi` +23.4%, `rows-mixed-lo` +10.0%, `gt-mixed-lo-c1k` +9.2% | 0.24% |
| 2. popcount tail, first version | rejected | `scan-wide-*`, `gs-wide-*` -23% to -32% | narrow cells +1.3% to +4.0% | 0.28% |
| 2. popcount tail, second version (separate instantiation) | **adopted** | `scan-wide-hi` -32.0%, `scan-wide-lo` -31.7%, `gs-wide-hi-c1k` -25.3%, `gs-wide-lo-c1k` -23.6%, `scan-wide-lo-10m` -31.0% | `gs-mixnq-lo-c100k` +2.56% (A/A +2.85%; rerun -1.66%, A/A +1.06%) | 0.22% |
| 3. prefetched group probes, first version | rejected | `gs-*-c100k`, `gi-*-c100k` -15% to -23% | `gs-mixed-lo-c10` +5.95%, `gs-mixnq-lo-c10` +5.94%, `gi-numeric-lo-c10` +5.68% | 0.33% |
| 3. prefetched group probes, second version (queue only above 16,384 slots) | **adopted** | `gs-mixed-lo-c100k` -17.2%, `gs-mixnq-lo-c100k` -19.0%, `gi-numeric-lo-c100k` -15.6%, `gs-mixed-lo-c100k-10m` -21.7%, `gi-numeric-lo-c100k-10m` -21.0% | `rows-numeric-lo` +1.85% (A/A -0.35%) | 0.33% |

Mapped windows were rejected on the numbers. The cause of its losses on quoted shapes is that a doubled quote is removed in place,
which writes to the private mapping and copies the page. It gained at most 3.5% on `scan-*` and `gs-*`. Because it is rejected, a file truncated while mapped (SIGBUS) is not a concern of the release. The first version of the popcount tail slowed narrow shapes because the per-field
tail check was in every framing; the second version instantiates framing with the tail apart, so plans with a short tail run the
same code as before. The first version of the batched probes slowed cache-resident tables by the queue's copying; the second version
queues only rows of tables with at least 16,384 slots.

A cell inside its A/A noise that decided a result was confirmed by a rerun: `gs-mixnq-lo-c100k-1m` in the popcount tail's session was
+2.56% against an A/A of +2.85%; the rerun of that cell and three neighbours gave -1.66% (A/A +1.06%), +0.30%, -0.20% and -0.18%.

### Mapped windows against the base build

`session: 21 rounds, variants ['base', 'c1', 'base2'], cores [26, 27, 28, 29]; load before ['6.06', '5.42', '3.95'] after ['3.11', '5.96', '6.48'], containers before 4 after 1`

| cell | c1/base | base2/base | excluded | digests |
| --- | ---: | ---: | ---: | --- |
| scan-numeric-hi-1m | -0.20% | -0.11% | 0 | identical |
| scan-numeric-lo-1m | -0.66% | -0.04% | 0 | identical |
| scan-mixed-hi-1m | -1.06% | -0.45% | 0 | identical |
| scan-mixed-lo-1m | -0.27% | -0.10% | 0 | identical |
| scan-mixnq-hi-1m | +0.31% | +0.60% | 0 | identical |
| scan-mixnq-lo-1m | -0.14% | +0.35% | 0 | identical |
| scan-wide-hi-1m | -0.37% | +0.19% | 0 | identical |
| scan-wide-lo-1m | -1.12% | +0.13% | 0 | identical |
| gs-mixed-hi-c10-1m | -0.30% | +0.58% | 0 | identical |
| gs-mixed-hi-c1k-1m | -0.89% | +0.25% | 1 | identical |
| gs-mixed-hi-c100k-1m | -0.36% | +1.08% | 0 | identical |
| gs-mixed-lo-c10-1m | -2.40% | +0.18% | 0 | identical |
| gs-mixed-lo-c1k-1m | -1.80% | -0.56% | 0 | identical |
| gs-mixed-lo-c100k-1m | -3.17% | -1.33% | 0 | identical |
| gs-mixnq-hi-c10-1m | -0.44% | -0.15% | 0 | identical |
| gs-mixnq-hi-c1k-1m | -0.61% | +0.32% | 0 | identical |
| gs-mixnq-hi-c100k-1m | -0.57% | +0.12% | 0 | identical |
| gs-mixnq-lo-c10-1m | -1.96% | +0.03% | 0 | identical |
| gs-mixnq-lo-c1k-1m | -1.18% | +0.74% | 0 | identical |
| gs-mixnq-lo-c100k-1m | -3.51% | +1.20% | 0 | identical |
| gs-wide-hi-c1k-1m | -1.32% | -0.69% | 0 | identical |
| gs-wide-lo-c1k-1m | -1.59% | -0.16% | 0 | identical |
| gi-numeric-hi-c10-1m | -0.06% | +0.48% | 0 | identical |
| gi-numeric-hi-c1k-1m | -1.20% | -0.24% | 0 | identical |
| gi-numeric-hi-c100k-1m | -0.66% | -0.31% | 0 | identical |
| gi-numeric-lo-c10-1m | -0.62% | -0.07% | 0 | identical |
| gi-numeric-lo-c1k-1m | -0.74% | -0.72% | 0 | identical |
| gi-numeric-lo-c100k-1m | -0.25% | +0.02% | 0 | identical |
| rows-numeric-hi-1m | -0.27% | +0.24% | 1 | identical |
| rows-numeric-lo-1m | -4.03% | +0.02% | 0 | identical |
| rows-mixed-hi-1m | +23.44% | +0.25% | 0 | identical |
| rows-mixed-lo-1m | +9.99% | -0.07% | 0 | identical |
| rows-wide-hi-1m | -0.63% | +0.06% | 0 | identical |
| rows-wide-lo-1m | -2.04% | -0.82% | 0 | identical |
| gt-mixed-lo-c1k-1m | +9.19% | -0.02% | 0 | identical |
| gti-numeric-lo-c1k-1m | -0.69% | +0.68% | 0 | identical |
| scan-numeric-lo-10m | -0.30% | -0.04% | 0 | identical |
| scan-numeric-hi-10m | +0.06% | +0.93% | 0 | identical |
| scan-mixed-lo-10m | +2.89% | +3.73% | 0 | identical |
| scan-wide-lo-10m | -0.53% | +0.22% | 0 | identical |

### Popcount tail, first version, against the base build

`session: 21 rounds, variants ['base', 'c2p', 'base2'], cores [26, 27, 28, 29]; load before ['1.78', '4.03', '5.65'] after ['82.28', '53.65', '28.24'], containers before 1 after 3`

| cell | c2p/base | base2/base | excluded | digests |
| --- | ---: | ---: | ---: | --- |
| scan-numeric-hi-1m | +1.85% | +0.17% | 0 | identical |
| scan-numeric-lo-1m | +1.35% | -0.32% | 0 | identical |
| scan-mixed-hi-1m | +1.40% | -0.03% | 0 | identical |
| scan-mixed-lo-1m | +2.13% | +0.04% | 0 | identical |
| scan-mixnq-hi-1m | +2.40% | +0.24% | 0 | identical |
| scan-mixnq-lo-1m | +2.19% | -0.18% | 0 | identical |
| scan-wide-hi-1m | -31.88% | +0.47% | 0 | identical |
| scan-wide-lo-1m | -31.95% | +0.40% | 0 | identical |
| gs-mixed-hi-c10-1m | +2.22% | -0.03% | 0 | identical |
| gs-mixed-hi-c1k-1m | +2.19% | +0.67% | 0 | identical |
| gs-mixed-hi-c100k-1m | +2.32% | +0.11% | 0 | identical |
| gs-mixed-lo-c10-1m | -1.26% | -0.55% | 0 | identical |
| gs-mixed-lo-c1k-1m | -0.53% | -0.01% | 0 | identical |
| gs-mixed-lo-c100k-1m | -0.95% | +2.64% | 0 | identical |
| gs-mixnq-hi-c10-1m | +1.90% | -0.04% | 0 | identical |
| gs-mixnq-hi-c1k-1m | +2.44% | +0.52% | 2 | identical |
| gs-mixnq-hi-c100k-1m | +3.07% | +0.08% | 0 | identical |
| gs-mixnq-lo-c10-1m | -0.97% | -0.00% | 0 | identical |
| gs-mixnq-lo-c1k-1m | -0.19% | +0.55% | 0 | identical |
| gs-mixnq-lo-c100k-1m | -1.80% | +4.83% | 0 | identical |
| gs-wide-hi-c1k-1m | -25.73% | +0.08% | 0 | identical |
| gs-wide-lo-c1k-1m | -23.05% | -0.51% | 0 | identical |
| gi-numeric-hi-c10-1m | +1.33% | +0.05% | 1 | identical |
| gi-numeric-hi-c1k-1m | +2.98% | -0.26% | 0 | identical |
| gi-numeric-hi-c100k-1m | +3.70% | -0.28% | 0 | identical |
| gi-numeric-lo-c10-1m | +1.25% | -0.12% | 0 | identical |
| gi-numeric-lo-c1k-1m | +1.26% | +0.64% | 0 | identical |
| gi-numeric-lo-c100k-1m | +3.95% | +1.47% | 0 | identical |
| rows-numeric-hi-1m | +0.57% | +0.01% | 0 | identical |
| rows-numeric-lo-1m | -1.00% | -0.48% | 0 | identical |
| rows-mixed-hi-1m | +1.19% | -0.36% | 0 | identical |
| rows-mixed-lo-1m | +0.29% | -0.61% | 0 | identical |
| rows-wide-hi-1m | +0.56% | +0.43% | 0 | identical |
| rows-wide-lo-1m | +1.20% | +1.41% | 0 | identical |
| gt-mixed-lo-c1k-1m | +0.41% | +0.16% | 0 | identical |
| gti-numeric-lo-c1k-1m | -0.66% | +0.23% | 0 | identical |
| scan-numeric-lo-10m | +0.68% | +0.28% | 0 | identical |
| scan-numeric-hi-10m | +2.63% | +0.01% | 0 | identical |
| scan-mixed-lo-10m | +4.28% | +3.02% | 0 | identical |
| scan-wide-lo-10m | -32.11% | +0.29% | 0 | identical |

### Popcount tail, second version, against the base build

`session: 21 rounds, variants ['base', 'c2q', 'base2'], cores [26, 27, 28, 29]; load before ['59.58', '59.04', '34.27'] after ['2.52', '24.46', '32.65'], containers before 0 after 0`

| cell | c2q/base | base2/base | excluded | digests |
| --- | ---: | ---: | ---: | --- |
| scan-numeric-hi-1m | -0.24% | +0.16% | 0 | identical |
| scan-numeric-lo-1m | -0.18% | +0.55% | 1 | identical |
| scan-mixed-hi-1m | +0.13% | -0.51% | 0 | identical |
| scan-mixed-lo-1m | -1.14% | -1.07% | 1 | identical |
| scan-mixnq-hi-1m | -0.22% | -0.48% | 0 | identical |
| scan-mixnq-lo-1m | +0.44% | +0.45% | 0 | identical |
| scan-wide-hi-1m | -32.04% | +0.06% | 0 | identical |
| scan-wide-lo-1m | -31.67% | -0.18% | 0 | identical |
| gs-mixed-hi-c10-1m | +0.69% | +0.18% | 0 | identical |
| gs-mixed-hi-c1k-1m | +0.81% | +0.97% | 0 | identical |
| gs-mixed-hi-c100k-1m | +0.23% | +0.01% | 0 | identical |
| gs-mixed-lo-c10-1m | -3.00% | +0.08% | 0 | identical |
| gs-mixed-lo-c1k-1m | -2.01% | +0.58% | 0 | identical |
| gs-mixed-lo-c100k-1m | +1.76% | +1.81% | 0 | identical |
| gs-mixnq-hi-c10-1m | -0.10% | -0.41% | 0 | identical |
| gs-mixnq-hi-c1k-1m | +0.63% | +0.60% | 0 | identical |
| gs-mixnq-hi-c100k-1m | +0.19% | -0.35% | 1 | identical |
| gs-mixnq-lo-c10-1m | -2.86% | -0.08% | 0 | identical |
| gs-mixnq-lo-c1k-1m | -2.83% | -0.31% | 0 | identical |
| gs-mixnq-lo-c100k-1m | +2.56% | +2.85% | 0 | identical |
| gs-wide-hi-c1k-1m | -25.28% | +0.05% | 0 | identical |
| gs-wide-lo-c1k-1m | -23.57% | +0.10% | 0 | identical |
| gi-numeric-hi-c10-1m | -0.94% | -0.11% | 0 | identical |
| gi-numeric-hi-c1k-1m | -0.71% | +0.38% | 1 | identical |
| gi-numeric-hi-c100k-1m | -0.10% | +0.28% | 2 | identical |
| gi-numeric-lo-c10-1m | +0.48% | -0.07% | 0 | identical |
| gi-numeric-lo-c1k-1m | +0.13% | +0.11% | 0 | identical |
| gi-numeric-lo-c100k-1m | -0.65% | -0.71% | 0 | identical |
| rows-numeric-hi-1m | +0.09% | +0.02% | 0 | identical |
| rows-numeric-lo-1m | -0.34% | +0.07% | 0 | identical |
| rows-mixed-hi-1m | -0.78% | -0.26% | 0 | identical |
| rows-mixed-lo-1m | -0.75% | -0.14% | 0 | identical |
| rows-wide-hi-1m | -0.65% | -0.08% | 0 | identical |
| rows-wide-lo-1m | -0.34% | -0.51% | 0 | identical |
| gt-mixed-lo-c1k-1m | -1.04% | -0.48% | 0 | identical |
| gti-numeric-lo-c1k-1m | -1.05% | -0.09% | 0 | identical |
| scan-numeric-lo-10m | -0.62% | +0.16% | 0 | identical |
| scan-numeric-hi-10m | -0.02% | +0.07% | 0 | identical |
| scan-mixed-lo-10m | -0.15% | -0.38% | 0 | identical |
| scan-wide-lo-10m | -31.03% | +0.16% | 0 | identical |

### Popcount tail, second version: rerun of the cells near their A/A noise

`session: 21 rounds, variants ['base', 'c2q', 'base2'], cores [26, 27, 28, 29]; load before ['1.53', '22.12', '31.61'] after ['2.54', '16.94', '28.84'], containers before 0 after 0`

| cell | c2q/base | base2/base | excluded | digests |
| --- | ---: | ---: | ---: | --- |
| gs-mixnq-lo-c100k-1m | -1.66% | +1.06% | 0 | identical |
| gs-mixed-lo-c100k-1m | +0.30% | -0.71% | 0 | identical |
| gs-mixnq-hi-c1k-1m | -0.20% | -0.82% | 0 | identical |
| gs-mixed-hi-c1k-1m | -0.18% | -0.74% | 0 | identical |

### Batched group probes, first version, against the build with the popcount tail

`session: 21 rounds, variants ['c2q', 'c3q', 'c2qb'], cores [26, 27, 28, 29]; load before ['2.54', '14.48', '27.31'] after ['1.06', '2.01', '7.14'], containers before 0 after 0`

| cell | c3q/c2q | c2qb/c2q | excluded | digests |
| --- | ---: | ---: | ---: | --- |
| scan-numeric-hi-1m | -0.05% | +0.75% | 1 | identical |
| scan-numeric-lo-1m | -0.54% | +0.61% | 0 | identical |
| scan-mixed-hi-1m | -0.32% | -0.19% | 0 | identical |
| scan-mixed-lo-1m | -1.55% | -0.16% | 0 | identical |
| scan-mixnq-hi-1m | +0.99% | +0.86% | 1 | identical |
| scan-mixnq-lo-1m | -1.82% | -0.14% | 1 | identical |
| scan-wide-hi-1m | +0.67% | +1.09% | 0 | identical |
| scan-wide-lo-1m | -0.11% | -0.04% | 0 | identical |
| gs-mixed-hi-c10-1m | -0.57% | -0.14% | 0 | identical |
| gs-mixed-hi-c1k-1m | +0.08% | +1.54% | 0 | identical |
| gs-mixed-hi-c100k-1m | -0.36% | +0.32% | 0 | identical |
| gs-mixed-lo-c10-1m | +5.95% | +0.91% | 0 | identical |
| gs-mixed-lo-c1k-1m | +4.01% | +0.82% | 0 | identical |
| gs-mixed-lo-c100k-1m | -18.59% | -1.06% | 0 | identical |
| gs-mixnq-hi-c10-1m | +0.22% | -0.33% | 2 | identical |
| gs-mixnq-hi-c1k-1m | -0.63% | -0.01% | 0 | identical |
| gs-mixnq-hi-c100k-1m | -1.16% | -0.54% | 0 | identical |
| gs-mixnq-lo-c10-1m | +5.94% | -0.49% | 0 | identical |
| gs-mixnq-lo-c1k-1m | +3.40% | -0.88% | 0 | identical |
| gs-mixnq-lo-c100k-1m | -20.58% | +0.59% | 1 | identical |
| gs-wide-hi-c1k-1m | -1.23% | +0.03% | 0 | identical |
| gs-wide-lo-c1k-1m | +2.55% | -0.20% | 0 | identical |
| gi-numeric-hi-c10-1m | +0.73% | -0.99% | 0 | identical |
| gi-numeric-hi-c1k-1m | +0.63% | -0.42% | 0 | identical |
| gi-numeric-hi-c100k-1m | +1.15% | -0.14% | 0 | identical |
| gi-numeric-lo-c10-1m | +5.68% | -0.70% | 0 | identical |
| gi-numeric-lo-c1k-1m | +3.61% | +0.19% | 0 | identical |
| gi-numeric-lo-c100k-1m | -15.13% | +1.35% | 1 | identical |
| rows-numeric-hi-1m | -0.20% | +1.14% | 1 | identical |
| rows-numeric-lo-1m | -0.36% | -0.28% | 0 | identical |
| rows-mixed-hi-1m | -0.46% | -0.22% | 0 | identical |
| rows-mixed-lo-1m | -0.10% | +0.44% | 0 | identical |
| rows-wide-hi-1m | +0.14% | +0.05% | 0 | identical |
| rows-wide-lo-1m | -0.15% | +0.29% | 0 | identical |
| gt-mixed-lo-c1k-1m | +0.40% | +0.33% | 0 | identical |
| gti-numeric-lo-c1k-1m | -2.59% | +0.45% | 0 | identical |
| scan-numeric-lo-10m | -0.81% | +0.15% | 0 | identical |
| scan-numeric-hi-10m | -0.17% | -0.24% | 0 | identical |
| scan-mixed-lo-10m | -1.43% | -0.04% | 0 | identical |
| scan-wide-lo-10m | +0.02% | +0.24% | 0 | identical |
| gs-mixed-lo-c100k-10m | -22.86% | -1.00% | 0 | identical |
| gi-numeric-lo-c100k-10m | -20.86% | -0.22% | 0 | identical |

### Batched group probes, second version, against the build with the popcount tail

`session: 21 rounds, variants ['c2q', 'c3r', 'c2qb'], cores [26, 27, 28, 29]; load before ['1.44', '1.55', '6.26'] after ['1.02', '1.91', '3.23'], containers before 0 after 0`

| cell | c3r/c2q | c2qb/c2q | excluded | digests |
| --- | ---: | ---: | ---: | --- |
| scan-numeric-hi-1m | +0.33% | +0.45% | 1 | identical |
| scan-numeric-lo-1m | +0.38% | +0.22% | 0 | identical |
| scan-mixed-hi-1m | -1.05% | -0.51% | 0 | identical |
| scan-mixed-lo-1m | -0.33% | -0.33% | 0 | identical |
| scan-mixnq-hi-1m | +0.32% | -0.01% | 0 | identical |
| scan-mixnq-lo-1m | -1.10% | -1.25% | 0 | identical |
| scan-wide-hi-1m | +0.91% | +0.92% | 0 | identical |
| scan-wide-lo-1m | +0.02% | +0.01% | 0 | identical |
| gs-mixed-hi-c10-1m | +0.73% | +0.09% | 0 | identical |
| gs-mixed-hi-c1k-1m | +0.61% | -0.16% | 0 | identical |
| gs-mixed-hi-c100k-1m | +0.30% | -0.76% | 0 | identical |
| gs-mixed-lo-c10-1m | +1.18% | +0.99% | 0 | identical |
| gs-mixed-lo-c1k-1m | +0.58% | +0.44% | 0 | identical |
| gs-mixed-lo-c100k-1m | -17.22% | +1.23% | 0 | identical |
| gs-mixnq-hi-c10-1m | +0.24% | +0.37% | 0 | identical |
| gs-mixnq-hi-c1k-1m | +0.32% | +0.37% | 0 | identical |
| gs-mixnq-hi-c100k-1m | -0.87% | -0.93% | 0 | identical |
| gs-mixnq-lo-c10-1m | +0.99% | +0.14% | 0 | identical |
| gs-mixnq-lo-c1k-1m | +0.87% | -0.60% | 0 | identical |
| gs-mixnq-lo-c100k-1m | -19.02% | -0.08% | 0 | identical |
| gs-wide-hi-c1k-1m | -0.29% | -0.44% | 0 | identical |
| gs-wide-lo-c1k-1m | +0.94% | +0.48% | 0 | identical |
| gi-numeric-hi-c10-1m | -0.55% | +0.27% | 0 | identical |
| gi-numeric-hi-c1k-1m | -0.79% | -0.33% | 0 | identical |
| gi-numeric-hi-c100k-1m | -0.68% | -0.59% | 0 | identical |
| gi-numeric-lo-c10-1m | -0.64% | -0.25% | 0 | identical |
| gi-numeric-lo-c1k-1m | -0.37% | +0.05% | 0 | identical |
| gi-numeric-lo-c100k-1m | -15.55% | +0.26% | 0 | identical |
| rows-numeric-hi-1m | +0.47% | +0.10% | 0 | identical |
| rows-numeric-lo-1m | +1.85% | -0.35% | 0 | identical |
| rows-mixed-hi-1m | +0.00% | -0.55% | 0 | identical |
| rows-mixed-lo-1m | +1.34% | +0.26% | 0 | identical |
| rows-wide-hi-1m | +0.06% | -0.02% | 0 | identical |
| rows-wide-lo-1m | +0.64% | -0.22% | 0 | identical |
| gt-mixed-lo-c1k-1m | +0.63% | +0.24% | 0 | identical |
| gti-numeric-lo-c1k-1m | -3.02% | -0.27% | 0 | identical |
| scan-numeric-lo-10m | -0.18% | -0.07% | 0 | identical |
| scan-numeric-hi-10m | +0.11% | +0.41% | 0 | identical |
| scan-mixed-lo-10m | -0.31% | +0.10% | 0 | identical |
| scan-wide-lo-10m | -0.08% | -0.05% | 0 | identical |
| gs-mixed-lo-c100k-10m | -21.73% | +0.95% | 0 | identical |
| gi-numeric-lo-c100k-10m | -20.96% | -1.71% | 0 | identical |

## Speed against the earlier streaming build

Question: do the comma-separated scan and `groupSum` paths of the release build stay within the bars against the earlier streaming
build (`32bc71e` plus both memory-preflight fixes, the build the earlier checks used)? Method as in the sessions above (21 rounds, 28 comma
cells at 1,000,000 rows, A/A control = a second build of that tree). Bars, fixed before: each comma cell within +1.0%; the 20 single-key
`groupSum` cells (`gs-*`, `gi-*`) within +2.0%.

- Session 1 (22:05Z) was **void**: median |A/A| 0.62%, above the 0.5% rule. It is kept in the logs and not used.
- Session 2 (22:07Z to 22:17Z, host quiet before it: load 0.76, no containers): median |A/A| 0.35%, max 1.74%; digests identical.
  **0 of 28 cells above +1.0%** (worst `gi-numeric-lo-c1k` +0.20%); **20 of 20 `groupSum` cells within +2.0%**. The four cells that were above +1.0%
  in the earlier comparison (2026-11-10) now pass. Several cells are faster by 20% or more (the wide shapes and the `c100k` cells: the popcount tail and the batched probes).

| cell | release/m2fix | m2fix2/m2fix | excluded | digests |
| --- | ---: | ---: | ---: | --- |
| scan-numeric-hi-1m | -3.04% | -0.56% | 0 | identical |
| scan-numeric-lo-1m | -3.58% | -1.74% | 0 | identical |
| scan-mixed-hi-1m | -0.75% | +0.24% | 1 | identical |
| scan-mixed-lo-1m | -2.36% | -1.26% | 0 | identical |
| scan-mixnq-hi-1m | -0.83% | +0.15% | 0 | identical |
| scan-mixnq-lo-1m | -1.37% | -0.31% | 0 | identical |
| scan-wide-hi-1m | -31.04% | -0.36% | 0 | identical |
| scan-wide-lo-1m | -31.49% | -0.36% | 0 | identical |
| gs-mixed-hi-c10-1m | -1.52% | +1.10% | 0 | identical |
| gs-mixed-hi-c1k-1m | -2.59% | +0.15% | 0 | identical |
| gs-mixed-hi-c100k-1m | -3.46% | +0.16% | 0 | identical |
| gs-mixed-lo-c10-1m | -2.30% | +0.32% | 0 | identical |
| gs-mixed-lo-c1k-1m | -2.30% | +0.08% | 0 | identical |
| gs-mixed-lo-c100k-1m | -18.67% | +0.99% | 0 | identical |
| gs-mixnq-hi-c10-1m | -2.71% | -0.30% | 0 | identical |
| gs-mixnq-hi-c1k-1m | -2.87% | -0.12% | 0 | identical |
| gs-mixnq-hi-c100k-1m | -3.16% | +1.31% | 0 | identical |
| gs-mixnq-lo-c10-1m | -2.58% | -0.03% | 0 | identical |
| gs-mixnq-lo-c1k-1m | -1.95% | -0.01% | 0 | identical |
| gs-mixnq-lo-c100k-1m | -19.73% | +1.41% | 0 | identical |
| gs-wide-hi-c1k-1m | -25.58% | +0.69% | 0 | identical |
| gs-wide-lo-c1k-1m | -21.61% | -0.20% | 0 | identical |
| gi-numeric-hi-c10-1m | -2.91% | -0.63% | 0 | identical |
| gi-numeric-hi-c1k-1m | -1.38% | +0.73% | 0 | identical |
| gi-numeric-hi-c100k-1m | -2.08% | +0.34% | 1 | identical |
| gi-numeric-lo-c10-1m | -1.24% | -0.29% | 0 | identical |
| gi-numeric-lo-c1k-1m | +0.20% | +0.58% | 0 | identical |
| gi-numeric-lo-c100k-1m | -16.57% | +0.85% | 0 | identical |

## Full benchmark run on the release build

Release commit: `src/`, `fuzz/` and `tests/` of the release tree; the run used the extension built from that tree
(one build, sha256 `a4e87a2bd9315f638889f8f23d2d297c9084e0595a2616c6c4eccb2f9e4abfb0`, used by the comparison against the earlier build, the full run and the `pdo_duckdb` rerun; AVX2 selected at run time; `native_scalar` forces the scalar masks). Host: Intel Xeon Platinum 8358, 30 cores, KVM guest, Linux
x86-64, PHP 8.3.36 NTS, gcc 14.2, `-O2`, opcache and JIT state asserted from inside PHP on every run (PHP loops: opcache and
tracing JIT on; native, SQLite and PDO DuckDB: opcache off), fixtures sha256-verified (`benchmarks/fixtures.sha256`), page cache warm
(cold cache was not established). Baselines: PHP 8.3 loops generated by `benchmarks/lib.php` (`stream`, `stream_fast`, `stream_hybrid`,
`rows`, `rows_foreach`, `cols`, `cols_dict`), SQLite 3 (with and without an index), DuckDB CLI 2.0.0 (1 thread and 4 threads, with and without a forced
`COUNT` of every unreferenced column) and `pdo_duckdb` 0.9.0 (1 and 4 threads).

- Samples: at 100,000 and 1,000,000 rows, 10 clean fresh-process samples and 10 clean warm samples per implementation (3 warm-up calls); at
  10,000,000 rows, 3 and 3 (1 warm-up call). At 10,000,000 rows only native, `native_scalar`, the best streaming PHP loops and DuckDB ran,
  plus every implementation on one workload per operation (`scan-mixed-lo`, `gs-mixed-lo-c1k`, `gi-numeric-lo-c1k`, `gt-mixed-lo-c1k`,
  `rows-mixed-hi`, `rows-numeric-lo`): the PHP arrays and SQLite tables of the other implementations do not fit or take many minutes. Not measured at
  10,000,000 rows elsewhere: `stream` (the generic loop), `rows`, `rows_foreach`, `cols`, `cols_dict`, `sqlite`, `sqlite_idx`, `pdo_duckdb`, `pdo_duckdb_1t`, `duckdb_forced*`.
- Isolation: one timed job per core on cores 26 to 29 (`--cpuset-cpus`, one core each; the DuckDB and `pdo_duckdb` 4-thread runs got all four cores and ran one at a time). A single-threaded
  sample with wall/CPU above 1.05 was excluded and rerun: 10 of 21,198 records in the single-core part (all kept in the raw log, marked excluded). Every series was measured.
- Load: the host was quiet for the whole run. The sampler (every 30 s) shows load average at most 7.24 (median 3.96) in the single-core part with at most 4 containers (the benchmark's own four), no other job; 4-thread
  part: load at most 2.55, one container. The two parts ran 22:27Z to 03:23Z (1,121 jobs) and 03:23Z to 03:59Z (216 jobs). The first run of the 28 `pdo_duckdb` 4-thread cells failed on a check in the harness
  (a bit-exact comparison of DuckDB's float sums between calls of one process, which varies in the last bits); the check was fixed and those cells were run again (05:03Z to 05:21Z, 73 jobs, load at most 1.99).
- Results are identical across implementations: for all 129 workloads the digest is bit-exact among native and the PHP loops, counts are equal everywhere, and SQLite and DuckDB sums are within 1e-6 relative.
- Claims are for x86-64 with AVX2. arm64: results are checked (PHPT, fuzz, the same digests at 100,000 rows) but no arm64 speed is measured: the Mac's Docker Desktop VM has wall/CPU of 1.06 to 2.1 per sample, so the isolation requirement leaves no clean series.

### Hypotheses, per cell (warm process and fresh process)

Bars fixed before measuring: group-by at least 3x the best JIT'd PHP loop and faster than single-threaded DuckDB; streaming sum at least 5x the best JIT'd PHP loop with
RSS within 1.5x of the streaming PHP loop; rows at least 2x the best PHP loader. Every 1M-row cell passes; one 100k-row fresh cell (`gs-mixnq-lo-c100k-100k`, 2.71x: `stream_fast` 73.60 ms against native 27.14 ms) misses the 3x bar, and its warm series passes (3.63x).
All 10M-row cells pass. A missed bar narrows the claim to the sizes where it holds. The tables for 1,000,000 rows follow; the tables for 100,000 and 10,000,000 rows come after them,
and the per-cell table lists every size. The RSS ratio compares native with opcache off against the PHP loop with opcache and the tracing JIT on, as each was run for its own best result: native's peak RSS is a constant
23,280 KB in every cell, so the ratio is the same in every cell.
The duckdb_1t column compares DuckDB's fresh process with both of native's series (DuckDB's CLI has no warm series).
### Group-by: at least 3x the best JIT PHP and faster than duckdb_1t (last column: duckdb_1t / native)

| cell | series | best PHP / native | best PHP | duckdb_1t / native | verdict |
| --- | --- | ---: | --- | ---: | --- |
| gi-numeric-hi-c10-1m | warm | 6.42 | stream_fast | 3.85 | pass |
| gi-numeric-hi-c10-1m | fresh | 6.43 | stream_fast | 3.82 | pass |
| gi-numeric-hi-c100k-1m | warm | 6.60 | stream_fast | 3.93 | pass |
| gi-numeric-hi-c100k-1m | fresh | 6.42 | stream_fast | 3.79 | pass |
| gi-numeric-hi-c1k-1m | warm | 6.68 | stream_fast | 3.92 | pass |
| gi-numeric-hi-c1k-1m | fresh | 6.60 | stream_fast | 3.83 | pass |
| gi-numeric-lo-c10-1m | warm | 5.47 | stream_fast | 3.26 | pass |
| gi-numeric-lo-c10-1m | fresh | 5.46 | stream_fast | 3.16 | pass |
| gi-numeric-lo-c100k-1m | warm | 4.83 | stream_fast | 3.03 | pass |
| gi-numeric-lo-c100k-1m | fresh | 4.44 | stream_fast | 2.76 | pass |
| gi-numeric-lo-c1k-1m | warm | 5.14 | stream_fast | 2.95 | pass |
| gi-numeric-lo-c1k-1m | fresh | 5.19 | stream_fast | 2.92 | pass |
| gs-mixed-hi-c10-1m | warm | 12.39 | stream_hybrid | 3.44 | pass |
| gs-mixed-hi-c10-1m | fresh | 12.53 | stream_hybrid | 3.43 | pass |
| gs-mixed-hi-c100k-1m | warm | 13.23 | stream_hybrid | 3.63 | pass |
| gs-mixed-hi-c100k-1m | fresh | 12.87 | stream_hybrid | 3.52 | pass |
| gs-mixed-hi-c1k-1m | warm | 13.51 | stream_hybrid | 3.65 | pass |
| gs-mixed-hi-c1k-1m | fresh | 13.61 | stream_hybrid | 3.63 | pass |
| gs-mixed-lo-c10-1m | warm | 10.72 | stream_hybrid | 3.03 | pass |
| gs-mixed-lo-c10-1m | fresh | 10.89 | stream_hybrid | 3.05 | pass |
| gs-mixed-lo-c100k-1m | warm | 8.94 | stream_hybrid | 2.86 | pass |
| gs-mixed-lo-c100k-1m | fresh | 8.32 | stream_hybrid | 2.59 | pass |
| gs-mixed-lo-c1k-1m | warm | 10.97 | stream_hybrid | 2.99 | pass |
| gs-mixed-lo-c1k-1m | fresh | 11.33 | stream_hybrid | 3.01 | pass |
| gs-mixnq-hi-c10-1m | warm | 4.97 | stream_fast | 3.46 | pass |
| gs-mixnq-hi-c10-1m | fresh | 5.07 | stream_fast | 3.48 | pass |
| gs-mixnq-hi-c100k-1m | warm | 5.27 | stream_fast | 3.61 | pass |
| gs-mixnq-hi-c100k-1m | fresh | 5.22 | stream_fast | 3.52 | pass |
| gs-mixnq-hi-c1k-1m | warm | 5.49 | stream_fast | 3.74 | pass |
| gs-mixnq-hi-c1k-1m | fresh | 5.57 | stream_fast | 3.69 | pass |
| gs-mixnq-lo-c10-1m | warm | 4.50 | stream_fast | 3.03 | pass |
| gs-mixnq-lo-c10-1m | fresh | 4.62 | stream_fast | 3.04 | pass |
| gs-mixnq-lo-c100k-1m | warm | 4.06 | stream_fast | 2.92 | pass |
| gs-mixnq-lo-c100k-1m | fresh | 3.73 | stream_fast | 2.62 | pass |
| gs-mixnq-lo-c1k-1m | warm | 4.81 | stream_fast | 3.02 | pass |
| gs-mixnq-lo-c1k-1m | fresh | 4.89 | stream_fast | 2.97 | pass |
| gs-wide-hi-c1k-1m | warm | 6.73 | stream_fast | 5.54 | pass |
| gs-wide-hi-c1k-1m | fresh | 6.68 | stream_fast | 5.53 | pass |
| gs-wide-lo-c1k-1m | warm | 5.77 | stream_fast | 4.55 | pass |
| gs-wide-lo-c1k-1m | fresh | 5.87 | stream_fast | 4.57 | pass |

### Streaming sum: at least 5x the best JIT PHP, RSS within 1.5x (last column: native RSS / streaming PHP RSS)

| cell | series | best PHP / native | best PHP | RSS ratio | verdict |
| --- | --- | ---: | --- | ---: | --- |
| scan-mixed-hi-1m | warm | 15.35 | stream_hybrid | 0.84 | pass |
| scan-mixed-hi-1m | fresh | 14.32 | stream_hybrid | 0.84 | pass |
| scan-mixed-lo-1m | warm | 13.71 | stream_hybrid | 0.84 | pass |
| scan-mixed-lo-1m | fresh | 13.61 | stream_hybrid | 0.84 | pass |
| scan-mixnq-hi-1m | warm | 5.52 | stream_fast | 0.84 | pass |
| scan-mixnq-hi-1m | fresh | 5.43 | stream_fast | 0.84 | pass |
| scan-mixnq-lo-1m | warm | 5.25 | stream_fast | 0.84 | pass |
| scan-mixnq-lo-1m | fresh | 5.28 | stream_fast | 0.84 | pass |
| scan-numeric-hi-1m | warm | 6.68 | stream_fast | 0.84 | pass |
| scan-numeric-hi-1m | fresh | 6.69 | stream_fast | 0.84 | pass |
| scan-numeric-lo-1m | warm | 6.58 | stream_fast | 0.84 | pass |
| scan-numeric-lo-1m | fresh | 6.33 | stream_fast | 0.84 | pass |
| scan-wide-hi-1m | warm | 7.08 | stream_fast | 0.84 | pass |
| scan-wide-hi-1m | fresh | 7.04 | stream_fast | 0.84 | pass |
| scan-wide-lo-1m | warm | 6.97 | stream_fast | 0.84 | pass |
| scan-wide-lo-1m | fresh | 6.88 | stream_fast | 0.84 | pass |

### Rows: at least 2x the best PHP loader

| cell | series | best PHP / native | best PHP |  | verdict |
| --- | --- | ---: | --- | ---: | --- |
| rows-mixed-hi-1m | warm | 7.42 | stream_hybrid |  | pass |
| rows-mixed-hi-1m | fresh | 7.52 | stream_hybrid |  | pass |
| rows-mixed-lo-1m | warm | 4.55 | stream_hybrid |  | pass |
| rows-mixed-lo-1m | fresh | 3.01 | stream_hybrid |  | pass |
| rows-numeric-hi-1m | warm | 6.58 | stream_fast |  | pass |
| rows-numeric-hi-1m | fresh | 6.50 | stream_fast |  | pass |
| rows-numeric-lo-1m | warm | 4.33 | stream_fast |  | pass |
| rows-numeric-lo-1m | fresh | 3.10 | stream_fast |  | pass |
| rows-wide-hi-1m | warm | 5.14 | stream_fast |  | pass |
| rows-wide-hi-1m | fresh | 5.33 | stream_fast |  | pass |
| rows-wide-lo-1m | warm | 3.74 | stream_fast |  | pass |
| rows-wide-lo-1m | fresh | 2.83 | stream_fast |  | pass |


### Hypotheses at 100,000 rows

#### Group-by: at least 3x the best JIT PHP and faster than duckdb_1t (last column: duckdb_1t / native)

| cell | series | best PHP / native | best PHP | duckdb_1t / native | verdict |
| --- | --- | ---: | --- | ---: | --- |
| gi-numeric-hi-c10-100k | warm | 6.90 | stream_fast | 10.50 | pass |
| gi-numeric-hi-c10-100k | fresh | 6.44 | stream_fast | 9.44 | pass |
| gi-numeric-hi-c100k-100k | warm | 6.93 | stream_fast | 10.29 | pass |
| gi-numeric-hi-c100k-100k | fresh | 6.45 | stream_fast | 9.14 | pass |
| gi-numeric-hi-c1k-100k | warm | 7.00 | stream_fast | 10.41 | pass |
| gi-numeric-hi-c1k-100k | fresh | 6.58 | stream_fast | 9.22 | pass |
| gi-numeric-lo-c10-100k | warm | 5.58 | stream_fast | 8.60 | pass |
| gi-numeric-lo-c10-100k | fresh | 5.82 | stream_fast | 7.91 | pass |
| gi-numeric-lo-c100k-100k | warm | 4.20 | stream_fast | 7.29 | pass |
| gi-numeric-lo-c100k-100k | fresh | 3.09 | stream_fast | 4.32 | pass |
| gi-numeric-lo-c1k-100k | warm | 5.25 | stream_fast | 7.65 | pass |
| gi-numeric-lo-c1k-100k | fresh | 5.63 | stream_fast | 7.24 | pass |
| gs-mixed-hi-c10-100k | warm | 13.52 | stream_hybrid | 7.87 | pass |
| gs-mixed-hi-c10-100k | fresh | 12.25 | stream_hybrid | 6.76 | pass |
| gs-mixed-hi-c100k-100k | warm | 14.46 | stream_hybrid | 8.45 | pass |
| gs-mixed-hi-c100k-100k | fresh | 12.76 | stream_hybrid | 7.09 | pass |
| gs-mixed-hi-c1k-100k | warm | 15.15 | stream_hybrid | 8.66 | pass |
| gs-mixed-hi-c1k-100k | fresh | 13.23 | stream_hybrid | 7.33 | pass |
| gs-mixed-lo-c10-100k | warm | 11.47 | stream_hybrid | 6.82 | pass |
| gs-mixed-lo-c10-100k | fresh | 10.90 | stream_hybrid | 5.94 | pass |
| gs-mixed-lo-c100k-100k | warm | 7.73 | stream_hybrid | 5.78 | pass |
| gs-mixed-lo-c100k-100k | fresh | 5.63 | stream_hybrid | 3.70 | pass |
| gs-mixed-lo-c1k-100k | warm | 11.58 | stream_hybrid | 6.74 | pass |
| gs-mixed-lo-c1k-100k | fresh | 11.36 | stream_hybrid | 5.97 | pass |
| gs-mixnq-hi-c10-100k | warm | 5.35 | stream_fast | 7.90 | pass |
| gs-mixnq-hi-c10-100k | fresh | 5.11 | stream_fast | 7.01 | pass |
| gs-mixnq-hi-c100k-100k | warm | 5.82 | stream_fast | 8.81 | pass |
| gs-mixnq-hi-c100k-100k | fresh | 5.37 | stream_fast | 7.55 | pass |
| gs-mixnq-hi-c1k-100k | warm | 5.90 | stream_fast | 8.85 | pass |
| gs-mixnq-hi-c1k-100k | fresh | 5.53 | stream_fast | 7.64 | pass |
| gs-mixnq-lo-c10-100k | warm | 4.79 | stream_fast | 6.74 | pass |
| gs-mixnq-lo-c10-100k | fresh | 4.80 | stream_fast | 6.08 | pass |
| gs-mixnq-lo-c100k-100k | warm | 3.63 | stream_fast | 5.99 | pass |
| gs-mixnq-lo-c100k-100k | fresh | 2.71 | stream_fast | 3.75 | FAIL |
| gs-mixnq-lo-c1k-100k | warm | 5.14 | stream_fast | 7.04 | pass |
| gs-mixnq-lo-c1k-100k | fresh | 5.04 | stream_fast | 6.12 | pass |
| gs-wide-hi-c1k-100k | warm | 7.35 | stream_fast | 10.30 | pass |
| gs-wide-hi-c1k-100k | fresh | 6.36 | stream_fast | 8.57 | pass |
| gs-wide-lo-c1k-100k | warm | 6.09 | stream_fast | 8.27 | pass |
| gs-wide-lo-c1k-100k | fresh | 5.84 | stream_fast | 7.12 | pass |

#### Streaming sum: at least 5x the best JIT PHP, RSS within 1.5x (last column: native RSS / streaming PHP RSS)

| cell | series | best PHP / native | best PHP | RSS ratio | verdict |
| --- | --- | ---: | --- | ---: | --- |
| scan-mixed-hi-100k | warm | 15.50 | stream_hybrid | 0.84 | pass |
| scan-mixed-hi-100k | fresh | 13.56 | stream_hybrid | 0.84 | pass |
| scan-mixed-lo-100k | warm | 14.78 | stream_hybrid | 0.84 | pass |
| scan-mixed-lo-100k | fresh | 13.57 | stream_hybrid | 0.84 | pass |
| scan-mixnq-hi-100k | warm | 6.09 | stream_fast | 0.84 | pass |
| scan-mixnq-hi-100k | fresh | 5.49 | stream_fast | 0.84 | pass |
| scan-mixnq-lo-100k | warm | 5.78 | stream_fast | 0.84 | pass |
| scan-mixnq-lo-100k | fresh | 5.42 | stream_fast | 0.84 | pass |
| scan-numeric-hi-100k | warm | 7.14 | stream_fast | 0.84 | pass |
| scan-numeric-hi-100k | fresh | 6.74 | stream_fast | 0.84 | pass |
| scan-numeric-lo-100k | warm | 6.82 | stream_fast | 0.84 | pass |
| scan-numeric-lo-100k | fresh | 6.55 | stream_fast | 0.84 | pass |
| scan-wide-hi-100k | warm | 8.03 | stream_fast | 0.84 | pass |
| scan-wide-hi-100k | fresh | 6.63 | stream_fast | 0.84 | pass |
| scan-wide-lo-100k | warm | 8.13 | stream_fast | 0.84 | pass |
| scan-wide-lo-100k | fresh | 6.70 | stream_fast | 0.84 | pass |

#### Rows: at least 2x the best PHP loader

| cell | series | best PHP / native | best PHP |  | verdict |
| --- | --- | ---: | --- | ---: | --- |
| rows-mixed-hi-100k | warm | 7.68 | stream_hybrid |  | pass |
| rows-mixed-hi-100k | fresh | 7.79 | stream_hybrid |  | pass |
| rows-mixed-lo-100k | warm | 4.09 | stream_hybrid |  | pass |
| rows-mixed-lo-100k | fresh | 3.40 | stream_hybrid |  | pass |
| rows-numeric-hi-100k | warm | 7.08 | stream_fast |  | pass |
| rows-numeric-hi-100k | fresh | 6.64 | stream_fast |  | pass |
| rows-numeric-lo-100k | warm | 4.58 | stream_fast |  | pass |
| rows-numeric-lo-100k | fresh | 3.27 | stream_fast |  | pass |
| rows-wide-hi-100k | warm | 5.23 | stream_fast |  | pass |
| rows-wide-hi-100k | fresh | 7.21 | stream_fast |  | pass |
| rows-wide-lo-100k | warm | 3.77 | stream_fast |  | pass |
| rows-wide-lo-100k | fresh | 3.72 | stream_fast |  | pass |

### Hypotheses at 10,000,000 rows

At this size only the streaming PHP loops ran on most workloads (see above), so `best PHP` is the best of those.

#### Group-by: at least 3x the best JIT PHP and faster than duckdb_1t (last column: duckdb_1t / native)

| cell | series | best PHP / native | best PHP | duckdb_1t / native | verdict |
| --- | --- | ---: | --- | ---: | --- |
| gi-numeric-hi-c10-10m | warm | 6.51 | stream_fast | 2.98 | pass |
| gi-numeric-hi-c10-10m | fresh | 6.54 | stream_fast | 3.02 | pass |
| gi-numeric-hi-c100k-10m | warm | 6.60 | stream_fast | 3.07 | pass |
| gi-numeric-hi-c100k-10m | fresh | 6.50 | stream_fast | 3.02 | pass |
| gi-numeric-hi-c1k-10m | warm | 6.73 | stream_fast | 3.04 | pass |
| gi-numeric-hi-c1k-10m | fresh | 6.71 | stream_fast | 3.03 | pass |
| gi-numeric-lo-c10-10m | warm | 5.44 | stream_fast | 2.53 | pass |
| gi-numeric-lo-c10-10m | fresh | 5.49 | stream_fast | 2.54 | pass |
| gi-numeric-lo-c100k-10m | warm | 5.29 | stream_fast | 2.42 | pass |
| gi-numeric-lo-c100k-10m | fresh | 5.20 | stream_fast | 2.38 | pass |
| gi-numeric-lo-c1k-10m | warm | 5.16 | stream_fast | 2.35 | pass |
| gi-numeric-lo-c1k-10m | fresh | 5.29 | stream_fast | 2.38 | pass |
| gs-mixed-hi-c10-10m | warm | 12.60 | stream_hybrid | 2.58 | pass |
| gs-mixed-hi-c10-10m | fresh | 12.24 | stream_hybrid | 2.58 | pass |
| gs-mixed-hi-c100k-10m | warm | 12.99 | stream_hybrid | 2.63 | pass |
| gs-mixed-hi-c100k-10m | fresh | 12.97 | stream_hybrid | 2.60 | pass |
| gs-mixed-hi-c1k-10m | warm | 13.69 | stream_hybrid | 2.70 | pass |
| gs-mixed-hi-c1k-10m | fresh | 13.58 | stream_hybrid | 2.68 | pass |
| gs-mixed-lo-c10-10m | warm | 10.56 | stream_hybrid | 2.31 | pass |
| gs-mixed-lo-c10-10m | fresh | 10.61 | stream_hybrid | 2.33 | pass |
| gs-mixed-lo-c100k-10m | warm | 9.88 | stream_hybrid | 2.15 | pass |
| gs-mixed-lo-c100k-10m | fresh | 9.72 | stream_hybrid | 2.14 | pass |
| gs-mixed-lo-c1k-10m | warm | 11.01 | stream_hybrid | 2.26 | pass |
| gs-mixed-lo-c1k-10m | fresh | 11.01 | stream_hybrid | 2.24 | pass |
| gs-mixnq-hi-c10-10m | warm | 5.02 | stream_fast | 2.59 | pass |
| gs-mixnq-hi-c10-10m | fresh | 5.00 | stream_fast | 2.59 | pass |
| gs-mixnq-hi-c100k-10m | warm | 5.30 | stream_fast | 2.70 | pass |
| gs-mixnq-hi-c100k-10m | fresh | 5.22 | stream_fast | 2.66 | pass |
| gs-mixnq-hi-c1k-10m | warm | 5.50 | stream_fast | 2.75 | pass |
| gs-mixnq-hi-c1k-10m | fresh | 5.51 | stream_fast | 2.75 | pass |
| gs-mixnq-lo-c10-10m | warm | 4.53 | stream_fast | 2.33 | pass |
| gs-mixnq-lo-c10-10m | fresh | 4.55 | stream_fast | 2.34 | pass |
| gs-mixnq-lo-c100k-10m | warm | 4.50 | stream_fast | 2.15 | pass |
| gs-mixnq-lo-c100k-10m | fresh | 4.36 | stream_fast | 2.12 | pass |
| gs-mixnq-lo-c1k-10m | warm | 4.86 | stream_fast | 2.24 | pass |
| gs-mixnq-lo-c1k-10m | fresh | 4.89 | stream_fast | 2.24 | pass |
| gs-wide-hi-c1k-10m | warm | 6.50 | stream_fast | 4.65 | pass |
| gs-wide-hi-c1k-10m | fresh | 6.50 | stream_fast | 4.63 | pass |
| gs-wide-lo-c1k-10m | warm | 5.58 | stream_fast | 3.88 | pass |
| gs-wide-lo-c1k-10m | fresh | 5.79 | stream_fast | 3.88 | pass |

#### Streaming sum: at least 5x the best JIT PHP, RSS within 1.5x (last column: native RSS / streaming PHP RSS)

| cell | series | best PHP / native | best PHP | RSS ratio | verdict |
| --- | --- | ---: | --- | ---: | --- |
| scan-mixed-hi-10m | warm | 14.09 | stream_hybrid | 0.84 | pass |
| scan-mixed-hi-10m | fresh | 14.13 | stream_hybrid | 0.84 | pass |
| scan-mixed-lo-10m | warm | 13.39 | stream_hybrid | 0.84 | pass |
| scan-mixed-lo-10m | fresh | 13.65 | stream_hybrid | 0.84 | pass |
| scan-mixnq-hi-10m | warm | 5.48 | stream_fast | 0.84 | pass |
| scan-mixnq-hi-10m | fresh | 5.50 | stream_fast | 0.84 | pass |
| scan-mixnq-lo-10m | warm | 5.27 | stream_fast | 0.84 | pass |
| scan-mixnq-lo-10m | fresh | 5.21 | stream_fast | 0.84 | pass |
| scan-numeric-hi-10m | warm | 6.82 | stream_fast | 0.84 | pass |
| scan-numeric-hi-10m | fresh | 6.82 | stream_fast | 0.84 | pass |
| scan-numeric-lo-10m | warm | 6.35 | stream_fast | 0.84 | pass |
| scan-numeric-lo-10m | fresh | 6.31 | stream_fast | 0.84 | pass |
| scan-wide-hi-10m | warm | 6.84 | stream_fast | 0.84 | pass |
| scan-wide-hi-10m | fresh | 6.93 | stream_fast | 0.84 | pass |
| scan-wide-lo-10m | warm | 6.98 | stream_fast | 0.84 | pass |
| scan-wide-lo-10m | fresh | 6.92 | stream_fast | 0.84 | pass |

#### Rows: at least 2x the best PHP loader

| cell | series | best PHP / native | best PHP |  | verdict |
| --- | --- | ---: | --- | ---: | --- |
| rows-mixed-hi-10m | warm | 7.39 | stream_hybrid |  | pass |
| rows-mixed-hi-10m | fresh | 7.38 | stream_hybrid |  | pass |
| rows-mixed-lo-10m | warm | 4.22 | stream_hybrid |  | pass |
| rows-mixed-lo-10m | fresh | 2.82 | stream_hybrid |  | pass |
| rows-numeric-hi-10m | warm | 6.62 | stream_fast |  | pass |
| rows-numeric-hi-10m | fresh | 6.52 | stream_fast |  | pass |
| rows-numeric-lo-10m | warm | 4.19 | stream_fast |  | pass |
| rows-numeric-lo-10m | fresh | 2.99 | stream_fast |  | pass |
| rows-wide-hi-10m | warm | 5.08 | stream_fast |  | pass |
| rows-wide-hi-10m | fresh | 5.13 | stream_fast |  | pass |
| rows-wide-lo-10m | warm | 3.50 | stream_fast |  | pass |
| rows-wide-lo-10m | fresh | 2.47 | stream_fast |  | pass |

### Losses: every cell where another implementation beat native

Below 1 means the other implementation was faster than native auto (ratio = its time / native). Scalar native is listed in its own table below; it is slower than AVX2 by construction.

| cell | series | implementation | its time / native |
| --- | --- | --- | ---: |
| gi-numeric-hi-c10-10m | warm | duckdb | 1.00 |
| gi-numeric-hi-c1k-10m | warm | duckdb | 0.99 |
| gi-numeric-hi-c1k-10m | fresh | duckdb | 0.99 |
| gi-numeric-lo-c10-10m | warm | duckdb | 0.82 |
| gi-numeric-lo-c10-10m | fresh | duckdb | 0.82 |
| gi-numeric-lo-c100k-10m | warm | duckdb | 0.88 |
| gi-numeric-lo-c100k-10m | fresh | duckdb | 0.86 |
| gi-numeric-lo-c1k-10m | warm | duckdb | 0.75 |
| gi-numeric-lo-c1k-10m | warm | pdo_duckdb | 0.78 |
| gi-numeric-lo-c1k-10m | fresh | duckdb | 0.76 |
| gi-numeric-lo-c1k-10m | fresh | pdo_duckdb | 0.81 |
| gs-mixed-hi-c10-10m | warm | duckdb | 0.97 |
| gs-mixed-hi-c10-10m | fresh | duckdb | 0.97 |
| gs-mixed-hi-c100k-10m | warm | duckdb | 1.00 |
| gs-mixed-hi-c100k-10m | fresh | duckdb | 0.99 |
| gs-mixed-lo-c10-10m | warm | duckdb | 0.81 |
| gs-mixed-lo-c10-10m | fresh | duckdb | 0.82 |
| gs-mixed-lo-c100k-10m | warm | duckdb | 0.84 |
| gs-mixed-lo-c100k-10m | fresh | duckdb | 0.83 |
| gs-mixed-lo-c1k-10m | warm | duckdb | 0.81 |
| gs-mixed-lo-c1k-10m | warm | pdo_duckdb | 0.83 |
| gs-mixed-lo-c1k-10m | fresh | duckdb | 0.80 |
| gs-mixed-lo-c1k-10m | fresh | pdo_duckdb | 0.84 |
| gs-mixnq-hi-c10-10m | warm | duckdb | 0.99 |
| gs-mixnq-hi-c10-10m | fresh | duckdb | 0.99 |
| gs-mixnq-lo-c10-10m | warm | duckdb | 0.83 |
| gs-mixnq-lo-c10-10m | fresh | duckdb | 0.83 |
| gs-mixnq-lo-c100k-10m | warm | duckdb | 0.84 |
| gs-mixnq-lo-c100k-10m | fresh | duckdb | 0.83 |
| gs-mixnq-lo-c1k-10m | warm | duckdb | 0.81 |
| gs-mixnq-lo-c1k-10m | fresh | duckdb | 0.82 |

The losses are all against DuckDB using four cores (its CLI in 27 rows of the table, `pdo_duckdb` in 4), on group-by cells of 10,000,000 rows: where most rows are kept (`lo`) it takes 0.75 to 0.88 of BatchFlow's time, and on the `hi` cells it ties (0.97 to 1.00). BatchFlow is single-threaded by design. Single-threaded DuckDB, SQLite and every PHP loop are slower than native in every measured cell.

### AVX2 and scalar for every native cell

The AVX2 build carries the win on the wide shapes (a scan of the wide shape is 3.0x slower scalar); on narrow shapes and table cells the scalar ratio is 1.1x to 1.5x. Warm and fresh side by side.

| cell | native warm ms | scalar warm ms | scalar / native warm | native fresh ms | scalar fresh ms | scalar / native fresh |
| --- | ---: | ---: | ---: | ---: | ---: | ---: |
| gi-numeric-hi-c10-100k | 5.38 | 7.83 | 1.46 | 5.98 | 8.51 | 1.42 |
| gi-numeric-hi-c10-10m | 575.84 | 833.13 | 1.45 | 568.49 | 828.33 | 1.46 |
| gi-numeric-hi-c10-1m | 57.14 | 82.53 | 1.44 | 57.50 | 82.60 | 1.44 |
| gi-numeric-hi-c100k-100k | 5.61 | 8.11 | 1.45 | 6.32 | 8.90 | 1.41 |
| gi-numeric-hi-c100k-10m | 598.73 | 869.01 | 1.45 | 608.97 | 867.93 | 1.43 |
| gi-numeric-hi-c100k-1m | 59.40 | 88.23 | 1.49 | 61.59 | 87.51 | 1.42 |
| gi-numeric-hi-c1k-100k | 5.46 | 8.11 | 1.49 | 6.16 | 8.68 | 1.41 |
| gi-numeric-hi-c1k-10m | 580.51 | 842.06 | 1.45 | 582.60 | 840.08 | 1.44 |
| gi-numeric-hi-c1k-1m | 57.59 | 82.59 | 1.43 | 59.04 | 84.14 | 1.43 |
| gi-numeric-lo-c10-100k | 6.86 | 9.23 | 1.35 | 7.45 | 10.00 | 1.34 |
| gi-numeric-lo-c10-10m | 720.40 | 982.92 | 1.36 | 717.10 | 972.71 | 1.36 |
| gi-numeric-lo-c10-1m | 70.89 | 96.61 | 1.36 | 73.02 | 98.26 | 1.35 |
| gi-numeric-lo-c100k-100k | 11.59 | 14.22 | 1.23 | 19.54 | 22.23 | 1.14 |
| gi-numeric-lo-c100k-10m | 855.77 | 1120.16 | 1.31 | 869.97 | 1118.51 | 1.29 |
| gi-numeric-lo-c100k-1m | 98.86 | 124.52 | 1.26 | 108.48 | 132.89 | 1.23 |
| gi-numeric-lo-c1k-100k | 7.85 | 10.36 | 1.32 | 8.30 | 10.93 | 1.32 |
| gi-numeric-lo-c1k-10m | 791.96 | 1052.94 | 1.33 | 780.46 | 1055.47 | 1.35 |
| gi-numeric-lo-c1k-1m | 79.82 | 106.86 | 1.34 | 80.74 | 105.15 | 1.30 |
| gs-mixed-hi-c10-100k | 8.49 | 15.72 | 1.85 | 9.88 | 17.45 | 1.77 |
| gs-mixed-hi-c10-10m | 955.51 | 1678.54 | 1.76 | 953.36 | 1696.76 | 1.78 |
| gs-mixed-hi-c10-1m | 93.47 | 168.55 | 1.80 | 93.89 | 169.27 | 1.80 |
| gs-mixed-hi-c100k-100k | 7.89 | 15.13 | 1.92 | 9.41 | 16.89 | 1.79 |
| gs-mixed-hi-c100k-10m | 905.34 | 1634.32 | 1.81 | 914.53 | 1665.44 | 1.82 |
| gs-mixed-hi-c100k-1m | 88.05 | 162.88 | 1.85 | 90.65 | 165.61 | 1.83 |
| gs-mixed-hi-c1k-100k | 7.64 | 14.82 | 1.94 | 9.03 | 16.57 | 1.84 |
| gs-mixed-hi-c1k-10m | 855.19 | 1594.01 | 1.86 | 860.79 | 1595.18 | 1.85 |
| gs-mixed-hi-c1k-1m | 85.18 | 158.60 | 1.86 | 85.58 | 160.41 | 1.87 |
| gs-mixed-lo-c10-100k | 10.40 | 17.71 | 1.70 | 11.93 | 19.27 | 1.61 |
| gs-mixed-lo-c10-10m | 1158.81 | 1880.85 | 1.62 | 1149.51 | 1886.81 | 1.64 |
| gs-mixed-lo-c10-1m | 114.06 | 187.99 | 1.65 | 113.38 | 186.66 | 1.65 |
| gs-mixed-lo-c100k-100k | 17.58 | 24.77 | 1.41 | 27.44 | 35.04 | 1.28 |
| gs-mixed-lo-c100k-10m | 1385.74 | 2167.69 | 1.56 | 1390.63 | 2205.20 | 1.59 |
| gs-mixed-lo-c100k-1m | 152.25 | 228.68 | 1.50 | 168.23 | 242.57 | 1.44 |
| gs-mixed-lo-c1k-100k | 10.42 | 17.41 | 1.67 | 11.75 | 19.17 | 1.63 |
| gs-mixed-lo-c1k-10m | 1115.97 | 1854.22 | 1.66 | 1125.49 | 1868.73 | 1.66 |
| gs-mixed-lo-c1k-1m | 111.67 | 185.20 | 1.66 | 110.93 | 184.12 | 1.66 |
| gs-mixnq-hi-c10-100k | 8.38 | 15.35 | 1.83 | 9.45 | 16.84 | 1.78 |
| gs-mixnq-hi-c10-10m | 915.63 | 1642.83 | 1.79 | 914.40 | 1646.37 | 1.80 |
| gs-mixnq-hi-c10-1m | 90.96 | 162.98 | 1.79 | 90.51 | 163.89 | 1.81 |
| gs-mixnq-hi-c100k-100k | 7.66 | 14.76 | 1.93 | 8.95 | 16.25 | 1.82 |
| gs-mixnq-hi-c100k-10m | 864.25 | 1601.89 | 1.85 | 876.21 | 1602.22 | 1.83 |
| gs-mixnq-hi-c100k-1m | 85.73 | 159.07 | 1.86 | 87.86 | 159.75 | 1.82 |
| gs-mixnq-hi-c1k-100k | 7.46 | 14.56 | 1.95 | 8.64 | 15.95 | 1.85 |
| gs-mixnq-hi-c1k-10m | 822.07 | 1543.45 | 1.88 | 824.10 | 1555.32 | 1.89 |
| gs-mixnq-hi-c1k-1m | 81.23 | 155.59 | 1.92 | 82.35 | 154.45 | 1.88 |
| gs-mixnq-lo-c10-100k | 10.32 | 17.18 | 1.66 | 11.44 | 18.69 | 1.63 |
| gs-mixnq-lo-c10-10m | 1116.83 | 1836.39 | 1.64 | 1110.32 | 1831.41 | 1.65 |
| gs-mixnq-lo-c10-1m | 111.83 | 185.10 | 1.66 | 111.41 | 183.49 | 1.65 |
| gs-mixnq-lo-c100k-100k | 16.99 | 24.66 | 1.45 | 27.14 | 34.27 | 1.26 |
| gs-mixnq-lo-c100k-10m | 1329.16 | 2100.34 | 1.58 | 1347.79 | 2080.09 | 1.54 |
| gs-mixnq-lo-c100k-1m | 146.66 | 222.06 | 1.51 | 163.33 | 236.15 | 1.45 |
| gs-mixnq-lo-c1k-100k | 9.84 | 17.06 | 1.73 | 11.32 | 18.70 | 1.65 |
| gs-mixnq-lo-c1k-10m | 1087.83 | 1806.05 | 1.66 | 1085.30 | 1808.85 | 1.67 |
| gs-mixnq-lo-c1k-1m | 106.88 | 179.93 | 1.68 | 108.70 | 182.39 | 1.68 |
| gs-wide-hi-c1k-100k | 10.13 | 29.99 | 2.96 | 12.18 | 31.55 | 2.59 |
| gs-wide-hi-c1k-10m | 1154.24 | 3085.95 | 2.67 | 1159.85 | 3090.89 | 2.66 |
| gs-wide-hi-c1k-1m | 112.21 | 306.91 | 2.74 | 112.48 | 305.19 | 2.71 |
| gs-wide-lo-c1k-100k | 13.05 | 32.21 | 2.47 | 15.15 | 34.41 | 2.27 |
| gs-wide-lo-c1k-10m | 1442.30 | 3368.36 | 2.34 | 1440.89 | 3381.15 | 2.35 |
| gs-wide-lo-c1k-1m | 142.62 | 334.70 | 2.35 | 142.21 | 332.88 | 2.34 |
| gt-mixed-hi-c10-100k | 65.41 | 73.20 | 1.12 | 67.61 | 74.61 | 1.10 |
| gt-mixed-hi-c10-10m | 5029.38 | 5849.15 | 1.16 | 5006.13 | 5805.47 | 1.16 |
| gt-mixed-hi-c10-1m | 524.65 | 599.69 | 1.14 | 527.52 | 603.68 | 1.14 |
| gt-mixed-hi-c100k-100k | 70.56 | 81.33 | 1.15 | 75.56 | 82.78 | 1.10 |
| gt-mixed-hi-c100k-10m | 5948.71 | 6816.65 | 1.15 | 5917.77 | 7082.83 | 1.20 |
| gt-mixed-hi-c100k-1m | 627.65 | 762.97 | 1.22 | 639.84 | 739.59 | 1.16 |
| gt-mixed-hi-c1k-100k | 66.01 | 74.20 | 1.12 | 68.14 | 75.60 | 1.11 |
| gt-mixed-hi-c1k-10m | 5034.99 | 5765.21 | 1.15 | 5105.73 | 5834.33 | 1.14 |
| gt-mixed-hi-c1k-1m | 526.06 | 603.88 | 1.15 | 533.72 | 603.05 | 1.13 |
| gt-mixed-lo-c10-100k | 76.50 | 83.54 | 1.09 | 78.79 | 86.24 | 1.09 |
| gt-mixed-lo-c10-10m | 5171.50 | 5918.19 | 1.14 | 5181.67 | 5969.87 | 1.15 |
| gt-mixed-lo-c10-1m | 555.72 | 638.79 | 1.15 | 563.06 | 637.78 | 1.13 |
| gt-mixed-lo-c100k-100k | 160.37 | 171.32 | 1.07 | 186.97 | 194.56 | 1.04 |
| gt-mixed-lo-c100k-10m | 6198.77 | 7295.01 | 1.18 | 6271.78 | 7026.60 | 1.12 |
| gt-mixed-lo-c100k-1m | 754.22 | 877.24 | 1.16 | 787.17 | 901.57 | 1.15 |
| gt-mixed-lo-c1k-100k | 78.50 | 86.17 | 1.10 | 81.08 | 88.47 | 1.09 |
| gt-mixed-lo-c1k-10m | 5142.31 | 5848.32 | 1.14 | 5184.76 | 5944.61 | 1.15 |
| gt-mixed-lo-c1k-1m | 564.34 | 636.51 | 1.13 | 565.37 | 644.80 | 1.14 |
| gti-numeric-lo-c10-100k | 45.44 | 47.91 | 1.05 | 47.23 | 49.84 | 1.06 |
| gti-numeric-lo-c10-10m | 2557.45 | 2811.91 | 1.10 | 2584.74 | 2851.74 | 1.10 |
| gti-numeric-lo-c10-1m | 270.33 | 295.23 | 1.09 | 273.88 | 302.04 | 1.10 |
| gti-numeric-lo-c100k-100k | 101.97 | 103.49 | 1.01 | 122.81 | 125.20 | 1.02 |
| gti-numeric-lo-c100k-10m | 3050.98 | 3287.94 | 1.08 | 3040.31 | 3293.79 | 1.08 |
| gti-numeric-lo-c100k-1m | 367.51 | 403.20 | 1.10 | 399.07 | 431.68 | 1.08 |
| gti-numeric-lo-c1k-100k | 47.02 | 50.14 | 1.07 | 49.22 | 51.81 | 1.05 |
| gti-numeric-lo-c1k-10m | 2629.13 | 2894.09 | 1.10 | 2627.67 | 2874.77 | 1.09 |
| gti-numeric-lo-c1k-1m | 278.75 | 304.79 | 1.09 | 282.72 | 308.99 | 1.09 |
| rows-mixed-hi-100k | 18.46 | 26.28 | 1.42 | 20.02 | 27.36 | 1.37 |
| rows-mixed-hi-10m | 1915.59 | 2659.01 | 1.39 | 1939.23 | 2677.50 | 1.38 |
| rows-mixed-hi-1m | 189.47 | 265.68 | 1.40 | 192.43 | 265.81 | 1.38 |
| rows-mixed-lo-100k | 38.91 | 43.54 | 1.12 | 65.33 | 72.54 | 1.11 |
| rows-mixed-lo-10m | 3912.48 | 4754.02 | 1.22 | 7147.19 | 7932.38 | 1.11 |
| rows-mixed-lo-1m | 356.85 | 431.43 | 1.21 | 654.54 | 729.05 | 1.11 |
| rows-numeric-hi-100k | 8.80 | 11.42 | 1.30 | 9.69 | 12.30 | 1.27 |
| rows-numeric-hi-10m | 944.87 | 1195.76 | 1.27 | 959.72 | 1205.18 | 1.26 |
| rows-numeric-hi-1m | 93.68 | 120.87 | 1.29 | 96.20 | 120.97 | 1.26 |
| rows-numeric-lo-100k | 16.11 | 17.65 | 1.10 | 28.17 | 30.51 | 1.08 |
| rows-numeric-lo-10m | 1762.86 | 2021.24 | 1.15 | 2919.07 | 3177.13 | 1.09 |
| rows-numeric-lo-1m | 169.31 | 193.26 | 1.14 | 281.57 | 304.35 | 1.08 |
| rows-wide-hi-100k | 48.52 | 64.70 | 1.33 | 51.22 | 66.15 | 1.29 |
| rows-wide-hi-10m | 4983.49 | 6522.91 | 1.31 | 5023.67 | 6569.21 | 1.31 |
| rows-wide-hi-1m | 495.66 | 650.35 | 1.31 | 502.70 | 656.89 | 1.31 |
| rows-wide-lo-100k | 82.48 | 99.80 | 1.21 | 132.13 | 148.23 | 1.12 |
| rows-wide-lo-10m | 8972.45 | 10519.83 | 1.17 | 15744.54 | 17128.71 | 1.09 |
| rows-wide-lo-1m | 824.22 | 983.69 | 1.19 | 1348.55 | 1492.63 | 1.11 |
| scan-mixed-hi-100k | 7.24 | 14.29 | 1.97 | 8.42 | 15.92 | 1.89 |
| scan-mixed-hi-10m | 806.76 | 1545.66 | 1.92 | 802.93 | 1548.79 | 1.93 |
| scan-mixed-hi-1m | 78.22 | 155.10 | 1.98 | 79.50 | 153.13 | 1.93 |
| scan-mixed-lo-100k | 7.53 | 14.73 | 1.96 | 8.82 | 16.34 | 1.85 |
| scan-mixed-lo-10m | 847.74 | 1579.89 | 1.86 | 839.40 | 1577.78 | 1.88 |
| scan-mixed-lo-1m | 83.01 | 157.50 | 1.90 | 83.87 | 157.19 | 1.87 |
| scan-mixnq-hi-100k | 6.68 | 13.88 | 2.08 | 7.89 | 15.35 | 1.95 |
| scan-mixnq-hi-10m | 767.96 | 1506.27 | 1.96 | 768.72 | 1502.30 | 1.95 |
| scan-mixnq-hi-1m | 75.70 | 150.42 | 1.99 | 77.15 | 149.04 | 1.93 |
| scan-mixnq-lo-100k | 7.15 | 14.74 | 2.06 | 8.33 | 15.74 | 1.89 |
| scan-mixnq-lo-10m | 818.20 | 1538.88 | 1.88 | 815.35 | 1540.18 | 1.89 |
| scan-mixnq-lo-1m | 81.47 | 155.37 | 1.91 | 81.57 | 154.41 | 1.89 |
| scan-numeric-hi-100k | 4.72 | 7.32 | 1.55 | 5.26 | 7.90 | 1.50 |
| scan-numeric-hi-10m | 507.24 | 776.11 | 1.53 | 503.90 | 762.85 | 1.51 |
| scan-numeric-hi-1m | 51.42 | 77.10 | 1.50 | 51.22 | 76.95 | 1.50 |
| scan-numeric-lo-100k | 5.20 | 7.75 | 1.49 | 5.69 | 8.25 | 1.45 |
| scan-numeric-lo-10m | 543.11 | 802.81 | 1.48 | 547.83 | 805.08 | 1.47 |
| scan-numeric-lo-1m | 55.36 | 80.43 | 1.45 | 55.05 | 80.07 | 1.45 |
| scan-wide-hi-100k | 8.51 | 29.30 | 3.44 | 10.87 | 31.12 | 2.86 |
| scan-wide-hi-10m | 1027.22 | 3031.07 | 2.95 | 1029.39 | 3026.30 | 2.94 |
| scan-wide-hi-1m | 100.37 | 303.11 | 3.02 | 100.68 | 299.76 | 2.98 |
| scan-wide-lo-100k | 8.82 | 28.92 | 3.28 | 11.09 | 31.38 | 2.83 |
| scan-wide-lo-10m | 1058.56 | 3068.22 | 2.90 | 1057.29 | 3071.62 | 2.91 |
| scan-wide-lo-1m | 102.62 | 306.09 | 2.98 | 104.50 | 304.38 | 2.91 |

### Every cell and implementation

Median and p95 of the clean samples in ms, peak RSS of the fresh process in MiB (for DuckDB the CLI child), wall/CPU median, excluded samples. `jit` rows are the PHP loops with the tracing JIT; native and SQLite ran with opcache off.

| workload | impl | mode | warm median ms | warm p95 ms | fresh median ms | fresh p95 ms | RSS MiB | wall/CPU median | excluded |
| --- | --- | --- | ---: | ---: | ---: | ---: | ---: | ---: | ---: |
| gi-numeric-hi-c10-100k | cols | jit | 73.54 | 77.80 | 80.45 | 84.08 | 41 | 1.000 | 0 |
| gi-numeric-hi-c10-100k | cols_dict | jit | 76.68 | 78.54 | 82.20 | 84.80 | 41 | 1.000 | 0 |
| gi-numeric-hi-c10-100k | duckdb | na |  |  | 57.59 | 69.26 | 48 | 0.888 | 0 |
| gi-numeric-hi-c10-100k | duckdb_1t | na |  |  | 56.44 | 57.58 | 47 | 1.003 | 0 |
| gi-numeric-hi-c10-100k | duckdb_forced | na |  |  | 65.37 | 67.18 | 49 | 0.897 | 0 |
| gi-numeric-hi-c10-100k | duckdb_forced_1t | na |  |  | 65.22 | 67.95 | 48 | 1.003 | 0 |
| gi-numeric-hi-c10-100k | native | off | 5.38 | 5.52 | 5.98 | 6.12 | 23 | 1.000 | 0 |
| gi-numeric-hi-c10-100k | native_scalar | off | 7.83 | 11.36 | 8.51 | 8.83 | 23 | 1.000 | 0 |
| gi-numeric-hi-c10-100k | pdo_duckdb | off | 37.34 | 38.28 | 52.16 | 52.57 | 82 | 0.952 | 0 |
| gi-numeric-hi-c10-100k | pdo_duckdb_1t | off | 36.16 | 40.42 | 52.08 | 53.91 | 79 | 1.001 | 0 |
| gi-numeric-hi-c10-100k | rows | jit | 84.70 | 88.49 | 102.27 | 105.69 | 65 | 1.000 | 0 |
| gi-numeric-hi-c10-100k | rows_foreach | jit | 77.93 | 79.38 | 99.48 | 104.90 | 64 | 1.000 | 0 |
| gi-numeric-hi-c10-100k | sqlite | off | 166.70 | 170.35 | 166.17 | 169.06 | 26 | 1.000 | 0 |
| gi-numeric-hi-c10-100k | sqlite_idx | off | 256.15 | 259.54 | 259.43 | 268.09 | 30 | 1.000 | 0 |
| gi-numeric-hi-c10-100k | stream | jit | 218.88 | 228.49 | 217.86 | 236.49 | 27 | 1.000 | 0 |
| gi-numeric-hi-c10-100k | stream_fast | jit | 37.08 | 38.34 | 38.51 | 39.31 | 27 | 1.000 | 0 |
| gi-numeric-hi-c10-10m | duckdb | na |  |  | 573.91 | 579.74 | 237 | 0.325 | 0 |
| gi-numeric-hi-c10-10m | duckdb_1t | na |  |  | 1717.25 | 1723.27 | 198 | 1.000 | 0 |
| gi-numeric-hi-c10-10m | native | off | 575.84 | 590.21 | 568.49 | 571.08 | 23 | 1.000 | 0 |
| gi-numeric-hi-c10-10m | native_scalar | off | 833.13 | 839.59 | 828.33 | 833.41 | 23 | 1.000 | 0 |
| gi-numeric-hi-c10-10m | stream | jit | 22616.72 | 22683.34 | 22486.22 | 22649.51 | 27 | 1.000 | 0 |
| gi-numeric-hi-c10-10m | stream_fast | jit | 3749.32 | 3766.73 | 3715.79 | 3742.01 | 27 | 1.000 | 0 |
| gi-numeric-hi-c10-1m | cols | jit | 758.47 | 768.29 | 772.42 | 797.64 | 151 | 1.000 | 0 |
| gi-numeric-hi-c10-1m | cols_dict | jit | 772.94 | 787.87 | 778.16 | 784.92 | 151 | 1.000 | 0 |
| gi-numeric-hi-c10-1m | duckdb | na |  |  | 114.98 | 125.47 | 89 | 0.477 | 0 |
| gi-numeric-hi-c10-1m | duckdb_1t | na |  |  | 219.78 | 224.78 | 88 | 1.001 | 0 |
| gi-numeric-hi-c10-1m | duckdb_forced | na |  |  | 141.25 | 144.63 | 90 | 0.443 | 0 |
| gi-numeric-hi-c10-1m | duckdb_forced_1t | na |  |  | 293.97 | 298.52 | 88 | 1.001 | 0 |
| gi-numeric-hi-c10-1m | native | off | 57.14 | 58.82 | 57.50 | 57.98 | 23 | 1.000 | 0 |
| gi-numeric-hi-c10-1m | native_scalar | off | 82.53 | 84.87 | 82.60 | 84.45 | 23 | 1.000 | 0 |
| gi-numeric-hi-c10-1m | pdo_duckdb | off | 98.22 | 101.57 | 112.68 | 115.56 | 123 | 0.417 | 0 |
| gi-numeric-hi-c10-1m | pdo_duckdb_1t | off | 213.48 | 221.41 | 224.06 | 227.40 | 120 | 1.000 | 0 |
| gi-numeric-hi-c10-1m | rows | jit | 945.04 | 991.93 | 1024.62 | 1045.63 | 404 | 1.000 | 0 |
| gi-numeric-hi-c10-1m | rows_foreach | jit | 847.46 | 863.76 | 995.10 | 1027.12 | 402 | 1.000 | 0 |
| gi-numeric-hi-c10-1m | sqlite | off | 1658.87 | 1694.45 | 1646.37 | 1668.95 | 43 | 1.000 | 0 |
| gi-numeric-hi-c10-1m | sqlite_idx | off | 2761.11 | 2787.56 | 2746.55 | 2808.04 | 73 | 1.000 | 0 |
| gi-numeric-hi-c10-1m | stream | jit | 2244.60 | 2273.26 | 2217.73 | 2271.42 | 27 | 1.000 | 0 |
| gi-numeric-hi-c10-1m | stream_fast | jit | 367.05 | 393.38 | 369.72 | 375.09 | 27 | 1.000 | 0 |
| gi-numeric-hi-c100k-100k | cols | jit | 77.30 | 107.38 | 80.99 | 92.88 | 41 | 1.000 | 0 |
| gi-numeric-hi-c100k-100k | cols_dict | jit | 80.43 | 86.39 | 90.08 | 92.55 | 44 | 1.000 | 0 |
| gi-numeric-hi-c100k-100k | duckdb | na |  |  | 58.59 | 59.83 | 48 | 0.890 | 0 |
| gi-numeric-hi-c100k-100k | duckdb_1t | na |  |  | 57.78 | 98.85 | 47 | 1.004 | 0 |
| gi-numeric-hi-c100k-100k | duckdb_forced | na |  |  | 66.47 | 68.02 | 49 | 0.898 | 0 |
| gi-numeric-hi-c100k-100k | duckdb_forced_1t | na |  |  | 65.26 | 67.01 | 48 | 1.003 | 0 |
| gi-numeric-hi-c100k-100k | native | off | 5.61 | 5.77 | 6.32 | 6.72 | 23 | 1.000 | 0 |
| gi-numeric-hi-c100k-100k | native_scalar | off | 8.11 | 8.25 | 8.90 | 9.12 | 23 | 1.000 | 0 |
| gi-numeric-hi-c100k-100k | pdo_duckdb | off | 37.96 | 39.61 | 53.13 | 54.15 | 82 | 0.892 | 0 |
| gi-numeric-hi-c100k-100k | pdo_duckdb_1t | off | 35.67 | 37.61 | 52.67 | 53.82 | 80 | 1.001 | 0 |
| gi-numeric-hi-c100k-100k | rows | jit | 83.47 | 92.42 | 103.45 | 105.32 | 65 | 1.000 | 0 |
| gi-numeric-hi-c100k-100k | rows_foreach | jit | 80.38 | 85.24 | 100.76 | 102.57 | 65 | 1.000 | 0 |
| gi-numeric-hi-c100k-100k | sqlite | off | 166.07 | 172.48 | 168.12 | 181.87 | 26 | 1.000 | 0 |
| gi-numeric-hi-c100k-100k | sqlite_idx | off | 282.75 | 287.05 | 276.85 | 285.49 | 31 | 1.000 | 0 |
| gi-numeric-hi-c100k-100k | stream | jit | 220.33 | 225.23 | 220.95 | 335.56 | 27 | 1.000 | 0 |
| gi-numeric-hi-c100k-100k | stream_fast | jit | 38.91 | 40.01 | 40.75 | 41.51 | 27 | 1.000 | 0 |
| gi-numeric-hi-c100k-10m | duckdb | na |  |  | 635.29 | 636.24 | 278 | 0.338 | 0 |
| gi-numeric-hi-c100k-10m | duckdb_1t | na |  |  | 1838.34 | 1858.55 | 204 | 1.000 | 0 |
| gi-numeric-hi-c100k-10m | native | off | 598.73 | 602.10 | 608.97 | 612.17 | 40 | 1.000 | 0 |
| gi-numeric-hi-c100k-10m | native_scalar | off | 869.01 | 877.12 | 867.93 | 890.43 | 40 | 1.000 | 0 |
| gi-numeric-hi-c100k-10m | stream | jit | 22979.63 | 23111.29 | 22833.54 | 22961.93 | 45 | 1.000 | 0 |
| gi-numeric-hi-c100k-10m | stream_fast | jit | 3953.48 | 3978.34 | 3960.77 | 4046.63 | 45 | 1.000 | 0 |
| gi-numeric-hi-c100k-1m | cols | jit | 755.42 | 769.09 | 768.98 | 786.24 | 152 | 1.000 | 0 |
| gi-numeric-hi-c100k-1m | cols_dict | jit | 826.28 | 840.57 | 836.94 | 853.28 | 160 | 1.000 | 0 |
| gi-numeric-hi-c100k-1m | duckdb | na |  |  | 121.75 | 123.37 | 91 | 0.450 | 0 |
| gi-numeric-hi-c100k-1m | duckdb_1t | na |  |  | 233.52 | 236.14 | 88 | 1.001 | 0 |
| gi-numeric-hi-c100k-1m | duckdb_forced | na |  |  | 149.95 | 159.94 | 93 | 0.440 | 0 |
| gi-numeric-hi-c100k-1m | duckdb_forced_1t | na |  |  | 303.49 | 307.71 | 89 | 1.001 | 0 |
| gi-numeric-hi-c100k-1m | native | off | 59.40 | 61.70 | 61.59 | 63.14 | 25 | 1.000 | 0 |
| gi-numeric-hi-c100k-1m | native_scalar | off | 88.23 | 90.33 | 87.51 | 161.62 | 25 | 1.000 | 0 |
| gi-numeric-hi-c100k-1m | pdo_duckdb | off | 104.18 | 105.03 | 116.97 | 117.93 | 126 | 0.408 | 0 |
| gi-numeric-hi-c100k-1m | pdo_duckdb_1t | off | 219.55 | 225.50 | 231.96 | 233.47 | 123 | 1.000 | 0 |
| gi-numeric-hi-c100k-1m | rows | jit | 967.79 | 1010.45 | 1028.78 | 1039.43 | 406 | 1.000 | 0 |
| gi-numeric-hi-c100k-1m | rows_foreach | jit | 854.34 | 875.62 | 992.32 | 1024.13 | 405 | 1.000 | 0 |
| gi-numeric-hi-c100k-1m | sqlite | off | 1710.47 | 1746.83 | 1712.70 | 1775.25 | 48 | 1.000 | 0 |
| gi-numeric-hi-c100k-1m | sqlite_idx | off | 2967.13 | 3089.55 | 2953.99 | 3004.36 | 79 | 1.000 | 0 |
| gi-numeric-hi-c100k-1m | stream | jit | 2233.77 | 2257.84 | 2241.32 | 2287.55 | 30 | 1.000 | 0 |
| gi-numeric-hi-c100k-1m | stream_fast | jit | 392.02 | 398.66 | 395.24 | 398.86 | 30 | 1.000 | 0 |
| gi-numeric-hi-c1k-100k | cols | jit | 74.38 | 75.86 | 80.75 | 83.69 | 41 | 1.000 | 0 |
| gi-numeric-hi-c1k-100k | cols_dict | jit | 76.30 | 77.42 | 84.20 | 88.21 | 42 | 1.000 | 0 |
| gi-numeric-hi-c1k-100k | duckdb | na |  |  | 58.14 | 62.14 | 48 | 0.889 | 0 |
| gi-numeric-hi-c1k-100k | duckdb_1t | na |  |  | 56.81 | 61.23 | 47 | 1.004 | 0 |
| gi-numeric-hi-c1k-100k | duckdb_forced | na |  |  | 65.77 | 66.52 | 49 | 0.897 | 0 |
| gi-numeric-hi-c1k-100k | duckdb_forced_1t | na |  |  | 64.50 | 66.18 | 48 | 1.004 | 0 |
| gi-numeric-hi-c1k-100k | native | off | 5.46 | 5.48 | 6.16 | 6.33 | 23 | 1.000 | 1 |
| gi-numeric-hi-c1k-100k | native_scalar | off | 8.11 | 8.29 | 8.68 | 8.90 | 23 | 1.000 | 0 |
| gi-numeric-hi-c1k-100k | pdo_duckdb | off | 37.50 | 38.77 | 52.50 | 53.46 | 82 | 0.955 | 0 |
| gi-numeric-hi-c1k-100k | pdo_duckdb_1t | off | 36.14 | 38.55 | 51.94 | 53.95 | 80 | 1.001 | 0 |
| gi-numeric-hi-c1k-100k | rows | jit | 81.71 | 85.95 | 102.54 | 104.37 | 64 | 1.000 | 0 |
| gi-numeric-hi-c1k-100k | rows_foreach | jit | 76.68 | 79.02 | 99.62 | 104.72 | 64 | 1.000 | 0 |
| gi-numeric-hi-c1k-100k | sqlite | off | 162.67 | 169.02 | 166.18 | 170.32 | 26 | 1.000 | 0 |
| gi-numeric-hi-c1k-100k | sqlite_idx | off | 261.93 | 267.05 | 267.96 | 283.77 | 31 | 1.000 | 0 |
| gi-numeric-hi-c1k-100k | stream | jit | 217.11 | 220.00 | 219.74 | 227.89 | 27 | 1.000 | 0 |
| gi-numeric-hi-c1k-100k | stream_fast | jit | 38.22 | 39.12 | 40.58 | 41.32 | 27 | 1.000 | 0 |
| gi-numeric-hi-c1k-10m | duckdb | na |  |  | 575.40 | 582.49 | 201 | 0.322 | 0 |
| gi-numeric-hi-c1k-10m | duckdb_1t | na |  |  | 1765.75 | 1768.11 | 198 | 1.000 | 0 |
| gi-numeric-hi-c1k-10m | native | off | 580.51 | 584.45 | 582.60 | 583.34 | 23 | 1.000 | 0 |
| gi-numeric-hi-c1k-10m | native_scalar | off | 842.06 | 845.62 | 840.08 | 846.16 | 23 | 1.000 | 0 |
| gi-numeric-hi-c1k-10m | stream | jit | 22905.81 | 23047.78 | 22856.78 | 23399.69 | 27 | 1.000 | 0 |
| gi-numeric-hi-c1k-10m | stream_fast | jit | 3907.05 | 3946.26 | 3910.08 | 3933.16 | 27 | 1.000 | 0 |
| gi-numeric-hi-c1k-1m | cols | jit | 756.07 | 773.90 | 770.61 | 781.68 | 151 | 1.000 | 0 |
| gi-numeric-hi-c1k-1m | cols_dict | jit | 778.63 | 804.64 | 795.28 | 809.21 | 151 | 1.000 | 0 |
| gi-numeric-hi-c1k-1m | duckdb | na |  |  | 116.83 | 126.78 | 90 | 0.447 | 0 |
| gi-numeric-hi-c1k-1m | duckdb_1t | na |  |  | 225.99 | 228.22 | 88 | 1.001 | 0 |
| gi-numeric-hi-c1k-1m | duckdb_forced | na |  |  | 142.40 | 146.55 | 91 | 0.441 | 0 |
| gi-numeric-hi-c1k-1m | duckdb_forced_1t | na |  |  | 296.36 | 305.22 | 88 | 1.001 | 0 |
| gi-numeric-hi-c1k-1m | native | off | 57.59 | 61.54 | 59.04 | 59.91 | 23 | 1.000 | 0 |
| gi-numeric-hi-c1k-1m | native_scalar | off | 82.59 | 84.70 | 84.14 | 88.11 | 23 | 1.000 | 0 |
| gi-numeric-hi-c1k-1m | pdo_duckdb | off | 99.79 | 103.67 | 113.76 | 116.47 | 124 | 0.406 | 0 |
| gi-numeric-hi-c1k-1m | pdo_duckdb_1t | off | 217.15 | 220.47 | 230.17 | 237.02 | 120 | 1.000 | 0 |
| gi-numeric-hi-c1k-1m | rows | jit | 961.92 | 996.15 | 1027.12 | 1060.22 | 404 | 1.000 | 0 |
| gi-numeric-hi-c1k-1m | rows_foreach | jit | 860.04 | 873.02 | 990.08 | 1006.77 | 402 | 1.000 | 0 |
| gi-numeric-hi-c1k-1m | sqlite | off | 1664.06 | 1684.22 | 1686.41 | 1708.63 | 45 | 1.000 | 0 |
| gi-numeric-hi-c1k-1m | sqlite_idx | off | 2810.72 | 2831.87 | 2882.63 | 2919.96 | 75 | 1.000 | 0 |
| gi-numeric-hi-c1k-1m | stream | jit | 2235.32 | 2291.52 | 2227.69 | 2314.34 | 27 | 1.000 | 0 |
| gi-numeric-hi-c1k-1m | stream_fast | jit | 384.69 | 390.41 | 389.48 | 399.83 | 27 | 1.000 | 0 |
| gi-numeric-lo-c10-100k | cols | jit | 77.50 | 81.48 | 83.48 | 86.69 | 41 | 1.000 | 0 |
| gi-numeric-lo-c10-100k | cols_dict | jit | 77.10 | 79.47 | 82.42 | 85.91 | 41 | 1.000 | 0 |
| gi-numeric-lo-c10-100k | duckdb | na |  |  | 58.64 | 60.38 | 49 | 0.889 | 0 |
| gi-numeric-lo-c10-100k | duckdb_1t | na |  |  | 58.96 | 59.93 | 49 | 1.004 | 0 |
| gi-numeric-lo-c10-100k | duckdb_forced | na |  |  | 66.64 | 68.01 | 49 | 0.897 | 0 |
| gi-numeric-lo-c10-100k | duckdb_forced_1t | na |  |  | 66.24 | 67.94 | 49 | 1.003 | 0 |
| gi-numeric-lo-c10-100k | native | off | 6.86 | 6.97 | 7.45 | 7.57 | 23 | 1.000 | 0 |
| gi-numeric-lo-c10-100k | native_scalar | off | 9.23 | 13.00 | 10.00 | 18.09 | 23 | 1.000 | 0 |
| gi-numeric-lo-c10-100k | pdo_duckdb | off | 38.38 | 39.88 | 53.27 | 54.46 | 82 | 0.953 | 0 |
| gi-numeric-lo-c10-100k | pdo_duckdb_1t | off | 39.08 | 40.70 | 54.63 | 56.18 | 82 | 1.001 | 0 |
| gi-numeric-lo-c10-100k | rows | jit | 121.43 | 149.66 | 127.19 | 130.41 | 69 | 1.000 | 0 |
| gi-numeric-lo-c10-100k | rows_foreach | jit | 80.31 | 82.36 | 104.45 | 106.19 | 64 | 1.000 | 0 |
| gi-numeric-lo-c10-100k | sqlite | off | 199.91 | 208.09 | 203.95 | 212.16 | 28 | 1.000 | 0 |
| gi-numeric-lo-c10-100k | sqlite_idx | off | 328.41 | 332.64 | 330.86 | 338.65 | 30 | 1.000 | 0 |
| gi-numeric-lo-c10-100k | stream | jit | 217.88 | 223.50 | 223.04 | 226.21 | 27 | 1.000 | 0 |
| gi-numeric-lo-c10-100k | stream_fast | jit | 38.24 | 40.26 | 43.34 | 44.16 | 27 | 1.000 | 0 |
| gi-numeric-lo-c10-10m | duckdb | na |  |  | 588.96 | 608.95 | 206 | 0.313 | 0 |
| gi-numeric-lo-c10-10m | duckdb_1t | na |  |  | 1820.49 | 1866.61 | 198 | 1.000 | 0 |
| gi-numeric-lo-c10-10m | native | off | 720.40 | 737.61 | 717.10 | 723.49 | 23 | 1.000 | 0 |
| gi-numeric-lo-c10-10m | native_scalar | off | 982.92 | 986.73 | 972.71 | 978.84 | 23 | 1.000 | 0 |
| gi-numeric-lo-c10-10m | stream | jit | 23190.18 | 23355.24 | 23096.85 | 23250.69 | 27 | 1.000 | 0 |
| gi-numeric-lo-c10-10m | stream_fast | jit | 3919.87 | 3933.68 | 3939.06 | 3980.26 | 28 | 1.000 | 0 |
| gi-numeric-lo-c10-1m | cols | jit | 780.70 | 789.90 | 782.40 | 808.25 | 151 | 1.000 | 0 |
| gi-numeric-lo-c10-1m | cols_dict | jit | 780.58 | 816.87 | 790.26 | 797.63 | 151 | 1.000 | 0 |
| gi-numeric-lo-c10-1m | duckdb | na |  |  | 119.20 | 131.98 | 89 | 0.443 | 0 |
| gi-numeric-lo-c10-1m | duckdb_1t | na |  |  | 230.94 | 239.00 | 88 | 1.001 | 0 |
| gi-numeric-lo-c10-1m | duckdb_forced | na |  |  | 146.24 | 156.97 | 90 | 0.423 | 0 |
| gi-numeric-lo-c10-1m | duckdb_forced_1t | na |  |  | 309.45 | 315.91 | 88 | 1.001 | 0 |
| gi-numeric-lo-c10-1m | native | off | 70.89 | 73.74 | 73.02 | 73.86 | 23 | 1.000 | 0 |
| gi-numeric-lo-c10-1m | native_scalar | off | 96.61 | 101.30 | 98.26 | 99.40 | 23 | 1.000 | 0 |
| gi-numeric-lo-c10-1m | pdo_duckdb | off | 102.40 | 104.87 | 116.09 | 117.91 | 123 | 0.401 | 0 |
| gi-numeric-lo-c10-1m | pdo_duckdb_1t | off | 224.46 | 232.37 | 236.79 | 242.22 | 120 | 1.000 | 0 |
| gi-numeric-lo-c10-1m | rows | jit | 1993.70 | 2360.21 | 1522.82 | 1545.81 | 435 | 1.000 | 0 |
| gi-numeric-lo-c10-1m | rows_foreach | jit | 871.18 | 903.87 | 1017.59 | 1097.24 | 402 | 1.000 | 0 |
| gi-numeric-lo-c10-1m | sqlite | off | 2033.07 | 2041.94 | 2104.21 | 2153.04 | 45 | 1.000 | 0 |
| gi-numeric-lo-c10-1m | sqlite_idx | off | 3652.66 | 3684.46 | 3659.15 | 3741.79 | 73 | 1.000 | 0 |
| gi-numeric-lo-c10-1m | stream | jit | 2365.49 | 2388.70 | 2258.79 | 2380.43 | 27 | 1.000 | 0 |
| gi-numeric-lo-c10-1m | stream_fast | jit | 387.47 | 392.33 | 398.53 | 413.61 | 27 | 1.000 | 0 |
| gi-numeric-lo-c100k-100k | cols | jit | 86.84 | 90.13 | 95.47 | 116.30 | 56 | 1.000 | 0 |
| gi-numeric-lo-c100k-100k | cols_dict | jit | 84.94 | 99.07 | 100.29 | 102.54 | 58 | 1.000 | 0 |
| gi-numeric-lo-c100k-100k | duckdb | na |  |  | 86.99 | 89.39 | 59 | 0.887 | 0 |
| gi-numeric-lo-c100k-100k | duckdb_1t | na |  |  | 84.45 | 87.08 | 54 | 1.003 | 0 |
| gi-numeric-lo-c100k-100k | duckdb_forced | na |  |  | 120.33 | 123.64 | 68 | 0.897 | 0 |
| gi-numeric-lo-c100k-100k | duckdb_forced_1t | na |  |  | 120.20 | 122.61 | 63 | 1.002 | 0 |
| gi-numeric-lo-c100k-100k | native | off | 11.59 | 11.88 | 19.54 | 19.85 | 39 | 1.000 | 1 |
| gi-numeric-lo-c100k-100k | native_scalar | off | 14.22 | 14.73 | 22.23 | 22.36 | 39 | 1.000 | 0 |
| gi-numeric-lo-c100k-100k | pdo_duckdb | off | 56.67 | 58.27 | 76.39 | 78.92 | 100 | 0.969 | 0 |
| gi-numeric-lo-c100k-100k | pdo_duckdb_1t | off | 54.28 | 55.60 | 73.50 | 74.86 | 97 | 1.001 | 0 |
| gi-numeric-lo-c100k-100k | rows | jit | 141.07 | 160.96 | 140.09 | 149.97 | 84 | 1.000 | 0 |
| gi-numeric-lo-c100k-100k | rows_foreach | jit | 91.71 | 99.95 | 119.98 | 122.94 | 82 | 1.000 | 0 |
| gi-numeric-lo-c100k-100k | sqlite | off | 265.26 | 270.32 | 271.85 | 276.15 | 43 | 1.000 | 0 |
| gi-numeric-lo-c100k-100k | sqlite_idx | off | 402.25 | 408.54 | 405.66 | 421.55 | 46 | 1.000 | 0 |
| gi-numeric-lo-c100k-100k | stream | jit | 239.43 | 241.69 | 245.79 | 282.70 | 44 | 1.000 | 0 |
| gi-numeric-lo-c100k-100k | stream_fast | jit | 48.72 | 50.86 | 60.36 | 62.61 | 44 | 1.000 | 0 |
| gi-numeric-lo-c100k-10m | duckdb | na |  |  | 752.34 | 770.01 | 396 | 0.330 | 0 |
| gi-numeric-lo-c100k-10m | duckdb_1t | na |  |  | 2067.99 | 2079.67 | 208 | 1.000 | 0 |
| gi-numeric-lo-c100k-10m | native | off | 855.77 | 862.80 | 869.97 | 875.03 | 50 | 1.000 | 0 |
| gi-numeric-lo-c100k-10m | native_scalar | off | 1120.16 | 1128.09 | 1118.51 | 1139.25 | 50 | 1.000 | 0 |
| gi-numeric-lo-c100k-10m | stream | jit | 24163.52 | 24258.09 | 23914.76 | 24099.35 | 57 | 1.000 | 0 |
| gi-numeric-lo-c100k-10m | stream_fast | jit | 4525.36 | 4657.41 | 4524.45 | 4663.19 | 58 | 1.000 | 0 |
| gi-numeric-lo-c100k-1m | cols | jit | 818.90 | 831.94 | 838.77 | 867.16 | 179 | 1.000 | 0 |
| gi-numeric-lo-c100k-1m | cols_dict | jit | 871.92 | 904.87 | 887.08 | 899.09 | 181 | 1.000 | 0 |
| gi-numeric-lo-c100k-1m | duckdb | na |  |  | 183.57 | 187.31 | 135 | 0.480 | 0 |
| gi-numeric-lo-c100k-1m | duckdb_1t | na |  |  | 299.39 | 324.47 | 97 | 1.001 | 0 |
| gi-numeric-lo-c100k-1m | duckdb_forced | na |  |  | 261.90 | 278.62 | 167 | 0.491 | 0 |
| gi-numeric-lo-c100k-1m | duckdb_forced_1t | na |  |  | 424.55 | 452.37 | 110 | 1.001 | 0 |
| gi-numeric-lo-c100k-1m | native | off | 98.86 | 100.02 | 108.48 | 110.09 | 50 | 1.000 | 0 |
| gi-numeric-lo-c100k-1m | native_scalar | off | 124.52 | 125.56 | 132.89 | 134.30 | 50 | 1.000 | 0 |
| gi-numeric-lo-c100k-1m | pdo_duckdb | off | 153.92 | 158.65 | 173.36 | 175.71 | 184 | 0.450 | 0 |
| gi-numeric-lo-c100k-1m | pdo_duckdb_1t | off | 262.53 | 275.98 | 281.89 | 286.17 | 147 | 1.000 | 0 |
| gi-numeric-lo-c100k-1m | rows | jit | 2074.67 | 2433.24 | 1594.79 | 1616.40 | 456 | 1.000 | 0 |
| gi-numeric-lo-c100k-1m | rows_foreach | jit | 942.87 | 971.39 | 1096.07 | 1116.30 | 433 | 1.000 | 0 |
| gi-numeric-lo-c100k-1m | sqlite | off | 2381.54 | 2402.36 | 2367.82 | 2428.53 | 71 | 1.000 | 0 |
| gi-numeric-lo-c100k-1m | sqlite_idx | off | 4012.55 | 4083.16 | 4109.79 | 4185.72 | 102 | 1.000 | 0 |
| gi-numeric-lo-c100k-1m | stream | jit | 2382.38 | 2404.16 | 2384.83 | 2526.37 | 57 | 1.000 | 0 |
| gi-numeric-lo-c100k-1m | stream_fast | jit | 477.38 | 503.95 | 481.76 | 505.40 | 57 | 1.000 | 0 |
| gi-numeric-lo-c1k-100k | cols | jit | 79.15 | 81.36 | 84.63 | 85.94 | 41 | 1.000 | 0 |
| gi-numeric-lo-c1k-100k | cols_dict | jit | 79.39 | 81.87 | 87.12 | 89.76 | 42 | 1.000 | 0 |
| gi-numeric-lo-c1k-100k | duckdb | na |  |  | 59.65 | 61.62 | 49 | 0.891 | 0 |
| gi-numeric-lo-c1k-100k | duckdb_1t | na |  |  | 60.06 | 61.16 | 49 | 1.004 | 0 |
| gi-numeric-lo-c1k-100k | duckdb_forced | na |  |  | 67.71 | 67.89 | 49 | 0.897 | 0 |
| gi-numeric-lo-c1k-100k | duckdb_forced_1t | na |  |  | 69.13 | 69.53 | 50 | 1.003 | 0 |
| gi-numeric-lo-c1k-100k | native | off | 7.85 | 7.96 | 8.30 | 8.43 | 23 | 1.000 | 0 |
| gi-numeric-lo-c1k-100k | native_scalar | off | 10.36 | 10.57 | 10.93 | 10.96 | 23 | 1.002 | 0 |
| gi-numeric-lo-c1k-100k | pdo_duckdb | off | 38.77 | 41.23 | 53.95 | 55.48 | 82 | 0.954 | 0 |
| gi-numeric-lo-c1k-100k | pdo_duckdb_1t | off | 39.02 | 42.84 | 55.05 | 56.14 | 82 | 1.001 | 0 |
| gi-numeric-lo-c1k-100k | rows | jit | 128.65 | 151.22 | 127.78 | 131.32 | 69 | 1.000 | 0 |
| gi-numeric-lo-c1k-100k | rows_foreach | jit | 81.21 | 92.28 | 105.05 | 110.36 | 64 | 1.000 | 0 |
| gi-numeric-lo-c1k-100k | sqlite | off | 211.42 | 237.55 | 215.44 | 220.68 | 28 | 1.000 | 0 |
| gi-numeric-lo-c1k-100k | sqlite_idx | off | 347.41 | 352.25 | 349.13 | 354.99 | 31 | 1.001 | 0 |
| gi-numeric-lo-c1k-100k | stream | jit | 221.46 | 227.10 | 228.07 | 232.62 | 27 | 1.000 | 0 |
| gi-numeric-lo-c1k-100k | stream_fast | jit | 41.16 | 43.95 | 46.69 | 47.17 | 27 | 1.000 | 0 |
| gi-numeric-lo-c1k-10m | cols | jit | 8278.90 | 8310.48 | 8075.40 | 8145.25 | 1250 | 1.000 | 0 |
| gi-numeric-lo-c1k-10m | cols_dict | jit | 8254.70 | 8279.34 | 8206.03 | 8393.93 | 1250 | 1.000 | 0 |
| gi-numeric-lo-c1k-10m | duckdb | na |  |  | 594.94 | 595.38 | 206 | 0.318 | 0 |
| gi-numeric-lo-c1k-10m | duckdb_1t | na |  |  | 1858.66 | 1869.70 | 198 | 1.000 | 0 |
| gi-numeric-lo-c1k-10m | duckdb_forced | na |  |  | 792.47 | 820.74 | 239 | 0.300 | 0 |
| gi-numeric-lo-c1k-10m | duckdb_forced_1t | na |  |  | 2623.17 | 2627.69 | 199 | 1.000 | 0 |
| gi-numeric-lo-c1k-10m | native | off | 791.96 | 798.87 | 780.46 | 791.24 | 23 | 1.000 | 0 |
| gi-numeric-lo-c1k-10m | native_scalar | off | 1052.94 | 1064.46 | 1055.47 | 1057.11 | 23 | 1.000 | 0 |
| gi-numeric-lo-c1k-10m | pdo_duckdb | off | 614.74 | 644.99 | 631.45 | 639.58 | 271 | 0.312 | 0 |
| gi-numeric-lo-c1k-10m | pdo_duckdb_1t | off | 1933.36 | 1944.41 | 1965.67 | 1976.31 | 231 | 1.000 | 0 |
| gi-numeric-lo-c1k-10m | rows_foreach | jit | 8917.50 | 8983.53 | 10317.29 | 10360.15 | 3777 | 1.000 | 0 |
| gi-numeric-lo-c1k-10m | sqlite | off | 23239.67 | 23406.03 | 23160.71 | 23390.44 | 237 | 1.000 | 0 |
| gi-numeric-lo-c1k-10m | sqlite_idx | off | 45824.71 | 46621.62 | 45156.21 | 45812.94 | 535 | 1.000 | 0 |
| gi-numeric-lo-c1k-10m | stream | jit | 22904.64 | 22929.17 | 22932.33 | 23022.38 | 27 | 1.000 | 0 |
| gi-numeric-lo-c1k-10m | stream_fast | jit | 4085.92 | 4111.89 | 4125.99 | 4139.62 | 27 | 1.000 | 0 |
| gi-numeric-lo-c1k-1m | cols | jit | 777.52 | 796.08 | 789.24 | 800.29 | 151 | 1.000 | 0 |
| gi-numeric-lo-c1k-1m | cols_dict | jit | 786.26 | 807.29 | 807.92 | 818.41 | 151 | 1.000 | 0 |
| gi-numeric-lo-c1k-1m | duckdb | na |  |  | 120.30 | 124.93 | 90 | 0.440 | 0 |
| gi-numeric-lo-c1k-1m | duckdb_1t | na |  |  | 235.56 | 240.71 | 88 | 1.001 | 0 |
| gi-numeric-lo-c1k-1m | duckdb_forced | na |  |  | 148.20 | 152.25 | 91 | 0.443 | 0 |
| gi-numeric-lo-c1k-1m | duckdb_forced_1t | na |  |  | 312.48 | 317.91 | 88 | 1.001 | 0 |
| gi-numeric-lo-c1k-1m | native | off | 79.82 | 81.50 | 80.74 | 81.53 | 23 | 1.000 | 0 |
| gi-numeric-lo-c1k-1m | native_scalar | off | 106.86 | 108.62 | 105.15 | 107.72 | 23 | 1.000 | 0 |
| gi-numeric-lo-c1k-1m | pdo_duckdb | off | 103.53 | 106.98 | 117.53 | 118.88 | 123 | 0.403 | 0 |
| gi-numeric-lo-c1k-1m | pdo_duckdb_1t | off | 225.09 | 230.82 | 239.60 | 243.28 | 120 | 1.000 | 0 |
| gi-numeric-lo-c1k-1m | rows | jit | 2036.89 | 2399.49 | 1523.78 | 1589.81 | 436 | 1.000 | 0 |
| gi-numeric-lo-c1k-1m | rows_foreach | jit | 877.61 | 900.43 | 1014.40 | 1039.44 | 402 | 1.000 | 0 |
| gi-numeric-lo-c1k-1m | sqlite | off | 2257.55 | 2275.28 | 2282.37 | 2369.86 | 46 | 1.000 | 0 |
| gi-numeric-lo-c1k-1m | sqlite_idx | off | 3878.44 | 3932.97 | 3887.92 | 3937.69 | 75 | 1.000 | 0 |
| gi-numeric-lo-c1k-1m | stream | jit | 2330.99 | 2343.62 | 2276.21 | 2368.10 | 27 | 1.000 | 0 |
| gi-numeric-lo-c1k-1m | stream_fast | jit | 410.64 | 438.73 | 419.42 | 427.14 | 27 | 1.000 | 0 |
| gs-mixed-hi-c10-100k | cols | jit | 194.85 | 205.58 | 185.25 | 187.00 | 71 | 1.000 | 0 |
| gs-mixed-hi-c10-100k | cols_dict | jit | 180.04 | 196.04 | 191.21 | 194.26 | 66 | 1.000 | 0 |
| gs-mixed-hi-c10-100k | duckdb | na |  |  | 60.25 | 61.24 | 58 | 0.789 | 0 |
| gs-mixed-hi-c10-100k | duckdb_1t | na |  |  | 66.78 | 68.65 | 55 | 1.003 | 0 |
| gs-mixed-hi-c10-100k | duckdb_forced | na |  |  | 70.26 | 71.25 | 58 | 0.757 | 0 |
| gs-mixed-hi-c10-100k | duckdb_forced_1t | na |  |  | 84.50 | 85.52 | 56 | 1.003 | 0 |
| gs-mixed-hi-c10-100k | native | off | 8.49 | 8.53 | 9.88 | 10.08 | 23 | 1.000 | 0 |
| gs-mixed-hi-c10-100k | native_scalar | off | 15.72 | 15.92 | 17.45 | 18.56 | 23 | 1.000 | 0 |
| gs-mixed-hi-c10-100k | pdo_duckdb | off | 40.36 | 41.80 | 54.75 | 56.19 | 90 | 0.791 | 0 |
| gs-mixed-hi-c10-100k | pdo_duckdb_1t | off | 47.23 | 50.70 | 62.74 | 65.54 | 88 | 1.001 | 0 |
| gs-mixed-hi-c10-100k | rows | jit | 189.66 | 200.66 | 236.35 | 238.18 | 126 | 1.000 | 0 |
| gs-mixed-hi-c10-100k | rows_foreach | jit | 177.13 | 193.47 | 231.65 | 234.14 | 125 | 1.000 | 0 |
| gs-mixed-hi-c10-100k | sqlite | off | 232.17 | 235.79 | 240.15 | 250.06 | 27 | 1.000 | 0 |
| gs-mixed-hi-c10-100k | sqlite_idx | off | 351.31 | 355.68 | 349.93 | 357.16 | 33 | 1.000 | 0 |
| gs-mixed-hi-c10-100k | stream | jit | 576.26 | 583.20 | 584.93 | 617.84 | 27 | 1.000 | 0 |
| gs-mixed-hi-c10-100k | stream_hybrid | jit | 114.74 | 121.16 | 121.10 | 125.35 | 27 | 1.000 | 0 |
| gs-mixed-hi-c10-10m | duckdb | na |  |  | 922.38 | 931.18 | 353 | 0.360 | 0 |
| gs-mixed-hi-c10-10m | duckdb_1t | na |  |  | 2464.41 | 2475.03 | 198 | 1.000 | 0 |
| gs-mixed-hi-c10-10m | native | off | 955.51 | 960.66 | 953.36 | 963.34 | 23 | 1.000 | 0 |
| gs-mixed-hi-c10-10m | native_scalar | off | 1678.54 | 1682.25 | 1696.76 | 1698.90 | 23 | 1.000 | 0 |
| gs-mixed-hi-c10-10m | stream | jit | 60532.10 | 60886.11 | 59005.51 | 60254.32 | 27 | 1.000 | 0 |
| gs-mixed-hi-c10-10m | stream_hybrid | jit | 12037.90 | 12061.74 | 11668.95 | 12066.05 | 27 | 1.000 | 0 |
| gs-mixed-hi-c10-1m | cols | jit | 2121.04 | 2143.35 | 1785.28 | 1801.74 | 467 | 1.000 | 0 |
| gs-mixed-hi-c10-1m | cols_dict | jit | 2021.61 | 2072.65 | 1781.39 | 1792.20 | 422 | 1.000 | 0 |
| gs-mixed-hi-c10-1m | duckdb | na |  |  | 158.31 | 166.08 | 139 | 0.462 | 0 |
| gs-mixed-hi-c10-1m | duckdb_1t | na |  |  | 321.64 | 324.94 | 169 | 1.001 | 0 |
| gs-mixed-hi-c10-1m | duckdb_forced | na |  |  | 190.92 | 214.99 | 142 | 0.383 | 0 |
| gs-mixed-hi-c10-1m | duckdb_forced_1t | na |  |  | 481.36 | 488.76 | 170 | 1.001 | 0 |
| gs-mixed-hi-c10-1m | native | off | 93.47 | 94.81 | 93.89 | 95.76 | 23 | 1.000 | 0 |
| gs-mixed-hi-c10-1m | native_scalar | off | 168.55 | 171.75 | 169.27 | 171.28 | 23 | 1.000 | 0 |
| gs-mixed-hi-c10-1m | pdo_duckdb | off | 145.83 | 149.70 | 151.26 | 163.90 | 173 | 0.418 | 0 |
| gs-mixed-hi-c10-1m | pdo_duckdb_1t | off | 319.59 | 329.33 | 332.56 | 340.78 | 201 | 1.000 | 0 |
| gs-mixed-hi-c10-1m | rows | jit | 2113.02 | 2267.39 | 2243.51 | 2260.81 | 1012 | 1.000 | 0 |
| gs-mixed-hi-c10-1m | rows_foreach | jit | 1864.13 | 1956.12 | 2209.83 | 2241.89 | 1010 | 1.000 | 0 |
| gs-mixed-hi-c10-1m | sqlite | off | 2379.12 | 2407.66 | 2395.93 | 2485.15 | 57 | 1.000 | 0 |
| gs-mixed-hi-c10-1m | sqlite_idx | off | 3744.09 | 3758.78 | 3770.86 | 3794.01 | 101 | 1.000 | 0 |
| gs-mixed-hi-c10-1m | stream | jit | 5829.51 | 5855.56 | 5821.40 | 5863.25 | 27 | 1.000 | 0 |
| gs-mixed-hi-c10-1m | stream_hybrid | jit | 1158.21 | 1164.65 | 1176.36 | 1205.12 | 27 | 1.000 | 0 |
| gs-mixed-hi-c100k-100k | cols | jit | 179.25 | 184.31 | 186.91 | 194.45 | 71 | 1.000 | 0 |
| gs-mixed-hi-c100k-100k | cols_dict | jit | 210.63 | 221.66 | 210.40 | 218.55 | 74 | 1.000 | 0 |
| gs-mixed-hi-c100k-100k | duckdb | na |  |  | 60.27 | 61.50 | 58 | 0.791 | 0 |
| gs-mixed-hi-c100k-100k | duckdb_1t | na |  |  | 66.73 | 68.46 | 55 | 1.003 | 0 |
| gs-mixed-hi-c100k-100k | duckdb_forced | na |  |  | 71.79 | 72.99 | 59 | 0.758 | 0 |
| gs-mixed-hi-c100k-100k | duckdb_forced_1t | na |  |  | 85.41 | 87.57 | 56 | 1.003 | 0 |
| gs-mixed-hi-c100k-100k | native | off | 7.89 | 7.91 | 9.41 | 9.50 | 23 | 1.000 | 0 |
| gs-mixed-hi-c100k-100k | native_scalar | off | 15.13 | 15.26 | 16.89 | 17.00 | 23 | 1.000 | 0 |
| gs-mixed-hi-c100k-100k | pdo_duckdb | off | 39.94 | 46.99 | 54.99 | 56.86 | 90 | 0.760 | 0 |
| gs-mixed-hi-c100k-100k | pdo_duckdb_1t | off | 47.07 | 50.23 | 62.72 | 71.09 | 88 | 1.001 | 0 |
| gs-mixed-hi-c100k-100k | rows | jit | 192.20 | 205.13 | 234.61 | 238.01 | 125 | 1.000 | 0 |
| gs-mixed-hi-c100k-100k | rows_foreach | jit | 175.17 | 198.33 | 231.45 | 236.77 | 126 | 1.000 | 0 |
| gs-mixed-hi-c100k-100k | sqlite | off | 236.38 | 245.33 | 241.02 | 247.83 | 29 | 1.000 | 0 |
| gs-mixed-hi-c100k-100k | sqlite_idx | off | 354.26 | 363.16 | 365.27 | 374.50 | 38 | 1.000 | 0 |
| gs-mixed-hi-c100k-100k | stream | jit | 572.57 | 579.26 | 578.49 | 647.04 | 27 | 1.000 | 0 |
| gs-mixed-hi-c100k-100k | stream_hybrid | jit | 114.14 | 115.28 | 120.11 | 122.20 | 27 | 1.000 | 0 |
| gs-mixed-hi-c100k-10m | duckdb | na |  |  | 901.34 | 903.45 | 312 | 0.366 | 0 |
| gs-mixed-hi-c100k-10m | duckdb_1t | na |  |  | 2379.77 | 2400.29 | 207 | 1.000 | 0 |
| gs-mixed-hi-c100k-10m | native | off | 905.34 | 912.01 | 914.53 | 915.63 | 46 | 1.000 | 0 |
| gs-mixed-hi-c100k-10m | native_scalar | off | 1634.32 | 1651.28 | 1665.44 | 1674.17 | 46 | 1.000 | 0 |
| gs-mixed-hi-c100k-10m | stream | jit | 58868.76 | 59358.57 | 58893.41 | 59152.60 | 49 | 1.000 | 0 |
| gs-mixed-hi-c100k-10m | stream_hybrid | jit | 11756.13 | 11807.27 | 11859.22 | 11912.71 | 49 | 1.000 | 0 |
| gs-mixed-hi-c100k-1m | cols | jit | 2139.55 | 2176.52 | 1794.79 | 1812.58 | 469 | 1.000 | 0 |
| gs-mixed-hi-c100k-1m | cols_dict | jit | 2326.74 | 2350.98 | 1998.26 | 2034.10 | 423 | 1.000 | 0 |
| gs-mixed-hi-c100k-1m | duckdb | na |  |  | 158.55 | 167.89 | 143 | 0.484 | 0 |
| gs-mixed-hi-c100k-1m | duckdb_1t | na |  |  | 319.50 | 328.46 | 170 | 1.001 | 0 |
| gs-mixed-hi-c100k-1m | duckdb_forced | na |  |  | 201.84 | 226.59 | 146 | 0.394 | 0 |
| gs-mixed-hi-c100k-1m | duckdb_forced_1t | na |  |  | 495.74 | 500.40 | 172 | 1.001 | 0 |
| gs-mixed-hi-c100k-1m | native | off | 88.05 | 90.52 | 90.65 | 91.72 | 26 | 1.000 | 0 |
| gs-mixed-hi-c100k-1m | native_scalar | off | 162.88 | 165.24 | 165.61 | 175.63 | 26 | 1.000 | 0 |
| gs-mixed-hi-c100k-1m | pdo_duckdb | off | 139.55 | 148.75 | 152.54 | 166.53 | 178 | 0.429 | 0 |
| gs-mixed-hi-c100k-1m | pdo_duckdb_1t | off | 316.74 | 324.17 | 330.01 | 337.09 | 205 | 1.000 | 0 |
| gs-mixed-hi-c100k-1m | rows | jit | 2135.99 | 2318.76 | 2264.34 | 2292.62 | 1014 | 1.000 | 0 |
| gs-mixed-hi-c100k-1m | rows_foreach | jit | 1894.75 | 1999.13 | 2214.77 | 2250.46 | 1013 | 1.000 | 0 |
| gs-mixed-hi-c100k-1m | sqlite | off | 2447.08 | 2460.49 | 2440.24 | 2476.71 | 83 | 1.000 | 0 |
| gs-mixed-hi-c100k-1m | sqlite_idx | off | 3931.27 | 3955.23 | 3984.00 | 4057.64 | 149 | 1.000 | 0 |
| gs-mixed-hi-c100k-1m | stream | jit | 5847.46 | 5880.14 | 5836.20 | 6004.46 | 30 | 1.000 | 0 |
| gs-mixed-hi-c100k-1m | stream_hybrid | jit | 1165.31 | 1173.69 | 1166.89 | 1217.00 | 30 | 1.000 | 0 |
| gs-mixed-hi-c1k-100k | cols | jit | 180.40 | 189.40 | 187.40 | 191.70 | 71 | 1.000 | 0 |
| gs-mixed-hi-c1k-100k | cols_dict | jit | 181.96 | 191.86 | 192.88 | 201.04 | 66 | 1.000 | 0 |
| gs-mixed-hi-c1k-100k | duckdb | na |  |  | 59.67 | 61.77 | 58 | 0.790 | 0 |
| gs-mixed-hi-c1k-100k | duckdb_1t | na |  |  | 66.15 | 68.64 | 55 | 1.004 | 0 |
| gs-mixed-hi-c1k-100k | duckdb_forced | na |  |  | 71.21 | 72.14 | 59 | 0.759 | 0 |
| gs-mixed-hi-c1k-100k | duckdb_forced_1t | na |  |  | 84.98 | 86.81 | 56 | 1.003 | 0 |
| gs-mixed-hi-c1k-100k | native | off | 7.64 | 7.83 | 9.03 | 9.11 | 23 | 1.000 | 0 |
| gs-mixed-hi-c1k-100k | native_scalar | off | 14.82 | 15.10 | 16.57 | 16.93 | 23 | 1.000 | 0 |
| gs-mixed-hi-c1k-100k | pdo_duckdb | off | 39.40 | 42.01 | 54.45 | 55.56 | 90 | 0.785 | 0 |
| gs-mixed-hi-c1k-100k | pdo_duckdb_1t | off | 46.01 | 50.21 | 62.48 | 63.75 | 88 | 1.001 | 0 |
| gs-mixed-hi-c1k-100k | rows | jit | 189.78 | 199.37 | 233.94 | 237.81 | 126 | 1.000 | 0 |
| gs-mixed-hi-c1k-100k | rows_foreach | jit | 176.33 | 189.14 | 229.38 | 233.56 | 126 | 1.000 | 0 |
| gs-mixed-hi-c1k-100k | sqlite | off | 235.03 | 239.31 | 239.98 | 268.79 | 28 | 1.000 | 0 |
| gs-mixed-hi-c1k-100k | sqlite_idx | off | 344.24 | 349.06 | 353.35 | 356.47 | 34 | 1.000 | 0 |
| gs-mixed-hi-c1k-100k | stream | jit | 574.03 | 578.68 | 576.57 | 604.50 | 27 | 1.000 | 0 |
| gs-mixed-hi-c1k-100k | stream_hybrid | jit | 115.65 | 117.96 | 119.41 | 123.25 | 27 | 1.000 | 0 |
| gs-mixed-hi-c1k-10m | duckdb | na |  |  | 872.68 | 878.63 | 324 | 0.365 | 0 |
| gs-mixed-hi-c1k-10m | duckdb_1t | na |  |  | 2309.06 | 2317.60 | 199 | 1.000 | 0 |
| gs-mixed-hi-c1k-10m | native | off | 855.19 | 859.13 | 860.79 | 869.94 | 23 | 1.000 | 0 |
| gs-mixed-hi-c1k-10m | native_scalar | off | 1594.01 | 1597.64 | 1595.18 | 1607.91 | 23 | 1.000 | 0 |
| gs-mixed-hi-c1k-10m | stream | jit | 58559.45 | 59213.95 | 58874.39 | 59105.42 | 27 | 1.000 | 0 |
| gs-mixed-hi-c1k-10m | stream_hybrid | jit | 11707.44 | 11782.43 | 11693.27 | 11761.46 | 27 | 1.000 | 0 |
| gs-mixed-hi-c1k-1m | cols | jit | 2133.82 | 2174.47 | 1791.75 | 1806.65 | 467 | 1.000 | 0 |
| gs-mixed-hi-c1k-1m | cols_dict | jit | 2144.52 | 2179.47 | 1809.20 | 1831.54 | 415 | 1.000 | 0 |
| gs-mixed-hi-c1k-1m | duckdb | na |  |  | 158.57 | 165.90 | 155 | 0.481 | 0 |
| gs-mixed-hi-c1k-1m | duckdb_1t | na |  |  | 310.90 | 314.10 | 169 | 1.001 | 0 |
| gs-mixed-hi-c1k-1m | duckdb_forced | na |  |  | 193.98 | 205.50 | 143 | 0.383 | 0 |
| gs-mixed-hi-c1k-1m | duckdb_forced_1t | na |  |  | 484.48 | 489.41 | 170 | 1.001 | 0 |
| gs-mixed-hi-c1k-1m | native | off | 85.18 | 88.93 | 85.58 | 87.42 | 23 | 1.001 | 0 |
| gs-mixed-hi-c1k-1m | native_scalar | off | 158.60 | 163.26 | 160.41 | 163.88 | 23 | 1.000 | 0 |
| gs-mixed-hi-c1k-1m | pdo_duckdb | off | 134.09 | 145.46 | 152.24 | 159.91 | 189 | 0.429 | 0 |
| gs-mixed-hi-c1k-1m | pdo_duckdb_1t | off | 309.89 | 313.55 | 323.21 | 328.45 | 202 | 1.000 | 0 |
| gs-mixed-hi-c1k-1m | rows | jit | 2119.80 | 2292.05 | 2256.66 | 2296.82 | 1012 | 1.000 | 0 |
| gs-mixed-hi-c1k-1m | rows_foreach | jit | 1857.53 | 2000.16 | 2205.71 | 2221.83 | 1010 | 1.000 | 0 |
| gs-mixed-hi-c1k-1m | sqlite | off | 2420.99 | 2440.65 | 2412.10 | 2506.95 | 64 | 1.000 | 0 |
| gs-mixed-hi-c1k-1m | sqlite_idx | off | 3810.97 | 3836.39 | 3843.26 | 3979.70 | 115 | 1.000 | 0 |
| gs-mixed-hi-c1k-1m | stream | jit | 5791.51 | 5823.71 | 5808.57 | 6122.01 | 27 | 1.000 | 0 |
| gs-mixed-hi-c1k-1m | stream_hybrid | jit | 1150.79 | 1173.47 | 1164.35 | 1185.77 | 27 | 1.000 | 0 |
| gs-mixed-lo-c10-100k | cols | jit | 190.04 | 197.94 | 190.47 | 195.23 | 71 | 1.000 | 0 |
| gs-mixed-lo-c10-100k | cols_dict | jit | 186.77 | 197.59 | 187.68 | 192.66 | 66 | 1.000 | 0 |
| gs-mixed-lo-c10-100k | duckdb | na |  |  | 62.38 | 63.86 | 58 | 0.783 | 0 |
| gs-mixed-lo-c10-100k | duckdb_1t | na |  |  | 70.92 | 73.94 | 57 | 1.003 | 0 |
| gs-mixed-lo-c10-100k | duckdb_forced | na |  |  | 72.87 | 76.26 | 58 | 0.758 | 0 |
| gs-mixed-lo-c10-100k | duckdb_forced_1t | na |  |  | 87.89 | 96.81 | 58 | 1.003 | 0 |
| gs-mixed-lo-c10-100k | native | off | 10.40 | 10.82 | 11.93 | 12.20 | 23 | 1.000 | 0 |
| gs-mixed-lo-c10-100k | native_scalar | off | 17.71 | 17.95 | 19.27 | 19.62 | 23 | 1.000 | 0 |
| gs-mixed-lo-c10-100k | pdo_duckdb | off | 41.76 | 42.79 | 56.62 | 57.66 | 91 | 0.731 | 0 |
| gs-mixed-lo-c10-100k | pdo_duckdb_1t | off | 52.24 | 54.55 | 66.81 | 68.07 | 90 | 1.001 | 0 |
| gs-mixed-lo-c10-100k | rows | jit | 293.61 | 324.55 | 305.99 | 311.29 | 130 | 1.000 | 0 |
| gs-mixed-lo-c10-100k | rows_foreach | jit | 180.56 | 192.93 | 236.06 | 238.67 | 125 | 1.000 | 0 |
| gs-mixed-lo-c10-100k | sqlite | off | 288.98 | 294.58 | 290.71 | 296.15 | 29 | 1.000 | 0 |
| gs-mixed-lo-c10-100k | sqlite_idx | off | 430.89 | 436.79 | 433.73 | 436.32 | 33 | 1.000 | 0 |
| gs-mixed-lo-c10-100k | stream | jit | 579.86 | 582.01 | 586.44 | 613.72 | 27 | 1.000 | 0 |
| gs-mixed-lo-c10-100k | stream_hybrid | jit | 119.25 | 122.68 | 130.11 | 133.03 | 27 | 1.000 | 0 |
| gs-mixed-lo-c10-10m | duckdb | na |  |  | 940.28 | 958.99 | 261 | 0.344 | 0 |
| gs-mixed-lo-c10-10m | duckdb_1t | na |  |  | 2672.70 | 2696.86 | 198 | 1.000 | 0 |
| gs-mixed-lo-c10-10m | native | off | 1158.81 | 1170.61 | 1149.51 | 1153.86 | 23 | 1.001 | 0 |
| gs-mixed-lo-c10-10m | native_scalar | off | 1880.85 | 1886.48 | 1886.81 | 1896.00 | 23 | 1.000 | 0 |
| gs-mixed-lo-c10-10m | stream | jit | 59448.62 | 59749.39 | 59356.28 | 59380.95 | 27 | 1.000 | 0 |
| gs-mixed-lo-c10-10m | stream_hybrid | jit | 12238.31 | 12254.14 | 12199.97 | 12535.57 | 28 | 1.000 | 0 |
| gs-mixed-lo-c10-1m | cols | jit | 2256.51 | 2289.89 | 1832.76 | 1841.98 | 467 | 1.000 | 0 |
| gs-mixed-lo-c10-1m | cols_dict | jit | 2086.19 | 2146.59 | 1788.86 | 1831.95 | 422 | 1.000 | 0 |
| gs-mixed-lo-c10-1m | duckdb | na |  |  | 165.51 | 177.38 | 169 | 0.453 | 0 |
| gs-mixed-lo-c10-1m | duckdb_1t | na |  |  | 345.59 | 349.97 | 169 | 1.001 | 0 |
| gs-mixed-lo-c10-1m | duckdb_forced | na |  |  | 206.91 | 211.18 | 157 | 0.378 | 0 |
| gs-mixed-lo-c10-1m | duckdb_forced_1t | na |  |  | 507.57 | 513.25 | 169 | 1.001 | 0 |
| gs-mixed-lo-c10-1m | native | off | 114.06 | 119.13 | 113.38 | 116.11 | 23 | 1.000 | 0 |
| gs-mixed-lo-c10-1m | native_scalar | off | 187.99 | 190.84 | 186.66 | 189.80 | 23 | 1.000 | 0 |
| gs-mixed-lo-c10-1m | pdo_duckdb | off | 151.38 | 155.19 | 165.30 | 168.37 | 203 | 0.422 | 0 |
| gs-mixed-lo-c10-1m | pdo_duckdb_1t | off | 346.74 | 352.89 | 361.17 | 367.02 | 201 | 1.000 | 0 |
| gs-mixed-lo-c10-1m | rows | jit | 4370.26 | 5338.95 | 3359.14 | 3398.89 | 1044 | 1.000 | 0 |
| gs-mixed-lo-c10-1m | rows_foreach | jit | 1906.93 | 2059.03 | 2251.22 | 2278.10 | 1010 | 1.000 | 0 |
| gs-mixed-lo-c10-1m | sqlite | off | 3077.44 | 3091.08 | 3015.83 | 3083.93 | 59 | 1.000 | 0 |
| gs-mixed-lo-c10-1m | sqlite_idx | off | 4781.65 | 4892.46 | 4915.32 | 4941.04 | 101 | 1.000 | 0 |
| gs-mixed-lo-c10-1m | stream | jit | 5887.27 | 5898.64 | 5877.56 | 5924.65 | 27 | 1.000 | 0 |
| gs-mixed-lo-c10-1m | stream_hybrid | jit | 1222.93 | 1229.59 | 1235.02 | 1259.65 | 27 | 1.000 | 0 |
| gs-mixed-lo-c100k-100k | cols | jit | 222.67 | 228.60 | 209.49 | 217.93 | 88 | 1.000 | 0 |
| gs-mixed-lo-c100k-100k | cols_dict | jit | 232.29 | 245.57 | 228.66 | 244.08 | 88 | 1.000 | 0 |
| gs-mixed-lo-c100k-100k | duckdb | na |  |  | 93.19 | 96.50 | 73 | 0.783 | 0 |
| gs-mixed-lo-c100k-100k | duckdb_1t | na |  |  | 101.57 | 105.30 | 64 | 1.002 | 0 |
| gs-mixed-lo-c100k-100k | duckdb_forced | na |  |  | 136.41 | 152.80 | 86 | 0.784 | 0 |
| gs-mixed-lo-c100k-100k | duckdb_forced_1t | na |  |  | 152.28 | 156.66 | 71 | 1.002 | 0 |
| gs-mixed-lo-c100k-100k | native | off | 17.58 | 18.67 | 27.44 | 28.06 | 44 | 1.000 | 0 |
| gs-mixed-lo-c100k-100k | native_scalar | off | 24.77 | 25.36 | 35.04 | 36.29 | 44 | 1.000 | 0 |
| gs-mixed-lo-c100k-100k | pdo_duckdb | off | 64.31 | 67.36 | 85.11 | 89.50 | 120 | 0.791 | 0 |
| gs-mixed-lo-c100k-100k | pdo_duckdb_1t | off | 72.66 | 75.92 | 91.09 | 93.18 | 112 | 1.000 | 0 |
| gs-mixed-lo-c100k-100k | rows | jit | 328.19 | 357.24 | 330.70 | 334.66 | 145 | 1.000 | 0 |
| gs-mixed-lo-c100k-100k | rows_foreach | jit | 217.16 | 234.20 | 263.31 | 265.62 | 143 | 1.000 | 0 |
| gs-mixed-lo-c100k-100k | sqlite | off | 359.52 | 362.96 | 367.12 | 373.09 | 50 | 1.000 | 0 |
| gs-mixed-lo-c100k-100k | sqlite_idx | off | 517.46 | 531.63 | 526.03 | 546.24 | 56 | 1.000 | 0 |
| gs-mixed-lo-c100k-100k | stream | jit | 606.38 | 614.56 | 619.50 | 644.19 | 47 | 1.000 | 0 |
| gs-mixed-lo-c100k-100k | stream_hybrid | jit | 135.89 | 139.52 | 154.55 | 156.97 | 48 | 1.000 | 0 |
| gs-mixed-lo-c100k-10m | duckdb | na |  |  | 1161.14 | 1212.11 | 587 | 0.352 | 0 |
| gs-mixed-lo-c100k-10m | duckdb_1t | na |  |  | 2982.08 | 2993.96 | 211 | 1.000 | 0 |
| gs-mixed-lo-c100k-10m | native | off | 1385.74 | 1397.38 | 1390.63 | 1405.36 | 60 | 1.000 | 0 |
| gs-mixed-lo-c100k-10m | native_scalar | off | 2167.69 | 2220.14 | 2205.20 | 2259.72 | 60 | 1.000 | 0 |
| gs-mixed-lo-c100k-10m | stream | jit | 61359.55 | 61846.20 | 61947.04 | 62002.93 | 63 | 1.000 | 0 |
| gs-mixed-lo-c100k-10m | stream_hybrid | jit | 13691.79 | 13733.96 | 13519.56 | 14062.28 | 64 | 1.000 | 0 |
| gs-mixed-lo-c100k-1m | cols | jit | 2348.13 | 2419.24 | 1957.15 | 1989.24 | 497 | 1.000 | 0 |
| gs-mixed-lo-c100k-1m | cols_dict | jit | 2454.63 | 2524.43 | 2081.66 | 2119.15 | 445 | 1.000 | 0 |
| gs-mixed-lo-c100k-1m | duckdb | na |  |  | 241.45 | 259.50 | 251 | 0.471 | 0 |
| gs-mixed-lo-c100k-1m | duckdb_1t | na |  |  | 435.85 | 439.78 | 182 | 1.001 | 0 |
| gs-mixed-lo-c100k-1m | duckdb_forced | na |  |  | 346.13 | 376.55 | 288 | 0.437 | 0 |
| gs-mixed-lo-c100k-1m | duckdb_forced_1t | na |  |  | 671.90 | 683.19 | 193 | 1.000 | 0 |
| gs-mixed-lo-c100k-1m | native | off | 152.25 | 159.91 | 168.23 | 171.50 | 60 | 1.000 | 0 |
| gs-mixed-lo-c100k-1m | native_scalar | off | 228.68 | 237.03 | 242.57 | 247.86 | 60 | 1.000 | 0 |
| gs-mixed-lo-c100k-1m | pdo_duckdb | off | 215.40 | 228.47 | 230.94 | 245.48 | 280 | 0.462 | 0 |
| gs-mixed-lo-c100k-1m | pdo_duckdb_1t | off | 404.48 | 413.94 | 423.85 | 441.65 | 240 | 1.000 | 0 |
| gs-mixed-lo-c100k-1m | rows | jit | 4605.35 | 5581.02 | 3523.58 | 3588.11 | 1064 | 1.000 | 0 |
| gs-mixed-lo-c100k-1m | rows_foreach | jit | 2289.98 | 2458.25 | 2501.21 | 2530.78 | 1041 | 1.000 | 0 |
| gs-mixed-lo-c100k-1m | sqlite | off | 3255.15 | 3263.57 | 3281.23 | 3467.02 | 111 | 1.000 | 0 |
| gs-mixed-lo-c100k-1m | sqlite_idx | off | 5436.42 | 5484.28 | 5463.20 | 5612.51 | 176 | 1.000 | 0 |
| gs-mixed-lo-c100k-1m | stream | jit | 6100.25 | 6123.48 | 6112.00 | 6172.25 | 63 | 1.000 | 0 |
| gs-mixed-lo-c100k-1m | stream_hybrid | jit | 1361.68 | 1383.52 | 1400.46 | 1425.80 | 63 | 1.000 | 0 |
| gs-mixed-lo-c1k-100k | cols | jit | 191.78 | 214.38 | 192.61 | 195.27 | 71 | 1.000 | 0 |
| gs-mixed-lo-c1k-100k | cols_dict | jit | 198.67 | 211.92 | 196.02 | 200.13 | 66 | 1.000 | 0 |
| gs-mixed-lo-c1k-100k | duckdb | na |  |  | 61.64 | 63.25 | 58 | 0.785 | 0 |
| gs-mixed-lo-c1k-100k | duckdb_1t | na |  |  | 70.15 | 71.21 | 58 | 1.003 | 0 |
| gs-mixed-lo-c1k-100k | duckdb_forced | na |  |  | 73.96 | 74.69 | 59 | 0.756 | 0 |
| gs-mixed-lo-c1k-100k | duckdb_forced_1t | na |  |  | 88.72 | 91.29 | 58 | 1.003 | 0 |
| gs-mixed-lo-c1k-100k | native | off | 10.42 | 10.68 | 11.75 | 11.82 | 23 | 1.000 | 0 |
| gs-mixed-lo-c1k-100k | native_scalar | off | 17.41 | 17.47 | 19.17 | 19.31 | 23 | 1.000 | 0 |
| gs-mixed-lo-c1k-100k | pdo_duckdb | off | 41.23 | 42.86 | 56.34 | 57.17 | 91 | 0.778 | 0 |
| gs-mixed-lo-c1k-100k | pdo_duckdb_1t | off | 50.22 | 53.54 | 66.15 | 67.24 | 90 | 1.001 | 0 |
| gs-mixed-lo-c1k-100k | rows | jit | 306.32 | 329.99 | 305.66 | 312.41 | 130 | 1.000 | 0 |
| gs-mixed-lo-c1k-100k | rows_foreach | jit | 181.99 | 195.84 | 238.65 | 242.78 | 125 | 1.000 | 0 |
| gs-mixed-lo-c1k-100k | sqlite | off | 293.04 | 293.84 | 298.32 | 312.37 | 30 | 1.000 | 0 |
| gs-mixed-lo-c1k-100k | sqlite_idx | off | 438.64 | 447.41 | 448.43 | 463.95 | 35 | 1.000 | 0 |
| gs-mixed-lo-c1k-100k | stream | jit | 583.79 | 588.18 | 588.37 | 640.68 | 27 | 1.000 | 0 |
| gs-mixed-lo-c1k-100k | stream_hybrid | jit | 120.57 | 122.69 | 133.43 | 135.39 | 27 | 1.000 | 0 |
| gs-mixed-lo-c1k-10m | cols | jit | 22205.32 | 23454.76 | 18742.07 | 18866.89 | 4423 | 1.000 | 0 |
| gs-mixed-lo-c1k-10m | cols_dict | jit | 21814.59 | 23293.51 | 18437.86 | 18743.48 | 3902 | 1.000 | 0 |
| gs-mixed-lo-c1k-10m | duckdb | na |  |  | 902.13 | 917.57 | 262 | 0.350 | 0 |
| gs-mixed-lo-c1k-10m | duckdb_1t | na |  |  | 2526.21 | 2530.07 | 199 | 1.000 | 0 |
| gs-mixed-lo-c1k-10m | duckdb_forced | na |  |  | 1383.39 | 1383.70 | 388 | 0.314 | 0 |
| gs-mixed-lo-c1k-10m | duckdb_forced_1t | na |  |  | 4298.85 | 4326.52 | 200 | 1.000 | 0 |
| gs-mixed-lo-c1k-10m | native | off | 1115.97 | 1123.61 | 1125.49 | 1126.07 | 23 | 1.000 | 0 |
| gs-mixed-lo-c1k-10m | native_scalar | off | 1854.22 | 1866.86 | 1868.73 | 1873.40 | 23 | 1.000 | 0 |
| gs-mixed-lo-c1k-10m | pdo_duckdb | off | 928.97 | 954.79 | 948.27 | 961.26 | 357 | 0.341 | 0 |
| gs-mixed-lo-c1k-10m | pdo_duckdb_1t | off | 2668.49 | 2679.92 | 2693.07 | 2706.27 | 231 | 1.000 | 0 |
| gs-mixed-lo-c1k-10m | rows_foreach | jit | 19588.41 | 20309.13 | 23384.17 | 23396.55 | 9857 | 1.000 | 0 |
| gs-mixed-lo-c1k-10m | sqlite | off | 31709.68 | 31855.54 | 31466.92 | 31652.87 | 435 | 1.000 | 0 |
| gs-mixed-lo-c1k-10m | sqlite_idx | off | 56834.57 | 57408.65 | 56550.19 | 56830.09 | 929 | 1.000 | 0 |
| gs-mixed-lo-c1k-10m | stream | jit | 59058.69 | 59119.13 | 59655.98 | 60446.63 | 27 | 1.000 | 0 |
| gs-mixed-lo-c1k-10m | stream_hybrid | jit | 12286.91 | 12313.65 | 12391.91 | 12417.32 | 27 | 1.000 | 0 |
| gs-mixed-lo-c1k-1m | cols | jit | 2265.16 | 2288.04 | 1850.41 | 1870.13 | 467 | 1.000 | 0 |
| gs-mixed-lo-c1k-1m | cols_dict | jit | 2184.02 | 2211.35 | 1817.67 | 1882.18 | 415 | 1.000 | 0 |
| gs-mixed-lo-c1k-1m | duckdb | na |  |  | 155.02 | 164.07 | 140 | 0.457 | 0 |
| gs-mixed-lo-c1k-1m | duckdb_1t | na |  |  | 334.01 | 336.05 | 169 | 1.001 | 0 |
| gs-mixed-lo-c1k-1m | duckdb_forced | na |  |  | 210.89 | 226.13 | 173 | 0.390 | 0 |
| gs-mixed-lo-c1k-1m | duckdb_forced_1t | na |  |  | 512.08 | 520.10 | 169 | 1.001 | 0 |
| gs-mixed-lo-c1k-1m | native | off | 111.67 | 113.85 | 110.93 | 113.50 | 23 | 1.000 | 0 |
| gs-mixed-lo-c1k-1m | native_scalar | off | 185.20 | 188.07 | 184.12 | 188.89 | 23 | 1.000 | 0 |
| gs-mixed-lo-c1k-1m | pdo_duckdb | off | 146.73 | 149.71 | 152.11 | 160.45 | 174 | 0.426 | 0 |
| gs-mixed-lo-c1k-1m | pdo_duckdb_1t | off | 331.49 | 338.75 | 344.48 | 352.87 | 202 | 1.000 | 0 |
| gs-mixed-lo-c1k-1m | rows | jit | 4387.80 | 5360.45 | 3359.29 | 3406.06 | 1044 | 1.000 | 0 |
| gs-mixed-lo-c1k-1m | rows_foreach | jit | 1985.85 | 2074.57 | 2276.31 | 2299.51 | 1010 | 1.000 | 0 |
| gs-mixed-lo-c1k-1m | sqlite | off | 3075.48 | 3084.12 | 3078.94 | 3129.44 | 66 | 1.000 | 0 |
| gs-mixed-lo-c1k-1m | sqlite_idx | off | 4975.58 | 5106.68 | 5080.56 | 5183.00 | 115 | 1.000 | 0 |
| gs-mixed-lo-c1k-1m | stream | jit | 5978.96 | 6002.39 | 5899.69 | 6047.04 | 27 | 1.000 | 0 |
| gs-mixed-lo-c1k-1m | stream_hybrid | jit | 1224.77 | 1242.60 | 1256.68 | 1276.04 | 27 | 1.000 | 0 |
| gs-mixnq-hi-c10-100k | cols | jit | 119.47 | 130.42 | 106.87 | 110.19 | 71 | 1.000 | 0 |
| gs-mixnq-hi-c10-100k | cols_dict | jit | 106.05 | 117.51 | 107.66 | 110.03 | 66 | 1.000 | 0 |
| gs-mixnq-hi-c10-100k | duckdb | na |  |  | 59.95 | 60.46 | 57 | 0.791 | 0 |
| gs-mixnq-hi-c10-100k | duckdb_1t | na |  |  | 66.23 | 67.57 | 55 | 1.004 | 0 |
| gs-mixnq-hi-c10-100k | duckdb_forced | na |  |  | 69.69 | 70.83 | 58 | 0.765 | 0 |
| gs-mixnq-hi-c10-100k | duckdb_forced_1t | na |  |  | 81.63 | 83.97 | 56 | 1.003 | 0 |
| gs-mixnq-hi-c10-100k | native | off | 8.38 | 8.93 | 9.45 | 9.61 | 23 | 1.000 | 0 |
| gs-mixnq-hi-c10-100k | native_scalar | off | 15.35 | 16.15 | 16.84 | 17.31 | 23 | 1.000 | 1 |
| gs-mixnq-hi-c10-100k | pdo_duckdb | off | 39.65 | 40.96 | 54.58 | 55.05 | 90 | 0.791 | 0 |
| gs-mixnq-hi-c10-100k | pdo_duckdb_1t | off | 49.86 | 51.87 | 62.16 | 63.07 | 88 | 1.001 | 0 |
| gs-mixnq-hi-c10-100k | rows | jit | 112.71 | 127.37 | 155.55 | 166.01 | 125 | 1.000 | 0 |
| gs-mixnq-hi-c10-100k | rows_foreach | jit | 103.21 | 114.77 | 151.15 | 155.81 | 125 | 1.000 | 0 |
| gs-mixnq-hi-c10-100k | sqlite | off | 171.77 | 175.61 | 170.15 | 173.24 | 27 | 1.000 | 0 |
| gs-mixnq-hi-c10-100k | sqlite_idx | off | 277.40 | 282.64 | 281.80 | 290.44 | 33 | 1.000 | 0 |
| gs-mixnq-hi-c10-100k | stream | jit | 581.45 | 585.01 | 587.74 | 598.62 | 27 | 1.000 | 0 |
| gs-mixnq-hi-c10-100k | stream_fast | jit | 44.85 | 48.35 | 48.27 | 50.09 | 27 | 1.000 | 0 |
| gs-mixnq-hi-c10-10m | duckdb | na |  |  | 908.74 | 926.65 | 383 | 0.366 | 0 |
| gs-mixnq-hi-c10-10m | duckdb_1t | na |  |  | 2372.81 | 2382.32 | 198 | 1.000 | 0 |
| gs-mixnq-hi-c10-10m | native | off | 915.63 | 918.98 | 914.40 | 917.79 | 23 | 1.000 | 0 |
| gs-mixnq-hi-c10-10m | native_scalar | off | 1642.83 | 1646.12 | 1646.37 | 1659.97 | 23 | 1.000 | 0 |
| gs-mixnq-hi-c10-10m | stream | jit | 59475.17 | 59843.18 | 59624.81 | 60778.92 | 27 | 1.000 | 0 |
| gs-mixnq-hi-c10-10m | stream_fast | jit | 4600.92 | 4622.32 | 4576.05 | 4612.71 | 27 | 1.000 | 0 |
| gs-mixnq-hi-c10-1m | cols | jit | 1331.48 | 1387.46 | 1034.73 | 1049.74 | 470 | 1.000 | 0 |
| gs-mixnq-hi-c10-1m | cols_dict | jit | 1219.02 | 1295.91 | 1029.91 | 1044.84 | 424 | 1.000 | 0 |
| gs-mixnq-hi-c10-1m | duckdb | na |  |  | 151.13 | 165.03 | 139 | 0.461 | 0 |
| gs-mixnq-hi-c10-1m | duckdb_1t | na |  |  | 315.16 | 321.37 | 167 | 1.001 | 0 |
| gs-mixnq-hi-c10-1m | duckdb_forced | na |  |  | 188.70 | 197.66 | 142 | 0.389 | 0 |
| gs-mixnq-hi-c10-1m | duckdb_forced_1t | na |  |  | 462.44 | 467.43 | 168 | 1.001 | 0 |
| gs-mixnq-hi-c10-1m | native | off | 90.96 | 97.00 | 90.51 | 91.45 | 23 | 1.000 | 0 |
| gs-mixnq-hi-c10-1m | native_scalar | off | 162.98 | 165.41 | 163.89 | 168.36 | 23 | 1.000 | 0 |
| gs-mixnq-hi-c10-1m | pdo_duckdb | off | 134.99 | 148.33 | 151.52 | 164.50 | 187 | 0.422 | 0 |
| gs-mixnq-hi-c10-1m | pdo_duckdb_1t | off | 314.01 | 317.45 | 322.97 | 329.28 | 200 | 1.000 | 0 |
| gs-mixnq-hi-c10-1m | rows | jit | 1339.85 | 1500.80 | 1498.82 | 1522.89 | 1015 | 1.000 | 0 |
| gs-mixnq-hi-c10-1m | rows_foreach | jit | 1133.49 | 1222.79 | 1461.12 | 1488.97 | 1014 | 1.000 | 0 |
| gs-mixnq-hi-c10-1m | sqlite | off | 1701.37 | 1726.44 | 1696.50 | 1823.19 | 57 | 1.000 | 0 |
| gs-mixnq-hi-c10-1m | sqlite_idx | off | 3037.94 | 3055.49 | 3046.91 | 3086.71 | 101 | 1.000 | 0 |
| gs-mixnq-hi-c10-1m | stream | jit | 5793.43 | 5844.33 | 5833.96 | 5873.60 | 27 | 1.000 | 0 |
| gs-mixnq-hi-c10-1m | stream_fast | jit | 452.41 | 457.47 | 458.49 | 463.17 | 27 | 1.000 | 0 |
| gs-mixnq-hi-c100k-100k | cols | jit | 111.02 | 127.52 | 107.08 | 111.38 | 71 | 1.000 | 0 |
| gs-mixnq-hi-c100k-100k | cols_dict | jit | 132.52 | 146.68 | 129.14 | 133.28 | 74 | 1.000 | 0 |
| gs-mixnq-hi-c100k-100k | duckdb | na |  |  | 59.68 | 60.86 | 58 | 0.790 | 0 |
| gs-mixnq-hi-c100k-100k | duckdb_1t | na |  |  | 67.50 | 68.73 | 55 | 1.004 | 0 |
| gs-mixnq-hi-c100k-100k | duckdb_forced | na |  |  | 71.24 | 71.70 | 58 | 0.764 | 0 |
| gs-mixnq-hi-c100k-100k | duckdb_forced_1t | na |  |  | 83.30 | 84.60 | 56 | 1.003 | 0 |
| gs-mixnq-hi-c100k-100k | native | off | 7.66 | 7.83 | 8.95 | 9.17 | 23 | 1.000 | 0 |
| gs-mixnq-hi-c100k-100k | native_scalar | off | 14.76 | 15.15 | 16.25 | 16.63 | 23 | 1.000 | 0 |
| gs-mixnq-hi-c100k-100k | pdo_duckdb | off | 39.47 | 42.69 | 54.31 | 55.26 | 91 | 0.799 | 0 |
| gs-mixnq-hi-c100k-100k | pdo_duckdb_1t | off | 46.11 | 49.35 | 61.42 | 63.45 | 88 | 1.001 | 0 |
| gs-mixnq-hi-c100k-100k | rows | jit | 114.18 | 126.19 | 155.04 | 158.28 | 126 | 1.000 | 0 |
| gs-mixnq-hi-c100k-100k | rows_foreach | jit | 104.17 | 116.06 | 151.99 | 154.68 | 126 | 1.000 | 0 |
| gs-mixnq-hi-c100k-100k | sqlite | off | 170.80 | 173.69 | 171.98 | 176.35 | 29 | 1.000 | 0 |
| gs-mixnq-hi-c100k-100k | sqlite_idx | off | 292.12 | 293.53 | 296.14 | 299.34 | 38 | 1.000 | 0 |
| gs-mixnq-hi-c100k-100k | stream | jit | 595.59 | 614.81 | 583.78 | 596.10 | 27 | 1.000 | 0 |
| gs-mixnq-hi-c100k-100k | stream_fast | jit | 44.59 | 46.24 | 48.06 | 50.49 | 27 | 1.000 | 0 |
| gs-mixnq-hi-c100k-10m | duckdb | na |  |  | 916.57 | 929.74 | 405 | 0.376 | 0 |
| gs-mixnq-hi-c100k-10m | duckdb_1t | na |  |  | 2330.34 | 2334.80 | 207 | 1.002 | 0 |
| gs-mixnq-hi-c100k-10m | native | off | 864.25 | 868.85 | 876.21 | 876.39 | 46 | 1.000 | 0 |
| gs-mixnq-hi-c100k-10m | native_scalar | off | 1601.89 | 1616.34 | 1602.22 | 1613.21 | 46 | 1.000 | 0 |
| gs-mixnq-hi-c100k-10m | stream | jit | 58587.48 | 59028.45 | 58974.23 | 59338.66 | 49 | 1.000 | 0 |
| gs-mixnq-hi-c100k-10m | stream_fast | jit | 4578.50 | 4619.36 | 4572.40 | 4576.93 | 49 | 1.000 | 0 |
| gs-mixnq-hi-c100k-1m | cols | jit | 1372.53 | 1402.30 | 1090.48 | 1104.57 | 472 | 1.000 | 0 |
| gs-mixnq-hi-c100k-1m | cols_dict | jit | 1540.08 | 1579.87 | 1206.24 | 1236.17 | 426 | 1.000 | 0 |
| gs-mixnq-hi-c100k-1m | duckdb | na |  |  | 154.31 | 171.10 | 142 | 0.473 | 0 |
| gs-mixnq-hi-c100k-1m | duckdb_1t | na |  |  | 309.49 | 320.27 | 168 | 1.002 | 0 |
| gs-mixnq-hi-c100k-1m | duckdb_forced | na |  |  | 197.72 | 210.88 | 145 | 0.398 | 0 |
| gs-mixnq-hi-c100k-1m | duckdb_forced_1t | na |  |  | 475.78 | 487.63 | 170 | 1.001 | 0 |
| gs-mixnq-hi-c100k-1m | native | off | 85.73 | 86.63 | 87.86 | 90.07 | 26 | 1.000 | 0 |
| gs-mixnq-hi-c100k-1m | native_scalar | off | 159.07 | 161.19 | 159.75 | 162.45 | 26 | 1.000 | 0 |
| gs-mixnq-hi-c100k-1m | pdo_duckdb | off | 134.72 | 145.90 | 150.76 | 163.72 | 178 | 0.432 | 0 |
| gs-mixnq-hi-c100k-1m | pdo_duckdb_1t | off | 308.69 | 311.82 | 318.67 | 326.44 | 204 | 1.000 | 0 |
| gs-mixnq-hi-c100k-1m | rows | jit | 1347.59 | 1506.77 | 1503.21 | 1521.38 | 1017 | 1.000 | 0 |
| gs-mixnq-hi-c100k-1m | rows_foreach | jit | 1165.43 | 1245.13 | 1524.76 | 1571.70 | 1017 | 1.000 | 0 |
| gs-mixnq-hi-c100k-1m | sqlite | off | 1707.73 | 1749.82 | 1727.00 | 1744.08 | 83 | 1.000 | 0 |
| gs-mixnq-hi-c100k-1m | sqlite_idx | off | 3184.01 | 3196.51 | 3247.88 | 3284.76 | 149 | 1.000 | 0 |
| gs-mixnq-hi-c100k-1m | stream | jit | 5946.46 | 6044.54 | 5846.62 | 6050.36 | 30 | 1.000 | 0 |
| gs-mixnq-hi-c100k-1m | stream_fast | jit | 451.96 | 459.49 | 459.05 | 462.96 | 30 | 1.000 | 0 |
| gs-mixnq-hi-c1k-100k | cols | jit | 118.32 | 123.92 | 109.35 | 112.08 | 71 | 1.000 | 0 |
| gs-mixnq-hi-c1k-100k | cols_dict | jit | 110.79 | 122.63 | 112.67 | 115.66 | 66 | 1.000 | 0 |
| gs-mixnq-hi-c1k-100k | duckdb | na |  |  | 59.26 | 61.03 | 57 | 0.791 | 0 |
| gs-mixnq-hi-c1k-100k | duckdb_1t | na |  |  | 66.02 | 67.20 | 55 | 1.003 | 0 |
| gs-mixnq-hi-c1k-100k | duckdb_forced | na |  |  | 70.55 | 72.54 | 58 | 0.765 | 0 |
| gs-mixnq-hi-c1k-100k | duckdb_forced_1t | na |  |  | 81.99 | 86.06 | 56 | 1.003 | 0 |
| gs-mixnq-hi-c1k-100k | native | off | 7.46 | 7.50 | 8.64 | 8.98 | 23 | 1.000 | 0 |
| gs-mixnq-hi-c1k-100k | native_scalar | off | 14.56 | 14.85 | 15.95 | 16.13 | 23 | 1.000 | 0 |
| gs-mixnq-hi-c1k-100k | pdo_duckdb | off | 38.19 | 39.76 | 53.72 | 54.80 | 90 | 0.802 | 0 |
| gs-mixnq-hi-c1k-100k | pdo_duckdb_1t | off | 47.36 | 49.95 | 61.19 | 63.23 | 87 | 1.001 | 0 |
| gs-mixnq-hi-c1k-100k | rows | jit | 116.86 | 121.55 | 155.27 | 157.70 | 126 | 1.000 | 0 |
| gs-mixnq-hi-c1k-100k | rows_foreach | jit | 104.82 | 118.92 | 153.07 | 157.40 | 126 | 1.000 | 0 |
| gs-mixnq-hi-c1k-100k | sqlite | off | 165.18 | 166.62 | 169.46 | 196.19 | 28 | 1.000 | 0 |
| gs-mixnq-hi-c1k-100k | sqlite_idx | off | 283.51 | 290.08 | 285.73 | 288.68 | 34 | 1.000 | 0 |
| gs-mixnq-hi-c1k-100k | stream | jit | 573.87 | 579.09 | 582.58 | 590.27 | 27 | 1.000 | 0 |
| gs-mixnq-hi-c1k-100k | stream_fast | jit | 44.02 | 45.69 | 47.75 | 49.00 | 27 | 1.000 | 0 |
| gs-mixnq-hi-c1k-10m | duckdb | na |  |  | 866.30 | 897.00 | 328 | 0.370 | 0 |
| gs-mixnq-hi-c1k-10m | duckdb_1t | na |  |  | 2264.19 | 2264.69 | 199 | 1.000 | 0 |
| gs-mixnq-hi-c1k-10m | native | off | 822.07 | 828.71 | 824.10 | 827.36 | 23 | 1.000 | 0 |
| gs-mixnq-hi-c1k-10m | native_scalar | off | 1543.45 | 1559.29 | 1555.32 | 1558.46 | 23 | 1.000 | 0 |
| gs-mixnq-hi-c1k-10m | stream | jit | 58782.09 | 59700.99 | 58988.73 | 59770.87 | 27 | 1.000 | 0 |
| gs-mixnq-hi-c1k-10m | stream_fast | jit | 4524.67 | 4535.57 | 4541.15 | 4545.96 | 27 | 1.000 | 0 |
| gs-mixnq-hi-c1k-1m | cols | jit | 1325.50 | 1357.12 | 1033.17 | 1039.14 | 470 | 1.000 | 0 |
| gs-mixnq-hi-c1k-1m | cols_dict | jit | 1349.38 | 1379.04 | 1052.15 | 1077.21 | 417 | 1.000 | 0 |
| gs-mixnq-hi-c1k-1m | duckdb | na |  |  | 149.89 | 170.49 | 140 | 0.473 | 0 |
| gs-mixnq-hi-c1k-1m | duckdb_1t | na |  |  | 303.75 | 312.46 | 167 | 1.001 | 0 |
| gs-mixnq-hi-c1k-1m | duckdb_forced | na |  |  | 189.78 | 204.18 | 142 | 0.391 | 0 |
| gs-mixnq-hi-c1k-1m | duckdb_forced_1t | na |  |  | 466.85 | 471.74 | 168 | 1.001 | 0 |
| gs-mixnq-hi-c1k-1m | native | off | 81.23 | 82.67 | 82.35 | 84.18 | 23 | 1.000 | 0 |
| gs-mixnq-hi-c1k-1m | native_scalar | off | 155.59 | 159.71 | 154.45 | 157.73 | 23 | 1.000 | 0 |
| gs-mixnq-hi-c1k-1m | pdo_duckdb | off | 133.74 | 147.62 | 151.10 | 159.28 | 189 | 0.441 | 0 |
| gs-mixnq-hi-c1k-1m | pdo_duckdb_1t | off | 299.61 | 305.13 | 314.56 | 386.09 | 199 | 1.000 | 0 |
| gs-mixnq-hi-c1k-1m | rows | jit | 1345.97 | 1508.15 | 1495.00 | 1524.95 | 1015 | 1.000 | 0 |
| gs-mixnq-hi-c1k-1m | rows_foreach | jit | 1115.46 | 1215.72 | 1456.21 | 1466.10 | 1014 | 1.000 | 0 |
| gs-mixnq-hi-c1k-1m | sqlite | off | 1670.90 | 1700.64 | 1687.70 | 1810.16 | 64 | 1.000 | 0 |
| gs-mixnq-hi-c1k-1m | sqlite_idx | off | 3113.43 | 3136.14 | 3135.43 | 3227.53 | 115 | 1.000 | 0 |
| gs-mixnq-hi-c1k-1m | stream | jit | 6503.94 | 6564.33 | 5818.39 | 6522.07 | 27 | 1.000 | 0 |
| gs-mixnq-hi-c1k-1m | stream_fast | jit | 445.65 | 447.19 | 458.32 | 461.71 | 27 | 1.000 | 0 |
| gs-mixnq-lo-c10-100k | cols | jit | 118.21 | 136.58 | 110.90 | 118.88 | 71 | 1.000 | 0 |
| gs-mixnq-lo-c10-100k | cols_dict | jit | 106.25 | 118.09 | 108.26 | 123.56 | 67 | 1.000 | 0 |
| gs-mixnq-lo-c10-100k | duckdb | na |  |  | 61.43 | 62.37 | 57 | 0.786 | 0 |
| gs-mixnq-lo-c10-100k | duckdb_1t | na |  |  | 69.52 | 70.93 | 57 | 1.003 | 0 |
| gs-mixnq-lo-c10-100k | duckdb_forced | na |  |  | 71.81 | 73.63 | 58 | 0.761 | 0 |
| gs-mixnq-lo-c10-100k | duckdb_forced_1t | na |  |  | 86.42 | 87.95 | 58 | 1.003 | 0 |
| gs-mixnq-lo-c10-100k | native | off | 10.32 | 10.53 | 11.44 | 11.56 | 23 | 1.000 | 1 |
| gs-mixnq-lo-c10-100k | native_scalar | off | 17.18 | 17.48 | 18.69 | 18.93 | 23 | 1.000 | 0 |
| gs-mixnq-lo-c10-100k | pdo_duckdb | off | 40.83 | 42.59 | 56.10 | 56.76 | 91 | 0.786 | 0 |
| gs-mixnq-lo-c10-100k | pdo_duckdb_1t | off | 50.18 | 53.71 | 66.01 | 67.44 | 90 | 1.001 | 0 |
| gs-mixnq-lo-c10-100k | rows | jit | 215.85 | 247.22 | 222.95 | 228.84 | 130 | 1.000 | 0 |
| gs-mixnq-lo-c10-100k | rows_foreach | jit | 106.29 | 118.60 | 156.23 | 160.62 | 126 | 1.000 | 0 |
| gs-mixnq-lo-c10-100k | sqlite | off | 219.22 | 223.29 | 222.03 | 225.56 | 29 | 1.000 | 0 |
| gs-mixnq-lo-c10-100k | sqlite_idx | off | 365.26 | 371.37 | 361.05 | 366.94 | 33 | 1.000 | 0 |
| gs-mixnq-lo-c10-100k | stream | jit | 581.85 | 589.73 | 593.41 | 599.27 | 27 | 1.000 | 0 |
| gs-mixnq-lo-c10-100k | stream_fast | jit | 49.38 | 52.78 | 54.88 | 57.34 | 27 | 1.000 | 0 |
| gs-mixnq-lo-c10-10m | duckdb | na |  |  | 922.61 | 928.06 | 265 | 0.344 | 0 |
| gs-mixnq-lo-c10-10m | duckdb_1t | na |  |  | 2597.48 | 2610.60 | 198 | 1.000 | 0 |
| gs-mixnq-lo-c10-10m | native | off | 1116.83 | 1120.56 | 1110.32 | 1111.75 | 23 | 1.000 | 0 |
| gs-mixnq-lo-c10-10m | native_scalar | off | 1836.39 | 1841.22 | 1831.41 | 1852.92 | 23 | 1.000 | 0 |
| gs-mixnq-lo-c10-10m | stream | jit | 59373.64 | 59655.67 | 59397.23 | 59545.33 | 27 | 1.000 | 0 |
| gs-mixnq-lo-c10-10m | stream_fast | jit | 5060.51 | 5103.85 | 5053.99 | 5054.65 | 27 | 1.000 | 0 |
| gs-mixnq-lo-c10-1m | cols | jit | 1479.50 | 1513.18 | 1077.42 | 1092.77 | 470 | 1.000 | 0 |
| gs-mixnq-lo-c10-1m | cols_dict | jit | 1278.14 | 1345.33 | 1036.35 | 1059.00 | 424 | 1.000 | 0 |
| gs-mixnq-lo-c10-1m | duckdb | na |  |  | 154.81 | 167.88 | 139 | 0.450 | 0 |
| gs-mixnq-lo-c10-1m | duckdb_1t | na |  |  | 338.86 | 343.57 | 167 | 1.001 | 0 |
| gs-mixnq-lo-c10-1m | duckdb_forced | na |  |  | 194.75 | 204.73 | 140 | 0.386 | 0 |
| gs-mixnq-lo-c10-1m | duckdb_forced_1t | na |  |  | 490.80 | 500.85 | 168 | 1.002 | 0 |
| gs-mixnq-lo-c10-1m | native | off | 111.83 | 113.10 | 111.41 | 116.24 | 23 | 1.000 | 0 |
| gs-mixnq-lo-c10-1m | native_scalar | off | 185.10 | 187.18 | 183.49 | 189.29 | 23 | 1.000 | 0 |
| gs-mixnq-lo-c10-1m | pdo_duckdb | off | 146.84 | 152.71 | 161.82 | 167.65 | 202 | 0.422 | 0 |
| gs-mixnq-lo-c10-1m | pdo_duckdb_1t | off | 337.56 | 342.60 | 348.04 | 359.40 | 200 | 1.000 | 0 |
| gs-mixnq-lo-c10-1m | rows | jit | 3557.58 | 4517.99 | 2606.35 | 2645.86 | 1051 | 1.000 | 0 |
| gs-mixnq-lo-c10-1m | rows_foreach | jit | 1181.10 | 1288.70 | 1506.95 | 1545.20 | 1014 | 1.000 | 0 |
| gs-mixnq-lo-c10-1m | sqlite | off | 2296.91 | 2307.77 | 2308.63 | 2345.09 | 59 | 1.000 | 0 |
| gs-mixnq-lo-c10-1m | sqlite_idx | off | 4167.31 | 4247.66 | 4189.13 | 4310.77 | 101 | 1.000 | 0 |
| gs-mixnq-lo-c10-1m | stream | jit | 5869.00 | 5902.01 | 5899.77 | 6136.28 | 27 | 1.000 | 0 |
| gs-mixnq-lo-c10-1m | stream_fast | jit | 503.34 | 519.58 | 514.70 | 519.77 | 27 | 1.000 | 0 |
| gs-mixnq-lo-c100k-100k | cols | jit | 144.76 | 154.37 | 130.61 | 136.61 | 88 | 1.000 | 0 |
| gs-mixnq-lo-c100k-100k | cols_dict | jit | 139.95 | 154.59 | 137.39 | 144.12 | 88 | 1.000 | 0 |
| gs-mixnq-lo-c100k-100k | duckdb | na |  |  | 92.87 | 93.32 | 73 | 0.786 | 0 |
| gs-mixnq-lo-c100k-100k | duckdb_1t | na |  |  | 101.80 | 104.46 | 64 | 1.002 | 0 |
| gs-mixnq-lo-c100k-100k | duckdb_forced | na |  |  | 134.93 | 139.50 | 85 | 0.787 | 0 |
| gs-mixnq-lo-c100k-100k | duckdb_forced_1t | na |  |  | 149.90 | 157.91 | 71 | 1.002 | 0 |
| gs-mixnq-lo-c100k-100k | native | off | 16.99 | 18.48 | 27.14 | 27.61 | 44 | 1.000 | 0 |
| gs-mixnq-lo-c100k-100k | native_scalar | off | 24.66 | 25.59 | 34.27 | 34.81 | 44 | 1.000 | 0 |
| gs-mixnq-lo-c100k-100k | pdo_duckdb | off | 63.06 | 66.77 | 83.98 | 86.61 | 117 | 0.817 | 0 |
| gs-mixnq-lo-c100k-100k | pdo_duckdb_1t | off | 67.89 | 72.16 | 89.76 | 91.49 | 112 | 1.000 | 0 |
| gs-mixnq-lo-c100k-100k | rows | jit | 257.41 | 272.91 | 244.41 | 247.23 | 145 | 1.000 | 0 |
| gs-mixnq-lo-c100k-100k | rows_foreach | jit | 147.16 | 161.27 | 184.06 | 189.36 | 143 | 1.000 | 0 |
| gs-mixnq-lo-c100k-100k | sqlite | off | 282.50 | 291.87 | 298.30 | 310.19 | 50 | 1.000 | 0 |
| gs-mixnq-lo-c100k-100k | sqlite_idx | off | 442.51 | 472.45 | 457.37 | 469.36 | 56 | 1.000 | 0 |
| gs-mixnq-lo-c100k-100k | stream | jit | 597.52 | 608.14 | 614.63 | 624.57 | 47 | 1.000 | 0 |
| gs-mixnq-lo-c100k-100k | stream_fast | jit | 61.68 | 63.68 | 73.60 | 76.34 | 47 | 1.000 | 0 |
| gs-mixnq-lo-c100k-10m | duckdb | na |  |  | 1114.60 | 1165.44 | 466 | 0.348 | 0 |
| gs-mixnq-lo-c100k-10m | duckdb_1t | na |  |  | 2861.57 | 2864.35 | 211 | 1.001 | 0 |
| gs-mixnq-lo-c100k-10m | native | off | 1329.16 | 1330.92 | 1347.79 | 1375.97 | 60 | 1.000 | 0 |
| gs-mixnq-lo-c100k-10m | native_scalar | off | 2100.34 | 2111.97 | 2080.09 | 2116.44 | 60 | 1.000 | 0 |
| gs-mixnq-lo-c100k-10m | stream | jit | 61414.02 | 61488.79 | 61267.25 | 61439.76 | 63 | 1.000 | 0 |
| gs-mixnq-lo-c100k-10m | stream_fast | jit | 5979.02 | 6408.83 | 5882.88 | 6025.29 | 63 | 1.000 | 0 |
| gs-mixnq-lo-c100k-1m | cols | jit | 1578.60 | 1626.81 | 1193.20 | 1202.57 | 500 | 1.000 | 0 |
| gs-mixnq-lo-c100k-1m | cols_dict | jit | 1626.74 | 1694.59 | 1260.24 | 1316.63 | 448 | 1.000 | 0 |
| gs-mixnq-lo-c100k-1m | duckdb | na |  |  | 232.66 | 249.41 | 222 | 0.473 | 0 |
| gs-mixnq-lo-c100k-1m | duckdb_1t | na |  |  | 428.55 | 448.77 | 180 | 1.001 | 0 |
| gs-mixnq-lo-c100k-1m | duckdb_forced | na |  |  | 346.03 | 372.10 | 313 | 0.427 | 0 |
| gs-mixnq-lo-c100k-1m | duckdb_forced_1t | na |  |  | 653.24 | 674.15 | 193 | 1.000 | 0 |
| gs-mixnq-lo-c100k-1m | native | off | 146.66 | 159.92 | 163.33 | 166.82 | 60 | 1.000 | 0 |
| gs-mixnq-lo-c100k-1m | native_scalar | off | 222.06 | 225.55 | 236.15 | 246.61 | 60 | 1.000 | 0 |
| gs-mixnq-lo-c100k-1m | pdo_duckdb | off | 204.40 | 220.10 | 231.81 | 242.58 | 293 | 0.458 | 0 |
| gs-mixnq-lo-c100k-1m | pdo_duckdb_1t | off | 391.84 | 399.37 | 418.82 | 437.17 | 239 | 1.000 | 0 |
| gs-mixnq-lo-c100k-1m | rows | jit | 3804.32 | 4804.13 | 2772.00 | 2816.02 | 1067 | 1.000 | 0 |
| gs-mixnq-lo-c100k-1m | rows_foreach | jit | 1498.42 | 1645.29 | 1734.17 | 1754.32 | 1044 | 1.000 | 0 |
| gs-mixnq-lo-c100k-1m | sqlite | off | 2550.74 | 2606.13 | 2565.78 | 2593.21 | 111 | 1.000 | 0 |
| gs-mixnq-lo-c100k-1m | sqlite_idx | off | 4702.46 | 4781.62 | 4725.32 | 4839.16 | 176 | 1.000 | 0 |
| gs-mixnq-lo-c100k-1m | stream | jit | 6549.70 | 6601.03 | 6107.20 | 6548.13 | 63 | 1.000 | 0 |
| gs-mixnq-lo-c100k-1m | stream_fast | jit | 595.83 | 610.58 | 608.80 | 624.80 | 63 | 1.000 | 0 |
| gs-mixnq-lo-c1k-100k | cols | jit | 119.91 | 137.62 | 113.41 | 118.56 | 71 | 1.000 | 0 |
| gs-mixnq-lo-c1k-100k | cols_dict | jit | 123.34 | 132.72 | 112.06 | 115.58 | 66 | 1.000 | 0 |
| gs-mixnq-lo-c1k-100k | duckdb | na |  |  | 61.50 | 63.21 | 58 | 0.786 | 0 |
| gs-mixnq-lo-c1k-100k | duckdb_1t | na |  |  | 69.32 | 71.21 | 57 | 1.003 | 0 |
| gs-mixnq-lo-c1k-100k | duckdb_forced | na |  |  | 73.09 | 74.27 | 59 | 0.762 | 0 |
| gs-mixnq-lo-c1k-100k | duckdb_forced_1t | na |  |  | 86.82 | 88.74 | 58 | 1.003 | 0 |
| gs-mixnq-lo-c1k-100k | native | off | 9.84 | 10.12 | 11.32 | 12.22 | 23 | 1.000 | 0 |
| gs-mixnq-lo-c1k-100k | native_scalar | off | 17.06 | 17.27 | 18.70 | 18.89 | 23 | 1.000 | 0 |
| gs-mixnq-lo-c1k-100k | pdo_duckdb | off | 40.90 | 42.02 | 55.60 | 56.42 | 91 | 0.739 | 0 |
| gs-mixnq-lo-c1k-100k | pdo_duckdb_1t | off | 49.16 | 53.93 | 64.55 | 66.82 | 90 | 1.001 | 0 |
| gs-mixnq-lo-c1k-100k | rows | jit | 215.10 | 254.14 | 222.86 | 230.68 | 131 | 1.000 | 0 |
| gs-mixnq-lo-c1k-100k | rows_foreach | jit | 109.25 | 126.19 | 159.39 | 163.66 | 126 | 1.000 | 0 |
| gs-mixnq-lo-c1k-100k | sqlite | off | 229.31 | 232.35 | 230.81 | 235.76 | 30 | 1.000 | 0 |
| gs-mixnq-lo-c1k-100k | sqlite_idx | off | 370.07 | 386.99 | 379.90 | 389.21 | 35 | 1.000 | 0 |
| gs-mixnq-lo-c1k-100k | stream | jit | 593.25 | 601.54 | 596.63 | 616.53 | 27 | 1.000 | 0 |
| gs-mixnq-lo-c1k-100k | stream_fast | jit | 50.57 | 52.30 | 57.04 | 57.96 | 27 | 1.000 | 0 |
| gs-mixnq-lo-c1k-10m | duckdb | na |  |  | 886.01 | 897.11 | 265 | 0.354 | 0 |
| gs-mixnq-lo-c1k-10m | duckdb_1t | na |  |  | 2434.57 | 2437.17 | 199 | 1.000 | 0 |
| gs-mixnq-lo-c1k-10m | native | off | 1087.83 | 1096.36 | 1085.30 | 1085.75 | 23 | 1.000 | 0 |
| gs-mixnq-lo-c1k-10m | native_scalar | off | 1806.05 | 1824.00 | 1808.85 | 1820.51 | 23 | 1.000 | 0 |
| gs-mixnq-lo-c1k-10m | stream | jit | 60123.24 | 60280.65 | 60089.24 | 60791.61 | 27 | 1.000 | 0 |
| gs-mixnq-lo-c1k-10m | stream_fast | jit | 5285.76 | 5484.12 | 5311.47 | 5502.17 | 27 | 1.000 | 0 |
| gs-mixnq-lo-c1k-1m | cols | jit | 1472.13 | 1548.06 | 1094.80 | 1129.86 | 470 | 1.000 | 0 |
| gs-mixnq-lo-c1k-1m | cols_dict | jit | 1400.97 | 1458.58 | 1064.93 | 1094.68 | 417 | 1.000 | 0 |
| gs-mixnq-lo-c1k-1m | duckdb | na |  |  | 162.98 | 180.26 | 169 | 0.472 | 0 |
| gs-mixnq-lo-c1k-1m | duckdb_1t | na |  |  | 322.77 | 331.82 | 167 | 1.001 | 0 |
| gs-mixnq-lo-c1k-1m | duckdb_forced | na |  |  | 204.86 | 217.48 | 171 | 0.391 | 0 |
| gs-mixnq-lo-c1k-1m | duckdb_forced_1t | na |  |  | 494.43 | 501.51 | 168 | 1.001 | 0 |
| gs-mixnq-lo-c1k-1m | native | off | 106.88 | 109.24 | 108.70 | 111.42 | 23 | 1.000 | 0 |
| gs-mixnq-lo-c1k-1m | native_scalar | off | 179.93 | 185.04 | 182.39 | 184.72 | 23 | 1.000 | 0 |
| gs-mixnq-lo-c1k-1m | pdo_duckdb | off | 142.79 | 148.35 | 155.73 | 159.84 | 188 | 0.431 | 0 |
| gs-mixnq-lo-c1k-1m | pdo_duckdb_1t | off | 325.99 | 401.20 | 336.13 | 360.39 | 200 | 1.000 | 0 |
| gs-mixnq-lo-c1k-1m | rows | jit | 3638.12 | 4599.12 | 2595.20 | 2630.32 | 1051 | 1.000 | 0 |
| gs-mixnq-lo-c1k-1m | rows_foreach | jit | 1172.88 | 1294.22 | 1522.91 | 1544.01 | 1014 | 1.000 | 0 |
| gs-mixnq-lo-c1k-1m | sqlite | off | 2329.96 | 2346.27 | 2366.47 | 2418.76 | 66 | 1.000 | 0 |
| gs-mixnq-lo-c1k-1m | sqlite_idx | off | 4394.28 | 4429.60 | 4394.26 | 4513.27 | 115 | 1.000 | 0 |
| gs-mixnq-lo-c1k-1m | stream | jit | 5883.09 | 6028.88 | 5921.43 | 6061.64 | 27 | 1.000 | 0 |
| gs-mixnq-lo-c1k-1m | stream_fast | jit | 514.23 | 521.78 | 532.03 | 544.53 | 27 | 1.000 | 0 |
| gs-wide-hi-c1k-100k | cols | jit | 414.07 | 423.42 | 437.67 | 461.93 | 125 | 1.000 | 0 |
| gs-wide-hi-c1k-100k | cols_dict | jit | 419.76 | 427.48 | 430.35 | 436.93 | 118 | 1.000 | 0 |
| gs-wide-hi-c1k-100k | duckdb | na |  |  | 71.16 | 77.29 | 72 | 0.624 | 0 |
| gs-wide-hi-c1k-100k | duckdb_1t | na |  |  | 104.38 | 106.87 | 69 | 1.002 | 0 |
| gs-wide-hi-c1k-100k | duckdb_forced | na |  |  | 93.70 | 105.93 | 77 | 0.511 | 0 |
| gs-wide-hi-c1k-100k | duckdb_forced_1t | na |  |  | 172.29 | 176.30 | 71 | 1.001 | 0 |
| gs-wide-hi-c1k-100k | native | off | 10.13 | 10.38 | 12.18 | 12.47 | 23 | 1.000 | 0 |
| gs-wide-hi-c1k-100k | native_scalar | off | 29.99 | 31.22 | 31.55 | 31.84 | 23 | 1.000 | 0 |
| gs-wide-hi-c1k-100k | pdo_duckdb | off | 52.24 | 54.94 | 66.17 | 71.24 | 106 | 0.565 | 0 |
| gs-wide-hi-c1k-100k | pdo_duckdb_1t | off | 88.87 | 92.79 | 101.67 | 103.71 | 101 | 1.000 | 0 |
| gs-wide-hi-c1k-100k | rows | jit | 374.04 | 402.69 | 483.53 | 489.29 | 202 | 1.000 | 0 |
| gs-wide-hi-c1k-100k | rows_foreach | jit | 349.35 | 429.23 | 479.66 | 483.60 | 202 | 1.000 | 0 |
| gs-wide-hi-c1k-100k | sqlite | off | 194.73 | 198.62 | 199.63 | 207.01 | 28 | 1.000 | 0 |
| gs-wide-hi-c1k-100k | sqlite_idx | off | 314.28 | 318.43 | 316.60 | 324.76 | 34 | 1.000 | 0 |
| gs-wide-hi-c1k-100k | stream | jit | 1232.58 | 1248.71 | 1235.16 | 1371.25 | 27 | 1.000 | 0 |
| gs-wide-hi-c1k-100k | stream_fast | jit | 74.48 | 76.52 | 77.45 | 83.23 | 27 | 1.000 | 0 |
| gs-wide-hi-c1k-10m | duckdb | na |  |  | 1891.04 | 1905.80 | 416 | 0.342 | 0 |
| gs-wide-hi-c1k-10m | duckdb_1t | na |  |  | 5365.93 | 5367.17 | 197 | 1.000 | 0 |
| gs-wide-hi-c1k-10m | native | off | 1154.24 | 1159.82 | 1159.85 | 1174.02 | 23 | 1.000 | 0 |
| gs-wide-hi-c1k-10m | native_scalar | off | 3085.95 | 3107.67 | 3090.89 | 3117.27 | 23 | 1.000 | 0 |
| gs-wide-hi-c1k-10m | stream | jit | 123243.26 | 123952.42 | 124730.12 | 126884.51 | 27 | 1.000 | 0 |
| gs-wide-hi-c1k-10m | stream_fast | jit | 7497.64 | 8092.25 | 7544.74 | 7586.12 | 27 | 1.000 | 0 |
| gs-wide-hi-c1k-1m | cols | jit | 4446.19 | 4484.17 | 3678.23 | 3697.46 | 923 | 1.000 | 0 |
| gs-wide-hi-c1k-1m | cols_dict | jit | 4473.93 | 4524.53 | 3700.91 | 3725.33 | 869 | 1.000 | 0 |
| gs-wide-hi-c1k-1m | duckdb | na |  |  | 254.36 | 276.43 | 171 | 0.404 | 0 |
| gs-wide-hi-c1k-1m | duckdb_1t | na |  |  | 622.04 | 693.81 | 197 | 1.000 | 0 |
| gs-wide-hi-c1k-1m | duckdb_forced | na |  |  | 425.03 | 450.27 | 216 | 0.325 | 0 |
| gs-wide-hi-c1k-1m | duckdb_forced_1t | na |  |  | 1264.87 | 1288.26 | 170 | 1.000 | 0 |
| gs-wide-hi-c1k-1m | native | off | 112.21 | 114.86 | 112.48 | 114.73 | 23 | 1.000 | 0 |
| gs-wide-hi-c1k-1m | native_scalar | off | 306.91 | 308.45 | 305.19 | 309.71 | 23 | 1.000 | 0 |
| gs-wide-hi-c1k-1m | pdo_duckdb | off | 249.33 | 263.41 | 261.66 | 280.36 | 216 | 0.384 | 0 |
| gs-wide-hi-c1k-1m | pdo_duckdb_1t | off | 643.27 | 696.00 | 659.38 | 795.59 | 229 | 1.000 | 0 |
| gs-wide-hi-c1k-1m | rows | jit | 4002.80 | 4290.31 | 4245.09 | 4290.75 | 1759 | 1.000 | 0 |
| gs-wide-hi-c1k-1m | rows_foreach | jit | 3663.25 | 3910.08 | 4204.67 | 4278.57 | 1757 | 1.000 | 0 |
| gs-wide-hi-c1k-1m | sqlite | off | 1985.17 | 2028.53 | 1990.05 | 2043.12 | 64 | 1.000 | 0 |
| gs-wide-hi-c1k-1m | sqlite_idx | off | 3462.71 | 3491.86 | 3433.60 | 3539.98 | 115 | 1.000 | 0 |
| gs-wide-hi-c1k-1m | stream | jit | 12337.78 | 12508.74 | 12345.35 | 12556.01 | 27 | 1.000 | 0 |
| gs-wide-hi-c1k-1m | stream_fast | jit | 754.63 | 781.13 | 751.83 | 766.44 | 27 | 1.000 | 0 |
| gs-wide-lo-c1k-100k | cols | jit | 424.78 | 438.82 | 442.35 | 447.71 | 125 | 1.000 | 0 |
| gs-wide-lo-c1k-100k | cols_dict | jit | 430.49 | 476.77 | 432.88 | 448.76 | 118 | 1.000 | 0 |
| gs-wide-lo-c1k-100k | duckdb | na |  |  | 72.31 | 73.27 | 73 | 0.616 | 0 |
| gs-wide-lo-c1k-100k | duckdb_1t | na |  |  | 107.89 | 109.74 | 71 | 1.002 | 0 |
| gs-wide-lo-c1k-100k | duckdb_forced | na |  |  | 98.13 | 111.03 | 78 | 0.508 | 0 |
| gs-wide-lo-c1k-100k | duckdb_forced_1t | na |  |  | 178.64 | 182.06 | 73 | 1.001 | 0 |
| gs-wide-lo-c1k-100k | native | off | 13.05 | 13.34 | 15.15 | 15.64 | 23 | 1.000 | 0 |
| gs-wide-lo-c1k-100k | native_scalar | off | 32.21 | 32.57 | 34.41 | 34.79 | 23 | 1.000 | 0 |
| gs-wide-lo-c1k-100k | pdo_duckdb | off | 53.04 | 55.19 | 67.33 | 68.78 | 107 | 0.563 | 0 |
| gs-wide-lo-c1k-100k | pdo_duckdb_1t | off | 91.88 | 94.42 | 105.26 | 110.46 | 103 | 1.000 | 0 |
| gs-wide-lo-c1k-100k | rows | jit | 545.93 | 577.06 | 626.21 | 634.47 | 207 | 1.000 | 0 |
| gs-wide-lo-c1k-100k | rows_foreach | jit | 372.69 | 408.02 | 490.82 | 498.84 | 202 | 1.000 | 0 |
| gs-wide-lo-c1k-100k | sqlite | off | 267.03 | 271.12 | 262.70 | 274.31 | 30 | 1.000 | 0 |
| gs-wide-lo-c1k-100k | sqlite_idx | off | 412.99 | 426.03 | 409.75 | 422.87 | 35 | 1.000 | 0 |
| gs-wide-lo-c1k-100k | stream | jit | 1252.11 | 1275.28 | 1249.51 | 1277.07 | 27 | 1.000 | 0 |
| gs-wide-lo-c1k-100k | stream_fast | jit | 79.51 | 80.69 | 88.51 | 90.17 | 27 | 1.000 | 0 |
| gs-wide-lo-c1k-10m | duckdb | na |  |  | 1960.64 | 1968.61 | 476 | 0.339 | 0 |
| gs-wide-lo-c1k-10m | duckdb_1t | na |  |  | 5592.17 | 5600.87 | 199 | 1.000 | 0 |
| gs-wide-lo-c1k-10m | native | off | 1442.30 | 1482.55 | 1440.89 | 1453.50 | 23 | 1.001 | 0 |
| gs-wide-lo-c1k-10m | native_scalar | off | 3368.36 | 3421.27 | 3381.15 | 3394.03 | 23 | 1.000 | 0 |
| gs-wide-lo-c1k-10m | stream | jit | 124180.67 | 124638.29 | 124047.39 | 127372.48 | 27 | 1.000 | 0 |
| gs-wide-lo-c1k-10m | stream_fast | jit | 8045.47 | 8156.51 | 8338.13 | 8394.41 | 27 | 1.000 | 0 |
| gs-wide-lo-c1k-1m | cols | jit | 4544.13 | 4562.96 | 3714.78 | 3779.65 | 923 | 1.000 | 0 |
| gs-wide-lo-c1k-1m | cols_dict | jit | 4517.40 | 4618.67 | 3719.47 | 3768.12 | 869 | 1.000 | 0 |
| gs-wide-lo-c1k-1m | duckdb | na |  |  | 264.29 | 281.25 | 185 | 0.395 | 0 |
| gs-wide-lo-c1k-1m | duckdb_1t | na |  |  | 649.30 | 661.47 | 199 | 1.000 | 0 |
| gs-wide-lo-c1k-1m | duckdb_forced | na |  |  | 430.86 | 450.11 | 208 | 0.321 | 0 |
| gs-wide-lo-c1k-1m | duckdb_forced_1t | na |  |  | 1318.75 | 1349.74 | 170 | 1.000 | 0 |
| gs-wide-lo-c1k-1m | native | off | 142.62 | 148.69 | 142.21 | 144.28 | 23 | 1.000 | 0 |
| gs-wide-lo-c1k-1m | native_scalar | off | 334.70 | 338.49 | 332.88 | 339.59 | 23 | 1.000 | 0 |
| gs-wide-lo-c1k-1m | pdo_duckdb | off | 254.67 | 269.98 | 271.06 | 288.54 | 235 | 0.372 | 0 |
| gs-wide-lo-c1k-1m | pdo_duckdb_1t | off | 669.97 | 679.73 | 685.42 | 719.69 | 231 | 1.000 | 0 |
| gs-wide-lo-c1k-1m | rows | jit | 7251.64 | 9133.99 | 6332.15 | 6373.01 | 1790 | 1.000 | 0 |
| gs-wide-lo-c1k-1m | rows_foreach | jit | 3718.05 | 4056.60 | 4286.17 | 4331.09 | 1757 | 1.000 | 0 |
| gs-wide-lo-c1k-1m | sqlite | off | 2671.98 | 2706.09 | 2676.68 | 2718.02 | 66 | 1.000 | 0 |
| gs-wide-lo-c1k-1m | sqlite_idx | off | 4636.69 | 4791.78 | 4638.96 | 4711.31 | 115 | 1.000 | 0 |
| gs-wide-lo-c1k-1m | stream | jit | 12327.03 | 12361.59 | 12421.38 | 12546.44 | 27 | 1.000 | 0 |
| gs-wide-lo-c1k-1m | stream_fast | jit | 822.29 | 830.21 | 835.28 | 908.74 | 27 | 1.000 | 0 |
| gt-mixed-hi-c10-100k | cols | jit | 212.53 | 228.51 | 202.32 | 209.80 | 71 | 1.000 | 0 |
| gt-mixed-hi-c10-100k | cols_dict | jit | 204.49 | 215.52 | 200.12 | 202.74 | 66 | 1.000 | 0 |
| gt-mixed-hi-c10-100k | native | off | 65.41 | 66.79 | 67.61 | 70.04 | 49 | 1.000 | 0 |
| gt-mixed-hi-c10-100k | native_scalar | off | 73.20 | 76.07 | 74.61 | 75.93 | 49 | 1.000 | 0 |
| gt-mixed-hi-c10-100k | rows_foreach | jit | 254.28 | 278.49 | 376.67 | 384.03 | 126 | 1.000 | 0 |
| gt-mixed-hi-c10-100k | sqlite | off | 478.75 | 483.79 | 475.24 | 497.65 | 37 | 1.000 | 0 |
| gt-mixed-hi-c10-100k | sqlite_idx | off | 472.10 | 475.49 | 481.79 | 490.63 | 43 | 1.000 | 0 |
| gt-mixed-hi-c10-10m | native | off | 5029.38 | 5041.76 | 5006.13 | 5029.63 | 1774 | 1.000 | 0 |
| gt-mixed-hi-c10-10m | native_scalar | off | 5849.15 | 5870.29 | 5805.47 | 5824.81 | 1774 | 1.000 | 0 |
| gt-mixed-hi-c10-1m | cols | jit | 2250.18 | 2279.49 | 1847.44 | 1895.98 | 467 | 1.000 | 0 |
| gt-mixed-hi-c10-1m | cols_dict | jit | 2130.91 | 2250.48 | 1844.51 | 1867.34 | 422 | 1.000 | 0 |
| gt-mixed-hi-c10-1m | native | off | 524.65 | 531.77 | 527.52 | 541.37 | 218 | 1.000 | 0 |
| gt-mixed-hi-c10-1m | native_scalar | off | 599.69 | 614.85 | 603.68 | 613.23 | 218 | 1.000 | 0 |
| gt-mixed-hi-c10-1m | rows_foreach | jit | 2537.06 | 2919.86 | 3040.83 | 3138.75 | 1012 | 1.000 | 0 |
| gt-mixed-hi-c10-1m | sqlite | off | 3902.56 | 3917.36 | 3926.87 | 4011.01 | 161 | 1.000 | 0 |
| gt-mixed-hi-c10-1m | sqlite_idx | off | 4934.06 | 4991.93 | 5008.62 | 5076.39 | 204 | 1.000 | 0 |
| gt-mixed-hi-c100k-100k | cols | jit | 223.45 | 230.18 | 206.25 | 208.81 | 71 | 1.000 | 0 |
| gt-mixed-hi-c100k-100k | cols_dict | jit | 246.86 | 254.34 | 232.32 | 244.69 | 75 | 1.000 | 0 |
| gt-mixed-hi-c100k-100k | native | off | 70.56 | 73.30 | 75.56 | 77.35 | 47 | 1.000 | 0 |
| gt-mixed-hi-c100k-100k | native_scalar | off | 81.33 | 84.51 | 82.78 | 89.07 | 47 | 1.000 | 0 |
| gt-mixed-hi-c100k-100k | rows_foreach | jit | 259.91 | 291.92 | 380.16 | 390.21 | 126 | 1.000 | 0 |
| gt-mixed-hi-c100k-100k | sqlite | off | 490.54 | 496.48 | 493.88 | 502.42 | 38 | 1.000 | 0 |
| gt-mixed-hi-c100k-100k | sqlite_idx | off | 501.87 | 505.41 | 513.55 | 526.76 | 46 | 1.000 | 0 |
| gt-mixed-hi-c100k-10m | native | off | 5948.71 | 5972.64 | 5917.77 | 5934.34 | 1697 | 1.000 | 0 |
| gt-mixed-hi-c100k-10m | native_scalar | off | 6816.65 | 6922.31 | 7082.83 | 7264.77 | 1697 | 1.000 | 0 |
| gt-mixed-hi-c100k-1m | cols | jit | 2299.82 | 2316.65 | 1853.63 | 1899.47 | 470 | 1.000 | 0 |
| gt-mixed-hi-c100k-1m | cols_dict | jit | 2502.35 | 2556.72 | 2058.72 | 2141.65 | 425 | 1.000 | 0 |
| gt-mixed-hi-c100k-1m | native | off | 627.65 | 641.95 | 639.84 | 745.19 | 243 | 1.000 | 0 |
| gt-mixed-hi-c100k-1m | native_scalar | off | 762.97 | 817.63 | 739.59 | 793.72 | 243 | 1.000 | 0 |
| gt-mixed-hi-c100k-1m | rows_foreach | jit | 2661.18 | 3055.70 | 3057.27 | 3115.57 | 1016 | 1.000 | 0 |
| gt-mixed-hi-c100k-1m | sqlite | off | 3946.70 | 3985.46 | 3942.90 | 4055.60 | 164 | 1.000 | 0 |
| gt-mixed-hi-c100k-1m | sqlite_idx | off | 5172.49 | 5237.72 | 5218.28 | 5255.75 | 230 | 1.000 | 0 |
| gt-mixed-hi-c1k-100k | cols | jit | 217.95 | 227.76 | 202.65 | 209.76 | 71 | 1.000 | 0 |
| gt-mixed-hi-c1k-100k | cols_dict | jit | 219.28 | 240.94 | 207.51 | 219.08 | 66 | 1.000 | 0 |
| gt-mixed-hi-c1k-100k | native | off | 66.01 | 67.68 | 68.14 | 68.86 | 50 | 1.000 | 0 |
| gt-mixed-hi-c1k-100k | native_scalar | off | 74.20 | 77.73 | 75.60 | 77.82 | 50 | 1.000 | 0 |
| gt-mixed-hi-c1k-100k | rows_foreach | jit | 256.16 | 276.87 | 374.43 | 383.87 | 126 | 1.000 | 0 |
| gt-mixed-hi-c1k-100k | sqlite | off | 495.28 | 523.84 | 489.58 | 505.77 | 37 | 1.000 | 0 |
| gt-mixed-hi-c1k-100k | sqlite_idx | off | 497.92 | 512.81 | 506.14 | 511.95 | 44 | 1.000 | 0 |
| gt-mixed-hi-c1k-10m | native | off | 5034.99 | 5044.97 | 5105.73 | 5131.13 | 1711 | 1.000 | 0 |
| gt-mixed-hi-c1k-10m | native_scalar | off | 5765.21 | 5770.99 | 5834.33 | 5853.73 | 1711 | 1.000 | 0 |
| gt-mixed-hi-c1k-1m | cols | jit | 2258.63 | 2274.39 | 1849.80 | 1956.49 | 467 | 1.000 | 0 |
| gt-mixed-hi-c1k-1m | cols_dict | jit | 2268.26 | 2315.81 | 1855.58 | 1880.96 | 415 | 1.000 | 0 |
| gt-mixed-hi-c1k-1m | native | off | 526.06 | 535.45 | 533.72 | 542.08 | 222 | 1.000 | 0 |
| gt-mixed-hi-c1k-1m | native_scalar | off | 603.88 | 609.67 | 603.05 | 612.62 | 222 | 1.000 | 0 |
| gt-mixed-hi-c1k-1m | rows_foreach | jit | 2552.16 | 2933.36 | 3036.71 | 3109.59 | 1012 | 1.000 | 0 |
| gt-mixed-hi-c1k-1m | sqlite | off | 3945.29 | 3971.64 | 3916.78 | 3941.64 | 161 | 1.000 | 0 |
| gt-mixed-hi-c1k-1m | sqlite_idx | off | 5075.57 | 5123.65 | 5119.30 | 5172.46 | 211 | 1.000 | 0 |
| gt-mixed-lo-c10-100k | cols | jit | 335.25 | 379.16 | 264.67 | 279.25 | 71 | 1.000 | 0 |
| gt-mixed-lo-c10-100k | cols_dict | jit | 235.01 | 247.87 | 224.76 | 228.67 | 66 | 1.000 | 0 |
| gt-mixed-lo-c10-100k | native | off | 76.50 | 80.47 | 78.79 | 81.89 | 49 | 1.000 | 0 |
| gt-mixed-lo-c10-100k | native_scalar | off | 83.54 | 85.57 | 86.24 | 92.26 | 49 | 1.000 | 0 |
| gt-mixed-lo-c10-100k | rows_foreach | jit | 354.46 | 413.03 | 478.17 | 487.77 | 126 | 1.000 | 0 |
| gt-mixed-lo-c10-100k | sqlite | off | 1543.97 | 1587.93 | 1546.82 | 1552.03 | 39 | 1.000 | 0 |
| gt-mixed-lo-c10-100k | sqlite_idx | off | 2314.28 | 2337.77 | 2363.96 | 2434.88 | 43 | 1.000 | 0 |
| gt-mixed-lo-c10-10m | native | off | 5171.50 | 5182.51 | 5181.67 | 5184.17 | 1774 | 1.000 | 0 |
| gt-mixed-lo-c10-10m | native_scalar | off | 5918.19 | 5963.74 | 5969.87 | 5974.58 | 1774 | 1.000 | 0 |
| gt-mixed-lo-c10-1m | cols | jit | 2893.37 | 2930.77 | 2019.78 | 2046.86 | 466 | 1.000 | 0 |
| gt-mixed-lo-c10-1m | cols_dict | jit | 2314.13 | 2440.53 | 1884.23 | 1896.30 | 422 | 1.000 | 0 |
| gt-mixed-lo-c10-1m | native | off | 555.72 | 559.62 | 563.06 | 612.53 | 218 | 1.000 | 0 |
| gt-mixed-lo-c10-1m | native_scalar | off | 638.79 | 648.49 | 637.78 | 663.85 | 218 | 1.000 | 0 |
| gt-mixed-lo-c10-1m | rows_foreach | jit | 2765.36 | 3200.34 | 3247.96 | 3279.38 | 1012 | 1.000 | 0 |
| gt-mixed-lo-c10-1m | sqlite | off | 6917.14 | 6988.93 | 6972.51 | 7044.29 | 162 | 1.000 | 0 |
| gt-mixed-lo-c10-1m | sqlite_idx | off | 11816.33 | 12012.85 | 11791.74 | 12194.69 | 204 | 1.000 | 0 |
| gt-mixed-lo-c100k-100k | cols | jit | 655.32 | 687.40 | 536.32 | 547.85 | 90 | 1.000 | 0 |
| gt-mixed-lo-c100k-100k | cols_dict | jit | 415.65 | 513.13 | 338.19 | 355.24 | 89 | 1.000 | 0 |
| gt-mixed-lo-c100k-100k | native | off | 160.37 | 167.19 | 186.97 | 200.53 | 84 | 1.000 | 0 |
| gt-mixed-lo-c100k-100k | native_scalar | off | 171.32 | 176.24 | 194.56 | 199.72 | 84 | 1.000 | 0 |
| gt-mixed-lo-c100k-100k | rows_foreach | jit | 800.34 | 896.36 | 816.58 | 876.92 | 145 | 1.000 | 0 |
| gt-mixed-lo-c100k-100k | sqlite | off | 2896.75 | 2907.18 | 2918.11 | 2969.79 | 58 | 1.000 | 0 |
| gt-mixed-lo-c100k-100k | sqlite_idx | off | 3730.39 | 3745.08 | 3725.09 | 3766.22 | 64 | 1.000 | 0 |
| gt-mixed-lo-c100k-10m | native | off | 6198.77 | 6402.35 | 6271.78 | 6288.54 | 1697 | 1.000 | 0 |
| gt-mixed-lo-c100k-10m | native_scalar | off | 7295.01 | 7541.71 | 7026.60 | 7410.00 | 1697 | 1.000 | 0 |
| gt-mixed-lo-c100k-1m | cols | jit | 3511.14 | 3753.88 | 2635.08 | 2751.40 | 498 | 1.000 | 0 |
| gt-mixed-lo-c100k-1m | cols_dict | jit | 2761.18 | 2825.16 | 2188.16 | 2301.22 | 448 | 1.000 | 0 |
| gt-mixed-lo-c100k-1m | native | off | 754.22 | 766.43 | 787.17 | 800.36 | 250 | 1.000 | 0 |
| gt-mixed-lo-c100k-1m | native_scalar | off | 877.24 | 918.01 | 901.57 | 943.16 | 250 | 1.000 | 0 |
| gt-mixed-lo-c100k-1m | rows_foreach | jit | 4025.80 | 4553.86 | 4252.86 | 4350.42 | 1044 | 1.000 | 0 |
| gt-mixed-lo-c100k-1m | sqlite | off | 8161.68 | 8187.16 | 8194.53 | 8312.77 | 193 | 1.000 | 0 |
| gt-mixed-lo-c100k-1m | sqlite_idx | off | 13210.05 | 13432.02 | 13269.96 | 13670.65 | 258 | 1.000 | 0 |
| gt-mixed-lo-c1k-100k | cols | jit | 367.43 | 408.13 | 309.02 | 314.01 | 71 | 1.000 | 0 |
| gt-mixed-lo-c1k-100k | cols_dict | jit | 261.06 | 265.14 | 237.68 | 242.39 | 66 | 1.000 | 0 |
| gt-mixed-lo-c1k-100k | native | off | 78.50 | 79.72 | 81.08 | 85.77 | 50 | 1.000 | 0 |
| gt-mixed-lo-c1k-100k | native_scalar | off | 86.17 | 88.19 | 88.47 | 91.16 | 50 | 1.000 | 0 |
| gt-mixed-lo-c1k-100k | rows_foreach | jit | 400.16 | 455.44 | 516.50 | 528.38 | 126 | 1.000 | 0 |
| gt-mixed-lo-c1k-100k | sqlite | off | 1738.24 | 1763.35 | 1743.46 | 1772.98 | 40 | 1.000 | 0 |
| gt-mixed-lo-c1k-100k | sqlite_idx | off | 2537.51 | 2569.90 | 2594.49 | 2653.91 | 44 | 1.000 | 0 |
| gt-mixed-lo-c1k-10m | cols | jit | 24279.33 | 26485.11 | 19562.27 | 19601.96 | 4423 | 1.000 | 0 |
| gt-mixed-lo-c1k-10m | cols_dict | jit | 22767.36 | 24437.46 | 18851.37 | 18952.94 | 3901 | 1.000 | 0 |
| gt-mixed-lo-c1k-10m | native | off | 5142.31 | 5163.62 | 5184.76 | 5216.79 | 1711 | 1.000 | 0 |
| gt-mixed-lo-c1k-10m | native_scalar | off | 5848.32 | 5862.39 | 5944.61 | 5983.90 | 1710 | 1.000 | 0 |
| gt-mixed-lo-c1k-10m | rows_foreach | jit | 22577.83 | 23509.19 | 26226.36 | 26396.04 | 9858 | 1.000 | 0 |
| gt-mixed-lo-c1k-10m | sqlite | off | 51701.60 | 51862.26 | 50977.25 | 52181.04 | 1406 | 1.000 | 0 |
| gt-mixed-lo-c1k-10m | sqlite_idx | off | 89248.79 | 89801.90 | 89607.63 | 89781.86 | 1898 | 1.000 | 0 |
| gt-mixed-lo-c1k-1m | cols | jit | 2987.48 | 3105.71 | 2144.95 | 2194.95 | 467 | 1.000 | 0 |
| gt-mixed-lo-c1k-1m | cols_dict | jit | 2466.71 | 2534.98 | 1934.29 | 2066.08 | 415 | 1.000 | 0 |
| gt-mixed-lo-c1k-1m | native | off | 564.34 | 572.73 | 565.37 | 569.71 | 222 | 1.000 | 0 |
| gt-mixed-lo-c1k-1m | native_scalar | off | 636.51 | 642.23 | 644.80 | 649.91 | 222 | 1.000 | 0 |
| gt-mixed-lo-c1k-1m | rows_foreach | jit | 2853.54 | 3285.52 | 3378.35 | 3441.71 | 1012 | 1.000 | 0 |
| gt-mixed-lo-c1k-1m | sqlite | off | 7331.57 | 7396.62 | 7378.60 | 7639.77 | 162 | 1.000 | 0 |
| gt-mixed-lo-c1k-1m | sqlite_idx | off | 12089.01 | 12509.99 | 12267.67 | 12572.87 | 212 | 1.000 | 0 |
| gti-numeric-lo-c10-100k | cols | jit | 110.21 | 113.64 | 118.50 | 121.68 | 41 | 1.000 | 0 |
| gti-numeric-lo-c10-100k | cols_dict | jit | 97.98 | 99.46 | 104.53 | 105.89 | 41 | 1.000 | 0 |
| gti-numeric-lo-c10-100k | native | off | 45.44 | 48.07 | 47.23 | 47.91 | 33 | 1.000 | 0 |
| gti-numeric-lo-c10-100k | native_scalar | off | 47.91 | 49.20 | 49.84 | 53.60 | 33 | 1.000 | 0 |
| gti-numeric-lo-c10-100k | rows_foreach | jit | 147.96 | 161.23 | 190.34 | 194.03 | 65 | 1.000 | 0 |
| gti-numeric-lo-c10-100k | sqlite | off | 1195.82 | 1202.50 | 1202.15 | 1224.57 | 30 | 1.000 | 0 |
| gti-numeric-lo-c10-100k | sqlite_idx | off | 1882.94 | 1893.98 | 1884.99 | 1904.18 | 32 | 1.000 | 0 |
| gti-numeric-lo-c10-10m | native | off | 2557.45 | 2596.08 | 2584.74 | 2618.37 | 749 | 1.000 | 0 |
| gti-numeric-lo-c10-10m | native_scalar | off | 2811.91 | 2828.95 | 2851.74 | 2888.75 | 749 | 1.000 | 0 |
| gti-numeric-lo-c10-1m | cols | jit | 866.10 | 896.46 | 867.04 | 874.00 | 151 | 1.000 | 0 |
| gti-numeric-lo-c10-1m | cols_dict | jit | 819.62 | 830.02 | 828.12 | 849.16 | 151 | 1.000 | 0 |
| gti-numeric-lo-c10-1m | native | off | 270.33 | 279.35 | 273.88 | 282.76 | 98 | 1.000 | 0 |
| gti-numeric-lo-c10-1m | native_scalar | off | 295.23 | 302.53 | 302.04 | 302.78 | 98 | 1.000 | 0 |
| gti-numeric-lo-c10-1m | rows_foreach | jit | 1230.39 | 1336.81 | 1451.18 | 1460.23 | 404 | 1.000 | 0 |
| gti-numeric-lo-c10-1m | sqlite | off | 5178.83 | 5218.02 | 5223.05 | 5375.86 | 64 | 1.000 | 0 |
| gti-numeric-lo-c10-1m | sqlite_idx | off | 8705.23 | 8857.95 | 8843.24 | 9005.92 | 92 | 1.000 | 0 |
| gti-numeric-lo-c100k-100k | cols | jit | 283.36 | 303.27 | 296.92 | 304.99 | 56 | 1.000 | 0 |
| gti-numeric-lo-c100k-100k | cols_dict | jit | 176.05 | 179.89 | 190.72 | 194.93 | 59 | 1.000 | 0 |
| gti-numeric-lo-c100k-100k | native | off | 101.97 | 105.07 | 122.81 | 126.50 | 65 | 1.000 | 0 |
| gti-numeric-lo-c100k-100k | native_scalar | off | 103.49 | 111.22 | 125.20 | 132.29 | 65 | 1.000 | 0 |
| gti-numeric-lo-c100k-100k | rows_foreach | jit | 357.74 | 376.72 | 416.85 | 432.07 | 82 | 1.000 | 0 |
| gti-numeric-lo-c100k-100k | sqlite | off | 2338.19 | 2375.82 | 2351.06 | 2379.53 | 44 | 1.000 | 0 |
| gti-numeric-lo-c100k-100k | sqlite_idx | off | 2975.48 | 3025.73 | 3010.16 | 3052.60 | 47 | 1.000 | 0 |
| gti-numeric-lo-c100k-10m | native | off | 3050.98 | 3100.00 | 3040.31 | 3102.24 | 803 | 1.000 | 0 |
| gti-numeric-lo-c100k-10m | native_scalar | off | 3287.94 | 3348.33 | 3293.79 | 3300.61 | 803 | 1.000 | 0 |
| gti-numeric-lo-c100k-1m | cols | jit | 1069.24 | 1095.36 | 1090.79 | 1121.70 | 180 | 1.000 | 0 |
| gti-numeric-lo-c100k-1m | cols_dict | jit | 1006.76 | 1028.37 | 1039.24 | 1061.27 | 184 | 1.000 | 0 |
| gti-numeric-lo-c100k-1m | native | off | 367.51 | 380.25 | 399.07 | 412.16 | 151 | 1.000 | 0 |
| gti-numeric-lo-c100k-1m | native_scalar | off | 403.20 | 414.26 | 431.68 | 450.60 | 151 | 1.000 | 0 |
| gti-numeric-lo-c100k-1m | rows_foreach | jit | 1525.42 | 1646.71 | 1824.18 | 1845.09 | 435 | 1.000 | 0 |
| gti-numeric-lo-c100k-1m | sqlite | off | 6325.05 | 6354.34 | 6276.33 | 6382.05 | 89 | 1.000 | 0 |
| gti-numeric-lo-c100k-1m | sqlite_idx | off | 9743.17 | 9942.34 | 9858.68 | 10128.97 | 120 | 1.000 | 0 |
| gti-numeric-lo-c1k-100k | cols | jit | 116.15 | 119.26 | 124.29 | 125.33 | 41 | 1.000 | 0 |
| gti-numeric-lo-c1k-100k | cols_dict | jit | 103.23 | 106.22 | 112.55 | 116.60 | 42 | 1.000 | 0 |
| gti-numeric-lo-c1k-100k | native | off | 47.02 | 47.46 | 49.22 | 53.21 | 33 | 1.000 | 0 |
| gti-numeric-lo-c1k-100k | native_scalar | off | 50.14 | 52.78 | 51.81 | 52.24 | 33 | 1.000 | 0 |
| gti-numeric-lo-c1k-100k | rows_foreach | jit | 158.14 | 170.16 | 197.90 | 211.85 | 65 | 1.000 | 0 |
| gti-numeric-lo-c1k-100k | sqlite | off | 1295.48 | 1304.47 | 1310.90 | 1330.14 | 30 | 1.000 | 0 |
| gti-numeric-lo-c1k-100k | sqlite_idx | off | 1970.76 | 1997.50 | 2001.39 | 2040.73 | 32 | 1.000 | 0 |
| gti-numeric-lo-c1k-10m | native | off | 2629.13 | 2637.99 | 2627.67 | 2647.47 | 750 | 1.000 | 0 |
| gti-numeric-lo-c1k-10m | native_scalar | off | 2894.09 | 2908.02 | 2874.77 | 2876.72 | 750 | 1.000 | 0 |
| gti-numeric-lo-c1k-1m | cols | jit | 865.89 | 882.79 | 879.22 | 893.53 | 151 | 1.000 | 0 |
| gti-numeric-lo-c1k-1m | cols_dict | jit | 841.86 | 852.64 | 855.96 | 860.29 | 151 | 1.000 | 0 |
| gti-numeric-lo-c1k-1m | native | off | 278.75 | 283.21 | 282.72 | 286.21 | 98 | 1.000 | 0 |
| gti-numeric-lo-c1k-1m | native_scalar | off | 304.79 | 315.42 | 308.99 | 319.08 | 98 | 1.000 | 0 |
| gti-numeric-lo-c1k-1m | rows_foreach | jit | 1259.33 | 1358.87 | 1474.70 | 1482.94 | 404 | 1.000 | 0 |
| gti-numeric-lo-c1k-1m | sqlite | off | 5699.82 | 5726.24 | 5680.70 | 5761.23 | 64 | 1.000 | 0 |
| gti-numeric-lo-c1k-1m | sqlite_idx | off | 9321.14 | 9814.43 | 9569.24 | 9677.30 | 94 | 1.000 | 0 |
| rows-mixed-hi-100k | native | off | 18.46 | 18.83 | 20.02 | 20.38 | 23 | 1.000 | 0 |
| rows-mixed-hi-100k | native_scalar | off | 26.28 | 27.09 | 27.36 | 28.03 | 23 | 1.000 | 0 |
| rows-mixed-hi-100k | pdo_duckdb | off | 64.46 | 65.56 | 77.12 | 79.80 | 89 | 0.971 | 0 |
| rows-mixed-hi-100k | pdo_duckdb_1t | off | 65.69 | 71.33 | 76.70 | 80.02 | 88 | 1.000 | 0 |
| rows-mixed-hi-100k | sqlite | off | 353.16 | 358.01 | 355.29 | 364.48 | 38 | 1.000 | 0 |
| rows-mixed-hi-100k | stream | jit | 617.79 | 625.89 | 618.54 | 650.29 | 27 | 1.000 | 0 |
| rows-mixed-hi-100k | stream_hybrid | jit | 141.79 | 147.51 | 156.04 | 157.97 | 28 | 1.000 | 0 |
| rows-mixed-hi-10m | native | off | 1915.59 | 1920.15 | 1939.23 | 1944.36 | 121 | 1.000 | 0 |
| rows-mixed-hi-10m | native_scalar | off | 2659.01 | 2678.64 | 2677.50 | 2683.49 | 121 | 1.000 | 0 |
| rows-mixed-hi-10m | pdo_duckdb | off | 4229.86 | 4261.72 | 4274.06 | 4303.44 | 267 | 0.999 | 0 |
| rows-mixed-hi-10m | pdo_duckdb_1t | off | 4255.12 | 4277.97 | 4306.74 | 4314.49 | 325 | 1.000 | 0 |
| rows-mixed-hi-10m | sqlite | off | 35134.01 | 35286.21 | 35688.19 | 37372.02 | 1504 | 1.000 | 0 |
| rows-mixed-hi-10m | stream | jit | 61723.34 | 62047.95 | 61709.01 | 61778.28 | 122 | 1.000 | 0 |
| rows-mixed-hi-10m | stream_hybrid | jit | 14151.62 | 14251.64 | 14307.93 | 14327.17 | 125 | 1.000 | 0 |
| rows-mixed-hi-1m | native | off | 189.47 | 196.60 | 192.43 | 194.90 | 32 | 1.000 | 0 |
| rows-mixed-hi-1m | native_scalar | off | 265.68 | 269.54 | 265.81 | 271.39 | 32 | 1.000 | 0 |
| rows-mixed-hi-1m | pdo_duckdb | off | 457.03 | 471.47 | 471.57 | 486.89 | 148 | 0.994 | 0 |
| rows-mixed-hi-1m | pdo_duckdb_1t | off | 486.68 | 493.72 | 500.19 | 508.30 | 209 | 1.000 | 0 |
| rows-mixed-hi-1m | sqlite | off | 3512.02 | 3545.12 | 3549.98 | 3665.04 | 170 | 1.000 | 0 |
| rows-mixed-hi-1m | stream | jit | 6083.80 | 6112.00 | 6133.47 | 6163.06 | 36 | 1.000 | 0 |
| rows-mixed-hi-1m | stream_hybrid | jit | 1405.16 | 1417.98 | 1446.21 | 1455.70 | 37 | 1.000 | 0 |
| rows-mixed-lo-100k | native | off | 38.91 | 45.46 | 65.33 | 68.72 | 110 | 1.000 | 0 |
| rows-mixed-lo-100k | native_scalar | off | 43.54 | 49.47 | 72.54 | 72.76 | 110 | 1.000 | 0 |
| rows-mixed-lo-100k | pdo_duckdb | off | 128.72 | 131.03 | 167.48 | 170.57 | 188 | 0.983 | 0 |
| rows-mixed-lo-100k | pdo_duckdb_1t | off | 128.26 | 132.12 | 167.92 | 174.74 | 187 | 1.000 | 0 |
| rows-mixed-lo-100k | sqlite | off | 428.64 | 441.82 | 490.40 | 496.76 | 127 | 1.000 | 0 |
| rows-mixed-lo-100k | stream | jit | 618.22 | 627.97 | 680.06 | 697.38 | 111 | 1.000 | 0 |
| rows-mixed-lo-100k | stream_hybrid | jit | 159.28 | 165.86 | 222.42 | 226.58 | 114 | 1.000 | 0 |
| rows-mixed-lo-10m | native | off | 3912.48 | 4225.88 | 7147.19 | 7198.43 | 8731 | 1.000 | 0 |
| rows-mixed-lo-10m | native_scalar | off | 4754.02 | 5111.78 | 7932.38 | 7969.61 | 8731 | 1.000 | 0 |
| rows-mixed-lo-10m | stream | jit | 63827.91 | 64167.02 | 66721.83 | 68129.40 | 8400 | 1.000 | 0 |
| rows-mixed-lo-10m | stream_hybrid | jit | 16499.01 | 16789.02 | 20162.45 | 20330.63 | 8694 | 1.000 | 0 |
| rows-mixed-lo-1m | native | off | 356.85 | 413.04 | 654.54 | 664.85 | 893 | 1.000 | 0 |
| rows-mixed-lo-1m | native_scalar | off | 431.43 | 494.60 | 729.05 | 736.24 | 893 | 1.000 | 0 |
| rows-mixed-lo-1m | pdo_duckdb | off | 1072.24 | 1106.09 | 1359.40 | 1381.45 | 1129 | 0.996 | 0 |
| rows-mixed-lo-1m | pdo_duckdb_1t | off | 1077.93 | 1118.91 | 1383.62 | 1389.57 | 1171 | 1.000 | 0 |
| rows-mixed-lo-1m | sqlite | off | 4532.85 | 4651.30 | 6315.77 | 6408.90 | 1062 | 1.000 | 0 |
| rows-mixed-lo-1m | stream | jit | 6517.32 | 6545.51 | 6593.71 | 6851.98 | 864 | 1.000 | 0 |
| rows-mixed-lo-1m | stream_hybrid | jit | 1622.50 | 1666.36 | 1970.57 | 1995.56 | 894 | 1.000 | 0 |
| rows-numeric-hi-100k | native | off | 8.80 | 9.11 | 9.69 | 9.81 | 23 | 1.000 | 0 |
| rows-numeric-hi-100k | native_scalar | off | 11.42 | 11.63 | 12.30 | 12.41 | 23 | 1.000 | 0 |
| rows-numeric-hi-100k | pdo_duckdb | off | 43.72 | 45.79 | 57.19 | 57.84 | 80 | 0.967 | 0 |
| rows-numeric-hi-100k | pdo_duckdb_1t | off | 44.63 | 46.85 | 57.39 | 59.62 | 79 | 1.001 | 0 |
| rows-numeric-hi-100k | sqlite | off | 267.04 | 273.88 | 262.76 | 269.69 | 28 | 1.000 | 0 |
| rows-numeric-hi-100k | stream | jit | 239.69 | 244.01 | 249.14 | 252.39 | 27 | 1.000 | 0 |
| rows-numeric-hi-100k | stream_fast | jit | 62.31 | 63.08 | 64.36 | 102.84 | 27 | 1.000 | 0 |
| rows-numeric-hi-10m | native | off | 944.87 | 949.47 | 959.72 | 962.67 | 60 | 1.000 | 0 |
| rows-numeric-hi-10m | native_scalar | off | 1195.76 | 1206.53 | 1205.18 | 1221.42 | 60 | 1.000 | 0 |
| rows-numeric-hi-10m | stream | jit | 25097.14 | 25294.39 | 25046.70 | 25186.13 | 64 | 1.000 | 0 |
| rows-numeric-hi-10m | stream_fast | jit | 6257.66 | 6277.69 | 6254.06 | 6392.39 | 64 | 1.000 | 0 |
| rows-numeric-hi-1m | native | off | 93.68 | 95.21 | 96.20 | 98.70 | 26 | 1.000 | 0 |
| rows-numeric-hi-1m | native_scalar | off | 120.87 | 132.65 | 120.97 | 123.42 | 26 | 1.000 | 0 |
| rows-numeric-hi-1m | pdo_duckdb | off | 291.55 | 294.88 | 303.30 | 307.49 | 123 | 0.991 | 0 |
| rows-numeric-hi-1m | pdo_duckdb_1t | off | 293.39 | 297.54 | 307.92 | 332.52 | 120 | 1.000 | 0 |
| rows-numeric-hi-1m | sqlite | off | 2675.46 | 2682.31 | 2662.44 | 2679.78 | 66 | 1.000 | 0 |
| rows-numeric-hi-1m | stream | jit | 2472.12 | 2534.22 | 2482.93 | 2577.00 | 30 | 1.000 | 0 |
| rows-numeric-hi-1m | stream_fast | jit | 616.87 | 630.06 | 625.63 | 632.30 | 30 | 1.000 | 0 |
| rows-numeric-lo-100k | native | off | 16.11 | 16.52 | 28.17 | 28.82 | 56 | 1.000 | 0 |
| rows-numeric-lo-100k | native_scalar | off | 17.65 | 17.95 | 30.51 | 30.85 | 56 | 1.000 | 0 |
| rows-numeric-lo-100k | pdo_duckdb | off | 72.99 | 74.29 | 96.30 | 96.83 | 118 | 0.978 | 0 |
| rows-numeric-lo-100k | pdo_duckdb_1t | off | 74.74 | 77.31 | 97.37 | 99.44 | 117 | 1.000 | 0 |
| rows-numeric-lo-100k | sqlite | off | 306.04 | 313.27 | 323.25 | 330.03 | 60 | 1.000 | 0 |
| rows-numeric-lo-100k | stream | jit | 253.58 | 255.59 | 274.41 | 283.51 | 60 | 1.000 | 0 |
| rows-numeric-lo-100k | stream_fast | jit | 73.73 | 80.51 | 92.10 | 94.20 | 60 | 1.000 | 0 |
| rows-numeric-lo-10m | native | off | 1762.86 | 1801.16 | 2919.07 | 2937.40 | 3326 | 1.001 | 0 |
| rows-numeric-lo-10m | native_scalar | off | 2021.24 | 2039.84 | 3177.13 | 3210.06 | 3326 | 1.000 | 0 |
| rows-numeric-lo-10m | pdo_duckdb | off | 5484.95 | 5543.11 | 6656.11 | 6719.34 | 4059 | 0.999 | 0 |
| rows-numeric-lo-10m | pdo_duckdb_1t | off | 5469.63 | 5521.48 | 6696.33 | 6717.83 | 4053 | 1.000 | 0 |
| rows-numeric-lo-10m | sqlite | off | 31605.23 | 31732.32 | 32666.74 | 32773.27 | 3723 | 1.000 | 0 |
| rows-numeric-lo-10m | stream | jit | 26898.52 | 27082.66 | 27749.92 | 28233.40 | 3331 | 1.000 | 0 |
| rows-numeric-lo-10m | stream_fast | jit | 7390.84 | 7431.80 | 8737.69 | 8779.25 | 3330 | 1.000 | 0 |
| rows-numeric-lo-1m | native | off | 169.31 | 174.60 | 281.57 | 301.41 | 353 | 1.000 | 0 |
| rows-numeric-lo-1m | native_scalar | off | 193.26 | 199.26 | 304.35 | 328.63 | 353 | 1.000 | 0 |
| rows-numeric-lo-1m | pdo_duckdb | off | 563.92 | 571.09 | 689.77 | 704.46 | 493 | 0.996 | 0 |
| rows-numeric-lo-1m | pdo_duckdb_1t | off | 582.00 | 589.18 | 690.31 | 699.56 | 490 | 1.000 | 0 |
| rows-numeric-lo-1m | sqlite | off | 3173.17 | 3208.11 | 3252.94 | 3381.57 | 392 | 1.000 | 0 |
| rows-numeric-lo-1m | stream | jit | 2591.67 | 2622.39 | 2740.83 | 2815.41 | 357 | 1.000 | 0 |
| rows-numeric-lo-1m | stream_fast | jit | 732.29 | 742.45 | 873.43 | 885.61 | 357 | 1.000 | 0 |
| rows-wide-hi-100k | native | off | 48.52 | 49.47 | 51.22 | 52.15 | 24 | 1.000 | 0 |
| rows-wide-hi-100k | native_scalar | off | 64.70 | 67.95 | 66.15 | 67.76 | 24 | 1.000 | 0 |
| rows-wide-hi-100k | pdo_duckdb | off | 155.27 | 156.59 | 168.11 | 170.69 | 104 | 0.985 | 0 |
| rows-wide-hi-100k | pdo_duckdb_1t | off | 159.02 | 161.51 | 170.83 | 274.25 | 102 | 1.000 | 0 |
| rows-wide-hi-100k | sqlite | off | 712.85 | 724.77 | 729.34 | 738.42 | 48 | 1.000 | 0 |
| rows-wide-hi-100k | stream | jit | 1412.14 | 1427.56 | 1526.65 | 1570.08 | 31 | 1.000 | 0 |
| rows-wide-hi-100k | stream_fast | jit | 253.70 | 262.90 | 369.04 | 374.72 | 31 | 1.000 | 0 |
| rows-wide-hi-10m | native | off | 4983.49 | 5001.21 | 5023.67 | 5047.53 | 193 | 1.000 | 0 |
| rows-wide-hi-10m | native_scalar | off | 6522.91 | 6534.65 | 6569.21 | 6574.58 | 193 | 1.000 | 0 |
| rows-wide-hi-10m | stream | jit | 141591.15 | 141869.55 | 141607.93 | 141802.97 | 193 | 1.000 | 0 |
| rows-wide-hi-10m | stream_fast | jit | 25332.49 | 25436.46 | 25774.81 | 25845.97 | 200 | 1.000 | 0 |
| rows-wide-hi-1m | native | off | 495.66 | 505.61 | 502.70 | 506.25 | 40 | 1.000 | 0 |
| rows-wide-hi-1m | native_scalar | off | 650.35 | 657.95 | 656.89 | 661.40 | 40 | 1.000 | 0 |
| rows-wide-hi-1m | pdo_duckdb | off | 1291.58 | 1311.79 | 1312.18 | 1328.89 | 188 | 0.997 | 0 |
| rows-wide-hi-1m | pdo_duckdb_1t | off | 1326.52 | 1349.39 | 1321.85 | 1346.82 | 217 | 1.000 | 0 |
| rows-wide-hi-1m | sqlite | off | 7176.55 | 7224.55 | 7224.30 | 7278.91 | 269 | 1.000 | 0 |
| rows-wide-hi-1m | stream | jit | 14229.22 | 14312.87 | 14317.92 | 14463.08 | 45 | 1.000 | 0 |
| rows-wide-hi-1m | stream_fast | jit | 2545.73 | 2557.19 | 2679.85 | 2694.75 | 46 | 1.000 | 0 |
| rows-wide-lo-100k | native | off | 82.48 | 95.55 | 132.13 | 135.05 | 174 | 1.000 | 0 |
| rows-wide-lo-100k | native_scalar | off | 99.80 | 110.76 | 148.23 | 150.30 | 174 | 1.000 | 0 |
| rows-wide-lo-100k | pdo_duckdb | off | 282.68 | 290.67 | 341.25 | 344.60 | 277 | 0.991 | 0 |
| rows-wide-lo-100k | pdo_duckdb_1t | off | 281.12 | 289.30 | 337.76 | 344.77 | 275 | 1.000 | 0 |
| rows-wide-lo-100k | sqlite | off | 947.64 | 969.25 | 1070.91 | 1133.22 | 201 | 1.000 | 0 |
| rows-wide-lo-100k | stream | jit | 1463.29 | 1480.93 | 1641.99 | 1658.09 | 174 | 1.000 | 0 |
| rows-wide-lo-100k | stream_fast | jit | 310.95 | 334.23 | 491.03 | 497.87 | 181 | 1.000 | 0 |
| rows-wide-lo-10m | native | off | 8972.45 | 9641.14 | 15744.54 | 15823.33 | 15250 | 1.000 | 0 |
| rows-wide-lo-10m | native_scalar | off | 10519.83 | 11205.37 | 17128.71 | 17221.98 | 15250 | 1.000 | 0 |
| rows-wide-lo-10m | stream | jit | 146370.03 | 147045.33 | 153722.58 | 155717.46 | 14638 | 1.000 | 0 |
| rows-wide-lo-10m | stream_fast | jit | 31364.95 | 32027.23 | 38866.28 | 39593.04 | 15257 | 1.000 | 0 |
| rows-wide-lo-1m | native | off | 824.22 | 969.22 | 1348.55 | 1364.68 | 1545 | 1.000 | 0 |
| rows-wide-lo-1m | native_scalar | off | 983.69 | 1130.08 | 1492.63 | 1531.11 | 1545 | 1.000 | 0 |
| rows-wide-lo-1m | pdo_duckdb | off | 2463.73 | 2576.30 | 3033.52 | 3054.21 | 1897 | 0.998 | 0 |
| rows-wide-lo-1m | pdo_duckdb_1t | off | 2505.25 | 2628.18 | 3045.13 | 3065.38 | 1925 | 1.000 | 0 |
| rows-wide-lo-1m | sqlite | off | 9767.72 | 10041.77 | 13259.70 | 13360.58 | 1806 | 1.000 | 0 |
| rows-wide-lo-1m | stream | jit | 14612.19 | 14704.57 | 15403.51 | 15867.44 | 1489 | 1.000 | 0 |
| rows-wide-lo-1m | stream_fast | jit | 3080.93 | 3144.83 | 3809.73 | 3861.44 | 1551 | 1.000 | 0 |
| scan-mixed-hi-100k | cols | jit | 197.04 | 204.91 | 187.03 | 193.75 | 71 | 1.000 | 0 |
| scan-mixed-hi-100k | duckdb | na |  |  | 59.61 | 60.99 | 56 | 0.799 | 0 |
| scan-mixed-hi-100k | duckdb_1t | na |  |  | 65.99 | 80.31 | 54 | 1.003 | 0 |
| scan-mixed-hi-100k | duckdb_forced | na |  |  | 71.01 | 72.44 | 57 | 0.765 | 0 |
| scan-mixed-hi-100k | duckdb_forced_1t | na |  |  | 84.29 | 86.88 | 55 | 1.003 | 0 |
| scan-mixed-hi-100k | native | off | 7.24 | 7.30 | 8.42 | 8.82 | 23 | 1.000 | 0 |
| scan-mixed-hi-100k | native_scalar | off | 14.29 | 14.44 | 15.92 | 16.01 | 23 | 1.000 | 0 |
| scan-mixed-hi-100k | pdo_duckdb | off | 38.93 | 39.88 | 53.72 | 54.35 | 89 | 0.789 | 0 |
| scan-mixed-hi-100k | pdo_duckdb_1t | off | 47.03 | 50.75 | 61.48 | 63.07 | 87 | 1.001 | 0 |
| scan-mixed-hi-100k | rows | jit | 189.19 | 203.97 | 232.13 | 237.20 | 126 | 1.000 | 0 |
| scan-mixed-hi-100k | rows_foreach | jit | 179.01 | 191.75 | 231.67 | 266.42 | 125 | 1.000 | 0 |
| scan-mixed-hi-100k | sqlite | off | 219.14 | 221.70 | 224.03 | 233.26 | 26 | 1.000 | 0 |
| scan-mixed-hi-100k | sqlite_idx | off | 280.62 | 286.00 | 285.83 | 291.30 | 30 | 1.000 | 0 |
| scan-mixed-hi-100k | stream | jit | 578.45 | 597.76 | 581.76 | 594.79 | 27 | 1.000 | 0 |
| scan-mixed-hi-100k | stream_hybrid | jit | 112.15 | 114.79 | 114.11 | 116.52 | 27 | 1.000 | 0 |
| scan-mixed-hi-10m | duckdb | na |  |  | 893.85 | 909.94 | 350 | 0.362 | 0 |
| scan-mixed-hi-10m | duckdb_1t | na |  |  | 2381.25 | 2411.43 | 195 | 1.000 | 0 |
| scan-mixed-hi-10m | native | off | 806.76 | 809.49 | 802.93 | 805.35 | 23 | 1.000 | 0 |
| scan-mixed-hi-10m | native_scalar | off | 1545.66 | 1557.58 | 1548.79 | 1557.94 | 23 | 1.000 | 0 |
| scan-mixed-hi-10m | stream | jit | 58221.12 | 58488.87 | 58689.55 | 58770.51 | 27 | 1.000 | 0 |
| scan-mixed-hi-10m | stream_hybrid | jit | 11365.94 | 11416.89 | 11342.53 | 11384.06 | 27 | 1.000 | 0 |
| scan-mixed-hi-1m | cols | jit | 2123.39 | 2150.83 | 1790.68 | 1803.37 | 467 | 1.000 | 0 |
| scan-mixed-hi-1m | duckdb | na |  |  | 149.94 | 167.53 | 136 | 0.479 | 0 |
| scan-mixed-hi-1m | duckdb_1t | na |  |  | 313.85 | 318.03 | 166 | 1.001 | 0 |
| scan-mixed-hi-1m | duckdb_forced | na |  |  | 194.50 | 219.65 | 139 | 0.389 | 0 |
| scan-mixed-hi-1m | duckdb_forced_1t | na |  |  | 481.77 | 489.60 | 167 | 1.001 | 0 |
| scan-mixed-hi-1m | native | off | 78.22 | 80.78 | 79.50 | 82.62 | 23 | 1.000 | 0 |
| scan-mixed-hi-1m | native_scalar | off | 155.10 | 159.09 | 153.13 | 154.22 | 23 | 1.000 | 0 |
| scan-mixed-hi-1m | pdo_duckdb | off | 138.78 | 146.58 | 147.49 | 150.88 | 169 | 0.436 | 0 |
| scan-mixed-hi-1m | pdo_duckdb_1t | off | 311.78 | 316.16 | 324.66 | 327.44 | 198 | 1.000 | 0 |
| scan-mixed-hi-1m | rows | jit | 2121.50 | 2298.24 | 2248.18 | 2280.23 | 1012 | 1.000 | 0 |
| scan-mixed-hi-1m | rows_foreach | jit | 1868.73 | 1978.71 | 2200.65 | 2222.08 | 1010 | 1.000 | 0 |
| scan-mixed-hi-1m | sqlite | off | 2237.35 | 2255.00 | 2260.69 | 2304.71 | 45 | 1.000 | 0 |
| scan-mixed-hi-1m | sqlite_idx | off | 3011.48 | 3029.57 | 3021.84 | 3064.22 | 70 | 1.000 | 0 |
| scan-mixed-hi-1m | stream | jit | 5785.53 | 5800.79 | 5819.21 | 5885.59 | 27 | 1.000 | 0 |
| scan-mixed-hi-1m | stream_hybrid | jit | 1200.51 | 1209.30 | 1138.38 | 1224.45 | 27 | 1.000 | 0 |
| scan-mixed-lo-100k | cols | jit | 186.96 | 203.79 | 186.18 | 192.69 | 71 | 1.000 | 0 |
| scan-mixed-lo-100k | duckdb | na |  |  | 59.57 | 61.28 | 56 | 0.797 | 0 |
| scan-mixed-lo-100k | duckdb_1t | na |  |  | 66.93 | 67.44 | 54 | 1.003 | 0 |
| scan-mixed-lo-100k | duckdb_forced | na |  |  | 70.68 | 71.51 | 57 | 0.766 | 0 |
| scan-mixed-lo-100k | duckdb_forced_1t | na |  |  | 85.63 | 87.21 | 55 | 1.003 | 0 |
| scan-mixed-lo-100k | native | off | 7.53 | 7.66 | 8.82 | 9.84 | 23 | 1.000 | 1 |
| scan-mixed-lo-100k | native_scalar | off | 14.73 | 15.22 | 16.34 | 16.50 | 23 | 1.000 | 0 |
| scan-mixed-lo-100k | pdo_duckdb | off | 38.63 | 40.72 | 53.87 | 54.86 | 89 | 0.785 | 0 |
| scan-mixed-lo-100k | pdo_duckdb_1t | off | 45.82 | 49.67 | 62.67 | 63.59 | 87 | 1.001 | 0 |
| scan-mixed-lo-100k | rows | jit | 268.55 | 301.46 | 295.91 | 327.75 | 130 | 1.000 | 0 |
| scan-mixed-lo-100k | rows_foreach | jit | 176.03 | 192.83 | 231.90 | 234.63 | 125 | 1.000 | 0 |
| scan-mixed-lo-100k | sqlite | off | 230.78 | 234.28 | 227.70 | 232.92 | 26 | 1.000 | 0 |
| scan-mixed-lo-100k | sqlite_idx | off | 294.66 | 299.85 | 293.35 | 299.45 | 30 | 1.000 | 0 |
| scan-mixed-lo-100k | stream | jit | 576.61 | 584.73 | 581.38 | 587.70 | 27 | 1.000 | 0 |
| scan-mixed-lo-100k | stream_hybrid | jit | 111.21 | 113.81 | 119.68 | 121.52 | 27 | 1.000 | 0 |
| scan-mixed-lo-10m | cols | jit | 21193.53 | 22217.12 | 18206.34 | 18215.38 | 4422 | 1.000 | 0 |
| scan-mixed-lo-10m | duckdb | na |  |  | 907.57 | 919.17 | 380 | 0.365 | 0 |
| scan-mixed-lo-10m | duckdb_1t | na |  |  | 2379.89 | 2380.68 | 195 | 1.000 | 0 |
| scan-mixed-lo-10m | duckdb_forced | na |  |  | 1318.94 | 1329.37 | 353 | 0.314 | 0 |
| scan-mixed-lo-10m | duckdb_forced_1t | na |  |  | 4091.27 | 4093.62 | 196 | 1.000 | 0 |
| scan-mixed-lo-10m | native | off | 847.74 | 849.10 | 839.40 | 844.46 | 23 | 1.000 | 0 |
| scan-mixed-lo-10m | native_scalar | off | 1579.89 | 1583.81 | 1577.78 | 1585.80 | 23 | 1.000 | 0 |
| scan-mixed-lo-10m | pdo_duckdb | off | 915.56 | 938.82 | 898.46 | 934.83 | 291 | 0.349 | 0 |
| scan-mixed-lo-10m | pdo_duckdb_1t | off | 2512.34 | 2525.86 | 2543.24 | 2562.13 | 228 | 1.000 | 0 |
| scan-mixed-lo-10m | rows_foreach | jit | 18958.44 | 19606.69 | 22982.26 | 23122.62 | 9857 | 1.000 | 0 |
| scan-mixed-lo-10m | sqlite | off | 23115.09 | 23268.76 | 23583.52 | 23714.94 | 247 | 1.000 | 0 |
| scan-mixed-lo-10m | sqlite_idx | off | 32138.61 | 32229.68 | 31936.91 | 32005.62 | 476 | 1.000 | 0 |
| scan-mixed-lo-10m | stream | jit | 58256.60 | 58646.32 | 58838.06 | 60201.18 | 27 | 1.000 | 0 |
| scan-mixed-lo-10m | stream_hybrid | jit | 11349.17 | 11388.76 | 11457.88 | 11949.54 | 27 | 1.000 | 0 |
| scan-mixed-lo-1m | cols | jit | 2119.00 | 2154.41 | 1787.37 | 1793.34 | 466 | 1.000 | 0 |
| scan-mixed-lo-1m | duckdb | na |  |  | 151.01 | 160.13 | 136 | 0.476 | 0 |
| scan-mixed-lo-1m | duckdb_1t | na |  |  | 317.52 | 322.16 | 166 | 1.001 | 0 |
| scan-mixed-lo-1m | duckdb_forced | na |  |  | 203.03 | 205.89 | 169 | 0.392 | 0 |
| scan-mixed-lo-1m | duckdb_forced_1t | na |  |  | 487.62 | 499.97 | 166 | 1.001 | 0 |
| scan-mixed-lo-1m | native | off | 83.01 | 84.34 | 83.87 | 86.01 | 23 | 1.000 | 0 |
| scan-mixed-lo-1m | native_scalar | off | 157.50 | 162.34 | 157.19 | 159.35 | 23 | 1.000 | 0 |
| scan-mixed-lo-1m | pdo_duckdb | off | 134.97 | 149.23 | 147.93 | 155.34 | 169 | 0.433 | 0 |
| scan-mixed-lo-1m | pdo_duckdb_1t | off | 316.28 | 322.36 | 325.70 | 330.90 | 198 | 1.000 | 0 |
| scan-mixed-lo-1m | rows | jit | 4030.38 | 5099.88 | 3269.33 | 3317.18 | 1044 | 1.000 | 0 |
| scan-mixed-lo-1m | rows_foreach | jit | 1873.07 | 2036.22 | 2220.94 | 2257.14 | 1010 | 1.000 | 0 |
| scan-mixed-lo-1m | sqlite | off | 2316.30 | 2322.74 | 2304.52 | 2339.56 | 45 | 1.000 | 0 |
| scan-mixed-lo-1m | sqlite_idx | off | 3092.71 | 3116.41 | 3111.63 | 3192.38 | 70 | 1.000 | 0 |
| scan-mixed-lo-1m | stream | jit | 5782.44 | 5819.55 | 5818.47 | 6249.83 | 27 | 1.000 | 0 |
| scan-mixed-lo-1m | stream_hybrid | jit | 1138.28 | 1151.45 | 1141.56 | 1159.48 | 27 | 1.000 | 0 |
| scan-mixnq-hi-100k | cols | jit | 105.44 | 110.52 | 104.76 | 105.45 | 71 | 1.000 | 0 |
| scan-mixnq-hi-100k | duckdb | na |  |  | 58.72 | 64.22 | 55 | 0.800 | 0 |
| scan-mixnq-hi-100k | duckdb_1t | na |  |  | 65.03 | 67.19 | 54 | 1.003 | 0 |
| scan-mixnq-hi-100k | duckdb_forced | na |  |  | 69.44 | 71.10 | 56 | 0.772 | 0 |
| scan-mixnq-hi-100k | duckdb_forced_1t | na |  |  | 80.74 | 82.81 | 55 | 1.003 | 0 |
| scan-mixnq-hi-100k | native | off | 6.68 | 6.70 | 7.89 | 7.98 | 23 | 1.000 | 0 |
| scan-mixnq-hi-100k | native_scalar | off | 13.88 | 14.28 | 15.35 | 15.86 | 23 | 1.000 | 0 |
| scan-mixnq-hi-100k | pdo_duckdb | off | 38.68 | 40.59 | 53.44 | 54.06 | 88 | 0.791 | 0 |
| scan-mixnq-hi-100k | pdo_duckdb_1t | off | 46.18 | 49.95 | 60.86 | 61.75 | 86 | 1.001 | 0 |
| scan-mixnq-hi-100k | rows | jit | 110.77 | 120.01 | 151.55 | 154.32 | 125 | 1.000 | 0 |
| scan-mixnq-hi-100k | rows_foreach | jit | 101.61 | 113.21 | 148.11 | 151.20 | 126 | 1.000 | 0 |
| scan-mixnq-hi-100k | sqlite | off | 151.22 | 153.82 | 150.63 | 155.30 | 26 | 1.000 | 0 |
| scan-mixnq-hi-100k | sqlite_idx | off | 202.68 | 208.62 | 213.25 | 226.84 | 30 | 1.000 | 0 |
| scan-mixnq-hi-100k | stream | jit | 574.16 | 582.98 | 576.00 | 596.39 | 27 | 1.000 | 0 |
| scan-mixnq-hi-100k | stream_fast | jit | 40.67 | 41.04 | 43.32 | 44.58 | 27 | 1.000 | 0 |
| scan-mixnq-hi-10m | duckdb | na |  |  | 862.60 | 878.09 | 289 | 0.364 | 0 |
| scan-mixnq-hi-10m | duckdb_1t | na |  |  | 2330.29 | 2337.24 | 195 | 1.000 | 0 |
| scan-mixnq-hi-10m | native | off | 767.96 | 774.16 | 768.72 | 771.91 | 23 | 1.000 | 0 |
| scan-mixnq-hi-10m | native_scalar | off | 1506.27 | 1518.53 | 1502.30 | 1502.40 | 22 | 1.000 | 0 |
| scan-mixnq-hi-10m | stream | jit | 60666.54 | 60736.86 | 58388.92 | 60878.83 | 27 | 1.000 | 0 |
| scan-mixnq-hi-10m | stream_fast | jit | 4210.75 | 4235.48 | 4224.34 | 4232.85 | 27 | 1.000 | 0 |
| scan-mixnq-hi-1m | cols | jit | 1351.11 | 1382.67 | 1028.79 | 1041.78 | 470 | 1.000 | 0 |
| scan-mixnq-hi-1m | duckdb | na |  |  | 148.25 | 167.82 | 136 | 0.484 | 0 |
| scan-mixnq-hi-1m | duckdb_1t | na |  |  | 304.34 | 310.24 | 164 | 1.001 | 0 |
| scan-mixnq-hi-1m | duckdb_forced | na |  |  | 188.24 | 208.80 | 139 | 0.390 | 0 |
| scan-mixnq-hi-1m | duckdb_forced_1t | na |  |  | 461.87 | 468.47 | 165 | 1.001 | 0 |
| scan-mixnq-hi-1m | native | off | 75.70 | 76.99 | 77.15 | 77.82 | 23 | 1.000 | 0 |
| scan-mixnq-hi-1m | native_scalar | off | 150.42 | 151.97 | 149.04 | 153.84 | 23 | 1.000 | 0 |
| scan-mixnq-hi-1m | pdo_duckdb | off | 132.98 | 145.15 | 144.99 | 155.61 | 169 | 0.428 | 0 |
| scan-mixnq-hi-1m | pdo_duckdb_1t | off | 300.19 | 311.31 | 313.64 | 317.26 | 196 | 1.000 | 0 |
| scan-mixnq-hi-1m | rows | jit | 1365.60 | 1504.41 | 1508.23 | 1529.54 | 1015 | 1.000 | 0 |
| scan-mixnq-hi-1m | rows_foreach | jit | 1116.18 | 1222.38 | 1453.42 | 1471.35 | 1013 | 1.000 | 0 |
| scan-mixnq-hi-1m | sqlite | off | 1537.19 | 1546.04 | 1534.43 | 1557.65 | 45 | 1.000 | 0 |
| scan-mixnq-hi-1m | sqlite_idx | off | 2320.86 | 2340.64 | 2319.24 | 2345.67 | 70 | 1.000 | 0 |
| scan-mixnq-hi-1m | stream | jit | 5777.44 | 5788.89 | 5830.93 | 5870.66 | 27 | 1.000 | 0 |
| scan-mixnq-hi-1m | stream_fast | jit | 417.84 | 422.02 | 418.82 | 432.75 | 27 | 1.000 | 0 |
| scan-mixnq-lo-100k | cols | jit | 110.95 | 120.48 | 107.07 | 108.49 | 71 | 1.000 | 0 |
| scan-mixnq-lo-100k | duckdb | na |  |  | 59.36 | 60.93 | 55 | 0.801 | 0 |
| scan-mixnq-lo-100k | duckdb_1t | na |  |  | 65.88 | 70.50 | 54 | 1.004 | 0 |
| scan-mixnq-lo-100k | duckdb_forced | na |  |  | 69.57 | 71.57 | 56 | 0.771 | 0 |
| scan-mixnq-lo-100k | duckdb_forced_1t | na |  |  | 82.31 | 83.57 | 55 | 1.003 | 0 |
| scan-mixnq-lo-100k | native | off | 7.15 | 7.28 | 8.33 | 8.72 | 23 | 1.000 | 0 |
| scan-mixnq-lo-100k | native_scalar | off | 14.74 | 15.28 | 15.74 | 16.30 | 23 | 1.000 | 1 |
| scan-mixnq-lo-100k | pdo_duckdb | off | 38.96 | 40.25 | 53.82 | 55.29 | 89 | 0.790 | 0 |
| scan-mixnq-lo-100k | pdo_duckdb_1t | off | 47.60 | 49.85 | 61.41 | 62.53 | 86 | 1.001 | 0 |
| scan-mixnq-lo-100k | rows | jit | 208.68 | 228.46 | 215.66 | 220.28 | 130 | 1.000 | 0 |
| scan-mixnq-lo-100k | rows_foreach | jit | 106.16 | 119.92 | 152.52 | 155.80 | 125 | 1.000 | 0 |
| scan-mixnq-lo-100k | sqlite | off | 158.87 | 161.78 | 158.59 | 165.87 | 26 | 1.000 | 0 |
| scan-mixnq-lo-100k | sqlite_idx | off | 218.97 | 222.64 | 224.25 | 228.23 | 30 | 1.000 | 0 |
| scan-mixnq-lo-100k | stream | jit | 581.46 | 613.98 | 582.91 | 597.78 | 27 | 1.000 | 0 |
| scan-mixnq-lo-100k | stream_fast | jit | 41.34 | 43.01 | 45.12 | 46.67 | 27 | 1.000 | 0 |
| scan-mixnq-lo-10m | duckdb | na |  |  | 855.49 | 856.17 | 257 | 0.348 | 0 |
| scan-mixnq-lo-10m | duckdb_1t | na |  |  | 2346.76 | 2350.48 | 195 | 1.000 | 0 |
| scan-mixnq-lo-10m | native | off | 818.20 | 821.28 | 815.35 | 821.76 | 23 | 1.000 | 0 |
| scan-mixnq-lo-10m | native_scalar | off | 1538.88 | 1547.55 | 1540.18 | 1541.98 | 23 | 1.000 | 0 |
| scan-mixnq-lo-10m | stream | jit | 58708.23 | 59013.88 | 58590.46 | 58789.67 | 27 | 1.000 | 0 |
| scan-mixnq-lo-10m | stream_fast | jit | 4315.78 | 4333.45 | 4244.37 | 4261.12 | 27 | 1.000 | 0 |
| scan-mixnq-lo-1m | cols | jit | 1367.28 | 1404.52 | 1037.46 | 1052.06 | 470 | 1.000 | 0 |
| scan-mixnq-lo-1m | duckdb | na |  |  | 149.51 | 161.16 | 136 | 0.478 | 0 |
| scan-mixnq-lo-1m | duckdb_1t | na |  |  | 309.01 | 336.48 | 164 | 1.001 | 0 |
| scan-mixnq-lo-1m | duckdb_forced | na |  |  | 198.44 | 200.44 | 168 | 0.401 | 0 |
| scan-mixnq-lo-1m | duckdb_forced_1t | na |  |  | 467.04 | 477.85 | 165 | 1.001 | 0 |
| scan-mixnq-lo-1m | native | off | 81.47 | 83.26 | 81.57 | 82.86 | 23 | 1.000 | 0 |
| scan-mixnq-lo-1m | native_scalar | off | 155.37 | 156.34 | 154.41 | 160.00 | 23 | 1.000 | 0 |
| scan-mixnq-lo-1m | pdo_duckdb | off | 131.29 | 142.69 | 145.76 | 160.67 | 169 | 0.425 | 0 |
| scan-mixnq-lo-1m | pdo_duckdb_1t | off | 306.37 | 308.94 | 319.55 | 324.94 | 196 | 1.000 | 0 |
| scan-mixnq-lo-1m | rows | jit | 3281.16 | 4290.34 | 2513.08 | 2566.44 | 1052 | 1.000 | 0 |
| scan-mixnq-lo-1m | rows_foreach | jit | 1130.94 | 1231.34 | 1473.50 | 1531.66 | 1014 | 1.000 | 0 |
| scan-mixnq-lo-1m | sqlite | off | 1554.55 | 1561.07 | 1591.88 | 1616.09 | 45 | 1.000 | 0 |
| scan-mixnq-lo-1m | sqlite_idx | off | 2371.83 | 2389.60 | 2360.24 | 2409.35 | 70 | 1.000 | 0 |
| scan-mixnq-lo-1m | stream | jit | 5784.49 | 5813.49 | 5820.03 | 5998.06 | 27 | 1.000 | 0 |
| scan-mixnq-lo-1m | stream_fast | jit | 428.00 | 434.53 | 430.53 | 466.53 | 27 | 1.000 | 0 |
| scan-numeric-hi-100k | cols | jit | 76.21 | 77.74 | 81.93 | 84.38 | 41 | 1.000 | 0 |
| scan-numeric-hi-100k | duckdb | na |  |  | 56.90 | 58.11 | 47 | 0.889 | 0 |
| scan-numeric-hi-100k | duckdb_1t | na |  |  | 56.27 | 57.88 | 46 | 1.005 | 0 |
| scan-numeric-hi-100k | duckdb_forced | na |  |  | 65.15 | 66.67 | 48 | 0.900 | 0 |
| scan-numeric-hi-100k | duckdb_forced_1t | na |  |  | 64.35 | 65.58 | 47 | 1.004 | 0 |
| scan-numeric-hi-100k | native | off | 4.72 | 4.83 | 5.26 | 5.37 | 23 | 1.000 | 1 |
| scan-numeric-hi-100k | native_scalar | off | 7.32 | 7.53 | 7.90 | 8.05 | 23 | 1.000 | 0 |
| scan-numeric-hi-100k | pdo_duckdb | off | 37.64 | 38.87 | 53.05 | 56.47 | 80 | 0.917 | 0 |
| scan-numeric-hi-100k | pdo_duckdb_1t | off | 38.59 | 41.03 | 53.16 | 54.50 | 78 | 1.001 | 0 |
| scan-numeric-hi-100k | rows | jit | 86.77 | 90.96 | 103.08 | 105.71 | 64 | 1.000 | 0 |
| scan-numeric-hi-100k | rows_foreach | jit | 80.81 | 92.70 | 100.15 | 102.68 | 64 | 1.000 | 0 |
| scan-numeric-hi-100k | sqlite | off | 143.93 | 148.78 | 148.69 | 152.61 | 25 | 1.000 | 0 |
| scan-numeric-hi-100k | sqlite_idx | off | 208.41 | 222.09 | 205.09 | 212.40 | 29 | 1.000 | 0 |
| scan-numeric-hi-100k | stream | jit | 216.82 | 223.34 | 215.68 | 222.20 | 27 | 1.000 | 0 |
| scan-numeric-hi-100k | stream_fast | jit | 33.72 | 34.76 | 35.47 | 36.84 | 27 | 1.000 | 1 |
| scan-numeric-hi-10m | duckdb | na |  |  | 562.73 | 567.85 | 227 | 0.323 | 0 |
| scan-numeric-hi-10m | duckdb_1t | na |  |  | 1662.02 | 1684.23 | 195 | 1.000 | 0 |
| scan-numeric-hi-10m | native | off | 507.24 | 512.01 | 503.90 | 507.95 | 23 | 1.000 | 0 |
| scan-numeric-hi-10m | native_scalar | off | 776.11 | 778.37 | 762.85 | 784.08 | 23 | 1.000 | 0 |
| scan-numeric-hi-10m | stream | jit | 22223.13 | 22432.97 | 22294.00 | 22639.22 | 27 | 1.000 | 0 |
| scan-numeric-hi-10m | stream_fast | jit | 3459.15 | 3465.26 | 3436.89 | 3456.25 | 27 | 1.000 | 0 |
| scan-numeric-hi-1m | cols | jit | 763.16 | 773.67 | 768.63 | 779.54 | 151 | 1.000 | 0 |
| scan-numeric-hi-1m | duckdb | na |  |  | 112.40 | 121.98 | 86 | 0.481 | 0 |
| scan-numeric-hi-1m | duckdb_1t | na |  |  | 216.66 | 220.73 | 85 | 1.001 | 0 |
| scan-numeric-hi-1m | duckdb_forced | na |  |  | 141.23 | 152.14 | 88 | 0.415 | 0 |
| scan-numeric-hi-1m | duckdb_forced_1t | na |  |  | 295.26 | 296.93 | 85 | 1.001 | 0 |
| scan-numeric-hi-1m | native | off | 51.42 | 51.71 | 51.22 | 55.03 | 23 | 1.000 | 0 |
| scan-numeric-hi-1m | native_scalar | off | 77.10 | 77.82 | 76.95 | 78.78 | 23 | 1.000 | 0 |
| scan-numeric-hi-1m | pdo_duckdb | off | 104.44 | 107.08 | 117.13 | 118.78 | 120 | 0.410 | 0 |
| scan-numeric-hi-1m | pdo_duckdb_1t | off | 230.30 | 238.19 | 240.37 | 248.74 | 117 | 1.000 | 0 |
| scan-numeric-hi-1m | rows | jit | 953.48 | 987.56 | 1029.72 | 1056.69 | 404 | 1.000 | 0 |
| scan-numeric-hi-1m | rows_foreach | jit | 850.02 | 863.27 | 994.19 | 1012.70 | 402 | 1.000 | 0 |
| scan-numeric-hi-1m | sqlite | off | 1440.11 | 1461.28 | 1469.07 | 1515.43 | 41 | 1.000 | 0 |
| scan-numeric-hi-1m | sqlite_idx | off | 2213.32 | 2255.64 | 2134.19 | 2231.34 | 61 | 1.000 | 0 |
| scan-numeric-hi-1m | stream | jit | 2174.21 | 2202.13 | 2171.40 | 2236.49 | 27 | 1.000 | 0 |
| scan-numeric-hi-1m | stream_fast | jit | 343.40 | 349.35 | 342.65 | 350.12 | 27 | 1.000 | 0 |
| scan-numeric-lo-100k | cols | jit | 75.83 | 77.73 | 80.98 | 82.36 | 41 | 1.000 | 0 |
| scan-numeric-lo-100k | duckdb | na |  |  | 56.66 | 57.36 | 47 | 0.888 | 0 |
| scan-numeric-lo-100k | duckdb_1t | na |  |  | 56.31 | 57.21 | 46 | 1.003 | 0 |
| scan-numeric-lo-100k | duckdb_forced | na |  |  | 65.27 | 67.43 | 48 | 0.899 | 0 |
| scan-numeric-lo-100k | duckdb_forced_1t | na |  |  | 63.98 | 73.84 | 47 | 1.004 | 0 |
| scan-numeric-lo-100k | native | off | 5.20 | 5.86 | 5.69 | 6.60 | 23 | 1.000 | 1 |
| scan-numeric-lo-100k | native_scalar | off | 7.75 | 7.77 | 8.25 | 8.42 | 23 | 1.000 | 1 |
| scan-numeric-lo-100k | pdo_duckdb | off | 38.46 | 39.78 | 53.36 | 54.25 | 80 | 0.955 | 0 |
| scan-numeric-lo-100k | pdo_duckdb_1t | off | 36.51 | 40.43 | 53.30 | 54.35 | 79 | 1.001 | 0 |
| scan-numeric-lo-100k | rows | jit | 118.13 | 142.54 | 126.85 | 129.66 | 69 | 1.000 | 0 |
| scan-numeric-lo-100k | rows_foreach | jit | 77.34 | 85.12 | 100.73 | 103.44 | 64 | 1.000 | 0 |
| scan-numeric-lo-100k | sqlite | off | 150.26 | 151.72 | 151.93 | 163.62 | 25 | 1.000 | 0 |
| scan-numeric-lo-100k | sqlite_idx | off | 207.09 | 209.25 | 211.44 | 214.93 | 29 | 1.000 | 0 |
| scan-numeric-lo-100k | stream | jit | 212.71 | 230.53 | 215.42 | 219.54 | 27 | 1.000 | 0 |
| scan-numeric-lo-100k | stream_fast | jit | 35.50 | 36.71 | 37.29 | 38.86 | 27 | 1.000 | 0 |
| scan-numeric-lo-10m | duckdb | na |  |  | 568.36 | 568.77 | 209 | 0.331 | 0 |
| scan-numeric-lo-10m | duckdb_1t | na |  |  | 1677.44 | 1691.26 | 195 | 1.000 | 0 |
| scan-numeric-lo-10m | native | off | 543.11 | 545.96 | 547.83 | 548.18 | 23 | 1.000 | 0 |
| scan-numeric-lo-10m | native_scalar | off | 802.81 | 810.08 | 805.08 | 805.47 | 23 | 1.000 | 0 |
| scan-numeric-lo-10m | stream | jit | 22129.20 | 22435.85 | 22269.50 | 22278.09 | 27 | 1.000 | 0 |
| scan-numeric-lo-10m | stream_fast | jit | 3450.89 | 3463.12 | 3455.52 | 3458.15 | 27 | 1.000 | 0 |
| scan-numeric-lo-1m | cols | jit | 758.16 | 772.51 | 771.55 | 787.40 | 151 | 1.000 | 0 |
| scan-numeric-lo-1m | duckdb | na |  |  | 113.43 | 125.23 | 86 | 0.455 | 0 |
| scan-numeric-lo-1m | duckdb_1t | na |  |  | 215.55 | 231.05 | 85 | 1.001 | 0 |
| scan-numeric-lo-1m | duckdb_forced | na |  |  | 142.82 | 150.66 | 87 | 0.412 | 0 |
| scan-numeric-lo-1m | duckdb_forced_1t | na |  |  | 298.61 | 310.05 | 85 | 1.001 | 0 |
| scan-numeric-lo-1m | native | off | 55.36 | 56.16 | 55.05 | 55.81 | 23 | 1.000 | 0 |
| scan-numeric-lo-1m | native_scalar | off | 80.43 | 81.91 | 80.07 | 81.92 | 23 | 1.000 | 0 |
| scan-numeric-lo-1m | pdo_duckdb | off | 103.81 | 105.69 | 117.90 | 120.40 | 120 | 0.399 | 0 |
| scan-numeric-lo-1m | pdo_duckdb_1t | off | 229.45 | 238.73 | 239.85 | 242.77 | 117 | 1.000 | 0 |
| scan-numeric-lo-1m | rows | jit | 1917.64 | 2266.86 | 1508.61 | 1519.91 | 435 | 1.000 | 0 |
| scan-numeric-lo-1m | rows_foreach | jit | 858.11 | 867.40 | 992.70 | 1003.37 | 402 | 1.000 | 0 |
| scan-numeric-lo-1m | sqlite | off | 1536.23 | 1560.93 | 1545.01 | 1610.06 | 41 | 1.000 | 0 |
| scan-numeric-lo-1m | sqlite_idx | off | 2200.76 | 2219.97 | 2192.96 | 2228.41 | 61 | 1.000 | 0 |
| scan-numeric-lo-1m | stream | jit | 2246.40 | 2254.52 | 2174.55 | 2262.74 | 27 | 1.000 | 0 |
| scan-numeric-lo-1m | stream_fast | jit | 364.44 | 371.84 | 348.73 | 375.89 | 27 | 1.000 | 0 |
| scan-wide-hi-100k | cols | jit | 401.71 | 425.39 | 429.15 | 436.46 | 123 | 1.000 | 0 |
| scan-wide-hi-100k | duckdb | na |  |  | 69.96 | 73.44 | 69 | 0.634 | 0 |
| scan-wide-hi-100k | duckdb_1t | na |  |  | 100.14 | 103.11 | 68 | 1.002 | 0 |
| scan-wide-hi-100k | duckdb_forced | na |  |  | 91.91 | 92.54 | 73 | 0.511 | 0 |
| scan-wide-hi-100k | duckdb_forced_1t | na |  |  | 170.08 | 171.65 | 70 | 1.001 | 0 |
| scan-wide-hi-100k | native | off | 8.51 | 8.71 | 10.87 | 15.73 | 23 | 1.000 | 0 |
| scan-wide-hi-100k | native_scalar | off | 29.30 | 30.55 | 31.12 | 31.55 | 23 | 1.000 | 0 |
| scan-wide-hi-100k | pdo_duckdb | off | 51.32 | 52.35 | 65.06 | 66.41 | 103 | 0.543 | 0 |
| scan-wide-hi-100k | pdo_duckdb_1t | off | 86.21 | 90.00 | 99.81 | 105.56 | 100 | 1.000 | 0 |
| scan-wide-hi-100k | rows | jit | 370.11 | 400.69 | 478.20 | 486.57 | 202 | 1.000 | 0 |
| scan-wide-hi-100k | rows_foreach | jit | 346.10 | 382.50 | 469.88 | 476.13 | 202 | 1.000 | 0 |
| scan-wide-hi-100k | sqlite | off | 180.49 | 183.21 | 183.29 | 189.09 | 26 | 1.000 | 0 |
| scan-wide-hi-100k | sqlite_idx | off | 253.98 | 259.82 | 243.69 | 253.43 | 30 | 1.000 | 0 |
| scan-wide-hi-100k | stream | jit | 1217.30 | 1230.59 | 1224.99 | 1233.72 | 27 | 1.000 | 0 |
| scan-wide-hi-100k | stream_fast | jit | 68.39 | 69.13 | 72.12 | 73.42 | 27 | 1.000 | 0 |
| scan-wide-hi-10m | duckdb | na |  |  | 1859.82 | 1869.44 | 442 | 0.343 | 0 |
| scan-wide-hi-10m | duckdb_1t | na |  |  | 5221.85 | 5247.00 | 195 | 1.000 | 0 |
| scan-wide-hi-10m | native | off | 1027.22 | 1035.20 | 1029.39 | 1035.37 | 23 | 1.000 | 0 |
| scan-wide-hi-10m | native_scalar | off | 3031.07 | 3045.74 | 3026.30 | 3050.94 | 23 | 1.000 | 0 |
| scan-wide-hi-10m | stream | jit | 123411.64 | 124070.66 | 123259.21 | 124033.78 | 27 | 1.000 | 0 |
| scan-wide-hi-10m | stream_fast | jit | 7030.26 | 7035.59 | 7133.34 | 7245.59 | 27 | 1.000 | 0 |
| scan-wide-hi-1m | cols | jit | 4360.75 | 4385.44 | 3660.73 | 3681.75 | 923 | 1.000 | 0 |
| scan-wide-hi-1m | duckdb | na |  |  | 256.42 | 268.62 | 197 | 0.409 | 0 |
| scan-wide-hi-1m | duckdb_1t | na |  |  | 612.37 | 621.37 | 195 | 1.000 | 0 |
| scan-wide-hi-1m | duckdb_forced | na |  |  | 406.81 | 423.83 | 158 | 0.320 | 0 |
| scan-wide-hi-1m | duckdb_forced_1t | na |  |  | 1258.42 | 1276.16 | 168 | 1.000 | 0 |
| scan-wide-hi-1m | native | off | 100.37 | 102.66 | 100.68 | 108.37 | 23 | 1.000 | 0 |
| scan-wide-hi-1m | native_scalar | off | 303.11 | 305.63 | 299.76 | 306.77 | 23 | 1.000 | 0 |
| scan-wide-hi-1m | pdo_duckdb | off | 243.64 | 264.31 | 241.13 | 264.22 | 176 | 0.378 | 0 |
| scan-wide-hi-1m | pdo_duckdb_1t | off | 629.97 | 643.00 | 644.64 | 663.06 | 228 | 1.000 | 0 |
| scan-wide-hi-1m | rows | jit | 4021.98 | 4271.22 | 4243.94 | 4274.84 | 1759 | 1.000 | 0 |
| scan-wide-hi-1m | rows_foreach | jit | 3594.15 | 3850.58 | 4172.98 | 4205.44 | 1756 | 1.000 | 0 |
| scan-wide-hi-1m | sqlite | off | 1812.24 | 1824.21 | 1850.66 | 1933.09 | 45 | 1.000 | 0 |
| scan-wide-hi-1m | sqlite_idx | off | 2630.65 | 2650.69 | 2624.15 | 2785.35 | 70 | 1.000 | 0 |
| scan-wide-hi-1m | stream | jit | 12222.22 | 12284.82 | 12268.69 | 12885.95 | 27 | 1.000 | 0 |
| scan-wide-hi-1m | stream_fast | jit | 710.61 | 728.01 | 708.73 | 734.09 | 27 | 1.000 | 0 |
| scan-wide-lo-100k | cols | jit | 416.50 | 426.80 | 431.98 | 440.96 | 123 | 1.000 | 0 |
| scan-wide-lo-100k | duckdb | na |  |  | 69.47 | 74.45 | 69 | 0.632 | 0 |
| scan-wide-lo-100k | duckdb_1t | na |  |  | 102.53 | 103.93 | 68 | 1.002 | 0 |
| scan-wide-lo-100k | duckdb_forced | na |  |  | 91.67 | 95.94 | 73 | 0.509 | 0 |
| scan-wide-lo-100k | duckdb_forced_1t | na |  |  | 172.78 | 175.42 | 70 | 1.003 | 0 |
| scan-wide-lo-100k | native | off | 8.82 | 8.95 | 11.09 | 11.38 | 23 | 1.000 | 0 |
| scan-wide-lo-100k | native_scalar | off | 28.92 | 29.02 | 31.38 | 31.93 | 23 | 1.000 | 0 |
| scan-wide-lo-100k | pdo_duckdb | off | 51.32 | 52.83 | 64.91 | 66.45 | 103 | 0.566 | 0 |
| scan-wide-lo-100k | pdo_duckdb_1t | off | 87.07 | 90.42 | 101.06 | 102.98 | 100 | 1.000 | 0 |
| scan-wide-lo-100k | rows | jit | 509.68 | 543.76 | 599.25 | 605.04 | 207 | 1.000 | 0 |
| scan-wide-lo-100k | rows_foreach | jit | 348.38 | 393.99 | 478.22 | 488.03 | 202 | 1.000 | 0 |
| scan-wide-lo-100k | sqlite | off | 186.88 | 189.46 | 189.15 | 197.16 | 26 | 1.000 | 0 |
| scan-wide-lo-100k | sqlite_idx | off | 243.70 | 245.58 | 253.65 | 267.14 | 30 | 1.000 | 0 |
| scan-wide-lo-100k | stream | jit | 1224.11 | 1234.99 | 1232.86 | 1245.25 | 27 | 1.000 | 0 |
| scan-wide-lo-100k | stream_fast | jit | 71.65 | 76.47 | 74.31 | 76.80 | 27 | 1.000 | 0 |
| scan-wide-lo-10m | duckdb | na |  |  | 1882.97 | 1900.51 | 442 | 0.343 | 0 |
| scan-wide-lo-10m | duckdb_1t | na |  |  | 5245.45 | 5269.79 | 195 | 1.000 | 0 |
| scan-wide-lo-10m | native | off | 1058.56 | 1064.62 | 1057.29 | 1062.36 | 23 | 1.000 | 0 |
| scan-wide-lo-10m | native_scalar | off | 3068.22 | 3073.74 | 3071.62 | 3084.90 | 23 | 1.000 | 0 |
| scan-wide-lo-10m | stream | jit | 133254.51 | 134042.14 | 124199.53 | 134315.96 | 27 | 1.000 | 0 |
| scan-wide-lo-10m | stream_fast | jit | 7384.97 | 7617.86 | 7317.86 | 7391.93 | 27 | 1.000 | 0 |
| scan-wide-lo-1m | cols | jit | 4444.69 | 4481.90 | 3645.03 | 3673.20 | 923 | 1.000 | 0 |
| scan-wide-lo-1m | duckdb | na |  |  | 259.45 | 270.84 | 197 | 0.405 | 0 |
| scan-wide-lo-1m | duckdb_1t | na |  |  | 613.93 | 642.37 | 195 | 1.000 | 0 |
| scan-wide-lo-1m | duckdb_forced | na |  |  | 422.21 | 438.60 | 202 | 0.322 | 0 |
| scan-wide-lo-1m | duckdb_forced_1t | na |  |  | 1266.47 | 1284.61 | 167 | 1.000 | 0 |
| scan-wide-lo-1m | native | off | 102.62 | 104.29 | 104.50 | 105.36 | 23 | 1.000 | 0 |
| scan-wide-lo-1m | native_scalar | off | 306.09 | 310.73 | 304.38 | 311.29 | 23 | 1.000 | 0 |
| scan-wide-lo-1m | pdo_duckdb | off | 240.65 | 275.06 | 257.64 | 287.49 | 215 | 0.386 | 0 |
| scan-wide-lo-1m | pdo_duckdb_1t | off | 628.24 | 634.27 | 644.36 | 654.43 | 228 | 1.000 | 0 |
| scan-wide-lo-1m | rows | jit | 6726.89 | 8640.51 | 6100.91 | 6183.88 | 1790 | 1.000 | 0 |
| scan-wide-lo-1m | rows_foreach | jit | 3643.05 | 3902.24 | 4191.32 | 4214.96 | 1757 | 1.000 | 0 |
| scan-wide-lo-1m | sqlite | off | 1914.23 | 1934.64 | 1897.83 | 1987.16 | 45 | 1.000 | 0 |
| scan-wide-lo-1m | sqlite_idx | off | 2686.06 | 2702.15 | 2685.36 | 2828.01 | 70 | 1.000 | 0 |
| scan-wide-lo-1m | stream | jit | 12212.19 | 12246.32 | 12339.80 | 12625.31 | 27 | 1.000 | 0 |
| scan-wide-lo-1m | stream_fast | jit | 715.20 | 729.18 | 718.72 | 739.54 | 27 | 1.000 | 0 |


### Result checks

- gi-numeric-hi-c10-100k: all agree
- gi-numeric-hi-c10-10m: all agree
- gi-numeric-hi-c10-1m: all agree
- gi-numeric-hi-c100k-100k: all agree
- gi-numeric-hi-c100k-10m: all agree
- gi-numeric-hi-c100k-1m: all agree
- gi-numeric-hi-c1k-100k: all agree
- gi-numeric-hi-c1k-10m: all agree
- gi-numeric-hi-c1k-1m: all agree
- gi-numeric-lo-c10-100k: all agree
- gi-numeric-lo-c10-10m: all agree
- gi-numeric-lo-c10-1m: all agree
- gi-numeric-lo-c100k-100k: all agree
- gi-numeric-lo-c100k-10m: all agree
- gi-numeric-lo-c100k-1m: all agree
- gi-numeric-lo-c1k-100k: all agree
- gi-numeric-lo-c1k-10m: all agree
- gi-numeric-lo-c1k-1m: all agree
- gs-mixed-hi-c10-100k: all agree
- gs-mixed-hi-c10-10m: all agree
- gs-mixed-hi-c10-1m: all agree
- gs-mixed-hi-c100k-100k: all agree
- gs-mixed-hi-c100k-10m: all agree
- gs-mixed-hi-c100k-1m: all agree
- gs-mixed-hi-c1k-100k: all agree
- gs-mixed-hi-c1k-10m: all agree
- gs-mixed-hi-c1k-1m: all agree
- gs-mixed-lo-c10-100k: all agree
- gs-mixed-lo-c10-10m: all agree
- gs-mixed-lo-c10-1m: all agree
- gs-mixed-lo-c100k-100k: all agree
- gs-mixed-lo-c100k-10m: all agree
- gs-mixed-lo-c100k-1m: all agree
- gs-mixed-lo-c1k-100k: all agree
- gs-mixed-lo-c1k-10m: all agree
- gs-mixed-lo-c1k-1m: all agree
- gs-mixnq-hi-c10-100k: all agree
- gs-mixnq-hi-c10-10m: all agree
- gs-mixnq-hi-c10-1m: all agree
- gs-mixnq-hi-c100k-100k: all agree
- gs-mixnq-hi-c100k-10m: all agree
- gs-mixnq-hi-c100k-1m: all agree
- gs-mixnq-hi-c1k-100k: all agree
- gs-mixnq-hi-c1k-10m: all agree
- gs-mixnq-hi-c1k-1m: all agree
- gs-mixnq-lo-c10-100k: all agree
- gs-mixnq-lo-c10-10m: all agree
- gs-mixnq-lo-c10-1m: all agree
- gs-mixnq-lo-c100k-100k: all agree
- gs-mixnq-lo-c100k-10m: all agree
- gs-mixnq-lo-c100k-1m: all agree
- gs-mixnq-lo-c1k-100k: all agree
- gs-mixnq-lo-c1k-10m: all agree
- gs-mixnq-lo-c1k-1m: all agree
- gs-wide-hi-c1k-100k: all agree
- gs-wide-hi-c1k-10m: all agree
- gs-wide-hi-c1k-1m: all agree
- gs-wide-lo-c1k-100k: all agree
- gs-wide-lo-c1k-10m: all agree
- gs-wide-lo-c1k-1m: all agree
- gt-mixed-hi-c10-100k: all agree
- gt-mixed-hi-c10-10m: all agree
- gt-mixed-hi-c10-1m: all agree
- gt-mixed-hi-c100k-100k: all agree
- gt-mixed-hi-c100k-10m: all agree
- gt-mixed-hi-c100k-1m: all agree
- gt-mixed-hi-c1k-100k: all agree
- gt-mixed-hi-c1k-10m: all agree
- gt-mixed-hi-c1k-1m: all agree
- gt-mixed-lo-c10-100k: all agree
- gt-mixed-lo-c10-10m: all agree
- gt-mixed-lo-c10-1m: all agree
- gt-mixed-lo-c100k-100k: all agree
- gt-mixed-lo-c100k-10m: all agree
- gt-mixed-lo-c100k-1m: all agree
- gt-mixed-lo-c1k-100k: all agree
- gt-mixed-lo-c1k-10m: all agree
- gt-mixed-lo-c1k-1m: all agree
- gti-numeric-lo-c10-100k: all agree
- gti-numeric-lo-c10-10m: all agree
- gti-numeric-lo-c10-1m: all agree
- gti-numeric-lo-c100k-100k: all agree
- gti-numeric-lo-c100k-10m: all agree
- gti-numeric-lo-c100k-1m: all agree
- gti-numeric-lo-c1k-100k: all agree
- gti-numeric-lo-c1k-10m: all agree
- gti-numeric-lo-c1k-1m: all agree
- rows-mixed-hi-100k: all agree
- rows-mixed-hi-10m: all agree
- rows-mixed-hi-1m: all agree
- rows-mixed-lo-100k: all agree
- rows-mixed-lo-10m: all agree
- rows-mixed-lo-1m: all agree
- rows-numeric-hi-100k: all agree
- rows-numeric-hi-10m: all agree
- rows-numeric-hi-1m: all agree
- rows-numeric-lo-100k: all agree
- rows-numeric-lo-10m: all agree
- rows-numeric-lo-1m: all agree
- rows-wide-hi-100k: all agree
- rows-wide-hi-10m: all agree
- rows-wide-hi-1m: all agree
- rows-wide-lo-100k: all agree
- rows-wide-lo-10m: all agree
- rows-wide-lo-1m: all agree
- scan-mixed-hi-100k: all agree
- scan-mixed-hi-10m: all agree
- scan-mixed-hi-1m: all agree
- scan-mixed-lo-100k: all agree
- scan-mixed-lo-10m: all agree
- scan-mixed-lo-1m: all agree
- scan-mixnq-hi-100k: all agree
- scan-mixnq-hi-10m: all agree
- scan-mixnq-hi-1m: all agree
- scan-mixnq-lo-100k: all agree
- scan-mixnq-lo-10m: all agree
- scan-mixnq-lo-1m: all agree
- scan-numeric-hi-100k: all agree
- scan-numeric-hi-10m: all agree
- scan-numeric-hi-1m: all agree
- scan-numeric-lo-100k: all agree
- scan-numeric-lo-10m: all agree
- scan-numeric-lo-1m: all agree
- scan-wide-hi-100k: all agree
- scan-wide-hi-10m: all agree
- scan-wide-hi-1m: all agree
- scan-wide-lo-100k: all agree
- scan-wide-lo-10m: all agree
- scan-wide-lo-1m: all agree

## Excluded, void and rerun samples and sessions

- **Full run, single-core part: 10 excluded samples** (wall/CPU above 1.05; each stays in the raw log marked excluded, and the series was run again until it had its clean samples). All are 100,000-row cells:

| cell | implementation | series | wall/CPU | wall ms |
| --- | --- | --- | ---: | ---: |
| scan-numeric-hi-100k | stream_fast | warm | 1.084 | 37 |
| scan-numeric-hi-100k | native | fresh | 1.053 | 5 |
| scan-numeric-lo-100k | native | fresh | 1.491 | 8 |
| scan-numeric-lo-100k | native_scalar | fresh | 1.080 | 8 |
| scan-mixed-lo-100k | native | fresh | 1.123 | 9 |
| scan-mixnq-lo-100k | native_scalar | fresh | 1.068 | 16 |
| gs-mixnq-hi-c10-100k | native_scalar | fresh | 1.053 | 17 |
| gs-mixnq-lo-c10-100k | native | fresh | 1.066 | 12 |
| gi-numeric-hi-c1k-100k | native | fresh | 1.072 | 6 |
| gi-numeric-lo-c100k-100k | native | fresh | 1.648 | 31 |

- **Full run, all-cores part:** no excluded samples (multi-threaded runs are exempt from the ratio). The first run of the 28 `pdo_duckdb` four-core cells
  (7 `scan-*`, 16 `gs-*` and 5 `gi-*` cells) produced
  no samples, because `run_one.php` compared DuckDB's float sums bit-exactly between calls of one process (`RESULT CHANGED between calls of one process`, exit 4) and parallel summation
  varies in the last bits. The check now runs after the timed calls only: bit-exact for native and the PHP loops, and for SQLite and DuckDB the counts and group counts equal and the sums within 1e-6 relative
  (`abs(a - b) <= 1e-6 * max(1, abs(b))`, so absolute below 1). It does not touch the timed region. For SQLite and DuckDB the check after the run compares sums (1e-6 relative) and group counts, not the row count; the row counts were equal in all 129 workloads in the logs. All 73 `pdo_duckdb` four-core cells were then run again (05:03Z to 05:21Z) and none is missing.
- **Comparison session 1 (the earlier-build comparison) is void** (median |A/A| 0.62%, above 0.5%; it started on a quiet host, load 0.05, no containers, and its load average rose to about 2.8 during the run, from the benchmark's own four jobs). It is not used; its numbers, for the record:

| cell | release/m2fix | m2fix2/m2fix | excluded | digests |
| --- | ---: | ---: | ---: | --- |
| scan-numeric-hi-1m | -1.10% | +1.89% | 1 | identical |
| scan-numeric-lo-1m | -1.97% | +0.49% | 0 | identical |
| scan-mixed-hi-1m | -1.25% | +0.04% | 0 | identical |
| scan-mixed-lo-1m | -1.86% | +0.76% | 0 | identical |
| scan-mixnq-hi-1m | -1.27% | -0.49% | 0 | identical |
| scan-mixnq-lo-1m | -1.30% | +0.41% | 0 | identical |
| scan-wide-hi-1m | -31.17% | -0.94% | 0 | identical |
| scan-wide-lo-1m | -31.43% | -0.12% | 0 | identical |
| gs-mixed-hi-c10-1m | -2.97% | -0.74% | 0 | identical |
| gs-mixed-hi-c1k-1m | -3.38% | -1.43% | 0 | identical |
| gs-mixed-hi-c100k-1m | -4.48% | +0.36% | 0 | identical |
| gs-mixed-lo-c10-1m | -2.88% | -0.25% | 0 | identical |
| gs-mixed-lo-c1k-1m | -2.17% | +0.56% | 0 | identical |
| gs-mixed-lo-c100k-1m | -18.65% | -0.65% | 0 | identical |
| gs-mixnq-hi-c10-1m | -2.37% | -0.64% | 0 | identical |
| gs-mixnq-hi-c1k-1m | -3.40% | -0.46% | 0 | identical |
| gs-mixnq-hi-c100k-1m | -4.88% | -0.44% | 0 | identical |
| gs-mixnq-lo-c10-1m | -2.70% | -0.69% | 0 | identical |
| gs-mixnq-lo-c1k-1m | -1.62% | -0.60% | 0 | identical |
| gs-mixnq-lo-c100k-1m | -19.35% | +1.36% | 0 | identical |
| gs-wide-hi-c1k-1m | -25.59% | +0.65% | 0 | identical |
| gs-wide-lo-c1k-1m | -21.63% | -0.07% | 0 | identical |
| gi-numeric-hi-c10-1m | -2.40% | -0.71% | 0 | identical |
| gi-numeric-hi-c1k-1m | -1.73% | +0.49% | 0 | identical |
| gi-numeric-hi-c100k-1m | -0.98% | -1.04% | 0 | identical |
| gi-numeric-lo-c10-1m | -1.12% | -1.03% | 0 | identical |
| gi-numeric-lo-c1k-1m | +1.38% | +1.13% | 0 | identical |
| gi-numeric-lo-c100k-1m | -16.50% | +0.37% | 0 | identical |

- **Comparison sessions:** none void. Excluded samples per cell are in each table above (the `excluded` column); a cell inside its A/A noise that decided a result was rerun (`ab3b`).
- **Observers and load:** during the comparison sessions other jobs on the shared host ran on cores 0 to 25 (load average 1 to 82 at times: container builds count toward load even under `--cpus`);
  the A/A control of each session carries that noise. From 20:12Z the host was held quiet for the earlier-build comparison and the full run: no other job ran during any timed window, and an interactive SSH login running `htop` (light load) was open from 22:01Z.
- **arm64:** no speed is claimed. Docker Desktop's Linux VM on the Apple Silicon Mac gives wall/CPU between 1.06 and 2.1 per sample for the same jobs (measured with the harness on the 1,000,000-row `scan-numeric-hi` cell: 1.01 to 1.19 warm, 1.8 to 2.1 fresh), so the
  isolation requirement excludes nearly every sample and the series are "not measured". arm64 is a correctness platform in this release: PHPT on 8.3, 8.4, 8.5 and 8.6RC5, a 6 x 20 minute and an 8 x 90 minute fuzz, and the same result digests at 100,000 rows. Timing was measured on x86-64 only.
