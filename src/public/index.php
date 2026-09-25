<?php
/**
 * Fallas Exitosas · Pantalla de ingreso.
 *
 * Propósito : Iniciar sesión con la cuenta corporativa (Microsoft Entra ID).
 * Autor     : William Valverde V.
 * Fecha     : 2026-09-24
 * Bitácora  : 2026-09-24 Versión inicial.
 */

declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';

sesion_iniciar();

// Con sesión vigente no tiene sentido volver a pedir credenciales.
if (isset($_SESSION['usuario_id']) && sesion_motivo_expiracion() === null) {
    header('Location: /dashboard.php');
    exit;
}

$Gb_EntraLista = config_entra_lista();

/** Mensajes de retorno. Cada uno explica causa y qué hacer. */
$Gar_Avisos = [
    'inactividad'    => ['info',  'La sesión se cerró por 30 minutos sin actividad. Volvé a ingresar.'],
    'maximo'         => ['info',  'La sesión alcanzó su duración máxima. Volvé a ingresar.'],
    'revocada'       => ['error', 'Tu acceso fue desactivado. Comunicate con el administrador de la plataforma.'],
    'no_autorizado'  => ['error', 'Tu cuenta es válida en la organización, pero todavía no está autorizada en Fallas Exitosas. Solicitá el alta al administrador.'],
    'inactivo'       => ['error', 'Tu usuario está inactivo en la plataforma. Comunicate con el administrador.'],
    'error'          => ['error', 'No se pudo completar el inicio de sesión. Intentá de nuevo; si persiste, avisá al administrador.'],
];

$Gv_Aviso  = (string) ($_GET['aviso'] ?? '');
$Gar_Aviso = $Gar_Avisos[$Gv_Aviso] ?? null;

/* Aviso previo: si la autorización no está disponible, conviene decirlo acá y
   no después de que la persona ya se autenticó en Microsoft. */
$Gar_Disponibilidad = $Gb_EntraLista
    ? db_disponibilidad()
    : ['alcanzable' => true, 'esquema' => true, 'motivo' => 'ok'];

$Gar_AvisoServicio = match ($Gar_Disponibilidad['motivo']) {
    'sin_conexion' => 'El servidor de base de datos no responde, así que el ingreso '
        . 'no se va a poder completar todavía. Microsoft sí va a pedirte la contraseña, '
        . 'pero al volver el acceso quedará en espera.',
    'sin_esquema'  => 'La base de datos responde, pero todavía no tiene el esquema '
        . 'instalado. Hay que ejecutar los scripts de la carpeta database.',
    default        => null,
};
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Fallas Exitosas · Ingreso</title>
<link rel="icon" href="/assets/logos/favicon-192.png">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<link href="/assets/styles.css" rel="stylesheet">
</head>
<body>
<div class="login-wrap">
    <div class="login-card">
        <img class="logo" src="/assets/logos/anc-logo-claro.png" alt="Grupo ANC">

        <h1>Fallas Exitosas</h1>
        <p class="lead-txt">Plataforma regional de Calidad de Servicio</p>

        <?php if ($Gar_Aviso !== null): ?>
            <div class="alerta alerta-<?= e($Gar_Aviso[0]) ?>">
                <i class="bi bi-<?= $Gar_Aviso[0] === 'error' ? 'exclamation-triangle' : 'info-circle' ?>"></i>
                <span><?= e($Gar_Aviso[1]) ?></span>
            </div>
        <?php endif; ?>

        <?php if ($Gar_AvisoServicio !== null): ?>
            <div class="alerta alerta-error">
                <i class="bi bi-exclamation-triangle"></i>
                <span><?= e($Gar_AvisoServicio) ?></span>
            </div>
        <?php endif; ?>

        <?php if ($Gb_EntraLista): ?>
            <a class="btn-ms" href="/login.php">
                <i class="bi bi-microsoft"></i>
                Iniciar sesión con Microsoft
            </a>
        <?php else: ?>
            <div class="alerta alerta-info">
                <i class="bi bi-gear"></i>
                <span>
                    Falta registrar la aplicación en Entra ID. Completá
                    <code>O365_CLIENT_ID</code> y <code>O365_CLIENT_SECRET</code>
                    en el archivo <code>.env</code> y reiniciá el contenedor.
                </span>
            </div>
        <?php endif; ?>

        <div class="login-foot">
            El acceso se concede únicamente a personas autorizadas en la plataforma.<br>
            Grupo ANC · Costa Rica · Guatemala · Nicaragua · Perú
        </div>
    </div>
</div>
</body>
</html>
