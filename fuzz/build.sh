#!/usr/bin/env bash
# Builds the libFuzzer target over the BatchFlow core and, with "coverage", a coverage binary.
# Needs clang with the compiler-rt fuzzer runtime; no PHP. Run from anywhere.
set -euo pipefail

ROOT=$(cd "$(dirname "$0")/.." && pwd)
OUT=${BF_FUZZ_OUT:-$ROOT/fuzz/out}
CC=${CC:-clang}
SRC=(fuzz/fuzz_batchflow.c src/bf_masks.c src/bf_value.c src/bf_csv.c src/bf_exec.c src/bf_hash.c src/bf_groups.c src/bf_table.c)
COMMON=(-std=gnu11 -g -O1 -fno-omit-frame-pointer -Isrc -DBF_FUZZ_HASH_SHIM -lm)

mkdir -p "$OUT"
cd "$ROOT"
# C unit test first: the SipHash-1-3 vector table (SPEC 6.3). A mismatch stops the build.
"$CC" -std=c11 -Wall -Wextra -O2 -Isrc fuzz/test_siphash.c src/bf_hash.c -o "$OUT/test_siphash"
"$OUT/test_siphash"
# The memory_limit rule on given ZendMM counters (fuzz/test_mem_rule.c); a failed check stops the build.
"$CC" -std=c11 -Wall -Wextra -O2 -Isrc fuzz/test_mem_rule.c -o "$OUT/test_mem_rule"
"$OUT/test_mem_rule"
# The group table with injected hash keys (fuzz/test_groups.c); a failed check stops the build.
"$CC" "${COMMON[@]}" -Wall -Wextra -fsanitize=address,undefined -fno-sanitize-recover=undefined fuzz/test_groups.c "${SRC[@]:1}" -o "$OUT/test_groups"
# The Table column store (fuzz/test_table.c); a failed check stops the build.
"$CC" "${COMMON[@]}" -Wall -Wextra -fsanitize=address,undefined -fno-sanitize-recover=undefined fuzz/test_table.c "${SRC[@]:1}" -o "$OUT/test_table"
"$OUT/test_table"
"$OUT/test_groups"
"$CC" "${COMMON[@]}" -fsanitize=fuzzer,address,undefined -fno-sanitize-recover=undefined "${SRC[@]}" -o "$OUT/fuzz_batchflow"
if [ "${1:-}" = coverage ]; then
	"$CC" "${COMMON[@]}" -fsanitize=fuzzer -fprofile-instr-generate -fcoverage-mapping "${SRC[@]}" -o "$OUT/fuzz_batchflow_cov"
fi
echo "built: $OUT/fuzz_batchflow"
