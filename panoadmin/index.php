<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';

function admin_next_item_order(array $panoramas, string $galleryId): int
{
    $max = 0;
    foreach ($panoramas as $item) {
        if (!is_array($item)) {
            continue;
        }

        if ((string) ($item['gallery_id'] ?? '') !== $galleryId) {
            continue;
        }

        $max = max($max, (int) ($item['display_order'] ?? 0));
    }

    return $max + 1;
}

function admin_move_item_in_list(array &$panoramas, string $id, string $direction): bool
{
    $index = app_find_pano_index($panoramas, $id);
    if ($index < 0) {
        return false;
    }

    $swap = $direction === 'up' ? $index - 1 : $index + 1;
    if (!isset($panoramas[$swap])) {
        return false;
    }

    if ((string) ($panoramas[$swap]['gallery_id'] ?? '') !== (string) ($panoramas[$index]['gallery_id'] ?? '')) {
        return false;
    }

    [$panoramas[$index], $panoramas[$swap]] = [$panoramas[$swap], $panoramas[$index]];
    return true;
}

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

if (app_is_admin() && isset($_POST['action'])) {
    if (!app_verify_csrf($_POST['csrf'] ?? null)) {
        app_flash_set('error', 'Invalid security token.');
        header('Location: /panoadmin/');
        exit;
    }

    $state = app_load_library_state();
    $action = (string) $_POST['action'];
    $saved = false;
    $error = null;

    if ($action === 'add_gallery') {
        $state['galleries'][] = [
            'id' => bin2hex(random_bytes(8)),
            'name' => app_sanitize_gallery_name((string) ($_POST['name'] ?? '')),
            'display_order' => count($state['galleries']) + 1,
        ];
        $saved = app_save_library_state($state);
        $error = $saved ? null : 'Unable to save the new gallery.';
    } elseif ($action === 'update_gallery') {
        $id = (string) ($_POST['id'] ?? '');
        $index = app_find_gallery_index($state['galleries'], $id);
        if ($index >= 0) {
            $state['galleries'][$index]['name'] = app_sanitize_gallery_name((string) ($_POST['name'] ?? ''));
            $saved = app_save_library_state($state);
            $error = $saved ? null : 'Unable to update that gallery.';
        } else {
            $error = 'Gallery not found.';
        }
    } elseif ($action === 'move_gallery') {
        $id = (string) ($_POST['id'] ?? '');
        $direction = (string) ($_POST['direction'] ?? '');
        $index = app_find_gallery_index($state['galleries'], $id);
        if ($index >= 0) {
            $swap = $direction === 'up' ? $index - 1 : $index + 1;
            if (isset($state['galleries'][$swap])) {
                [$state['galleries'][$index], $state['galleries'][$swap]] = [$state['galleries'][$swap], $state['galleries'][$index]];
                $saved = app_save_library_state($state);
                $error = $saved ? null : 'Unable to reorder galleries.';
            } else {
                $error = 'Unable to reorder galleries.';
            }
        } else {
            $error = 'Gallery not found.';
        }
    } elseif ($action === 'delete_gallery') {
        $id = (string) ($_POST['id'] ?? '');
        $index = app_find_gallery_index($state['galleries'], $id);
        if ($index >= 0) {
            array_splice($state['galleries'], $index, 1);
            foreach ($state['panoramas'] as &$item) {
                if (is_array($item) && (string) ($item['gallery_id'] ?? '') === $id) {
                    $item['gallery_id'] = '';
                }
            }
            unset($item);
            $saved = app_save_library_state($state);
            $error = $saved ? null : 'Unable to delete that gallery.';
        } else {
            $error = 'Gallery not found.';
        }
    } elseif ($action === 'upload' && isset($_FILES['panorama'])) {
        $upload = $_FILES['panorama'];
        $title = app_sanitize_title((string) ($_POST['title'] ?? ''));
        $galleryId = trim((string) ($_POST['gallery_id'] ?? ''));
        if ($galleryId !== '' && app_find_gallery_index($state['galleries'], $galleryId) < 0) {
            $galleryId = '';
        }

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
                    $item = [
                        'id' => bin2hex(random_bytes(8)),
                        'filename' => $safeName,
                        'thumbnail' => 'uploads/thumbnails/' . $thumbName,
                        'image_path' => 'uploads/panoramas/' . $safeName,
                        'title' => $title !== '' ? $title : app_title_from_filename($originalName),
                        'gallery_id' => $galleryId,
                        'display_order' => admin_next_item_order($state['panoramas'], $galleryId),
                        'upload_date' => gmdate(DATE_ATOM),
                        'default_pitch' => $defaultPitch,
                        'default_yaw' => $defaultYaw,
                        'default_fov' => $defaultFov,
                        'viewer_mode' => app_detect_viewer_mode_for_path($targetPath),
                    ];
                    if (is_array($location)) {
                        $item['location_lat'] = $location['lat'];
                        $item['location_lng'] = $location['lng'];
                    }
                    $state['panoramas'][] = $item;
                    $saved = app_save_library_state($state);
                    $error = $saved ? null : 'Uploaded, but metadata save failed.';
                }
            }
        }
    } elseif ($action === 'update_item') {
        $id = (string) ($_POST['id'] ?? '');
        $index = app_find_pano_index($state['panoramas'], $id);
        if ($index >= 0) {
            $currentGalleryId = (string) ($state['panoramas'][$index]['gallery_id'] ?? '');
            $newGalleryId = trim((string) ($_POST['gallery_id'] ?? ''));
            if ($newGalleryId !== '' && app_find_gallery_index($state['galleries'], $newGalleryId) < 0) {
                $newGalleryId = '';
            }

            $state['panoramas'][$index]['title'] = app_sanitize_title((string) ($_POST['title'] ?? ''));
            if ($newGalleryId !== $currentGalleryId) {
                $state['panoramas'][$index]['gallery_id'] = $newGalleryId;
                $state['panoramas'][$index]['display_order'] = admin_next_item_order($state['panoramas'], $newGalleryId);
            }
            $saved = app_save_library_state($state);
            $error = $saved ? null : 'Unable to update that image.';
        } else {
            $error = 'Image not found.';
        }
    } elseif ($action === 'move_item') {
        $id = (string) ($_POST['id'] ?? '');
        $direction = (string) ($_POST['direction'] ?? '');
        if (admin_move_item_in_list($state['panoramas'], $id, $direction)) {
            $saved = app_save_library_state($state);
            $error = $saved ? null : 'Unable to reorder that image.';
        } else {
            $error = 'Unable to reorder that image.';
        }
    } elseif ($action === 'delete_item') {
        $id = (string) ($_POST['id'] ?? '');
        $index = app_find_pano_index($state['panoramas'], $id);
        if ($index >= 0) {
            $item = $state['panoramas'][$index];
            @unlink(PANORAMA_DIR . '/' . ($item['filename'] ?? ''));
            $thumbPath = APP_ROOT . '/' . ltrim((string) ($item['thumbnail'] ?? ''), '/');
            @unlink($thumbPath);
            array_splice($state['panoramas'], $index, 1);
            $saved = app_save_library_state($state);
            $error = $saved ? null : 'Unable to delete that image.';
        } else {
            $error = 'Image not found.';
        }
    }

    if ($saved) {
        app_flash_set('success', match ($action) {
            'add_gallery' => 'Gallery added.',
            'update_gallery' => 'Gallery updated.',
            'move_gallery' => 'Gallery order updated.',
            'delete_gallery' => 'Gallery deleted.',
            'upload' => 'Panorama uploaded.',
            'update_item' => 'Image updated.',
            'move_item' => 'Display order updated.',
            'delete_item' => 'Image deleted.',
            default => 'Saved.',
        });
    } else {
        app_flash_set('error', $error ?? 'Unable to save changes.');
    }

    header('Location: /panoadmin/');
    exit;
}

$flash = app_flash_get();
$state = app_load_library_state();
$loggedIn = app_is_admin();
$csrf = app_csrf_token();
$grouped = app_group_panoramas_by_gallery($state, true);
$assetPrefix = '../';
$galleryOptions = $state['galleries'] ?? [];
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
                <h2>Gallery management</h2>
                <form method="post" class="stack-form gallery-create-form">
                    <input type="hidden" name="csrf" value="<?= app_h($csrf) ?>">
                    <input type="hidden" name="action" value="add_gallery">
                    <label class="field">
                        <span>New gallery name</span>
                        <input type="text" name="name" maxlength="120" placeholder="Gallery name">
                    </label>
                    <button type="submit" class="button">Add gallery</button>
                </form>

                <?php if (!$galleryOptions): ?>
                    <p class="muted gallery-note">No galleries yet. Add one to make images visible on the public page.</p>
                <?php else: ?>
                    <div class="gallery-admin-list">
                        <?php foreach ($galleryOptions as $galleryIndex => $gallery): ?>
                            <?php
                                $galleryId = (string) ($gallery['id'] ?? '');
                                $galleryName = (string) ($gallery['name'] ?? 'Untitled Gallery');
                                $galleryCount = count($galleryOptions);
                                $canMoveUp = $galleryIndex > 0;
                                $canMoveDown = $galleryIndex < ($galleryCount - 1);
                            ?>
                            <article class="gallery-admin-row">
                                <form method="post" class="inline-form gallery-title-form">
                                    <input type="hidden" name="csrf" value="<?= app_h($csrf) ?>">
                                    <input type="hidden" name="action" value="update_gallery">
                                    <input type="hidden" name="id" value="<?= app_h($galleryId) ?>">
                                    <input type="text" name="name" value="<?= app_h($galleryName) ?>" maxlength="120">
                                    <button type="submit" class="button button-ghost">Save</button>
                                </form>
                                <div class="admin-actions">
                                    <form method="post" class="inline-form">
                                        <input type="hidden" name="csrf" value="<?= app_h($csrf) ?>">
                                        <input type="hidden" name="action" value="move_gallery">
                                        <input type="hidden" name="id" value="<?= app_h($galleryId) ?>">
                                        <input type="hidden" name="direction" value="up">
                                        <button type="submit" class="button button-ghost"<?= $canMoveUp ? '' : ' disabled' ?>>Up</button>
                                    </form>
                                    <form method="post" class="inline-form">
                                        <input type="hidden" name="csrf" value="<?= app_h($csrf) ?>">
                                        <input type="hidden" name="action" value="move_gallery">
                                        <input type="hidden" name="id" value="<?= app_h($galleryId) ?>">
                                        <input type="hidden" name="direction" value="down">
                                        <button type="submit" class="button button-ghost"<?= $canMoveDown ? '' : ' disabled' ?>>Down</button>
                                    </form>
                                    <form method="post" class="inline-form" onsubmit="return confirm('Delete this gallery? Images will move to Unassigned.');">
                                        <input type="hidden" name="csrf" value="<?= app_h($csrf) ?>">
                                        <input type="hidden" name="action" value="delete_gallery">
                                        <input type="hidden" name="id" value="<?= app_h($galleryId) ?>">
                                        <button type="submit" class="button button-danger">Delete</button>
                                    </form>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </section>

            <section class="panel">
                <h2>Upload panorama</h2>
                <form method="post" enctype="multipart/form-data" class="stack-form" action="/panoadmin/" autocomplete="off">
                    <input type="hidden" name="csrf" value="<?= app_h($csrf) ?>">
                    <input type="hidden" name="action" value="upload">
                    <label class="field">
                        <span>Title</span>
                        <input type="text" name="title" maxlength="120" placeholder="Optional title">
                    </label>
                    <label class="field">
                        <span>Gallery</span>
                        <select name="gallery_id">
                            <option value="">Unassigned / Hidden</option>
                            <?php foreach ($galleryOptions as $gallery): ?>
                                <option value="<?= app_h((string) ($gallery['id'] ?? '')) ?>"><?= app_h((string) ($gallery['name'] ?? 'Untitled Gallery')) ?></option>
                            <?php endforeach; ?>
                        </select>
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
                <?php if (!$state['panoramas']): ?>
                    <p class="muted">No panoramas uploaded yet.</p>
                <?php else: ?>
                    <?php foreach ($galleryOptions as $gallery): ?>
                        <?php
                            $galleryId = (string) ($gallery['id'] ?? '');
                            $galleryName = (string) ($gallery['name'] ?? 'Untitled Gallery');
                            $items = $grouped['galleries'][$galleryId]['panoramas'] ?? [];
                            if (!$items) {
                                continue;
                            }
                        ?>
                        <section class="library-section">
                            <div class="library-section-header">
                                <div>
                                    <div class="gallery-title"><?= app_h($galleryName) ?></div>
                                    <div class="muted"><?= count($items) ?> image<?= count($items) === 1 ? '' : 's' ?></div>
                                </div>
                            </div>
                            <div class="admin-list">
                                <?php foreach ($items as $itemIndex => $item): ?>
                                    <?php
                                        $id = (string) $item['id'];
                                        $title = (string) ($item['title'] ?? 'Untitled Panorama');
                                        $currentGalleryId = (string) ($item['gallery_id'] ?? '');
                                        $viewerMode = (string) ($item['viewer_mode'] ?? 'flat');
                                        $itemCount = count($items);
                                        $canMoveUp = $itemIndex > 0;
                                        $canMoveDown = $itemIndex < ($itemCount - 1);
                                    ?>
                                    <article class="admin-row">
                                        <img src="../<?= app_h((string) ($item['thumbnail'] ?? '')) ?>" alt="" class="admin-thumb">
                                        <div class="admin-meta">
                                            <form method="post" class="stack-form item-form">
                                                <input type="hidden" name="csrf" value="<?= app_h($csrf) ?>">
                                                <input type="hidden" name="action" value="update_item">
                                                <input type="hidden" name="id" value="<?= app_h($id) ?>">
                                                <label class="field">
                                                    <span>Title</span>
                                                    <input type="text" name="title" value="<?= app_h($title) ?>" maxlength="120">
                                                </label>
                                                <label class="field">
                                                    <span>Gallery</span>
                                                    <select name="gallery_id">
                                                        <option value=""<?= $currentGalleryId === '' ? ' selected' : '' ?>>Unassigned / Hidden</option>
                                                        <?php foreach ($galleryOptions as $galleryOption): ?>
                                                            <?php $optionId = (string) ($galleryOption['id'] ?? ''); ?>
                                                            <option value="<?= app_h($optionId) ?>"<?= $optionId === $currentGalleryId ? ' selected' : '' ?>>
                                                                <?= app_h((string) ($galleryOption['name'] ?? 'Untitled Gallery')) ?>
                                                            </option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                </label>
                                                <div class="inline-form">
                                                    <button type="submit" class="button button-ghost">Save</button>
                                                </div>
                                            </form>
                                            <div class="admin-paths">
                                                <code><?= app_h((string) ($item['image_path'] ?? '')) ?></code>
                                                <span class="muted">Mode: <?= app_h($viewerMode) ?></span>
                                            </div>
                                        </div>
                                        <div class="admin-actions">
                                            <form method="post" class="inline-form">
                                                <input type="hidden" name="csrf" value="<?= app_h($csrf) ?>">
                                                <input type="hidden" name="action" value="move_item">
                                                <input type="hidden" name="id" value="<?= app_h($id) ?>">
                                                <input type="hidden" name="direction" value="up">
                                                <button type="submit" class="button button-ghost"<?= $canMoveUp ? '' : ' disabled' ?>>Up</button>
                                            </form>
                                            <form method="post" class="inline-form">
                                                <input type="hidden" name="csrf" value="<?= app_h($csrf) ?>">
                                                <input type="hidden" name="action" value="move_item">
                                                <input type="hidden" name="id" value="<?= app_h($id) ?>">
                                                <input type="hidden" name="direction" value="down">
                                                <button type="submit" class="button button-ghost"<?= $canMoveDown ? '' : ' disabled' ?>>Down</button>
                                            </form>
                                            <form method="post" class="inline-form" onsubmit="return confirm('Delete this panorama?');">
                                                <input type="hidden" name="csrf" value="<?= app_h($csrf) ?>">
                                                <input type="hidden" name="action" value="delete_item">
                                                <input type="hidden" name="id" value="<?= app_h($id) ?>">
                                                <button type="submit" class="button button-danger">Delete</button>
                                            </form>
                                        </div>
                                    </article>
                                <?php endforeach; ?>
                            </div>
                        </section>
                    <?php endforeach; ?>

                    <?php if (!empty($grouped['unassigned'])): ?>
                        <section class="library-section library-section-unassigned">
                            <div class="library-section-header">
                                <div>
                                    <div class="gallery-title">Unassigned</div>
                                    <div class="muted"><?= count($grouped['unassigned']) ?> image<?= count($grouped['unassigned']) === 1 ? '' : 's' ?></div>
                                </div>
                            </div>
                            <div class="admin-list">
                                <?php foreach ($grouped['unassigned'] as $itemIndex => $item): ?>
                                    <?php
                                        $id = (string) $item['id'];
                                        $title = (string) ($item['title'] ?? 'Untitled Panorama');
                                        $viewerMode = (string) ($item['viewer_mode'] ?? 'flat');
                                        $itemCount = count($grouped['unassigned']);
                                        $canMoveUp = $itemIndex > 0;
                                        $canMoveDown = $itemIndex < ($itemCount - 1);
                                    ?>
                                    <article class="admin-row">
                                        <img src="../<?= app_h((string) ($item['thumbnail'] ?? '')) ?>" alt="" class="admin-thumb">
                                        <div class="admin-meta">
                                            <form method="post" class="stack-form item-form">
                                                <input type="hidden" name="csrf" value="<?= app_h($csrf) ?>">
                                                <input type="hidden" name="action" value="update_item">
                                                <input type="hidden" name="id" value="<?= app_h($id) ?>">
                                                <label class="field">
                                                    <span>Title</span>
                                                    <input type="text" name="title" value="<?= app_h($title) ?>" maxlength="120">
                                                </label>
                                                <label class="field">
                                                    <span>Gallery</span>
                                                    <select name="gallery_id">
                                                        <option value="" selected>Unassigned / Hidden</option>
                                                        <?php foreach ($galleryOptions as $galleryOption): ?>
                                                            <option value="<?= app_h((string) ($galleryOption['id'] ?? '')) ?>">
                                                                <?= app_h((string) ($galleryOption['name'] ?? 'Untitled Gallery')) ?>
                                                            </option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                </label>
                                                <div class="inline-form">
                                                    <button type="submit" class="button button-ghost">Save</button>
                                                </div>
                                            </form>
                                            <div class="admin-paths">
                                                <code><?= app_h((string) ($item['image_path'] ?? '')) ?></code>
                                                <span class="muted">Mode: <?= app_h($viewerMode) ?></span>
                                            </div>
                                        </div>
                                        <div class="admin-actions">
                                            <form method="post" class="inline-form">
                                                <input type="hidden" name="csrf" value="<?= app_h($csrf) ?>">
                                                <input type="hidden" name="action" value="move_item">
                                                <input type="hidden" name="id" value="<?= app_h($id) ?>">
                                                <input type="hidden" name="direction" value="up">
                                                <button type="submit" class="button button-ghost"<?= $canMoveUp ? '' : ' disabled' ?>>Up</button>
                                            </form>
                                            <form method="post" class="inline-form">
                                                <input type="hidden" name="csrf" value="<?= app_h($csrf) ?>">
                                                <input type="hidden" name="action" value="move_item">
                                                <input type="hidden" name="id" value="<?= app_h($id) ?>">
                                                <input type="hidden" name="direction" value="down">
                                                <button type="submit" class="button button-ghost"<?= $canMoveDown ? '' : ' disabled' ?>>Down</button>
                                            </form>
                                            <form method="post" class="inline-form" onsubmit="return confirm('Delete this panorama?');">
                                                <input type="hidden" name="csrf" value="<?= app_h($csrf) ?>">
                                                <input type="hidden" name="action" value="delete_item">
                                                <input type="hidden" name="id" value="<?= app_h($id) ?>">
                                                <button type="submit" class="button button-danger">Delete</button>
                                            </form>
                                        </div>
                                    </article>
                                <?php endforeach; ?>
                            </div>
                        </section>
                    <?php endif; ?>
                <?php endif; ?>
            </section>
        <?php endif; ?>
    </main>
</body>
</html>
