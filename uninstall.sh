#!/usr/bin/env bash

set -Eeuo pipefail

DB_NAME="asterisk"
AGI_TARGET="/var/lib/asterisk/agi-bin/did_optimizer.agi"
FASTAGI_SERVICE_NAME="did-optimizer-fastagi.service"
FASTAGI_SERVICE_TARGET="/etc/systemd/system/$FASTAGI_SERVICE_NAME"
MAINTENANCE_DIR="/usr/local/share/did-optimizer"
UNINSTALL_TARGET="/usr/local/sbin/uninstall-did-optimizer"
ROLE=""
PURGE_DATA=0
ASSUME_YES=0

die() {
    printf 'ERROR: %s\n' "$*" >&2
    exit 1
}

require_command() {
    command -v "$1" >/dev/null 2>&1 || die "Required command not found: $1"
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
        'Usage: uninstall.sh --role dialer|database|all [--purge-data] [--yes]' \
        '  dialer       stop FastAGI and remove files from this dialer/web node' \
        '  database     drop shared optimizer tables; requires --purge-data' \
        '  all          remove this node and drop shared tables; requires --purge-data' \
        '  --purge-data permanently delete all shared optimizer data' \
        '  --yes        skip the typed confirmation used with --purge-data' \
        '' \
        'Dialplan configuration is never edited automatically.'
}

while (($#)); do
    case "$1" in
        --role) [[ $# -ge 2 ]] || die '--role requires a value'; ROLE="$2"; shift 2 ;;
        --purge-data) PURGE_DATA=1; shift ;;
        --yes) ASSUME_YES=1; shift ;;
        -h|--help) usage; exit 0 ;;
        *) die "Unknown argument: $1" ;;
    esac
done

[[ -n "$ROLE" ]] || die 'A role is required: dialer, database, or all'
[[ "$ROLE" =~ ^(dialer|database|all)$ ]] || die "Invalid role: $ROLE"
[[ ${EUID:-$(id -u)} -eq 0 ]] || die 'Run this uninstaller as root.'
if [[ "$ROLE" == 'dialer' && "$PURGE_DATA" == '1' ]]; then
    die '--purge-data is valid only with --role database or --role all'
fi
if [[ "$ROLE" =~ ^(database|all)$ && "$PURGE_DATA" != '1' ]]; then
    die 'Database removal requires the explicit --purge-data option'
fi
if [[ "$ASSUME_YES" == '1' && "$PURGE_DATA" != '1' ]]; then
    die '--yes is valid only with --purge-data'
fi

confirm_database_purge() {
    require_command mysql
    if [[ "$ASSUME_YES" != '1' ]]; then
        [[ -t 0 ]] || die 'Interactive confirmation requires a terminal; use --yes for automation'
        printf 'Type DROP %s to permanently delete all DID optimizer data: ' "$DB_NAME" >&2
        read -r confirmation
        [[ "$confirmation" == "DROP $DB_NAME" ]] || die 'Database purge cancelled'
    fi
}

remove_dialer() {
    local vicidial_path php_target dialplan_references
    require_command systemctl

    dialplan_references=$(grep -RhsE --include='*.conf' \
        'agi://127\.0\.0\.1:4578/did_optimizer|AGI\(did_optimizer\.agi' \
        /etc/asterisk 2>/dev/null || true)

    systemctl disable --now "$FASTAGI_SERVICE_NAME" >/dev/null 2>&1 || true
    rm -f -- "$FASTAGI_SERVICE_TARGET"
    systemctl daemon-reload
    systemctl reset-failed "$FASTAGI_SERVICE_NAME" >/dev/null 2>&1 || true

    rm -f -- "$AGI_TARGET"
    if vicidial_path=$(find_vicidial_path); then
        php_target="$vicidial_path/admin_did_optimizer_pool.php"
        rm -f -- "$php_target"
    else
        printf '%s\n' 'WARNING: VICIdial web path was not detected; no admin PHP file was removed.' >&2
    fi

    rm -f -- \
        "$MAINTENANCE_DIR/did_optimizer.agi" \
        "$MAINTENANCE_DIR/did-optimizer-fastagi.service" \
        "$MAINTENANCE_DIR/admin_did_optimizer_pool.php" \
        "$MAINTENANCE_DIR/quick-test.sh" \
        "$MAINTENANCE_DIR/uninstall.sh"
    rmdir -- "$MAINTENANCE_DIR" 2>/dev/null || true

    printf '%s\n' 'Dialer/web optimizer service and installed files removed.'
    if [[ -n "$dialplan_references" ]]; then
        printf '%s\n' \
            'WARNING: DID optimizer references remain in /etc/asterisk.' \
            'Remove the FastAGI/AGI line from the VICIdial carrier dialplan and reload Asterisk.' >&2
    fi
}

purge_database() {
    mysql --protocol=socket --database="$DB_NAME" -e \
        "DROP TABLE IF EXISTS did_optimizer_assignments;
         DROP TABLE IF EXISTS did_optimizer_campaign_state;
         DROP TABLE IF EXISTS did_optimizer_pool;
         DROP TABLE IF EXISTS did_optimizer_geo_npa_centroids;
         DROP TABLE IF EXISTS did_optimizer_geo_prefixes;
         DROP TABLE IF EXISTS did_optimizer_reputation_cache;
         DROP TABLE IF EXISTS did_optimizer_settings;"
    printf 'Shared DID optimizer tables and data removed from database %s.\n' "$DB_NAME"
}

[[ "$ROLE" =~ ^(database|all)$ ]] && confirm_database_purge
[[ "$ROLE" =~ ^(dialer|all)$ ]] && remove_dialer
[[ "$ROLE" =~ ^(database|all)$ ]] && purge_database

rm -f -- "$UNINSTALL_TARGET"
printf '%s\n' 'DID optimizer uninstall completed.'
