#!/usr/bin/env bash

set -Eeuo pipefail

SCRIPT_DIR=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)

SQL_SOURCE="$SCRIPT_DIR/did_optimizer.sql"
AGI_SOURCE="$SCRIPT_DIR/did_optimizer.agi"
PHP_SOURCE="$SCRIPT_DIR/admin_did_optimizer_pool.php"
QUICK_TEST_SOURCE="$SCRIPT_DIR/quick-test.sh"
DB_NAME="asterisk"
AGI_TARGET="/var/lib/asterisk/agi-bin/did_optimizer.agi"
MAINTENANCE_DIR="/usr/local/share/did-optimizer"
ROLE=""
CLEAN_INSTALL=0
SOURCE_BASE_URL="${DIDOPT_SOURCE_BASE_URL:-https://raw.githubusercontent.com/aovatalk/did-optimizer-cluster/refs/heads/main}"

die() {
    printf 'ERROR: %s\n' "$*" >&2
    exit 1
}

require_command() {
    command -v "$1" >/dev/null 2>&1 || die "Required command not found: $1"
}

download_source_file() {
    local filename="$1" target="$SCRIPT_DIR/$1" temp_file
    local -a curl_args=(--fail --location --silent --show-error)
    require_command curl
    require_command mktemp
    if [[ "${DIDOPT_CURL_INSECURE:-0}" =~ ^(1|Y|y|YES|yes|true|TRUE)$ ]]; then
        curl_args+=(--insecure)
        printf 'WARNING: downloading %s without TLS certificate verification.\n' "$filename" >&2
    fi
    temp_file=$(mktemp "$SCRIPT_DIR/.didopt-download.XXXXXX") \
        || die "Could not create a temporary download for $filename"
    if ! curl "${curl_args[@]}" "$SOURCE_BASE_URL/$filename" --output "$temp_file"; then
        rm -f -- "$temp_file"
        die "Could not download required file: $filename"
    fi
    chmod 0644 "$temp_file"
    mv -f -- "$temp_file" "$target"
    printf 'Downloaded required file: %s\n' "$target"
}

find_vicidial_path() {
    local base candidate
    for base in /srv/www/htdocs /var/www/html /var/www; do
        candidate="$base/vicidial"
        if [[ -f "$candidate/admin.php" \
              && -f "$candidate/functions.php" \
              && -f "$candidate/dbconnect_mysqli.php" ]]; then
            printf '%s\n' "$candidate"
            return 0
        fi
    done
    return 1
}

usage() {
    printf '%s\n' \
        'Usage: install_did_optimizer.sh --role database|dialer [--clean]' \
        '  database  install/upgrade shared schema only' \
        '  dialer    install AGI and web admin page on this node only' \
        '  --clean   drop optimizer data before recreating the schema'
}

while (($#)); do
    case "$1" in
        --role) [[ $# -ge 2 ]] || die '--role requires a value'; ROLE="$2"; shift 2 ;;
        --clean) CLEAN_INSTALL=1; shift ;;
        -h|--help) usage; exit 0 ;;
        *) die "Unknown argument: $1" ;;
    esac
done
[[ -n "$ROLE" ]] || die 'A role is required: --role database or --role dialer'
[[ "$ROLE" =~ ^(database|dialer)$ ]] || die "Invalid role: $ROLE"
[[ ${EUID:-$(id -u)} -eq 0 ]] || die 'Run this installer as root.'
[[ "$ROLE" == 'database' || "$CLEAN_INSTALL" == '0' ]] \
    || die '--clean is valid only with --role database.'

if [[ "$ROLE" == 'database' ]]; then
    download_source_file did_optimizer.sql
else
    download_source_file did_optimizer.agi
    download_source_file admin_did_optimizer_pool.php
    download_source_file quick-test.sh
fi

install_database() {
    require_command mysql
    [[ -r "$SQL_SOURCE" ]] || die "Missing schema: $SQL_SOURCE"
    if ((CLEAN_INSTALL)); then
        printf 'Dropping optimizer tables from shared database %s...\n' "$DB_NAME"
        mysql --protocol=socket --database="$DB_NAME" -e \
            "DROP TABLE IF EXISTS did_optimizer_assignments;
             DROP TABLE IF EXISTS did_optimizer_campaign_state;
             DROP TABLE IF EXISTS did_optimizer_pool;
             DROP TABLE IF EXISTS did_optimizer_geo_prefixes;
             DROP TABLE IF EXISTS did_optimizer_reputation_cache;"
    fi
    printf 'Applying optimizer schema to shared database %s...\n' "$DB_NAME"
    mysql --protocol=socket --database="$DB_NAME" < "$SQL_SOURCE"

    # Upgrade installations made by the original three-table release.
    column_count=$(mysql --protocol=socket --batch --skip-column-names --database="$DB_NAME" -e \
        "SELECT COUNT(*) FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA='$DB_NAME' AND TABLE_NAME='did_optimizer_assignments'
            AND COLUMN_NAME='server_ip';")
    if [[ "$column_count" == '0' ]]; then
        mysql --protocol=socket --database="$DB_NAME" -e \
            "ALTER TABLE did_optimizer_assignments
               ADD COLUMN server_ip VARCHAR(45) NOT NULL DEFAULT '' AFTER campaign_id,
               ADD KEY idx_didopt_assignment_server (server_ip, assigned_at);"
    fi
    index_count=$(mysql --protocol=socket --batch --skip-column-names --database="$DB_NAME" -e \
        "SELECT COUNT(*) FROM information_schema.STATISTICS
          WHERE TABLE_SCHEMA='$DB_NAME' AND TABLE_NAME='did_optimizer_assignments'
            AND INDEX_NAME='idx_didopt_assignment_server';")
    if [[ "$index_count" == '0' ]]; then
        mysql --protocol=socket --database="$DB_NAME" -e \
            "ALTER TABLE did_optimizer_assignments
               ADD KEY idx_didopt_assignment_server (server_ip, assigned_at);"
    fi

    table_count=$(mysql --protocol=socket --batch --skip-column-names --database="$DB_NAME" -e \
        "SELECT COUNT(*) FROM information_schema.TABLES
          WHERE TABLE_SCHEMA='$DB_NAME'
            AND TABLE_NAME IN ('did_optimizer_pool','did_optimizer_assignments',
              'did_optimizer_campaign_state','did_optimizer_geo_prefixes',
              'did_optimizer_reputation_cache');")
    [[ "$table_count" == '5' ]] \
        || die "Schema verification failed: expected 5 optimizer tables, found $table_count"
    printf 'Shared database schema ready (%s tables).\n' "$table_count"
}

install_dialer() {
    local vicidial_path php_target source_agi_hash target_agi_hash source_php_hash target_php_hash
    require_command php
    require_command install
    require_command sha256sum
    require_command awk
    require_command perl
    require_command grep
    [[ -r "$AGI_SOURCE" && -r "$PHP_SOURCE" && -r "$QUICK_TEST_SOURCE" ]] \
        || die 'AGI, PHP, or quick-test source is missing.'
    vicidial_path=$(find_vicidial_path) \
        || die 'VICIdial web installation not found in the supported web roots.'
    php_target="$vicidial_path/admin_did_optimizer_pool.php"
    [[ -d "$(dirname -- "$AGI_TARGET")" ]] \
        || die "Asterisk AGI directory does not exist: $(dirname -- "$AGI_TARGET")"
    [[ -r /etc/astguiclient.conf ]] || die '/etc/astguiclient.conf is not readable.'
    grep -Eq '^[[:space:]]*VARserver_ip[[:space:]]*=>?[[:space:]]*[^[:space:]]+' /etc/astguiclient.conf \
        || die 'VARserver_ip is missing from /etc/astguiclient.conf.'

    perl -c "$AGI_SOURCE"
    php -l "$PHP_SOURCE"
    install -o asterisk -g asterisk -m 0750 "$AGI_SOURCE" "$AGI_TARGET"
    install -o root -g root -m 0755 "$PHP_SOURCE" "$php_target"
    install -d -o root -g root -m 0755 "$MAINTENANCE_DIR"
    install -o root -g root -m 0644 "$AGI_SOURCE" "$MAINTENANCE_DIR/did_optimizer.agi"
    install -o root -g root -m 0644 "$PHP_SOURCE" "$MAINTENANCE_DIR/admin_did_optimizer_pool.php"
    install -o root -g root -m 0755 "$QUICK_TEST_SOURCE" "$MAINTENANCE_DIR/quick-test.sh"
    perl -c "$AGI_TARGET"
    php -l "$php_target"

    source_agi_hash=$(sha256sum "$AGI_SOURCE" | awk '{print $1}')
    target_agi_hash=$(sha256sum "$AGI_TARGET" | awk '{print $1}')
    source_php_hash=$(sha256sum "$PHP_SOURCE" | awk '{print $1}')
    target_php_hash=$(sha256sum "$php_target" | awk '{print $1}')
    [[ "$source_agi_hash" == "$target_agi_hash" ]] || die 'Installed AGI hash mismatch.'
    [[ "$source_php_hash" == "$target_php_hash" ]] || die 'Installed PHP hash mismatch.'
    printf 'Dialer/web node ready: AGI=%s admin=%s test=%s/quick-test.sh\n' \
        "$AGI_TARGET" "$php_target" "$MAINTENANCE_DIR"
}

[[ "$ROLE" == 'database' ]] && install_database
[[ "$ROLE" == 'dialer' ]] && install_dialer

printf '%s\n' \
    'DID optimizer installation completed successfully.' \
    "  Role: $ROLE" \
    "  Clean schema: $([[ $CLEAN_INSTALL == 1 ]] && echo Y || echo N)" \
    '  Dialplan: unchanged' \
    '' \
    'Add after call_log and immediately before Dial() on every dialer node:' \
    ' same => n,AGI(did_optimizer.agi,${campaign_id},${dialed_number},${UNIQUEID},${lead_id})' \
    ' same => n,NoOp(DID optimizer: ${DIDOPT_STATUS} ${DIDOPT_SELECTED} ${DIDOPT_REASON})'
