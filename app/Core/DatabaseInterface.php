<?php

namespace App\Core;

interface DatabaseInterface
{
    /**
     * Execute a query and return all matching rows.
     *
     * @param  string $sql
     * @param  array  $bindings
     * @return array
     */
    public function fetch(string $sql, array $bindings = []): array;

    /**
     * Execute a query and return the first matching row, or null.
     *
     * @param  string $sql
     * @param  array  $bindings
     * @return array|null
     */
    public function fetchOne(string $sql, array $bindings = []): ?array;

    /**
     * Execute a statement (INSERT, UPDATE, DELETE) and return affected rows.
     *
     * @param  string $sql
     * @param  array  $bindings
     * @return int
     */
    public function execute(string $sql, array $bindings = []): int;

    /**
     * Return the last inserted row ID.
     *
     * @return string
     */
    public function lastInsertId(): string;

    /**
     * Return the underlying PDO connection.
     *
     * @return \PDO
     */
    public function pdo(): \PDO;
}
