<?php
/**
 * Fallas Exitosas · OpenID Connect contra Microsoft Entra ID.
 *
 * Propósito : Autenticar con la cuenta corporativa usando el flujo de código de
 *             autorización con PKCE, y validar el id_token contra las llaves
 *             publicadas por el tenant.
 * Autor     : William Valverde V.
 * Fecha     : 2026-09-24
 * Bitácora  : 2026-09-24 Versión inicial.
 *             2026-09-25 Transacción OIDC de un solo uso y logs seguros.
 *
 * Seguridad : Se validan firma (RS256 contra JWKS), emisor, audiencia,
 *             vencimiento y nonce. Sin estas validaciones un id_token podría
 *             ser falsificado.
 */

declare(strict_types=1);

/** URL base del tenant. */
function oidc_base(): string
{
    $Lv_Tenant = config_aplicacion()['entra']['tenant_id'];

    return 'https://login.microsoftonline.com/' . rawurlencode($Lv_Tenant);
}

/** Codificación base64 segura para URL. */
function oidc_base64url(string $Pv_Datos_i): string
{
    return rtrim(strtr(base64_encode($Pv_Datos_i), '+/', '-_'), '=');
}

/** Decodificación base64 segura para URL. */
function oidc_base64url_decodificar(string $Pv_Datos_i): string
{
    $Lv_Relleno = strlen($Pv_Datos_i) % 4;

    if ($Lv_Relleno !== 0) {
        $Pv_Datos_i .= str_repeat('=', 4 - $Lv_Relleno);
    }

    return (string) base64_decode(strtr($Pv_Datos_i, '-_', '+/'), true);
}

/**
 * Construye la URL de autorización y guarda en sesión los valores de un solo
 * uso que después deben coincidir (state, nonce y verificador PKCE).
 */
function oidc_url_autorizacion(): string
{
    $Lar_Entra = config_aplicacion()['entra'];

    $Lv_State        = bin2hex(random_bytes(16));
    $Lv_Nonce        = bin2hex(random_bytes(16));
    $Lv_Verificador  = oidc_base64url(random_bytes(48));
    $Lv_Desafio      = oidc_base64url(hash('sha256', $Lv_Verificador, true));

    $_SESSION['oidc_state']       = $Lv_State;
    $_SESSION['oidc_nonce']       = $Lv_Nonce;
    $_SESSION['oidc_verificador'] = $Lv_Verificador;
    $_SESSION['oidc_iniciada_at'] = time();

    $Lar_Parametros = [
        'client_id'             => $Lar_Entra['client_id'],
        'response_type'         => 'code',
        'redirect_uri'          => $Lar_Entra['redirect_uri'],
        'response_mode'         => 'query',
        'scope'                 => 'openid profile email',
        'state'                 => $Lv_State,
        'nonce'                 => $Lv_Nonce,
        'code_challenge'        => $Lv_Desafio,
        'code_challenge_method' => 'S256',
    ];

    return oidc_base() . '/oauth2/v2.0/authorize?' . http_build_query($Lar_Parametros);
}

/**
 * Consume la transacción que inició el login si state coincide y no venció.
 * Un state inválido nunca borra una transacción legítima en curso.
 *
 * @return array{nonce:string,verificador:string}|null
 */
function oidc_consumir_transaccion(string $Pv_State_i): ?array
{
    $Lv_StateEsperado = (string) ($_SESSION['oidc_state'] ?? '');

    if ($Pv_State_i === '' || $Lv_StateEsperado === '' || !hash_equals($Lv_StateEsperado, $Pv_State_i)) {
        return null;
    }

    $Lar_Transaccion = [
        'nonce'       => (string) ($_SESSION['oidc_nonce'] ?? ''),
        'verificador' => (string) ($_SESSION['oidc_verificador'] ?? ''),
        'iniciada_at' => (int) ($_SESSION['oidc_iniciada_at'] ?? 0),
    ];

    unset(
        $_SESSION['oidc_state'],
        $_SESSION['oidc_nonce'],
        $_SESSION['oidc_verificador'],
        $_SESSION['oidc_iniciada_at']
    );

    $Lb_Completa = $Lar_Transaccion['nonce'] !== '' && $Lar_Transaccion['verificador'] !== '';
    $Lb_Vigente  = $Lar_Transaccion['iniciada_at'] > 0
        && time() - $Lar_Transaccion['iniciada_at'] <= 600;

    return $Lb_Completa && $Lb_Vigente ? $Lar_Transaccion : null;
}

/** Intercambia el código de autorización por tokens. */
function oidc_canjear_codigo(string $Pv_Codigo_i, string $Pv_Verificador_i): array
{
    $Lar_Entra = config_aplicacion()['entra'];

    $Lar_Cuerpo = [
        'client_id'     => $Lar_Entra['client_id'],
        'client_secret' => $Lar_Entra['client_secret'],
        'grant_type'    => 'authorization_code',
        'code'          => $Pv_Codigo_i,
        'redirect_uri'  => $Lar_Entra['redirect_uri'],
        'code_verifier' => $Pv_Verificador_i,
    ];

    $Lo_Curl = curl_init(oidc_base() . '/oauth2/v2.0/token');
    curl_setopt_array($Lo_Curl, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query($Lar_Cuerpo),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);

    $Lv_Respuesta = curl_exec($Lo_Curl);
    $Li_Estado    = (int) curl_getinfo($Lo_Curl, CURLINFO_HTTP_CODE);
    $Li_ErrorCurl = curl_errno($Lo_Curl);
    curl_close($Lo_Curl);

    if ($Lv_Respuesta === false || $Li_Estado !== 200) {
        $Lar_Error      = json_decode((string) $Lv_Respuesta, true);
        $Lv_CodigoOauth = is_array($Lar_Error) ? (string) ($Lar_Error['error'] ?? '') : '';
        $Lv_CodigoOauth = preg_match('/^[a-z0-9_.-]{1,64}$/i', $Lv_CodigoOauth)
            ? $Lv_CodigoOauth
            : 'respuesta_invalida';
        $Lv_ErrorId = bin2hex(random_bytes(6));

        error_log(sprintf(
            'Fallas Exitosas · OIDC error_id=%s etapa=token http=%d curl_errno=%d oauth=%s',
            $Lv_ErrorId,
            $Li_Estado,
            $Li_ErrorCurl,
            $Lv_CodigoOauth
        ));
        throw new RuntimeException('Microsoft no completó el inicio de sesión.');
    }

    $Lar_Tokens = json_decode((string) $Lv_Respuesta, true);

    if (!is_array($Lar_Tokens) || !isset($Lar_Tokens['id_token'])) {
        throw new RuntimeException('La respuesta de Microsoft no incluyó un id_token.');
    }

    return $Lar_Tokens;
}

/** Descarga y memoriza las llaves públicas del tenant. */
function oidc_jwks(): array
{
    static $Sar_Jwks = null;

    if ($Sar_Jwks !== null) {
        return $Sar_Jwks;
    }

    $Lo_Curl = curl_init(oidc_base() . '/discovery/v2.0/keys');
    curl_setopt_array($Lo_Curl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);
    $Lv_Respuesta = curl_exec($Lo_Curl);
    $Li_Estado    = (int) curl_getinfo($Lo_Curl, CURLINFO_HTTP_CODE);
    $Li_ErrorCurl = curl_errno($Lo_Curl);
    curl_close($Lo_Curl);

    if ($Lv_Respuesta === false || $Li_Estado !== 200) {
        $Lv_ErrorId = bin2hex(random_bytes(6));
        error_log(sprintf(
            'Fallas Exitosas · OIDC error_id=%s etapa=jwks http=%d curl_errno=%d',
            $Lv_ErrorId,
            $Li_Estado,
            $Li_ErrorCurl
        ));
        throw new RuntimeException('No fue posible obtener las llaves públicas del tenant.');
    }

    $Lar_Datos = json_decode((string) $Lv_Respuesta, true);

    if (!is_array($Lar_Datos) || empty($Lar_Datos['keys'])) {
        throw new RuntimeException('No fue posible obtener las llaves públicas del tenant.');
    }

    $Sar_Jwks = $Lar_Datos['keys'];

    return $Sar_Jwks;
}

/** Convierte el módulo y exponente de un JWK en una llave pública PEM. */
function oidc_jwk_a_pem(string $Pv_Modulo_i, string $Pv_Exponente_i): string
{
    $Lv_N = oidc_base64url_decodificar($Pv_Modulo_i);
    $Lv_E = oidc_base64url_decodificar($Pv_Exponente_i);

    // Un entero ASN.1 con el bit más alto encendido necesita un byte 0x00 al frente.
    if (ord($Lv_N[0]) > 0x7F) {
        $Lv_N = "\x00" . $Lv_N;
    }
    if (ord($Lv_E[0]) > 0x7F) {
        $Lv_E = "\x00" . $Lv_E;
    }

    $Lv_Secuencia = oidc_asn1(0x02, $Lv_N) . oidc_asn1(0x02, $Lv_E);
    $Lv_Rsa       = oidc_asn1(0x30, $Lv_Secuencia);

    $Lv_Algoritmo = oidc_asn1(0x30, "\x06\x09\x2a\x86\x48\x86\xf7\x0d\x01\x01\x01\x05\x00");
    $Lv_BitString = oidc_asn1(0x03, "\x00" . $Lv_Rsa);
    $Lv_ClaveDer  = oidc_asn1(0x30, $Lv_Algoritmo . $Lv_BitString);

    return "-----BEGIN PUBLIC KEY-----\n"
        . chunk_split(base64_encode($Lv_ClaveDer), 64, "\n")
        . "-----END PUBLIC KEY-----\n";
}

/** Envuelve un valor en una estructura ASN.1 con su longitud. */
function oidc_asn1(int $Pi_Tipo_i, string $Pv_Valor_i): string
{
    $Li_Largo = strlen($Pv_Valor_i);

    if ($Li_Largo < 0x80) {
        $Lv_Largo = chr($Li_Largo);
    } else {
        $Lv_Bytes = ltrim(pack('N', $Li_Largo), "\x00");
        $Lv_Largo = chr(0x80 | strlen($Lv_Bytes)) . $Lv_Bytes;
    }

    return chr($Pi_Tipo_i) . $Lv_Largo . $Pv_Valor_i;
}

/**
 * Valida el id_token y devuelve sus claims.
 * Falla si la firma, el emisor, la audiencia, el vencimiento o el nonce no
 * corresponden.
 */
function oidc_validar_id_token(string $Pv_IdToken_i, string $Pv_NonceEsperado_i): array
{
    $Lar_Partes = explode('.', $Pv_IdToken_i);

    if (count($Lar_Partes) !== 3) {
        throw new RuntimeException('El id_token no tiene el formato esperado.');
    }

    [$Lv_Cabecera64, $Lv_Cuerpo64, $Lv_Firma64] = $Lar_Partes;

    $Lar_Cabecera = json_decode(oidc_base64url_decodificar($Lv_Cabecera64), true);
    $Lar_Claims   = json_decode(oidc_base64url_decodificar($Lv_Cuerpo64), true);

    if (!is_array($Lar_Cabecera) || !is_array($Lar_Claims)) {
        throw new RuntimeException('El id_token no se pudo interpretar.');
    }

    if (($Lar_Cabecera['alg'] ?? '') !== 'RS256') {
        throw new RuntimeException('El id_token usa un algoritmo no permitido.');
    }

    // --- Firma ---
    $Lv_Kid     = (string) ($Lar_Cabecera['kid'] ?? '');
    $Lar_Llave  = null;

    foreach (oidc_jwks() as $Lar_Candidata) {
        if (($Lar_Candidata['kid'] ?? '') === $Lv_Kid) {
            $Lar_Llave = $Lar_Candidata;
            break;
        }
    }

    if ($Lar_Llave === null) {
        throw new RuntimeException('No se encontró la llave pública que firmó el token.');
    }

    $Lv_Pem      = oidc_jwk_a_pem((string) $Lar_Llave['n'], (string) $Lar_Llave['e']);
    $Li_Valida   = openssl_verify(
        $Lv_Cabecera64 . '.' . $Lv_Cuerpo64,
        oidc_base64url_decodificar($Lv_Firma64),
        $Lv_Pem,
        OPENSSL_ALGO_SHA256
    );

    if ($Li_Valida !== 1) {
        throw new RuntimeException('La firma del id_token no es válida.');
    }

    // --- Claims ---
    $Lar_Entra  = config_aplicacion()['entra'];
    $Lv_Emisor  = (string) ($Lar_Claims['iss'] ?? '');
    $Lv_Esperado = 'https://login.microsoftonline.com/' . ($Lar_Claims['tid'] ?? '') . '/v2.0';

    if ($Lv_Emisor !== $Lv_Esperado || ($Lar_Claims['tid'] ?? '') !== $Lar_Entra['tenant_id']) {
        throw new RuntimeException('El id_token proviene de un emisor no autorizado.');
    }

    if (($Lar_Claims['aud'] ?? '') !== $Lar_Entra['client_id']) {
        throw new RuntimeException('El id_token fue emitido para otra aplicación.');
    }

    $Li_Ahora = time();

    if ((int) ($Lar_Claims['exp'] ?? 0) <= $Li_Ahora) {
        throw new RuntimeException('El id_token está vencido.');
    }

    if ((int) ($Lar_Claims['nbf'] ?? 0) > $Li_Ahora + 300) {
        throw new RuntimeException('El id_token todavía no es válido.');
    }

    if ($Pv_NonceEsperado_i === '' || ($Lar_Claims['nonce'] ?? '') !== $Pv_NonceEsperado_i) {
        throw new RuntimeException('El nonce del id_token no corresponde a esta solicitud.');
    }

    return $Lar_Claims;
}

/** URL de cierre de sesión en Entra, para que la salida sea completa. */
function oidc_url_salida(): string
{
    $Lv_Destino = config_aplicacion()['origen_publico'] . '/index.php';

    return oidc_base() . '/oauth2/v2.0/logout?post_logout_redirect_uri=' . rawurlencode($Lv_Destino);
}
