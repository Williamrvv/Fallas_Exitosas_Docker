<?php

declare(strict_types=1);

/**
 * Fallas Exitosas · Sonda HTTPS interna del contenedor.
 *
 * Propósito : comprueba la ruta de salud validando el certificado con la CA local configurada.
 * Autor     : William Valverde V.
 * Fecha     : 2026-09-20
 */

$caCertificate = getenv('TLS_CA_CERTIFICATE_PATH');
if (!is_string($caCertificate) || $caCertificate === '' || !is_readable($caCertificate)) {
    exit(1);
}

$context = stream_context_create([
    'ssl' => [
        'allow_self_signed' => false,
        'cafile' => $caCertificate,
        'peer_name' => 'localhost',
        'verify_peer' => true,
        'verify_peer_name' => true,
    ],
]);

$response = @file_get_contents('https://localhost/health.php', false, $context);

exit(trim((string) $response) === 'ok' ? 0 : 1);
