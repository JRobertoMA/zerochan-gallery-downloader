<?php
/**
 * Descarga imágenes en tamaño full a downloads/<slug del tag primario>/<id>.<ext>
 * junto a <id>.json con los metadatos completos. Verifica el md5 contra `hash` y mantiene el índice SQLite.
 */
class Downloader
{
    public function __construct(private ZerochanClient $client, private Index $index)
    {
        if (!is_dir(ZC_DOWNLOAD_DIR)) {
            mkdir(ZC_DOWNLOAD_DIR, 0775, true);
        }
    }

    /**
     * Descarga una imagen. Devuelve ['id','status'=>'downloaded'|'skipped','path','url','primary','size'].
     * status 'skipped' si ya existe (índice o fichero <id>.* en cualquier carpeta de downloads/).
     * $onProgress(int $bytes, int $total) informa del avance del binario (≤ 1 vez/s).
     */
    public function one(int $id, ?callable $onProgress = null): array
    {
        if ($existing = $this->findExisting($id)) {
            $meta = json_decode((string) @file_get_contents(dirname($existing) . "/$id.json"), true);
            return $this->result($id, 'skipped', $existing, is_array($meta) ? $meta : null);
        }

        $item = $this->client->item($id);
        $full = $item['full'] ?? $item['large'] ?? null;
        if (!$full) {
            throw new RuntimeException("La imagen $id no tiene URL full", 404);
        }

        $ext = strtolower(pathinfo(parse_url($full, PHP_URL_PATH) ?? '', PATHINFO_EXTENSION)) ?: 'jpg';
        $dir = ZC_DOWNLOAD_DIR . '/' . self::slug($item['primary'] ?? 'sin-tag');
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $dest = "$dir/$id.$ext";
        $tmp  = "$dest.part";

        // Hasta 2 intentos si el fichero llega corrupto (md5 distinto de `hash`)
        for ($attempt = 1; ; $attempt++) {
            $status = $this->client->downloadFile($full, $tmp, $onProgress);
            if ($status !== 200 || !is_file($tmp) || filesize($tmp) === 0) {
                @unlink($tmp);
                throw new RuntimeException("HTTP $status descargando full de $id", $status ?: 502);
            }
            if (empty($item['hash']) || md5_file($tmp) === $item['hash']) {
                break;
            }
            @unlink($tmp);
            if ($attempt >= 2) {
                Log::error('md5_mismatch', ['id' => $id, 'url' => $full]);
                throw new RuntimeException("md5 no coincide para $id", 502);
            }
            Log::warn('md5_retry', ['id' => $id, 'url' => $full]);
        }
        rename($tmp, $dest);
        chmod($dest, 0644);

        $item['downloaded_at'] = date('c');
        $item['local_file'] = basename($dest);
        file_put_contents("$dir/$id.json", json_encode($item, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $this->index->upsert($item, $dest);

        return $this->result($id, 'downloaded', $dest, $item);
    }

    /**
     * Recoge hasta $max IDs paginando el listado desde $params['p'] y aplicando los filtros locales
     * (ecchi, min). Tope de 30 páginas para no recorrer indefinidamente cuando el filtro deja poco.
     */
    public function collectIds(array $tags, array $params, int $max): array
    {
        $max = max(1, min($max, ZC_BATCH_MAX));
        // Respeta el tamaño de página del que llama para que "empezar en la página N" coincida con lo que ve
        // en la galería; sin él (CLI), páginas grandes para gastar menos peticiones.
        if (empty($params['l'])) {
            $params['l'] = min(100, $max);
        }
        $page = (int) ($params['p'] ?? 1);
        $lastPage = $page + 30;
        $queue = [];
        $seen = [];

        while (count($queue) < $max && $page < $lastPage) {
            $params['p'] = $page++;
            $items = $this->client->search($tags, $params);
            if (!$items) {
                break;
            }
            foreach (ZerochanClient::applyLocalFilters($items, $params) as $it) {
                if (isset($seen[$it['id']])) continue;
                $seen[$it['id']] = true;
                $queue[] = (int) $it['id'];
                if (count($queue) >= $max) break;
            }
        }
        return $queue;
    }

    /**
     * Lote síncrono (CLI --sync). $onProgress(array) se llama tras cada imagen con:
     * done, total, id, status ('downloaded'|'skipped'|'error'), path|null, url|null, error|null.
     */
    public function batch(array $tags, array $params, int $max, callable $onProgress): array
    {
        $queue = $this->collectIds($tags, $params, $max);
        $summary = ['total' => count($queue), 'downloaded' => 0, 'skipped' => 0, 'errors' => 0];
        foreach ($queue as $i => $id) {
            try {
                $r = $this->one($id);
                $summary[$r['status']]++;
                $onProgress(['done' => $i + 1, 'total' => count($queue), 'id' => $id,
                             'status' => $r['status'], 'path' => $r['path'], 'url' => $r['url'], 'error' => null]);
            } catch (Throwable $e) {
                $summary['errors']++;
                $onProgress(['done' => $i + 1, 'total' => count($queue), 'id' => $id,
                             'status' => 'error', 'path' => null, 'url' => null, 'error' => $e->getMessage()]);
            }
        }
        return $summary;
    }

    /** Borra ficheros .part huérfanos (descargas interrumpidas). */
    public static function cleanPartials(): int
    {
        $n = 0;
        foreach (glob(ZC_DOWNLOAD_DIR . '/*/*.part') ?: [] as $f) {
            if (@unlink($f)) $n++;
        }
        return $n;
    }

    public static function slug(string $s): string
    {
        $s = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s) ?: $s;
        $s = strtolower(trim(preg_replace('/[^A-Za-z0-9]+/', '-', $s), '-'));
        return $s !== '' ? $s : 'sin-tag';
    }

    /** Índice primero; si no está pero el fichero existe en disco, lo indexa (auto-cura). */
    private function findExisting(int $id): ?string
    {
        if ($path = $this->index->has($id)) {
            return $path;
        }
        foreach (glob(ZC_DOWNLOAD_DIR . "/*/$id.*") ?: [] as $f) {
            if (!str_ends_with($f, '.json') && !str_ends_with($f, '.part')) {
                $meta = json_decode((string) @file_get_contents(dirname($f) . "/$id.json"), true);
                if (is_array($meta)) {
                    $this->index->upsert($meta, $f);
                }
                return $f;
            }
        }
        return null;
    }

    private function result(int $id, string $status, string $path, ?array $item = null): array
    {
        return [
            'id'      => $id,
            'status'  => $status,
            'path'    => str_replace(dirname(ZC_DOWNLOAD_DIR) . '/', '', $path),
            'url'     => $this->publicUrl($path),
            'primary' => $item['primary'] ?? basename(dirname($path)),
            'size'    => filesize($path),
        ];
    }

    /** URL relativa al proyecto para servir el fichero descargado desde Apache. */
    private function publicUrl(string $path): string
    {
        return 'downloads/' . implode('/', array_map('rawurlencode', explode('/', substr($path, strlen(ZC_DOWNLOAD_DIR) + 1))));
    }
}
