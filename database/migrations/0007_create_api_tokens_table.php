<?php

use App\Core\Migration;

class CreateApiTokensTable extends Migration
{
    public function up(): void
    {
        // Design notes:
        //   - token_hash only — raw token is never stored; generated once and shown to the user
        //   - revoked_at NULL means active; a non-NULL value means revoked (stores when)
        //   - expires_at NULL means the token never expires
        //   - last_used_at NULL means the token has not been used yet
        //   - name is a human-readable label set by the user (e.g. "CI deploy key")
        //   - token_permissions table is intentionally deferred; api_tokens.id is the FK target
        $this->db->pdo()->exec(
            'CREATE TABLE IF NOT EXISTS api_tokens (
                id           INTEGER      NOT NULL,
                user_id      INTEGER      NOT NULL,
                name         VARCHAR(128) NOT NULL,
                token_hash   VARCHAR(255) NOT NULL,
                last_used_at VARCHAR(32),
                expires_at   VARCHAR(32),
                revoked_at   VARCHAR(32),
                created_at   VARCHAR(32)  NOT NULL,
                PRIMARY KEY (id),
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            )'
        );

        // Hash must be unique — prevents timing-safe lookup from returning multiple rows
        $this->db->pdo()->exec(
            'CREATE UNIQUE INDEX IF NOT EXISTS api_tokens_hash_unique ON api_tokens (token_hash)'
        );

        // Typical query: look up active tokens for a user
        $this->db->pdo()->exec(
            'CREATE INDEX IF NOT EXISTS api_tokens_user_id ON api_tokens (user_id)'
        );
    }

    public function down(): void
    {
        $this->db->pdo()->exec('DROP TABLE IF EXISTS api_tokens');
    }
}
