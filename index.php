<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/gallery.php';

app_bootstrap();
$state = app_load_library_state();
render_public_gallery($state);
