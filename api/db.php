<?php
/**
 * Acceso al "almacén" de datos (JSON) con bloqueo de archivo
 * para evitar condiciones de carrera si hay peticiones simultáneas.
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';

define('LOCK_FILE', dirname(DATA_FILE) . '/store.lock');

/**
 * Adquiere un bloqueo exclusivo que debe mantenerse durante TODA la operación
 * de leer → modificar → guardar, no solo durante la escritura. Esto es lo que
 * evita que dos peticiones simultáneas lean el mismo saldo y una sobrescriba
 * el resultado de la otra (condición de carrera).
 *
 * Se bloquea sobre un archivo dedicado (store.lock), separado de store.json,
 * para no interferir con las lecturas puntuales de loadStore().
 *
 * Nota: si el proceso termina (incluida una llamada a respond()/exit) mientras
 * el bloqueo está activo, el sistema operativo lo libera automáticamente al
 * cerrarse el descriptor de archivo, así que no puede quedar bloqueado para siempre.
 */
function acquireStoreLock()
{
    $fp = fopen(LOCK_FILE, 'c');
    if (!$fp) {
        respond(['error' => 'No se pudo bloquear el almacén de datos.'], 500);
    }
    flock($fp, LOCK_EX); // Bloqueante: espera su turno si otra petición ya lo tiene.
    return $fp;
}

function releaseStoreLock($fp): void
{
    flock($fp, LOCK_UN);
    fclose($fp);
}

/**
 * Lee todo el store. Lanza un error controlado si el archivo no existe o está corrupto.
 * Debe llamarse siempre después de acquireStoreLock() cuando la operación vaya a escribir.
 */
function loadStore(): array
{
    if (!file_exists(DATA_FILE)) {
        respond(['error' => 'No se encontró el archivo de datos.'], 500);
    }

    $contents = file_get_contents(DATA_FILE);
    if ($contents === false) {
        respond(['error' => 'No se pudo leer el archivo de datos.'], 500);
    }

    $data = json_decode($contents, true);
    if (!is_array($data)) {
        respond(['error' => 'El archivo de datos está corrupto.'], 500);
    }

    return $data;
}

/**
 * Guarda el store completo de forma atómica: escribe en un archivo temporal
 * y solo lo sustituye por el definitivo si la escritura fue exitosa
 * (rename() es una operación atómica a nivel de sistema de archivos).
 * Esto evita dejar store.json corrupto o vacío si el proceso se interrumpe.
 *
 * Debe llamarse mientras se mantiene el bloqueo de acquireStoreLock() para
 * que la secuencia completa leer→modificar→guardar sea, en conjunto, atómica.
 */
function saveStore(array $data): void
{
    $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    if ($json === false) {
        respond(['error' => 'Error interno al preparar los datos.'], 500);
    }

    $dir = dirname(DATA_FILE);
    $tmpFile = $dir . '/store.tmp.' . bin2hex(random_bytes(6)) . '.json';

    $bytesWritten = file_put_contents($tmpFile, $json);

    if ($bytesWritten === false || $bytesWritten !== strlen($json)) {
        @unlink($tmpFile);
        respond(['error' => 'Escritura incompleta; se descartó el archivo temporal.'], 500);
    }

    // Sustitución atómica: o queda el archivo viejo completo, o el nuevo completo.
    if (!rename($tmpFile, DATA_FILE)) {
        @unlink($tmpFile);
        respond(['error' => 'No se pudo finalizar el guardado de datos.'], 500);
    }
}

/**
 * Calcula el saldo total a partir del historial de movimientos.
 */
function computeTotalBalance(array $data): float
{
    $total = 0.0;
    foreach ($data['balance']['history'] as $entry) {
        $total += (float) $entry['amount'];
    }
    return round($total, 2);
}

/**
 * Calcula la distribución del saldo total según el modo configurado
 * (porcentaje o monto fijo), devolviendo cada concepto con su monto resultante.
 */
function computeDistribution(array $data): array
{
    $total = computeTotalBalance($data);
    $mode = $data['distribution']['mode'] ?? 'percentage';
    $items = $data['distribution']['items'] ?? [];

    $result = [];
    $assigned = 0.0;

    foreach ($items as $item) {
        if ($mode === 'percentage') {
            $amount = round($total * ((float) $item['value'] / 100), 2);
        } else {
            $amount = round((float) $item['value'], 2);
        }
        $assigned += $amount;
        $result[] = [
            'id' => $item['id'],
            'concept' => $item['concept'],
            'value' => $item['value'],
            'amount' => $amount,
        ];
    }

    return [
        'mode' => $mode,
        'total' => $total,
        'assigned' => round($assigned, 2),
        'remaining' => round($total - $assigned, 2),
        'items' => $result,
    ];
}
