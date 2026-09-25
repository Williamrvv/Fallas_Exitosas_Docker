<?php
/**
 * Fallas Exitosas · Sonda de salud.
 *
 * Propósito : Responder si el contenedor está en pie. No toca la base de datos
 *             a propósito: una caída de SQL Server no debe reiniciar el web.
 * Autor     : William Valverde V.
 * Fecha     : 2026-09-24
 */

declare(strict_types=1);

header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-store');

echo 'ok';
