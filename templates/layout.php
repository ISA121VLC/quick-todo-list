<?php
/**
 * @var string $content HTML ya renderizado de la página.
 * @var bool $addButton Mostrar el botón "+" que abre el formulario de alta (solo en el listado).
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
    <link rel="stylesheet" href="/style.css">
    <script src="/app.js" defer></script>
    <script src="/efectos.js" defer></script>
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
        </header>
        <?= $content ?>
    </main>
</body>
</html>
