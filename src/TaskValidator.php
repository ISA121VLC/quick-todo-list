<?php

declare(strict_types=1);

namespace App;

/**
 * Valida y normaliza los datos de un formulario de tarea (crear o editar).
 */
final class TaskValidator
{
    public const TITLE_MAX = 200;
    public const DESCRIPTION_MAX = 2000;

    /** Etiqueta del formulario → valor almacenado en la columna priority. */
    public const PRIORITIES = ['baja' => 1, 'media' => 2, 'alta' => 3];
    public const DEFAULT_PRIORITY = 'media';

    private const INVALID = 'Valor no válido';

    /**
     * @param array<string, mixed> $input Datos crudos, normalmente $_POST.
     * @return array{
     *     0: array{title: string, description: string, due_date: ?string, priority: int},
     *     1: array<string, string>
     * } Datos normalizados y errores por campo (vacío si todo es válido).
     */
    public function validate(array $input): array
    {
        $errors = [];

        $title = $this->text($input, 'title', $errors);
        if (!isset($errors['title'])) {
            if ($title === '') {
                $errors['title'] = 'El título es obligatorio';
            } elseif (mb_strlen($title) > self::TITLE_MAX) {
                $errors['title'] = 'El título no puede superar ' . self::TITLE_MAX . ' caracteres';
            }
        }

        $description = $this->text($input, 'description', $errors);
        if (!isset($errors['description']) && mb_strlen($description) > self::DESCRIPTION_MAX) {
            $errors['description'] = 'La descripción no puede superar ' . self::DESCRIPTION_MAX . ' caracteres';
        }

        $dueDate = $this->text($input, 'due_date', $errors);
        if (!isset($errors['due_date']) && $dueDate !== '' && !self::isValidDate($dueDate)) {
            $errors['due_date'] = 'La fecha límite no es válida (formato AAAA-MM-DD)';
        }

        $priority = $this->text($input, 'priority', $errors);
        if ($priority === '') {
            $priority = self::DEFAULT_PRIORITY;
        }
        if (!isset($errors['priority']) && !isset(self::PRIORITIES[$priority])) {
            $errors['priority'] = 'La prioridad debe ser baja, media o alta';
        }

        $data = [
            'title' => $title,
            'description' => $description,
            'due_date' => $dueDate === '' ? null : $dueDate,
            'priority' => self::PRIORITIES[$priority] ?? self::PRIORITIES[self::DEFAULT_PRIORITY],
        ];

        return [$data, $errors];
    }

    /**
     * Lee un campo de texto opcional y le quita espacios al principio y al final.
     * Si no es una cadena UTF-8 válida, anota el error y devuelve ''.
     *
     * @param array<string, mixed> $input
     * @param array<string, string> $errors
     */
    private function text(array $input, string $field, array &$errors): string
    {
        $value = $input[$field] ?? '';
        if (!is_string($value) || !mb_check_encoding($value, 'UTF-8')) {
            $errors[$field] = self::INVALID;
            return '';
        }

        return mb_trim($value);
    }

    private static function isValidDate(string $value): bool
    {
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m) !== 1) {
            return false;
        }

        return checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
    }
}
