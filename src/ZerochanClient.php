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

    /** Indica si la última llamada a get() hubo que repararla (ver repairJson). */
    public bool $lastRepaired = false;

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

    /**
     * Sugerencias de tags para un prefijo. Endpoint no documentado (/suggest?q=, lo usa el buscador de zerochan.net):
     * texto plano, una línea `Tag|Tipo|Padre` por sugerencia, `-` cuando no hay padre. Caché propia de ZC_SUGGEST_TTL.
     * Devuelve [['tag','type','parent'], ...].
     */
    public function suggest(string $q): array
    {
        $cacheFile = ZC_CACHE_DIR . '/suggest-' . md5(mb_strtolower($q)) . '.json';
        if (is_file($cacheFile) && filemtime($cacheFile) > time() - ZC_SUGGEST_TTL) {
            $cached = json_decode((string) file_get_contents($cacheFile), true);
            if (is_array($cached)) {
                $this->lastFromCache = true;
                return $cached;
            }
        }
        $this->lastFromCache = false;

        $url = ZC_BASE_URL . '/suggest?q=' . rawurlencode($q);
        [$body, $status] = $this->withRetries($url, fn() => $this->curlJson($url));
        if ($status !== 200) {
            throw new RuntimeException("Zerochan respondió HTTP $status", $status >= 300 && $status < 400 ? 404 : $status);
        }
        $items = [];
        foreach (explode("\n", (string) $body) as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '<')) {   // una página HTML aquí significaría error/redirección
                continue;
            }
            [$tag, $type, $parent] = array_pad(explode('|', $line, 3), 3, '');
            $items[] = ['tag' => trim($tag), 'type' => trim($type), 'parent' => trim($parent) === '-' || trim($parent) === '' ? null : trim($parent)];
            if (count($items) >= 15) {
                break;
            }
        }
        file_put_contents($cacheFile, json_encode($items, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
        return $items;
    }

    /** ¿El cuerpo parece JSON (y no una página de error HTML)? Decide si un 5xx merece reintento. */
    private static function looksLikeJson(string $body): bool
    {
        $t = ltrim($body);
        return $t !== '' && ($t[0] === '{' || $t[0] === '[');
    }

    /**
     * Primer objeto JSON equilibrado a partir del `{` en $from, respetando cadenas y escapes
     * (el cuerpo roto trae HTML con llaves que no deben contarse). null si nunca cierra.
     */
    private static function balanced(string $s, int $from): ?string
    {
        $depth = 0;
        $inStr = false;
        $esc = false;
        for ($i = $from, $len = strlen($s); $i < $len; $i++) {
            $c = $s[$i];
            if ($inStr) {
                if ($esc)            { $esc = false; }
                elseif ($c === '\\') { $esc = true; }
                elseif ($c === '"')  { $inStr = false; }
                continue;
            }
            if ($c === '"') { $inStr = true; }
            elseif ($c === '{') { $depth++; }
            elseif ($c === '}' && --$depth === 0) { return substr($s, $from, $i - $from + 1); }
        }
        return null;
    }

    /**
     * WORKAROUND (2026-10-13): Zerochan sirve los listados ?json con su plantilla rota —
     * `{\r\n` + el JSON real `{"items":[…]}` + HTML de la galería + `}\r\n` — y con el estado HTTP
     * oscilando entre 200 y 500. Extrae el JSON real probando los tres primeros `{` del cuerpo.
     * Devuelve null si no hay nada aprovechable (entonces el error se propaga como siempre).
     * Ver CLAUDE.md, «Workaround activo», para cómo comprobar si ya se puede retirar.
     */
    private static function repairJson(string $body): ?array
    {
        for ($i = 0, $tried = 0, $len = strlen($body); $i < $len && $tried < 3; $i++) {
            if ($body[$i] !== '{') {
                continue;
            }
            $tried++;
            $json = self::balanced($body, $i);
            if ($json === null) {
                continue;
            }
            $data = json_decode($json, true);
            // Un payload de error legítimo no se hace pasar por datos
            if (is_array($data) && $data !== [] && !isset($data['error'])) {
                return $data;
            }
        }
        return null;
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
                $this->lastRepaired = false;
                return $cached;
            }
        }
        $this->lastFromCache = false;
        $this->lastRepaired = false;

        // No seguimos redirecciones: Zerochan responde 302 a "/" para tags restringidos o meta tags,
        // y seguirla devolvería el HTML de la portada.
        [$body, $status] = $this->withRetries($url, fn() => $this->curlJson($url));

        if ($status >= 300 && $status < 400) {
            throw new RuntimeException('Zerochan redirige: tag restringido, meta tag (p.ej. Ecchi) o inexistente', 404);
        }
        // WORKAROUND (2026-10-13): un 5xx que aun así trae JSON utilizable se aprovecha (ver repairJson)
        $brokenButUsable = ZC_TOLERATE_BROKEN_JSON && $status >= 500 && self::looksLikeJson((string) $body);
        if ($status !== 200 && !$brokenButUsable) {
            throw new RuntimeException("Zerochan respondió HTTP $status", $status);
        }

        $data = json_decode((string) $body, true);
        if (!is_array($data) && ZC_TOLERATE_BROKEN_JSON) {
            $data = self::repairJson((string) $body);
            if (is_array($data)) {
                $this->lastRepaired = true;
                Log::warn('json_repaired', ['url' => $url, 'status' => $status, 'items' => count($data['items'] ?? [])]);
            }
        }
        if (!is_array($data)) {
            Log::error('invalid_json', ['url' => $url, 'status' => $status, 'head' => substr((string) $body, 0, 80)]);
            throw new RuntimeException('Zerochan devolvió un JSON inválido', 502);
        }
        if ($brokenButUsable && !$this->lastRepaired) {
            Log::warn('json_http_error_ignored', ['url' => $url, 'status' => $status]);
        }

        // Se cachea el JSON ya saneado, no el cuerpo crudo: si no, el siguiente HIT volvería a fallar
        file_put_contents($cacheFile, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
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
            // Un 5xx con cuerpo JSON no se reintenta: Zerochan lo manda roto pero utilizable (ver repairJson),
            // y reintentar solo gastaría 3 peticiones del rate limit y ~7 s de espera por búsqueda.
            $retryable = $body === false
                || ($status >= 500 && !(ZC_TOLERATE_BROKEN_JSON && self::looksLikeJson((string) $body)));
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
