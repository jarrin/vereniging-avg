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
if ($action === 'login') {
    // Handle POST request for login
    $message = null;
    $messageType = null;
    $errors = null;
    
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $ctrl = new AuthController();
        $res = $ctrl->login($_POST);

        // Redirect on success
        if (!empty($res['success']) && $res['success'] === true) {
            header('Location: /index.php?action=dashboard');
            exit;
        }

        // Handle errors
        $message = $res['message'] ?? null;
        $messageType = $res['success'] ? 'success' : 'error';
        $errors = $res['errors'] ?? null;
    }
    
    echo $twig->render('login.twig', [
        'title' => 'Inloggen - AVG Consent',
        'message' => $message,
        'messageType' => $messageType,
        'errors' => $errors,
        'post' => $_POST,
    ]);
    exit;
} elseif ($action === 'register') {
    // Handle POST request for register
    $message = null;
    $messageType = null;
    $fieldErrors = [];
    
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $ctrl = new AuthController();
        $res = $ctrl->registerUser($_POST);

        // Redirect on success
        if (!empty($res['success']) && $res['success'] === true) {
            header('Location: /index.php?action=login&message=registration_success');
            exit;
        }

        // Handle errors
        $message = $res['message'] ?? null;
        $messageType = $res['success'] ? 'success' : 'error';
        if (!empty($res['errors'])) {
            if (!empty($res['errors']['username_exists'])) {
                $fieldErrors['username'] = 'Gebruikersnaam bestaat al.';
            }
            if (!empty($res['errors']['email_exists'])) {
                $fieldErrors['email'] = 'E-mail bestaat al.';
            }
            if (!empty($res['errors']['password_confirm'])) {
                $fieldErrors['password_confirm'] = 'Wachtwoorden komen niet overeen.';
            }
            if (!empty($res['errors']['password_length'])) {
                $fieldErrors['password'] = 'Wachtwoord moet minimaal 8 tekens lang zijn.';
            }
        }
    }
    
    echo $twig->render('register.twig', [
        'title' => 'Account Aanmaken - AVG Consent',
        'message' => $message,
        'messageType' => $messageType,
        'fieldErrors' => $fieldErrors,
        'post' => $_POST,
    ]);
    exit;
}

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
    echo $twig->render('nieuwe_campagne/email-tekst.twig', [
        'title' => 'E-mail Tekst - AVG Consent',
    ]);
} elseif ($path === '/campagne/form/ledenlijst') {
    echo $twig->render('nieuwe_campagne/ledenlijst.twig', [
        'title' => 'Ledenlijst - AVG Consent',
    ]);
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
