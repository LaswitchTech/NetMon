# Operations

Operational guide for running NetMon in production or long-lived development environments.

---

## Scheduled Jobs

NetMon has no built-in scheduler or daemon. Both recurring tasks must be registered with an external scheduler (cron on Linux/macOS, Task Scheduler on Windows).

### Monitoring runner

Executes one pass of device and service checks. Recommended interval: every minute.

```cron
* * * * * php /path/to/netmon/scripts/monitor.php >> /path/to/netmon/storage/logs/monitor.log 2>&1
```

See [monitoring.md](monitoring.md) for full documentation.

---

### Retention cleanup

Deletes historical rows from `device_checks`, `service_checks`, and `notification_history` that are older than the configured retention thresholds. Recommended interval: once daily, during off-peak hours.

```cron
0 3 * * * php /path/to/netmon/scripts/cleanup.php >> /path/to/netmon/storage/logs/cleanup.log 2>&1
```

**What it cleans:**

| Table | Cutoff column | Default retention |
|-------|---------------|-------------------|
| `device_checks` | `checked_at` | 30 days |
| `service_checks` | `checked_at` | 30 days |
| `notification_history` | `created_at` | 90 days |

**What it never touches:**
- `alerts` table — alert history is always preserved
- Summary columns: `devices.status`, `devices.last_check_at`, `monitored_services.last_state`

**Configuring retention thresholds:**

Add a `monitoring` key to `config/local.php`:

```php
return [
    'monitoring' => [
        'retention' => [
            'device_checks_days'        => 60,
            'service_checks_days'       => 60,
            'notification_history_days' => 180,
        ],
    ],
];
```

Defaults are defined in `config/monitoring.php`. Local overrides take precedence.

**Manual run:**

```bash
php scripts/cleanup.php
```

Example output:
```
── Cleanup Summary
Device checks deleted:  1243
Service checks deleted: 842
Notifications deleted:  211
```

The script exits 0 on success and non-zero on any fatal error (e.g. unsupported database driver). It is safe to run repeatedly — already-deleted rows are simply not matched again.

---

## Log files

| Log | Path | Written by |
|-----|------|------------|
| Monitoring runner | `storage/logs/monitor.log` | `scripts/monitor.php` (via cron redirect) |
| Retention cleanup | `storage/logs/cleanup.log` | `scripts/cleanup.php` (via cron redirect) |
| Notification channel | `storage/logs/notifications.log` | `App\Notifications\LogChannel` |
| Application errors | `storage/logs/app.log` | `App\Core\Logger` |

Ensure `storage/logs/` is writable by the process running these scripts.

---

## Manual operations

### Forcing a full cleanup pass

Run cleanup directly and observe the deleted row counts:

```bash
php scripts/cleanup.php
```

### Running a single monitoring pass

```bash
php scripts/monitor.php --verbose
```

### Dry-run monitoring (no DB writes)

```bash
php scripts/monitor.php --dry-run
```

### Database VACUUM (SQLite)

Cleanup deletes rows but does not reclaim disk space automatically. To compact the database file after a large cleanup run:

```bash
sqlite3 data/app.db "VACUUM;"
```

Run this manually when needed — it is not automated. VACUUM can take several seconds on large databases and briefly locks the file.

---

## Future improvements

| Item | Notes |
|------|-------|
| Batch deletes | Delete in chunks (e.g. 1000 rows per DELETE) to reduce lock contention on large tables |
| Table partitioning | For very high-frequency polling, consider time-bucketed tables or a time-series store |
| Cleanup dry-run flag | `--dry-run` flag to print counts without deleting (for capacity planning) |
| Retention per-device | Per-device override of check retention (e.g. keep critical devices longer) |
