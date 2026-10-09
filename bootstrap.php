<?php
/**
 * Recovery bootstrap for the mem project.
 * Place this file at: private/bootstrap.php
 *
 * This is a compatibility bootstrap, not a recovered copy of the original file.
 * It loads private/config.php when present and optionally creates a PDO connection
 * from common configuration constants/environment variables.
 */

declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$projectRoot = dirname(__DIR__);

// Load the project's private configuration if it exists.
$configCandidates = [
    __DIR__ . '/config.php',
    $projectRoot . '/config.php',
];
foreach ($configCandidates as $configFile) {
    if (is_file($configFile) && is_readable($configFile)) {
        require_once $configFile;
        break;
    }
}

if (!function_exists('env_value')) {
    function env_value(string $key, mixed $default = null): mixed
    {
        if (array_key_exists($key, $_ENV)) {
            return $_ENV[$key];
        }
        $value = getenv($key);
        return $value === false ? $default : $value;
    }
}

if (!function_exists('e')) {
    function e(mixed $value): string
    {
        return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

if (!function_exists('url_path')) {
    function url_path(string $path = ''): string
    {
        $base = defined('BASE_URL') ? (string) BASE_URL : (string) env_value('BASE_URL', '');
        $base = rtrim($base, '/');
        return $base . '/' . ltrim($path, '/');
    }
}

if (!function_exists('redirect')) {
    function redirect(string $url, int $statusCode = 302): never
    {
        if (!preg_match('~^https?://~i', $url) && !str_starts_with($url, '/')) {
            $url = url_path($url);
        }
        header('Location: ' . $url, true, $statusCode);
        exit;
    }
}

// Create a PDO connection only when the project has not already created one.
// Common global names are supported for compatibility with older PHP projects.
if (!isset($GLOBALS['pdo']) && !isset($GLOBALS['db'])) {
    $dsn = defined('DB_DSN') ? (string) DB_DSN : (string) env_value('DB_DSN', '');
    $dbHost = defined('DB_HOST') ? (string) DB_HOST : (string) env_value('DB_HOST', 'localhost');
    $dbName = defined('DB_NAME') ? (string) DB_NAME : (string) env_value('DB_NAME', '');
    $dbUser = defined('DB_USER') ? (string) DB_USER : (string) env_value('DB_USER', '');
    $dbPass = defined('DB_PASS') ? (string) DB_PASS : (string) env_value('DB_PASS', '');
    $dbCharset = defined('DB_CHARSET') ? (string) DB_CHARSET : (string) env_value('DB_CHARSET', 'utf8mb4');

    if ($dsn === '' && $dbName !== '') {
        $dsn = 'mysql:host=' . $dbHost . ';dbname=' . $dbName . ';charset=' . $dbCharset;
    }

    if ($dsn !== '' && $dbUser !== '') {
        try {
            $GLOBALS['pdo'] = new PDO($dsn, $dbUser, $dbPass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
            // Many projects use $db instead of $pdo.
            $GLOBALS['db'] = $GLOBALS['pdo'];
        } catch (Throwable $exception) {
            error_log('Database connection failed in private/bootstrap.php: ' . $exception->getMessage());
            http_response_code(500);
            exit('Database connection failed. Check private/config.php and database settings.');
        }
    }
}

// Expose conventional local variables for code that expects them after require.
if (isset($GLOBALS['pdo']) && !isset($pdo)) {
    $pdo = $GLOBALS['pdo'];
}
if (isset($GLOBALS['db']) && !isset($db)) {
    $db = $GLOBALS['db'];
}
