# NewZoe Server Backup

The production backup is a `restic` repository stored through an `rclone`
Google Drive remote. Data is encrypted on the server before upload, so the
Drive account does not contain readable database dumps or mail data.

## Included Data

- Consistent logical dumps for Dujiaoka MariaDB and Sub2API PostgreSQL.
- Consistent SQLite snapshots for the active GPT-UPI, Jiema, and YCJ databases.
- Mail history in `/var/lib/random-mailbox`.
- Application sources, persistent uploads, Nginx, TLS certificates, systemd
  services, and user-owned application configuration.

Docker database and Redis data directories are intentionally excluded. A live
database volume must not be restored from a blind file copy when a logical dump
is available.

## Drive Configuration

Use a separate Google account dedicated to backups. If storage comes from a
Google One family plan, add that account to the family group before authorizing
the Drive remote.

Create a private Google Cloud OAuth desktop client and configure its consent
screen for production use. rclone's shared Google client is being retired in
2026, so the server uses its own Client ID. Restrict the remote to the
`drive.file` scope and let rclone create `NewZoe-Server-Backups` in My Drive.

The root-owned remote configuration is stored at
`/etc/newzoe-backup/rclone.conf`. The restic password at
`/etc/newzoe-backup/restic-password` is required for recovery; keep an offline
copy before relying on the backup.

## Installation

Deploy the files as follows:

```bash
sudo install -m 0755 scripts/newzoe-backup.sh /usr/local/sbin/newzoe-backup
sudo install -m 0644 scripts/newzoe-backup.service /etc/systemd/system/newzoe-backup.service
sudo install -m 0644 scripts/newzoe-backup.timer /etc/systemd/system/newzoe-backup.timer
sudo install -m 0644 scripts/newzoe-backup-logrotate /etc/logrotate.d/newzoe-backup
sudo install -d -m 0700 /etc/newzoe-backup /var/lib/newzoe-backup /var/log/newzoe-backup
sudo install -m 0600 scripts/newzoe-backup.env.example /etc/newzoe-backup/backup.env
sudo systemctl daemon-reload
```

After adding the OAuth configuration and restic password, verify the remote and
run the first snapshot manually:

```bash
sudo newzoe-backup --preflight
sudo systemctl start newzoe-backup.service
sudo systemctl enable --now newzoe-backup.timer
```

The timer runs at about 03:30 Asia/Shanghai, with up to 30 minutes of random
delay. It retains 14 daily, 8 weekly, and 12 monthly snapshots.

## Verification And Recovery

Run metadata verification after the first successful backup:

```bash
sudo -E restic snapshots
sudo -E restic check
```

Restore a snapshot into an empty directory before using it for recovery:

```bash
sudo -E restic restore latest --target /srv/newzoe-restore-test
```

Set `RCLONE_CONFIG`, `RESTIC_REPOSITORY`, and `RESTIC_PASSWORD_FILE` from
`/etc/newzoe-backup/backup.env` in the current shell before invoking standalone
restic commands.
