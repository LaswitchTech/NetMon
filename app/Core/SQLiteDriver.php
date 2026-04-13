<?php

namespace App\Core;

use PDO;
use PDOException;

class SQLiteDriver implements DatabaseInterface
{
    private PDO $pdo;

    public function __construct(string $path)
    {
        $dir = dirname($path);

        if (!is_dir($dir)) {
            throw new \RuntimeException("SQLite directory does not exist: {$dir}");
        }

        try {
            $this->pdo = new PDO('sqlite:' . $path);
            $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
            $this->pdo->exec('PRAGMA journal_mode=WAL;');
            $this->pdo->exec('PRAGMA foreign_keys=ON;');
        } catch (PDOException $e) {
            throw new \RuntimeException('SQLite connection failed: ' . $e->getMessage());
        }
    }

    public function fetch(string $sql, array $bindings = []): array
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($bindings);
        return $stmt->fetchAll();
    }

    public function fetchOne(string $sql, array $bindings = []): ?array
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($bindings);
        $row = $stmt->fetch();
        return $row !== false ? $row : null;
    }

    public function execute(string $sql, array $bindings = []): int
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($bindings);
        return $stmt->rowCount();
    }

    public function lastInsertId(): string
    {
        return $this->pdo->lastInsertId();
    }

    public function pdo(): PDO
    {
        return $this->pdo;
    }
}
