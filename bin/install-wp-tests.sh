#!/usr/bin/env bash
# Installs the WordPress test suite using only curl/tar — no svn required.
# Usage: bash bin/install-wp-tests.sh <db-name> <db-user> <db-pass> [db-host] [wp-version] [skip-db-create]

set -euo pipefail

# Helper: escape a string for safe use as a sed replacement value.
sed_escape() {
  printf '%s' "$1" | sed 's/[\/&]/\\&/g'
}

DB_NAME=${1:-wordpress_test}
DB_USER=${2:-root}
DB_PASS=${3:-}
DB_HOST=${4:-localhost}
WP_VERSION=${5:-latest}
SKIP_DB_CREATE=${6:-false}

WP_TESTS_DIR=${WP_TESTS_DIR:-/tmp/wordpress-tests-lib}
WP_CORE_DIR=${WP_CORE_DIR:-/tmp/wordpress}

# Download a URL to a file, or to stdout when $2 is "-".
download() {
    if [ "$(which curl)" ]; then
        if [ "$2" = "-" ]; then
            curl -sSL "$1"
        else
            curl -sSL "$1" > "$2"
        fi
    elif [ "$(which wget)" ]; then
        wget -nv -O "$2" "$1"
    fi
}

# Resolve the SVN tag (used to build raw GitHub URLs — no svn binary needed).
if [[ $WP_VERSION =~ ^[0-9]+\.[0-9]+$ ]]; then
    WP_TESTS_TAG="branches/$WP_VERSION"
elif [[ $WP_VERSION == 'nightly' || $WP_VERSION == 'trunk' ]]; then
    WP_TESTS_TAG="trunk"
else
    if [[ $WP_VERSION == 'latest' ]]; then
        # || true: grep exits 1 on no match; that must not abort the script.
        local_version="$(download "https://api.wordpress.org/core/version-check/1.7/" - 2>/dev/null \
            | grep -o '"version":"[^"]*"' | head -1 | grep -o '[0-9.]*' || true)"
        if [[ -z "$local_version" ]]; then
            WP_TESTS_TAG="trunk"
        else
            WP_TESTS_TAG="tags/$local_version"
        fi
    fi
fi

set -x

install_wp() {
    if [ -d "$WP_CORE_DIR" ]; then
        return
    fi

    mkdir -p "$WP_CORE_DIR"
    if [[ $WP_VERSION == 'nightly' || $WP_VERSION == 'trunk' ]]; then
        mkdir -p /tmp/wordpress-nightly
        download https://wordpress.org/nightly-builds/wordpress-latest.zip /tmp/wordpress-nightly/wordpress-nightly.zip
        unzip -q /tmp/wordpress-nightly/wordpress-nightly.zip -d /tmp/wordpress-nightly/
        mv /tmp/wordpress-nightly/wordpress/* "$WP_CORE_DIR"
    else
        if [ "$WP_VERSION" == 'latest' ]; then
            local_version=''
        fi
        download "https://wordpress.org/wordpress-${WP_VERSION}.tar.gz" /tmp/wordpress.tar.gz
        tar --strip-components=1 -zxmf /tmp/wordpress.tar.gz -C "$WP_CORE_DIR"
    fi
}

install_test_suite() {
    if [ -d "$WP_TESTS_DIR" ]; then
        return
    fi

    mkdir -p "$WP_TESTS_DIR"

    # Download the test suite as a zip archive from the WordPress develop GitHub
    # mirror — no svn binary required.
    local zip_url
    if [[ "$WP_TESTS_TAG" == "trunk" ]]; then
        zip_url="https://github.com/WordPress/wordpress-develop/archive/refs/heads/trunk.zip"
    elif [[ "$WP_TESTS_TAG" == branches/* ]]; then
        local branch="${WP_TESTS_TAG#branches/}"
        zip_url="https://github.com/WordPress/wordpress-develop/archive/refs/heads/${branch}.zip"
    else
        local tag="${WP_TESTS_TAG#tags/}"
        zip_url="https://github.com/WordPress/wordpress-develop/archive/refs/tags/${tag}.zip"
    fi

    download "$zip_url" /tmp/wp-develop.zip

    # Validate the zip before extracting. GitHub returns an HTML 404 page (not a
    # zip) when a tag doesn't exist yet, which causes unzip to fail with a
    # confusing error. If the download isn't a valid zip, fall back to trunk.
    if ! unzip -t /tmp/wp-develop.zip > /dev/null 2>&1; then
        echo "Warning: '$zip_url' is not a valid zip (tag may not exist yet). Falling back to trunk."
        rm -f /tmp/wp-develop.zip
        download "https://github.com/WordPress/wordpress-develop/archive/refs/heads/trunk.zip" /tmp/wp-develop.zip
    fi

    unzip -q /tmp/wp-develop.zip -d /tmp/wp-develop-extracted/

    # The zip extracts to wordpress-develop-<ref>/ — find the actual dir name.
    local extracted_dir
    extracted_dir="$(find /tmp/wp-develop-extracted -maxdepth 1 -mindepth 1 -type d | head -1)"

    if [[ -z "$extracted_dir" ]]; then
        echo "Error: could not find extracted WordPress develop directory." >&2
        exit 1
    fi

    mkdir -p "$WP_TESTS_DIR/includes" "$WP_TESTS_DIR/data"
    cp -r "${extracted_dir}/tests/phpunit/includes/." "$WP_TESTS_DIR/includes/"
    cp -r "${extracted_dir}/tests/phpunit/data/."     "$WP_TESTS_DIR/data/"
    cp    "${extracted_dir}/wp-tests-config-sample.php" "$WP_TESTS_DIR/wp-tests-config.php"

    rm -rf /tmp/wp-develop.zip /tmp/wp-develop-extracted

    # Patch wp-tests-config.php with local paths and credentials.
    WP_CORE_DIR="${WP_CORE_DIR%%/}"   # strip trailing slash

    # Escape all substitution values so special characters in paths/passwords
    # cannot break the sed expression or inject additional commands.
    local esc_core_dir esc_db_name esc_db_user esc_db_pass esc_db_host
    esc_core_dir=$(sed_escape "${WP_CORE_DIR}/")
    esc_db_name=$(sed_escape "${DB_NAME}")
    esc_db_user=$(sed_escape "${DB_USER}")
    esc_db_pass=$(sed_escape "${DB_PASS}")
    esc_db_host=$(sed_escape "${DB_HOST}")

    # macOS sed requires an explicit (possibly empty) backup extension with -i.
    if [[ "$(uname)" == "Darwin" ]]; then
        sed -i '' "s:dirname( __FILE__ ) . '/src/':'${esc_core_dir}':" "$WP_TESTS_DIR/wp-tests-config.php"
        sed -i '' "s/youremptytestdbnamehere/${esc_db_name}/"  "$WP_TESTS_DIR/wp-tests-config.php"
        sed -i '' "s/yourusernamehere/${esc_db_user}/"         "$WP_TESTS_DIR/wp-tests-config.php"
        sed -i '' "s/yourpasswordhere/${esc_db_pass}/"         "$WP_TESTS_DIR/wp-tests-config.php"
        sed -i '' "s|localhost|${esc_db_host}|"                "$WP_TESTS_DIR/wp-tests-config.php"
    else
        sed -i "s:dirname( __FILE__ ) . '/src/':'${esc_core_dir}':" "$WP_TESTS_DIR/wp-tests-config.php"
        sed -i "s/youremptytestdbnamehere/${esc_db_name}/"  "$WP_TESTS_DIR/wp-tests-config.php"
        sed -i "s/yourusernamehere/${esc_db_user}/"         "$WP_TESTS_DIR/wp-tests-config.php"
        sed -i "s/yourpasswordhere/${esc_db_pass}/"         "$WP_TESTS_DIR/wp-tests-config.php"
        sed -i "s|localhost|${esc_db_host}|"                "$WP_TESTS_DIR/wp-tests-config.php"
    fi
}

create_db() {
    if [ "$SKIP_DB_CREATE" = "true" ]; then
        return
    fi
    mysqladmin create "$DB_NAME" --user="$DB_USER" --password="$DB_PASS" --host="$DB_HOST" 2>/dev/null || true
}

install_wp
install_test_suite
create_db
