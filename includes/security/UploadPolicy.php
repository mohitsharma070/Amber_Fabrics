<?php

final class UploadPolicy
{
    public static function validate(
        array $file,
        array $allowedExtensions,
        array $allowedMimes,
        int $maxBytes,
        bool $requireImage = false
    ): array {
        if ((int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new RuntimeException('File upload failed.');
        }

        $tmpName = (string) ($file['tmp_name'] ?? '');
        $size = (int) ($file['size'] ?? 0);
        if ($tmpName === '' || $size <= 0 || $size > max(1, $maxBytes)) {
            throw new RuntimeException('Uploaded file size is invalid.');
        }

        $extension = strtolower((string) pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));
        $allowedExtensions = array_values(array_unique(array_map(
            static fn(mixed $value): string => strtolower(trim((string) $value)),
            $allowedExtensions
        )));
        if (!in_array($extension, $allowedExtensions, true)) {
            throw new RuntimeException('Uploaded file extension is not allowed.');
        }

        $mime = self::detectMime($tmpName);
        $mimeMap = [];
        foreach ($allowedMimes as $key => $value) {
            if (is_int($key)) {
                $mimeMap[strtolower(trim((string) $value))] = $extension;
            } else {
                $mimeMap[strtolower(trim((string) $key))] = strtolower(trim((string) $value));
            }
        }
        if ($mime === '' || !array_key_exists(strtolower($mime), $mimeMap)) {
            throw new RuntimeException('Uploaded file type is not allowed.');
        }
        if ($requireImage && !is_array(@getimagesize($tmpName))) {
            throw new RuntimeException('Uploaded image content is invalid.');
        }

        return [
            'tmp_name' => $tmpName,
            'size' => $size,
            'mime' => strtolower($mime),
            'extension' => $extension,
            'storage_extension' => $mimeMap[strtolower($mime)] ?: $extension,
        ];
    }

    public static function ensureDirectory(string $directory, int $permissions = 0755): string
    {
        $directory = rtrim($directory, '/\\');
        if ($directory === '') {
            throw new RuntimeException('Upload directory is invalid.');
        }
        if (!is_dir($directory) && !@mkdir($directory, $permissions, true) && !is_dir($directory)) {
            throw new RuntimeException('Upload directory is unavailable.');
        }
        $resolved = realpath($directory);
        if (!is_string($resolved) || $resolved === '') {
            throw new RuntimeException('Upload directory is unavailable.');
        }
        return rtrim($resolved, DIRECTORY_SEPARATOR);
    }

    public static function targetPath(string $directory, string $filename): string
    {
        $root = self::ensureDirectory($directory);
        $filename = trim(str_replace('\\', '/', $filename));
        if ($filename === '' || basename($filename) !== $filename || in_array($filename, ['.', '..'], true)) {
            throw new RuntimeException('Upload filename is invalid.');
        }
        return $root . DIRECTORY_SEPARATOR . $filename;
    }

    public static function move(array $file, string $directory, string $filename): string
    {
        $target = self::targetPath($directory, $filename);
        if (!move_uploaded_file((string) ($file['tmp_name'] ?? ''), $target)) {
            throw new RuntimeException('Failed to store uploaded file.');
        }
        return $target;
    }

    public static function deleteStoredFile(string $directory, ?string $storedName): bool
    {
        $storedName = trim(str_replace('\\', '/', (string) $storedName));
        if ($storedName === '' || basename($storedName) !== $storedName || in_array($storedName, ['.', '..'], true)) {
            return false;
        }
        $root = realpath(rtrim($directory, '/\\'));
        if (!is_string($root) || $root === '') {
            return false;
        }
        $target = rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $storedName;
        return !is_file($target) || @unlink($target);
    }

    private static function detectMime(string $path): string
    {
        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            if ($finfo !== false) {
                $mime = (string) (finfo_file($finfo, $path) ?: '');
                finfo_close($finfo);
                if ($mime !== '') {
                    return strtolower($mime);
                }
            }
        }
        return function_exists('mime_content_type') ? strtolower((string) (mime_content_type($path) ?: '')) : '';
    }
}
