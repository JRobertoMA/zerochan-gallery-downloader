<?php
/**
 * Cliente de la API pública de Zerochan (https://www.zerochan.net/api).
 * Solo GET; respuestas JSON cacheadas en fichero; todas las llamadas pasan por el RateLimiter.
 */
class ZerochanClient
{
    public const SORTS  = ['id', 'fav'];
    public const TIMES  = ['0', '1', '2'];
    public const DIMS   = ['large', 'huge', 'landscape', 'portrait', 'square'];
    public const COLORS = ['black', 'blue', 'brown', 'green', 'pink', 'purple', 'red', 'white', 'yellow', 'orange', 'gray'];
    /** Filtro local de contenido sugerente. 'Ecchi' es meta tag: la API no lo filtra (302), solo aparece en `tags`. */
    public const ECCHI_MODES = ['all', 'hide', 'only'];

    /** Indica si la última llamada a get() salió de caché. */
    public bool $lastFromCache = false;

    public function __construct(private RateLimiter $limiter)
    {
        if (!is_dir(ZC_CACHE_DIR)) {
            mkdir(ZC_CACHE_DIR, 0755, true);
        }
    }

    /**
     * Construye la URL de listado. $tags es un array de nombres de tag (con espacios).
     * $params admite: p, l, s, t, d, c, strict.
     */
    public function buildSearchUrl(array $tags, array $params = []): string
    {
        $tags = array_values(array_filter(array_map('trim', $tags), fn($t) => $t !== ''));
        $path = $tags
            ? '/' . implode(',', array_map(fn($t) => str_replace('%20', '+', rawurlencode($t)), $tags))
            : '/';

        $query = ['json'];
        foreach (['p', 'l', 's', 't', 'd', 'c'] as $k) {
            if (isset($params[$k]) && $params[$k] !== '' && $params[$k] !== null) {
                $query[] = $k . '=' . rawurlencode((string) $params[$k]);
            }
        }
        if (!empty($params['strict'])) {
            $query[] = 'strict';
        }
        return ZC_BASE_URL . $path . '?' . implode('&', $query);
    }

    /** Cookie de sesión opcional (ZEROCHAN_COOKIE en .env) para ver lo que Zerochan oculta a invitados. */
    private static function cookieOpt(): array
    {
        return ZC_COOKIE !== '' ? [CURLOPT_COOKIE => ZC_COOKIE] : [];
    }

    /** Filtra items por presencia del tag Ecchi según $mode ('all' | 'hide' | 'only'). */
    public static function filterEcchi(array $items, string $mode): array
    {
        if ($mode === 'all') {
            return $items;
        }
        $want = $mode === 'only';
        return array_values(array_filter($items, fn($it) => self::isEcchi($it) === $want));
    }

    public static function isEcchi(array $item): bool
    {
        return in_array('Ecchi', $item['tags'] ?? [], true);
    }

    /** Filtro local de resolución: lado corto >= $min px (0 = sin filtro). */
    public static function filterMin(array $items, int $min): array
    {
        if ($min <= 0) {
            return $items;
        }
        return array_values(array_filter($items, fn($it) => min((int) ($it['width'] ?? 0), (int) ($it['height'] ?? 0)) >= $min));
    }

    /** Aplica todos los filtros locales ('ecchi', 'min') de $params a un listado. */
    public static function applyLocalFilters(array $items, array $params): array
    {
        $items = self::filterEcchi($items, $params['ecchi'] ?? 'all');
        return self::filterMin($items, (int) ($params['min'] ?? 0));
    }

    /** Listado: devuelve el array 'items' (cada uno con id, width, height, md5, thumbnail, source, tag, tags). */
    public function search(array $tags, array $params = []): array
    {
        $data = $this->get($this->buildSearchUrl($tags, $params));
        return $data['items'] ?? [];
    }

    /** Detalle de una imagen: id, small, medium, large, full, width, height, size, hash, source, primary, tags. */
    public function item(int $id): array
    {
        $data = $this->get(ZC_BASE_URL . '/' . $id . '?json');
        if (empty($data['id'])) {
            throw new RuntimeException("Zerochan no devolvió la imagen $id", 404);
        }
        return $data;
    }

    /** GET JSON con caché en disco. Lanza RuntimeException(code = HTTP status) si falla. */
    public function get(string $url): array
    {
        // Con sesión los listados incluyen entradas ocultas a invitados: caché separada
        $cacheFile = ZC_CACHE_DIR . '/' . md5($url . (ZC_COOKIE !== '' ? '|auth' : '')) . '.json';
        if (is_file($cacheFile) && filemtime($cacheFile) > time() - ZC_CACHE_TTL) {
            $cached = json_decode((string) file_get_contents($cacheFile), true);
            if (is_array($cached)) {
                $this->lastFromCache = true;
                return $cached;
            }
        }
        $this->lastFromCache = false;

        // No seguimos redirecciones: Zerochan responde 302 a "/" para tags restringidos o meta tags,
        // y seguirla devolvería el HTML de la portada.
        [$body, $status] = $this->withRetries($url, fn() => $this->curlJson($url));

        if ($status >= 300 && $status < 400) {
            throw new RuntimeException('Zerochan redirige: tag restringido, meta tag (p.ej. Ecchi) o inexistente', 404);
        }
        if ($status !== 200) {
            throw new RuntimeException("Zerochan respondió HTTP $status", $status);
        }
        $data = json_decode($body, true);
        if (!is_array($data)) {
            Log::error('invalid_json', ['url' => $url, 'head' => substr($body, 0, 80)]);
            throw new RuntimeException('Zerochan devolvió un JSON inválido', 502);
        }

        file_put_contents($cacheFile, $body, LOCK_EX);
        return $data;
    }

    /**
     * Descarga un binario a $dest respetando el rate limit. Devuelve el status HTTP.
     * $onProgress(int $bytes, int $total) se llama como máximo una vez por segundo.
     */
    public function downloadFile(string $url, string $dest, ?callable $onProgress = null): int
    {
        [, $status] = $this->withRetries($url, function () use ($url, $dest, $onProgress) {
            $fh = fopen($dest, 'wb');
            if ($fh === false) {
                throw new RuntimeException("No se puede escribir en $dest");
            }
            $ch = curl_init($url);
            $opts = [
                CURLOPT_FILE           => $fh,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS      => 3,
                CURLOPT_TIMEOUT         => ZC_DL_TIMEOUT,
                CURLOPT_LOW_SPEED_LIMIT => ZC_DL_MIN_SPEED,
                CURLOPT_LOW_SPEED_TIME  => ZC_DL_STALL_SECS,
                CURLOPT_USERAGENT       => ZC_USER_AGENT,
            ] + self::cookieOpt();
            if ($onProgress) {
                $last = 0.0;
                $opts[CURLOPT_NOPROGRESS] = false;
                $opts[CURLOPT_PROGRESSFUNCTION] = function ($ch, $dlTotal, $dlNow) use ($onProgress, &$last) {
                    $now = microtime(true);
                    if ($now - $last >= 1.0 && $dlNow > 0) {
                        $last = $now;
                        $onProgress((int) $dlNow, (int) $dlTotal);
                    }
                    return 0;
                };
            }
            curl_setopt_array($ch, $opts);
            $ok = curl_exec($ch);
            $st = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $err = curl_error($ch);
            fclose($fh);
            if ($ok === false) {
                @unlink($dest);
                return [false, 0, $err];
            }
            return ['', $st, ''];
        });
        return $status;
    }

    /** Borra respuestas cacheadas con más de 6×TTL. Devuelve cuántas eliminó. */
    public static function pruneCache(): int
    {
        $n = 0;
        $limit = time() - ZC_CACHE_TTL * 6;
        foreach (glob(ZC_CACHE_DIR . '/*.json') ?: [] as $f) {
            if (basename($f) !== 'ratelimit.json' && filemtime($f) < $limit && @unlink($f)) {
                $n++;
            }
        }
        return $n;
    }

    /** GET JSON simple. Devuelve [body|false, status, curlError]. */
    private function curlJson(string $url): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT        => ZC_HTTP_TIMEOUT,
            CURLOPT_USERAGENT      => ZC_USER_AGENT,
            CURLOPT_HTTPHEADER     => ['Accept: application/json'],
        ] + self::cookieOpt());
        $body = curl_exec($ch);
        return [$body, (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE), curl_error($ch)];
    }

    /**
     * Ejecuta $fn (que devuelve [body|false, status, err]) hasta ZC_RETRIES veces con backoff
     * ante error de red o 5xx. 4xx y 3xx no se reintentan. Cada intento pasa por el rate limiter.
     * Devuelve [body, status].
     */
    private function withRetries(string $url, callable $fn): array
    {
        $backoff = [2, 5, 10];
        for ($attempt = 1; ; $attempt++) {
            $this->limiter->acquire();
            [$body, $status, $err] = $fn();
            $retryable = $body === false || $status >= 500;
            if (!$retryable) {
                return [$body, $status];
            }
            $ctx = ['url' => $url, 'status' => $status, 'error' => $err, 'attempt' => $attempt];
            if ($attempt >= ZC_RETRIES) {
                Log::error('http_failed', $ctx);
                throw new RuntimeException(
                    $body === false ? "Error de red hacia Zerochan: $err" : "Zerochan respondió HTTP $status",
                    $status >= 500 ? $status : 502
                );
            }
            Log::warn('http_retry', $ctx);
            sleep($backoff[$attempt - 1] ?? 10);
        }
    }
}
