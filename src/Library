<?php
/**
 * Operaciones sobre lo ya descargado: detalle local, borrado y recortes.
 *
 * Los recortes se guardan en downloads/<slug>/crops/<id>-<W>x<H>[-N].<ext>: en una subcarpeta y con prefijo
 * `<id>-` para que los globs de Downloader::findExisting (<slug>/<id>.<ext>) y de Index::rebuild (<slug>/<id>.json)
 * no los confundan con el original. El original nunca se modifica.
 *
 * Todas las rutas se construyen desde la fila del índice (o el glob de disco), nunca desde lo que envíe el cliente;
 * antes de borrar o escribir se comprueba con realpath() que el destino cuelga de ZC_DOWNLOAD_DIR.
 */
class Library
{
    private const CROP_RE   = '/^(\d+)-(\d+)x(\d+)(?:-(\d+))?\.(jpe?g|png|gif|webp)$/i';
    private const FORMATS   = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
    private const MIN_SIDE  = 16;                 // px mínimos por lado del recorte
    private const MAX_PIXELS = 200_000_000;       // originales por encima se rechazan (413)
    private const GD_MAX_BYTES = 200 * 1024 * 1024; // W*H*5 estimado; GD vive dentro de memory_limit
    private const QUALITY   = 92;

    public function __construct(private Index $index) {}

    /** Detalle local: fila del índice + sidecar + recortes. null si no está descargada. */
    public function locate(int $id): ?array
    {
        $path = $this->index->has($id);
        if (!$path) {
            // Mismo auto-curado que Downloader::findExisting: fichero en disco sin fila en el índice.
            foreach (glob(ZC_DOWNLOAD_DIR . "/*/$id.*") ?: [] as $f) {
                if (!str_ends_with($f, '.json') && !str_ends_with($f, '.part')) {
                    $meta = json_decode((string) @file_get_contents(dirname($f) . "/$id.json"), true);
                    if (is_array($meta)) {
                        $this->index->upsert($meta, $f);
                    }
                    $path = $f;
                    break;
                }
            }
        }
        if (!$path) {
            return null;
        }
        $dir  = dirname($path);
        $meta = json_decode((string) @file_get_contents("$dir/$id.json"), true) ?: [];
        return [
            'id'            => $id,
            'primary'       => $meta['primary'] ?? basename($dir),
            'tags'          => $meta['tags'] ?? [],
            'source'        => $meta['source'] ?? null,
            'width'         => (int) ($meta['width'] ?? 0),
            'height'        => (int) ($meta['height'] ?? 0),
            'size'          => filesize($path),
            'ext'           => strtolower(pathinfo($path, PATHINFO_EXTENSION)),
            'url'           => self::publicUrl($path),
            'path'          => str_replace(dirname(ZC_DOWNLOAD_DIR) . '/', '', $path),
            'downloaded_at' => $meta['downloaded_at'] ?? null,
            'crops'         => self::crops($dir, $id),
            '_path'         => $path,   // uso interno; los endpoints lo quitan
        ];
    }

    /** Recortes de una imagen, más recientes primero. Dimensiones parseadas del nombre. */
    public static function crops(string $dir, int $id): array
    {
        $out = [];
        foreach (glob("$dir/crops/$id-*.*") ?: [] as $f) {
            if (!preg_match(self::CROP_RE, basename($f), $m) || $m[1] !== (string) $id) {
                continue;
            }
            $out[] = [
                'name'   => basename($f),
                'url'    => self::publicUrl($f),
                'width'  => (int) $m[2],
                'height' => (int) $m[3],
                'size'   => filesize($f),
                'mtime'  => filemtime($f),
            ];
        }
        usort($out, fn($a, $b) => $b['mtime'] <=> $a['mtime']);
        return $out;
    }

    public static function cropCount(string $folder, int $id): int
    {
        return count(self::crops(ZC_DOWNLOAD_DIR . '/' . $folder, $id));
    }

    /** Borra original, sidecar, recortes y la fila del índice. Bytes liberados, o false si no existe. */
    public function delete(int $id): int|false
    {
        $it = $this->locate($id);
        if (!$it) {
            $this->index->remove($id);
            return false;
        }
        $path = $it['_path'];
        $dir  = dirname($path);
        $freed = 0;
        foreach (self::crops($dir, $id) as $c) {
            $freed += $c['size'];
            self::unlinkSafe("$dir/crops/" . $c['name']);
        }
        $freed += filesize($path);
        self::unlinkSafe($path);
        if (is_file("$dir/$id.json")) {
            $freed += filesize("$dir/$id.json");
            self::unlinkSafe("$dir/$id.json");
        }
        @rmdir("$dir/crops");                                 // solo si quedó vacío
        if (realpath($dir) !== realpath(ZC_DOWNLOAD_DIR)) {
            @rmdir($dir);
        }
        $this->index->remove($id);
        Log::info('delete', ['id' => $id, 'bytes' => $freed]);
        return $freed;
    }

    public function deleteCrop(int $id, string $name): array
    {
        $name = basename($name);
        if (!preg_match(self::CROP_RE, $name, $m) || $m[1] !== (string) $id) {
            throw new RuntimeException('nombre de recorte inválido', 400);
        }
        $it = $this->locate($id);
        if (!$it) {
            throw new RuntimeException('imagen no descargada', 404);
        }
        $dir = dirname($it['_path']);
        $f = "$dir/crops/$name";
        if (!is_file($f)) {
            throw new RuntimeException('recorte no encontrado', 404);
        }
        self::unlinkSafe($f);
        @rmdir("$dir/crops");
        Log::info('crop_delete', ['id' => $id, 'name' => $name]);
        return self::crops($dir, $id);
    }

    /**
     * Recorta el original según un rectángulo normalizado (x, y, w, h en 0..1, relativo a la imagen ya orientada)
     * y guarda crops/<id>-<W>x<H>.<ext>. La relación de aspecto la impone el cliente; aquí solo se recorta.
     */
    public function crop(int $id, array $rect): array
    {
        foreach (['x', 'y', 'w', 'h'] as $k) {
            if (!isset($rect[$k]) || !is_numeric($rect[$k])) {
                throw new RuntimeException('rect inválido', 400);
            }
        }
        $it = $this->locate($id);
        if (!$it) {
            throw new RuntimeException('imagen no descargada', 404);
        }
        $path = $it['_path'];
        $ext  = $it['ext'] === 'jpeg' ? 'jpg' : $it['ext'];
        if (!in_array($ext, self::FORMATS, true)) {
            throw new RuntimeException("formato no soportado: $ext", 415);
        }
        $dir = dirname($path) . '/crops';
        if (!is_dir($dir) && !@mkdir($dir, 0775, true)) {
            throw new RuntimeException('no se pudo crear crops/', 500);
        }

        set_time_limit(120);
        $tmp = "$dir/$id.crop.tmp";
        try {
            if (class_exists('Imagick')) {
                [$pw, $ph] = $this->cropImagick($path, $rect, $ext, $tmp);
            } else {
                [$pw, $ph] = $this->cropGd($path, $rect, $ext, $tmp);
            }
            $dest = "$dir/$id-{$pw}x{$ph}.$ext";
            for ($n = 2; is_file($dest); $n++) {
                $dest = "$dir/$id-{$pw}x{$ph}-$n.$ext";
            }
            self::assertInside($dest);
            rename($tmp, $dest);
            chmod($dest, 0644);
        } catch (Throwable $e) {
            @unlink($tmp);
            $ctx = ['id' => $id, 'error' => $e->getMessage()];
            $e->getCode() >= 400 && $e->getCode() < 500 ? Log::warn('crop_rejected', $ctx) : Log::error('crop_failed', $ctx);
            throw $e;
        }
        Log::info('crop', ['id' => $id, 'file' => basename($dest), 'bytes' => filesize($dest)]);
        return [
            'name'   => basename($dest),
            'url'    => self::publicUrl($dest),
            'width'  => $pw,
            'height' => $ph,
            'size'   => filesize($dest),
            'mtime'  => filemtime($dest),
        ];
    }

    /** Rect normalizado → píxeles, recortado a los bordes de la imagen. */
    private static function pixelRect(array $r, int $W, int $H): array
    {
        $x = min(max((float) $r['x'], 0), 1);
        $y = min(max((float) $r['y'], 0), 1);
        $w = min(max((float) $r['w'], 0), 1 - $x);
        $h = min(max((float) $r['h'], 0), 1 - $y);
        $px = (int) round($x * $W);
        $py = (int) round($y * $H);
        $pw = min((int) round($w * $W), $W - $px);
        $ph = min((int) round($h * $H), $H - $py);
        if ($pw < self::MIN_SIDE || $ph < self::MIN_SIDE) {
            throw new RuntimeException('recorte demasiado pequeño (mínimo ' . self::MIN_SIDE . ' px por lado)', 400);
        }
        return [$px, $py, $pw, $ph];
    }

    /** @return array{int,int} ancho y alto del recorte */
    private function cropImagick(string $path, array $rect, string $ext, string $tmp): array
    {
        // El caché de píxeles de Imagick vive fuera del heap de PHP (memory_limit no lo acota): se limita aquí
        // para que un full de 30 MB no tumbe el contenedor (768 MB para todo el host). Al superar MEMORY pasa a
        // mmap, al superar MAP a disco: más lento pero seguro.
        Imagick::setResourceLimit(Imagick::RESOURCETYPE_MEMORY, 192 * 1024 * 1024);
        Imagick::setResourceLimit(Imagick::RESOURCETYPE_MAP,    384 * 1024 * 1024);
        Imagick::setResourceLimit(Imagick::RESOURCETYPE_DISK,  2048 * 1024 * 1024);
        Imagick::setResourceLimit(Imagick::RESOURCETYPE_AREA,   160 * 1000 * 1000);
        Imagick::setResourceLimit(Imagick::RESOURCETYPE_THREAD, 1);

        $im = new Imagick();
        try {
            $im->pingImage($path);
            if ($im->getImageWidth() * $im->getImageHeight() > self::MAX_PIXELS) {
                throw new RuntimeException('imagen demasiado grande para recortar', 413);
            }
            $im->clear();
            $im->readImage($path . '[0]');          // primer frame en gif/webp animados
            $im->autoOrient();                      // el navegador muestra la imagen orientada; el rect es relativo a eso
            [$px, $py, $pw, $ph] = self::pixelRect($rect, $im->getImageWidth(), $im->getImageHeight());
            $im->cropImage($pw, $ph, $px, $py);
            $im->setImagePage(0, 0, 0, 0);
            $im->profileImage('exif', null);        // la orientación ya está aplicada; se conserva el ICC
            if ($ext === 'jpg') {
                $im->setImageCompression(Imagick::COMPRESSION_JPEG);
                $im->setImageCompressionQuality(self::QUALITY);
            } elseif ($ext === 'webp') {
                $im->setImageCompressionQuality(self::QUALITY);
            }
            $im->setImageFormat($ext === 'jpg' ? 'jpeg' : $ext);
            $im->writeImage($tmp);
        } finally {
            $im->clear();
        }
        return [$pw, $ph];
    }

    /** Fallback sin Imagick. GD decodifica dentro de memory_limit y no auto-orienta (no hay extensión exif). */
    private function cropGd(string $path, array $rect, string $ext, string $tmp): array
    {
        $info = @getimagesize($path);
        if (!$info) {
            throw new RuntimeException('no se pudo leer la imagen', 500);
        }
        [$W, $H] = $info;
        if ($W * $H * 5 > self::GD_MAX_BYTES) {
            throw new RuntimeException('imagen demasiado grande para recortar sin Imagick', 413);
        }
        [$px, $py, $pw, $ph] = self::pixelRect($rect, $W, $H);
        $src = match ($ext) {
            'jpg'  => imagecreatefromjpeg($path),
            'png'  => imagecreatefrompng($path),
            'gif'  => imagecreatefromgif($path),
            'webp' => imagecreatefromwebp($path),
        };
        if (!$src) {
            throw new RuntimeException('GD no pudo decodificar la imagen', 500);
        }
        $dst = imagecrop($src, ['x' => $px, 'y' => $py, 'width' => $pw, 'height' => $ph]);
        imagedestroy($src);
        if (!$dst) {
            throw new RuntimeException('GD no pudo recortar', 500);
        }
        $ok = match ($ext) {
            'jpg'  => imagejpeg($dst, $tmp, self::QUALITY),
            'png'  => (imagesavealpha($dst, true) && imagepng($dst, $tmp)),
            'gif'  => imagegif($dst, $tmp),
            'webp' => imagewebp($dst, $tmp, self::QUALITY),
        };
        imagedestroy($dst);
        if (!$ok) {
            throw new RuntimeException('GD no pudo escribir el recorte', 500);
        }
        return [$pw, $ph];
    }

    private static function assertInside(string $path): void
    {
        $base = realpath(ZC_DOWNLOAD_DIR);
        $real = realpath(dirname($path));
        if ($base === false || $real === false || !str_starts_with($real . '/', $base . '/')) {
            throw new RuntimeException('ruta fuera de downloads/', 400);
        }
    }

    private static function unlinkSafe(string $path): void
    {
        self::assertInside($path);
        @unlink($path);
    }

    /** URL relativa al proyecto (mismo formato que Downloader::publicUrl / Index::list). */
    private static function publicUrl(string $path): string
    {
        return 'downloads/' . implode('/', array_map('rawurlencode', explode('/', substr($path, strlen(ZC_DOWNLOAD_DIR) + 1))));
    }
}
