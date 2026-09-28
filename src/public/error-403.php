<?php
/**
 * Fallas Exitosas · Acceso no autorizado (403).
 * Autor : William Valverde V.  |  Fecha : 2026-09-25
 *
 * Bitácora : 2026-09-25 Se retira el CSS por CDN, que la CSP de Apache
 *            (`default-src 'self'`) bloqueaba y dejaba la página sin estilos.
 *            Ahora usa la hoja local y SVG literal, sin depender de view.php.
 */
declare(strict_types=1);
?>
<!DOCTYPE html>
<html lang="es"><head><meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Fallas Exitosas · Sin acceso</title>
<link rel="icon" href="/assets/logos/favicon-192.png">
<link href="/assets/app.css" rel="stylesheet"></head>
<body><div class="fx-ingreso"><div class="fx-ingreso-tarjeta">
<img src="/assets/logos/anc-logo-claro.png" alt="Grupo ANC">
<h1>No tenés acceso a esta sección</h1>
<p class="fx-ingreso-lead">Tu rol no incluye este módulo. Si necesitás entrar, pedile al administrador que ajuste tu rol o tu ámbito.</p>
<a class="fx-btn fx-btn-primario fx-btn-bloque" href="/dashboard.php">
<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M19 12H5"/><path d="m11 6-6 6 6 6"/></svg>
Volver al panel</a>
</div></div></body></html>
