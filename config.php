<?php
/**
 * Configuración de zerochan-gallery-downloader.
 * El usuario de Zerochan se lee de .env (ver .env.example). Es obligatorio:
 * la API exige un User-Agent "<proyecto> - <usuario>" y avisa de ban para proyectos anónimos.
 */

$envFile = __DIR__ . '/.env';
$env = is_file($envFile) ? (parse_ini_file($envFile, false, INI_SCANNER_RAW) ?: []) : [];

$username = trim($env['ZEROCHAN_USERNAME'] ?? getenv('ZEROCHAN_USERNAME') ?: '');
$appName  = trim($env['ZEROCHAN_APP_NAME'] ?? getenv('ZEROCHAN_APP_NAME') ?: 'zerochan-gallery-downloader');

if ($username === '') {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    die("Falta ZEROCHAN_USERNAME. Copia .env.example a .env y pon tu usuario de Zerochan.\n");
}

define('ZC_USER_AGENT',   "$appName - $username");
define('ZC_COOKIE',       trim($env['ZEROCHAN_COOKIE'] ?? getenv('ZEROCHAN_COOKIE') ?: ''));  // opcional: sesión del navegador
define('ZC_BASE_URL',     'https://www.zerochan.net');
define('ZC_CACHE_DIR',    __DIR__ . '/cache');
define('ZC_CACHE_TTL',    600);              // segundos que vive una respuesta JSON en caché
define('ZC_DOWNLOAD_DIR', __DIR__ . '/downloads');
define('ZC_RATE_LIMIT',   55);               // peticiones/min; la API permite 60, dejamos margen
define('ZC_HTTP_TIMEOUT', 20);               // segundos para llamadas JSON
define('ZC_DL_TIMEOUT',   1800);             // tope total por fichero; el corte real es por velocidad mínima:
define('ZC_DL_MIN_SPEED', 1024);             // ... abortar si baja de 1 KB/s durante ZC_DL_STALL_SECS
define('ZC_DL_STALL_SECS', 60);              //     (static.zerochan.net a veces sirve a ~50 KB/s; 29 MB tardan 10 min)
define('ZC_BATCH_MAX',    500);              // tope duro de imágenes por lote
define('ZC_RETRIES',      3);                // intentos ante error de red / 5xx (backoff 2s, 5s, 10s)
define('ZC_DATA_DIR',     __DIR__ . '/data');          // índice SQLite, jobs, lock del worker
define('ZC_JOBS_DIR',     ZC_DATA_DIR . '/jobs');
define('ZC_INDEX_DB',     ZC_DATA_DIR . '/index.sqlite');
define('ZC_LOG_DIR',      __DIR__ . '/logs');
define('ZC_LOG_FILE',     ZC_LOG_DIR . '/zerochan.log');
define('ZC_PHP_BIN',      PHP_BINARY ?: '/usr/local/bin/php');   // para lanzar el worker desde Apache

// `docker exec` entra como root: los ficheros que creara (índice, log, jobs) quedarían ilegibles
// para Apache y el worker (www-data). Obliga a `docker exec -u www-data ...`.
if (PHP_SAPI === 'cli' && function_exists('posix_geteuid') && posix_geteuid() === 0 && getenv('ZC_ALLOW_ROOT') === false) {
    fwrite(STDERR, "No ejecutes los scripts como root: usa `docker exec -u www-data php-apache php ...`\n"
        . "(o exporta ZC_ALLOW_ROOT=1 si sabes lo que haces).\n");
    exit(1);
}

foreach ([ZC_CACHE_DIR, ZC_DOWNLOAD_DIR, ZC_DATA_DIR, ZC_JOBS_DIR, ZC_LOG_DIR] as $dir) {
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
}

spl_autoload_register(function (string $class): void {
    $file = __DIR__ . '/src/' . $class . '.php';
    if (is_file($file)) {
        require $file;
    }
});
