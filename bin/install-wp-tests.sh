#!/usr/bin/env bash

set -euo pipefail

DB_NAME="${1-wordpress_test}"
DB_USER="${2-root}"
DB_PASS="${3-}"
DB_HOST="${4-localhost}"
WP_VERSION="${5-latest}"
WP_TESTS_DIR="${WP_TESTS_DIR-/tmp/wordpress-tests-lib}"
WP_CORE_DIR="${WP_CORE_DIR-/tmp/wordpress}"
WC_VERSION="${WC_VERSION-latest-stable}"

if [[ "${DB_HOST}" == "localhost" ]]; then
	DB_HOST="127.0.0.1"
fi

download() {
	local url="$1"
	local destination="$2"

	if command -v curl >/dev/null 2>&1; then
		curl --fail --location --retry 3 --silent --show-error "${url}" --output "${destination}"
	elif command -v wget >/dev/null 2>&1; then
		wget --quiet "${url}" --output-document="${destination}"
	else
		printf '%s\n' 'Error: curl or wget is required.' >&2
		exit 1
	fi
}

mkdir -p "${WP_TESTS_DIR}" "${WP_CORE_DIR}"

if [[ "${WP_VERSION}" == "latest" ]]; then
	WP_ARCHIVE_URL="https://wordpress.org/latest.tar.gz"
	DEVELOP_REF=""
else
	WP_ARCHIVE_URL="https://wordpress.org/wordpress-${WP_VERSION}.tar.gz"
	DEVELOP_REF="${WP_VERSION}"
fi

if [[ ! -f "${WP_CORE_DIR}/wp-settings.php" ]]; then
	WP_ARCHIVE="$(mktemp -t wordpress.XXXXXX.tar.gz)"
	download "${WP_ARCHIVE_URL}" "${WP_ARCHIVE}"
	tar --strip-components=1 --extract --gzip --file="${WP_ARCHIVE}" --directory="${WP_CORE_DIR}"
	rm -f "${WP_ARCHIVE}"
fi

if [[ -z "${DEVELOP_REF}" ]]; then
	DEVELOP_REF="$(php -r 'require $argv[1]; echo $wp_version;' "${WP_CORE_DIR}/wp-includes/version.php")"
fi

if [[ ! -f "${WP_TESTS_DIR}/includes/bootstrap.php" ]]; then
	TESTS_TEMP="$(mktemp -d -t wordpress-tests.XXXXXX)"
	git clone --branch "${DEVELOP_REF}" --depth 1 --filter=blob:none --no-checkout --quiet https://github.com/WordPress/wordpress-develop.git "${TESTS_TEMP}/wordpress-develop"
	git -C "${TESTS_TEMP}/wordpress-develop" sparse-checkout set --no-cone /tests/phpunit/ /wp-tests-config-sample.php
	git -C "${TESTS_TEMP}/wordpress-develop" checkout --quiet
	cp -R "${TESTS_TEMP}/wordpress-develop/tests/phpunit/." "${WP_TESTS_DIR}/"
	cp "${TESTS_TEMP}/wordpress-develop/wp-tests-config-sample.php" "${WP_TESTS_DIR}/"
	rm -rf "${TESTS_TEMP}"
fi

if [[ ! -f "${WP_CORE_DIR}/wp-content/plugins/woocommerce/woocommerce.php" ]]; then
	WC_ARCHIVE="$(mktemp -t woocommerce.XXXXXX.zip)"
	download "https://downloads.wordpress.org/plugin/woocommerce.${WC_VERSION}.zip" "${WC_ARCHIVE}"
	mkdir -p "${WP_CORE_DIR}/wp-content/plugins"
	unzip -q "${WC_ARCHIVE}" -d "${WP_CORE_DIR}/wp-content/plugins"
	rm -f "${WC_ARCHIVE}"
fi

sed \
	-e "s/youremptytestdbnamehere/${DB_NAME}/" \
	-e "s/yourusernamehere/${DB_USER}/" \
	-e "s/yourpasswordhere/${DB_PASS}/" \
	-e "s|localhost|${DB_HOST}|" \
	-e "s|dirname( __FILE__ ) . '/src/'|'${WP_CORE_DIR}/'|" \
	"${WP_TESTS_DIR}/wp-tests-config-sample.php" > "${WP_TESTS_DIR}/wp-tests-config.php"

php -r '$mysqli = @new mysqli($argv[1], $argv[2], $argv[3]); if ($mysqli->connect_errno) { fwrite(STDERR, "Database connection failed: " . $mysqli->connect_error . PHP_EOL); exit(1); } $name = str_replace("`", "``", $argv[4]); if (!$mysqli->query("CREATE DATABASE IF NOT EXISTS `{$name}`")) { fwrite(STDERR, "Database creation failed: " . $mysqli->error . PHP_EOL); exit(1); }' "${DB_HOST}" "${DB_USER}" "${DB_PASS}" "${DB_NAME}"

printf '%s\n' "WordPress tests are ready in ${WP_TESTS_DIR}."
