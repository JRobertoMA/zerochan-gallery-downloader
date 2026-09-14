<?php
/**
 * Cola de lotes de descarga: un fichero JSON por job en data/jobs/<id>.json.
 * Los procesa cli/worker.php (un único proceso, fuera de Apache). Escrituras atómicas (tmp + rename).
 */
class Jobs
{
    public const ACTIVE = ['queued', 'collecting', 'running'];
    public const LOCK_FILE = ZC_DATA_DIR . '/worker.lock';

    public function __construct()
    {
        if (!is_dir(ZC_JOBS_DIR)) {
            mkdir(ZC_JOBS_DIR, 0775, true);
        }
    }

    /** Crea un job en cola. $queue permite fijar los IDs directamente (reintentos). */
    public function create(array $tags, array $params, int $max, array $queue = []): array
    {
        $job = [
            'id'         => date('Ymd-His') . '-' . substr(uniqid(), -4),
            'created_at' => date('c'),
            'updated_at' => date('c'),
            'tags'       => array_values($tags),
            'params'     => $params,
            'max'        => max(1, min(ZC_BATCH_MAX, $max)),
            'status'     => 'queued',
            'queue'      => array_values($queue),
            'cursor'     => 0,
            'total'      => count($queue),
            'downloaded' => 0,
            'skipped'    => 0,
            'errors'     => 0,
            'failed'     => [],
            'current'    => null,
            'cancel'     => false,
            'error'      => null,
            'pid'        => null,
        ];
        $this->save($job);
        Log::info('job_created', ['job' => $job['id'], 'tags' => $tags, 'max' => $max, 'queued_ids' => count($queue)]);
        return $job;
    }

    public function get(string $id): ?array
    {
        $file = $this->file($id);
        if (!$file || !is_file($file)) {
            return null;
        }
        $job = json_decode((string) file_get_contents($file), true);
        return is_array($job) ? $job : null;
    }

    /** Todos los jobs, más antiguos primero. */
    public function all(): array
    {
        $jobs = [];
        foreach (glob(ZC_JOBS_DIR . '/*.json') ?: [] as $f) {
            $j = json_decode((string) file_get_contents($f), true);
            if (is_array($j)) $jobs[] = $j;
        }
        usort($jobs, fn($a, $b) => strcmp($a['created_at'], $b['created_at']));
        return $jobs;
    }

    /** Siguiente job a procesar: primero los que estaban en marcha (reanudación), luego los en cola. */
    public function next(): ?array
    {
        $pending = array_filter($this->all(), fn($j) => in_array($j['status'], self::ACTIVE, true));
        usort($pending, fn($a, $b) => ($a['status'] === 'queued') <=> ($b['status'] === 'queued'));
        return $pending ? array_values($pending)[0] : null;
    }

    public function save(array $job): void
    {
        $job['updated_at'] = date('c');
        $file = $this->file($job['id']);
        // El worker guarda su copia en memoria; no debe pisar una cancelación pedida desde la API
        if (empty($job['cancel']) && is_file($file)) {
            $onDisk = json_decode((string) file_get_contents($file), true);
            if (!empty($onDisk['cancel'])) {
                $job['cancel'] = true;
            }
        }
        $tmp = $file . '.tmp';
        file_put_contents($tmp, json_encode($job, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        rename($tmp, $file);
    }

    /** Marca la cancelación; el worker la aplica tras la imagen en curso. */
    public function cancel(string $id): ?array
    {
        $job = $this->get($id);
        if (!$job) return null;
        if (in_array($job['status'], self::ACTIVE, true)) {
            $job['cancel'] = true;
            if ($job['status'] === 'queued') {
                $job['status'] = 'cancelled';
            }
            $this->save($job);
            Log::info('job_cancel', ['job' => $id]);
        }
        return $job;
    }

    /** Nuevo job con solo los IDs fallidos del job dado. */
    public function retry(string $id): ?array
    {
        $job = $this->get($id);
        if (!$job || !$job['failed']) return null;
        return $this->create($job['tags'], $job['params'], count($job['failed']), array_column($job['failed'], 'id'));
    }

    /** Borra los jobs terminados (done/cancelled/error). */
    public function clearFinished(): int
    {
        $n = 0;
        foreach ($this->all() as $j) {
            if (!in_array($j['status'], self::ACTIVE, true) && @unlink($this->file($j['id']))) $n++;
        }
        return $n;
    }

    /** Resumen ligero para listados (sin la cola de IDs ni fallos). */
    public static function summary(array $job): array
    {
        unset($job['queue'], $job['failed']);
        return $job;
    }

    /** true si hay un worker con el lock cogido. */
    public function workerRunning(): bool
    {
        $fh = @fopen(self::LOCK_FILE, 'c');
        if (!$fh) return false;
        $free = flock($fh, LOCK_EX | LOCK_NB);
        if ($free) flock($fh, LOCK_UN);
        fclose($fh);
        return !$free;
    }

    /** Lanza cli/worker.php desacoplado de la petición si no hay uno corriendo. */
    public function spawnWorker(): bool
    {
        if ($this->workerRunning()) {
            return false;
        }
        $cmd = sprintf('setsid nohup %s %s >> %s 2>&1 &',
            escapeshellarg(ZC_PHP_BIN),
            escapeshellarg(dirname(__DIR__) . '/cli/worker.php'),
            escapeshellarg(ZC_LOG_DIR . '/worker.log'));
        exec($cmd);
        Log::info('worker_spawn', ['cmd' => $cmd]);
        return true;
    }

    private function file(string $id): ?string
    {
        return preg_match('/^[0-9]{8}-[0-9]{6}-[a-z0-9]{4}$/', $id) ? ZC_JOBS_DIR . "/$id.json" : null;
    }
}
