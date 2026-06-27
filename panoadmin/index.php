<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';

app_bootstrap();

if (isset($_POST['logout']) && app_verify_csrf($_POST['csrf'] ?? null)) {
    app_logout_admin();
    app_flash_set('success', 'Signed out.');
    header('Location: /panoadmin/');
    exit;
}

if (!app_is_admin() && isset($_POST['password'])) {
    if (app_login_admin((string) $_POST['password'])) {
        app_flash_set('success', 'Signed in.');
        header('Location: /panoadmin/');
        exit;
    }
    app_flash_set('error', 'Invalid password.');
    header('Location: /panoadmin/');
    exit;
}

if (app_is_admin()) {
    if (isset($_POST['action']) && !app_verify_csrf($_POST['csrf'] ?? null)) {
        app_flash_set('error', 'Invalid security token.');
        header('Location: /panoadmin/');
        exit;
    } elseif (isset($_POST['action'])) {
        $action = (string) $_POST['action'];
        $items = app_load_panoramas();

        if ($action === 'upload' && isset($_FILES['panorama'])) {
            $upload = $_FILES['panorama'];
            $title = app_sanitize_title((string) ($_POST['title'] ?? ''));

            if (!is_array($upload) || ($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                $error = 'Upload failed.';
            } elseif (($upload['size'] ?? 0) > MAX_UPLOAD_BYTES) {
                $error = 'File is too large.';
            } else {
                $originalName = (string) ($upload['name'] ?? 'panorama');
                $tmpPath = (string) ($upload['tmp_name'] ?? '');
                $mime = app_detect_image_mime($tmpPath);

                $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
                if (!app_allowed_image_extension($extension) || !app_allowed_image_mime($mime)) {
                    $error = 'Only JPG, PNG, WEBP, and GIF files are allowed.';
                } elseif (!is_uploaded_file($tmpPath)) {
                    $error = 'Invalid upload source.';
                } else {
                    $safeName = app_sanitize_filename($originalName, $extension);
                    $fileBase = pathinfo($safeName, PATHINFO_FILENAME);
                    $targetPath = PANORAMA_DIR . '/' . $safeName;
                    $thumbName = $fileBase . '.jpg';
                    $thumbPath = THUMB_DIR . '/' . $thumbName;
                    $defaultPitch = app_parse_float($_POST['default_pitch'] ?? null, 0.0, -90.0, 90.0);
                    $defaultYaw = app_parse_float($_POST['default_yaw'] ?? null, 0.0, -180.0, 180.0);
                    $defaultFov = app_parse_float($_POST['default_fov'] ?? null, 100.0, 30.0, 120.0);

                    if (!move_uploaded_file($tmpPath, $targetPath)) {
                        $error = 'Unable to save the uploaded file.';
                    } elseif (!app_create_thumbnail($targetPath, $thumbPath, $mime, $defaultPitch, $defaultYaw, $defaultFov)) {
                        @unlink($targetPath);
                        $error = 'Uploaded, but thumbnail generation failed.';
                    } else {
                        $location = app_extract_gps_coordinates($targetPath);
                        $nextOrder = count($items) + 1;
                        $item = [
                            'id' => bin2hex(random_bytes(8)),
                            'filename' => $safeName,
                            'thumbnail' => 'uploads/thumbnails/' . $thumbName,
                            'image_path' => 'uploads/panoramas/' . $safeName,
                            'title' => $title !== '' ? $title : app_title_from_filename($originalName),
                            'display_order' => $nextOrder,
                            'upload_date' => gmdate(DATE_ATOM),
                            'default_pitch' => $defaultPitch,
                            'default_yaw' => $defaultYaw,
                            'default_fov' => $defaultFov,
                        ];
                        if (is_array($location)) {
                            $item['location_lat'] = $location['lat'];
                            $item['location_lng'] = $location['lng'];
                        }
                        $items[] = $item;
                        if (!app_save_panoramas($items)) {
                            $error = 'Uploaded, but metadata save failed.';
                        } else {
                            app_flash_set('success', 'Panorama uploaded.');
                            header('Location: /panoadmin/');
                            exit;
                        }
                    }
                }
            }
            app_flash_set('error', $error ?: 'Upload failed.');
            header('Location: /panoadmin/');
            exit;
        } elseif ($action === 'update_title') {
            $id = (string) ($_POST['id'] ?? '');
            $title = app_sanitize_title((string) ($_POST['title'] ?? ''));
            $index = app_find_pano_index($items, $id);
            if ($index >= 0) {
                $items[$index]['title'] = $title;
                app_save_panoramas($items);
                app_flash_set('success', 'Title updated.');
                header('Location: /panoadmin/');
                exit;
            }
            app_flash_set('error', 'Panorama not found.');
            header('Location: /panoadmin/');
            exit;
        } elseif ($action === 'move') {
            $id = (string) ($_POST['id'] ?? '');
            $direction = (string) ($_POST['direction'] ?? '');
            $index = app_find_pano_index($items, $id);
            if ($index >= 0) {
                $swap = $direction === 'up' ? $index - 1 : $index + 1;
                if (isset($items[$swap])) {
                    [$items[$index], $items[$swap]] = [$items[$swap], $items[$index]];
                    app_save_panoramas($items);
                    app_flash_set('success', 'Display order updated.');
                    header('Location: /panoadmin/');
                    exit;
                }
            }
            app_flash_set('error', 'Unable to reorder that item.');
            header('Location: /panoadmin/');
            exit;
        } elseif ($action === 'delete') {
            $id = (string) ($_POST['id'] ?? '');
            $index = app_find_pano_index($items, $id);
            if ($index >= 0) {
                $item = $items[$index];
                @unlink(PANORAMA_DIR . '/' . ($item['filename'] ?? ''));
                $thumbPath = APP_ROOT . '/' . ltrim((string) ($item['thumbnail'] ?? ''), '/');
                @unlink($thumbPath);
                array_splice($items, $index, 1);
                app_save_panoramas($items);
                app_flash_set('success', 'Panorama deleted.');
                header('Location: /panoadmin/');
                exit;
            }
            app_flash_set('error', 'Panorama not found.');
            header('Location: /panoadmin/');
            exit;
        }
    }
}

$flash = app_flash_get();
$items = app_load_panoramas();
$loggedIn = app_is_admin();
$csrf = app_csrf_token();
$assetPrefix = '../';
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Panorama Admin</title>
    <link rel="stylesheet" href="../assets/css/app.css">
</head>
<body class="admin-page">
    <main class="admin-shell">
        <header class="admin-header">
            <h1>Panorama Admin</h1>
            <?php if ($loggedIn): ?>
                <form method="post" class="inline-form">
                    <input type="hidden" name="csrf" value="<?= app_h($csrf) ?>">
                    <input type="hidden" name="logout" value="1">
                    <button type="submit" class="button button-ghost">Sign out</button>
                </form>
            <?php endif; ?>
        </header>

        <?php if ($flash): ?>
            <div class="flash flash-<?= app_h($flash['type']) ?>"><?= app_h($flash['message']) ?></div>
        <?php endif; ?>

        <?php if (!$loggedIn): ?>
            <section class="panel auth-panel">
                <form method="post" class="stack-form">
                    <label class="field">
                        <span>Password</span>
                        <input type="password" name="password" autocomplete="current-password" required>
                    </label>
                    <button type="submit" class="button">Sign in</button>
                </form>
            </section>
        <?php else: ?>
            <section class="panel">
                <h2>Upload panorama</h2>
                <form method="post" enctype="multipart/form-data" class="stack-form" action="/panoadmin/" autocomplete="off">
                    <input type="hidden" name="csrf" value="<?= app_h($csrf) ?>">
                    <input type="hidden" name="action" value="upload">
                    <label class="field">
                        <span>Title</span>
                        <input type="text" name="title" maxlength="120" placeholder="Optional title">
                    </label>
                    <div class="field-grid">
                        <label class="field">
                            <span>Start pitch</span>
                            <input type="number" name="default_pitch" step="0.1" min="-90" max="90" value="0">
                        </label>
                        <label class="field">
                            <span>Start yaw</span>
                            <input type="number" name="default_yaw" step="0.1" min="-180" max="180" value="0">
                        </label>
                        <label class="field">
                            <span>Start FOV</span>
                            <input type="number" name="default_fov" step="0.1" min="30" max="120" value="100">
                        </label>
                    </div>
                    <label class="field">
                        <span>Image file</span>
                        <input type="file" name="panorama" accept="image/jpeg,image/png,image/webp,image/gif" required>
                    </label>
                    <button type="submit" class="button">Upload</button>
                </form>
            </section>

            <section class="panel">
                <h2>Library</h2>
                <?php if (!$items): ?>
                    <p class="muted">No panoramas uploaded yet.</p>
                <?php else: ?>
                    <div class="admin-list">
                        <?php foreach ($items as $item): ?>
                            <?php
                                $id = (string) $item['id'];
                                $title = (string) ($item['title'] ?? 'Untitled Panorama');
                            ?>
                            <article class="admin-row">
                                <img src="../<?= app_h($item['thumbnail']) ?>" alt="" class="admin-thumb">
                                <div class="admin-meta">
                                    <form method="post" class="inline-form title-form">
                                        <input type="hidden" name="csrf" value="<?= app_h($csrf) ?>">
                                        <input type="hidden" name="action" value="update_title">
                                        <input type="hidden" name="id" value="<?= app_h($id) ?>">
                                        <input type="text" name="title" value="<?= app_h($title) ?>" maxlength="120">
                                        <button type="submit" class="button button-ghost">Save</button>
                                    </form>
                                    <div class="admin-paths">
                                        <code><?= app_h((string) ($item['image_path'] ?? '')) ?></code>
                                    </div>
                                </div>
                                <div class="admin-actions">
                                    <form method="post" class="inline-form">
                                        <input type="hidden" name="csrf" value="<?= app_h($csrf) ?>">
                                        <input type="hidden" name="action" value="move">
                                        <input type="hidden" name="id" value="<?= app_h($id) ?>">
                                        <input type="hidden" name="direction" value="up">
                                        <button type="submit" class="button button-ghost">Up</button>
                                    </form>
                                    <form method="post" class="inline-form">
                                        <input type="hidden" name="csrf" value="<?= app_h($csrf) ?>">
                                        <input type="hidden" name="action" value="move">
                                        <input type="hidden" name="id" value="<?= app_h($id) ?>">
                                        <input type="hidden" name="direction" value="down">
                                        <button type="submit" class="button button-ghost">Down</button>
                                    </form>
                                    <form method="post" class="inline-form" onsubmit="return confirm('Delete this panorama?');">
                                        <input type="hidden" name="csrf" value="<?= app_h($csrf) ?>">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="id" value="<?= app_h($id) ?>">
                                        <button type="submit" class="button button-danger">Delete</button>
                                    </form>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </section>
        <?php endif; ?>
    </main>
</body>
</html>
