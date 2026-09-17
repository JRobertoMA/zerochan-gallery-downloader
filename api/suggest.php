<?php
/**
 * GET api/suggest.php?q=gawr → { q, items: [{ tag, type, parent }] }   (autocompletado del buscador)
 */
require __DIR__ . '/_bootstrap.php';

$q = trim((string) ($_GET['q'] ?? ''));
if ($q === '' || mb_strlen($q) > 60) {
    json_out(['error' => 'q inválido (1-60 caracteres)', 'status' => 400], 400);
}

try {
    $items = $client->suggest($q);
    header('X-Cache: ' . ($client->lastFromCache ? 'HIT' : 'MISS'));
    json_out(['q' => $q, 'items' => $items]);
} catch (Throwable $e) {
    json_error($e);
}
