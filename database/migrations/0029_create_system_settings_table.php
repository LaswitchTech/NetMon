<?php

use App\Core\Migration;

/**
 * Create the system_settings table.
 *
 * Stores application-level configuration values that can be managed
 * through the Admin UI and override file-based config at runtime.
 *
 * Precedence (highest to lowest):
 *   1. DB row (this table)
 *   2. config/local.php override
 *   3. .env variable
 *   4. Hardcoded application default
 *
 * Storage model:
 *   - key   is the unique dotted setting identifier (e.g. 'app.name')
 *   - value is stored as plain text; booleans use '1'/'0'
 *   - No hard typing at the DB level — the service layer handles conversion
 *
 * Phase 1 known keys (validated and displayed in Admin → Settings):
 *   app.name                    Application display name
 *   app.url                     Public-facing application URL
 *   notifications.email_enabled Enable/disable email delivery (boolean)
 *   monitoring.check_interval   Default monitoring check interval in seconds
 */
class CreateSystemSettingsTable extends Migration
{
    public function up(): void
    {
        $this->db->pdo()->exec(
            "CREATE TABLE IF NOT EXISTS system_settings (
                key        TEXT        NOT NULL,
                value      TEXT        NOT NULL,
                created_at VARCHAR(32) NOT NULL,
                updated_at VARCHAR(32) NOT NULL,
                PRIMARY KEY (key)
            )"
        );
    }

    public function down(): void
    {
        $this->db->pdo()->exec('DROP TABLE IF EXISTS system_settings');
    }
}
