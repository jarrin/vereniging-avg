<?php

require_once __DIR__ . '/../vendor/autoload.php';

use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Dotenv\Dotenv;

// Load environment variables
$dotenv = Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->safeLoad();

// Setup Twig
$loader = new FilesystemLoader(__DIR__ . '/../resources/views');
$twig = new Environment($loader, [
    'cache' => false, // Set to __DIR__ . '/../storage/cache' in production
    'debug' => true,
]);

// Basic routing (very simple for now)
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

if ($path === '/' || $path === '/index.php') {
    echo $twig->render('home.twig', [
        'title' => 'AVG Consent - Toestemmingsbeheer vereenvoudigd',
    ]);
} elseif ($path === '/dashboard') {
    echo $twig->render('dashboard.twig', [
        'title' => 'Dashboard - AVG Consent',
    ]);
} elseif ($path === '/campagne/nieuw') {
    header('Location: /campagne/form/verenigingsgegevens');
    exit;
} elseif ($path === '/campagne/form/verenigingsgegevens') {
    echo $twig->render('nieuwe_campagne/verenigingsgegevens.twig', [
        'title' => 'Verenigingsgegevens - AVG Consent',
    ]);
} elseif ($path === '/campagne/form/vragen') {
    echo $twig->render('nieuwe_campagne/vragen.twig', [
        'title' => 'Vragen - AVG Consent',
    ]);
} elseif ($path === '/campagne/form/email-tekst') {
    echo $twig->render('nieuwe_campagne/stap_3_email.twig', [
        'title' => 'E-mail Tekst - AVG Consent',
    ]);
} elseif ($path === '/campagne/form/ledenlijst') {
    echo $twig->render('nieuwe_campagne/stap_4_ledenlijst.twig', [
        'title' => 'Ledenlijst - AVG Consent',
    ]);
} else {
    header("HTTP/1.0 404 Not Found");
    echo "404 Not Found";
}
