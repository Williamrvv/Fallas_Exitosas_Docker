<?php
/**
 * Fallas Exitosas · Error interno (500).
 * Muestra un identificador para ubicar el detalle en el log del contenedor.
 * Autor : William Valverde V.  |  Fecha : 2026-09-24
 */
declare(strict_types=1);
$Lv_ErrorId = (string) ($GLOBALS['Gv_ErrorId'] ?? 'sin-identificador');
?>
<!DOCTYPE html>
<html lang="es"><head><meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Fallas Exitosas · Error</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<link href="/assets/styles.css" rel="stylesheet"></head>
<body><div class="login-wrap"><div class="login-card">
<img class="logo" src="/assets/logos/anc-logo-claro.png" alt="Grupo ANC">
<h1>Algo falló de nuestro lado</h1>
<p class="lead-txt">La operación no se completó. Volvé a intentarlo; si continúa, pasale este código al administrador para ubicar el detalle.</p>
<div class="alerta alerta-error"><i class="bi bi-hash"></i><span>Código de error: <b><?= htmlspecialchars($Lv_ErrorId, ENT_QUOTES, 'UTF-8') ?></b></span></div>
<a class="btn-ms" href="/index.php"><i class="bi bi-arrow-left"></i>Volver al inicio</a>
</div></div></body></html>
