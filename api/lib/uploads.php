<?php
/**
 * Upload storage helpers.
 *
 * Default behavior keeps the existing repository-level uploads directory.
 * Cloud Run can override POSLA_UPLOADS_DIR to a mounted shared storage path.
 */

function posla_uploads_env($key, $default = '')
{
    $value = getenv($key);
    if ($value === false || trim((string)$value) === '') {
        return $default;
    }
    return trim((string)$value);
}

function posla_uploads_root()
{
    $root = posla_uploads_env('POSLA_UPLOADS_DIR', dirname(__DIR__, 2) . '/uploads');
    return rtrim(str_replace('\\', '/', $root), '/');
}

function posla_uploads_public_prefix()
{
    $prefix = posla_uploads_env('POSLA_UPLOADS_PUBLIC_PREFIX', 'uploads');
    $prefix = trim(str_replace('\\', '/', $prefix), '/');
    return $prefix === '' ? 'uploads' : $prefix;
}

function posla_uploads_safe_relative($path)
{
    $path = trim(str_replace('\\', '/', (string)$path), '/');
    if ($path === '') {
        return '';
    }

    $safeParts = [];
    foreach (explode('/', $path) as $part) {
        if ($part === '' || $part === '.') {
            continue;
        }
        if ($part === '..') {
            return '';
        }
        $safeParts[] = $part;
    }

    return implode('/', $safeParts);
}

function posla_uploads_path($relativePath = '')
{
    $root = posla_uploads_root();
    $relativePath = posla_uploads_safe_relative($relativePath);
    return $relativePath === '' ? $root : $root . '/' . $relativePath;
}

function posla_uploads_public_url($relativePath)
{
    $relativePath = posla_uploads_safe_relative($relativePath);
    if ($relativePath === '') {
        return posla_uploads_public_prefix();
    }
    return posla_uploads_public_prefix() . '/' . $relativePath;
}

function posla_uploads_ensure_dir($dir)
{
    if (is_dir($dir)) {
        return true;
    }

    if (@mkdir($dir, 0755, true)) {
        return true;
    }

    return is_dir($dir);
}
