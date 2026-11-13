#!/usr/bin/env bash
# Builds an NTS, non-debug PHP from a pinned source tarball, then runs phpt.sh against it unchanged.
# For a PHP line without an official NTS image: php:8.6-rc-cli is a ZTS build (checked 2026-11-12).
# env: PHP_VERSION, PHP_URL, PHP_SHA256. Called from .github/workflows/ci.yml in debian:trixie-slim,
# and run unchanged on any Linux host.
set -euo pipefail
: "${PHP_VERSION:?}" "${PHP_URL:?}" "${PHP_SHA256:?}"

# The phpize dependencies of the official images; phpt.sh installs them again, which is a no-op.
export PHPIZE_DEPS="autoconf dpkg-dev file g++ gcc libc-dev make pkg-config re2c"
export DEBIAN_FRONTEND=noninteractive
apt-get update -qq
# shellcheck disable=SC2086
apt-get install -y -qq --no-install-recommends $PHPIZE_DEPS ca-certificates curl xz-utils libxml2-dev libsqlite3-dev >/dev/null

src=$(pwd)
cd /usr/src
curl -fsSL -o php.tar.xz "$PHP_URL"
echo "${PHP_SHA256}  php.tar.xz" | sha256sum -c -
mkdir php && tar xf php.tar.xz -C php --strip-components=1
cd php
./configure --prefix=/usr/local --with-config-file-path=/usr/local/etc/php --with-config-file-scan-dir=/usr/local/etc/php/conf.d \
    --disable-all --enable-cli --disable-cgi --disable-phpdbg >/dev/null
make -j"$(nproc)" >/dev/null
make install >/dev/null
cp run-tests.php /usr/local/lib/php/build/run-tests.php
mkdir -p /usr/local/etc/php/conf.d

php -r 'exit(PHP_VERSION === getenv("PHP_VERSION") && !PHP_ZTS && !PHP_DEBUG ? 0 : 1);' \
    || { echo "::error::expected PHP ${PHP_VERSION} NTS non-debug, got $(php -r 'echo PHP_VERSION, PHP_ZTS ? " ZTS" : " NTS", PHP_DEBUG ? " debug" : "";')" >&2; exit 1; }

cd "$src"
bash .github/ci/phpt.sh
