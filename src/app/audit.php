<?php
/**
 * Fallas Exitosas · Bitácora de auditoría.
 *
 * Propósito : Dejar rastro de quién hizo qué y cuándo (PB-21).
 * Autor     : William Valverde V.
 * Fecha     : 2026-09-24
 * Bitácora  : 2026-09-24 Versión inicial.
 */

declare(strict_types=1);

/**
 * Registra un evento en la bitácora.
 *
 * Nunca interrumpe la operación del usuario: si la bitácora falla, se anota en
 * el log del servidor y la petición continúa.
 *
 * @param string $Pv_Accion_i Uno de: ingreso, salida, alta, cambio, baja, acceso_denegado.
 * @param array  $Par_Datos_i usuario_id, correo_actor, entidad, entidad_id,
 *                            valor_anterior, valor_nuevo, detalle.
 */
function audit_registrar(string $Pv_Accion_i, array $Par_Datos_i = []): void
{
    try {
        $Li_UsuarioId = (int) ($Par_Datos_i['usuario_id'] ?? 0);

        db_ejecutar(
            'INSERT INTO fx.auditoria
                 (usuario_id, correo_actor, accion, entidad, entidad_id,
                  valor_anterior, valor_nuevo, detalle, direccion_ip)
             VALUES
                 (:usuario_id, :correo_actor, :accion, :entidad, :entidad_id,
                  :valor_anterior, :valor_nuevo, :detalle, :direccion_ip)',
            [
                ':usuario_id'     => $Li_UsuarioId > 0 ? $Li_UsuarioId : null,
                ':correo_actor'   => $Par_Datos_i['correo_actor'] ?? ($_SESSION['correo'] ?? null),
                ':accion'         => $Pv_Accion_i,
                ':entidad'        => $Par_Datos_i['entidad'] ?? null,
                ':entidad_id'     => $Par_Datos_i['entidad_id'] ?? null,
                ':valor_anterior' => $Par_Datos_i['valor_anterior'] ?? null,
                ':valor_nuevo'    => $Par_Datos_i['valor_nuevo'] ?? null,
                ':detalle'        => $Par_Datos_i['detalle'] ?? null,
                ':direccion_ip'   => audit_direccion_ip(),
            ]
        );
    } catch (Throwable $Lo_Error) {
        $Lv_ErrorId = bin2hex(random_bytes(6));
        error_log(sprintf(
            'Fallas Exitosas · auditoría no disponible error_id=%s tipo=%s',
            $Lv_ErrorId,
            get_class($Lo_Error)
        ));
    }
}

/** Dirección IP del cliente, acotada al largo de la columna. */
function audit_direccion_ip(): ?string
{
    $Lv_Ip = $_SERVER['REMOTE_ADDR'] ?? null;

    return $Lv_Ip === null ? null : substr((string) $Lv_Ip, 0, 45);
}
