#!/bin/sh
set -eu

# Fallas Exitosas · Preparación TLS antes de iniciar Apache.
# Genera una CA local y un certificado de servidor únicamente en el entorno demo.

fail() {
    printf '%s\n' "Fallas Exitosas TLS: $1" >&2
    exit 1
}

is_true() {
    case "$(printf '%s' "$1" | tr '[:upper:]' '[:lower:]')" in
        1|true|yes|on) return 0 ;;
        *) return 1 ;;
    esac
}

public_origin="${APP_PUBLIC_ORIGIN:-https://localhost:8659}"
public_origin="${public_origin%/}"
case "$public_origin" in
    https://*) ;;
    *) fail "APP_PUBLIC_ORIGIN debe iniciar con https://" ;;
esac
export APP_PUBLIC_ORIGIN="$public_origin"

ca_certificate="${TLS_CA_CERTIFICATE_PATH:-/var/lib/fallas-exitosas/tls/local-ca.crt}"
ca_private_key="${TLS_CA_PRIVATE_KEY_PATH:-/var/lib/fallas-exitosas/tls/local-ca.key}"
server_certificate="${TLS_CERTIFICATE_PATH:-/var/lib/fallas-exitosas/tls/server.crt}"
server_private_key="${TLS_PRIVATE_KEY_PATH:-/var/lib/fallas-exitosas/tls/server.key}"
common_name="${TLS_CERTIFICATE_COMMON_NAME:-localhost}"
subject_alt_name="${TLS_CERTIFICATE_SAN:-DNS:localhost,IP:127.0.0.1,IP:::1}"

tls_directory="$(dirname "$server_certificate")"
[ "$(dirname "$server_private_key")" = "$tls_directory" ] \
    || fail "el certificado y la llave TLS deben compartir directorio"
mkdir -p "$tls_directory" "$(dirname "$ca_certificate")" "$(dirname "$ca_private_key")"
server_san_record="${tls_directory}/server.san"

generate_ca() {
    umask 077
    openssl req -x509 -new -nodes -newkey rsa:3072 -sha256 -days 3650 \
        -subj "/C=CR/O=Grupo ANC/OU=Fallas Exitosas/CN=Fallas Exitosas Local CA" \
        -keyout "$ca_private_key" \
        -out "$ca_certificate" >/dev/null 2>&1
    chmod 0600 "$ca_private_key"
    chmod 0644 "$ca_certificate"
}

generate_server_certificate() {
    request_file="${tls_directory}/server.csr"
    extension_file="${tls_directory}/server.ext"
    umask 077

    openssl req -new -nodes -newkey rsa:3072 -sha256 \
        -subj "/C=CR/O=Grupo ANC/OU=Fallas Exitosas/CN=${common_name}" \
        -keyout "$server_private_key" \
        -out "$request_file" >/dev/null 2>&1

    printf '%s\n' \
        "basicConstraints=critical,CA:FALSE" \
        "keyUsage=critical,digitalSignature,keyEncipherment" \
        "extendedKeyUsage=serverAuth" \
        "subjectAltName=${subject_alt_name}" > "$extension_file"

    openssl x509 -req -sha256 -days 825 \
        -in "$request_file" \
        -CA "$ca_certificate" \
        -CAkey "$ca_private_key" \
        -CAcreateserial \
        -extfile "$extension_file" \
        -out "$server_certificate" >/dev/null 2>&1

    rm -f "$request_file" "$extension_file"
    chmod 0600 "$server_private_key"
    chmod 0644 "$server_certificate"
    printf '%s\n' "${common_name}|${subject_alt_name}" > "$server_san_record"
    chmod 0644 "$server_san_record"
}

development_ca="${TLS_DEVELOPMENT_CA_ENABLED:-true}"
if is_true "$development_ca"; then
    if [ ! -s "$ca_certificate" ] || [ ! -s "$ca_private_key" ]; then
        [ ! -e "$ca_certificate" ] && [ ! -e "$ca_private_key" ] \
            || fail "la CA local está incompleta; restaure ambos archivos"
        generate_ca
    fi

    renew_server=false
    if [ ! -s "$server_certificate" ] || [ ! -s "$server_private_key" ]; then
        [ ! -e "$server_certificate" ] && [ ! -e "$server_private_key" ] \
            || fail "el certificado TLS está incompleto; restaure ambos archivos"
        renew_server=true
    elif ! openssl x509 -checkend 2592000 -noout -in "$server_certificate" >/dev/null 2>&1; then
        renew_server=true
    elif [ ! -r "$server_san_record" ] \
        || [ "$(cat "$server_san_record")" != "${common_name}|${subject_alt_name}" ]; then
        renew_server=true
    fi

    if [ "$renew_server" = true ]; then
        generate_server_certificate
    fi
fi

[ -r "$ca_certificate" ] || fail "no se puede leer la CA configurada"
[ -r "$server_certificate" ] || fail "no se puede leer el certificado TLS configurado"
[ -r "$server_private_key" ] || fail "no se puede leer la llave privada TLS configurada"

openssl x509 -checkend 0 -noout -in "$server_certificate" >/dev/null 2>&1 \
    || fail "el certificado TLS está vencido o no es válido"
openssl pkey -check -noout -in "$server_private_key" >/dev/null 2>&1 \
    || fail "la llave privada TLS no es válida"
openssl verify -CAfile "$ca_certificate" "$server_certificate" >/dev/null 2>&1 \
    || fail "el certificado TLS no encadena con la CA configurada"

certificate_public_key="$(openssl x509 -pubkey -noout -in "$server_certificate" \
    | openssl pkey -pubin -outform DER 2>/dev/null \
    | sha256sum | cut -d' ' -f1)"
private_public_key="$(openssl pkey -pubout -in "$server_private_key" 2>/dev/null \
    | openssl pkey -pubin -outform DER 2>/dev/null \
    | sha256sum | cut -d' ' -f1)"
[ "$certificate_public_key" = "$private_public_key" ] \
    || fail "el certificado TLS y la llave privada no corresponden"

exec docker-php-entrypoint "$@"
