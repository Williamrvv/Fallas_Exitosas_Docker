<?php
/**
 * Fallas Exitosas · Acceso a SQL Server.
 *
 * Propósito : Entregar una conexión PDO única y helpers de consulta con
 *             parámetros. Toda consulta va parametrizada; nunca concatenada.
 * Autor     : William Valverde V.
 * Fecha     : 2026-09-24
 * Bitácora  : 2026-09-24 Versión inicial.
 */

declare(strict_types=1);

/**
 * Valor `Server=` del DSN.
 *
 * Con instancia nombrada (EQUIPO\\SQLEXPRESS) el puerto es dinámico y lo
 * resuelve el SQL Browser, así que no se agrega el puerto al DSN.
 */
function db_servidor(): string
{
    $Lar_Db = config_aplicacion()['base_datos'];

    return db_instancia_nombrada()
        ? (string) $Lar_Db['host']
        : sprintf('%s,%d', $Lar_Db['host'], (int) $Lar_Db['puerto']);
}

/** Indica si DB_HOST apunta a una instancia nombrada. */
function db_instancia_nombrada(): bool
{
    return str_contains((string) config_aplicacion()['base_datos']['host'], '\\');
}

/** Conexión PDO a la base propia, reutilizada durante la petición. */
function db_conexion(): PDO
{
    static $So_Conexion = null;

    if ($So_Conexion instanceof PDO) {
        return $So_Conexion;
    }

    $Lar_Db = config_aplicacion()['base_datos'];

    $Lv_Dsn = sprintf(
        'sqlsrv:Server=%s;Database=%s;Encrypt=%s;TrustServerCertificate=%s;LoginTimeout=10',
        db_servidor(),
        $Lar_Db['nombre'],
        $Lar_Db['cifrar'] ? 'yes' : 'no',
        $Lar_Db['confiar_cert'] ? 'yes' : 'no'
    );

    try {
        $So_Conexion = new PDO($Lv_Dsn, $Lar_Db['usuario'], $Lar_Db['contrasena'], [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_STRINGIFY_FETCHES  => false,
        ]);
    } catch (PDOException $Lo_Error) {
        // Registra solo códigos técnicos; el mensaje del driver puede incluir host o usuario.
        $Lar_ErrorInfo = is_array($Lo_Error->errorInfo ?? null) ? $Lo_Error->errorInfo : [];
        $Lv_SqlState   = preg_replace('/[^A-Z0-9]/i', '', (string) ($Lar_ErrorInfo[0] ?? ''));
        $Lv_DriverCode = preg_replace('/[^A-Z0-9-]/i', '', (string) ($Lar_ErrorInfo[1] ?? ''));
        $Lv_ErrorId    = bin2hex(random_bytes(6));

        error_log(sprintf(
            'Fallas Exitosas · SQL error_id=%s sqlstate=%s driver_code=%s',
            $Lv_ErrorId,
            $Lv_SqlState !== '' ? $Lv_SqlState : 'desconocido',
            $Lv_DriverCode !== '' ? $Lv_DriverCode : 'desconocido'
        ));
        throw new RuntimeException('No fue posible conectar con la base de datos.', 0, $Lo_Error);
    }

    return $So_Conexion;
}

/**
 * Ejecuta una consulta parametrizada.
 *
 * Nota IRI: con PDO_SQLSRV un mismo parámetro nombrado no puede repetirse
 * dentro de la misma sentencia; usar nombres distintos si hace falta.
 */
function db_ejecutar(string $Pv_Sql_i, array $Par_Parametros_i = []): PDOStatement
{
    $Lo_Sentencia = db_conexion()->prepare($Pv_Sql_i);
    $Lo_Sentencia->execute($Par_Parametros_i);

    return $Lo_Sentencia;
}

/** Devuelve todas las filas de una consulta. */
function db_filas(string $Pv_Sql_i, array $Par_Parametros_i = []): array
{
    return db_ejecutar($Pv_Sql_i, $Par_Parametros_i)->fetchAll();
}

/** Devuelve la primera fila, o null si no hay resultados. */
function db_fila(string $Pv_Sql_i, array $Par_Parametros_i = []): ?array
{
    $Lar_Fila = db_ejecutar($Pv_Sql_i, $Par_Parametros_i)->fetch();

    return $Lar_Fila === false ? null : $Lar_Fila;
}

/** Comprueba que la base responde y que el esquema está instalado. */
function db_estado(): array
{
    try {
        $Lar_Fila = db_fila(
            'SELECT COUNT(*) AS total FROM sys.tables AS t
                 INNER JOIN sys.schemas AS s ON s.schema_id = t.schema_id
             WHERE s.name = :esquema'
            ,
            [':esquema' => 'fx']
        );

        $Li_Tablas = (int) ($Lar_Fila['total'] ?? 0);

        return [
            'conectado'        => true,
            'esquema_instalado' => $Li_Tablas > 0,
            'tablas'           => $Li_Tablas,
        ];
    } catch (Throwable $Lo_Error) {
        return [
            'conectado'         => false,
            'esquema_instalado' => false,
            'tablas'            => 0,
            'mensaje'           => $Lo_Error->getMessage(),
        ];
    }
}

/**
 * Comprobación rápida de disponibilidad para la pantalla de ingreso.
 *
 * Primero se prueba el puerto con un tiempo de espera corto: si el servidor no
 * responde, no tiene sentido esperar el tiempo completo del controlador ODBC.
 * Así la pantalla de ingreso nunca se queda colgada.
 *
 * @return array{alcanzable:bool, esquema:bool, motivo:string}
 */
function db_disponibilidad(int $Pi_TiempoEspera_i = 2): array
{
    $Lar_Db = config_aplicacion()['base_datos'];

    // Con instancia nombrada el puerto no se conoce de antemano: se omite la
    // sonda y se deja que el controlador resuelva por SQL Browser.
    if (!db_instancia_nombrada()) {
        $Lo_Socket = @fsockopen(
            $Lar_Db['host'],
            $Lar_Db['puerto'],
            $Li_ErrNo,
            $Lv_ErrStr,
            $Pi_TiempoEspera_i
        );

        if ($Lo_Socket === false) {
            return [
                'alcanzable' => false,
                'esquema'    => false,
                'motivo'     => 'sin_conexion',
            ];
        }

        fclose($Lo_Socket);
    }

    $Lar_Estado = db_estado();

    if (($Lar_Estado['conectado'] ?? false) !== true) {
        return ['alcanzable' => false, 'esquema' => false, 'motivo' => 'sin_conexion'];
    }

    return [
        'alcanzable' => true,
        'esquema'    => ($Lar_Estado['esquema_instalado'] ?? false) === true,
        'motivo'     => ($Lar_Estado['esquema_instalado'] ?? false) === true ? 'ok' : 'sin_esquema',
    ];
}
