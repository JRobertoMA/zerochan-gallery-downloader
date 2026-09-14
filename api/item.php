<?php
/**
 * GET api/item.php?id=3793685 → detalle de la imagen (small/medium/large/full, tags, source...)
 */
require __DIR__ . '/_bootstrap.php';

$id = (int) ($_GET['id'] ?? 0);
if ($id <= 0) {
    json_out(['error' => 'id inválido', 'status' => 400], 400);
}

try {
    $item = $client->item($id);
    header('X-Cache: ' . ($client->lastFromCache ? 'HIT' : 'MISS'));
    json_out($item);
} catch (Throwable $e) {
    json_error($e);
}
