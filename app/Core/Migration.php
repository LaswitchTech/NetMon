<?php

namespace App\Core;

abstract class Migration
{
    protected DatabaseInterface $db;

    public function __construct(DatabaseInterface $db)
    {
        $this->db = $db;
    }

    /**
     * Apply this migration.
     */
    abstract public function up(): void;

    /**
     * Reverse this migration.
     */
    abstract public function down(): void;
}
