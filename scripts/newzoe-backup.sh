#!/usr/bin/env bash
# Encrypted offsite backup for the NewZoe server.
#
# This script is installed outside the application release so it remains
# available while a release is being replaced. It expects a root-only config at
# /etc/newzoe-backup/backup.env.

set -Eeuo pipefail

readonly CONFIG_FILE="${NEWZOE_BACKUP_CONFIG:-/etc/newzoe-backup/backup.env}"
readonly STATE_DIR="${NEWZOE_BACKUP_STATE_DIR:-/var/lib/newzoe-backup}"
readonly LOG_DIR="${NEWZOE_BACKUP_LOG_DIR:-/var/log/newzoe-backup}"
readonly LOCK_FILE="/run/lock/newzoe-backup.lock"

usage() {
    cat <<'EOF'
Usage: newzoe-backup [--preflight]

Creates consistent database snapshots, then stores encrypted backups through
restic and an rclone Google Drive remote. The command must run as root.
EOF
}

log() {
    printf '%s %s\n' "$(date --iso-8601=seconds)" "$*"
}

die() {
    log "ERROR: $*"
    exit 1
}

require_root() {
    [[ "${EUID}" -eq 0 ]] || die "run this command as root"
}

load_configuration() {
    [[ -f "${CONFIG_FILE}" ]] || die "missing ${CONFIG_FILE}"
    # shellcheck disable=SC1090
    source "${CONFIG_FILE}"

    : "${RCLONE_CONFIG:?RCLONE_CONFIG is required}"
    : "${RCLONE_REMOTE:?RCLONE_REMOTE is required}"
    : "${RESTIC_PASSWORD_FILE:?RESTIC_PASSWORD_FILE is required}"
    : "${RESTIC_REPOSITORY:?RESTIC_REPOSITORY is required}"

    [[ -f "${RCLONE_CONFIG}" ]] || die "missing rclone config: ${RCLONE_CONFIG}"
    [[ -f "${RESTIC_PASSWORD_FILE}" ]] || die "missing restic password file"
    [[ "$(stat -c '%a' "${RCLONE_CONFIG}")" -le 600 ]] || die "rclone config permissions are too broad"
    [[ "$(stat -c '%a' "${RESTIC_PASSWORD_FILE}")" -le 600 ]] || die "restic password file permissions are too broad"

    export RCLONE_CONFIG RESTIC_PASSWORD_FILE RESTIC_REPOSITORY
    export RESTIC_CACHE_DIR="${RESTIC_CACHE_DIR:-${STATE_DIR}/cache}"
    export RESTIC_PROGRESS_FPS="${RESTIC_PROGRESS_FPS:-0}"
}

check_remote() {
    command -v rclone >/dev/null || die "rclone is not installed"
    command -v restic >/dev/null || die "restic is not installed"
    rclone --config "${RCLONE_CONFIG}" listremotes | grep -Fxq "${RCLONE_REMOTE}:" || \
        die "rclone remote '${RCLONE_REMOTE}' is not configured"
    rclone --config "${RCLONE_CONFIG}" about "${RCLONE_REMOTE}:" >/dev/null || \
        die "cannot reach the configured Google Drive remote"
}

sqlite_snapshot() {
    local source_path="$1"
    local destination_path="$2"

    [[ -f "${source_path}" ]] || return 0
    log "Creating SQLite snapshot: ${source_path}"
    python3 - "${source_path}" "${destination_path}" <<'PY'
import sqlite3
import sys

source_path, destination_path = sys.argv[1:]
source = sqlite3.connect(f"file:{source_path}?mode=ro", uri=True)
destination = sqlite3.connect(destination_path)
try:
    source.backup(destination)
finally:
    destination.close()
    source.close()
PY
}

container_id() {
    local project="$1"
    local service="$2"
    docker ps -q \
        --filter "label=com.docker.compose.project=${project}" \
        --filter "label=com.docker.compose.service=${service}" \
        | head -n 1
}

dump_mariadb() {
    local output_path="$1"
    local database_container
    database_container="$(container_id dujiaoka database)"
    [[ -n "${database_container}" ]] || die "Dujiaoka MariaDB container is not running"

    log "Creating Dujiaoka MariaDB logical dump"
    docker exec "${database_container}" sh -c \
        'exec mariadb-dump -u"$MARIADB_USER" -p"$MARIADB_PASSWORD" --single-transaction --routines --events --databases "$MARIADB_DATABASE"' \
        > "${output_path}"
    [[ -s "${output_path}" ]] || die "Dujiaoka MariaDB dump is empty"
}

dump_sub2api_postgres() {
    local output_path="$1"
    local database_container
    database_container="$(docker ps -q --filter 'name=^/sub2api-postgres$' | head -n 1)"
    [[ -n "${database_container}" ]] || return 0

    log "Creating Sub2API PostgreSQL logical dump"
    docker exec "${database_container}" sh -c \
        'exec pg_dumpall -U "${POSTGRES_USER:-postgres}"' \
        > "${output_path}"
    [[ -s "${output_path}" ]] || die "Sub2API PostgreSQL dump is empty"
}

append_path_if_present() {
    local path="$1"
    [[ -e "${path}" ]] && BACKUP_PATHS+=("${path}")
}

run_preflight() {
    require_root
    load_configuration
    check_remote
    log "Preflight passed: Google Drive remote and backup credentials are available"
}

run_backup() {
    require_root
    load_configuration
    install -d -m 700 "${STATE_DIR}" "${STATE_DIR}/cache" "${LOG_DIR}"
    exec 9>"${LOCK_FILE}"
    flock -n 9 || {
        log "Another backup is already running; skipping this invocation"
        exit 0
    }

    check_remote

    # A forced stop can bypass EXIT traps. Remove old staging directories once
    # the backup lock proves that no other backup run is using them.
    find "${STATE_DIR}/runs" -mindepth 1 -maxdepth 1 -type d -mmin +30 \
        -exec rm -rf -- {} + 2>/dev/null || true

    local run_id run_dir log_file
    run_id="$(date +%Y%m%dT%H%M%SZ)"
    run_dir="${STATE_DIR}/runs/${run_id}"
    log_file="${LOG_DIR}/backup.log"
    install -d -m 700 "${run_dir}"
    touch "${log_file}"
    chmod 600 "${log_file}"
    exec > >(tee -a "${log_file}") 2>&1

    cleanup_run() {
        local status=$?
        if [[ -n "${run_dir:-}" && -d "${run_dir}" ]]; then
            rm -rf -- "${run_dir}"
        fi
        trap - EXIT INT TERM
        exit "${status}"
    }
    trap cleanup_run EXIT INT TERM

    log "Starting encrypted offsite backup run ${run_id}"
    dump_mariadb "${run_dir}/dujiaoka-mariadb.sql"
    dump_sub2api_postgres "${run_dir}/sub2api-postgres.sql"
    sqlite_snapshot /opt/gpt-upi/cdk.sqlite3 "${run_dir}/gpt-upi.sqlite3"
    sqlite_snapshot /opt/jiema/data/jiema.db "${run_dir}/jiema.sqlite3"
    sqlite_snapshot /opt/ycj-newzoe/auth.sqlite3 "${run_dir}/ycj-auth.sqlite3"

    cat > "${run_dir}/manifest.txt" <<EOF
created_at=$(date --iso-8601=seconds)
hostname=$(hostname -f 2>/dev/null || hostname)
release=$(readlink -f /opt/dujiaoka 2>/dev/null || true)
EOF

    local -a BACKUP_PATHS=()
    append_path_if_present /etc
    append_path_if_present /root
    append_path_if_present /home/ubuntu
    append_path_if_present /opt
    append_path_if_present /var/lib/random-mailbox
    append_path_if_present /var/lib/docker/volumes/dujiaoka_app_storage/_data
    append_path_if_present /var/lib/docker/volumes/dujiaoka_uploads/_data
    BACKUP_PATHS+=("${run_dir}")

    [[ "${#BACKUP_PATHS[@]}" -gt 1 ]] || die "no backup source paths were found"

    if ! restic snapshots --latest 1 >/dev/null 2>&1; then
        log "Initializing a new restic repository"
        restic init
    fi

    log "Uploading backup snapshot"
    restic backup \
        --tag newzoe \
        --tag "host:$(hostname -s)" \
        --exclude='/opt/RoxyBrowser' \
        --exclude='/opt/**/node_modules' \
        --exclude='/opt/**/.venv' \
        --exclude='/opt/**/venv' \
        --exclude='/opt/sub2api/postgres_data' \
        --exclude='/opt/sub2api/redis_data' \
        --exclude='/opt/dujiaoka' \
        --exclude='/opt/newzoe-pay' \
        "${BACKUP_PATHS[@]}"

    log "Applying retention policy: 14 daily, 8 weekly, 12 monthly snapshots"
    restic forget --tag newzoe --keep-daily 14 --keep-weekly 8 --keep-monthly 12 --prune
    log "Backup run ${run_id} finished successfully"
}

case "${1:-}" in
    '')
        run_backup
        ;;
    --preflight)
        run_preflight
        ;;
    --help|-h)
        usage
        ;;
    *)
        usage >&2
        exit 2
        ;;
esac
