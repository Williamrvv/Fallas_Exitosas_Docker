<?php
/**
 * Fallas Exitosas · Arranque de la aplicación.
 *
 * Propósito : Cargar la configuración y los módulos comunes, y fijar el manejo
 *             de errores para que el usuario nunca vea detalles internos.
 * Autor     : William Valverde V.
 * Fecha     : 2026-09-24
 * Bitácora  : 2026-09-24 Versión inicial.
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/audit.php';
require_once __DIR__ . '/session.php';
require_once __DIR__ . '/oidc.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/view.php';

date_default_timezone_set('America/Costa_Rica');

// El detalle técnico va al log del contenedor, no a la pantalla.
ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

/**
 * Cualquier error no controlado se convierte en una pantalla segura con un
 * identificador que permite ubicar el detalle en el log.
 */
set_exception_handler(static function (Throwable $Po_Error): void {
    $Lv_ErrorId = bin2hex(random_bytes(6));

    error_log(sprintf(
        'Fallas Exitosas · error %s: %s en %s:%d',
        $Lv_ErrorId,
        $Po_Error->getMessage(),
        $Po_Error->getFile(),
        $Po_Error->getLine()
    ));

    if (!headers_sent()) {
        http_response_code(500);
    }

    $GLOBALS['Gv_ErrorId'] = $Lv_ErrorId;
    require __DIR__ . '/../public/error-500.php';
});
