#!/usr/bin/env bash
# Build and PHPT for one official PHP image (php:X-cli or php:X-zts). Called from .github/workflows/ci.yml
# and run unchanged on any Linux host.
# env (optional): BF_EXPECT_ZTS, 0 or 1: fail unless the image's thread safety matches; BF_TEST_JOBS, run-tests workers (default 1).
set -euo pipefail

export DEBIAN_FRONTEND=noninteractive
apt-get update -qq
# shellcheck disable=SC2086
apt-get install -y -qq --no-install-recommends $PHPIZE_DEPS locales >/dev/null
# A comma-decimal locale, so the locale-independence tests run instead of skipping.
sed -i 's/^# *de_DE.UTF-8 UTF-8/de_DE.UTF-8 UTF-8/' /etc/locale.gen
locale-gen >/dev/null
# The stock image enables opcache: state the test configuration instead of inheriting it.
rm -f /usr/local/etc/php/conf.d/docker-php-ext-opcache.ini
php -r 'echo PHP_VERSION, PHP_ZTS ? " ZTS" : " NTS", " ", php_uname("m"), "\n";'
if [ -n "${BF_EXPECT_ZTS:-}" ]; then
    php -r 'exit((int)PHP_ZTS === (int)getenv("BF_EXPECT_ZTS") ? 0 : 1);' \
        || { echo "::error::expected PHP_ZTS=${BF_EXPECT_ZTS}" >&2; exit 1; }
fi

src=$(pwd)
build=$(mktemp -d)
cp -a "$src/." "$build/"
cd "$build"

phpize >/dev/null
CFLAGS="${PHP_CFLAGS:-} -Wall -Wextra" ./configure --enable-batchflow >/dev/null
make -j"$(nproc)" 2>&1 | tee /tmp/bf-make.log >/dev/null

if grep -E 'warning:' /tmp/bf-make.log | grep -E 'src/'; then
    echo "::error::compiler warnings in src/" >&2
    exit 1
fi
# Only the module entry point may be exported (the build uses -fvisibility=hidden).
syms=$(nm -D --defined-only modules/batchflow.so | awk '{print $3}') \
    || { echo "::error::nm failed, so the exported-symbol check cannot run" >&2; exit 1; }
extra=$(grep -vxE 'get_module|_init|_fini|_edata|_end|__bss_start' <<<"$syms" || true)
if [ -n "$extra" ]; then
    echo "::error::unexpected exported symbols: $(tr '\n' ' ' <<<"$extra")" >&2
    exit 1
fi

NO_COLOR=1 NO_INTERACTION=1 REPORT_EXIT_STATUS=1 make test "TESTS=-j${BF_TEST_JOBS:-1} tests" 2>&1 | tee /tmp/bf-test.log | tail -8
# make test's exit code changes between PHP versions, so read the summary it printed.
failed=$(awk '/^Tests failed/ {print $4}' /tmp/bf-test.log)
borked=$(awk '/^Tests borked/ {print $4}' /tmp/bf-test.log)
passed=$(awk '/^Tests passed/ {print $4}' /tmp/bf-test.log)
skipped=$(awk '/^Tests skipped/ {print $4}' /tmp/bf-test.log)
echo "RESULT passed=${passed:-0} failed=${failed:-0} borked=${borked:-0} skipped=${skipped:-0}"
# Every test file must have run, none may be an expected failure, and none may skip.
total=$(awk '/^Number of tests/ {print $5}' /tmp/bf-test.log)
xfail=$(awk '/^Expected fail/ {print $4}' /tmp/bf-test.log)
want=$(ls tests/*.phpt | wc -l | tr -d ' ')
if [ "${total:-0}" != "$want" ] || [ "${xfail:-0}" != 0 ] || [ "${skipped:-0}" != 0 ]; then
    echo "::error::expected $want tests, no expected failure and no skip, got ${total:-0}, ${xfail:-0} and ${skipped:-0}" >&2
    exit 1
fi
[ "${failed:-0}" = 0 ] && [ "${borked:-0}" = 0 ] && [ "${passed:-0}" -gt 0 ]
