<?php
/**
 * POST api/download.php  (id=3793685) → descarga el full a downloads/<tag>/<id>.<ext>
 * → { id, status: 'downloaded'|'skipped', path, url, primary, size }
 */
require __DIR__ . '/_bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_out(['error' => 'Usa POST', 'status' => 405], 405);
}
$id = (int) ($_POST['id'] ?? 0);
if ($id <= 0) {
    json_out(['error' => 'id inválido', 'status' => 400], 400);
}

try {
    json_out($downloader->one($id));
} catch (Throwable $e) {
    json_error($e);
}
