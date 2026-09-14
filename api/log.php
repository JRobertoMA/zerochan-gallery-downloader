<?php
/**
 * GET api/log.php?n=100 → { lines: [...] }  últimas líneas de logs/zerochan.log
 */
require __DIR__ . '/_bootstrap.php';

$n = max(1, min(1000, (int) ($_GET['n'] ?? 100)));
json_out(['lines' => Log::tail($n)]);
