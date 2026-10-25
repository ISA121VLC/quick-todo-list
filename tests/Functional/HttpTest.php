<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Csrf;
use App\Database;
use Dom\HTMLDocument;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Lanza la app con `php -S` en un puerto libre y le hace peticiones reales con ext-curl.
 * La BD es un fichero temporal (TASKS_DB_PATH): nunca se toca data/tasks.sqlite.
 */
final class HttpTest extends TestCase
{
    private const STARTUP_TIMEOUT = 5.0;
    private const POLL_INTERVAL_US = 20_000;
    private const START_ATTEMPTS = 3;
    private const PASSWORD = 'clave-de-prueba';

    /** @var resource|null */
    private static $process = null;
    private static string $tmpDir;
    private static string $dbPath;
    private static string $baseUrl;
    /** Cookie de sesión del "navegador" del test actual. */
    private static ?string $cookie = null;

    public static function setUpBeforeClass(): void
    {
        self::$tmpDir = sys_get_temp_dir() . '/gestor-tareas-http-' . bin2hex(random_bytes(6));
        mkdir(self::$tmpDir);
        self::$dbPath = self::$tmpDir . '/tasks.sqlite';

        // Red de seguridad: si PHPUnit muere sin llegar a tearDownAfterClass, el servidor no queda vivo.
        register_shutdown_function([self::class, 'stopServer']);

        self::startServer();
    }

    public static function tearDownAfterClass(): void
    {
        self::stopServer();
        // BD, log y ficheros de sesión.
        array_map(unlink(...), glob(self::$tmpDir . '/*') ?: []);
        @rmdir(self::$tmpDir);
    }

    protected function setUp(): void
    {
        // Cada test empieza con la BD vacía; el servidor abre la conexión en cada petición.
        @unlink(self::$dbPath);
        self::$cookie = null;
        $this->login();
    }

    /**
     * Inicia sesión como lo haría el navegador: portada, token CSRF y POST /login.
     */
    private function login(string $password = self::PASSWORD, string $name = 'Tester'): array
    {
        $body = $this->request('GET', '/')['body'];
        $field = preg_quote(Csrf::FIELD, '/');
        $this->assertSame(1, preg_match("/name=\"$field\" value=\"([0-9a-f]+)\"/", $body, $m), 'Falta el token CSRF de la portada');

        return $this->request('POST', '/login', [Csrf::FIELD => $m[1], 'nombre' => $name, 'password' => $password]);
    }

    public function testEmptyDatabaseShowsNoTasks(): void
    {
        $response = $this->request('GET', '/');

        $this->assertSame(200, $response['status']);
        $this->assertStringContainsString('No hay tareas', $response['body']);
        $this->assertFileExists(self::$dbPath, 'El servidor debe usar la BD de TASKS_DB_PATH');
    }

    public function testGetOnPostOnlyRouteIs405(): void
    {
        $response = $this->request('GET', '/tasks/1/delete');

        $this->assertSame(405, $response['status']);
        $this->assertSame('POST', $response['headers']['allow'] ?? null);
    }

    public function testToggleOnMissingTaskIs404(): void
    {
        $this->assertSame(404, $this->submit('/tasks/999/toggle', [])['status']);
    }

    public function testUnknownRouteIs404(): void
    {
        $this->assertSame(404, $this->request('GET', '/no-existe')['status']);
    }

    public function testCreateRedirectsAndTaskIsListedWithDefaultPriority(): void
    {
        $response = $this->submit('/tasks', ['title' => 'Comprar pan']);

        $this->assertSame(303, $response['status']);
        $this->assertSame('/', $response['headers']['location'] ?? null);

        $body = $this->request('GET', '/')['body'];
        $this->assertStringContainsString('Comprar pan', $body);
        $this->assertStringContainsString('Prioridad: media', $body);
        $this->assertStringNotContainsString('No hay tareas', $body);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function blankTitles(): iterable
    {
        yield 'vacío' => [''];
        yield 'solo espacios' => ['   '];
    }

    #[DataProvider('blankTitles')]
    public function testBlankTitleIs422AndNothingIsSaved(string $title): void
    {
        $response = $this->submit('/tasks', ['title' => $title, 'description' => 'Se conserva']);

        $this->assertSame(422, $response['status']);
        $this->assertStringContainsString('El título es obligatorio', $response['body']);
        $this->assertStringContainsString('Se conserva', $response['body'], 'Los valores introducidos se conservan');
        $this->assertSame(0, $this->countTasks());
    }

    public function testTitleLengthLimitIs200Characters(): void
    {
        $tooLong = $this->submit('/tasks', ['title' => str_repeat('ñ', 201), 'priority' => 'alta']);
        $this->assertSame(422, $tooLong['status']);
        $this->assertStringContainsString('<option value="alta" selected>', $tooLong['body']);
        $this->assertSame(0, $this->countTasks());

        $this->assertSame(303, $this->submit('/tasks', ['title' => str_repeat('ñ', 200)])['status']);
        $this->assertSame(1, $this->countTasks());
    }

    /**
     * @return iterable<string, array{array<string, string>}>
     */
    public static function invalidCsrfTokens(): iterable
    {
        yield 'sin token' => [[]];
        yield 'token incorrecto' => [[Csrf::FIELD => 'incorrecto']];
    }

    /**
     * @param array<string, string> $token
     */
    #[DataProvider('invalidCsrfTokens')]
    public function testPostWithoutValidCsrfTokenIs403AndChangesNothing(array $token): void
    {
        $this->csrfToken(); // la sesión existe y tiene token, pero no se envía el correcto

        $response = $this->request('POST', '/tasks', ['title' => 'No debe crearse'] + $token);

        $this->assertSame(403, $response['status']);
        $this->assertSame(0, $this->countTasks());
    }

    public function testPostWithoutLoginRedirectsToLandingAndChangesNothing(): void
    {
        self::$cookie = null;

        $response = $this->request('POST', '/tasks', ['title' => 'X', Csrf::FIELD => '']);

        $this->assertSame(303, $response['status']);
        $this->assertSame('/', $response['headers']['location'] ?? null);
        $this->assertSame(0, $this->countTasks());
    }

    public function testGuestSeesLandingInsteadOfTasks(): void
    {
        self::$cookie = null;

        $response = $this->request('GET', '/');

        $this->assertSame(200, $response['status']);
        $this->assertStringContainsString('id="entrada"', $response['body']);
        $this->assertStringNotContainsString('Nueva tarea', $response['body']);
        $this->assertStringNotContainsString(' open', explode('<dialog', $response['body'])[1] ?? '', 'El diálogo empieza cerrado');
    }

    public function testGuestCannotOpenEditOrListRoutes(): void
    {
        $this->createTask('Privada');
        self::$cookie = null;

        $this->assertSame(303, $this->request('GET', '/tasks/' . $this->lastTaskId() . '/edit')['status']);
    }

    public function testEnterQueryServesTheDialogOpen(): void
    {
        self::$cookie = null;

        $this->assertMatchesRegularExpression('/<dialog[^>]* open/', $this->request('GET', '/?entrar')['body']);
    }

    public function testLoginRejectsWrongAndOverlongPasswordsWithoutLoggingIn(): void
    {
        self::$cookie = null;

        $wrong = $this->login('incorrecta');
        $this->assertSame(401, $wrong['status']);
        $this->assertStringContainsString('Contraseña incorrecta', $wrong['body']);
        $this->assertStringContainsString('Te quedan 2 intentos', $wrong['body']);

        $long = $this->login(str_repeat('a', 21));
        $this->assertSame(401, $long['status']);

        $this->assertStringContainsString('id="entrada"', $this->request('GET', '/')['body']);
    }

    public function testLandingAssetsCarryAVersionSoCachesDoNotServeOldOnes(): void
    {
        self::$cookie = null;

        $body = $this->request('GET', '/')['body'];

        $this->assertMatchesRegularExpression('#href="/landing\.css\?v=\d+"#', $body);
        $this->assertMatchesRegularExpression('#src="/landing\.js\?v=\d+"#', $body);
        $this->assertSame(200, $this->request('GET', '/landing.js?v=1')['status']);
    }

    public function testPasswordFieldIsLimitedTo20Characters(): void
    {
        self::$cookie = null;

        $this->assertStringContainsString('maxlength="20"', $this->request('GET', '/')['body']);
    }

    public function testThreeFailuresLockTheIpEvenWithTheRightPassword(): void
    {
        self::$cookie = null;

        $this->assertSame(401, $this->login('mal1')['status']);
        $this->assertSame(401, $this->login('mal2')['status']);
        $third = $this->login('mal3');
        $this->assertSame(429, $third['status']);
        $this->assertSame('120', $third['headers']['retry-after'] ?? null);

        $correct = $this->login();
        $this->assertSame(429, $correct['status'], 'Bloqueada: ni la contraseña correcta entra');
        $this->assertStringContainsString('id="entrada"', $this->request('GET', '/')['body']);
    }

    public function testSuccessfulLoginRedirectsAndShowsTasksAndLogoutReturnsToLanding(): void
    {
        $response = $this->request('GET', '/');
        $this->assertStringContainsString('Nueva tarea', $response['body']);
        $this->assertStringContainsString('action="/logout"', $response['body']);

        $logout = $this->submit('/logout', []);
        $this->assertSame(303, $logout['status']);
        $this->assertStringContainsString('id="entrada"', $this->request('GET', '/')['body']);
    }

    public function testLoginAsksForAName(): void
    {
        self::$cookie = null;
        $body = $this->request('GET', '/')['body'];
        $this->assertStringContainsString('name="nombre"', $body);

        $response = $this->login(name: '   ');

        $this->assertSame(422, $response['status']);
        $this->assertStringContainsString('Escribe tu nombre', $response['body']);
        $this->assertStringContainsString('id="entrada"', $this->request('GET', '/')['body'], 'Sin nombre no se entra');
    }

    public function testLoginRejectsTooLongOrControlCharacterNames(): void
    {
        self::$cookie = null;

        $this->assertSame(422, $this->login(name: str_repeat('a', 31))['status']);
        $this->assertSame(422, $this->login(name: "Ana")['status']);
    }

    public function testLoginSetsLongLivedNameCookieAndPrefillsItNextTime(): void
    {
        self::$cookie = null;

        $response = $this->login(name: '  Nacho   García ');

        $this->assertSame(303, $response['status']);
        $cookie = current(array_filter($response['cookies'], static fn (string $c): bool => str_starts_with($c, 'quien=')));
        $this->assertNotFalse($cookie, 'Falta la cookie con el nombre');
        $this->assertStringStartsWith('quien=Nacho%20Garc', $cookie);
        $this->assertSame(1, preg_match('/expires=([^;]+)/i', $cookie, $m));
        $this->assertGreaterThan(time() + 5 * 365 * 86400, strtotime($m[1]), 'La cookie debe durar años');

        // Nueva visita sin sesión pero con la cookie del nombre: el campo viene rellenado.
        self::$cookie = 'quien=Nacho%20Garc%C3%ADa';
        $this->assertStringContainsString('value="Nacho García"', $this->request('GET', '/')['body']);
    }

    public function testTaskRecordsWhoCreatedItAndShowsIt(): void
    {
        self::$cookie = null;
        $this->login(name: 'Marta');
        $this->submit('/tasks', ['title' => 'Hecha por Marta']);

        self::$cookie = null;
        $this->login(name: 'Pepe');
        $this->submit('/tasks', ['title' => 'Hecha por Pepe']);

        $authors = $this->pdo()->query('SELECT title, created_by FROM tasks ORDER BY id')->fetchAll(PDO::FETCH_KEY_PAIR);
        $this->assertSame(['Hecha por Marta' => 'Marta', 'Hecha por Pepe' => 'Pepe'], $authors);

        $body = $this->request('GET', '/')['body'];
        $this->assertStringContainsString('Creada por: Marta', $body);
        $this->assertStringContainsString('Creada por: Pepe', $body);
    }

    public function testAuthorNameIsEscaped(): void
    {
        self::$cookie = null;
        $this->login(name: '<b>x</b>');
        $this->submit('/tasks', ['title' => 'T']);

        $body = $this->request('GET', '/')['body'];
        $this->assertStringContainsString('Creada por: &lt;b&gt;x&lt;/b&gt;', $body);
        $this->assertStringNotContainsString('<b>x</b>', $body);
    }

    public function testLoginSuccessRedirectsToRoot(): void
    {
        self::$cookie = null;

        $response = $this->login();

        $this->assertSame(303, $response['status']);
        $this->assertSame('/', $response['headers']['location'] ?? null);
    }

    public function testToggleSwitchesCompletedBackAndForth(): void
    {
        $id = $this->createTask('Regar plantas');
        $this->assertStringContainsString('action="/tasks/' . $id . '/toggle"', $this->request('GET', '/')['body']);

        $first = $this->submit("/tasks/$id/toggle", []);
        $this->assertSame(303, $first['status']);
        $this->assertSame('/', $first['headers']['location'] ?? null);
        $this->assertSame(1, $this->completed($id));
        $this->assertStringContainsString('Estado: completada', $this->request('GET', '/')['body']);

        $this->assertSame(303, $this->submit("/tasks/$id/toggle", [])['status']);
        $this->assertSame(0, $this->completed($id));
        $this->assertStringContainsString('Estado: pendiente', $this->request('GET', '/')['body']);
    }

    public function testDeleteRemovesTheTask(): void
    {
        $id = $this->createTask('Tarea a borrar');
        $this->createTask('Tarea que se queda');
        $this->assertStringContainsString('action="/tasks/' . $id . '/delete"', $this->request('GET', '/')['body']);

        $response = $this->submit("/tasks/$id/delete", []);

        $this->assertSame(303, $response['status']);
        $this->assertSame('/', $response['headers']['location'] ?? null);
        $body = $this->request('GET', '/')['body'];
        $this->assertStringNotContainsString('Tarea a borrar', $body);
        $this->assertStringContainsString('Tarea que se queda', $body);
        $this->assertFalse($this->pdo()->query("SELECT 1 FROM tasks WHERE id = $id")->fetchColumn());
    }

    public function testDeleteOnMissingTaskIs404(): void
    {
        $this->assertSame(404, $this->submit('/tasks/999/delete', [])['status']);
    }

    public function testEditFormIsPrefilledWithCurrentValues(): void
    {
        $this->submit('/tasks', [
            'title' => 'Original',
            'description' => 'Descripción original',
            'due_date' => '2026-04-01',
            'priority' => 'baja',
        ]);
        $id = $this->lastTaskId();
        $this->assertStringContainsString('href="/tasks/' . $id . '/edit?estado=todas"', $this->request('GET', '/')['body']);

        $response = $this->request('GET', "/tasks/$id/edit");

        $this->assertSame(200, $response['status']);
        $body = $response['body'];
        $this->assertStringContainsString('action="/tasks/' . $id . '"', $body);
        $this->assertStringContainsString('name="title" value="Original"', $body);
        $this->assertStringContainsString('>Descripción original</textarea>', $body);
        $this->assertStringContainsString('name="due_date" value="2026-04-01"', $body);
        $this->assertStringContainsString('<option value="baja" selected>', $body);
        $this->assertStringContainsString('<a href="/?estado=todas">Cancelar</a>', $body);
    }

    public function testEditUpdatesAllFieldsAndListingShowsNewValues(): void
    {
        $id = $this->createTask('Título viejo');

        $response = $this->submit("/tasks/$id", [
            'title' => 'Título nuevo',
            'description' => 'Descripción nueva',
            'due_date' => '2026-12-31',
            'priority' => 'alta',
        ]);

        $this->assertSame(303, $response['status']);
        $this->assertSame('/', $response['headers']['location'] ?? null);
        $body = $this->request('GET', '/')['body'];
        $this->assertStringNotContainsString('Título viejo', $body);
        $this->assertStringContainsString('Título nuevo', $body);
        $this->assertStringContainsString('Descripción nueva', $body);
        $this->assertStringContainsString('Fecha límite: 2026-12-31', $body);
        $this->assertStringContainsString('Prioridad: alta', $body);
    }

    public function testInvalidEditIs422AndKeepsTheTaskUnchanged(): void
    {
        $id = $this->createTask('Sin cambios');

        $response = $this->submit("/tasks/$id", ['title' => '  ', 'description' => 'Escrito por el usuario']);

        $this->assertSame(422, $response['status']);
        $this->assertStringContainsString('El título es obligatorio', $response['body']);
        $this->assertStringContainsString('>Escrito por el usuario</textarea>', $response['body']);
        $this->assertStringContainsString('action="/tasks/' . $id . '"', $response['body']);
        $row = $this->pdo()->query("SELECT title, description FROM tasks WHERE id = $id")->fetch();
        $this->assertSame(['title' => 'Sin cambios', 'description' => ''], $row);
    }

    public function testEditingMissingTaskIs404(): void
    {
        $this->assertSame(404, $this->request('GET', '/tasks/999/edit')['status']);
        $this->assertSame(404, $this->submit('/tasks/999', ['title' => 'X'])['status']);
    }

    public function testFilterShowsOnlyMatchingTasks(): void
    {
        $this->createTask('Tarea pendiente');
        $this->submit('/tasks/' . $this->createTask('Tarea completada') . '/toggle', []);

        $pending = $this->request('GET', '/?estado=pendientes')['body'];
        $this->assertStringContainsString('Tarea pendiente', $pending);
        $this->assertStringNotContainsString('Tarea completada', $pending);

        $completed = $this->request('GET', '/?estado=completadas')['body'];
        $this->assertStringNotContainsString('Tarea pendiente', $completed);
        $this->assertStringContainsString('Tarea completada', $completed);

        foreach (['/', '/?estado=todas', '/?estado=xyz'] as $path) {
            $all = $this->request('GET', $path)['body'];
            $this->assertStringContainsString('Tarea pendiente', $all, $path);
            $this->assertStringContainsString('Tarea completada', $all, $path);
        }
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function activeFilterLinks(): iterable
    {
        yield 'sin estado' => ['/', 'todas'];
        yield 'pendientes' => ['/?estado=pendientes', 'pendientes'];
        yield 'completadas' => ['/?estado=completadas', 'completadas'];
        yield 'desconocido' => ['/?estado=xyz', 'todas'];
    }

    #[DataProvider('activeFilterLinks')]
    public function testFilterLinksMarkTheActiveOne(string $path, string $active): void
    {
        $body = $this->request('GET', $path)['body'];

        foreach (['todas', 'pendientes', 'completadas'] as $value) {
            $this->assertStringContainsString('href="/?estado=' . $value . '"', $body);
        }
        $this->assertSame(1, substr_count($body, 'aria-current="page"'));
        $this->assertStringContainsString('<a href="/?estado=' . $active . '" class="activo" aria-current="page">', $body);
    }

    /**
     * @return iterable<string, array{string, array<string, string>, string}>
     */
    public static function redirectsKeepingTheFilter(): iterable
    {
        yield 'toggle con pendientes' => ['toggle', ['estado' => 'pendientes'], '/?estado=pendientes'];
        yield 'crear con completadas' => ['create', ['estado' => 'completadas'], '/?estado=completadas'];
        yield 'editar con pendientes' => ['update', ['estado' => 'pendientes'], '/?estado=pendientes'];
        yield 'borrar con xyz' => ['delete', ['estado' => 'xyz'], '/'];
        yield 'borrar sin estado' => ['delete', [], '/'];
        yield 'toggle con todas' => ['toggle', ['estado' => 'todas'], '/'];
    }

    /**
     * @param array<string, string> $estado
     */
    #[DataProvider('redirectsKeepingTheFilter')]
    public function testSuccessfulPostRedirectsKeepingTheFilter(string $action, array $estado, string $location): void
    {
        $id = $this->createTask('Tarea');
        [$path, $form] = match ($action) {
            'create' => ['/tasks', ['title' => 'Otra']],
            'update' => ["/tasks/$id", ['title' => 'Editada']],
            'toggle' => ["/tasks/$id/toggle", []],
            'delete' => ["/tasks/$id/delete", []],
        };

        $response = $this->submit($path, $form + $estado);

        $this->assertSame(303, $response['status']);
        $this->assertSame($location, $response['headers']['location'] ?? null);
    }

    public function testListingFormsAndEditLinkCarryTheFilter(): void
    {
        $id = $this->createTask('Tarea');

        $body = $this->request('GET', '/?estado=pendientes')['body'];

        // Alta, toggle y borrar, más el de salir (que no lleva filtro).
        $this->assertSame(4, substr_count($body, '<form method="post"'));
        $this->assertSame(3, substr_count($body, 'name="estado" value="pendientes"'));
        $this->assertStringContainsString('href="/tasks/' . $id . '/edit?estado=pendientes"', $body);
    }

    public function testEditFormCarriesTheFilter(): void
    {
        $id = $this->createTask('Tarea');

        $body = $this->request('GET', "/tasks/$id/edit?estado=completadas")['body'];

        $this->assertStringContainsString('name="estado" value="completadas"', $body);
        $this->assertStringContainsString('<a href="/?estado=completadas">Cancelar</a>', $body);
    }

    public function testInvalidFilterIsNormalizedInForms(): void
    {
        $id = $this->createTask('Tarea');

        $this->assertStringContainsString('name="estado" value="todas"', $this->request('GET', '/?estado=<b>')['body']);
        $this->assertStringContainsString('name="estado" value="todas"', $this->request('GET', "/tasks/$id/edit?estado=xyz")['body']);
    }

    public function testValidationErrorKeepsTheFilter(): void
    {
        $id = $this->createTask('Tarea');

        $create = $this->submit('/tasks', ['title' => '', 'estado' => 'completadas']);
        $this->assertSame(422, $create['status']);
        $this->assertStringContainsString('name="estado" value="completadas"', $create['body']);
        $this->assertStringContainsString('class="activo" aria-current="page">Completadas</a>', $create['body']);

        $update = $this->submit("/tasks/$id", ['title' => '', 'estado' => 'pendientes']);
        $this->assertSame(422, $update['status']);
        $this->assertStringContainsString('name="estado" value="pendientes"', $update['body']);
        $this->assertStringContainsString('<a href="/?estado=pendientes">Cancelar</a>', $update['body']);
    }

    public function testUserInputIsEscaped(): void
    {
        $xss = '<script>alert(1)</script>';
        $this->submit('/tasks', ['title' => $xss, 'description' => $xss]);
        $id = $this->lastTaskId();

        $listing = $this->request('GET', '/')['body'];
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $listing);
        $this->assertStringNotContainsString($xss, $listing);

        $edit = $this->request('GET', "/tasks/$id/edit")['body'];
        $this->assertStringContainsString('value="&lt;script&gt;alert(1)&lt;/script&gt;"', $edit);
        $this->assertStringNotContainsString($xss, $edit);

        $invalid = $this->submit('/tasks', ['title' => '', 'description' => $xss])['body'];
        $this->assertStringContainsString('&lt;script&gt;', $invalid);
        $this->assertStringNotContainsString($xss, $invalid);
    }

    public function testAttributeValuesAreEscaped(): void
    {
        $this->submit('/tasks', ['title' => '" onmouseover="alert(1)']);

        $this->assertStringContainsString('&quot; onmouseover=&quot;alert(1)', $this->request('GET', '/')['body']);
    }

    /**
     * Servidor con APP_NOW=2026-03-10T12:00:00Z y APP_TIMEZONE=UTC: "hoy" es 2026-03-10.
     */
    public function testOnlyPendingTasksDueBeforeTodayAreMarkedOverdue(): void
    {
        $this->submit('/tasks', ['title' => 'Pendiente de ayer', 'due_date' => '2026-03-09']);
        $this->submit('/tasks', ['title' => 'Pendiente de hoy', 'due_date' => '2026-03-10']);
        $this->submit('/tasks', ['title' => 'Completada de ayer', 'due_date' => '2026-03-09']);
        $this->submit('/tasks/' . $this->lastTaskId() . '/toggle', []);
        $this->submit('/tasks', ['title' => 'Pendiente sin fecha']);

        $tasks = $this->listedTasks($this->request('GET', '/')['body']);

        $this->assertCount(4, $tasks);
        $this->assertTrue($tasks['Pendiente de ayer']['overdue']);
        $this->assertFalse($tasks['Pendiente de hoy']['overdue']);
        $this->assertFalse($tasks['Completada de ayer']['overdue']);
        $this->assertFalse($tasks['Pendiente sin fecha']['overdue']);
    }

    public function testListingHasPriorityClassesAndStylesheet(): void
    {
        $this->submit('/tasks', ['title' => 'Urgente', 'priority' => 'alta']);
        $this->submit('/tasks', ['title' => 'Cuando pueda', 'priority' => 'baja']);

        $body = $this->request('GET', '/')['body'];
        $tasks = $this->listedTasks($body);
        $this->assertContains('prioridad-alta', $tasks['Urgente']['classes']);
        $this->assertContains('prioridad-baja', $tasks['Cuando pueda']['classes']);
        $this->assertMatchesRegularExpression('#<link rel="stylesheet" href="/style\.css\?v=\d+">#', $body);

        $css = $this->request('GET', '/style.css');
        $this->assertSame(200, $css['status']);
        $this->assertStringStartsWith('text/css', $css['headers']['content-type'] ?? '');
        $this->assertStringContainsString('.vencida', $css['body']);
    }

    public function testCreateFormIsInAClosedDialogOpenedByThePlusButton(): void
    {
        $body = $this->request('GET', '/')['body'];

        $this->assertStringContainsString('<button type="button" class="boton-nueva" commandfor="nueva-tarea" command="show-modal"', $body);
        $this->assertMatchesRegularExpression('#<dialog id="nueva-tarea"[^>]*>#', $body);
        preg_match('#<dialog id="nueva-tarea"[^>]*>#', $body, $tag);
        $this->assertStringNotContainsString(' open', $tag[0], 'De normal el diálogo está cerrado');
        $this->assertMatchesRegularExpression('#<dialog id="nueva-tarea".*<form method="post" action="/tasks">.*</dialog>#s', $body);
        $this->assertStringContainsString('command="close"', $body);
        $this->assertMatchesRegularExpression("#<script src=\"/app\\.js\\?v=\\d+\" defer></script>#", $body);

        $js = $this->request('GET', '/app.js');
        $this->assertSame(200, $js['status']);
        $this->assertMatchesRegularExpression('#^(text|application)/javascript#', $js['headers']['content-type'] ?? '');
    }

    public function testValidationErrorOpensTheDialog(): void
    {
        $body = $this->submit('/tasks', ['title' => ''])['body'];

        $this->assertMatchesRegularExpression('#<dialog id="nueva-tarea"[^>]* open data-con-errores>#', $body);
        $this->assertStringContainsString('El título es obligatorio', $body);
    }

    public function testPlusButtonOnlyAppearsOnTheListing(): void
    {
        $id = $this->createTask('Tarea');

        $this->assertStringNotContainsString('boton-nueva', $this->request('GET', "/tasks/$id/edit")['body']);
        $this->assertStringNotContainsString('boton-nueva', $this->request('GET', '/no-existe')['body']);
    }

    public function testLogoIsServedAndUsedAsFavicon(): void
    {
        $this->assertStringContainsString('<link rel="icon" href="/logo.svg" type="image/svg+xml">', $this->request('GET', '/')['body']);
        $this->assertStringContainsString('url("/logo.svg")', $this->request('GET', '/style.css')['body']);

        $logo = $this->request('GET', '/logo.svg');
        $this->assertSame(200, $logo['status']);
        $this->assertStringStartsWith('image/svg+xml', $logo['headers']['content-type'] ?? '');
        $this->assertNotFalse(simplexml_load_string($logo['body']), 'El logo debe ser un SVG bien formado');
    }

    public function testCursorEffectsScriptIsOptionalAndDeferred(): void
    {
        $this->assertMatchesRegularExpression("#<script src=\"/efectos\\.js\\?v=\\d+\" defer></script>#", $this->request("GET", "/")["body"]);

        $js = $this->request('GET', '/efectos.js');
        $this->assertSame(200, $js['status']);
        $this->assertMatchesRegularExpression('#^(text|application)/javascript#', $js['headers']['content-type'] ?? '');
        $this->assertStringContainsString('prefers-reduced-motion', $js['body']);
    }

    /**
     * Tareas del listado por título: clases CSS del <li> y si se muestra como vencida
     * (clase `vencida` y etiqueta "Vencida", que deben ir siempre juntas).
     *
     * @return array<string, array{classes: list<string>, overdue: bool}>
     */
    private function listedTasks(string $html): array
    {
        $document = HTMLDocument::createFromString($html, LIBXML_NOERROR);
        $tasks = [];
        foreach ($document->querySelectorAll('li.tarea') as $li) {
            $classes = preg_split('/\s+/', trim($li->getAttribute('class') ?? ''));
            $hasClass = in_array('vencida', $classes, true);
            $hasLabel = $li->querySelector('.etiqueta-vencida')?->textContent === 'Vencida';
            $title = $li->querySelector('.titulo')->textContent;
            $this->assertSame($hasClass, $hasLabel, "Clase y etiqueta \"Vencida\" no coinciden en '$title'");
            $tasks[$title] = ['classes' => $classes, 'overdue' => $hasClass];
        }

        return $tasks;
    }

    public function testTasksSurviveAServerRestart(): void
    {
        $this->createTask('Sobrevive al reinicio');
        $oldUrl = self::$baseUrl;

        self::stopServer();
        $this->assertNull(self::send('GET', '/', [], 1, required: false), 'El servidor anterior sigue respondiendo en ' . $oldUrl);
        self::startServer();

        $response = $this->request('GET', '/');
        $this->assertSame(200, $response['status']);
        $this->assertStringContainsString('Sobrevive al reinicio', $response['body']);
    }

    /**
     * Crea una tarea desde el formulario y devuelve su id.
     */
    private function createTask(string $title): int
    {
        $this->assertSame(303, $this->submit('/tasks', ['title' => $title])['status']);

        return $this->lastTaskId();
    }

    private function lastTaskId(): int
    {
        return (int) $this->pdo()->query('SELECT MAX(id) FROM tasks')->fetchColumn();
    }

    private function completed(int $id): int
    {
        return (int) $this->pdo()->query("SELECT completed FROM tasks WHERE id = $id")->fetchColumn();
    }

    /**
     * POST con el token CSRF de la sesión, como haría el formulario.
     *
     * @param array<string, string> $form
     * @return array{status: int, headers: array<string, string>, body: string}
     */
    private function submit(string $path, array $form): array
    {
        return $this->request('POST', $path, [Csrf::FIELD => $this->csrfToken()] + $form);
    }

    /**
     * Abre el listado (iniciando sesión si hace falta) y lee el token del formulario.
     */
    private function csrfToken(): string
    {
        $body = $this->request('GET', '/')['body'];
        $field = preg_quote(Csrf::FIELD, '/');
        $this->assertSame(1, preg_match("/name=\"$field\" value=\"([0-9a-f]+)\"/", $body, $m), 'Falta el token CSRF');

        return $m[1];
    }

    private function countTasks(): int
    {
        return (int) $this->pdo()->query('SELECT COUNT(*) FROM tasks')->fetchColumn();
    }

    /** Conexión directa a la BD del servidor, para comprobar el estado de las filas. */
    private function pdo(): PDO
    {
        return Database::connect(self::$dbPath);
    }

    /**
     * @param array<string, string> $form
     * @return array{status: int, headers: array<string, string>, body: string}
     */
    private function request(string $method, string $path, array $form = []): array
    {
        return self::send($method, $path, $form, 5);
    }

    /**
     * @param array<string, string> $form
     * @return array{status: int, headers: array<string, string>, body: string}|null null si no hay conexión.
     */
    private static function send(string $method, string $path, array $form, int $timeout, bool $required = true): ?array
    {
        $headers = [];
        $setCookies = [];
        $ch = curl_init(self::$baseUrl . $path);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_COOKIE => self::$cookie ?? '',
            CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use (&$headers, &$setCookies): int {
                if (str_contains($line, ':')) {
                    [$name, $value] = explode(':', $line, 2);
                    $headers[strtolower(trim($name))] = trim($value);
                    if (strtolower(trim($name)) === 'set-cookie') {
                        $setCookies[] = trim($value);
                    }
                }
                return strlen($line);
            },
        ]);
        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($form));
        }

        $body = curl_exec($ch);
        if ($body === false) {
            $error = curl_error($ch);
            if ($required) {
                throw new RuntimeException("Fallo en $method $path: $error");
            }
            return null;
        }

        if ($setCookies !== []) {
            // Jar mínimo: conserva las cookies anteriores y sustituye las que llegan de nuevo.
            $jar = [];
            foreach (array_filter(explode('; ', self::$cookie ?? '')) as $pair) {
                [$k, $v] = explode('=', $pair, 2);
                $jar[$k] = $v;
            }
            foreach ($setCookies as $line) {
                [$k, $v] = explode('=', explode(';', $line, 2)[0], 2);
                $jar[$k] = $v;
            }
            self::$cookie = implode('; ', array_map(static fn (string $k, string $v): string => "$k=$v", array_keys($jar), $jar));
        }

        return [
            'status' => curl_getinfo($ch, CURLINFO_RESPONSE_CODE),
            'headers' => $headers,
            'body' => $body,
            'cookies' => $setCookies,
        ];
    }

    /**
     * Arranca `php -S` en un puerto libre y espera a que responda. Si el puerto se ocupa
     * entre elegirlo y arrancar (carrera), reintenta con otro.
     */
    private static function startServer(): void
    {
        $log = self::$tmpDir . '/server.log';
        $env = array_merge(getenv(), [
            'TASKS_DB_PATH' => self::$dbPath,
            'APP_NOW' => '2026-03-10T12:00:00Z',
            'APP_TIMEZONE' => 'UTC',
            'APP_PASSWORD_HASH' => password_hash(self::PASSWORD, PASSWORD_BCRYPT, ['cost' => 4]),
        ]);

        for ($attempt = 1; $attempt <= self::START_ATTEMPTS; $attempt++) {
            $port = self::freePort();
            self::$baseUrl = "http://127.0.0.1:$port";

            // Comando como array: sin shell intermedia, así proc_terminate llega al propio php.
            $command = [
                PHP_BINARY, '-d', 'session.save_path=' . self::$tmpDir,
                '-S', "127.0.0.1:$port", '-t', dirname(__DIR__, 2) . '/public',
            ];
            $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']], $pipes, null, $env);
            if ($process === false) {
                throw new RuntimeException('No se pudo lanzar php -S');
            }
            fclose($pipes[0]);
            self::$process = $process;

            if (self::waitUntilReady()) {
                return;
            }
            self::stopServer();
        }

        throw new RuntimeException("El servidor no arrancó. Log:\n" . (string) @file_get_contents($log));
    }

    /**
     * @return bool false si el proceso muere o no responde antes del timeout.
     */
    private static function waitUntilReady(): bool
    {
        $deadline = microtime(true) + self::STARTUP_TIMEOUT;
        while (microtime(true) < $deadline) {
            if (!proc_get_status(self::$process)['running']) {
                return false;
            }
            if (self::send('GET', '/', [], 1, required: false) !== null) {
                return true;
            }
            usleep(self::POLL_INTERVAL_US);
        }

        return false;
    }

    private static function freePort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        if ($socket === false) {
            throw new RuntimeException("No se pudo obtener un puerto libre: $errstr");
        }
        $name = stream_socket_get_name($socket, false);
        fclose($socket);

        return (int) substr($name, strrpos($name, ':') + 1);
    }

    /** Idempotente: se llama desde tearDownAfterClass y desde la función de shutdown. */
    public static function stopServer(): void
    {
        if (self::$process === null) {
            return;
        }

        proc_terminate(self::$process, 15);
        $deadline = microtime(true) + 2.0;
        while (proc_get_status(self::$process)['running'] && microtime(true) < $deadline) {
            usleep(self::POLL_INTERVAL_US);
        }
        if (proc_get_status(self::$process)['running']) {
            proc_terminate(self::$process, 9);
        }
        proc_close(self::$process);
        self::$process = null;
    }
}
