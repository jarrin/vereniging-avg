<?php
// Initialize variables
$message = null;
$errors = null;

// Start session if not already started
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

// Handle auto-login if cookie exists and no session
if (!isset($_SESSION['user_id']) && isset($_COOKIE['remember_me'])) {
    require_once __DIR__ . '/../models/User.php';
    $user = User::loginWithToken($_COOKIE['remember_me']);
    if ($user) {
        header('Location: /index.php?action=dashboard');
        exit;
    }
}

// Handle POST request
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_once __DIR__ . '/../controllers/Authcontroller.php';
    $ctrl = new AuthController();
    $res = $ctrl->login($_POST);

    // Redirect on success
    if (!empty($res['success']) && $res['success'] === true) {
        header('Location: /index.php?action=dashboard');
        exit;
    }

    // Handle errors
    $message = $res['message'] ?? null;
    $errors = $res['errors'] ?? null;
}
?>
<!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Vereniging AVG - Inloggen</title>
    <link rel="stylesheet" href="/css/style.css">
    <link rel="manifest" href="/manifest.json">
</head>
<body class="auth-page">
    <div class="center-container">
        <div class="auth-form login-form">
            <h2>Inloggen</h2>
            <p>Log in met uw organisatieaccount</p>
            
            <?php if (!empty($message)): ?>
                <div class="message <?= strpos($message, 'gelukt') !== false ? 'success' : 'error' ?>">
                    <?= htmlspecialchars($message) ?>
                </div>
            <?php endif; ?>
            
            <?php if (!empty($errors) && is_array($errors)): ?>
                <ul class="errors">
                    <?php foreach ($errors as $field => $error): ?>
                        <li><?= htmlspecialchars($error) ?></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>

            <form method="post" action="" novalidate>
                <div class="form-group">
                    <label for="username">Gebruikersnaam of E-mail</label>
                    <input 
                        id="username" 
                        name="username" 
                        type="text" 
                        required 
                        maxlength="255"
                        value="<?= htmlspecialchars($_POST['username'] ?? '') ?>"
                        placeholder="Voer uw gebruikersnaam of e-mail in"
                    />
                </div>

                <div class="form-group">
                    <label for="password">Wachtwoord</label>
                    <input 
                        id="password" 
                        name="password" 
                        type="password" 
                        required
                        placeholder="Voer uw wachtwoord in"
                    />
                </div>

                <div class="form-group remember-me">
                    <input 
                        type="checkbox" 
                        id="remember" 
                        name="remember"
                    />
                    <label for="remember">Herinner mij voor 30 dagen</label>
                </div>

                <div class="form-group">
                    <button type="submit" class="btn btn-primary">Inloggen</button>
                </div>
            </form>

            <div class="auth-footer">
                <p>Geen account? <a href="/index.php?action=register">Registreer hier</a></p>
            </div>
        </div>
    </div>

    <script src="/js/pwa.js"></script>
</body>
</html>
