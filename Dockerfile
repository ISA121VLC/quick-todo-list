FROM php:8.4-cli-alpine

RUN addgroup -g 10001 app && adduser -D -u 10001 -G app app \
    && mkdir -p /app/data && chown app:app /app/data

WORKDIR /app

COPY --chown=app:app public/ public/
COPY --chown=app:app src/ src/
COPY --chown=app:app templates/ templates/

USER app

EXPOSE 8000

# Servidor de desarrollo de PHP: válido para uso local, no para producción.
CMD ["php", "-S", "0.0.0.0:8000", "-t", "public"]
