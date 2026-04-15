<?php

namespace App\Models;

use App\Core\DatabaseInterface;

/**
 * Database queries for discovery_jobs and discovery_findings.
 *
 * Design rules:
 *   - Findings are informational only — this repository never creates or
 *     modifies device, device_interface, or device_address rows.
 *   - saveFinding() upserts by (job_id, ip_address): inserts on first sight,
 *     updates mutable fields (mac_address, hostname, status, matched_device_id)
 *     on subsequent scans.
 *   - matchFindingToDevice() promotes a pending finding to matched status.
 *     It is safe to call on an already-matched finding (idempotent).
 *
 * Returns raw arrays — no domain objects.
 */
class DiscoveryRepository
{
    private DatabaseInterface $db;

    public function __construct(DatabaseInterface $db)
    {
        $this->db = $db;
    }

    // -------------------------------------------------------------------------
    // Jobs
    // -------------------------------------------------------------------------

    /**
     * Return all enabled discovery jobs, ordered alphabetically by name.
     *
     * @return array<int, array{
     *   id:         int,
     *   name:       string,
     *   subnet:     string,
     *   last_run_at: string|null
     * }>
     */
    public function findEnabledJobs(): array
    {
        return $this->db->fetch(
            "SELECT id, name, subnet, last_run_at
             FROM   discovery_jobs
             WHERE  enabled = 1
             ORDER  BY name ASC"
        );
    }

    /**
     * Update the last_run_at timestamp on a discovery job.
     *
     * Called at the end of each successful job scan.
     *
     * @param int    $jobId
     * @param string $ts   ISO datetime (e.g. 2026-04-15 10:00:00)
     */
    public function updateJobLastRun(int $jobId, string $ts): void
    {
        $this->db->execute(
            "UPDATE discovery_jobs SET last_run_at = ? WHERE id = ?",
            [$ts, $jobId]
        );
    }

    // -------------------------------------------------------------------------
    // Findings — single-row read
    // -------------------------------------------------------------------------

    /**
     * Return a single discovery finding by ID, with job and device context.
     *
     * JOINs discovery_jobs so job_name and subnet are available on the detail
     * page without an extra query. LEFT JOINs devices for matched findings.
     *
     * @param  int $id
     * @return array|null  Finding row with job_name, job_subnet, device_name; or null if not found.
     */
    public function findById(int $id): ?array
    {
        return $this->db->fetchOne(
            "SELECT  f.*,
                     j.name   AS job_name,
                     j.subnet AS job_subnet,
                     d.name   AS device_name
             FROM    discovery_findings f
             JOIN    discovery_jobs j ON j.id = f.job_id
             LEFT JOIN devices      d ON d.id = f.matched_device_id
             WHERE   f.id = ?",
            [$id]
        );
    }

    // -------------------------------------------------------------------------
    // Findings — write
    // -------------------------------------------------------------------------

    /**
     * Insert or update a discovery finding for the given (job_id, ip_address).
     *
     * Insert behaviour:
     *   A new row is created with the supplied status and matched_device_id.
     *
     * Update behaviour (finding already exists for this job + IP):
     *   status and matched_device_id are always overwritten, allowing a re-scan
     *   to upgrade 'pending' → 'matched' (if a device was added) or downgrade
     *   'matched' → 'pending' (if the linked device was removed).
     *   mac_address and hostname are only overwritten if the new value is
     *   non-null — existing enrichment data is preserved when the current scan
     *   cannot resolve them (e.g. ARP cache miss, DNS unavailable).
     *
     * @param  array{
     *   job_id:            int,
     *   ip_address:        string,
     *   mac_address:       string|null,
     *   hostname:          string|null,
     *   status:            string,
     *   matched_device_id: int|null
     * } $data
     * @return int  The finding row ID (existing or newly created)
     */
    public function saveFinding(array $data): int
    {
        $existing = $this->db->fetchOne(
            "SELECT id FROM discovery_findings WHERE job_id = ? AND ip_address = ?",
            [$data['job_id'], $data['ip_address']]
        );

        if ($existing !== null) {
            // mac_address and hostname use COALESCE so a null new value keeps
            // whatever was stored by a previous scan (best-effort enrichment).
            // status and matched_device_id are always updated to reflect the
            // current scan's matching result.
            $this->db->execute(
                "UPDATE discovery_findings
                 SET    mac_address       = COALESCE(?, mac_address),
                        hostname          = COALESCE(?, hostname),
                        status            = ?,
                        matched_device_id = ?
                 WHERE  id = ?",
                [
                    $data['mac_address']       ?? null,
                    $data['hostname']          ?? null,
                    $data['status']            ?? 'pending',
                    $data['matched_device_id'] ?? null,
                    (int) $existing['id'],
                ]
            );

            return (int) $existing['id'];
        }

        $now = date('Y-m-d H:i:s');

        $this->db->execute(
            "INSERT INTO discovery_findings
                 (job_id, ip_address, mac_address, hostname,
                  status, matched_device_id, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?)",
            [
                $data['job_id'],
                $data['ip_address'],
                $data['mac_address']       ?? null,
                $data['hostname']          ?? null,
                $data['status']            ?? 'pending',
                $data['matched_device_id'] ?? null,
                $now,
            ]
        );

        return (int) $this->db->lastInsertId();
    }

    /**
     * Mark a finding as matched to a specific device (scanner path).
     *
     * Sets status = 'matched' and matched_device_id. Safe to call on a finding
     * that is already matched — it will update the device link and is idempotent
     * if called with the same device_id twice.
     *
     * Used by the discovery runner when a responding IP already exists in
     * device_addresses. For operator-driven linking from the UI, use linkFindingToDevice().
     *
     * @param int $findingId  discovery_findings row to update
     * @param int $deviceId   devices row to link
     */
    public function matchFindingToDevice(int $findingId, int $deviceId): void
    {
        $this->db->execute(
            "UPDATE discovery_findings
             SET    status            = 'matched',
                    matched_device_id = ?
             WHERE  id = ?",
            [$deviceId, $findingId]
        );
    }

    /**
     * Link a finding to a device via operator action (UI path).
     *
     * Functionally identical to matchFindingToDevice() but named to distinguish
     * operator-initiated links (UI) from automatic scanner matches (CLI).
     *
     * This method ONLY updates the finding row. It does NOT add the finding's
     * IP address to the device's address list — device_addresses is not modified.
     * The operator may separately add the IP via the device edit page if needed.
     *
     * Safe to call on already-matched findings (re-linking to a different device).
     *
     * @param int $findingId  discovery_findings row to update
     * @param int $deviceId   devices row to link
     */
    public function linkFindingToDevice(int $findingId, int $deviceId): void
    {
        $this->db->execute(
            "UPDATE discovery_findings
             SET    status            = 'matched',
                    matched_device_id = ?
             WHERE  id = ?",
            [$deviceId, $findingId]
        );
    }

    /**
     * Mark a finding as ignored.
     *
     * The finding row is preserved for historical reference; it will no longer
     * appear in pending-review workflows. The matched_device_id is cleared when
     * ignoring to avoid phantom links on previously matched findings.
     *
     * Idempotent — safe to call on a finding that is already ignored.
     *
     * @param int $findingId
     */
    public function markIgnored(int $findingId): void
    {
        $this->db->execute(
            "UPDATE discovery_findings
             SET    status            = 'ignored',
                    matched_device_id = NULL
             WHERE  id = ?",
            [$findingId]
        );
    }

    // -------------------------------------------------------------------------
    // Findings — read (UI / reporting)
    // -------------------------------------------------------------------------

    /**
     * Return the most recent discovery findings across all jobs.
     *
     * JOINs discovery_jobs and devices so each row carries job_name and
     * device_name for display. LEFT JOINs preserve unmatched findings.
     *
     * @param  int $limit  Maximum rows to return (default 100)
     * @return array[]
     */
    public function findAllFindings(int $limit = 100): array
    {
        return $this->db->fetch(
            "SELECT  f.*,
                     j.name AS job_name,
                     d.name AS device_name
             FROM    discovery_findings f
             JOIN    discovery_jobs j ON j.id = f.job_id
             LEFT JOIN devices      d ON d.id = f.matched_device_id
             ORDER   BY f.created_at DESC, f.id DESC
             LIMIT   ?",
            [$limit]
        );
    }

    /**
     * Return counts of findings grouped by status.
     *
     * Returns an associative array keyed by status value:
     *   ['pending' => 12, 'matched' => 5, 'ignored' => 2]
     *
     * Missing statuses are absent from the result (not zero-filled).
     *
     * @return array<string, int>
     */
    public function countByStatus(): array
    {
        $rows = $this->db->fetch(
            "SELECT status, COUNT(*) AS cnt
             FROM   discovery_findings
             GROUP  BY status"
        );

        $result = [];
        foreach ($rows as $row) {
            $result[$row['status']] = (int) $row['cnt'];
        }

        return $result;
    }

    // -------------------------------------------------------------------------
    // Merge suggestions (informational — read-only)
    // -------------------------------------------------------------------------

    /**
     * Return active devices that are likely candidates for this finding, based
     * on strong identity signals only.
     *
     * Priority order:
     *   1. MAC address match (strong) — finding.mac_address matches a
     *      device_interfaces.mac_address row on an active device.
     *   2. Hostname match (weaker) — finding.hostname matches the hostname on
     *      another discovery finding that is already linked to an active device.
     *
     * The already-matched device (finding.matched_device_id) is excluded so it
     * does not appear as its own "possible match".
     *
     * Results are informational only. No link or merge action is taken.
     * Each row carries `match_reason` ('mac' or 'hostname') and `match_value`
     * (the shared value) so the UI can label the signal strength clearly.
     *
     * @param  int $findingId
     * @return array<int, array{
     *   id:           int,
     *   name:         string,
     *   address:      string,
     *   match_reason: string,
     *   match_value:  string
     * }>
     */
    public function possibleMatchesForFinding(int $findingId): array
    {
        $finding = $this->findById($findingId);
        if ($finding === null) {
            return [];
        }

        $suggestions      = [];
        $alreadyMatchedId = !empty($finding['matched_device_id'])
            ? (int) $finding['matched_device_id']
            : null;

        // ── Strong signal: MAC address match ──────────────────────────────────
        // The finding's MAC matches a MAC on any interface of an active device.
        if (!empty($finding['mac_address'])) {
            $macMatches = $this->db->fetch(
                "SELECT DISTINCT
                     d.id,
                     d.name,
                     COALESCE(
                         (
                             SELECT  da.address
                             FROM    device_interfaces di2
                             JOIN    device_addresses  da ON da.interface_id = di2.id
                             WHERE   di2.device_id    = d.id
                               AND   di2.is_management = 1
                               AND   da.is_primary     = 1
                             LIMIT 1
                         ),
                         d.host
                     ) AS address,
                     di.mac_address AS match_value,
                     'mac'          AS match_reason
                 FROM   devices d
                 JOIN   device_interfaces di ON di.device_id = d.id
                 WHERE  d.deleted_at   IS NULL
                   AND  di.mac_address  = ?
                 ORDER  BY d.name ASC",
                [$finding['mac_address']]
            );

            foreach ($macMatches as $row) {
                $rid = (int) $row['id'];
                if ($rid !== $alreadyMatchedId) {
                    $suggestions[$rid] = $row;
                }
            }
        }

        // ── Weaker signal: hostname match via linked findings ─────────────────
        // Finding.hostname matches the hostname stored on a different finding
        // that is already linked to an active device.
        if (!empty($finding['hostname'])) {
            $hostnameMatches = $this->db->fetch(
                "SELECT DISTINCT
                     d.id,
                     d.name,
                     COALESCE(
                         (
                             SELECT  da.address
                             FROM    device_interfaces di2
                             JOIN    device_addresses  da ON da.interface_id = di2.id
                             WHERE   di2.device_id    = d.id
                               AND   di2.is_management = 1
                               AND   da.is_primary     = 1
                             LIMIT 1
                         ),
                         d.host
                     ) AS address,
                     f2.hostname AS match_value,
                     'hostname'  AS match_reason
                 FROM   devices d
                 JOIN   discovery_findings f2 ON f2.matched_device_id = d.id
                 WHERE  d.deleted_at IS NULL
                   AND  f2.hostname   = ?
                   AND  f2.id        != ?
                 ORDER  BY d.name ASC",
                [$finding['hostname'], $findingId]
            );

            foreach ($hostnameMatches as $row) {
                $rid = (int) $row['id'];
                // Only add hostname matches for devices not already found via MAC
                // and not the already-matched device.
                if (!isset($suggestions[$rid]) && $rid !== $alreadyMatchedId) {
                    $suggestions[$rid] = $row;
                }
            }
        }

        return array_values($suggestions);
    }

    // -------------------------------------------------------------------------
    // Matching support
    // -------------------------------------------------------------------------

    /**
     * Find an active device whose address matches the given IP.
     *
     * Searches device_addresses across all interfaces for any non-deleted device.
     * Returns the first match — if multiple devices claim the same IP (which
     * should not happen but is not enforced at the DB level), the one with
     * the lowest device ID is returned.
     *
     * @param  string $ip  IPv4 or IPv6 address to look up
     * @return array{id: int, name: string}|null  Device row, or null if not found
     */
    public function findDeviceByAddress(string $ip): ?array
    {
        return $this->db->fetchOne(
            "SELECT  d.id, d.name
             FROM    device_addresses  da
             JOIN    device_interfaces di ON di.id  = da.interface_id
             JOIN    devices           d  ON d.id   = di.device_id
             WHERE   da.address   = ?
               AND   d.deleted_at IS NULL
             ORDER   BY d.id ASC
             LIMIT   1",
            [$ip]
        );
    }
}
