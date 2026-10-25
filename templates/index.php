<?php
/**
 * @var list<array<string, mixed>> $tasks Filas de la BD más la clave 'overdue' (RF11).
 * @var string $filter Filtro activo.
 * @var array<string, string> $form Valores del formulario de alta.
 * @var array<string, string> $errors Errores por campo.
 * @var string $csrfToken
 * @var Closure(mixed): string $e
 */

use App\TaskRepository;
use App\TaskValidator;

$priorityLabels = array_flip(TaskValidator::PRIORITIES);
$filterLabels = ['todas' => 'Todas', 'pendientes' => 'Pendientes', 'completadas' => 'Completadas'];

// El formulario de alta vive en un <dialog> modal que abre el botón "+" de la cabecera.
// Si hay errores (422) el servidor lo marca como abierto y app.js lo convierte en modal.
$formOpen = $errors !== [];
$autofocus = true;
?>
<dialog id="nueva-tarea" class="nueva-tarea" aria-labelledby="nueva-tarea-titulo" closedby="any"<?= $formOpen ? ' open data-con-errores' : '' ?>>
    <button type="button" class="cerrar" commandfor="nueva-tarea" command="close">
        <span aria-hidden="true">×</span>
        <span class="solo-lectores">Cerrar</span>
    </button>
    <h2 id="nueva-tarea-titulo">Nueva tarea</h2>
    <form method="post" action="/tasks">
        <?php require __DIR__ . '/hidden_fields.php' ?>

        <?php require __DIR__ . '/task_fields.php' ?>
        <p><button type="submit">Crear</button></p>
    </form>
</dialog>

<h2>Tareas</h2>
<nav class="filtros">
    <?php foreach (array_keys(TaskRepository::FILTERS) as $value): ?>
        <?php if ($value === $filter): ?>
            <a href="/?estado=<?= $e($value) ?>" class="activo" aria-current="page"><?= $e($filterLabels[$value]) ?></a>
        <?php else: ?>
            <a href="/?estado=<?= $e($value) ?>"><?= $e($filterLabels[$value]) ?></a>
        <?php endif ?>
    <?php endforeach ?>
</nav>
<?php if ($tasks === []): ?>
    <p class="vacio">No hay tareas</p>
<?php else: ?>
    <ul class="tareas">
        <?php foreach ($tasks as $task): ?>
            <?php
            $priority = $priorityLabels[$task['priority']] ?? '';
            $classes = ['tarea', 'prioridad-' . $priority];
            if ($task['completed']) {
                $classes[] = 'completada';
            }
            if ($task['overdue']) {
                $classes[] = 'vencida';
            }
            ?>
            <li class="<?= $e(implode(' ', $classes)) ?>">
                <div class="cabecera">
                    <strong class="titulo"><?= $e($task['title']) ?></strong>
                    <span class="prioridad">Prioridad: <?= $e($priority) ?></span>
                    <?php if ($task['overdue']): ?><span class="etiqueta-vencida">Vencida</span><?php endif ?>
                </div>
                <?php if ($task['description'] !== ''): ?><p class="descripcion"><?= $e($task['description']) ?></p><?php endif ?>
                <div class="detalles">
                    <span>Fecha límite: <?= $task['due_date'] !== null ? $e($task['due_date']) : 'sin fecha' ?></span>
                    <span>Estado: <?= $task['completed'] ? 'completada' : 'pendiente' ?></span>
                    <?php if ($task['created_by'] !== ''): ?><span>Creada por: <?= $e($task['created_by']) ?></span><?php endif ?>
                </div>
                <div class="acciones">
                    <a href="/tasks/<?= $e($task['id']) ?>/edit?estado=<?= $e($filter) ?>">Editar</a>
                    <form method="post" action="/tasks/<?= $e($task['id']) ?>/toggle">
                        <?php require __DIR__ . '/hidden_fields.php' ?>
                        <button type="submit"><?= $task['completed'] ? 'Marcar pendiente' : 'Completar' ?></button>
                    </form>
                    <form method="post" action="/tasks/<?= $e($task['id']) ?>/delete">
                        <?php require __DIR__ . '/hidden_fields.php' ?>
                        <button type="submit" class="peligro">Borrar</button>
                    </form>
                </div>
            </li>
        <?php endforeach ?>
    </ul>
<?php endif ?>
