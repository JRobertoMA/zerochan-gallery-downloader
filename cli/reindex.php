#!/usr/bin/env php
<?php
/**
 * Reconstruye data/index.sqlite a partir de los <id>.json de downloads/.
 *   docker exec -u www-data php-apache php /var/www/html/zerochan-gallery-downloader/cli/reindex.php
 */
if (PHP_SAPI !== 'cli') {
    exit("Solo CLI\n");
}
require __DIR__ . '/../config.php';

$index = new Index();
$n = $index->rebuild();
$s = $index->stats();
printf("%d imágenes indexadas (%.1f MB)\n", $n, $s['bytes'] / 1048576);
