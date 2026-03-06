<?php

require_once __DIR__ . '/../vendor/autoload.php';

use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Dotenv\Dotenv;

// Load environment variables
$dotenv = Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->safeLoad();

// Start session
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

// Setup Twig
$loader = new FilesystemLoader(__DIR__ . '/../resources/views');
$twig = new Environment($loader, [
    'cache' => false, // Set to __DIR__ . '/../storage/cache' in production
    'debug' => true,
]);

// Check if user is logged in
require_once __DIR__ . '/../controllers/Authcontroller.php';
$isLoggedIn = AuthController::isLoggedIn();

// Basic routing (very simple for now)
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$action = $_GET['action'] ?? null;

// Public pages (no login required)
if ($action === 'login' || $action === 'register') {
    if ($action === 'login') {
        require __DIR__ . '/login.php';
    } elseif ($action === 'register') {
        require __DIR__ . '/register.php';
    }
} else {
    // Protected pages - require login
    if (!$isLoggedIn) {
        header('Location: /index.php?action=login');
        exit;
    }

    // Authenticated user pages
    if ($path === '/' || $path === '/index.php') {
        echo $twig->render('home.twig', [
            'title' => 'AVG Consent - Toestemmingsbeheer vereenvoudigd',
        ]);
    } else {
        header("HTTP/1.0 404 Not Found");
        echo "404 Not Found";
    }
}
