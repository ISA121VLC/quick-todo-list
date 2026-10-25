<?php

declare(strict_types=1);

use App\Auth;
use App\AuthController;
use App\ClientIp;
use App\Csrf;
use App\Database;
use App\FixedClock;
use App\LoginThrottle;
use App\Router;
use App\SystemClock;
use App\TaskController;
use App\TaskRepository;
use App\TaskValidator;

// Autoloader mínimo para que la app funcione sin vendor/.
spl_autoload_register(static function (string $class): void {
    if (str_starts_with($class, 'App\\')) {
        $file = __DIR__ . '/../src/' . str_replace('\\', '/', substr($class, 4)) . '.php';
        if (is_file($file)) {
            require $file;
        }
    }
});

$root = dirname(__DIR__);

session_start([
    'use_strict_mode' => true,
    'cookie_httponly' => true,
    'cookie_samesite' => 'Lax',
    'cookie_secure' => ClientIp::isSecure($_SERVER),
]);
$csrf = new Csrf($_SESSION);

$appNow = getenv('APP_NOW');
$clock = $appNow !== false && $appNow !== ''
    ? new FixedClock(new DateTimeImmutable($appNow))
    : new SystemClock();

$appTimezone = getenv('APP_TIMEZONE');
$timezone = new DateTimeZone($appTimezone !== false && $appTimezone !== '' ? $appTimezone : date_default_timezone_get());

$dbPath = getenv('TASKS_DB_PATH');
$pdo = Database::connect($dbPath !== false && $dbPath !== '' ? $dbPath : $root . '/data/tasks.sqlite');

$passwordFile = getenv('APP_PASSWORD_FILE');
$auth = new Auth($_SESSION, Auth::loadHash($passwordFile !== false && $passwordFile !== '' ? $passwordFile : $root . '/data/password.hash'));
$authController = new AuthController($auth, new LoginThrottle($pdo, $clock), $csrf, $root . '/templates');

$controller = new TaskController(
    new TaskRepository($pdo, $clock),
    new TaskValidator(),
    $auth,
    $csrf,
    $clock,
    $timezone,
    $root . '/templates',
);

$router = new Router();
$router->get('/', $auth->isLoggedIn() ? $controller->index(...) : $authController->landing(...));
$router->post('/login', $authController->login(...));
$router->post('/logout', $authController->logout(...));
$router->post('/tasks', $controller->create(...));
$router->get('/tasks/{id}/edit', $controller->edit(...));
$router->post('/tasks/{id}', $controller->update(...));
$router->post('/tasks/{id}/toggle', $controller->toggle(...));
$router->post('/tasks/{id}/delete', $controller->delete(...));

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$match = $router->match($method, is_string($path) ? $path : '/');

if ($match['status'] === 404) {
    $controller->notFound();
} elseif ($match['status'] === 405) {
    $controller->methodNotAllowed($match['allowed']);
} elseif (!$auth->isLoggedIn() && !in_array($path, ['/', '/login'], true)) {
    // Todo salvo la portada y el acceso exige haber entrado.
    header('Location: /', true, 303);
} elseif ($method === 'POST' && !$csrf->verify($_POST[Csrf::FIELD] ?? null)) {
    // Todas las rutas POST modifican datos: exigen el token CSRF de la sesión.
    $controller->forbidden();
} else {
    ($match['handler'])(...$match['params']);
}
