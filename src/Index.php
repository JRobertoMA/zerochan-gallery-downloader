<?php
/**
 * Índice SQLite de lo descargado (data/index.sqlite): permite listar/buscar sin releer los .json,
 * marcar "ya descargada" en el listado de la API y detectar duplicados por md5.
 * Se reconstruye desde los <id>.json de downloads/ (rebuild) y se auto-inicializa si está vacío.
 */
class Index
{
    public const SORTS = ['date', 'size', 'id'];
    private PDO $db;

    public function __construct()
    {
        $fresh = !is_file(ZC_INDEX_DB);
        $this->db = new PDO('sqlite:' . ZC_INDEX_DB, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $this->db->exec('PRAGMA journal_mode=WAL');
        $this->db->exec('PRAGMA busy_timeout=5000');
        $this->db->exec('CREATE TABLE IF NOT EXISTS images (
            id INTEGER PRIMARY KEY, md5 TEXT, primary_tag TEXT, folder TEXT, file TEXT,
            width INT, height INT, size INT, source TEXT, children INT DEFAULT 0, downloaded_at TEXT)');
        $this->db->exec('CREATE INDEX IF NOT EXISTS images_md5 ON images(md5)');
        $this->db->exec('CREATE INDEX IF NOT EXISTS images_date ON images(downloaded_at)');
        $this->db->exec('CREATE TABLE IF NOT EXISTS image_tags (image_id INTEGER, tag TEXT, PRIMARY KEY(image_id, tag))');
        $this->db->exec('CREATE INDEX IF NOT EXISTS image_tags_tag ON image_tags(tag)');

        // Primer arranque con descargas previas: indexa lo que ya hay en disco
        if ($fresh || (int) $this->db->query('SELECT COUNT(*) FROM images')->fetchColumn() === 0) {
            if (glob(ZC_DOWNLOAD_DIR . '/*/*.json')) {
                $this->rebuild();
            }
        }
    }

    /** Inserta/actualiza una imagen a partir del detalle de la API y la ruta local del fichero. */
    public function upsert(array $item, string $path): void
    {
        $this->db->beginTransaction();
        try {
            $st = $this->db->prepare('INSERT OR REPLACE INTO images
                (id, md5, primary_tag, folder, file, width, height, size, source, children, downloaded_at)
                VALUES (:id, :md5, :primary, :folder, :file, :w, :h, :size, :source, :children, :at)');
            $st->execute([
                ':id' => (int) $item['id'],
                ':md5' => $item['hash'] ?? $item['md5'] ?? null,
                ':primary' => $item['primary'] ?? $item['tag'] ?? '',
                ':folder' => basename(dirname($path)),
                ':file' => basename($path),
                ':w' => (int) ($item['width'] ?? 0),
                ':h' => (int) ($item['height'] ?? 0),
                ':size' => (int) ($item['size'] ?? (is_file($path) ? filesize($path) : 0)),
                ':source' => $item['source'] ?? null,
                ':children' => (int) ($item['children'] ?? 0),
                ':at' => $item['downloaded_at'] ?? date('c'),
            ]);
            $this->db->prepare('DELETE FROM image_tags WHERE image_id = ?')->execute([(int) $item['id']]);
            $ins = $this->db->prepare('INSERT OR IGNORE INTO image_tags (image_id, tag) VALUES (?, ?)');
            foreach ($item['tags'] ?? [] as $tag) {
                $ins->execute([(int) $item['id'], (string) $tag]);
            }
            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /** Ruta absoluta del fichero si el id está indexado y existe en disco; null si no. */
    public function has(int $id): ?string
    {
        $st = $this->db->prepare('SELECT folder, file FROM images WHERE id = ?');
        $st->execute([$id]);
        $row = $st->fetch();
        if (!$row) {
            return null;
        }
        $path = ZC_DOWNLOAD_DIR . '/' . $row['folder'] . '/' . $row['file'];
        if (!is_file($path)) {
            $this->remove($id);   // fichero borrado a mano: limpia el índice
            return null;
        }
        return $path;
    }

    public function remove(int $id): void
    {
        $this->db->prepare('DELETE FROM image_tags WHERE image_id = ?')->execute([$id]);
        $this->db->prepare('DELETE FROM images WHERE id = ?')->execute([$id]);
    }

    /** Subconjunto de $ids que ya está descargado. */
    public function savedIds(array $ids): array
    {
        return array_map('intval', array_column($this->whereIn('SELECT id FROM images WHERE id IN (%s)', $ids), 'id'));
    }

    /** md5 → id de la imagen ya descargada con ese hash. */
    public function md5Owners(array $md5s): array
    {
        $out = [];
        foreach ($this->whereIn('SELECT md5, id FROM images WHERE md5 IN (%s)', $md5s) as $r) {
            $out[$r['md5']] = (int) $r['id'];
        }
        return $out;
    }

    /**
     * Lista paginada. $f: tag, q (LIKE en primary_tag o tags), sort (date|size|id), p, l.
     * Devuelve ['items'=>[], 'count'=>N (del filtro), 'page', 'limit'].
     */
    public function list(array $f = []): array
    {
        $where = [];
        $args = [];
        if (!empty($f['tag'])) {
            $where[] = 'i.id IN (SELECT image_id FROM image_tags WHERE tag = :tag)';
            $args[':tag'] = $f['tag'];
        }
        if (!empty($f['q'])) {
            $where[] = '(i.primary_tag LIKE :q OR i.id IN (SELECT image_id FROM image_tags WHERE tag LIKE :q2))';
            $args[':q'] = '%' . $f['q'] . '%';
            $args[':q2'] = '%' . $f['q'] . '%';
        }
        $sql = $where ? ' WHERE ' . implode(' AND ', $where) : '';
        $order = match ($f['sort'] ?? 'date') {
            'size' => 'i.size DESC',
            'id'   => 'i.id DESC',
            default => 'i.downloaded_at DESC',
        };
        $page = max(1, (int) ($f['p'] ?? 1));
        $limit = max(1, min(500, (int) ($f['l'] ?? 60)));

        $st = $this->db->prepare("SELECT COUNT(*) FROM images i$sql");
        $st->execute($args);
        $count = (int) $st->fetchColumn();

        $st = $this->db->prepare("SELECT i.* FROM images i$sql ORDER BY $order LIMIT :lim OFFSET :off");
        foreach ($args as $k => $v) $st->bindValue($k, $v);
        $st->bindValue(':lim', $limit, PDO::PARAM_INT);
        $st->bindValue(':off', ($page - 1) * $limit, PDO::PARAM_INT);
        $st->execute();
        $items = $st->fetchAll();

        // Tags de los items de esta página en una sola consulta
        $byId = [];
        foreach ($items as &$it) {
            $it['id'] = (int) $it['id'];
            $it['tags'] = [];
            $it['url'] = 'downloads/' . rawurlencode($it['folder']) . '/' . rawurlencode($it['file']);
            $it['primary'] = $it['primary_tag'];
            $byId[$it['id']] = &$it;
        }
        unset($it);
        foreach ($this->whereIn('SELECT image_id, tag FROM image_tags WHERE image_id IN (%s)', array_keys($byId)) as $r) {
            $byId[(int) $r['image_id']]['tags'][] = $r['tag'];
        }

        return ['items' => $items, 'count' => $count, 'page' => $page, 'limit' => $limit];
    }

    /** Tags más frecuentes (opcionalmente dentro de un filtro de tag). [['tag','n'],...] */
    public function facetTags(int $limit = 30, ?string $withinTag = null): array
    {
        if ($withinTag) {
            $st = $this->db->prepare('SELECT tag, COUNT(*) n FROM image_tags WHERE tag != :t
                AND image_id IN (SELECT image_id FROM image_tags WHERE tag = :t2) GROUP BY tag ORDER BY n DESC LIMIT :l');
            $st->bindValue(':t', $withinTag);
            $st->bindValue(':t2', $withinTag);
        } else {
            $st = $this->db->prepare('SELECT tag, COUNT(*) n FROM image_tags GROUP BY tag ORDER BY n DESC LIMIT :l');
        }
        $st->bindValue(':l', $limit, PDO::PARAM_INT);
        $st->execute();
        return array_map(fn($r) => ['tag' => $r['tag'], 'n' => (int) $r['n']], $st->fetchAll());
    }

    public function stats(): array
    {
        $r = $this->db->query('SELECT COUNT(*) c, COALESCE(SUM(size),0) b FROM images')->fetch();
        return ['count' => (int) $r['c'], 'bytes' => (int) $r['b']];
    }

    /** Reconstruye el índice desde los <id>.json de downloads/. Devuelve cuántas imágenes indexó. */
    public function rebuild(): int
    {
        $this->db->exec('DELETE FROM image_tags');
        $this->db->exec('DELETE FROM images');
        $n = 0;
        foreach (glob(ZC_DOWNLOAD_DIR . '/*/*.json') ?: [] as $json) {
            $meta = json_decode((string) file_get_contents($json), true);
            if (!is_array($meta) || empty($meta['local_file']) || empty($meta['id'])) continue;
            $file = dirname($json) . '/' . $meta['local_file'];
            if (!is_file($file)) continue;
            $meta['size'] = $meta['size'] ?? filesize($file);
            $this->upsert($meta, $file);
            $n++;
        }
        return $n;
    }

    private function whereIn(string $sqlTpl, array $values): array
    {
        $values = array_values(array_unique(array_filter($values, fn($v) => $v !== null && $v !== '')));
        if (!$values) {
            return [];
        }
        $st = $this->db->prepare(sprintf($sqlTpl, implode(',', array_fill(0, count($values), '?'))));
        $st->execute($values);
        return $st->fetchAll();
    }
}
