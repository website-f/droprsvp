<?php

/*
|--------------------------------------------------------------------------
| DropRSVP — root front controller for shared hosting (cPanel)
|--------------------------------------------------------------------------
| Copy this file to your web root as  public_html/index.php  when your host
| forces the document root to be public_html and you cannot point it at the
| app's own public/ folder.
|
| It boots the Laravel app that lives in a SEPARATE folder and tells Laravel
| that THIS directory (public_html) is the public path — so /build assets,
| /storage uploads, favicons and SEO all resolve at the domain root with no
| 404s.
|
| Set $appRoot to where you cloned the repo:
|   - App ABOVE the web root (recommended, most secure):  __DIR__.'/../droprsvp'
|   - App inside the web root:                            __DIR__.'/droprsvp'
| See DEPLOY.md for the matching folder layout + the one symlink/copy step.
|--------------------------------------------------------------------------
*/

use Illuminate\Foundation\Application;
use Illuminate\Http\Request;

define('LARAVEL_START', microtime(true));

$appRoot = __DIR__.'/../droprsvp';   // <-- EDIT THIS to your clone location

if (! is_dir($appRoot)) {
    http_response_code(500);
    exit('DropRSVP: $appRoot in index.php does not point at the app folder.');
}

// Maintenance mode...
if (file_exists($maintenance = $appRoot.'/storage/framework/maintenance.php')) {
    require $maintenance;
}

// Composer autoloader...
require $appRoot.'/vendor/autoload.php';

// Bootstrap Laravel...
/** @var Application $app */
$app = require_once $appRoot.'/bootstrap/app.php';

// Serve public assets (build/, storage/, icons) from THIS directory.
$app->usePublicPath(__DIR__);

$app->handleRequest(Request::capture());
