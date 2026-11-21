<?php
/**
 * 环境变量加载器
 * 优先读取操作系统/Web服务器环境变量（getenv / $_ENV / $_SERVER），
 * 若 Web 进程未重启导致未注入，则从项目根目录 .env 文件加载到进程环境变量中。
 */

function app_env(string $key, ?string $default = null): ?string
{
    static $loaded = false;

    $val = getenv($key);
    if ($val !== false && $val !== '') {
        return (string) $val;
    }
    if (!empty($_ENV[$key])) {
        return (string) $_ENV[$key];
    }
    if (!empty($_SERVER[$key])) {
        return (string) $_SERVER[$key];
    }

    if (!$loaded) {
        $loaded = true;
        $envPath = dirname(__DIR__, 2) . '/.env';
        if (is_file($envPath) && is_readable($envPath)) {
            $lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            if (is_array($lines)) {
                foreach ($lines as $line) {
                    $line = trim($line);
                    if ($line === '' || $line[0] === '#') {
                        continue;
                    }
                    $pos = strpos($line, '=');
                    if ($pos === false) {
                        continue;
                    }
                    $k = trim(substr($line, 0, $pos));
                    $v = trim(substr($line, $pos + 1), " \t\n\r\0\x0B\"'");
                    if ($k !== '' && getenv($k) === false) {
                        putenv("$k=$v");
                        $_ENV[$k] = $v;
                    }
                }
            }
        }
    }

    $val = getenv($key);
    if ($val !== false && $val !== '') {
        return (string) $val;
    }
    return $_ENV[$key] ?? $default;
}