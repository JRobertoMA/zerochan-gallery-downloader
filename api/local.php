<?php
/**
 * Imágenes ya descargadas: detalle, borrado y recortes.
 *   GET  api/local.php?id=X                       → { id, primary, tags, width, height, size, url, crops: [...] }
 *   POST action=delete&id=X | ids=1,2,3 (máx 200) → { deleted: [ids], missing: [ids], freed: bytes }
 *   POST action=delete_crop&id=X&name=<fichero>   → { ok, crops }
 *   POST action=crop&id=X&x=&y=&w=&h= (0..1)      → { ok, crop, crops }
 */
require __DIR__ . '/_bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $id = (int) ($_GET['id'] ?? 0);
    if ($id <= 0) json_out(['error' => 'id inválido', 'status' => 400], 400);
    $it = $library->locate($id);
    if (!$it) json_out(['error' => 'imagen no descargada', 'status' => 404], 404);
    unset($it['_path']);
    json_out($it);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_out(['error' => 'Usa GET o POST', 'status' => 405], 405);
}

$action = (string) ($_POST['action'] ?? '');
$id = (int) ($_POST['id'] ?? 0);

try {
    switch ($action) {
        case 'delete':
            $raw = $_POST['ids'] ?? (string) $id;
            $ids = array_values(array_unique(array_filter(array_map('intval', explode(',', (string) $raw)), fn($n) => $n > 0)));
            if (!$ids) json_out(['error' => 'id inválido', 'status' => 400], 400);
            if (count($ids) > 200) json_out(['error' => 'máximo 200 ids por petición', 'status' => 400], 400);
            $deleted = $missing = [];
            $freed = 0;
            foreach ($ids as $n) {
                $b = $library->delete($n);
                if ($b === false) $missing[] = $n; else { $deleted[] = $n; $freed += $b; }
            }
            json_out(['deleted' => $deleted, 'missing' => $missing, 'freed' => $freed]);

        case 'delete_crop':
            if ($id <= 0) json_out(['error' => 'id inválido', 'status' => 400], 400);
            json_out(['ok' => true, 'crops' => $library->deleteCrop($id, (string) ($_POST['name'] ?? ''))]);

        case 'crop':
            if ($id <= 0) json_out(['error' => 'id inválido', 'status' => 400], 400);
            $crop = $library->crop($id, ['x' => $_POST['x'] ?? null, 'y' => $_POST['y'] ?? null, 'w' => $_POST['w'] ?? null, 'h' => $_POST['h'] ?? null]);
            $it = $library->locate($id);
            json_out(['ok' => true, 'crop' => $crop, 'crops' => $it['crops'] ?? [$crop]]);

        default:
            json_out(['error' => 'action inválida', 'status' => 400], 400);
    }
} catch (Throwable $e) {
    json_error($e);
}
