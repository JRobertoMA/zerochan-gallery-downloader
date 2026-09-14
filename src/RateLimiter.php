<?php
/**
 * Limitador de peticiones compartido entre web y CLI.
 * Guarda los timestamps del último minuto en un fichero con flock; si se alcanza el límite,
 * duerme hasta que expire la petición más antigua.
 */
class RateLimiter
{
    private string $file;

    public function __construct(
        private int $limit,
        private int $windowSeconds = 60,
        ?string $file = null,
    ) {
        $this->file = $file ?? ZC_CACHE_DIR . '/ratelimit.json';
    }

    /** Bloquea hasta que haya hueco y registra la petición. */
    public function acquire(): void
    {
        $fh = fopen($this->file, 'c+');
        if ($fh === false) {
            throw new RuntimeException("No se puede abrir {$this->file}");
        }

        while (true) {
            flock($fh, LOCK_EX);
            $stamps = $this->read($fh);
            $now = microtime(true);
            $stamps = array_values(array_filter($stamps, fn($t) => $t > $now - $this->windowSeconds));

            if (count($stamps) < $this->limit) {
                $stamps[] = $now;
                $this->write($fh, $stamps);
                flock($fh, LOCK_UN);
                fclose($fh);
                return;
            }

            $wait = ($stamps[0] + $this->windowSeconds) - $now;
            flock($fh, LOCK_UN);
            usleep((int) (max(0.05, $wait) * 1_000_000) + 50_000);
        }
    }

    /** @return float[] */
    private function read($fh): array
    {
        rewind($fh);
        $raw = stream_get_contents($fh);
        $data = $raw !== '' ? json_decode($raw, true) : null;
        return is_array($data) ? array_map('floatval', $data) : [];
    }

    private function write($fh, array $stamps): void
    {
        ftruncate($fh, 0);
        rewind($fh);
        fwrite($fh, json_encode($stamps));
        fflush($fh);
    }
}
