#!/usr/bin/env bash
# Installs the WordPress test suite.
# Usage: bash bin/install-wp-tests.sh <db-name> <db-user> <db-pass> [db-host] [wp-version] [skip-db-create]

DB_NAME=${1:-wordpress_test}
DB_USER=${2:-root}
DB_PASS=${3:-}
DB_HOST=${4:-localhost}
WP_VERSION=${5:-latest}
SKIP_DB_CREATE=${6:-false}

WP_TESTS_DIR=${WP_TESTS_DIR:-/tmp/wordpress-tests-lib}
WP_CORE_DIR=${WP_CORE_DIR:-/tmp/wordpress}

download() {
    if [ "$(which curl)" ]; then
        curl -s "$1" > "$2"
    elif [ "$(which wget)" ]; then
        wget -nv -O "$2" "$1"
    fi
}

if [[ $WP_VERSION =~ ^[0-9]+\.[0-9]+$ ]]; then
    WP_TESTS_TAG="branches/$WP_VERSION"
elif [[ $WP_VERSION == 'nightly' || $WP_VERSION == 'trunk' ]]; then
    WP_TESTS_TAG="trunk"
else
    # Pull the latest version from the API.
    if [[ $WP_VERSION == 'latest' ]]; then
        local_version="$(download https://api.wordpress.org/core/version-check/1.7/ - 2>/dev/null | grep -o '"version":"[^"]*"' | head -1 | grep -o '[0-9.]*')"
        if [[ -z "$local_version" ]]; then
            WP_TESTS_TAG="trunk"
        else
            WP_TESTS_TAG="tags/$local_version"
        fi
    fi
fi

set -ex

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
        if [ $WP_VERSION == 'latest' ]; then
            local_version=''
        fi
        download "https://wordpress.org/wordpress-$WP_VERSION.tar.gz" /tmp/wordpress.tar.gz
        tar --strip-components=1 -zxmf /tmp/wordpress.tar.gz -C "$WP_CORE_DIR"
    fi
}

install_test_suite() {
    if [ -d "$WP_TESTS_DIR" ]; then
        return
    fi

    mkdir -p "$WP_TESTS_DIR"

    local download_url="https://develop.svn.wordpress.org/${WP_TESTS_TAG}/tests/phpunit/includes/"
    svn co --trust-server-cert "https://develop.svn.wordpress.org/${WP_TESTS_TAG}/tests/phpunit/includes/" "$WP_TESTS_DIR/includes"
    svn co --trust-server-cert "https://develop.svn.wordpress.org/${WP_TESTS_TAG}/tests/phpunit/data/" "$WP_TESTS_DIR/data"

    download "https://develop.svn.wordpress.org/${WP_TESTS_TAG}/wp-tests-config-sample.php" "$WP_TESTS_DIR/wp-tests-config.php"
    # Remove trailing slash.
    WP_CORE_DIR=$(echo $WP_CORE_DIR | sed "s:/\+$::")
    sed -i "s:dirname( __FILE__ ) . '/src/':'$WP_CORE_DIR/':" "$WP_TESTS_DIR/wp-tests-config.php"
    sed -i "s/youremptytestdbnamehere/$DB_NAME/" "$WP_TESTS_DIR/wp-tests-config.php"
    sed -i "s/yourusernamehere/$DB_USER/" "$WP_TESTS_DIR/wp-tests-config.php"
    sed -i "s/yourpasswordhere/$DB_PASS/" "$WP_TESTS_DIR/wp-tests-config.php"
    sed -i "s|localhost|$DB_HOST|" "$WP_TESTS_DIR/wp-tests-config.php"
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
