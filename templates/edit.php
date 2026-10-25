<?php
/**
 * @var int $id
 * @var string $filter Filtro activo del listado.
 * @var array<string, string> $form Valores del formulario.
 * @var array<string, string> $errors Errores por campo.
 * @var string $csrfToken
 * @var Closure(mixed): string $e
 */
?>
<h2>Editar tarea</h2>
<form method="post" action="/tasks/<?= $e($id) ?>">
    <?php require __DIR__ . '/hidden_fields.php' ?>

    <?php require __DIR__ . '/task_fields.php' ?>
    <p>
        <button type="submit">Guardar</button>
        <a href="/?estado=<?= $e($filter) ?>">Cancelar</a>
    </p>
</form>
