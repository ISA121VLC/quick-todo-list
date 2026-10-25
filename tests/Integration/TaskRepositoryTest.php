<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Database;
use App\FixedClock;
use App\TaskRepository;
use DateTimeImmutable;
use PDO;
use PHPUnit\Framework\TestCase;

final class TaskRepositoryTest extends TestCase
{
    private string $path;
    private PDO $pdo;
    private FixedClock $clock;
    private TaskRepository $repo;

    protected function setUp(): void
    {
        $this->path = tempnam(sys_get_temp_dir(), 'gestor-tareas-');
        $this->pdo = Database::connect($this->path);
        $this->clock = new FixedClock(new DateTimeImmutable('2026-03-10T10:00:00Z'));
        $this->repo = new TaskRepository($this->pdo, $this->clock);
    }

    protected function tearDown(): void
    {
        unset($this->repo, $this->pdo);
        @unlink($this->path);
    }

    /**
     * @param array{title?: string, description?: string, due_date?: ?string, priority?: int} $data
     */
    private function create(array $data = []): int
    {
        return $this->repo->create($data + [
            'title' => 'Tarea',
            'description' => '',
            'due_date' => null,
            'priority' => 2,
        ]);
    }

    private function complete(int $id): void
    {
        $this->assertTrue($this->repo->toggle($id));
    }

    /**
     * @return list<string>
     */
    private function titles(string $filter = 'todas'): array
    {
        return array_column($this->repo->all($filter), 'title');
    }

    public function testAllIsEmptyOnEmptyDatabase(): void
    {
        $this->assertSame([], $this->repo->all());
    }

    public function testCreateStoresAllFieldsAndTimestamps(): void
    {
        $id = $this->repo->create([
            'title' => 'Comprar pan',
            'description' => 'Integral',
            'due_date' => '2026-12-31',
            'priority' => 3,
        ]);

        $this->assertSame([[
            'id' => $id,
            'title' => 'Comprar pan',
            'description' => 'Integral',
            'due_date' => '2026-12-31',
            'priority' => 3,
            'completed' => 0,
            'created_at' => '2026-03-10T10:00:00.000000Z',
            'updated_at' => '2026-03-10T10:00:00.000000Z',
        ]], $this->repo->all());
    }

    public function testCreateStoresNullDueDate(): void
    {
        $this->create(['due_date' => null]);

        $this->assertNull($this->repo->all()[0]['due_date']);
    }

    public function testCreateReturnsIncreasingIds(): void
    {
        $first = $this->create();
        $second = $this->create();

        $this->assertGreaterThan(0, $first);
        $this->assertSame($first + 1, $second);
    }

    public function testTimestampsUseClockWithMicrosecondsInUtc(): void
    {
        $this->clock->advance('+1 microsecond');
        $this->create(['title' => 'a']);

        $clock = new FixedClock(new DateTimeImmutable('2026-03-10T11:30:00.250000+01:00'));
        (new TaskRepository($this->pdo, $clock))->create([
            'title' => 'b', 'description' => '', 'due_date' => null, 'priority' => 2,
        ]);

        $rows = array_column($this->repo->all(), 'created_at', 'title');
        $this->assertSame('2026-03-10T10:00:00.000001Z', $rows['a']);
        $this->assertSame('2026-03-10T10:30:00.250000Z', $rows['b']);
    }

    public function testValuesAreStoredLiterallyWithoutSqlInjection(): void
    {
        $title = "O'Brien'); DROP TABLE tasks; --";
        $this->create(['title' => $title, 'description' => '"; DELETE FROM tasks; --']);

        $this->assertSame([$title], $this->titles());
    }

    // Criterio 11
    public function testAllOrdersByCompletedThenPriorityThenDueDate(): void
    {
        $completed = $this->create(['title' => 'completada alta', 'priority' => 3]);
        $this->complete($completed);
        $this->create(['title' => 'pendiente baja', 'priority' => 1]);
        $this->create(['title' => 'alta sin fecha', 'priority' => 3, 'due_date' => null]);
        $this->create(['title' => 'alta 2026-01-01', 'priority' => 3, 'due_date' => '2026-01-01']);

        $this->assertSame(
            ['alta 2026-01-01', 'alta sin fecha', 'pendiente baja', 'completada alta'],
            $this->titles(),
        );
    }

    public function testDueDatesAreAscendingWithinSamePriority(): void
    {
        $this->create(['title' => 'sin fecha', 'due_date' => null]);
        $this->create(['title' => 'diciembre', 'due_date' => '2026-12-01']);
        $this->create(['title' => 'enero', 'due_date' => '2026-01-15']);
        $this->create(['title' => 'año siguiente', 'due_date' => '2027-01-01']);

        $this->assertSame(['enero', 'diciembre', 'año siguiente', 'sin fecha'], $this->titles());
    }

    public function testTiesAreBrokenByCreationOrder(): void
    {
        $this->create(['title' => 'primera']);
        $this->create(['title' => 'segunda']);
        $this->create(['title' => 'tercera']);

        $this->assertSame(['primera', 'segunda', 'tercera'], $this->titles());
    }

    public function testCompletedTasksFollowTheSameOrderAmongThemselves(): void
    {
        foreach (
            [
                ['title' => 'c baja', 'priority' => 1],
                ['title' => 'c alta sin fecha', 'priority' => 3],
                ['title' => 'c alta con fecha', 'priority' => 3, 'due_date' => '2026-05-05'],
            ] as $data
        ) {
            $this->complete($this->create($data));
        }
        $this->create(['title' => 'pendiente', 'priority' => 1]);

        $this->assertSame(
            ['pendiente', 'c alta con fecha', 'c alta sin fecha', 'c baja'],
            $this->titles(),
        );
    }

    public function testFindReturnsTaskOrNull(): void
    {
        $id = $this->create(['title' => 'buscada']);

        $this->assertSame('buscada', $this->repo->find($id)['title']);
        $this->assertNull($this->repo->find($id + 1));
    }

    // Criterio 9 (nivel integración)
    public function testUpdateChangesEditableFieldsAndUpdatedAtOnly(): void
    {
        $id = $this->create([
            'title' => 'original', 'description' => 'antes', 'due_date' => null, 'priority' => 1,
        ]);
        $this->clock->advance('+1 microsecond');

        $updated = $this->repo->update($id, [
            'title' => 'editada', 'description' => 'después', 'due_date' => '2026-04-01', 'priority' => 3,
        ]);

        $this->assertTrue($updated);
        $this->assertSame([
            'id' => $id,
            'title' => 'editada',
            'description' => 'después',
            'due_date' => '2026-04-01',
            'priority' => 3,
            'completed' => 0,
            'created_at' => '2026-03-10T10:00:00.000000Z',
            'updated_at' => '2026-03-10T10:00:00.000001Z',
        ], $this->repo->find($id));
    }

    public function testUpdateCanClearDueDateAndKeepsCompletedState(): void
    {
        $id = $this->create(['due_date' => '2026-04-01']);
        $this->complete($id);

        $this->repo->update($id, ['title' => 't', 'description' => '', 'due_date' => null, 'priority' => 2]);

        $task = $this->repo->find($id);
        $this->assertNull($task['due_date']);
        $this->assertSame(1, $task['completed']);
    }

    public function testUpdateOnlyAffectsTheGivenTask(): void
    {
        $id = $this->create(['title' => 'a']);
        $other = $this->create(['title' => 'b']);

        $this->repo->update($id, ['title' => 'a2', 'description' => '', 'due_date' => null, 'priority' => 2]);

        $this->assertSame('b', $this->repo->find($other)['title']);
    }

    public function testUpdateOfMissingTaskReturnsFalse(): void
    {
        $this->assertFalse(
            $this->repo->update(999, ['title' => 't', 'description' => '', 'due_date' => null, 'priority' => 2])
        );
        $this->assertSame([], $this->repo->all());
    }

    // Criterio 7
    public function testToggleSwitchesCompletedBackAndForth(): void
    {
        $id = $this->create();

        $this->assertTrue($this->repo->toggle($id));
        $this->assertSame(1, $this->repo->find($id)['completed']);

        $this->assertTrue($this->repo->toggle($id));
        $this->assertSame(0, $this->repo->find($id)['completed']);
    }

    public function testToggleUpdatesUpdatedAt(): void
    {
        $id = $this->create();
        $this->clock->advance('+1 minute');

        $this->repo->toggle($id);

        $task = $this->repo->find($id);
        $this->assertSame('2026-03-10T10:00:00.000000Z', $task['created_at']);
        $this->assertSame('2026-03-10T10:01:00.000000Z', $task['updated_at']);
    }

    public function testToggleOnlyAffectsTheGivenTask(): void
    {
        $id = $this->create();
        $other = $this->create();

        $this->repo->toggle($id);

        $this->assertSame(0, $this->repo->find($other)['completed']);
    }

    public function testToggleOfMissingTaskReturnsFalse(): void
    {
        $this->assertFalse($this->repo->toggle(999));
    }

    // Criterio 8
    public function testDeleteRemovesOnlyTheGivenTask(): void
    {
        $id = $this->create(['title' => 'borrar']);
        $this->create(['title' => 'mantener']);

        $this->assertTrue($this->repo->delete($id));

        $this->assertNull($this->repo->find($id));
        $this->assertSame(['mantener'], $this->titles());
        $this->assertSame(0, (int) $this->pdo->query("SELECT COUNT(*) FROM tasks WHERE id = $id")->fetchColumn());
    }

    public function testDeleteOfMissingTaskReturnsFalse(): void
    {
        $id = $this->create();
        $this->repo->delete($id);

        $this->assertFalse($this->repo->delete($id));
        $this->assertFalse($this->repo->delete(999));
    }

    // Criterio 10
    public function testFilterByStatus(): void
    {
        $this->create(['title' => 'p1', 'priority' => 1]);
        $this->complete($this->create(['title' => 'c1', 'priority' => 1]));
        $this->create(['title' => 'p2', 'priority' => 3]);
        $this->complete($this->create(['title' => 'c2', 'priority' => 3]));

        $this->assertSame(['p2', 'p1'], $this->titles('pendientes'));
        $this->assertSame(['c2', 'c1'], $this->titles('completadas'));
        $this->assertSame(['p2', 'p1', 'c2', 'c1'], $this->titles('todas'));
        $this->assertSame(['p2', 'p1', 'c2', 'c1'], $this->titles('xyz'));
        $this->assertSame(['p2', 'p1', 'c2', 'c1'], array_column($this->repo->all(), 'title'));
    }

    public function testFilterOnEmptyGroupReturnsEmptyList(): void
    {
        $this->create();

        $this->assertSame([], $this->repo->all('completadas'));
    }
}
