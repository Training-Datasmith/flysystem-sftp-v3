#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

PHP_IMAGE='php@sha256:f93c435550489262d463e81de0d5fe0f8228903e474441db916aa994fbfbb853'
PHP_IMAGE_DIGEST='sha256:f93c435550489262d463e81de0d5fe0f8228903e474441db916aa994fbfbb853'
ARTIFACT_DIR='/opt/cursor/artifacts'
ARTIFACT_FILE="${ARTIFACT_DIR}/suite-results.md"
RANDOM_SEED='20261008'

cleanup_lock() {
  rm -f "${ROOT}/composer.lock"
}
trap cleanup_lock EXIT

mkdir -p "${ARTIFACT_DIR}"

DOCKER=(docker)
if ! docker info >/dev/null 2>&1; then
  if sudo docker info >/dev/null 2>&1; then
    DOCKER=(sudo docker)
  fi
fi

if ! command -v docker >/dev/null 2>&1 && [ "${DOCKER[0]}" = "docker" ]; then
  apt-get update
  apt-get install -y docker.io
  if ! docker info >/dev/null 2>&1; then
    dockerd --storage-driver=vfs >/tmp/dockerd.log 2>&1 &
    for _ in $(seq 1 60); do
      docker info >/dev/null 2>&1 && break
      sleep 1
    done
  fi
fi

"${DOCKER[@]}" pull --platform=linux/amd64 "${PHP_IMAGE}"

HOST_SHA="$(git -C "${ROOT}" rev-parse HEAD)"
HOST_BRANCH="$(git -C "${ROOT}" rev-parse --abbrev-ref HEAD)"

"${DOCKER[@]}" run --rm --platform=linux/amd64 \
  -e ARTIFACT_FILE="${ARTIFACT_FILE}" \
  -e RANDOM_SEED="${RANDOM_SEED}" \
  -e HOST_SHA="${HOST_SHA}" \
  -e HOST_BRANCH="${HOST_BRANCH}" \
  -e PHP_IMAGE_DIGEST="${PHP_IMAGE_DIGEST}" \
  -v "${ROOT}:/app" \
  -v "${ARTIFACT_DIR}:/opt/cursor/artifacts" \
  -w /app \
  "${PHP_IMAGE}" \
  bash -lc '
set -euo pipefail
export DEBIAN_FRONTEND=noninteractive
export COMPOSER_ALLOW_SUPERUSER=1

if grep -q deb.debian.org /etc/apt/sources.list 2>/dev/null; then
  sed -i \
    -e "s|http://deb.debian.org/debian|http://archive.debian.org/debian|g" \
    -e "s|http://security.debian.org/debian-security|http://archive.debian.org/debian-security|g" \
    /etc/apt/sources.list
  printf "Acquire::Check-Valid-Until \"false\";\n" >/etc/apt/apt.conf.d/99no-check-valid
fi

apt-get update
apt-get install -y --no-install-recommends \
  openssh-server \
  openssh-client \
  curl \
  ca-certificates \
  git \
  unzip \
  libxml2-dev \
  libzip-dev \
  zlib1g-dev \
  libonig-dev \
  pkg-config

docker-php-ext-install sockets
docker-php-ext-install dom mbstring xml

php -m | grep -E "^(dom|mbstring|xml|xmlwriter|sockets)$" >/tmp/ext-check.txt
for ext in dom mbstring xml xmlwriter sockets; do
  php -m | grep -qx "${ext}" || { echo "missing extension: ${ext}"; exit 1; }
done

curl -fsSL -o /tmp/composer-setup.php https://getcomposer.org/installer
curl -fsSL -o /tmp/composer-setup.sig https://composer.github.io/installer.sig
php -r "exit(hash_file(\"sha384\", \"/tmp/composer-setup.php\") === trim(file_get_contents(\"/tmp/composer-setup.sig\")) ? 0 : 1);"
php /tmp/composer-setup.php --2.2 --install-dir=/usr/local/bin --filename=composer

composer update --no-interaction --prefer-dist --no-progress

PHP_VERSION="$(php -r "echo PHP_VERSION;")"
COMPOSER_VERSION="$(composer --version --no-ansi)"
OPENSSH_VERSION="$(dpkg-query -W openssh-server 2>/dev/null | cut -f2 || true)"
PKG_VERSIONS="$(composer show --no-ansi --direct 2>/dev/null | awk "{print \$1\"=\"\$2}" | tr "\n" "; ")"

run_phpunit() {
  local name="$1"
  shift
  local extra="$*"
  printf "COMMAND[%s]: php -d error_reporting=-1 vendor/bin/phpunit --do-not-cache-result%s\n" "${name}" "$( [ -n "${extra}" ] && printf " %s" "${extra}" )"
  set +e
  php -d error_reporting=-1 vendor/bin/phpunit --do-not-cache-result "$@" 2>&1 | tee "/tmp/phpunit-${name}.log"
  local rc=$?
  set -e
  echo "${rc}" >"/tmp/phpunit-${name}.rc"
  printf "EXIT[%s]: %s\n" "${name}" "${rc}"
}

run_phpunit default-1
run_phpunit default-2
run_phpunit random-1 --order-by=random --random-order-seed "${RANDOM_SEED}"
run_phpunit random-2 --order-by=random --random-order-seed "${RANDOM_SEED}"

for gate in default-1 default-2 random-1 random-2; do
  if [ "$(cat "/tmp/phpunit-${gate}.rc")" -ne 0 ]; then
    echo "expected ${gate} gate to pass" >&2
    exit 1
  fi
done

# Demonstrate stat-cache regression without the fromArray fix.
cp SftpConnectionProvider.php /tmp/SftpConnectionProvider.php.bak
php scripts/revert-stat-cache.php SftpConnectionProvider.php
set +e
php -d error_reporting=-1 vendor/bin/phpunit --do-not-cache-result \
  --filter "testDisableStatCacheFalseServesTheCachedSize|testFromArrayCopiesEveryOption" 2>&1 | tee /tmp/phpunit-stat-cache-broken.log
STAT_CACHE_DEMO_RC=$?
set -e
mv /tmp/SftpConnectionProvider.php.bak SftpConnectionProvider.php
if [ "${STAT_CACHE_DEMO_RC}" -eq 0 ]; then
  echo "expected stat-cache regression tests to fail without production fix" >&2
  exit 1
fi

php -d error_reporting=-1 vendor/bin/phpunit --do-not-cache-result \
  --filter "testDisableStatCacheFalseServesTheCachedSize|testFromArrayCopiesEveryOption"

cp SftpAdapter.php /tmp/SftpAdapter.php.bak
php scripts/revert-listcontents-cast.php SftpAdapter.php
set +e
php -d error_reporting=-1 vendor/bin/phpunit --do-not-cache-result \
  --filter testListContentsCastsNumericRawlistKeysToStringPaths 2>&1 | tee /tmp/phpunit-listcontents-cast-broken.log
LISTCONTENTS_CAST_DEMO_RC=$?
set -e
mv /tmp/SftpAdapter.php.bak SftpAdapter.php
if [ "${LISTCONTENTS_CAST_DEMO_RC}" -eq 0 ]; then
  echo "expected numeric rawlist test to fail without (string) cast" >&2
  exit 1
fi

cp SftpAdapter.php /tmp/SftpAdapter.php.bak
php scripts/revert-copy-decline-visibility.php SftpAdapter.php
set +e
php -d error_reporting=-1 vendor/bin/phpunit --do-not-cache-result \
  --filter testCopyCanDeclineRetainedVisibility 2>&1 | tee /tmp/phpunit-copy-visibility-broken.log
COPY_VISIBILITY_DEMO_RC=$?
set -e
mv /tmp/SftpAdapter.php.bak SftpAdapter.php
if [ "${COPY_VISIBILITY_DEMO_RC}" -eq 0 ]; then
  echo "expected copy decline-visibility test to fail when retain is forced" >&2
  exit 1
fi

DEFAULT_SUMMARY="$(grep -E "^OK \\(|^Tests:|^Assertions:|^Failures:|^Errors:|^Skipped:|^Incomplete:" /tmp/phpunit-default-1.log | tail -n 6)"
RANDOM_SUMMARY="$(grep -E "^OK \\(|^Tests:|^Assertions:|^Failures:|^Errors:|^Skipped:|^Incomplete:" /tmp/phpunit-random-1.log | tail -n 6)"

PHPUNIT_FAILURES="$(grep -E "^Failures:" /tmp/phpunit-default-1.log | tail -n1 | awk "{print \$2}" | sed "s/\\.$//")"
PHPUNIT_ERRORS="$(grep -E "^Errors:" /tmp/phpunit-default-1.log | tail -n1 | awk "{print \$2}" | sed "s/\\.$//")"
PHPUNIT_SKIPPED="$(grep -E "^OK \\(|^Tests:" /tmp/phpunit-default-1.log | tail -n1 | sed -nE "s/.*Skipped: ([0-9]+).*/\\1/p" | head -n1)"
PHPUNIT_INCOMPLETE="$(grep -E "^Incomplete:" /tmp/phpunit-default-1.log | tail -n1 | awk "{print \$2}" | sed "s/\\.$//")"
PHPUNIT_FAILURES="${PHPUNIT_FAILURES:-0}"
PHPUNIT_ERRORS="${PHPUNIT_ERRORS:-0}"
PHPUNIT_SKIPPED="${PHPUNIT_SKIPPED:-0}"
PHPUNIT_INCOMPLETE="${PHPUNIT_INCOMPLETE:-0}"

BRANCH="${HOST_BRANCH}"
SHA="${HOST_SHA}"
DATE="$(date -u +%Y-%m-%d)"

cat >"${ARTIFACT_FILE}" <<EOF
repo: Training-Datasmith/flysystem-sftp-v3
date: ${DATE}
branch: ${BRANCH}
sha: ${SHA}
tests: $(grep -E "^OK \\(|^Tests:" /tmp/phpunit-default-1.log | tail -n1 | sed -nE "s/^OK \\(([0-9]+) tests.*/\\1/p; s/^Tests: ([0-9]+).*/\\1/p" | head -n1)
assertions: $(grep -E "^OK \\(|^Tests:" /tmp/phpunit-default-1.log | tail -n1 | sed -nE "s/^OK \\([0-9]+ tests, ([0-9]+) assertions.*/\\1/p; s/^Tests: [0-9]+, Assertions: ([0-9]+).*/\\1/p" | head -n1)
failures: ${PHPUNIT_FAILURES}
errors: ${PHPUNIT_ERRORS}
skipped/incomplete: ${PHPUNIT_SKIPPED} skipped, ${PHPUNIT_INCOMPLETE} incomplete
php: ${PHP_VERSION}
image_digest: ${PHP_IMAGE_DIGEST}
composer: ${COMPOSER_VERSION}
openssh: ${OPENSSH_VERSION}
resolved_packages: ${PKG_VERSIONS}
extensions_verified: dom;mbstring;xml;xmlwriter;sockets
error_reporting: -1
random_seed: ${RANDOM_SEED}
commands: |
  php -d error_reporting=-1 vendor/bin/phpunit --do-not-cache-result
  php -d error_reporting=-1 vendor/bin/phpunit --do-not-cache-result
  php -d error_reporting=-1 vendor/bin/phpunit --do-not-cache-result --order-by=random --random-order-seed ${RANDOM_SEED}
  php -d error_reporting=-1 vendor/bin/phpunit --do-not-cache-result --order-by=random --random-order-seed ${RANDOM_SEED}
exit_codes: default-1=$(cat /tmp/phpunit-default-1.rc); default-2=$(cat /tmp/phpunit-default-2.rc); random-1=$(cat /tmp/phpunit-random-1.rc); random-2=$(cat /tmp/phpunit-random-2.rc)
fingerprint_literals_derivation: blob base64 Zml4dHVyZS1rZXktbWF0ZXJpYWw= (fixture-key-material); MD5 colon-hex verified via openssl dgst -md5; SHA-512 colon-hex verified via openssl dgst -sha512 (see SftpConnectionProviderFingerprintTest docblock)
notes: |
  PRODUCTION CHANGES: SftpConnectionProvider::fromArray() now forwards disableStatCache (default true).
  DEFERRED: listContents root double-slash normalization; private-key prefix OR condition; delete return handling; SSH agent; fingerprint format expansions.
  ENVIRONMENT: Digest-pinned php:8.0.2-cli (amd64), Composer 2.2 with verified installer, config.platform.php=8.0.2, composer.lock removed on exit (not committed).
  STAT_CACHE_DEMO_WITHOUT_FIX: exit ${STAT_CACHE_DEMO_RC} (non-zero expected).
  LISTCONTENTS_CAST_DEMO_WITHOUT_FIX: exit ${LISTCONTENTS_CAST_DEMO_RC} (non-zero expected).
  COPY_DECLINE_VISIBILITY_DEMO_WITHOUT_FIX: exit ${COPY_VISIBILITY_DEMO_RC} (non-zero expected).
  DEFAULT_RUN_SUMMARY:
${DEFAULT_SUMMARY}
  RANDOM_RUN_SUMMARY:
${RANDOM_SUMMARY}
EOF

rm -f /app/composer.lock
'

if [ -f "${ARTIFACT_FILE}" ]; then
  sed -i "s/^sha:.*/sha: $(git -C "${ROOT}" rev-parse HEAD)/" "${ARTIFACT_FILE}"
  sed -i "s/^branch:.*/branch: $(git -C "${ROOT}" rev-parse --abbrev-ref HEAD)/" "${ARTIFACT_FILE}"
fi

echo "Wrote ${ARTIFACT_FILE}"
