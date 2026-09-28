<?php
/**
 * Fallas Exitosas · Error interno (500).
 * Muestra un identificador para ubicar el detalle en el log del contenedor.
 * Autor : William Valverde V.  |  Fecha : 2026-09-25
 *
 * Bitácora : 2026-09-25 Se retira el CSS por CDN, que la CSP de Apache
 *            (`default-src 'self'`) bloqueaba y dejaba la página sin estilos.
 *            Ahora usa la hoja local y SVG literal, sin depender de view.php:
 *            esta pantalla tiene que poder dibujarse aunque el arranque falle.
 */
declare(strict_types=1);
$Lv_ErrorId = (string) ($GLOBALS['Gv_ErrorId'] ?? 'sin-identificador');
?>
<!DOCTYPE html>
<html lang="es"><head><meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Fallas Exitosas · Error</title>
<link rel="icon" href="/assets/logos/favicon-192.png">
<link href="/assets/app.css" rel="stylesheet"></head>
<body><div class="fx-ingreso"><div class="fx-ingreso-tarjeta">
<img src="/assets/logos/anc-logo-claro.png" alt="Grupo ANC">
<h1>Algo falló de nuestro lado</h1>
<p class="fx-ingreso-lead">La operación no se completó. Volvé a intentarlo; si continúa, pasale este código al administrador para ubicar el detalle.</p>
<div class="fx-alerta fx-alerta-error mb-5">
<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M5 9h14M5 15h14M10 4 8 20M16 4l-2 16"/></svg>
<span>Código de error: <b><?= htmlspecialchars($Lv_ErrorId, ENT_QUOTES, 'UTF-8') ?></b></span></div>
<a class="fx-btn fx-btn-primario fx-btn-bloque" href="/index.php">
<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M19 12H5"/><path d="m11 6-6 6 6 6"/></svg>
Volver al inicio</a>
</div></div></body></html>
