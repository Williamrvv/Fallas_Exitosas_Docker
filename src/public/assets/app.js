/**
 * Fallas Exitosas · Comportamiento común (mejora progresiva).
 *
 * Propósito : Aplicar el tema claro u oscuro, convertir en modal real el
 *             diálogo que el servidor ya entrega abierto, y pedir confirmación
 *             en los formularios marcados con data-confirmar. Sin JavaScript
 *             todo sigue funcionando: el tema sigue al sistema operativo y el
 *             diálogo se cierra con "Cancelar".
 * Autor     : William Valverde V.
 * Fecha     : 2026-09-25
 * Bitácora  : 2026-09-25 Versión inicial.
 *             2026-09-29 Tema claro/oscuro guardado en este equipo (localStorage).
 *                        Se carga en <head> sin defer para no parpadear.
 *
 * Nota CSP  : Apache solo permite scripts de este mismo origen. Por eso no se
 *             usan onsubmit ni <script> en línea dentro de las páginas.
 */
'use strict';

/* --------------------------------------------------------------------------
   Tema. La primera vez se toma el del sistema operativo y se guarda; desde
   ahí manda lo que la persona elija con el botón, solo en este equipo.
   -------------------------------------------------------------------------- */
const TEMA_CLAVE = 'fx-tema';

function tema_leer() {
    try {
        const Lv_Guardado = localStorage.getItem(TEMA_CLAVE);
        if (Lv_Guardado === 'claro' || Lv_Guardado === 'oscuro') {
            return Lv_Guardado;
        }
    } catch (Lo_Error) {
        // Almacenamiento bloqueado (navegación privada): se usa el del sistema.
    }

    return window.matchMedia('(prefers-color-scheme: dark)').matches ? 'oscuro' : 'claro';
}

function tema_aplicar(Pv_Tema_i) {
    document.documentElement.dataset.tema = Pv_Tema_i;
    try {
        localStorage.setItem(TEMA_CLAVE, Pv_Tema_i);
    } catch (Lo_Error) {
        // Sin almacenamiento el cambio dura hasta recargar la página.
    }
}

tema_aplicar(tema_leer());

document.addEventListener('DOMContentLoaded', () => {
    // Botón de tema: alterna entre claro y oscuro.
    document.querySelectorAll('[data-cambiar-tema]').forEach((Lo_Boton) => {
        const Lf_Marcar = () => Lo_Boton.setAttribute(
            'aria-pressed', String(document.documentElement.dataset.tema === 'oscuro')
        );

        Lf_Marcar();
        Lo_Boton.addEventListener('click', () => {
            tema_aplicar(document.documentElement.dataset.tema === 'oscuro' ? 'claro' : 'oscuro');
            Lf_Marcar();
        });
    });
    // Modal real: fondo inerte, foco atrapado dentro y cierre con Esc.
    document.querySelectorAll('dialog[data-modal][open]').forEach((Lo_Dialogo) => {
        Lo_Dialogo.addEventListener('close', () => {
            // close() de abajo también dispara este evento; se ignora si sigue abierto.
            if (Lo_Dialogo.open) {
                return;
            }
            window.location.assign(Lo_Dialogo.dataset.cerrar || window.location.pathname);
        });

        Lo_Dialogo.close();
        Lo_Dialogo.showModal();
    });

    // Confirmación previa para acciones sensibles.
    document.querySelectorAll('form[data-confirmar]').forEach((Lo_Formulario) => {
        Lo_Formulario.addEventListener('submit', (Lo_Evento) => {
            if (!window.confirm(Lo_Formulario.dataset.confirmar)) {
                Lo_Evento.preventDefault();
            }
        });
    });
});