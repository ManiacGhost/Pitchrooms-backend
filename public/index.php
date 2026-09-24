<?php

declare(strict_types=1);

/**
 * PitchRooms API — front controller.
 *
 * On Hostinger, point the api subdomain's document root at this `public`
 * directory. Everything else (src, storage, .env) then sits outside the
 * webroot and is not reachable over HTTP.
 */

use PitchRooms\Core\Autoloader;
use PitchRooms\Core\Kernel;
use PitchRooms\Core\Request;

$basePath = dirname(__DIR__);

require $basePath . '/src/Core/Autoloader.php';
Autoloader::register($basePath . '/src');

$kernel = Kernel::boot($basePath);
$response = $kernel->handle(Request::capture());
$response->send();

// Response is flushed first; due scheduled jobs then run in this same
// process. That is what replaces hosting-panel cron.
$kernel->terminate();
