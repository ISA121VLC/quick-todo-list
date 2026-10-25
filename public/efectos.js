/*
 * Efectos visuales que siguen al cursor (SPEC.md §6). Mejora progresiva: la aplicación
 * funciona y se ve igual sin este script, salvo estos efectos.
 *
 * - Aura: un anillo que persigue al cursor con algo de retardo y crece sobre lo interactivo.
 * - Foco de luz: un resplandor suave en el panel, bajo el cursor.
 * - Tarjetas: brillo local e inclinación 3D hacia el cursor.
 * - Botones y filtros "magnéticos": se desplazan un poco hacia el cursor.
 *
 * Solo escribe propiedades CSS (--cursor-*, --foco-*, --mx/--my, --rx/--ry, --mag-*);
 * el aspecto lo decide style.css. No se activa con movimiento reducido ni sin ratón.
 */
(() => {
    'use strict';

    const motionOk = window.matchMedia('(prefers-reduced-motion: no-preference)');
    const mouse = window.matchMedia('(hover: hover) and (pointer: fine)');
    if (!motionOk.matches || !mouse.matches) {
        return;
    }

    const MAGNETIC = 'button, .acciones a, .filtros a';
    const INTERACTIVE = 'a, button, input, select, textarea, label';
    const MAX_TILT_DEG = 5;
    const MAGNET_PULL = 0.3;   // fracción de la distancia al centro que se desplaza el elemento
    const AURA_EASING = 0.2;   // cuánto se acerca el aura al cursor en cada frame (0–1)

    const root = document.documentElement;
    const panel = document.querySelector('main');

    const aura = document.createElement('div');
    aura.className = 'cursor-aura';
    aura.setAttribute('aria-hidden', 'true');
    document.body.append(aura);

    let x = 0;
    let y = 0;
    let auraX = 0;
    let auraY = 0;
    let target = null;
    let card = null;
    let magnet = null;
    let frame = 0;
    let active = false;

    function schedule() {
        if (!frame) {
            frame = requestAnimationFrame(update);
        }
    }

    function onPointerMove(event) {
        if (event.pointerType !== 'mouse') {
            return;
        }
        x = event.clientX;
        y = event.clientY;
        target = event.target instanceof Element ? event.target : null;
        if (!active) {
            // Primera vez (o al volver a la ventana): el aura aparece ya en su sitio.
            active = true;
            auraX = x;
            auraY = y;
            root.classList.add('cursor-activo');
        }
        schedule();
    }

    function onScroll() {
        if (active) {
            // Al hacer scroll el contenido se mueve bajo un cursor quieto.
            target = document.elementFromPoint(x, y);
            schedule();
        }
    }

    function onLeave(event) {
        if (event.relatedTarget === null) {
            active = false;
            target = null;
            root.classList.remove('cursor-activo');
            schedule();
        }
    }

    /** Posición del cursor relativa a un elemento, en px y en fracción (-0.5 … 0.5) desde su centro. */
    function relative(element) {
        const rect = element.getBoundingClientRect();
        return {
            px: x - rect.left,
            py: y - rect.top,
            fx: (x - rect.left) / rect.width - 0.5,
            fy: (y - rect.top) / rect.height - 0.5,
            dx: x - (rect.left + rect.width / 2),
            dy: y - (rect.top + rect.height / 2),
        };
    }

    function updateCard() {
        const next = target?.closest('.tarea') ?? null;
        if (card && card !== next) {
            for (const name of ['--mx', '--my', '--rx', '--ry']) {
                card.style.removeProperty(name);
            }
        }
        card = next;
        if (card) {
            const r = relative(card);
            card.style.setProperty('--mx', `${r.px}px`);
            card.style.setProperty('--my', `${r.py}px`);
            card.style.setProperty('--rx', `${(-r.fy * 2 * MAX_TILT_DEG).toFixed(2)}deg`);
            card.style.setProperty('--ry', `${(r.fx * 2 * MAX_TILT_DEG).toFixed(2)}deg`);
        }
    }

    function updateMagnet() {
        const next = target?.closest(MAGNETIC) ?? null;
        if (magnet && magnet !== next) {
            magnet.style.removeProperty('--mag-x');
            magnet.style.removeProperty('--mag-y');
        }
        magnet = next;
        if (magnet) {
            const r = relative(magnet);
            magnet.style.setProperty('--mag-x', `${(r.dx * MAGNET_PULL).toFixed(1)}px`);
            magnet.style.setProperty('--mag-y', `${(r.dy * MAGNET_PULL).toFixed(1)}px`);
        }
    }

    function update() {
        frame = 0;

        if (panel) {
            const r = relative(panel);
            panel.style.setProperty('--foco-x', `${r.px}px`);
            panel.style.setProperty('--foco-y', `${r.py}px`);
        }
        updateCard();
        updateMagnet();

        // El aura se acerca poco a poco al cursor; sigue animando hasta alcanzarlo.
        auraX += (x - auraX) * AURA_EASING;
        auraY += (y - auraY) * AURA_EASING;
        aura.style.transform = `translate(${auraX.toFixed(1)}px, ${auraY.toFixed(1)}px)`;
        aura.classList.toggle('sobre-interactivo', Boolean(target?.closest(INTERACTIVE)));

        if (active && (Math.abs(x - auraX) > 0.5 || Math.abs(y - auraY) > 0.5)) {
            schedule();
        }
    }

    document.addEventListener('pointermove', onPointerMove, { passive: true });
    document.addEventListener('pointerout', onLeave, { passive: true });
    window.addEventListener('scroll', onScroll, { passive: true });
})();
