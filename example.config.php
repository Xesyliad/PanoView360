<?php
declare(strict_types=1);

define('APP_ROOT', __DIR__);
define('DATA_DIR', APP_ROOT . '/data');
define('UPLOAD_DIR', APP_ROOT . '/uploads');
define('PANORAMA_DIR', UPLOAD_DIR . '/panoramas');
define('THUMB_DIR', UPLOAD_DIR . '/thumbnails');
define('DATA_FILE', DATA_DIR . '/panoramas.json');

define('MAX_UPLOAD_BYTES', 100 * 1024 * 1024);
define('THUMB_WIDTH', 400);
define('THUMB_HEIGHT', 225);
define('ASSET_VERSION', '20260627a');

// Set a production password hash before use.
define('ADMIN_PASSWORD_HASH', 'CHANGE_ME');
