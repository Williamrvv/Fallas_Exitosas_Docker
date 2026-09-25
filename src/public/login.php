<?php
/**
 * Fallas Exitosas · Redirección a Microsoft Entra ID.
 *
 * Propósito : Arrancar el flujo de código de autorización con PKCE.
 * Autor     : William Valverde V.
 * Fecha     : 2026-09-24
 * Bitácora  : 2026-09-24 Versión inicial.
 */

declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';

sesion_iniciar();

if (!config_entra_lista()) {
    header('Location: /index.php');
    exit;
}

header('Location: ' . oidc_url_autorizacion());
exit;
