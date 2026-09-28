<?php
/**
 * Fallas Exitosas · Cierre de sesión.
 *
 * Propósito : Cerrar la sesión local y también la de Entra ID, para que salir
 *             signifique salir de verdad en equipos compartidos.
 * Autor     : William Valverde V.
 * Fecha     : 2026-09-24
 * Bitácora  : 2026-09-24 Versión inicial.
 *             2026-09-25 Con ingreso por contraseña no se redirige a Microsoft.
 */

declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';

sesion_iniciar();

// Solo se cierra también la sesión de Microsoft si se ingresó con Microsoft.
$Gb_Entra = config_entra_lista() && ($_SESSION['metodo_ingreso'] ?? 'entra') !== 'local';

sesion_cerrar('salida');

header('Location: ' . ($Gb_Entra ? oidc_url_salida() : '/index.php'));
exit;
