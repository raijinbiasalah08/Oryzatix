<?php

use Illuminate\Foundation\Application;
use Illuminate\Http\Request;

define('LARAVEL_START', microtime(true));

// Force HTTPS behind Vercel edge proxy
if (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https') {
    $_SERVER['HTTPS'] = 'on';
    $_SERVER['SERVER_PORT'] = 443;
}

// Prepare storage directories in /tmp for Vercel serverless environment
$tmpStorage = '/tmp/storage';
if (!is_dir($tmpStorage)) {
    @mkdir($tmpStorage, 0755, true);
    @mkdir($tmpStorage . '/framework/views', 0755, true);
    @mkdir($tmpStorage . '/framework/cache', 0755, true);
    @mkdir($tmpStorage . '/framework/sessions', 0755, true);
    @mkdir($tmpStorage . '/logs', 0755, true);
    @mkdir($tmpStorage . '/app/public', 0755, true);
}

// Ensure SSL CA certificate is available in /tmp for serverless cloud MySQL
$caDest = '/tmp/ca.pem';
if (!file_exists($caDest)) {
    $caCandidates = [
        __DIR__ . '/../database/certs/ca.pem',
        __DIR__ . '/../storage/certs/ca.pem',
    ];
    foreach ($caCandidates as $c) {
        if (file_exists($c)) {
            @copy($c, $caDest);
            break;
        }
    }
}

// Register the Composer autoloader...
require __DIR__ . '/../vendor/autoload.php';

// Bootstrap Laravel and handle the request...
/** @var Application $app */
$app = require_once __DIR__ . '/../bootstrap/app.php';

$app->handleRequest(Request::capture());
