<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\TaskValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TaskValidatorTest extends TestCase
{
    private TaskValidator $validator;

    protected function setUp(): void
    {
        $this->validator = new TaskValidator();
    }

    public function testMinimalValidInputUsesDefaults(): void
    {
        [$data, $errors] = $this->validator->validate(['title' => 'Comprar pan']);

        $this->assertSame([], $errors);
        $this->assertSame([
            'title' => 'Comprar pan',
            'description' => '',
            'due_date' => null,
            'priority' => 2,
        ], $data);
    }

    public function testFullValidInputIsNormalized(): void
    {
        [$data, $errors] = $this->validator->validate([
            'title' => "  Llamar al médico \n",
            'description' => "  Pedir cita  ",
            'due_date' => ' 2026-12-31 ',
            'priority' => 'alta',
        ]);

        $this->assertSame([], $errors);
        $this->assertSame([
            'title' => 'Llamar al médico',
            'description' => 'Pedir cita',
            'due_date' => '2026-12-31',
            'priority' => 3,
        ], $data);
    }

    // Criterio 3

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function missingTitles(): iterable
    {
        yield 'vacío' => [['title' => '']];
        yield 'solo espacios' => [['title' => '   ']];
        yield 'espacios y saltos' => [['title' => " \t\n "]];
        yield 'espacio unicode' => [['title' => "\u{00A0}\u{3000}"]];
        yield 'ausente' => [[]];
    }

    /**
     * @param array<string, mixed> $input
     */
    #[DataProvider('missingTitles')]
    public function testTitleIsRequired(array $input): void
    {
        [, $errors] = $this->validator->validate($input);

        $this->assertSame(['title' => 'El título es obligatorio'], $errors);
    }

    // Criterio 4

    public function testTitleOf200CharactersIsAccepted(): void
    {
        [$data, $errors] = $this->validator->validate(['title' => str_repeat('a', 200)]);

        $this->assertSame([], $errors);
        $this->assertSame(200, mb_strlen($data['title']));
    }

    public function testTitleOf201CharactersIsRejected(): void
    {
        [, $errors] = $this->validator->validate(['title' => str_repeat('a', 201)]);

        $this->assertSame(['title' => 'El título no puede superar 200 caracteres'], $errors);
    }

    public function testTitleLengthCountsCharactersNotBytes(): void
    {
        [, $errors] = $this->validator->validate(['title' => str_repeat('ñ', 200)]);
        $this->assertSame([], $errors);

        [, $errors] = $this->validator->validate(['title' => str_repeat('ñ', 201)]);
        $this->assertArrayHasKey('title', $errors);
    }

    public function testSurroundingSpacesDoNotCountTowardsTitleLength(): void
    {
        [, $errors] = $this->validator->validate(['title' => '  ' . str_repeat('a', 200) . '  ']);

        $this->assertSame([], $errors);
    }

    public function testDescriptionLimitIs2000Characters(): void
    {
        [, $errors] = $this->validator->validate(['title' => 't', 'description' => str_repeat('é', 2000)]);
        $this->assertSame([], $errors);

        [, $errors] = $this->validator->validate(['title' => 't', 'description' => str_repeat('é', 2001)]);
        $this->assertSame(['description' => 'La descripción no puede superar 2000 caracteres'], $errors);
    }

    // Criterio 5

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidDates(): iterable
    {
        yield '30 de febrero' => ['2026-02-30'];
        yield 'texto' => ['mañana'];
        yield 'mes 13' => ['2026-13-01'];
        yield 'sin ceros' => ['2026-1-5'];
        yield 'formato europeo' => ['31/12/2026'];
        yield 'con hora' => ['2026-12-31T10:00'];
        yield '29 feb no bisiesto' => ['2027-02-29'];
    }

    #[DataProvider('invalidDates')]
    public function testInvalidDueDateIsRejected(string $dueDate): void
    {
        [, $errors] = $this->validator->validate(['title' => 't', 'due_date' => $dueDate]);

        $this->assertSame(['due_date' => 'La fecha límite no es válida (formato AAAA-MM-DD)'], $errors);
    }

    public function testValidDueDatesAreKept(): void
    {
        [$data, $errors] = $this->validator->validate(['title' => 't', 'due_date' => '2026-12-31']);
        $this->assertSame([], $errors);
        $this->assertSame('2026-12-31', $data['due_date']);

        [$data, $errors] = $this->validator->validate(['title' => 't', 'due_date' => '2028-02-29']);
        $this->assertSame([], $errors);
        $this->assertSame('2028-02-29', $data['due_date']);
    }

    public function testEmptyDueDateBecomesNull(): void
    {
        [$data, $errors] = $this->validator->validate(['title' => 't', 'due_date' => '  ']);

        $this->assertSame([], $errors);
        $this->assertNull($data['due_date']);
    }

    // Criterio 6

    /**
     * @return iterable<string, array{string, int}>
     */
    public static function validPriorities(): iterable
    {
        yield 'baja' => ['baja', 1];
        yield 'media' => ['media', 2];
        yield 'alta' => ['alta', 3];
        yield 'vacía usa media' => ['', 2];
    }

    #[DataProvider('validPriorities')]
    public function testValidPriorityIsMappedToInteger(string $priority, int $expected): void
    {
        [$data, $errors] = $this->validator->validate(['title' => 't', 'priority' => $priority]);

        $this->assertSame([], $errors);
        $this->assertSame($expected, $data['priority']);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidPriorities(): iterable
    {
        yield 'urgente' => ['urgente'];
        yield 'mayúsculas' => ['Alta'];
        yield 'número' => ['3'];
    }

    #[DataProvider('invalidPriorities')]
    public function testInvalidPriorityIsRejected(string $priority): void
    {
        [, $errors] = $this->validator->validate(['title' => 't', 'priority' => $priority]);

        $this->assertSame(['priority' => 'La prioridad debe ser baja, media o alta'], $errors);
    }

    // Entradas malformadas

    public function testNonStringValuesAreRejected(): void
    {
        [, $errors] = $this->validator->validate([
            'title' => ['a'],
            'description' => ['b'],
            'due_date' => ['2026-01-01'],
            'priority' => ['alta'],
        ]);

        $this->assertSame([
            'title' => 'Valor no válido',
            'description' => 'Valor no válido',
            'due_date' => 'Valor no válido',
            'priority' => 'Valor no válido',
        ], $errors);
    }

    public function testInvalidUtf8IsRejected(): void
    {
        [, $errors] = $this->validator->validate(['title' => "abc\xFF"]);

        $this->assertSame(['title' => 'Valor no válido'], $errors);
    }

    public function testReportsAllErrorsAtOnce(): void
    {
        [, $errors] = $this->validator->validate([
            'title' => '',
            'due_date' => 'mañana',
            'priority' => 'urgente',
        ]);

        $this->assertSame(['title', 'due_date', 'priority'], array_keys($errors));
    }
}
