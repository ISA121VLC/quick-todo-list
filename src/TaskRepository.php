<?php

declare(strict_types=1);

namespace App;

use DateTimeZone;
use PDO;

/**
 * Acceso a la tabla tasks. Todas las consultas usan sentencias preparadas.
 *
 * @phpstan-type TaskRow array{
 *     id: int, title: string, description: string, due_date: ?string,
 *     priority: int, completed: int, created_at: string, updated_at: string
 * }
 * @phpstan-type TaskData array{title: string, description: string, due_date: ?string, priority: int}
 */
final class TaskRepository
{
    /** Formato de created_at/updated_at: UTC con microsegundos. */
    public const TIMESTAMP_FORMAT = 'Y-m-d\TH:i:s.u\Z';

    /** Filtro por estado → valor de completed (null = sin filtrar). */
    public const FILTERS = ['todas' => null, 'pendientes' => 0, 'completadas' => 1];
    public const DEFAULT_FILTER = 'todas';

    private const ORDER_BY = 'completed ASC, priority DESC, due_date IS NULL, due_date ASC, id ASC';

    public function __construct(private PDO $pdo, private Clock $clock)
    {
    }

    /**
     * @param TaskData $data Datos ya validados por TaskValidator.
     * @return int Id de la tarea creada.
     */
    public function create(array $data): int
    {
        $now = $this->timestamp();
        $stmt = $this->pdo->prepare(
            'INSERT INTO tasks (title, description, due_date, priority, created_at, updated_at)
             VALUES (:title, :description, :due_date, :priority, :created_at, :updated_at)'
        );
        $stmt->execute([
            'title' => $data['title'],
            'description' => $data['description'],
            'due_date' => $data['due_date'],
            'priority' => $data['priority'],
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * @return list<TaskRow> Pendientes primero; luego prioridad alta→baja,
     *                       fecha límite más cercana (sin fecha al final) e id.
     */
    public function all(string $filter = self::DEFAULT_FILTER): array
    {
        $completed = self::FILTERS[$filter] ?? null;
        if ($completed === null) {
            return $this->pdo->query('SELECT * FROM tasks ORDER BY ' . self::ORDER_BY)->fetchAll();
        }

        $stmt = $this->pdo->prepare('SELECT * FROM tasks WHERE completed = ? ORDER BY ' . self::ORDER_BY);
        $stmt->execute([$completed]);

        return $stmt->fetchAll();
    }

    /**
     * @return TaskRow|null
     */
    public function find(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM tasks WHERE id = ?');
        $stmt->execute([$id]);

        return $stmt->fetch() ?: null;
    }

    /**
     * Sustituye los campos editables y actualiza updated_at.
     *
     * @param TaskData $data Datos ya validados por TaskValidator.
     * @return bool false si la tarea no existe.
     */
    public function update(int $id, array $data): bool
    {
        $stmt = $this->pdo->prepare(
            'UPDATE tasks
             SET title = :title, description = :description, due_date = :due_date,
                 priority = :priority, updated_at = :updated_at
             WHERE id = :id'
        );
        $stmt->execute([
            'title' => $data['title'],
            'description' => $data['description'],
            'due_date' => $data['due_date'],
            'priority' => $data['priority'],
            'updated_at' => $this->timestamp(),
            'id' => $id,
        ]);

        return $stmt->rowCount() > 0;
    }

    /**
     * Alterna completada/pendiente y actualiza updated_at.
     *
     * @return bool false si la tarea no existe.
     */
    public function toggle(int $id): bool
    {
        $stmt = $this->pdo->prepare('UPDATE tasks SET completed = 1 - completed, updated_at = ? WHERE id = ?');
        $stmt->execute([$this->timestamp(), $id]);

        return $stmt->rowCount() > 0;
    }

    /**
     * @return bool false si la tarea no existe.
     */
    public function delete(int $id): bool
    {
        $stmt = $this->pdo->prepare('DELETE FROM tasks WHERE id = ?');
        $stmt->execute([$id]);

        return $stmt->rowCount() > 0;
    }

    private function timestamp(): string
    {
        return $this->clock->now()->setTimezone(new DateTimeZone('UTC'))->format(self::TIMESTAMP_FORMAT);
    }
}
