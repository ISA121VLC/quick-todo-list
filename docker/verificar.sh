#!/usr/bin/env bash
# Comprueba los criterios 21–24 de SPEC.md contra un docker compose real.
# Usa un proyecto y un puerto propios para no tocar los datos del uso normal.
set -euo pipefail

cd "$(dirname "$0")/.."

export COMPOSE_PROJECT_NAME=gestor-verificar
export APP_PORT=${APP_PORT:-8765}
URL=http://localhost:$APP_PORT
JAR=$(mktemp)
fallos=0

ok()   { echo "OK   $1"; }
fallo() { echo "FALLO $1"; fallos=$((fallos + 1)); }
limpiar() { docker compose down -v >/dev/null 2>&1 || true; rm -f "$JAR"; }
trap limpiar EXIT

esperar() {
    for _ in $(seq 1 30); do
        curl -fs -o /dev/null "$URL/" && return 0
        sleep 1
    done
    return 1
}

token() { curl -fs -c "$JAR" -b "$JAR" "$URL/" | grep -o 'name="csrf_token" value="[^"]*"' | head -1 | sed 's/.*value="//;s/"$//'; }

docker compose down -v >/dev/null 2>&1 || true
docker compose up -d --build

# 21
if esperar && curl -fs "$URL/" | grep -q "Gestor de tareas"; then
    ok "21 la app responde 200 y contiene «Gestor de tareas»"
else
    fallo "21 la app no responde en 30 s"
fi

# 22
id_antes=$(docker compose ps -q app)
t=$(token)
curl -fs -o /dev/null -c "$JAR" -b "$JAR" --data-urlencode "csrf_token=$t" --data-urlencode "title=Tarea de verificación" "$URL/tasks"
docker compose down >/dev/null
docker compose up -d >/dev/null
id_despues=$(docker compose ps -q app)
esperar || true
if [ "$id_antes" != "$id_despues" ] && curl -fs "$URL/" | grep -q "Tarea de verificación"; then
    ok "22 la tarea persiste tras recrear el contenedor"
else
    fallo "22 la tarea no persiste o el contenedor no se recreó"
fi

# 23
uid=$(docker compose exec -T app id -u | tr -d '\r')
imagen_user=$(docker image inspect "$(docker compose images -q app | head -1)" --format '{{.Config.User}}')
if [ "$uid" != "0" ] && [ -n "$imagen_user" ] && [ "$imagen_user" != "root" ] && [ "$imagen_user" != "0" ]; then
    ok "23 corre sin root (uid $uid, Config.User=$imagen_user)"
else
    fallo "23 corre como root (uid $uid, Config.User='$imagen_user')"
fi

# 24
sobran=$(docker compose exec -T app sh -c 'ls -d /app/vendor /app/tests /app/.git 2>/dev/null' || true)
if [ -z "$sobran" ]; then
    ok "24 la imagen no contiene vendor, tests ni .git"
else
    fallo "24 la imagen contiene: $sobran"
fi

[ "$fallos" -eq 0 ] && echo "Todos los criterios Docker OK" || { echo "$fallos criterio(s) fallido(s)"; exit 1; }
