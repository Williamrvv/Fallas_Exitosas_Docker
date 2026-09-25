<?php
/**
 * Fallas Exitosas · Cierre de sesión.
 *
 * Propósito : Cerrar la sesión local y también la de Entra ID, para que salir
 *             signifique salir de verdad en equipos compartidos.
 * Autor     : William Valverde V.
 * Fecha     : 2026-09-24
 * Bitácora  : 2026-09-24 Versión inicial.
 */

declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';

sesion_iniciar();

$Gb_Entra = config_entra_lista();

sesion_cerrar('salida');

header('Location: ' . ($Gb_Entra ? oidc_url_salida() : '/index.php'));
exit;
