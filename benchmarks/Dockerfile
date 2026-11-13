# The benchmark image: the PHP of the test machine (NTS, no opcache unless a mode turns it on), the baselines, and the scripts.
# The extension under test is not in the image: harness.py builds each variant (a commit's tree) with the PHP's own
# flags and mounts it, so two commits run in the same image.
#   docker build -t bfm6bench:base --build-arg BASE=phplab:nts-8.3 benchmarks/
# DuckDB is the pinned x86-64 CLI and the pinned pdo_duckdb release; WITH_DUCKDB=0 leaves them out (arm64 smoke runs).
ARG BASE=phplab:nts-8.3
FROM ${BASE}
ARG WITH_DUCKDB=1
RUN apt-get update && apt-get install -y --no-install-recommends unzip curl ca-certificates && rm -rf /var/lib/apt/lists/*
RUN if [ "$WITH_DUCKDB" = 1 ]; then \
    curl -fsSL -o /tmp/duckdb.tgz https://github.com/duckdb/duckdb/releases/download/v2.0.0/duckdb-cli-linux-amd64.tar.gz \
 && echo "c6507206baf7793c6013dbd82368469a45f20dc0e17a6f92f204602f652ed2bc  /tmp/duckdb.tgz" | sha256sum -c - \
 && tar -xzf /tmp/duckdb.tgz -C /usr/local/bin duckdb && rm /tmp/duckdb.tgz \
 && curl -fsSL -o /tmp/pd.zip https://github.com/iliaal/pdo_duckdb/releases/download/0.9.0/php_pdo_duckdb-0.9.0_php8.3-x86_64-linux-glibc.zip \
 && echo "87d9a48d0161933a3d41202277869f2009def295e60302743e70f0913595272c  /tmp/pd.zip" | sha256sum -c - \
 && cd /tmp && unzip -q -o pd.zip pdo_duckdb.so -d "$(php-config --extension-dir)" && rm /tmp/pd.zip; \
    fi
COPY gen.php lib.php run_one.php run_duck.php duck_verify.php /bench/
WORKDIR /bench
