#!/usr/bin/env bash
# ASan+UBSan PHPT, the Zend-allocator run, a separate UBSan-only run and the fuzz corpus replay,
# on a debug PHP built from source.
# env (optional): PHP_VERSION, PHP_URL, PHP_SHA256 (default 8.3.36 from php.net) and SAN_CC, the compiler
# of PHP and the extension (default clang), BF_TEST_JOBS (run-tests workers, default 1).
# The fuzz corpus replay always uses clang (libFuzzer).
# Called from .github/workflows/ci.yml and run unchanged on any Linux host.
# Each lane's log, and the .diff, .out, .exp, .log and .mem files of every test it failed, go to
# $BF_LOG_DIR (default sanitizer-logs/ in the checkout; mount it to keep them past a container),
# and a failing test's name and diff are printed too.
set -euo pipefail

export PHP_VERSION=${PHP_VERSION:-8.3.36}
PHP_SHA256=${PHP_SHA256:-dd54693aa334c5c4d72e9aee96b082c2473750a18a6c1d105789c93a3b1692fd}
PHP_URL=${PHP_URL:-https://www.php.net/distributions/php-${PHP_VERSION}.tar.xz}
SAN_CC=${SAN_CC:-clang}
J=${BF_TEST_JOBS:-1}
case "$SAN_CC" in
    clang) SAN_CXX=clang++ ;;
    gcc) SAN_CXX=g++ ;;
    *) echo "::error::SAN_CC must be clang or gcc" >&2; exit 1 ;;
esac

export DEBIAN_FRONTEND=noninteractive
apt-get update -qq
apt-get install -y -qq --no-install-recommends clang llvm lld libclang-rt-dev gcc g++ autoconf automake libtool bison re2c make \
    pkg-config file ca-certificates curl xz-utils libxml2-dev libsqlite3-dev libonig-dev zlib1g-dev locales >/dev/null
sed -i 's/^# *de_DE.UTF-8 UTF-8/de_DE.UTF-8 UTF-8/' /etc/locale.gen
locale-gen >/dev/null

src=$(pwd)
logs=${BF_LOG_DIR:-$src/sanitizer-logs}
mkdir -p "$logs"
cd /usr/src
curl -fsSL -o php.tar.xz "$PHP_URL"
echo "${PHP_SHA256}  php.tar.xz" | sha256sum -c -
mkdir php && tar xf php.tar.xz -C php --strip-components=1
cd php
CC=$SAN_CC CXX=$SAN_CXX CFLAGS="-O1 -g -fno-omit-frame-pointer" ./configure \
    --prefix=/usr/local --with-config-file-path=/usr/local/etc/php --with-config-file-scan-dir=/usr/local/etc/php/conf.d \
    --disable-all --enable-cli --enable-debug --enable-address-sanitizer --enable-undefined-sanitizer \
    --enable-mbstring --enable-session --without-pcre-jit --disable-cgi --disable-phpdbg >/dev/null
make -j"$(nproc)" >/dev/null
make install >/dev/null
cp run-tests.php /usr/local/lib/php/build/run-tests.php
mkdir -p /usr/local/etc/php/conf.d
php -r 'exit(PHP_VERSION === getenv("PHP_VERSION") && PHP_DEBUG ? 0 : 1);' \
    || { echo "::error::expected a debug PHP ${PHP_VERSION}" >&2; exit 1; }
php -r 'echo PHP_VERSION, " ", php_uname("m"), "\n";'
cd "$src"

# build label cflags ldflags: a fresh copy of the tree built with those flags, left in $build.
build_ext() {
    build=$(mktemp -d)
    cp -a "$src/." "$build/"
    cd "$build"
    CC=$SAN_CC CFLAGS="$1" LDFLAGS="$2" phpize >/dev/null
    CC=$SAN_CC CFLAGS="$1" LDFLAGS="$2" ./configure --enable-batchflow >/dev/null
    CC=$SAN_CC CFLAGS="$1" LDFLAGS="$2" make -j"$(nproc)" 2>&1 | tee /tmp/bf-make.log >/dev/null
    if grep -E 'warning:' /tmp/bf-make.log | grep -E 'src/'; then
        echo "::error::compiler warnings in src/" >&2
        exit 1
    fi
}

keep() { # label log: print the failed tests and their diffs, and move their files to $logs/label/
    local t
    mkdir -p "$logs/$1"
    sed -n '/^FAILED TEST SUMMARY/,/^=====/p' "$2"
    for t in tests/*.diff; do
        [ -e "$t" ] || continue
        echo "--- $t"
        cat "$t"
        mv "${t%.diff}".diff "${t%.diff}".out "${t%.diff}".exp "${t%.diff}".log "${t%.diff}".mem "$logs/$1/" 2>/dev/null || true
    done
}

summary() { # label log minimum-passed skip-list (a SKIPIF regression must not pass quietly)
    local failed borked passed skipped total xfail want skips
    failed=$(awk '/^Tests failed/ {print $4}' "$2")
    borked=$(awk '/^Tests borked/ {print $4}' "$2")
    passed=$(awk '/^Tests passed/ {print $4}' "$2")
    skipped=$(awk '/^Tests skipped/ {print $4}' "$2")
    total=$(awk '/^Number of tests/ {print $5}' "$2")
    xfail=$(awk '/^Expected fail/ {print $4}' "$2")
    echo "$1 RESULT passed=${passed:-0} failed=${failed:-0} borked=${borked:-0} skipped=${skipped:-0} total=${total:-0} xfail=${xfail:-0}"
    want=$(ls tests/*.phpt | wc -l)
    if [ "${total:-0}" != "$want" ] || [ "${xfail:-0}" != 0 ]; then
        echo "::error::$1: expected $want tests and no expected failure, got ${total:-0} and ${xfail:-0}" >&2
        exit 1
    fi
    skips=$(tr '\r' '\n' <"$2" | grep -E '^SKIP ' | grep -oE '\[tests/[^]]+\.phpt\]' | sort | tr '\n' ' ' || true)
    if [ "$skips" != "$4" ]; then
        echo "::error::$1: expected the skips ${4:-none}, got ${skips:-none}" >&2
        exit 1
    fi
    [ "${failed:-0}" = 0 ] && [ "${borked:-0}" = 0 ] && [ "${passed:-0}" -ge "$3" ]
}

ASAN_RUN="-fno-omit-frame-pointer -fsanitize=address,undefined -fno-sanitize-recover=undefined"

# PHPT under ASan/UBSan with leak detection. Zend's allocator is off, so memory_limit tests skip themselves,
# and only they may.
build_ext "-O1 -g $ASAN_RUN" "-fsanitize=address,undefined"
USE_ZEND_ALLOC=0 ZEND_DONT_UNLOAD_MODULES=1 \
ASAN_OPTIONS=detect_leaks=1:abort_on_error=0:halt_on_error=1:strict_string_checks=1:detect_stack_use_after_return=1:symbolize=1:print_summary=1 \
UBSAN_OPTIONS=print_stacktrace=1:halt_on_error=1:print_summary=1 \
NO_COLOR=1 NO_INTERACTION=1 make test "TESTS=-j$J --asan --set-timeout 1500 tests" >"$logs/asan.log" 2>&1 || true
keep asan "$logs/asan.log"
summary asan "$logs/asan.log" 119 '[tests/060_reader_memory.phpt] [tests/063_initial_buffer_preflight.phpt] [tests/089_group_agg_result_margin.phpt] [tests/095_toarray_budget_boundary.phpt] [tests/122_table_dict_load_fatal_cleanup.phpt] [tests/123_table_group_agg_arrays_memory.phpt] [tests/124_table_row_arrays_memory.phpt] [tests/125_table_dict_load_no_fatal.phpt] [tests/126_table_dict2_load_no_fatal.phpt] [tests/127_stream_group_fragmented_heap.phpt] [tests/128_mem_try_restores_limit.phpt] '

# The same build with the Zend allocator on, so ZendMM's leak report applies and memory_limit tests run.
# Without --asan run-tests.php keeps its plain 60 s limit per test. The table memory sweeps (118 to 120) take
# 2 to 5 minutes under ASan on a busy machine (over 180 s, run-tests' limit under --asan), so every lane
# gets 1,500 s per test, the value the arm64 run was validated with.
ASAN_OPTIONS=detect_leaks=0:abort_on_error=0:halt_on_error=1 \
UBSAN_OPTIONS=print_stacktrace=1:halt_on_error=1:print_summary=1 \
NO_COLOR=1 NO_INTERACTION=1 make test "TESTS=-j$J --set-timeout 1500 tests" >"$logs/zend-allocator.log" 2>&1 || true
keep zend-allocator "$logs/zend-allocator.log"
summary zend-allocator "$logs/zend-allocator.log" 130 ''

# UBSan alone in the extension (the PHP above still carries both runtimes), with float-divide-by-zero as well;
# any report stops the process.
build_ext "-O1 -g -fno-omit-frame-pointer -fsanitize=undefined,float-divide-by-zero -fno-sanitize-recover=all" \
    "-fsanitize=undefined,float-divide-by-zero"
ASAN_OPTIONS=detect_leaks=0:abort_on_error=0:halt_on_error=1 \
UBSAN_OPTIONS=print_stacktrace=1:halt_on_error=1:print_summary=1 \
NO_COLOR=1 NO_INTERACTION=1 make test "TESTS=-j$J --set-timeout 1500 tests" >"$logs/ubsan.log" 2>&1 || true
keep ubsan "$logs/ubsan.log"
if grep -q 'runtime error' "$logs/ubsan.log"; then
    echo "::error::ubsan: runtime error in the log" >&2
    exit 1
fi
summary ubsan "$logs/ubsan.log" 130 ''

# Replay the committed fuzz corpus under ASan/UBSan, and run the C unit tests the fuzz build ends with.
cd "$src"
BF_FUZZ_OUT=/tmp/bf-fuzz CC=clang fuzz/build.sh
/tmp/bf-fuzz/fuzz_batchflow fuzz/corpus/seed/* fuzz/corpus/regression/* 2>&1 | tail -n 4
echo "fuzz corpus replay done, no failure"
