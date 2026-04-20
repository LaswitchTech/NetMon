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

## Development seeds

Seed scripts populate realistic sample data for local development and UI testing.
They are never run during installation and must never be run against production data.

### Available seeds

| File | Class | Depends on | Purpose |
|------|-------|------------|---------|
| `AdminBootstrap.php` | `AdminBootstrap` | — | Creates the default admin user |
| `DeviceSeed.php` | `DeviceSeed` | — | 3 sample devices (Core Router, Distribution Switch, File Server) |
| `DiscoverySeed.php` | `DiscoverySeed` | DeviceSeed | 2 discovery jobs + 10 findings (matched/pending/ignored) |
| `MonitoredServiceSeed.php` | `MonitoredServiceSeed` | DeviceSeed | TCP services per device (SSH, HTTPS, HTTP, SMB, NFS) |
| `MonitoringDataSeed.php` | `MonitoringDataSeed` | DeviceSeed, MonitoredServiceSeed | 48h device checks, 24h service checks, alerts, notifications |

### Scenario summary

Once all seeds have run, the database represents this scenario:

| Device | Status | Notes |
|--------|--------|-------|
| Core Router | Online | Brief 20-min offline blip ~28h ago |
| Distribution Switch | Online | Two 10-min timeout windows (~12h and ~36h ago) |
| File Server | Offline | Down for the last 18h; all services down |

**Alerts:**
- File Server `device_offline` — open, 216 occurrences
- File Server SSH `service_down` — open
- File Server SMB `service_down` — open
- Core Router HTTPS `service_down` — resolved (15-min incident, ~8h ago)
- Distribution Switch HTTP `service_down` — resolved (10-min incident, ~16h ago)

**Discovery findings:**
- 3 matched findings (one per device) in the LAN job
- 1 ignored finding (guest device)
- 6 pending findings across both jobs
- `10.0.0.5` (management network) has hostname `fileserver.lan` — viewing it in the UI
  triggers a hostname-based match suggestion pointing to File Server

### Running seeds

Run all seeds in dependency order (alphabetical ordering is correct by design):

```bash
php scripts/seed.php
```

Or run a single seed by class name:

```bash
php scripts/seed.php DeviceSeed
php scripts/seed.php MonitoredServiceSeed
php scripts/seed.php MonitoringDataSeed
php scripts/seed.php DiscoverySeed
```

All seeds are **idempotent** — running them more than once skips any data that already exists.

### Re-seeding from scratch

To reset and re-seed the development database:

```bash
php scripts/uninstall.php   # removes DB and install lock
php scripts/install.php     # re-runs migrations
php scripts/seed.php        # re-seeds all data
```

---

## Future improvements

| Item | Notes |
|------|-------|
| Batch deletes | Delete in chunks (e.g. 1000 rows per DELETE) to reduce lock contention on large tables |
| Table partitioning | For very high-frequency polling, consider time-bucketed tables or a time-series store |
| Cleanup dry-run flag | `--dry-run` flag to print counts without deleting (for capacity planning) |
| Retention per-device | Per-device override of check retention (e.g. keep critical devices longer) |
