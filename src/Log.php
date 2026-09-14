<?php
/**
 * Log de aplicación en logs/zerochan.log: una línea por evento, `fecha NIVEL evento {contexto json}`.
 * Rotación simple: al superar 5 MB se renombra a .1 (una sola copia).
 */
class Log
{
    private const MAX_BYTES = 5 * 1024 * 1024;

    public static function info(string $event, array $ctx = []): void  { self::write('INFO', $event, $ctx); }
    public static function warn(string $event, array $ctx = []): void  { self::write('WARN', $event, $ctx); }
    public static function error(string $event, array $ctx = []): void { self::write('ERROR', $event, $ctx); }

    /** Últimas $n líneas del log (más reciente al final). */
    public static function tail(int $n = 100): array
    {
        if (!is_file(ZC_LOG_FILE)) {
            return [];
        }
        $lines = file(ZC_LOG_FILE, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        return array_slice($lines, -$n);
    }

    private static function write(string $level, string $event, array $ctx): void
    {
        if (is_file(ZC_LOG_FILE) && filesize(ZC_LOG_FILE) > self::MAX_BYTES) {
            @rename(ZC_LOG_FILE, ZC_LOG_FILE . '.1');
        }
        $ctx['sapi'] = PHP_SAPI;
        $line = date('c') . " $level $event " . json_encode($ctx, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
        @file_put_contents(ZC_LOG_FILE, $line, FILE_APPEND | LOCK_EX);
    }
}
