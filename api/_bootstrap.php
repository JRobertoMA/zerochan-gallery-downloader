<?php
/**
 * Común a todos los endpoints: carga config, crea cliente/downloader y helpers de respuesta.
 */
require __DIR__ . '/../config.php';

$limiter    = new RateLimiter(ZC_RATE_LIMIT);
$client     = new ZerochanClient($limiter);
$index      = new Index();
$downloader = new Downloader($client, $index);
$jobs       = new Jobs();
$library    = new Library($index);

function json_out(array $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function json_error(Throwable $e): never
{
    $code = $e->getCode();
    $status = ($code >= 400 && $code < 600) ? $code : 502;
    json_out(['error' => $e->getMessage(), 'status' => $status], $status);
}

/** Normaliza los filtros de búsqueda recibidos por GET; descarta valores fuera de los permitidos. */
function search_params(array $in): array
{
    $p = [];
    $p['p'] = max(1, (int) ($in['p'] ?? 1));
    $p['l'] = max(1, min(250, (int) ($in['l'] ?? 40)));
    if (in_array($in['s'] ?? '', ZerochanClient::SORTS, true))  $p['s'] = $in['s'];
    if (in_array($in['t'] ?? '', ZerochanClient::TIMES, true))  $p['t'] = $in['t'];
    if (in_array($in['d'] ?? '', ZerochanClient::DIMS, true))   $p['d'] = $in['d'];
    if (in_array($in['c'] ?? '', ZerochanClient::COLORS, true)) $p['c'] = $in['c'];
    if (!empty($in['strict']) && $in['strict'] !== '0' && $in['strict'] !== 'false') $p['strict'] = true;
    // Filtro local, no va a la URL de la API (buildSearchUrl lo ignora)
    $p['ecchi'] = in_array($in['ecchi'] ?? '', ZerochanClient::ECCHI_MODES, true) ? $in['ecchi'] : 'all';
    $p['min']   = max(0, min(10000, (int) ($in['min'] ?? 0)));   // lado corto mínimo en px, también local
    return $p;
}

/** "Tag1, Tag2" → ['Tag1','Tag2'] */
function parse_tags(string $raw): array
{
    return array_values(array_filter(array_map('trim', explode(',', $raw)), fn($t) => $t !== ''));
}
