<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/gallery.php';

app_bootstrap();
$items = app_load_panoramas();
render_public_gallery($items);

