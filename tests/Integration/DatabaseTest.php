<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Database;
use PDO;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DatabaseTest extends TestCase
{
    private string $dir;
    private string $path;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/gestor-tareas-' . bin2hex(random_bytes(6));
        $this->path = $this->dir . '/nested/tasks.sqlite';
    }

    protected function tearDown(): void
    {
        @unlink($this->path);
        @rmdir($this->dir . '/nested');
        @rmdir($this->dir);
    }

    public function testCreatesFileAndMissingDirectories(): void
    {
        $this->assertFileDoesNotExist($this->path);

        Database::connect($this->path);

        $this->assertFileExists($this->path);
    }

    public function testCreatesTasksTableWithExpectedColumns(): void
    {
        $pdo = Database::connect($this->path);

        $columns = [];
        foreach ($pdo->query('PRAGMA table_info(tasks)') as $col) {
            $columns[$col['name']] = [$col['type'], (bool) $col['notnull'], $col['dflt_value'], (bool) $col['pk']];
        }

        $this->assertSame([
            'id' => ['INTEGER', false, null, true],
            'title' => ['TEXT', true, null, false],
            'description' => ['TEXT', true, "''", false],
            'due_date' => ['TEXT', false, null, false],
            'priority' => ['INTEGER', true, '2', false],
            'completed' => ['INTEGER', true, '0', false],
            'created_at' => ['TEXT', true, null, false],
            'updated_at' => ['TEXT', true, null, false],
            'created_by' => ['TEXT', true, "''", false],
        ], $columns);
    }

    public function testAddsCreatedByColumnToAnOlderDatabaseKeepingItsRows(): void
    {
        mkdir(dirname($this->path), 0775, true);
        $old = new PDO('sqlite:' . $this->path);
        $old->exec("CREATE TABLE tasks (id INTEGER PRIMARY KEY AUTOINCREMENT, title TEXT NOT NULL, description TEXT NOT NULL DEFAULT '',
            due_date TEXT NULL, priority INTEGER NOT NULL DEFAULT 2, completed INTEGER NOT NULL DEFAULT 0,
            created_at TEXT NOT NULL, updated_at TEXT NOT NULL)");
        $old->exec("INSERT INTO tasks (title, created_at, updated_at) VALUES ('antigua', 't', 't')");
        unset($old);

        $pdo = Database::connect($this->path);

        $this->assertSame('', $pdo->query("SELECT created_by FROM tasks WHERE title = 'antigua'")->fetchColumn());
        Database::connect($this->path); // volver a conectar no repite la migración
        $this->addToAssertionCount(1);
    }

    public function testAppliesDefaults(): void
    {
        $pdo = Database::connect($this->path);
        $pdo->exec("INSERT INTO tasks (title, created_at, updated_at) VALUES ('x', 't', 't')");

        $row = $pdo->query('SELECT description, due_date, priority, completed FROM tasks')->fetch();

        $this->assertSame(['description' => '', 'due_date' => null, 'priority' => 2, 'completed' => 0], $row);
    }

    public function testReconnectingKeepsExistingData(): void
    {
        Database::connect($this->path)
            ->exec("INSERT INTO tasks (title, created_at, updated_at) VALUES ('persistente', 't', 't')");

        $pdo = Database::connect($this->path);

        $this->assertSame('persistente', $pdo->query('SELECT title FROM tasks')->fetchColumn());
    }

    public function testUsesExceptionErrorMode(): void
    {
        $pdo = Database::connect($this->path);

        $this->assertSame(PDO::ERRMODE_EXCEPTION, $pdo->getAttribute(PDO::ATTR_ERRMODE));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidRows(): iterable
    {
        yield 'priority 0' => ["INSERT INTO tasks (title, priority, created_at, updated_at) VALUES ('x', 0, 't', 't')"];
        yield 'priority 4' => ["INSERT INTO tasks (title, priority, created_at, updated_at) VALUES ('x', 4, 't', 't')"];
        yield 'completed 2' => ["INSERT INTO tasks (title, completed, created_at, updated_at) VALUES ('x', 2, 't', 't')"];
        yield 'title NULL' => ["INSERT INTO tasks (title, created_at, updated_at) VALUES (NULL, 't', 't')"];
        yield 'sin created_at' => ["INSERT INTO tasks (title, updated_at) VALUES ('x', 't')"];
    }

    #[DataProvider('invalidRows')]
    public function testRejectsRowsViolatingConstraints(string $sql): void
    {
        $pdo = Database::connect($this->path);

        $this->expectException(PDOException::class);
        $pdo->exec($sql);
    }
}
