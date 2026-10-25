# quick-todo-list

Gestor de tareas web, local y monousuario. Crea, edita, completa, borra y filtra tus tareas desde el navegador; los datos se guardan en un fichero SQLite.

Está hecho en **PHP 8.4 puro**: sin framework y sin dependencias de producción. Composer solo se usa para los tests.

## Qué hace

- **Tareas completas:** título, descripción, fecha límite y prioridad (baja, media o alta).
- **Crear, editar, completar o desmarcar y borrar** tareas.
- **Filtrar** por estado: todas, pendientes o completadas. El filtro se conserva después de cada acción.
- **Marca las vencidas:** una tarea pendiente con fecha anterior a hoy muestra la etiqueta «Vencida».
- **Orden automático:** primero las pendientes, luego por prioridad y por fecha límite.
- **Alta en un diálogo:** el botón «+» abre el formulario en un `<dialog>` nativo.
- **Persistente:** las tareas sobreviven a reiniciar el servidor.
- **Interfaz cuidada:** funciona en móvil desde 320 px, con modo claro y oscuro, contraste accesible (WCAG AA) y efectos de cursor opcionales que se desactivan con `prefers-reduced-motion` y en pantallas táctiles.
- **Seguridad básica:** salida escapada, consultas preparadas y token CSRF en cada POST.

## Cómo arrancar

Necesitas PHP 8.4 con las extensiones `pdo_sqlite`, `mbstring` y `session`.

```
php -S localhost:8000 -t public
```

Abre <http://localhost:8000>. La base de datos se crea sola en `data/tasks.sqlite` la primera vez. Para empezar de cero, para el servidor y borra ese fichero.

La aplicación no necesita `vendor/`.

### Variables de entorno

| variable | por defecto | uso |
|---|---|---|
| `TASKS_DB_PATH` | `data/tasks.sqlite` | ruta de la base de datos |
| `APP_TIMEZONE` | `date.timezone` de php.ini | zona horaria con la que se decide si una tarea está vencida |

## Acceso con contraseña

La web muestra una portada pública; al pulsar en cualquier parte se pide la contraseña (máximo 20 caracteres). Defínela con:

```
php bin/set-password.php
```

Se guarda su hash en `data/password.hash` (o en `APP_PASSWORD_FILE`; también vale `APP_PASSWORD_HASH`). Sin contraseña configurada nadie puede entrar. Con Docker: `docker compose exec app php bin/set-password.php`.

Antes de la contraseña se pregunta «¿Quién eres?». Ese nombre (hasta 30 caracteres) se recuerda en la cookie `quien`, de larga duración, y se guarda en cada tarea que crees (columna `created_by`, visible como «Creada por»). Es una etiqueta, no una identidad verificada: cualquiera que conozca la contraseña puede escribir el nombre que quiera.

Límite de intentos por IP: 3 fallos en menos de 1 minuto bloquean el acceso 2 minutos; si tras ese bloqueo vuelven a fallar 3 intentos, la IP queda vetada 1 hora. Detrás de Cloudflare se usa la cabecera `CF-Connecting-IP` (solo se confía en ella si la conexión llega desde loopback).

## Con Docker

Alternativa sin instalar PHP: necesitas Docker con el plugin Compose.

```
docker compose up -d --build
```

Abre <http://localhost:8000>. Si ese puerto está ocupado, usa otro con `APP_PORT=8080 docker compose up -d --build`. La zona horaria por defecto es `Europe/Madrid` (cámbiala con `APP_TIMEZONE`).

Las tareas se guardan en un volumen y sobreviven a `docker compose down`. Para borrarlas y empezar de cero: `docker compose down -v`.

El contenedor usa el servidor de desarrollo de PHP y no tiene login: está pensado para uso local, no para exponerlo en internet.

## Tests

```
composer install
composer test
```

Ejecuta PHPUnit con tests unitarios, de integración (SQLite temporal) y funcionales (levantan `php -S` y hacen peticiones HTTP reales sin tocar tus datos).

## Documentación

Los requisitos detallados, el modelo de datos, los criterios de aceptación y la estructura del proyecto están en [SPEC.md](SPEC.md).

## Licencia

Software libre bajo la licencia **GNU GPL v3 o posterior** (`GPL-3.0-or-later`). El texto completo está en [LICENSE](LICENSE). Puedes usarlo, estudiarlo, modificarlo y redistribuirlo según los términos de esa licencia: <https://www.gnu.org/licenses/gpl-3.0.html>.
