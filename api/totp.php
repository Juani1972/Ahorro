<?php
/**
 * TOTP (RFC 6238) en PHP puro, sin librerías externas.
 * Compatible con cualquier app autenticadora estándar (Google Authenticator,
 * Authy, Microsoft Authenticator, 1Password, etc.).
 *
 * Importante: el secreto nunca se envía a ningún servicio de terceros para
 * generar un código QR — solo se muestra al usuario como texto (clave
 * manual) para que lo introduzca él mismo en su app autenticadora.
 */

declare(strict_types=1);

const TOTP_PERIOD = 30;   // segundos por código, estándar de facto
const TOTP_DIGITS = 6;
const TOTP_ALGO = 'sha1';  // el que usan todas las apps autenticadoras habituales

/**
 * Genera un secreto aleatorio de 20 bytes (160 bits), codificado en Base32
 * (formato estándar que esperan las apps autenticadoras).
 */
function totpGenerateSecret(): string
{
    return totpBase32Encode(random_bytes(20));
}

/**
 * URI otpauth:// para que el usuario pueda añadir la cuenta escaneando un QR
 * generado por él mismo (o copiando la clave manualmente). No se llama a
 * ningún servicio externo desde el servidor.
 */
function totpProvisioningUri(string $secret, string $username, string $issuer = 'Arca'): string
{
    $label = rawurlencode($issuer) . ':' . rawurlencode($username);
    $params = http_build_query([
        'secret' => $secret,
        'issuer' => $issuer,
        'algorithm' => 'SHA1',
        'digits' => TOTP_DIGITS,
        'period' => TOTP_PERIOD,
    ]);
    return "otpauth://totp/$label?$params";
}

/**
 * Calcula el código TOTP de 6 dígitos para un instante de tiempo dado.
 */
function totpCodeAt(string $secret, int $timestamp): string
{
    $key = totpBase32Decode($secret);
    $counter = intdiv($timestamp, TOTP_PERIOD);
    $counterBytes = pack('N*', 0, $counter); // 8 bytes big-endian

    $hash = hash_hmac(TOTP_ALGO, $counterBytes, $key, true);
    $offset = ord($hash[strlen($hash) - 1]) & 0x0F;

    $binary = ((ord($hash[$offset]) & 0x7F) << 24)
        | ((ord($hash[$offset + 1]) & 0xFF) << 16)
        | ((ord($hash[$offset + 2]) & 0xFF) << 8)
        | (ord($hash[$offset + 3]) & 0xFF);

    $code = $binary % (10 ** TOTP_DIGITS);
    return str_pad((string) $code, TOTP_DIGITS, '0', STR_PAD_LEFT);
}

/**
 * Verifica un código introducido por el usuario, admitiendo ±1 periodo
 * (±30s) de desfase de reloj entre el móvil y el servidor, que es habitual.
 */
function totpVerifyCode(string $secret, string $code): bool
{
    $code = trim($code);
    if (!preg_match('/^\d{6}$/', $code)) {
        return false;
    }

    $now = time();
    foreach ([-1, 0, 1] as $windowOffset) {
        $candidate = totpCodeAt($secret, $now + ($windowOffset * TOTP_PERIOD));
        if (hash_equals($candidate, $code)) {
            return true;
        }
    }
    return false;
}

// --- Base32 (RFC 4648), sin depender de la extensión opcional de PHP ---

function totpBase32Encode(string $data): string
{
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $bits = '';
    foreach (str_split($data) as $char) {
        $bits .= str_pad(decbin(ord($char)), 8, '0', STR_PAD_LEFT);
    }
    $bits = str_pad($bits, (int) ceil(strlen($bits) / 5) * 5, '0', STR_PAD_RIGHT);

    $output = '';
    foreach (str_split($bits, 5) as $chunk) {
        $output .= $alphabet[bindec($chunk)];
    }
    return $output;
}

function totpBase32Decode(string $base32): string
{
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $base32 = strtoupper(rtrim($base32, '='));

    $bits = '';
    foreach (str_split($base32) as $char) {
        $pos = strpos($alphabet, $char);
        if ($pos === false) {
            continue; // ignora caracteres no válidos, por si el usuario copia con espacios
        }
        $bits .= str_pad(decbin($pos), 5, '0', STR_PAD_LEFT);
    }

    $output = '';
    foreach (str_split($bits, 8) as $byte) {
        if (strlen($byte) === 8) {
            $output .= chr(bindec($byte));
        }
    }
    return $output;
}
