<?php
/**
 * Portada pública. Pulsar en cualquier parte abre el diálogo de contraseña (landing.js);
 * sin JS, el enlace "Entrar" lleva a /?entrar, que lo sirve ya abierto.
 *
 * @var string $csrfToken
 * @var bool $dialogOpen Servir el diálogo abierto (error de acceso o /?entrar).
 * @var ?string $error Mensaje de error del último intento.
 * @var int $retryAfter Segundos de bloqueo restantes (0 si no hay bloqueo).
 * @var int $maxLength Longitud máxima de la contraseña.
 * @var Closure(mixed): string $e
 */

use App\Csrf;

$tasks = [
    ['Preparar la presentación', 'alta'],
    ['Llamar al dentista', 'media'],
    ['Comprar fruta', 'baja'],
    ['Regar las plantas', 'baja'],
];
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title>quick-todo-list · Tus tareas, bajo control</title>
    <meta name="description" content="Una lista de tareas sencilla: apunta, prioriza y tacha.">
    <link rel="icon" href="/logo.svg" type="image/svg+xml">
    <link rel="stylesheet" href="/style.css">
    <link rel="stylesheet" href="/landing.css">
    <script src="/landing.js" defer></script>
</head>
<body class="portada">
    <div class="landing" id="landing">
        <header class="landing-marca">
            <img src="/logo.svg" alt="" width="36" height="36">
            <span>quick-todo-list</span>
        </header>

        <section class="hero">
            <div class="hero-texto">
                <p class="sobretitulo">¿Qué es una to-do list?</p>
                <h1>Tu cabeza es para <span class="degradado">tener ideas</span>, no para recordarlas.</h1>
                <p class="explica">
                    Una <strong>lista de tareas</strong> es muy simple: <strong>apuntas</strong> lo que tienes que hacer,
                    decides <strong>qué va primero</strong> y lo <strong>tachas</strong> cuando está hecho.
                    Menos agobio, más cosas terminadas.
                </p>
                <a class="boton-entrar" href="/?entrar" id="entrar">
                    <span aria-hidden="true">🔒</span> Entrar
                </a>
            </div>

            <div class="demo" aria-hidden="true">
                <div class="demo-tarjeta">
                    <div class="demo-cabecera">
                        <strong>Hoy</strong>
                        <span class="demo-hecho">¡Todo hecho! 🎉</span>
                    </div>
                    <div class="demo-barra"><span></span></div>
                    <ul class="demo-lista">
                        <?php foreach ($tasks as $i => [$title, $priority]): ?>
                            <li class="t<?= $i + 1 ?>">
                                <span class="casilla"><svg viewBox="0 0 24 24" width="16" height="16"><path d="M5 12.5l4.5 4.5L19 7.5"/></svg></span>
                                <span class="texto"><?= $e($title) ?></span>
                                <span class="chip <?= $e($priority) ?>"><?= $e($priority) ?></span>
                            </li>
                        <?php endforeach ?>
                    </ul>
                </div>
                <span class="chispa c1">✦</span>
                <span class="chispa c2">✓</span>
                <span class="chispa c3">✦</span>
            </div>
        </section>

        <section class="pasos">
            <article class="paso">
                <span class="paso-num">1</span>
                <h2>Apunta</h2>
                <p>Saca todo de tu cabeza. Un título, una nota y una fecha límite si la hay.</p>
            </article>
            <article class="paso">
                <span class="paso-num">2</span>
                <h2>Prioriza</h2>
                <p>Alta, media o baja. Lo urgente sube solo y lo vencido se marca en rojo.</p>
            </article>
            <article class="paso">
                <span class="paso-num">3</span>
                <h2>Tacha</h2>
                <p>Marca como hecho y siente cómo avanza el día. Esa es la mejor parte.</p>
            </article>
        </section>
    </div>

    <p class="pista" aria-hidden="true"><span class="pulso"></span>Toca en cualquier parte para entrar</p>

    <dialog id="entrada" class="nueva-tarea entrada" aria-labelledby="entrada-titulo" closedby="any"<?= $dialogOpen ? ' open data-abierto' : '' ?>>
        <button type="button" class="cerrar" commandfor="entrada" command="close">
            <span aria-hidden="true">×</span>
            <span class="solo-lectores">Cerrar</span>
        </button>
        <h2 id="entrada-titulo">Entrar</h2>
        <form method="post" action="/login" autocomplete="off">
            <input type="hidden" name="<?= $e(Csrf::FIELD) ?>" value="<?= $e($csrfToken) ?>">
            <p>
                <label for="password">Contraseña</label>
                <input type="password" id="password" name="password" maxlength="<?= $e($maxLength) ?>"
                       autocomplete="current-password" required<?= $retryAfter > 0 ? ' disabled' : '' ?>>
                <span class="contador" aria-hidden="true"><output id="cuenta">0</output>/<?= $e($maxLength) ?></span>
            </p>
            <?php if ($error !== null): ?>
                <p class="error" role="alert" id="error-acceso"<?= $retryAfter > 0 ? ' data-espera="' . $e($retryAfter) . '"' : '' ?>><?= $e($error) ?></p>
            <?php endif ?>
            <p><button type="submit"<?= $retryAfter > 0 ? ' disabled' : '' ?>>Entrar</button></p>
        </form>
    </dialog>
</body>
</html>
