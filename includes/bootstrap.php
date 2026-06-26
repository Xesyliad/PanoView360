<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';

function app_bootstrap(): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        ini_set('session.cookie_httponly', '1');
        ini_set('session.cookie_samesite', 'Lax');
        ini_set('session.use_strict_mode', '1');
        $sessionPath = DATA_DIR . '/sessions';
        if (!is_dir($sessionPath)) {
            mkdir($sessionPath, 0775, true);
        }
        ini_set('session.save_path', $sessionPath);
        session_start();
    }

    foreach ([DATA_DIR, UPLOAD_DIR, PANORAMA_DIR, THUMB_DIR] as $dir) {
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
    }

    if (!is_file(DATA_FILE)) {
        file_put_contents(DATA_FILE, json_encode([], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }
}

function app_load_panoramas(): array
{
    if (!is_file(DATA_FILE)) {
        return [];
    }

    $raw = file_get_contents(DATA_FILE);
    if ($raw === false || trim($raw) === '') {
        return [];
    }

    $items = json_decode($raw, true);
    if (!is_array($items)) {
        return [];
    }

    usort($items, static fn (array $a, array $b): int => ($a['display_order'] ?? 0) <=> ($b['display_order'] ?? 0));
    return array_values($items);
}

function app_save_panoramas(array $items): bool
{
    $items = array_values($items);
    foreach ($items as $index => &$item) {
        $item['display_order'] = $index + 1;
    }
    unset($item);

    $json = json_encode($items, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        return false;
    }

    $tmp = DATA_FILE . '.tmp';
    if (file_put_contents($tmp, $json, LOCK_EX) === false) {
        return false;
    }

    return rename($tmp, DATA_FILE);
}

function app_find_pano_index(array $items, string $id): int
{
    foreach ($items as $index => $item) {
        if (($item['id'] ?? '') === $id) {
            return $index;
        }
    }

    return -1;
}

function app_sanitize_title(string $value): string
{
    $value = trim(preg_replace('/\s+/', ' ', $value) ?? '');
    return $value !== '' ? $value : 'Untitled Panorama';
}

function app_title_from_filename(string $name): string
{
    return app_sanitize_title(pathinfo($name, PATHINFO_FILENAME));
}

function app_slugify(string $value): string
{
    $value = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
    $value = strtolower((string) $value);
    $value = preg_replace('/[^a-z0-9]+/', '-', $value) ?? '';
    $value = trim($value, '-');
    return $value !== '' ? $value : 'panorama';
}

function app_sanitize_filename(string $name, string $extension): string
{
    $base = pathinfo($name, PATHINFO_FILENAME);
    $base = app_slugify($base);
    $random = bin2hex(random_bytes(4));
    return $base . '-' . date('YmdHis') . '-' . $random . '.' . strtolower($extension);
}

function app_exif_rational_to_float(mixed $value): ?float
{
    if (is_int($value) || is_float($value)) {
        return (float) $value;
    }

    if (!is_string($value) || $value === '') {
        return null;
    }

    if (str_contains($value, '/')) {
        [$numerator, $denominator] = array_pad(explode('/', $value, 2), 2, '0');
        $denominator = (float) $denominator;
        if ($denominator === 0.0) {
            return null;
        }

        return (float) $numerator / $denominator;
    }

    if (is_numeric($value)) {
        return (float) $value;
    }

    return null;
}

function app_exif_gps_to_decimal(array $gps, string $valueKey, string $refKey): ?float
{
    if (empty($gps[$valueKey]) || empty($gps[$refKey]) || !is_array($gps[$valueKey])) {
        return null;
    }

    $degrees = app_exif_rational_to_float($gps[$valueKey][0] ?? null);
    $minutes = app_exif_rational_to_float($gps[$valueKey][1] ?? null);
    $seconds = app_exif_rational_to_float($gps[$valueKey][2] ?? null);

    if ($degrees === null || $minutes === null || $seconds === null) {
        return null;
    }

    $decimal = $degrees + ($minutes / 60) + ($seconds / 3600);
    $ref = strtoupper(trim((string) $gps[$refKey]));
    if (in_array($ref, ['S', 'W'], true)) {
        $decimal *= -1;
    }

    return $decimal;
}

function app_extract_gps_coordinates(string $path): ?array
{
    if (!extension_loaded('exif') || !is_file($path)) {
        return null;
    }

    $exif = @exif_read_data($path, 'GPS', true, false);
    if (!is_array($exif)) {
        return null;
    }

    $gps = $exif['GPS'] ?? $exif;
    if (!is_array($gps)) {
        return null;
    }

    $lat = app_exif_gps_to_decimal($gps, 'GPSLatitude', 'GPSLatitudeRef');
    $lng = app_exif_gps_to_decimal($gps, 'GPSLongitude', 'GPSLongitudeRef');

    if ($lat === null || $lng === null) {
        return null;
    }

    return ['lat' => $lat, 'lng' => $lng];
}

function app_allowed_image_extension(string $ext): bool
{
    return in_array(strtolower($ext), ['jpg', 'jpeg', 'png', 'webp', 'gif'], true);
}

function app_allowed_image_mime(string $mime): bool
{
    return in_array($mime, ['image/jpeg', 'image/png', 'image/webp', 'image/gif'], true);
}

function app_csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return (string) $_SESSION['csrf_token'];
}

function app_verify_csrf(?string $token): bool
{
    return is_string($token) && hash_equals(app_csrf_token(), $token);
}

function app_is_admin(): bool
{
    return !empty($_SESSION['is_admin']);
}

function app_require_admin(): void
{
    if (!app_is_admin()) {
        header('Location: ?login=1');
        exit;
    }
}

function app_login_admin(string $password): bool
{
    if (!password_verify($password, ADMIN_PASSWORD_HASH)) {
        return false;
    }

    $_SESSION['is_admin'] = true;
    session_regenerate_id(true);
    return true;
}

function app_logout_admin(): void
{
    unset($_SESSION['is_admin']);
    session_regenerate_id(true);
}

function app_flash_set(string $type, string $message): void
{
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

function app_flash_get(): ?array
{
    if (empty($_SESSION['flash']) || !is_array($_SESSION['flash'])) {
        return null;
    }

    $flash = $_SESSION['flash'];
    unset($_SESSION['flash']);
    return $flash;
}

function app_image_from_upload(string $path, string $mime)
{
    return match ($mime) {
        'image/jpeg' => imagecreatefromjpeg($path),
        'image/png' => imagecreatefrompng($path),
        'image/webp' => function_exists('imagecreatefromwebp') ? imagecreatefromwebp($path) : false,
        'image/gif' => imagecreatefromgif($path),
        default => false,
    };
}

function app_save_jpeg($image, string $path, int $quality = 86): bool
{
    return imagejpeg($image, $path, $quality);
}

function app_create_thumbnail(string $sourcePath, string $thumbPath, string $mime): bool
{
    if (class_exists('Imagick')) {
        try {
            $image = new Imagick($sourcePath);
            $image->setImageFormat('jpeg');
            $image->setImageColorspace(Imagick::COLORSPACE_SRGB);
            $image->cropThumbnailImage(THUMB_WIDTH, THUMB_HEIGHT);
            $image->setImageCompressionQuality(86);
            $result = $image->writeImage($thumbPath);
            $image->clear();
            $image->destroy();
            return $result;
        } catch (Throwable) {
            return false;
        }
    }

    if (extension_loaded('gd')) {
        $source = @app_image_from_upload($sourcePath, $mime);
        if (!$source) {
            return false;
        }

        $srcWidth = imagesx($source);
        $srcHeight = imagesy($source);
        if ($srcWidth <= 0 || $srcHeight <= 0) {
            imagedestroy($source);
            return false;
        }

        $targetRatio = THUMB_WIDTH / THUMB_HEIGHT;
        $sourceRatio = $srcWidth / $srcHeight;

        if ($sourceRatio > $targetRatio) {
            $cropWidth = (int) round($srcHeight * $targetRatio);
            $cropHeight = $srcHeight;
            $srcX = max(0, (int) floor(($srcWidth - $cropWidth) / 2));
            $srcY = 0;
        } else {
            $cropWidth = $srcWidth;
            $cropHeight = (int) round($srcWidth / $targetRatio);
            $srcX = 0;
            $srcY = max(0, (int) floor(($srcHeight - $cropHeight) / 2));
        }

        $thumb = imagecreatetruecolor(THUMB_WIDTH, THUMB_HEIGHT);
        $black = imagecolorallocate($thumb, 0, 0, 0);
        imagefill($thumb, 0, 0, $black);
        imagecopyresampled(
            $thumb,
            $source,
            0,
            0,
            $srcX,
            $srcY,
            THUMB_WIDTH,
            THUMB_HEIGHT,
            $cropWidth,
            $cropHeight
        );

        $result = app_save_jpeg($thumb, $thumbPath, 86);
        imagedestroy($source);
        imagedestroy($thumb);
        return $result;
    }

    return false;
}

function app_detect_image_mime(string $path): string
{
    if (function_exists('finfo_open')) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo) {
            $mime = (string) finfo_file($finfo, $path);
            finfo_close($finfo);
            if ($mime !== '') {
                return $mime;
            }
        }
    }

    $info = @getimagesize($path);
    if (is_array($info) && isset($info['mime'])) {
        return (string) $info['mime'];
    }

    return '';
}

function app_h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
