# SPEC — Gestor de tareas

## 1. Objetivo
Aplicación web monousuario, local, para gestionar tareas: crear, listar, editar, completar/desmarcar, borrar y filtrar, con persistencia en SQLite. PHP 8.4 puro, sin framework, sin dependencias de producción. La interfaz se apoya en HTML nativo (`<dialog>`, Invoker Commands) con JavaScript mínimo de apoyo y un script opcional de efectos visuales (§6).

## 2. Requisitos previos
Versión única de PHP en todo el proyecto: **PHP 8.4** (local, `composer.json` con `"php": "^8.4"` y CI).

Extensiones PHP (paquetes Debian `php8.4-*`):

| extensión | la necesita | paquete Debian |
|---|---|---|
| `pdo`, `pdo_sqlite` | la app | `php8.4-sqlite3` |
| `session`, `mbstring` | la app (CSRF; longitud de título en caracteres) | `php8.4-cli`, `php8.4-mbstring` |
| `dom`, `json`, `libxml`, `mbstring`, `xml`, `xmlwriter` | PHPUnit 11 | `php8.4-xml`, `php8.4-mbstring` (`json` va en el núcleo) |
| `curl` | tests funcionales (cliente HTTP desde PHP) | `php8.4-curl` |
| `openssl`, `phar`, `zip` | Composer (descarga e instalación de paquetes) | `php8.4-cli`, `php8.4-zip` |

`curl` es la **extensión PHP `ext-curl`**, usada por `HttpTest.php`; el comando `curl` no se necesita. Los tests funcionales también requieren que `proc_open` no esté en `disable_functions` (para lanzar `php -S`).

Herramientas: Composer (solo desarrollo) y `unzip`; `git`; y para el paso 13, una cuenta de GitHub y `gh` (GitHub CLI) autenticado, o crear el repo desde la web.

```
sudo apt install php8.4-cli php8.4-sqlite3 php8.4-mbstring php8.4-xml php8.4-curl php8.4-zip composer unzip
```

`composer.json` declara estas extensiones (`ext-pdo_sqlite`, `ext-mbstring`, `ext-session` en `require`; `ext-curl`, `ext-dom`, `ext-xmlwriter` en `require-dev`), así que `composer install` falla con un mensaje claro si falta alguna.

## 3. Fuera de alcance
Usuarios, login, permisos; API JSON; JavaScript para la funcionalidad / SPA; etiquetas, categorías, subtareas, recordatorios, adjuntos; búsqueda de texto; paginación; ordenación configurable por el usuario; i18n (UI solo en español); despliegue en producción (servidor público, HTTPS, escalado). El despliegue local con Docker sí está en alcance (§11).

## 4. Modelo de datos
Tabla `tasks`:

| columna | tipo | reglas |
|---|---|---|
| id | INTEGER PK AUTOINCREMENT | |
| title | TEXT NOT NULL | trim, 1–200 caracteres |
| description | TEXT NOT NULL DEFAULT '' | ≤ 2000 caracteres |
| due_date | TEXT NULL | `YYYY-MM-DD` válida o NULL |
| priority | INTEGER NOT NULL DEFAULT 2 | CHECK IN (1,2,3) = baja/media/alta |
| completed | INTEGER NOT NULL DEFAULT 0 | CHECK IN (0,1) |
| created_at / updated_at | TEXT NOT NULL | UTC con microsegundos: `Y-m-d\TH:i:s.u\Z` (p. ej. `2026-03-10T10:00:00.000000Z`) |

El esquema se crea automáticamente (`CREATE TABLE IF NOT EXISTS`) al abrir la conexión. Ruta de la BD: `data/tasks.sqlite`, sobreescribible con la variable de entorno `TASKS_DB_PATH` (para tests).

### Reloj inyectable
Ningún código lee la hora directamente (`time()`, `new DateTime()`…): todo pasa por la interfaz `Clock::now(): DateTimeImmutable`.
- `SystemClock`: hora real. Es la implementación por defecto.
- `FixedClock`: hora fija, que se puede avanzar con `advance(string $intervalo)`. Se usa en los tests unitarios y de integración.
- Si la variable de entorno `APP_NOW` está definida (ISO 8601, p. ej. `2026-03-10T12:00:00Z`), `public/index.php` usa un `FixedClock` con esa hora. Solo la usan los tests funcionales.

"Hoy" es la fecha de `Clock::now()` en la zona horaria `APP_TIMEZONE` (por defecto, la de `date.timezone` en php.ini).

## 5. Requisitos funcionales
- **RF1 Listar**: `GET /` muestra las tareas con título, descripción (si hay), fecha límite, prioridad y estado. Orden: `completed ASC, priority DESC, due_date IS NULL, due_date ASC, id ASC`. Mensaje "No hay tareas" si la lista está vacía.
- **RF2 Filtrar**: `GET /?estado=todas|pendientes|completadas` (por defecto `todas`; valor desconocido ⇒ `todas`). Enlaces de filtro visibles, con el activo marcado.
- **RF3 Crear**: formulario en `/` → `POST /tasks`, dentro de un diálogo modal (`<dialog id="nueva-tarea">`) cerrado por defecto: se abre con el botón «+» de la cabecera (arriba a la derecha) y se cierra con ×, Esc o clic fuera. Prioridad por defecto media. Éxito ⇒ redirect 303 según RF12. En un 422 el diálogo se envía ya abierto con los errores.
- **RF4 Editar**: `GET /tasks/{id}/edit` muestra formulario precargado → `POST /tasks/{id}` actualiza todos los campos editables y pone `updated_at = Clock::now()`.
- **RF5 Completar/desmarcar**: `POST /tasks/{id}/toggle` invierte `completed`.
- **RF6 Borrar**: `POST /tasks/{id}/delete` elimina la tarea directamente (sin diálogo de confirmación: la funcionalidad no depende de JS).
- **RF7 Validación**: entradas inválidas ⇒ se re-renderiza el formulario con estado 422, mensajes de error por campo y los valores introducidos conservados; nada se guarda.
- **RF8 Persistencia**: los datos sobreviven a reiniciar el servidor.
- **RF9 Seguridad básica**: toda salida escapada con `htmlspecialchars`; todas las consultas con sentencias preparadas; token CSRF en sesión verificado en cada POST (fallo ⇒ 403); las acciones que modifican solo aceptan POST (otro método ⇒ 405).
- **RF10 Errores**: id inexistente ⇒ 404; ruta desconocida ⇒ 404.
- **RF11 Vencidas**: una tarea **pendiente** con `due_date` anterior a hoy (según `Clock`) se muestra con la etiqueta "Vencida" y la clase CSS `vencida`. Las que vencen hoy, las completadas y las que no tienen fecha no se marcan.
- **RF12 Conservar el filtro tras un POST**:
  - Todo formulario POST (crear, toggle, borrar, editar) incluye un campo oculto `estado` con el filtro activo.
  - El enlace "Editar" lleva el filtro en la URL (`/tasks/{id}/edit?estado=…`), y el formulario de edición lo reenvía en su campo oculto. El enlace "Cancelar" vuelve a `/?estado=…`.
  - Tras un POST con éxito, el servidor responde 303 con `Location: /?estado=<valor>` si `estado` es `pendientes` o `completadas`, y `Location: /` si es `todas`, falta o no es válido.
  - No se usa la cabecera `Referer` ni la sesión para recordar el filtro.
  - En una respuesta 422, el formulario re-renderizado mantiene el mismo `estado`.

## 6. Requisitos visuales
- **Recursos locales**: estilos en `public/style.css`, logo en `public/logo.svg`. Sin frameworks ni recursos externos (CDN, fuentes web): la app debe verse igual sin conexión.
- **Disposición**: cabecera con el logo y el título a la izquierda y el botón «+» a la derecha; las tareas, en una cuadrícula de tarjetas que se adapta al ancho (una columna en móvil). De normal solo se ven las tareas: el formulario de alta está en el diálogo de RF3.
- **JavaScript** (sin dependencias, cargado con `defer`):
  - `public/app.js`: apoyo al diálogo. Si el navegador no soporta Invoker Commands (`command`/`commandfor`) o `closedby`, abre/cierra el diálogo y lo cierra al pulsar fuera; en un 422 lo reabre como modal.
  - `public/efectos.js`: efectos visuales opcionales que siguen al ratón (aura, foco de luz, inclinación de tarjetas, botones magnéticos). No se activa con `prefers-reduced-motion: reduce` ni sin un ratón. Lo que dibuje sigue cumpliendo los contrastes de abajo.
- **HTML y tests**: el HTML puede cambiar; si cambia algo que comprueban los tests, se actualizan los tests y `composer test` sigue en verde.
- **Usable en móvil**: legible y sin scroll horizontal desde 320 px de ancho; botones, enlaces de filtro y campos con zona táctil de al menos 44 px de alto; los formularios ocupan el ancho disponible en pantallas estrechas.
- **Contraste accesible** (WCAG 2.1 AA), tanto en modo claro como en oscuro (`prefers-color-scheme`):
  - Texto ≥ 4.5:1 sobre su fondo (≥ 3:1 si es texto grande: ≥ 24 px, o ≥ 18.66 px en negrita).
  - Bordes de campos, indicador de foco y otros elementos de interfaz ≥ 3:1 sobre el fondo adyacente.
  - Foco de teclado siempre visible (`:focus-visible`).
  - La información no depende solo del color: prioridad, estado y "Vencida" siguen teniendo su texto.
- Si se usan transiciones o animaciones, se desactivan con `prefers-reduced-motion: reduce`.

## 7. Criterios de aceptación (comprobables con tests)
1. BD vacía: `GET /` → 200 y contiene "No hay tareas".
2. `POST /tasks` con `title="Comprar pan"`, sin `estado` y con CSRF válido → 303 a `/`; el siguiente `GET /` contiene "Comprar pan" con prioridad "media".
3. `POST /tasks` con título vacío o solo espacios → 422, mensaje "El título es obligatorio", fila no creada.
4. Título de 201 caracteres → 422; de 200 → se crea.
5. `due_date="2026-02-30"` o `"mañana"` → 422; `"2026-12-31"` → se guarda; vacío → NULL.
6. `priority="urgente"` → 422.
7. Toggle sobre una pendiente → `completed=1`; segundo toggle → `completed=0`.
8. `POST /tasks/{id}/delete` → la tarea ya no aparece y la fila no existe en la BD.
9. Edición con reloj inyectado, en dos niveles:
   - Integración: con un `FixedClock` en T0 = `2026-03-10T10:00:00.000000Z` se crea la tarea; se avanza el reloj a T1 = T0 + 1 µs y se editan los cuatro campos. En la BD queda `created_at = "2026-03-10T10:00:00.000000Z"` y `updated_at = "2026-03-10T10:00:00.000001Z"`.
   - Funcional: tras editar, los nuevos valores se ven en el listado.
   - Ningún test usa `sleep`.
10. Con tareas mixtas: `?estado=pendientes` solo muestra pendientes; `?estado=completadas` solo completadas; `?estado=xyz` muestra todas.
11. Orden: dadas (pendiente, baja), (pendiente, alta, sin fecha), (pendiente, alta, 2026-01-01), (completada, alta) → el listado aparece en orden: alta-2026-01-01, alta-sin fecha, baja, completada.
12. Título `<script>alert(1)</script>` se muestra escapado (`&lt;script&gt;`).
13. POST sin token CSRF o con token incorrecto → 403 y sin cambios.
14. `GET /tasks/1/delete` → 405; `POST /tasks/999/toggle` → 404; `GET /no-existe` → 404.
15. Crear tarea, parar y relanzar el servidor → la tarea sigue ahí.
16. `composer test` pasa en verde.
17. Conservación del filtro:
    - `POST /tasks/{id}/toggle` con `estado=pendientes` → 303 con `Location: /?estado=pendientes`.
    - `POST /tasks` con `estado=completadas` → `Location: /?estado=completadas`.
    - `POST /tasks/{id}` (editar) con `estado=pendientes` → `Location: /?estado=pendientes`.
    - `POST /tasks/{id}/delete` con `estado=xyz` o sin `estado` → `Location: /`.
    - `GET /?estado=pendientes` contiene `name="estado" value="pendientes"` en sus formularios, y el enlace de editar contiene `?estado=pendientes`.
18. Vencidas, con `APP_NOW=2026-03-10T12:00:00Z` y `APP_TIMEZONE=UTC`:
    - Pendiente con fecha 2026-03-09 → "Vencida".
    - No se marcan: pendiente con fecha 2026-03-10, completada con fecha 2026-03-09, ni pendiente sin fecha.
    - Además, un test unitario de la regla de vencimiento con `FixedClock` cubre el cambio de día en la zona configurada.
19. CI: un push a `main` en GitHub ejecuta la Action, que pasa en verde.
20. Formulario de alta en diálogo: `GET /` contiene el botón «+» (`command="show-modal"`, `commandfor="nueva-tarea"`) y el formulario `POST /tasks` dentro de `<dialog id="nueva-tarea">` sin el atributo `open`; una respuesta 422 de `POST /tasks` envía el diálogo con `open`; el botón «+» no aparece en la edición ni en las páginas de error.

## 8. Estructura del proyecto
```
.
├── SPEC.md
├── README.md
├── LICENSE                # GNU GPL v3 (GPL-3.0-or-later)
├── composer.json          # php ^8.4, ext-* requeridas; require-dev: phpunit 11; script "test"; autoload PSR-4 App\ → src/
├── composer.lock          # versionado
├── phpunit.xml
├── .gitignore             # vendor/, data/*.sqlite
├── Dockerfile             # imagen de la app (§11)
├── compose.yaml           # servicio app + volumen de datos (§11)
├── .dockerignore          # excluye vendor/, tests/, .git, data/*.sqlite…
├── docker/
│   └── verificar.sh       # comprueba los criterios 21–24 (puerto 8765 por defecto)
├── .github/
│   └── workflows/ci.yml   # composer test en PHP 8.4
├── public/
│   ├── index.php          # front controller: sesión, reloj, router, dispatch
│   ├── style.css
│   ├── logo.svg           # logo y favicon
│   ├── app.js             # apoyo al diálogo de alta (§6)
│   └── efectos.js         # opcional: efectos visuales de cursor (§6)
├── src/
│   ├── Clock.php          # interfaz
│   ├── SystemClock.php
│   ├── FixedClock.php
│   ├── Database.php       # crea PDO, aplica esquema
│   ├── TaskRepository.php # all(filter), find, create, update, toggle, delete; recibe Clock
│   ├── TaskValidator.php  # valida/normaliza input → [data, errors]
│   ├── Csrf.php           # generar/verificar token
│   ├── Router.php         # mapea método+ruta → handler, 404/405
│   └── TaskController.php # handlers HTTP; renderiza vistas o redirige (RF12); calcula "vencida"
├── templates/
│   ├── layout.php
│   ├── index.php          # filtros + formulario de alta + lista
│   ├── edit.php
│   └── error.php
├── data/                  # tasks.sqlite (ignorada en git)
└── tests/
    ├── Unit/TaskValidatorTest.php
    ├── Unit/OverdueTest.php                 # regla de vencimiento con FixedClock
    ├── Integration/TaskRepositoryTest.php   # SQLite en fichero temporal + FixedClock
    └── Functional/HttpTest.php              # lanza php -S (proc_open) en puerto libre con APP_NOW/TASKS_DB_PATH; peticiones con ext-curl
```
Sin autoloader de Composer en producción: `public/index.php` usa un `spl_autoload_register` mínimo, de modo que la app funciona sin `vendor/`.

## 9. Plan por pasos pequeños
Cada paso termina con tests en verde y (opcional) un commit.
1. **Andamiaje**:
   - Si el directorio no está ya dentro de un repo git (`git rev-parse --is-inside-work-tree` falla), ejecutar `git init`; si ya lo está, no tocarlo.
   - Crear `composer.json` (`php ^8.4`, extensiones de §2, PHPUnit 11), `phpunit.xml`, `.gitignore` y un test trivial que pasa.
   - Verificar que `php -v` es 8.4 y que `php -m` lista todas las extensiones de §2.
2. **Reloj y Database**:
   - `Clock`, `SystemClock`, `FixedClock` con test unitario.
   - `Database::connect(path)` crea el fichero y el esquema, con un test de integración que verifica la tabla.
3. **TaskValidator**: reglas de título, descripción, fecha, prioridad + tests unitarios (criterios 3–6).
4. **TaskRepository – crear/listar**: `create`, `all` con orden especificado, timestamps vía `Clock` + tests (criterio 11).
5. **TaskRepository – resto**: `find`, `update`, `toggle`, `delete`, filtro por estado + tests (7–10 a nivel repositorio, 9 con `FixedClock`).
6. **Router + front controller**: rutas, 404/405, página de listado vacío; lectura de `APP_NOW`/`APP_TIMEZONE`; arrancar con `php -S localhost:8000 -t public` y test funcional (1, 14).
7. **Crear desde la UI**: formulario, CSRF, PRG, errores 422 (2–4, 13).
8. **Toggle y borrar desde la UI** (7, 8).
9. **Editar desde la UI** (9 funcional).
10. **Filtros en la UI y conservación del filtro** según RF12 (10, 17).
11. **Escapado, estilos y vencidas**: verificar XSS (12), CSS mínimo legible, prioridad destacada visualmente, RF11 (18).
12. **Persistencia y README**: test de reinicio (15), README con requisitos, cómo arrancar y cómo testear (16).
13. **GitHub + CI**:
    - Añadir `.github/workflows/ci.yml`:
      - Se dispara en `push` y `pull_request`, en `ubuntu-latest`.
      - Pasos: `actions/checkout@v4`; `shivammathur/setup-php@v2` con `php-version: '8.4'`, `extensions: pdo_sqlite, mbstring, dom, xml, xmlwriter, curl` y `coverage: none`; `composer install --no-interaction --prefer-dist`; `composer test`.
    - Crear el repo con `gh repo create` (visibilidad a elegir en ese momento) y subir `main`.
    - Comprobar que la Action pasa en verde (19).
14. **Mejora visual** según §6 (estilos, logo, cuadrícula, diálogo de alta y efectos de cursor) (20):
    - Elegir una dirección de estilo antes de empezar.
    - Comprobar el contraste de cada par texto/fondo e interfaz/fondo usado, en claro y en oscuro (calculando la ratio WCAG).
    - Revisar a 320 px de ancho y en escritorio: sin scroll horizontal y zonas táctiles de 44 px.
    - Navegar con el teclado: el foco se ve en todos los elementos interactivos.
    - `composer test` sigue en verde (16).
15. **Docker** según §11 (21–24):
    - Tener Docker Engine y el plugin Compose v2 instalados en la máquina.
    - Añadir `Dockerfile`, `compose.yaml` y `.dockerignore`.
    - Añadir `docker/verificar.sh`, que comprueba los criterios 21–24 contra un `docker compose` real.
    - Ejecutarlo en verde; `composer test` sigue en verde (16).
    - Documentar en el README cómo arrancar con Docker y cómo borrar los datos (`docker compose down -v`).

## 10. Cómo arrancar / verificar
```
composer install
php -S localhost:8000 -t public
composer test
```

## 11. Despliegue local con Docker
Alternativa a `php -S` en la máquina: la app corre en un contenedor, sin instalar PHP en el anfitrión.

**Requisitos**
- **Herramientas**: Docker Engine con el plugin Compose v2 (`docker compose`). Solo para esta forma de arrancar; el desarrollo y los tests siguen necesitando PHP 8.4 local (§2).
- **Imagen** (`Dockerfile`):
  - Base oficial `php:8.4-cli-alpine`, que ya incluye `pdo_sqlite`, `mbstring` y `session`; no se instalan extensiones extra.
  - Arranca `php -S 0.0.0.0:8000 -t public`, el mismo servidor que §10. Es un servidor de desarrollo: válido para uso local, no para producción (§3).
  - Solo copia lo que la app necesita en tiempo de ejecución: `public/`, `src/` y `templates/`, más el directorio `data/` vacío. No se ejecuta Composer, porque la app no usa `vendor/`.
  - Corre como un usuario sin privilegios (`app`, UID/GID 10001), declarado con `USER` en la imagen.
  - `.dockerignore` excluye `vendor/`, `tests/`, `.git`, `data/*.sqlite`, `.phpunit.cache` y los ficheros de desarrollo.
- **Compose** (`compose.yaml`), servicio `app`:
  - Publica `${APP_PORT:-8000}:8000`: por defecto responde en `localhost:8000` y en la IP del anfitrión en la red local; `APP_PORT` permite usar otro puerto si el 8000 está ocupado. La app no tiene login, así que cualquiera de esa red puede usarla.
  - Volumen con nombre `datos` montado en `/app/data`, propiedad del usuario `app`. `TASKS_DB_PATH=/app/data/tasks.sqlite`.
  - `APP_TIMEZONE` = `${APP_TIMEZONE:-Europe/Madrid}`.
  - `restart: unless-stopped`.
- **Datos**: `docker compose down` conserva el volumen; `docker compose down -v` lo borra (empezar de cero).

**Criterios de aceptación** (los comprueba `docker/verificar.sh` contra Docker real; no forman parte de `composer test`):

`verificar.sh` usa su propio proyecto Compose (`gestor-verificar`) y `APP_PORT=8765` por defecto, para no chocar con otros servicios ni tocar los datos del uso normal; al terminar elimina contenedores y volumen.

21. `docker compose up -d --build` levanta la app: en menos de 30 s, `GET http://localhost:$APP_PORT/` responde 200 y contiene "Gestor de tareas".
22. Persistencia en volumen:
    - Se crea una tarea por HTTP (con sesión y token CSRF).
    - `docker compose down` (sin `-v`) destruye el contenedor; `docker compose up -d` crea uno nuevo, con otro ID.
    - La tarea sigue apareciendo en `GET /`.
23. Sin root: `docker compose exec app id -u` devuelve un valor distinto de `0`, y `docker image inspect` muestra un `Config.User` no vacío y distinto de `root`/`0`.
24. Imagen limpia: en un contenedor de la imagen no existen `/app/vendor`, `/app/tests` ni `/app/.git`, y `.git`, `vendor` y `tests` no aparecen bajo `/app`.
