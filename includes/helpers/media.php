<?php
/**
 * Generate a safe filename for uploads.
 */
function random_filename(string $originalName): string
{
    $ext = pathinfo($originalName, PATHINFO_EXTENSION);
    return uniqid('fabric_', true) . ($ext ? ".{$ext}" : '');
}

function fabric_upload_directory(): string
{
    $directory = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'images' . DIRECTORY_SEPARATOR . 'fabrics';
    if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
        throw new RuntimeException('Fabric upload directory is missing.');
    }
    if (!is_writable($directory)) {
        throw new RuntimeException('Fabric upload directory is not writable.');
    }
    return $directory;
}

function fabric_upload_path(string $filename): string
{
    return fabric_upload_directory() . DIRECTORY_SEPARATOR . basename($filename);
}

function image_upload_max_mb(): int
{
    $mb = (int) _cfg('IMAGE_UPLOAD_MAX_MB', '5');
    return $mb > 0 ? $mb : 5;
}

function image_upload_max_bytes(): int
{
    return image_upload_max_mb() * 1024 * 1024;
}

function image_pipeline_webp_quality(): int
{
    $quality = (int) _cfg('IMAGE_WEBP_QUALITY', '82');
    if ($quality < 40) {
        return 40;
    }
    if ($quality > 95) {
        return 95;
    }
    return $quality;
}

function image_pipeline_max_width(): int
{
    $maxWidth = (int) _cfg('IMAGE_MAX_WIDTH', '1920');
    return $maxWidth > 0 ? $maxWidth : 1920;
}

function image_pipeline_webp_widths(): array
{
    $raw = trim(_cfg('IMAGE_RESPONSIVE_WIDTHS', '360,720,1200'));
    $parts = array_filter(array_map('trim', explode(',', $raw)), static fn($v) => $v !== '');
    $widths = [];
    foreach ($parts as $part) {
        if (!is_numeric($part)) {
            continue;
        }
        $w = (int) $part;
        if ($w > 0) {
            $widths[] = $w;
        }
    }
    if (empty($widths)) {
        $widths = [360, 720, 1200];
    }
    $widths = array_values(array_unique($widths));
    sort($widths);
    return $widths;
}

function image_pipeline_thumb_dimensions(): array
{
    $w = (int) _cfg('IMAGE_THUMB_WIDTH', '360');
    $h = (int) _cfg('IMAGE_THUMB_HEIGHT', '360');
    return [max(64, $w), max(64, $h)];
}

function image_pipeline_create_resource(string $path, string $mime)
{
    if ($mime === 'image/jpeg' && function_exists('imagecreatefromjpeg')) {
        return @imagecreatefromjpeg($path);
    }
    if ($mime === 'image/png' && function_exists('imagecreatefrompng')) {
        return @imagecreatefrompng($path);
    }
    if ($mime === 'image/webp' && function_exists('imagecreatefromwebp')) {
        return @imagecreatefromwebp($path);
    }
    return false;
}

function image_pipeline_create_canvas(int $width, int $height)
{
    $canvas = imagecreatetruecolor($width, $height);
    if ($canvas === false) {
        return false;
    }
    imagealphablending($canvas, false);
    imagesavealpha($canvas, true);
    $transparent = imagecolorallocatealpha($canvas, 0, 0, 0, 127);
    imagefilledrectangle($canvas, 0, 0, $width, $height, $transparent);
    return $canvas;
}

function image_pipeline_save_resource($resource, string $path, string $mime, int $quality): bool
{
    if ($mime === 'image/jpeg') {
        if (function_exists('imageinterlace')) {
            imageinterlace($resource, true);
        }
        return (bool) @imagejpeg($resource, $path, $quality);
    }
    if ($mime === 'image/png') {
        $compression = (int) round((100 - $quality) / 10);
        $compression = max(0, min(9, $compression));
        return (bool) @imagepng($resource, $path, $compression);
    }
    if ($mime === 'image/webp' && function_exists('imagewebp')) {
        return (bool) @imagewebp($resource, $path, $quality);
    }
    return false;
}

function image_pipeline_resize_to_width($source, int $srcWidth, int $srcHeight, int $targetWidth)
{
    if ($targetWidth <= 0 || $srcWidth <= 0 || $srcHeight <= 0) {
        return false;
    }
    $targetHeight = (int) round(($srcHeight * $targetWidth) / $srcWidth);
    $target = image_pipeline_create_canvas($targetWidth, max(1, $targetHeight));
    if ($target === false) {
        return false;
    }
    imagecopyresampled($target, $source, 0, 0, 0, 0, $targetWidth, max(1, $targetHeight), $srcWidth, $srcHeight);
    return $target;
}

function image_pipeline_resize_cover($source, int $srcWidth, int $srcHeight, int $targetWidth, int $targetHeight)
{
    if ($srcWidth <= 0 || $srcHeight <= 0 || $targetWidth <= 0 || $targetHeight <= 0) {
        return false;
    }
    $srcRatio = $srcWidth / $srcHeight;
    $targetRatio = $targetWidth / $targetHeight;

    if ($srcRatio > $targetRatio) {
        $cropHeight = $srcHeight;
        $cropWidth = (int) round($srcHeight * $targetRatio);
        $srcX = (int) floor(($srcWidth - $cropWidth) / 2);
        $srcY = 0;
    } else {
        $cropWidth = $srcWidth;
        $cropHeight = (int) round($srcWidth / $targetRatio);
        $srcX = 0;
        $srcY = (int) floor(($srcHeight - $cropHeight) / 2);
    }

    $target = image_pipeline_create_canvas($targetWidth, $targetHeight);
    if ($target === false) {
        return false;
    }
    imagecopyresampled($target, $source, 0, 0, $srcX, $srcY, $targetWidth, $targetHeight, $cropWidth, $cropHeight);
    return $target;
}

function image_pipeline_generate_derivatives(string $absoluteImagePath): void
{
    if (!is_file($absoluteImagePath) || !extension_loaded('gd') || !function_exists('imagecreatetruecolor')) {
        return;
    }

    $info = @getimagesize($absoluteImagePath);
    if (!is_array($info) || !isset($info[0], $info[1], $info['mime'])) {
        return;
    }

    $mime = strtolower((string) $info['mime']);
    if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) {
        return;
    }

    $source = image_pipeline_create_resource($absoluteImagePath, $mime);
    if ($source === false) {
        return;
    }

    $quality = image_pipeline_webp_quality();
    $srcWidth = (int) $info[0];
    $srcHeight = (int) $info[1];
    $maxWidth = image_pipeline_max_width();

    if ($maxWidth > 0 && $srcWidth > $maxWidth) {
        $resizedOriginal = image_pipeline_resize_to_width($source, $srcWidth, $srcHeight, $maxWidth);
        if ($resizedOriginal !== false) {
            if (image_pipeline_save_resource($resizedOriginal, $absoluteImagePath, $mime, $quality)) {
                imagedestroy($source);
                $source = $resizedOriginal;
                $srcWidth = imagesx($source);
                $srcHeight = imagesy($source);
            } else {
                imagedestroy($resizedOriginal);
            }
        }
    }

    $dir = dirname($absoluteImagePath);
    $base = pathinfo($absoluteImagePath, PATHINFO_FILENAME);
    $ext = strtolower(pathinfo($absoluteImagePath, PATHINFO_EXTENSION));

    if (function_exists('imagewebp')) {
        $widths = image_pipeline_webp_widths();
        foreach ($widths as $width) {
            if ($width <= 0 || $width > $srcWidth) {
                continue;
            }
            $resized = image_pipeline_resize_to_width($source, $srcWidth, $srcHeight, $width);
            if ($resized === false) {
                continue;
            }
            $targetPath = $dir . DIRECTORY_SEPARATOR . $base . '-' . $width . 'w.webp';
            image_pipeline_save_resource($resized, $targetPath, 'image/webp', $quality);
            imagedestroy($resized);
        }

        [$thumbW, $thumbH] = image_pipeline_thumb_dimensions();
        $thumbWebp = image_pipeline_resize_cover($source, $srcWidth, $srcHeight, $thumbW, $thumbH);
        if ($thumbWebp !== false) {
            $thumbWebpPath = $dir . DIRECTORY_SEPARATOR . $base . '-thumb.webp';
            image_pipeline_save_resource($thumbWebp, $thumbWebpPath, 'image/webp', $quality);
            imagedestroy($thumbWebp);
        }
    }

    [$thumbW, $thumbH] = image_pipeline_thumb_dimensions();
    $thumbFallback = image_pipeline_resize_cover($source, $srcWidth, $srcHeight, $thumbW, $thumbH);
    if ($thumbFallback !== false) {
        $thumbExt = $ext !== '' ? $ext : ($mime === 'image/png' ? 'png' : 'jpg');
        $thumbMime = $mime;
        if ($thumbMime === 'image/webp' && !function_exists('imagewebp')) {
            $thumbMime = 'image/jpeg';
            $thumbExt = 'jpg';
        }
        $thumbPath = $dir . DIRECTORY_SEPARATOR . $base . '-thumb.' . $thumbExt;
        image_pipeline_save_resource($thumbFallback, $thumbPath, $thumbMime, $quality);
        imagedestroy($thumbFallback);
    }

    imagedestroy($source);
}

function image_pipeline_delete_files(string $directory, string $filename): void
{
    $filename = trim($filename);
    if ($filename === '') {
        return;
    }

    $directory = rtrim($directory, '/\\');
    $filename = basename($filename);
    $originalPath = $directory . DIRECTORY_SEPARATOR . $filename;
    if (is_file($originalPath)) {
        @unlink($originalPath);
    }

    $base = pathinfo($filename, PATHINFO_FILENAME);
    if ($base === '') {
        return;
    }
    $matches = glob($directory . DIRECTORY_SEPARATOR . $base . '-*');
    if (is_array($matches)) {
        foreach ($matches as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }
    }
}

function save_fabric_image_upload(array $file, string $label = 'Image'): string
{
    $allowedImageExt = ['jpg', 'jpeg', 'png', 'webp'];
    $allowedImageMime = ['image/jpeg', 'image/png', 'image/webp'];
    $maxImageSize = image_upload_max_bytes();
    // Minimum image size restriction removed
    $minImageWidth = 1;
    $minImageHeight = 1;

    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new RuntimeException($label . ' upload failed. Please try again.');
    }

    $tmpName = (string) ($file['tmp_name'] ?? '');
    $ext = strtolower(pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));
    $mime = function_exists('mime_content_type') ? (mime_content_type($tmpName) ?: '') : '';
    $size = (int) ($file['size'] ?? 0);
    $imageInfo = @getimagesize($tmpName);

    if ($size > $maxImageSize) {
        throw new RuntimeException($label . ' must be under ' . image_upload_max_mb() . 'MB.');
    }

    if (!in_array($ext, $allowedImageExt, true) || !in_array($mime, $allowedImageMime, true) || !is_array($imageInfo)) {
        throw new RuntimeException($label . ' must be JPG, PNG or WEBP.');
    }

    $imgWidth = (int) ($imageInfo[0] ?? 0);
    $imgHeight = (int) ($imageInfo[1] ?? 0);
    // No minimum image size check

    $saved = random_filename((string) ($file['name'] ?? 'image.jpg'));
    $target = fabric_upload_path($saved);
    if (!move_uploaded_file($tmpName, $target)) {
        throw new RuntimeException($label . ' upload failed.');
    }

    // Re-encode through GD to strip any embedded payloads (polyglot/steganography defense).
    // If GD is unavailable, keep the validated upload and skip transform pipeline.
    if (!extension_loaded('gd') || !function_exists('imagecreatetruecolor')) {
        return $saved;
    }

    $gdMime = strtolower((string) ($imageInfo['mime'] ?? $mime));
    if (!in_array($gdMime, ['image/jpeg', 'image/png', 'image/webp'], true)) {
        $gdMime = $mime;
    }
    $gdResource = image_pipeline_create_resource($target, $gdMime);
    if ($gdResource === false) {
        @unlink($target);
        throw new RuntimeException($label . ' could not be processed. Please upload a valid image file.');
    }
    $gdQuality = image_pipeline_webp_quality();
    if (!image_pipeline_save_resource($gdResource, $target, $gdMime, $gdQuality)) {
        imagedestroy($gdResource);
        @unlink($target);
        throw new RuntimeException($label . ' could not be saved. Please try again.');
    }
    imagedestroy($gdResource);

    image_pipeline_generate_derivatives($target);
    return $saved;
}

function image_pipeline_asset_data(string $relativeDir, string $filename): array
{
    $filename = trim($filename);
    if ($filename === '') {
        return [
            'src' => '',
            'thumb_src' => '',
            'webp_srcset' => '',
        ];
    }

    $relativeDir = trim(str_replace('\\', '/', $relativeDir), '/');
    $filename = basename(str_replace('\\', '/', $filename));
    $base = pathinfo($filename, PATHINFO_FILENAME);
    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    $absDir = __DIR__ . '/../../' . $relativeDir;

    $baseUrl = '/' . $relativeDir;
    $originalUrl = $baseUrl . '/' . $filename;

    $thumbWebp = $base . '-thumb.webp';
    $thumbWebpAbs = $absDir . DIRECTORY_SEPARATOR . $thumbWebp;
    $thumbFallback = ($ext !== '') ? ($base . '-thumb.' . $ext) : '';
    $thumbFallbackAbs = $thumbFallback !== '' ? ($absDir . DIRECTORY_SEPARATOR . $thumbFallback) : '';

    if (is_file($thumbWebpAbs)) {
        $thumbUrl = $baseUrl . '/' . $thumbWebp;
    } elseif ($thumbFallbackAbs !== '' && is_file($thumbFallbackAbs)) {
        $thumbUrl = $baseUrl . '/' . $thumbFallback;
    } else {
        $thumbUrl = $originalUrl;
    }

    $srcsetParts = [];
    foreach (image_pipeline_webp_widths() as $w) {
        $variant = $base . '-' . $w . 'w.webp';
        $variantAbs = $absDir . DIRECTORY_SEPARATOR . $variant;
        if (is_file($variantAbs)) {
            $srcsetParts[] = $baseUrl . '/' . $variant . ' ' . $w . 'w';
        }
    }

    return [
        'src' => $originalUrl,
        'thumb_src' => $thumbUrl,
        'webp_srcset' => implode(', ', $srcsetParts),
    ];
}

function fabric_image_asset_data(string $filename): array
{
    return image_pipeline_asset_data('images/fabrics', $filename);
}

/**
 * Scan original fabric images and list files below configured minimum dimensions.
 */
function image_pipeline_low_resolution_fabric_images(int $limit = 20): array
{
    static $cache = [];

    $minWidth = max(1, (int) _cfg('IMAGE_MIN_WIDTH', '600'));
    $minHeight = max(1, (int) _cfg('IMAGE_MIN_HEIGHT', '800'));
    $limit = max(1, min(5000, $limit));
    $cacheKey = $minWidth . 'x' . $minHeight . ':' . $limit;

    if (isset($cache[$cacheKey])) {
        return $cache[$cacheKey];
    }

    $directory = __DIR__ . '/../../images/fabrics';
    $rows = [];

    if (is_dir($directory)) {
        try {
            $iterator = new DirectoryIterator($directory);
            foreach ($iterator as $fileInfo) {
                if ($fileInfo->isDot() || !$fileInfo->isFile()) {
                    continue;
                }

                $filename = $fileInfo->getFilename();
                if (!preg_match('/\.(jpe?g|png|webp)$/i', $filename)) {
                    continue;
                }
                if (preg_match('/-(thumb|\d+w)\.(webp|jpe?g|png)$/i', $filename)) {
                    continue;
                }

                $info = @getimagesize($fileInfo->getPathname());
                if (!is_array($info)) {
                    continue;
                }

                $width = (int) ($info[0] ?? 0);
                $height = (int) ($info[1] ?? 0);
                if ($width < $minWidth || $height < $minHeight) {
                    $rows[] = [
                        'filename' => $filename,
                        'width' => $width,
                        'height' => $height,
                        'area' => $width * $height,
                    ];
                }
            }
        } catch (Throwable $e) {
            $rows = [];
        }
    }

    usort($rows, static function (array $a, array $b): int {
        $cmp = ((int) ($a['area'] ?? 0)) <=> ((int) ($b['area'] ?? 0));
        if ($cmp !== 0) {
            return $cmp;
        }
        return strcmp((string) ($a['filename'] ?? ''), (string) ($b['filename'] ?? ''));
    });

    $result = [
        'min_width' => $minWidth,
        'min_height' => $minHeight,
        'total' => count($rows),
        'items' => array_slice($rows, 0, $limit),
    ];

    $cache[$cacheKey] = $result;
    return $result;
}
