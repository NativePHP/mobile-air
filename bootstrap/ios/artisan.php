<?php

use Symfony\Component\Console\Input\ArgvInput;

define('LARAVEL_START', microtime(true));

// Register the Composer autoloader...
require __DIR__.'/../../../../autoload.php';

// Bootstrap Laravel and handle the command...
$app = require_once __DIR__.'/../../../../../bootstrap/app.php';

require_once __DIR__.'/../shared/bundle-paths.php';
nativephp_use_bundle_paths($app);

$status = $app->handleCommand(new ArgvInput);

exit($status);
