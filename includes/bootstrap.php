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
        file_put_contents(DATA_FILE, json_encode(app_library_default_state(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }
}

function app_library_default_state(): array
{
    return [
        'galleries' => [],
        'panoramas' => [],
    ];
}

function app_sanitize_gallery_name(string $value): string
{
    $value = trim(preg_replace('/\s+/', ' ', $value) ?? '');
    return $value !== '' ? $value : 'Untitled Gallery';
}

function app_is_panorama_ratio(int $width, int $height): bool
{
    if ($width <= 0 || $height <= 0) {
        return false;
    }

    return ($width / $height) >= 1.8;
}

function app_detect_viewer_mode_for_path(string $path): string
{
    if (!is_file($path)) {
        return 'flat';
    }

    $info = @getimagesize($path);
    if (!is_array($info)) {
        return 'flat';
    }

    $width = (int) ($info[0] ?? 0);
    $height = (int) ($info[1] ?? 0);
    return app_is_panorama_ratio($width, $height) ? 'panorama' : 'flat';
}

function app_load_library_state(): array
{
    if (!is_file(DATA_FILE)) {
        return app_library_default_state();
    }

    $raw = file_get_contents(DATA_FILE);
    if ($raw === false || trim($raw) === '') {
        return app_library_default_state();
    }

    $data = json_decode($raw, true);
    if (!is_array($data)) {
        return app_library_default_state();
    }

    return app_normalize_library_state($data);
}

function app_normalize_library_state(array $data): array
{
    if (array_is_list($data)) {
        $data = ['galleries' => [], 'panoramas' => $data];
    }

    $galleries = [];
    $galleryPosition = 0;
    foreach (($data['galleries'] ?? []) as $gallery) {
        if (!is_array($gallery)) {
            continue;
        }

        $galleryPosition++;
        $id = trim((string) ($gallery['id'] ?? ''));
        if ($id === '') {
            $id = bin2hex(random_bytes(8));
        }

        $name = app_sanitize_gallery_name((string) ($gallery['name'] ?? ''));
        $displayOrder = (int) ($gallery['display_order'] ?? $galleryPosition);
        $galleries[] = [
            'id' => $id,
            'name' => $name,
            'display_order' => $displayOrder,
        ];
    }

    usort($galleries, static fn (array $a, array $b): int => ($a['display_order'] ?? 0) <=> ($b['display_order'] ?? 0));
    $galleriesById = [];
    foreach ($galleries as $gallery) {
        $galleriesById[$gallery['id']] = $gallery;
    }

    $groupedPanoramas = [];
    $unassignedPanoramas = [];
    $panoPosition = 0;
    foreach (($data['panoramas'] ?? []) as $item) {
        if (!is_array($item)) {
            continue;
        }

        $panoPosition++;
        $id = trim((string) ($item['id'] ?? ''));
        if ($id === '') {
            $id = bin2hex(random_bytes(8));
        }

        $galleryId = trim((string) ($item['gallery_id'] ?? ''));
        if ($galleryId === '' || !isset($galleriesById[$galleryId])) {
            $galleryId = '';
        }

        $imagePath = (string) ($item['image_path'] ?? '');
        $viewerMode = (string) ($item['viewer_mode'] ?? '');
        if (!in_array($viewerMode, ['panorama', 'flat'], true)) {
            $viewerMode = $imagePath !== '' ? app_detect_viewer_mode_for_path(APP_ROOT . '/' . ltrim($imagePath, '/')) : 'flat';
        }

        $normalizedItem = $item;
        $normalizedItem['id'] = $id;
        $normalizedItem['gallery_id'] = $galleryId;
        $normalizedItem['viewer_mode'] = $viewerMode;
        $normalizedItem['display_order'] = (int) ($item['display_order'] ?? $panoPosition);

        if ($galleryId === '') {
            $unassignedPanoramas[] = $normalizedItem;
            continue;
        }

        $groupedPanoramas[$galleryId][] = $normalizedItem;
    }

    $panoramas = [];
    foreach ($galleries as $gallery) {
        $group = $groupedPanoramas[$gallery['id']] ?? [];
        usort($group, static fn (array $a, array $b): int => ($a['display_order'] ?? 0) <=> ($b['display_order'] ?? 0));
        foreach ($group as $index => $item) {
            $item['display_order'] = $index + 1;
            $panoramas[] = $item;
        }
    }

    usort($unassignedPanoramas, static fn (array $a, array $b): int => ($a['display_order'] ?? 0) <=> ($b['display_order'] ?? 0));
    foreach ($unassignedPanoramas as $index => $item) {
        $item['display_order'] = $index + 1;
        $panoramas[] = $item;
    }

    foreach ($galleries as $index => &$gallery) {
        $gallery['display_order'] = $index + 1;
    }
    unset($gallery);

    return [
        'galleries' => $galleries,
        'panoramas' => $panoramas,
    ];
}

function app_save_library_state(array $state): bool
{
    $state = app_normalize_library_state($state);
    $json = json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        return false;
    }

    $tmp = DATA_FILE . '.tmp';
    if (file_put_contents($tmp, $json, LOCK_EX) === false) {
        return false;
    }

    return rename($tmp, DATA_FILE);
}

function app_load_panoramas(): array
{
    return app_load_library_state()['panoramas'];
}

function app_save_panoramas(array $items): bool
{
    return app_save_library_state([
        'galleries' => [],
        'panoramas' => $items,
    ]);
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

function app_find_gallery_index(array $galleries, string $id): int
{
    foreach ($galleries as $index => $gallery) {
        if (($gallery['id'] ?? '') === $id) {
            return $index;
        }
    }

    return -1;
}

function app_item_viewer_mode(array $item): string
{
    $viewerMode = (string) ($item['viewer_mode'] ?? '');
    if (in_array($viewerMode, ['panorama', 'flat'], true)) {
        return $viewerMode;
    }

    $imagePath = (string) ($item['image_path'] ?? '');
    if ($imagePath === '') {
        return 'flat';
    }

    return app_detect_viewer_mode_for_path(APP_ROOT . '/' . ltrim($imagePath, '/'));
}

function app_group_panoramas_by_gallery(array $state, bool $includeUnassigned = true): array
{
    $galleries = $state['galleries'] ?? [];
    $panoramas = $state['panoramas'] ?? [];

    $galleryMap = [];
    foreach ($galleries as $gallery) {
        if (is_array($gallery) && isset($gallery['id'])) {
            $galleryMap[(string) $gallery['id']] = [
                'gallery' => $gallery,
                'panoramas' => [],
            ];
        }
    }

    $unassigned = [];
    foreach ($panoramas as $item) {
        if (!is_array($item)) {
            continue;
        }

        $galleryId = (string) ($item['gallery_id'] ?? '');
        if ($galleryId !== '' && isset($galleryMap[$galleryId])) {
            $galleryMap[$galleryId]['panoramas'][] = $item;
            continue;
        }

        if ($includeUnassigned) {
            $unassigned[] = $item;
        }
    }

    return [
        'galleries' => $galleryMap,
        'unassigned' => $unassigned,
    ];
}

function app_sanitize_title(string $value): string
{
    $value = trim(preg_replace('/\s+/', ' ', $value) ?? '');
    return $value !== '' ? $value : 'Untitled Panorama';
}

function app_parse_float(mixed $value, float $default, float $min, float $max): float
{
    if (is_string($value) || is_int($value) || is_float($value)) {
        $value = trim((string) $value);
        if ($value !== '' && is_numeric($value)) {
            return max($min, min($max, (float) $value));
        }
    }

    return max($min, min($max, $default));
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

function app_panorama_camera_fov(float $hfov, int $width, int $height): float
{
    $hfov = max(1.0, min(175.0, $hfov));
    $aspect = $width / max($height, 1);
    return rad2deg(2.0 * atan(tan(deg2rad($hfov) / 2.0) / $aspect));
}

function app_normalize_longitude(float $value): float
{
    $value = fmod($value + M_PI, M_PI * 2.0);
    if ($value < 0.0) {
        $value += M_PI * 2.0;
    }

    return $value - M_PI;
}

function app_sample_imagick_pixel(Imagick $source, int $srcWidth, int $srcHeight, float $x, float $y): array
{
    $x = (int) round($x);
    $y = (int) round($y);

    if ($srcWidth > 0) {
        $x %= $srcWidth;
        if ($x < 0) {
            $x += $srcWidth;
        }
    }

    if ($y < 0) {
        $y = 0;
    } elseif ($y >= $srcHeight) {
        $y = $srcHeight - 1;
    }

    $pixel = $source->getImagePixelColor($x, $y);
    $color = $pixel->getColor();

    return [
        (int) round((float) ($color['r'] ?? 0.0)),
        (int) round((float) ($color['g'] ?? 0.0)),
        (int) round((float) ($color['b'] ?? 0.0)),
    ];
}

function app_sample_gd_pixel($source, int $srcWidth, int $srcHeight, float $x, float $y): array
{
    $x0 = (int) floor($x);
    $y0 = (int) floor($y);
    $x1 = $x0 + 1;
    $y1 = $y0 + 1;

    if ($srcWidth > 0) {
        $x0 %= $srcWidth;
        if ($x0 < 0) {
            $x0 += $srcWidth;
        }
        $x1 %= $srcWidth;
        if ($x1 < 0) {
            $x1 += $srcWidth;
        }
    }

    if ($y0 < 0) {
        $y0 = 0;
    } elseif ($y0 >= $srcHeight) {
        $y0 = $srcHeight - 1;
    }

    if ($y1 < 0) {
        $y1 = 0;
    } elseif ($y1 >= $srcHeight) {
        $y1 = $srcHeight - 1;
    }

    $fx = $x - floor($x);
    $fy = $y - floor($y);

    if (imageistruecolor($source)) {
        $c00 = imagecolorat($source, $x0, $y0);
        $c10 = imagecolorat($source, $x1, $y0);
        $c01 = imagecolorat($source, $x0, $y1);
        $c11 = imagecolorat($source, $x1, $y1);

        $r = ((1 - $fx) * (1 - $fy) * (($c00 >> 16) & 0xFF))
            + ($fx * (1 - $fy) * (($c10 >> 16) & 0xFF))
            + ((1 - $fx) * $fy * (($c01 >> 16) & 0xFF))
            + ($fx * $fy * (($c11 >> 16) & 0xFF));

        $g = ((1 - $fx) * (1 - $fy) * (($c00 >> 8) & 0xFF))
            + ($fx * (1 - $fy) * (($c10 >> 8) & 0xFF))
            + ((1 - $fx) * $fy * (($c01 >> 8) & 0xFF))
            + ($fx * $fy * (($c11 >> 8) & 0xFF));

        $b = ((1 - $fx) * (1 - $fy) * ($c00 & 0xFF))
            + ($fx * (1 - $fy) * ($c10 & 0xFF))
            + ((1 - $fx) * $fy * ($c01 & 0xFF))
            + ($fx * $fy * ($c11 & 0xFF));
    } else {
        $c00 = imagecolorsforindex($source, imagecolorat($source, $x0, $y0));
        $c10 = imagecolorsforindex($source, imagecolorat($source, $x1, $y0));
        $c01 = imagecolorsforindex($source, imagecolorat($source, $x0, $y1));
        $c11 = imagecolorsforindex($source, imagecolorat($source, $x1, $y1));

        $r = ((1 - $fx) * (1 - $fy) * ($c00['red'] ?? 0))
            + ($fx * (1 - $fy) * ($c10['red'] ?? 0))
            + ((1 - $fx) * $fy * ($c01['red'] ?? 0))
            + ($fx * $fy * ($c11['red'] ?? 0));

        $g = ((1 - $fx) * (1 - $fy) * ($c00['green'] ?? 0))
            + ($fx * (1 - $fy) * ($c10['green'] ?? 0))
            + ((1 - $fx) * $fy * ($c01['green'] ?? 0))
            + ($fx * $fy * ($c11['green'] ?? 0));

        $b = ((1 - $fx) * (1 - $fy) * ($c00['blue'] ?? 0))
            + ($fx * (1 - $fy) * ($c10['blue'] ?? 0))
            + ((1 - $fx) * $fy * ($c01['blue'] ?? 0))
            + ($fx * $fy * ($c11['blue'] ?? 0));
    }

    return [(int) round($r), (int) round($g), (int) round($b)];
}

function app_render_panorama_thumbnail($source, int $srcWidth, int $srcHeight, string $thumbPath, float $pitch, float $yaw, float $hfov): bool
{
    $cameraHfov = deg2rad(max(1.0, min(175.0, $hfov)));
    $cameraVfov = deg2rad(app_panorama_camera_fov($hfov, THUMB_WIDTH, THUMB_HEIGHT));
    $tanHalfH = tan($cameraHfov / 2.0);
    $tanHalfV = tan($cameraVfov / 2.0);
    $yawRad = deg2rad($yaw);
    $pitchRad = deg2rad($pitch);

    if (class_exists('Imagick') && $source instanceof Imagick) {
        $thumb = new Imagick();
        $thumb->newImage(THUMB_WIDTH, THUMB_HEIGHT, new ImagickPixel('black'), 'jpeg');
        $thumb->setImageFormat('jpeg');
        $thumb->setImageCompressionQuality(86);

        $pixels = [];
        $pixelsSize = THUMB_WIDTH * THUMB_HEIGHT * 3;
        $pixels = array_fill(0, $pixelsSize, 0);
        $offset = 0;

        for ($y = 0; $y < THUMB_HEIGHT; $y++) {
            $v = (1.0 - (($y + 0.5) / THUMB_HEIGHT) * 2.0) * $tanHalfV;
            for ($x = 0; $x < THUMB_WIDTH; $x++) {
                $u = ((($x + 0.5) / THUMB_WIDTH) * 2.0 - 1.0) * $tanHalfH;
                $lon = app_normalize_longitude(atan2($u, 1.0) + $yawRad);
                $lat = max(-M_PI / 2.0, min(M_PI / 2.0, atan2($v, sqrt(($u * $u) + 1.0)) + $pitchRad));

                $srcX = (($lon + M_PI) / (M_PI * 2.0)) * $srcWidth;
                $srcY = ((M_PI / 2.0 - $lat) / M_PI) * $srcHeight;

                [$r, $g, $b] = app_sample_imagick_pixel($source, $srcWidth, $srcHeight, $srcX, $srcY);
                $pixels[$offset++] = $r;
                $pixels[$offset++] = $g;
                $pixels[$offset++] = $b;
            }
        }

        $thumb->importImagePixels(0, 0, THUMB_WIDTH, THUMB_HEIGHT, 'RGB', Imagick::PIXEL_CHAR, $pixels);
        $result = $thumb->writeImage($thumbPath);
        $thumb->clear();
        $thumb->destroy();
        return $result;
    }

    if (extension_loaded('gd') && $source) {
        $thumb = imagecreatetruecolor(THUMB_WIDTH, THUMB_HEIGHT);
        $black = imagecolorallocate($thumb, 0, 0, 0);
        imagefill($thumb, 0, 0, $black);

        for ($y = 0; $y < THUMB_HEIGHT; $y++) {
            $v = (1.0 - (($y + 0.5) / THUMB_HEIGHT) * 2.0) * $tanHalfV;
            for ($x = 0; $x < THUMB_WIDTH; $x++) {
                $u = ((($x + 0.5) / THUMB_WIDTH) * 2.0 - 1.0) * $tanHalfH;
                $lon = app_normalize_longitude(atan2($u, 1.0) + $yawRad);
                $lat = max(-M_PI / 2.0, min(M_PI / 2.0, atan2($v, sqrt(($u * $u) + 1.0)) + $pitchRad));

                $srcX = (($lon + M_PI) / (M_PI * 2.0)) * $srcWidth;
                $srcY = ((M_PI / 2.0 - $lat) / M_PI) * $srcHeight;

                [$r, $g, $b] = app_sample_gd_pixel($source, $srcWidth, $srcHeight, $srcX, $srcY);
                imagesetpixel($thumb, $x, $y, ($r << 16) | ($g << 8) | $b);
            }
        }

        $result = app_save_jpeg($thumb, $thumbPath, 86);
        imagedestroy($thumb);
        return $result;
    }

    return false;
}

function app_create_thumbnail(string $sourcePath, string $thumbPath, string $mime, float $pitch = 0.0, float $yaw = 0.0, float $hfov = 100.0): bool
{
    if (class_exists('Imagick')) {
        try {
            $image = new Imagick($sourcePath);
            $image->setImageColorspace(Imagick::COLORSPACE_SRGB);
            $srcWidth = $image->getImageWidth();
            $srcHeight = $image->getImageHeight();
            if ($srcWidth <= 0 || $srcHeight <= 0) {
                $image->clear();
                $image->destroy();
                return false;
            }

            $sourceRatio = $srcWidth / $srcHeight;
            if (app_is_panorama_ratio($srcWidth, $srcHeight)) {
                $result = app_render_panorama_thumbnail($image, $srcWidth, $srcHeight, $thumbPath, $pitch, $yaw, $hfov);
            } else {
                $image->setImageFormat('jpeg');
                $image->cropThumbnailImage(THUMB_WIDTH, THUMB_HEIGHT);
                $image->setImageCompressionQuality(86);
                $result = $image->writeImage($thumbPath);
            }
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

        $sourceRatio = $srcWidth / $srcHeight;
        if (app_is_panorama_ratio($srcWidth, $srcHeight)) {
            $result = app_render_panorama_thumbnail($source, $srcWidth, $srcHeight, $thumbPath, $pitch, $yaw, $hfov);
            imagedestroy($source);
            return $result;
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
