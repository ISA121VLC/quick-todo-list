<?php

declare(strict_types=1);

namespace App;

use DateTimeZone;
use Throwable;

/**
 * Handlers HTTP: renderizan una plantilla o responden con un error.
 */
final class TaskController
{
    /** Valores iniciales del formulario de alta. */
    private const EMPTY_FORM = [
        'title' => '',
        'description' => '',
        'due_date' => '',
        'priority' => TaskValidator::DEFAULT_PRIORITY,
    ];

    /**
     * @param DateTimeZone $timezone Zona en la que se calcula "hoy" (APP_TIMEZONE).
     */
    public function __construct(
        private TaskRepository $repo,
        private TaskValidator $validator,
        private Csrf $csrf,
        private Clock $clock,
        private DateTimeZone $timezone,
        private string $templatesDir,
    ) {
    }

    public function index(): void
    {
        $this->renderIndex(self::filter($_GET), self::EMPTY_FORM);
    }

    public function create(): void
    {
        [$data, $errors] = $this->validator->validate($_POST);
        if ($errors !== []) {
            $this->renderIndex(self::filter($_POST), self::submitted($_POST), $errors, 422);
            return;
        }

        $this->repo->create($data);
        $this->redirectToList(self::filter($_POST));
    }

    public function edit(string $id): void
    {
        $task = $this->repo->find((int) $id);
        if ($task === null) {
            $this->taskNotFound();
            return;
        }

        $this->renderEdit($task['id'], self::filter($_GET), self::formFromTask($task));
    }

    public function update(string $id): void
    {
        $task = $this->repo->find((int) $id);
        if ($task === null) {
            $this->taskNotFound();
            return;
        }

        [$data, $errors] = $this->validator->validate($_POST);
        if ($errors !== []) {
            $this->renderEdit($task['id'], self::filter($_POST), self::submitted($_POST), $errors, 422);
            return;
        }

        $this->repo->update($task['id'], $data);
        $this->redirectToList(self::filter($_POST));
    }

    public function toggle(string $id): void
    {
        if (!$this->repo->toggle((int) $id)) {
            $this->taskNotFound();
            return;
        }

        $this->redirectToList(self::filter($_POST));
    }

    public function delete(string $id): void
    {
        if (!$this->repo->delete((int) $id)) {
            $this->taskNotFound();
            return;
        }

        $this->redirectToList(self::filter($_POST));
    }

    /**
     * RF11: una tarea pendiente vence cuando su fecha límite es anterior a "hoy",
     * siendo "hoy" la fecha de $clock->now() en $timezone.
     *
     * @param array{due_date: ?string, completed: int} $task
     */
    public static function isOverdue(array $task, Clock $clock, DateTimeZone $timezone): bool
    {
        if ($task['completed'] || $task['due_date'] === null) {
            return false;
        }

        // Ambas en formato AAAA-MM-DD: la comparación de cadenas es cronológica.
        return $task['due_date'] < $clock->now()->setTimezone($timezone)->format('Y-m-d');
    }

    public function forbidden(): void
    {
        $this->error(403, 'Token CSRF no válido. Recarga la página e inténtalo de nuevo.');
    }

    public function notFound(): void
    {
        $this->error(404, 'Página no encontrada');
    }

    /**
     * @param list<string> $allowed
     */
    public function methodNotAllowed(array $allowed): void
    {
        $this->error(405, 'Método no permitido', ['Allow' => implode(', ', $allowed)]);
    }

    /**
     * @param string $filter Filtro activo (válido).
     * @param array<string, string> $form Valores a mostrar en el formulario de alta.
     * @param array<string, string> $errors Errores por campo.
     */
    private function renderIndex(string $filter, array $form, array $errors = [], int $status = 200): void
    {
        $tasks = array_map(
            fn (array $task): array => $task + ['overdue' => self::isOverdue($task, $this->clock, $this->timezone)],
            $this->repo->all($filter),
        );

        $this->render('index', [
            'tasks' => $tasks,
            'filter' => $filter,
            'addButton' => true,
            'form' => $form,
            'errors' => $errors,
            'csrfToken' => $this->csrf->token(),
        ], $status);
    }

    /**
     * @param string $filter Filtro activo (válido), para volver al listado con él.
     * @param array<string, string> $form Valores a mostrar en el formulario de edición.
     * @param array<string, string> $errors Errores por campo.
     */
    private function renderEdit(int $id, string $filter, array $form, array $errors = [], int $status = 200): void
    {
        $this->render('edit', [
            'id' => $id,
            'filter' => $filter,
            'form' => $form,
            'errors' => $errors,
            'csrfToken' => $this->csrf->token(),
        ], $status);
    }

    /**
     * Valores actuales de la tarea, en el formato del formulario.
     *
     * @param array{title: string, description: string, due_date: ?string, priority: int} $task
     * @return array<string, string>
     */
    private static function formFromTask(array $task): array
    {
        return [
            'title' => $task['title'],
            'description' => $task['description'],
            'due_date' => $task['due_date'] ?? '',
            'priority' => array_search($task['priority'], TaskValidator::PRIORITIES, true) ?: TaskValidator::DEFAULT_PRIORITY,
        ];
    }

    /**
     * Valores introducidos por el usuario, tal cual, para volver a mostrarlos tras un 422.
     *
     * @param array<string, mixed> $input
     * @return array<string, string>
     */
    private static function submitted(array $input): array
    {
        $form = [];
        foreach (self::EMPTY_FORM as $field => $default) {
            $value = $input[$field] ?? $default;
            $form[$field] = is_string($value) ? $value : '';
        }

        return $form;
    }

    /**
     * Filtro de estado de la petición (campo `estado` de $_GET o $_POST); si falta o no es válido, 'todas'.
     *
     * @param array<string, mixed> $input
     */
    private static function filter(array $input): string
    {
        $value = $input['estado'] ?? null;

        return is_string($value) && array_key_exists($value, TaskRepository::FILTERS)
            ? $value
            : TaskRepository::DEFAULT_FILTER;
    }

    /**
     * Post/Redirect/Get: 303 al listado conservando el filtro (RF12). 'todas' va a '/' sin parámetro.
     */
    private function redirectToList(string $filter): void
    {
        $location = $filter === TaskRepository::DEFAULT_FILTER ? '/' : '/?estado=' . rawurlencode($filter);
        header("Location: $location", true, 303);
    }

    private function taskNotFound(): void
    {
        $this->error(404, 'La tarea no existe');
    }

    /**
     * @param array<string, string> $headers
     */
    private function error(int $status, string $message, array $headers = []): void
    {
        $this->render('error', ['status' => $status, 'message' => $message], $status, $headers);
    }

    /**
     * @param array<string, mixed> $vars
     * @param array<string, string> $headers
     */
    private function render(string $template, array $vars = [], int $status = 200, array $headers = []): void
    {
        $e = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $content = $this->capture($template, $vars + ['e' => $e]);
        $page = $this->capture('layout', ['content' => $content, 'e' => $e, 'addButton' => $vars['addButton'] ?? false]);

        http_response_code($status);
        header('Content-Type: text/html; charset=UTF-8');
        foreach ($headers as $name => $value) {
            header("$name: $value");
        }
        echo $page;
    }

    /**
     * @param array<string, mixed> $vars Variables visibles en la plantilla.
     */
    private function capture(string $template, array $vars): string
    {
        extract($vars);
        ob_start();
        try {
            require $this->templatesDir . "/$template.php";
        } catch (Throwable $ex) {
            ob_end_clean();
            throw $ex;
        }

        return (string) ob_get_clean();
    }
}
