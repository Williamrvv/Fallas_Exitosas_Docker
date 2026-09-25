<?php
/**
 * Fallas Exitosas · Configuración del entorno.
 *
 * Propósito : Leer la configuración desde variables de entorno y fallar rápido
 *             si falta algo indispensable. Ningún secreto vive en el código.
 * Autor     : William Valverde V.
 * Fecha     : 2026-09-24
 * Bitácora  : 2026-09-24 Versión inicial.
 */

declare(strict_types=1);

/**
 * Devuelve una variable de entorno como texto.
 *
 * @param string      $Pv_Nombre_i     Nombre de la variable.
 * @param string|null $Pv_PorDefecto_i Valor si no está definida; null la hace obligatoria.
 */
function config_texto(string $Pv_Nombre_i, ?string $Pv_PorDefecto_i = null): string
{
    $Lv_Valor = getenv($Pv_Nombre_i);

    if ($Lv_Valor === false || $Lv_Valor === '') {
        if ($Pv_PorDefecto_i === null) {
            throw new RuntimeException(
                "Falta la variable de entorno obligatoria {$Pv_Nombre_i}. Revise el archivo .env."
            );
        }

        return $Pv_PorDefecto_i;
    }

    return $Lv_Valor;
}

/** Devuelve una variable de entorno como entero acotado. */
function config_entero(string $Pv_Nombre_i, int $Pi_PorDefecto_i, int $Pi_Minimo_i, int $Pi_Maximo_i): int
{
    $Li_Valor = (int) config_texto($Pv_Nombre_i, (string) $Pi_PorDefecto_i);

    return max($Pi_Minimo_i, min($Pi_Maximo_i, $Li_Valor));
}

/** Interpreta una variable de entorno como booleano. */
function config_booleano(string $Pv_Nombre_i, bool $Pb_PorDefecto_i): bool
{
    $Lv_Valor = strtolower(config_texto($Pv_Nombre_i, $Pb_PorDefecto_i ? 'true' : 'false'));

    return in_array($Lv_Valor, ['1', 'true', 'yes', 'on', 'si', 'sí'], true);
}

/** Configuración completa de la aplicación. */
function config_aplicacion(): array
{
    static $Sar_Config = null;

    if ($Sar_Config !== null) {
        return $Sar_Config;
    }

    $Lv_Origen = rtrim(config_texto('APP_PUBLIC_ORIGIN'), '/');

    if (!str_starts_with($Lv_Origen, 'https://')) {
        throw new RuntimeException('APP_PUBLIC_ORIGIN debe usar https://. La aplicación no opera sobre HTTP.');
    }

    $Sar_Config = [
        'entorno'        => config_texto('APP_ENV', 'desarrollo'),
        'origen_publico' => $Lv_Origen,

        'sesion' => [
            'inactividad_segundos' => config_entero('SESSION_IDLE_TIMEOUT', 1800, 300, 86400),
            'absoluto_segundos'    => config_entero('SESSION_ABSOLUTE_TIMEOUT', 28800, 900, 604800),
        ],

        'base_datos' => [
            'host'        => config_texto('DB_HOST'),
            'puerto'      => config_entero('DB_PORT', 1433, 1, 65535),
            'nombre'      => config_texto('DB_NAME'),
            'usuario'     => config_texto('DB_USER'),
            'contrasena'  => config_texto('DB_PASSWORD'),
            'cifrar'      => config_booleano('DB_ENCRYPT', false),
            'confiar_cert'=> config_booleano('DB_TRUST_CERT', true),
        ],

        'entra' => [
            'tenant_id'     => config_texto('O365_TENANT_ID', ''),
            'client_id'     => config_texto('O365_CLIENT_ID', ''),
            'client_secret' => config_texto('O365_CLIENT_SECRET', ''),
            'redirect_uri'  => config_texto('O365_REDIRECT_URI', $Lv_Origen . '/callback.php'),
        ],
    ];

    return $Sar_Config;
}

/**
 * Indica si Entra ID está completamente configurado.
 * Permite mostrar un mensaje claro en lugar de un error técnico cuando todavía
 * no se ha creado el App Registration.
 */
function config_entra_lista(): bool
{
    $Lar_Entra = config_aplicacion()['entra'];

    return $Lar_Entra['tenant_id'] !== ''
        && $Lar_Entra['client_id'] !== ''
        && $Lar_Entra['client_secret'] !== '';
}
