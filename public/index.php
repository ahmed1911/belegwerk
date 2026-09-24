<?php

declare(strict_types=1);

use Belegwerk\Gotenberg\GotenbergClient;
use Belegwerk\Http\App;

require dirname(__DIR__) . '/vendor/autoload.php';

$app = new App(new GotenbergClient(), getenv('BELEGWERK_API_KEY') ?: null);

$app->handle(
    $_SERVER['REQUEST_METHOD'] ?? 'GET',
    parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/',
    $_GET,
    (string) file_get_contents('php://input'),
    $_SERVER['HTTP_AUTHORIZATION'] ?? null,
)->send();
