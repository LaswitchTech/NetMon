# Discovery

> **Status (Phase 14+):** Subnet scanning, staged findings, and the operator action workflow are implemented. Operators can review each finding and choose to link it to an existing device, create a new device from it, or ignore it — all from the browser. The finding detail page surfaces **Possible Device Matches** (informational only) based on MAC and hostname signals, and a **Notes section** for operator annotations. Discovery jobs are now fully manageable from the browser (`/discovery/jobs`). Discovery never automatically creates or modifies devices.

---

## Overview

The discovery subsystem scans configured subnets via ICMP ping sweep and stores observed hosts as **discovery findings**. Findings are informational only — they never automatically create devices, update addresses, or trigger merges.

The workflow is deliberately staged to give operators full control:

```
Subnet scan → Findings (pending) → Match against known devices → Manual action
```

---

## Workflow

### 1. Configure a job

Manage jobs in the browser at **`/discovery/jobs`**, accessible via the "Manage Jobs" button on the Discovery page.

From the jobs list you can:
- Create new jobs (`GET /discovery/jobs/create`)
- Edit existing jobs (`GET /discovery/jobs/{id}/edit`)
- Delete jobs — with a confirmation modal warning that all findings for the job will also be deleted

| Field | Meaning |
|-------|---------|
| `name` | Human-readable label shown in the UI |
| `subnet` | IPv4 CIDR notation, e.g. `192.168.1.0/24` (validated server-side) |
| `enabled` | Enabled = include in scheduled scans; Disabled = skip |
| `last_run_at` | Updated after each scan; Never until first run |

You can also insert directly via SQL if preferred:

```sql
INSERT INTO discovery_jobs (name, subnet, enabled, created_at)
VALUES ('Office LAN', '192.168.1.0/24', 1, datetime('now'));
```

### 2. Run the scanner

```bash
php scripts/discover.php
```

The runner:
1. Loads all enabled jobs from `discovery_jobs`
2. For each job, iterates every usable host address in the subnet
3. Pings each address (ICMP via `exec(ping)`, 1-second timeout)
4. For each host that responds:
   - Looks up the IP in `device_addresses`
   - If found → creates/updates a finding with `status = 'matched'`
   - If not found → creates/updates a finding with `status = 'pending'`
5. Updates `discovery_jobs.last_run_at`

### 3. Review findings

Visit `GET /discovery` in the browser. Findings are listed in a table showing:
- IP address (links to the finding detail page)
- Hostname (blank until reverse DNS is implemented)
- Job name
- Status badge (Pending / Matched / Ignored, links to detail page)
- Linked device (for matched findings, links to `/devices/{id}`)

Click any finding to open the detail page (`GET /discovery/{id}`), which shows full
finding metadata alongside an actions panel.

### 4. Take action

The detail page offers three actions for each non-ignored finding:

#### Link to an existing device

`POST /discovery/{id}/link`

Associates the finding with a device already in the inventory. Sets
`status = 'matched'` and `matched_device_id` on the finding row.

**This action does NOT modify `device_addresses`.** The IP is not recorded on the
target device. If you want the IP to appear on the device, add it manually via the
device's edit page (`/devices/{id}/edit`).

Use this when: the IP belongs to a device you already track, and you simply want to
mark the finding as resolved without changing the device's address configuration.

#### Create a new device from this finding

`GET /discovery/{id}/create-device` → `POST /discovery/{id}/create-device`

Opens a prefilled form (name from hostname if available, address from discovered IP).
On submission:

1. Creates a new device row, a default management interface, and a primary
   `device_addresses` row containing the finding's IP (via `DeviceRepository::create()`).
2. Links the finding to the new device (`status = 'matched'`, `matched_device_id = newId`).
3. Redirects to the new device's detail page (`/devices/{newId}`).

**This is the only action that writes to `device_addresses`.**

Use this when: the IP belongs to a host not yet in the inventory and you want to
register it as a new monitored device in one step.

Only available for **pending** findings (not for already-matched findings).

#### Ignore

`POST /discovery/{id}/ignore`

Sets `status = 'ignored'` and clears `matched_device_id`. The finding row is
preserved for history but is excluded from the pending-review count and from the
actions panel on subsequent visits. **This cannot be undone from the UI.**

Use this when: the IP is noise (a guest device, transient host, scanner artifact, etc.)
that you do not want to track and do not want to review again.

---

**Summary of what each action does to the device model:**

| Action | `discovery_findings` | `devices` | `device_addresses` |
|--------|----------------------|-----------|--------------------|
| Link | status=matched, matched_device_id=? | unchanged | **unchanged** |
| Create device | status=matched, matched_device_id=newId | new row created | new row created |
| Ignore | status=ignored, matched_device_id=NULL | unchanged | unchanged |

---

## Enrichment

After a host responds to a ping, the discovery runner attempts to collect additional
identity data before saving the finding. Enrichment is **best-effort** — failures are
silent and never block the scan.

### Hostname (reverse DNS)

```php
$resolved = gethostbyaddr($ip);
$hostname = ($resolved !== false && $resolved !== $ip) ? $resolved : null;
```

`gethostbyaddr()` queries the system's DNS resolver. If the result equals the input IP
(the function's way of signalling failure) or returns `false`, `hostname` is left NULL.

**Limitations:**
- Requires a PTR record in DNS for the scanned IP.
- Resolution is synchronous — a slow or unresponsive DNS server adds latency per host.
- No retry or timeout override beyond the OS resolver config.
- Internal/RFC-1918 subnets often have no PTR records configured.

### MAC Address (ARP cache)

```php
$mac = $arpResolver->resolve($ip);  // App\Monitoring\ArpResolver
```

`ArpResolver::resolve()` runs `arp -n <ip>` and extracts the MAC address from the
output using a hex-pattern regex. Works on Linux and macOS.

**The ARP cache is only populated for hosts that have recently communicated with the
scanning machine.** Running a ping sweep immediately before this step maximises hits,
because the OS updates its ARP table when ICMP echo-replies are received.

**Limitations:**
- Requires the IP to be in the local ARP cache (layer-2 reachability to the scanner).
- Does not cross L3 boundaries — routed subnets will not have ARP entries unless the
  scanner is on the same broadcast domain.
- `exec()` must be enabled in PHP (not all hosting environments allow it).
- MAC addresses change if a NIC is replaced; they are useful for identity hints, not
  as a stable long-term identifier.

### Preservation on re-scan

Once a finding has a hostname or MAC, that data is preserved on subsequent scans even
if the new scan cannot resolve them. `saveFinding()` uses `COALESCE(?, column)` so
a null new value never overwrites a previously-stored non-null value.

```sql
SET mac_address = COALESCE(?, mac_address),
    hostname    = COALESCE(?, hostname),
```

This means enrichment data accumulates over time: a finding might get its hostname
on one scan and its MAC on the next (once it is in the ARP cache), and both are
retained on all future re-scans.

### How enrichment improves future matching and merge safety

| Data | Matching benefit | Merge safety benefit |
|------|-----------------|----------------------|
| Hostname | Helps operators recognise a device at a glance without looking it up | Provides a secondary identity signal if the IP changes (e.g. DHCP) |
| MAC address | Strong identity signal — can match a device even if its IP was reassigned | MAC-first merge logic (planned) reduces risk of merging two different physical hosts |

---

## Safety Principles

**Findings never modify the device model.**

- `DiscoveryRepository` never writes to `devices`, `device_interfaces`, or `device_addresses`
- `scripts/discover.php` never creates or merges devices
- The `matched_device_id` FK is informational — it can be SET NULL by a cascade if the device is deleted, but the finding row is preserved
- The `ignored` status allows operators to dismiss noise without losing the observation history

These constraints are enforced in code, not just convention. The repository layer has no methods that write device tables.

---

## Matching Logic

Matching runs on every scan for every responding IP:

```
for each responding IP:
    SELECT d.id, d.name
    FROM   device_addresses  da
    JOIN   device_interfaces di ON di.id  = da.interface_id
    JOIN   devices           d  ON d.id   = di.device_id
    WHERE  da.address   = '<ip>'
      AND  d.deleted_at IS NULL
    LIMIT  1
```

If a device is found → `status = 'matched'`, `matched_device_id = device.id`
If not found → `status = 'pending'`, `matched_device_id = NULL`

The match is re-evaluated on every scan. This means:
- A **pending** finding is automatically upgraded to **matched** on the next scan if the device was added in the interim.
- A **matched** finding is downgraded to **pending** if the linked device is deleted before the next scan.

---

## CLI Usage

```bash
# Run all enabled jobs
php scripts/discover.php

# Verbose: show each host as it is checked
php scripts/discover.php --verbose

# Dry run: parse jobs, print host counts — no pings, no DB writes
php scripts/discover.php --dry-run

# Run a single job by ID
php scripts/discover.php --job=1
```

### Example output (verbose)

```
NetMon Discovery — 2026-04-15 10:00:00
--------------------------------------------------

Job: Office LAN — 192.168.1.0/24
  Scanning 254 host(s)...
  [MATCH] 192.168.1.1          4 ms     Core Router
  [MATCH] 192.168.1.2          2 ms     Distribution Switch
  [NEW]   192.168.1.50         1 ms     no device
  [NEW]   192.168.1.51         1 ms     no device
  [MATCH] 192.168.1.10         3 ms     File Server
  Found 5 host(s): 3 matched, 2 pending.

--------------------------------------------------
── Discovery Summary
Hosts found:   5
  Matched:     3  (IP already known to a device)
  Pending:     2  (new — review at /discovery)
Elapsed:       87.3s
```

### Cron scheduling

Recommended interval: once per hour.

```cron
0 * * * * php /path/to/netmon/scripts/discover.php >> /path/to/netmon/storage/logs/discovery.log 2>&1
```

---

## Database Schema

### `discovery_jobs`

| Column | Type | Notes |
|--------|------|-------|
| `id` | INTEGER PK | Auto-increment |
| `name` | VARCHAR(128) | Human label |
| `subnet` | VARCHAR(45) | IPv4 CIDR, e.g. `192.168.1.0/24` |
| `enabled` | INTEGER | `1` = scan; `0` = skip |
| `last_run_at` | VARCHAR(32) | NULL until first successful scan |
| `created_at` | VARCHAR(32) | Row insertion time |

**Indexes:** `discovery_jobs_enabled`

### `discovery_findings`

| Column | Type | Notes |
|--------|------|-------|
| `id` | INTEGER PK | Auto-increment |
| `job_id` | INTEGER FK | → `discovery_jobs.id` CASCADE DELETE |
| `ip_address` | VARCHAR(45) | IPv4 or IPv6 |
| `mac_address` | VARCHAR(17) | NULL (future: ARP scan) |
| `hostname` | VARCHAR(253) | NULL (future: reverse DNS) |
| `status` | VARCHAR(16) | `pending`, `matched`, `ignored` |
| `matched_device_id` | INTEGER FK | → `devices.id` SET NULL on delete; NULL if pending/ignored |
| `created_at` | VARCHAR(32) | Row insertion time (first seen) |

**Constraints:** `UNIQUE (job_id, ip_address)` — one finding per IP per job; re-scans update the existing row.

**Indexes:** `discovery_findings_job_id`, `discovery_findings_ip_address`, `discovery_findings_status`

---

## File Structure

```
app/
  Models/
    DiscoveryRepository.php     ← All discovery DB queries
  Monitoring/
    SubnetScanner.php           ← CIDR parser + ICMP ping sweep
    ArpResolver.php             ← ARP cache lookup (MAC address enrichment)

database/
  migrations/
    0019_create_discovery_jobs_table.php
    0020_create_discovery_findings_table.php

scripts/
  discover.php                  ← CLI runner

app/
  NetMon/Controllers/
    DiscoveryController.php     ← All discovery routes (findings + jobs)

  Views/
    discovery/
      index.php                 ← Findings list (GET /discovery)
      show.php                  ← Finding detail + actions (GET /discovery/{id})
      create-device.php         ← Create device form (GET /discovery/{id}/create-device)
      jobs/
        index.php               ← Jobs list (GET /discovery/jobs)
        create.php              ← New job form (GET /discovery/jobs/create)
        edit.php                ← Edit job form + danger zone (GET /discovery/jobs/{id}/edit)
```

---

## Repository Methods

### Jobs

| Method | Description |
|--------|-------------|
| `findAllJobs(): array` | All jobs with findings_count, sorted by name |
| `findJobById(int): ?array` | Single job row or null |
| `createJob(array): int` | Insert new job; returns new ID |
| `updateJob(int, array): void` | Update name, subnet, enabled |
| `deleteJob(int): void` | Delete job; findings removed by CASCADE DELETE |
| `findEnabledJobs(): array` | All jobs with enabled=1 (CLI scanner path) |
| `updateJobLastRun(int, string): void` | Stamp last_run_at on completion |

### Findings

| Method | Description |
|--------|-------------|
| `saveFinding(array): int` | Upsert by (job_id, ip_address); returns finding ID |
| `matchFindingToDevice(int, int): void` | Set status=matched, matched_device_id=? (scanner path) |
| `findAllFindings(int): array` | Recent findings joined with job+device names |
| `countByStatus(): array<string,int>` | Counts per status for summary cards |
| `findDeviceByAddress(string): ?array` | Look up device claiming a given IP address |
| `findById(int): ?array` | Single finding with job_name, job_subnet, device_name |
| `linkFindingToDevice(int, int): void` | Set status=matched, matched_device_id=? (UI action path) |
| `markIgnored(int): void` | Set status=ignored, matched_device_id=NULL |
| `possibleMatchesForFinding(int): array` | Return active devices that share a strong identity signal with this finding; informational only |

---

## SubnetScanner

**Class:** `App\Monitoring\SubnetScanner`

| Method | Description |
|--------|-------------|
| `scan(string $cidr, int $timeout, callable $onProgress): string[]` | Run ping sweep; return responsive IPs |
| `hostCount(string $cidr): int` | Count usable hosts in CIDR without scanning |

**Constraints:**
- IPv4 only (IPv6 subnet scanning not implemented)
- Synchronous (one host at a time)
- Maximum subnet size: /16 (65 534 hosts) — larger subnets are rejected with an exception
- Network and broadcast addresses are always excluded

---

## Limitations (Phase 12)

| Item | Notes |
|------|-------|
| Hostname (partial) | `gethostbyaddr()` is called per-host but requires PTR records. Internal subnets without PTR records will show NULL. |
| MAC address (partial) | ARP cache lookup only. Hosts not in the scanner's ARP cache, or on routed subnets, will show NULL. |
| No nmap | The scanner uses `exec(ping)` only. nmap would provide OS fingerprinting and port discovery. |
| Synchronous scan | A /24 takes ~4–5 minutes worst-case (254 hosts × 1 s timeout). A parallel runner (via `proc_open` or forking) would reduce this significantly. |
| Ignore is one-way | Once a finding is ignored, only a direct SQL update can revert it. A UI unignore action is not yet implemented. |

---

## Possible Device Matches (Suggestions)

The finding detail page (`GET /discovery/{id}`) may display a **Possible Device Matches** card below the actions panel. These suggestions surface active devices that share a strong identity signal with the finding. They are **informational only** — no link or merge is taken automatically.

### How it works

`DiscoveryRepository::possibleMatchesForFinding(int $findingId): array` runs two read-only queries:

1. **MAC address match (strong signal)**
   If the finding has a non-null `mac_address`, looks for active devices that have a `device_interfaces.mac_address` row with the same value. A MAC match strongly suggests the same physical NIC.

2. **Hostname match via linked findings (weaker signal)**
   If the finding has a non-null `hostname`, looks for active devices linked (via `matched_device_id`) to any other finding that shares the same hostname. This is heuristic — hostnames can be recycled, are not globally unique, and are not a reliable long-term identity signal.

Results are priority-ordered: MAC matches first, then hostname-only matches for devices not already present. The already-matched device (`finding.matched_device_id`) is excluded from suggestions. Soft-deleted devices are excluded.

Each result row carries:
- `match_reason` — `'mac'` or `'hostname'`
- `match_value` — the shared MAC or hostname string

### Signal strength and labelling

| Signal | Badge | Note in UI |
|--------|-------|------------|
| MAC match | **MAC match** (yellow/warning) | "strong signal" |
| Hostname match | **Hostname match** (grey/secondary) | "weaker — not unique" |

### UI behaviour

When `$possibleMatches` is non-empty, the view renders a left-yellow-bordered card at the bottom of the finding detail page. Each suggestion shows: device name (linked to `/devices/{id}`), management address, signal badge, and matched value.

A footer note explains how to act on a suggestion: use the **Link to an existing device** panel above, or visit the device page to initiate a merge.

The card is hidden entirely when there are no suggestions (`if (!empty($possibleMatches))`).

### Rules enforced

- No automatic link, merge, or mutation ever occurs from this feature.
- The operator must explicitly use the Link or Create device action panels to act.
- Suggestions reference the actions panel — no separate action button exists on the suggestion card itself.

---

## Notes on Findings

Operators can attach free-text notes to any discovery finding via the Notes section on the detail page (`GET /discovery/{id}`).

- **Entity type:** `finding`
- **Routes:** `POST /discovery/{id}/notes`, `POST /discovery/{id}/notes/{noteId}/delete`
- **Controller methods:** `DiscoveryController::addNote()`, `DiscoveryController::deleteNote()`
- Notes are author-owned and can only be deleted by their author
- Notes survive finding deletion: the `notes` table has no FK cascade to `discovery_findings`
- Rendered via the shared `partials/notes-section.php` partial — consistent with device and alert note sections

Typical use: recording why a finding was ignored, what action was taken, or context about the scanned host.

See [notes-module.md](notes-module.md) for full Notes module documentation.

---

## Future Enhancements

### Link action: optional address addition

The current `link` action intentionally does not modify `device_addresses`. A future
enhancement could offer an opt-in checkbox: "Also add this IP to the device's address
list". This would keep the safe default (no side-effects) while reducing clicks for
the common case.

### Full merge workflow

A richer merge workflow (for cases where a device is discovered under multiple IPs
or with a MAC address) will require:

1. **Operator confirmation** — never automatic
2. **Identity signal**: prefer MAC address match (strongest); fall back to IP match (weaker, may be dynamic)
3. **Conflict detection**: warn if the IP is already claimed by a different device
4. **Address reconciliation**: add or deduplicate `device_addresses` rows as needed
5. **No auto-merge under any circumstances**

This workflow will be documented in detail when implemented.
