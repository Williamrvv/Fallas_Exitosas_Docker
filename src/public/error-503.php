<?php
/**
 * Fallas Exitosas · Servicio de autorización no disponible (503).
 *
 * Propósito : Informar una indisponibilidad temporal sin revelar red, SQL ni PII.
 * Autor     : William Valverde V.
 * Fecha     : 2026-09-25
 * Bitácora  : 2026-09-25 Se retira el CSS por CDN, que la CSP de Apache
 *             (`default-src 'self'`) bloqueaba y dejaba la página sin estilos.
 *             Ahora usa la hoja local y SVG literal, sin depender de view.php.
 */

declare(strict_types=1);

$Lv_ErrorId = (string) ($GLOBALS['Gv_ErrorId'] ?? 'sin-identificador');
?>
<!DOCTYPE html>
<html lang="es"><head><meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Fallas Exitosas · Servicio temporalmente no disponible</title>
<link rel="icon" href="/assets/logos/favicon-192.png">
<link href="/assets/app.css" rel="stylesheet"></head>
<body><div class="fx-ingreso"><div class="fx-ingreso-tarjeta">
<img src="/assets/logos/anc-logo-claro.png" alt="Grupo ANC">
<h1>Acceso temporalmente no disponible</h1>
<p class="fx-ingreso-lead">Microsoft confirmó la identidad, pero el servicio que autoriza el acceso no está disponible. Intentá nuevamente cuando se restablezca.</p>
<div class="fx-alerta fx-alerta-info mb-5">
<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M5 9h14M5 15h14M10 4 8 20M16 4l-2 16"/></svg>
<span>Código de seguimiento: <b><?= htmlspecialchars($Lv_ErrorId, ENT_QUOTES, 'UTF-8') ?></b></span></div>
<a class="fx-btn fx-btn-primario fx-btn-bloque" href="/index.php">
<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M20 11a8 8 0 1 0-.6 4"/><path d="M20 5v6h-6"/></svg>
Intentar de nuevo</a>
</div></div></body></html>
