<?php
/**
 * Fallas Exitosas · Servicio de autorización no disponible (503).
 *
 * Propósito : Informar una indisponibilidad temporal sin revelar red, SQL ni PII.
 * Autor     : William Valverde V.
 * Fecha     : 2026-09-25
 */

declare(strict_types=1);

$Lv_ErrorId = (string) ($GLOBALS['Gv_ErrorId'] ?? 'sin-identificador');
?>
<!DOCTYPE html>
<html lang="es"><head><meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Fallas Exitosas · Servicio temporalmente no disponible</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<link href="/assets/styles.css" rel="stylesheet"></head>
<body><div class="login-wrap"><div class="login-card">
<img class="logo" src="/assets/logos/anc-logo-claro.png" alt="Grupo ANC">
<h1>Acceso temporalmente no disponible</h1>
<p class="lead-txt">Microsoft confirmó la identidad, pero el servicio que autoriza el acceso no está disponible. Intentá nuevamente cuando se restablezca.</p>
<div class="alerta alerta-info"><i class="bi bi-hash"></i><span>Código de seguimiento: <b><?= htmlspecialchars($Lv_ErrorId, ENT_QUOTES, 'UTF-8') ?></b></span></div>
<a class="btn-ms" href="/index.php"><i class="bi bi-arrow-clockwise"></i>Intentar de nuevo</a>
</div></div></body></html>
