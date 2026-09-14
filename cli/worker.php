#!/usr/bin/env php
<?php
/**
 * Worker de la cola de descargas. Un único proceso a la vez (flock en data/worker.lock).
 * Lo lanza api/jobs.php (o cli/download.php) al crear un job; también se puede invocar por cron
 * como red de seguridad. Procesa todos los jobs pendientes y termina.
 *
 *   docker exec -u www-data php-apache php /var/www/html/zerochan/cli/worker.php
 */
if (PHP_SAPI !== 'cli') {
    exit("Solo CLI\n");
}
require __DIR__ . '/../config.php';
set_time_limit(0);

$lock = fopen(Jobs::LOCK_FILE, 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
    echo "Ya hay un worker en marcha\n";
    exit(0);
}

$jobs       = new Jobs();
$client     = new ZerochanClient(new RateLimiter(ZC_RATE_LIMIT));
$downloader = new Downloader($client, new Index());

Log::info('worker_start', ['pid' => getmypid()]);
Downloader::cleanPartials();
ZerochanClient::pruneCache();

while ($job = $jobs->next()) {
    $job['pid'] = getmypid();
    echo "[{$job['id']}] " . implode(', ', $job['tags']) . " (max {$job['max']})\n";

    try {
        if (!$job['queue']) {
            $job['status'] = 'collecting';
            $jobs->save($job);
            $job['queue'] = $downloader->collectIds($job['tags'], $job['params'], $job['max']);
            $job['total'] = count($job['queue']);
        }
        $job['status'] = 'running';
        $jobs->save($job);

        for ($i = $job['cursor']; $i < $job['total']; $i++) {
            // El flag de cancelación lo pone api/jobs.php en el fichero; save() lo conserva en nuestra copia
            $fresh = $jobs->get($job['id']);
            if ($fresh === null || !empty($fresh['cancel'])) {
                $job['cancel'] = true;
                $job['status'] = 'cancelled';
                break;
            }

            $id = (int) $job['queue'][$i];
            $job['current'] = ['id' => $id, 'bytes' => 0, 'total' => 0];
            $jobs->save($job);
            try {
                $r = $downloader->one($id, function (int $bytes, int $total) use (&$job, $jobs) {
                    $job['current'] = ['id' => $job['current']['id'], 'bytes' => $bytes, 'total' => $total];
                    $jobs->save($job);
                });
                $job[$r['status']]++;
                echo sprintf("  [%d/%d] %-10s %d → %s\n", $i + 1, $job['total'], $r['status'], $id, $r['path']);
            } catch (Throwable $e) {
                $job['errors']++;
                $job['failed'][] = ['id' => $id, 'error' => $e->getMessage()];
                Log::error('job_item_failed', ['job' => $job['id'], 'id' => $id, 'error' => $e->getMessage()]);
                echo sprintf("  [%d/%d] %-10s %d (%s)\n", $i + 1, $job['total'], 'error', $id, $e->getMessage());
            }
            $job['cursor'] = $i + 1;
            $job['current'] = null;
            $jobs->save($job);
        }

        if ($job['status'] === 'running') {
            $job['status'] = 'done';
        }
    } catch (Throwable $e) {
        $job['status'] = 'error';
        $job['error'] = $e->getMessage();
        Log::error('job_failed', ['job' => $job['id'], 'error' => $e->getMessage()]);
    }

    $job['current'] = null;
    $job['pid'] = null;
    $jobs->save($job);
    Log::info('job_' . $job['status'], ['job' => $job['id'], 'total' => $job['total'], 'downloaded' => $job['downloaded'],
        'skipped' => $job['skipped'], 'errors' => $job['errors']]);
    echo "  → {$job['status']} · {$job['downloaded']} nuevas · {$job['skipped']} omitidas · {$job['errors']} errores\n";
}

Log::info('worker_end', ['pid' => getmypid()]);
flock($lock, LOCK_UN);
fclose($lock);
