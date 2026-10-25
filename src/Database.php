<?php

declare(strict_types=1);

namespace App;

use PDO;
use RuntimeException;

final class Database
{
    private const SCHEMA = <<<'SQL'
        CREATE TABLE IF NOT EXISTS tasks (
            id          INTEGER PRIMARY KEY AUTOINCREMENT,
            title       TEXT    NOT NULL,
            description TEXT    NOT NULL DEFAULT '',
            due_date    TEXT    NULL,
            priority    INTEGER NOT NULL DEFAULT 2 CHECK (priority IN (1, 2, 3)),
            completed   INTEGER NOT NULL DEFAULT 0 CHECK (completed IN (0, 1)),
            created_at  TEXT    NOT NULL,
            updated_at  TEXT    NOT NULL
        );
        CREATE TABLE IF NOT EXISTS login_throttle (
            ip            TEXT    PRIMARY KEY,
            fails         INTEGER NOT NULL DEFAULT 0,
            window_start  INTEGER NOT NULL DEFAULT 0,
            strikes       INTEGER NOT NULL DEFAULT 0,
            strike_at     INTEGER NOT NULL DEFAULT 0,
            blocked_until INTEGER NOT NULL DEFAULT 0,
            blocked_kind  TEXT    NULL
        );
        SQL;

    /**
     * Abre (o crea) la base de datos SQLite en $path y aplica el esquema.
     */
    public static function connect(string $path): PDO
    {
        $dir = dirname($path);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException("No se pudo crear el directorio de la base de datos: $dir");
        }

        $pdo = new PDO('sqlite:' . $path, options: [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $pdo->exec(self::SCHEMA);

        return $pdo;
    }
}
