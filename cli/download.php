#!/usr/bin/env php
<?php
/**
 * Descarga por lote desde la línea de comandos.
 *
 *   php cli/download.php "Genshin Impact" [--d=portrait] [--s=fav] [--t=1] [--c=blue] [--max=50] [--strict] [--p=1]
 *                        [--ecchi=hide|only] [--min=1080] [--sync]
 *   php cli/download.php "Lumine,Flower" --max=20
 *   php cli/download.php --id=3793685            descarga una sola imagen (síncrono)
 *   php cli/download.php --status                lista los jobs
 *
 * Por defecto encola un job y lanza el worker (cli/worker.php) en segundo plano.
 * Con --sync descarga en este mismo proceso mostrando el progreso (útil para cron con salida).
 *
 * Dentro del contenedor: docker exec -u www-data php-apache php /var/www/html/zerochan/cli/download.php "Hololive" --max=5
 */
if (PHP_SAPI !== 'cli') {
    exit("Solo CLI\n");
}
require __DIR__ . '/../config.php';

// Parseo manual: getopt() deja de leer opciones tras el primer argumento posicional,
// y aquí el tag va normalmente primero ("Tag" --max=5).
$opts = [];
$positional = [];
foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--([a-z]+)(?:=(.*))?$/', $arg, $m)) {
        $opts[$m[1]] = $m[2] ?? true;
    } else {
        $positional[] = $arg;
    }
}

$jobs = new Jobs();

if (isset($opts['status'])) {
    foreach (array_reverse($jobs->all()) as $j) {
        printf("%s  %-10s %-40s %d/%d  ↓%d =%d ✗%d\n", $j['id'], $j['status'], implode(', ', $j['tags']),
            $j['cursor'], $j['total'], $j['downloaded'], $j['skipped'], $j['errors']);
    }
    echo $jobs->workerRunning() ? "worker: en marcha\n" : "worker: parado\n";
    exit(0);
}

if (isset($opts['help']) || (!$positional && !isset($opts['id']))) {
    fwrite(STDERR, "Uso: php cli/download.php \"Tag1,Tag2\" [--d=portrait|landscape|square|large|huge] [--s=id|fav] [--t=0|1|2] [--c=color] [--max=N] [--strict] [--p=N] [--ecchi=all|hide|only] [--min=PX] [--sync]\n");
    fwrite(STDERR, "     php cli/download.php --id=3793685\n     php cli/download.php --status\n");
    exit(1);
}

$client     = new ZerochanClient(new RateLimiter(ZC_RATE_LIMIT));
$downloader = new Downloader($client, new Index());

if (isset($opts['id'])) {
    $r = $downloader->one((int) $opts['id']);
    echo "[{$r['status']}] {$r['id']} → {$r['path']}\n";
    exit(0);
}

$tags = array_values(array_filter(array_map('trim', explode(',', $positional[0]))));
$params = [];
foreach (['d', 's', 't', 'c', 'p'] as $k) {
    if (isset($opts[$k])) $params[$k] = $opts[$k];
}
if (isset($opts['strict'])) $params['strict'] = true;
if (isset($opts['ecchi'])) {
    if (!in_array($opts['ecchi'], ZerochanClient::ECCHI_MODES, true)) {
        fwrite(STDERR, "--ecchi debe ser: " . implode('|', ZerochanClient::ECCHI_MODES) . "\n");
        exit(1);
    }
    $params['ecchi'] = $opts['ecchi'];
}
if (isset($opts['min'])) $params['min'] = max(0, min(10000, (int) $opts['min']));
$max = (int) ($opts['max'] ?? 50);

if (!isset($opts['sync'])) {
    $job = $jobs->create($tags, $params, $max);
    $spawned = $jobs->spawnWorker();
    echo "Job {$job['id']} encolado" . ($spawned ? ' · worker lanzado' : ' · worker ya en marcha') . "\n";
    echo "Sigue el progreso con --status o en la web.\n";
    exit(0);
}

echo "Descargando hasta $max imágenes de [" . implode(', ', $tags) . "]" . ($params ? ' con ' . json_encode($params) : '') . "\n";

$summary = $downloader->batch($tags, $params, $max, function (array $p) {
    $line = sprintf('[%d/%d] %-10s %d', $p['done'], $p['total'], $p['status'], $p['id']);
    echo $line, $p['path'] ? " → {$p['path']}" : '', $p['error'] ? " ({$p['error']})" : '', "\n";
});

printf("Total %d · descargadas %d · omitidas %d · errores %d\n",
    $summary['total'], $summary['downloaded'], $summary['skipped'], $summary['errors']);
exit($summary['errors'] > 0 ? 2 : 0);
