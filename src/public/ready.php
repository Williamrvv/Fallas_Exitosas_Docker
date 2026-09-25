<?php
/**
 * Fallas Exitosas · Sonda de disponibilidad.
 *
 * Propósito : Verificar que SQL Server responde y que el esquema fx está instalado.
 *             La sonda de liveness continúa separada en health.php.
 * Autor     : William Valverde V.
 * Fecha     : 2026-09-25
 */

declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';

$Gar_Estado = db_estado();
$Gb_Disponible = ($Gar_Estado['conectado'] ?? false) === true
    && ($Gar_Estado['esquema_instalado'] ?? false) === true;

http_response_code($Gb_Disponible ? 200 : 503);
header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-store');

echo $Gb_Disponible ? 'ready' : 'unavailable';
