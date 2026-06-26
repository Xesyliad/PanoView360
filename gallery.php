<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

function render_public_gallery(array $items): void
{
    $assetPrefix = '';
    $flash = app_flash_get();
    ?>
    <!doctype html>
    <html lang="en">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>360 Panorama Gallery</title>
        <link rel="stylesheet" href="<?= app_h($assetPrefix) ?>assets/css/app.css?v=<?= app_h(ASSET_VERSION) ?>">
        <link rel="stylesheet" href="<?= app_h($assetPrefix) ?>assets/vendor/pannellum/pannellum.css?v=<?= app_h(ASSET_VERSION) ?>">
        <link rel="stylesheet" href="<?= app_h($assetPrefix) ?>assets/vendor/leaflet/leaflet.css?v=<?= app_h(ASSET_VERSION) ?>">
    </head>
    <body class="gallery-page">
        <main class="gallery-shell">
            <?php if ($flash): ?>
                <div class="flash flash-<?= app_h($flash['type']) ?>"><?= app_h($flash['message']) ?></div>
            <?php endif; ?>
            <section class="gallery-grid" aria-label="Panorama gallery">
                <?php foreach ($items as $item): ?>
                    <?php
                        $thumb = $item['thumbnail'] ?? '';
                        $image = $item['image_path'] ?? '';
                        $title = $item['title'] ?? 'Untitled Panorama';
                        $pitch = (string) ($item['default_pitch'] ?? 0);
                        $yaw = (string) ($item['default_yaw'] ?? 0);
                        $fov = (string) ($item['default_fov'] ?? 100);
                        $lat = $item['location_lat'] ?? '';
                        $lng = $item['location_lng'] ?? '';
                        if ($lat === '' || $lng === '') {
                            $sourcePath = APP_ROOT . '/' . ltrim((string) $image, '/');
                            $location = is_file($sourcePath) ? app_extract_gps_coordinates($sourcePath) : null;
                            if (is_array($location)) {
                                $lat = (string) $location['lat'];
                                $lng = (string) $location['lng'];
                            }
                        }
                    ?>
                    <button
                        type="button"
                        class="thumb-card"
                        data-title="<?= app_h($title) ?>"
                        data-image="<?= app_h($image) ?>"
                        data-full-title="<?= app_h($title) ?>"
                        data-pitch="<?= app_h($pitch) ?>"
                        data-yaw="<?= app_h($yaw) ?>"
                        data-fov="<?= app_h($fov) ?>"
                        data-lat="<?= app_h((string) $lat) ?>"
                        data-lng="<?= app_h((string) $lng) ?>"
                        aria-label="Open <?= app_h($title) ?>"
                    >
                        <img src="<?= app_h($thumb) ?>" alt="<?= app_h($title) ?>" loading="lazy">
                    </button>
                <?php endforeach; ?>
            </section>
        </main>

        <div class="lightbox" id="lightbox" aria-hidden="true">
            <div class="lightbox-backdrop" data-close></div>
            <div class="lightbox-panel" role="dialog" aria-modal="true" aria-label="Panorama viewer">
                <button type="button" class="lightbox-close" id="lightboxClose" aria-label="Close panorama">X</button>
                <div class="viewer-stage">
                    <div id="viewerStage" class="pano-viewer"></div>
                    <div class="location-overlay" id="locationOverlay" aria-label="Location preview">
                        <a class="location-link" id="locationLink" href="#" target="_blank" rel="noopener noreferrer" hidden>
                            <div class="location-map" id="locationMap"></div>
                        </a>
                        <div class="location-empty" id="locationEmpty">No Location</div>
                    </div>
                </div>
            </div>
        </div>

        <script src="<?= app_h($assetPrefix) ?>assets/vendor/pannellum/pannellum.js?v=<?= app_h(ASSET_VERSION) ?>" defer></script>
        <script src="<?= app_h($assetPrefix) ?>assets/vendor/leaflet/leaflet.js?v=<?= app_h(ASSET_VERSION) ?>" defer></script>
        <script src="<?= app_h($assetPrefix) ?>assets/js/viewer.js?v=<?= app_h(ASSET_VERSION) ?>" defer></script>
    </body>
    </html>
    <?php
}
