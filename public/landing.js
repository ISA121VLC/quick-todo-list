/*
 * Portada: pulsar en cualquier parte (o Enter/Espacio) abre el diálogo de contraseña.
 * Mejora progresiva: sin JS, el enlace "Entrar" lleva a /?entrar, que lo sirve ya abierto.
 */
(() => {
    'use strict';

    const dialog = document.getElementById('entrada');
    const landing = document.getElementById('landing');
    if (!(dialog instanceof HTMLDialogElement) || !landing) {
        return;
    }

    const input = dialog.querySelector('input[type="password"]');
    const counter = document.getElementById('cuenta');
    const submit = dialog.querySelector('button[type="submit"]');
    const error = document.getElementById('error-acceso');
    let closedAt = 0;

    function openDialog() {
        if (!dialog.open) {
            dialog.showModal();
        } else if (!dialog.matches(':modal')) {
            // Servido abierto por el servidor: lo convierte en modal para atrapar el foco.
            dialog.close();
            dialog.showModal();
        }
        if (input && !input.disabled) {
            input.focus();
        }
    }

    dialog.addEventListener('close', () => {
        closedAt = Date.now();
    });

    // El clic que cierra el diálogo al pulsar fuera no debe volver a abrirlo.
    function justClosed() {
        return Date.now() - closedAt < 400;
    }

    landing.addEventListener('click', (event) => {
        if (dialog.open || justClosed()) {
            return;
        }
        event.preventDefault();
        openDialog();
    });

    document.addEventListener('keydown', (event) => {
        if ((event.key === 'Enter' || event.key === ' ') && !dialog.open && !justClosed()
            && !(event.target instanceof HTMLElement && event.target.closest('dialog'))) {
            event.preventDefault();
            openDialog();
        }
    });

    if (input && counter) {
        input.addEventListener('input', () => {
            counter.textContent = String(input.value.length);
        });
    }

    if (dialog.hasAttribute('data-abierto')) {
        openDialog();
    }

    // Bloqueo por intentos: cuenta atrás y reactivación del formulario.
    const wait = error ? Number(error.dataset.espera) : 0;
    if (wait > 0 && input && submit) {
        const base = error.textContent.replace(/(dentro de|durante) .*\.$/, '$1 ');
        const end = Date.now() + wait * 1000;
        const format = (s) => {
            const m = Math.floor(s / 60);
            const r = s % 60;
            const parts = [];
            if (m > 0) {
                parts.push(m === 1 ? '1 minuto' : `${m} minutos`);
            }
            if (r > 0) {
                parts.push(r === 1 ? '1 segundo' : `${r} segundos`);
            }
            return parts.join(' y ');
        };
        const tick = () => {
            const left = Math.ceil((end - Date.now()) / 1000);
            if (left <= 0) {
                clearInterval(timer);
                error.textContent = 'Ya puedes volver a intentarlo.';
                input.disabled = false;
                submit.disabled = false;
                input.focus();
                return;
            }
            error.textContent = `${base}${format(left)}.`;
        };
        const timer = setInterval(tick, 1000);
    }
})();
