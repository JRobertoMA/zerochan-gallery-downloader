<?php
/**
 * Cola de descargas en segundo plano.
 *   GET  api/jobs.php            → { jobs: [resumen...], worker_running }
 *   GET  api/jobs.php?id=X       → job completo (con failed, sin queue)
 *   POST action=create&tags=..&max=..&[p,l,s,t,d,c,strict,ecchi,min]  → { job, spawned }
 *   POST action=cancel&id=X | action=retry&id=X | action=clear
 */
require __DIR__ . '/_bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    if (!empty($_GET['id'])) {
        $job = $jobs->get((string) $_GET['id']);
        if (!$job) json_out(['error' => 'job no encontrado', 'status' => 404], 404);
        unset($job['queue']);
        json_out($job);
    }
    json_out([
        'jobs' => array_map([Jobs::class, 'summary'], array_reverse($jobs->all())),
        'worker_running' => $jobs->workerRunning(),
    ]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_out(['error' => 'Usa GET o POST', 'status' => 405], 405);
}

$action = (string) ($_POST['action'] ?? '');
$id = (string) ($_POST['id'] ?? '');

switch ($action) {
    case 'create':
        $tags = parse_tags((string) ($_POST['tags'] ?? ''));
        $params = search_params($_POST);
        $max = (int) ($_POST['max'] ?? 50);
        $job = $jobs->create($tags, $params, $max);
        $spawned = $jobs->spawnWorker();
        json_out(['job' => Jobs::summary($job), 'spawned' => $spawned]);

    case 'cancel':
        $job = $jobs->cancel($id);
        if (!$job) json_out(['error' => 'job no encontrado', 'status' => 404], 404);
        json_out(['job' => Jobs::summary($job)]);

    case 'retry':
        $job = $jobs->retry($id);
        if (!$job) json_out(['error' => 'job no encontrado o sin fallos', 'status' => 404], 404);
        $spawned = $jobs->spawnWorker();
        json_out(['job' => Jobs::summary($job), 'spawned' => $spawned]);

    case 'clear':
        json_out(['cleared' => $jobs->clearFinished()]);

    default:
        json_out(['error' => 'action inválida', 'status' => 400], 400);
}
