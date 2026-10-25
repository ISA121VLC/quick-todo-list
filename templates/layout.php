<?php
/**
 * @var string $content HTML ya renderizado de la página.
 * @var bool $addButton Mostrar el botón "+" que abre el formulario de alta (solo en el listado).
 * @var ?string $csrfToken Si hay, se muestra el botón para salir.
 * @var Closure(mixed): string $e
 */
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Gestor de tareas</title>
    <link rel="icon" href="/logo.svg" type="image/svg+xml">
    <link rel="stylesheet" href="<?= $e(App\Assets::url('style.css')) ?>">
    <script src="<?= $e(App\Assets::url('app.js')) ?>" defer></script>
    <script src="<?= $e(App\Assets::url('efectos.js')) ?>" defer></script>
</head>
<body>
    <main>
        <header class="barra">
            <h1><a href="/">Gestor de tareas</a></h1>
            <?php if ($addButton): ?>
                <button type="button" class="boton-nueva" commandfor="nueva-tarea" command="show-modal" aria-haspopup="dialog">
                    <span aria-hidden="true">+</span>
                    <span class="solo-lectores">Nueva tarea</span>
                </button>
            <?php endif ?>
            <?php if ($csrfToken !== null): ?>
                <form method="post" action="/logout" class="salir">
                    <input type="hidden" name="<?= $e(App\Csrf::FIELD) ?>" value="<?= $e($csrfToken) ?>">
                    <button type="submit">Salir</button>
                </form>
            <?php endif ?>
        </header>
        <?= $content ?>
    </main>
</body>
</html>
