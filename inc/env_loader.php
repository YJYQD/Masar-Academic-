<?php
function load_env_file($path = null)
{
    $envPath = $path ?: __DIR__ . '/../.env';
    if (!is_file($envPath)) {
        return false;
    }

    $lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) {
        return false;
    }

    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }

        [$name, $value] = array_pad(explode('=', $line, 2), 2, '');
        $name = trim($name);
        $value = trim($value);

        if ($name === '') {
            continue;
        }

        $value = trim($value, "\"'");
        if (!getenv($name)) {
            putenv("{$name}={$value}");
            $_ENV[$name] = $value;
            $_SERVER[$name] = $value;
        }
    }

    return true;
}
?>
