<?php
// Initialize variables
$message = null;
$fieldErrors = [];

// Start session if not already started
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

// Handle POST request
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_once __DIR__ . '/../controllers/Authcontroller.php';
    $ctrl = new AuthController();
    $res = $ctrl->registerUser($_POST);

    // Redirect on success
    if (!empty($res['success']) && $res['success'] === true) {
        header('Location: /index.php?action=login&message=registration_success');
        exit;
    }

    // Handle errors
    $message = $res['message'] ?? null;
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
?>
<!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Vereniging AVG - Registreren</title>
    <link rel="stylesheet" href="/css/style.css">
    <link rel="manifest" href="/manifest.json">
</head>
<body class="auth-page">
    <div class="center-container">
        <div class="auth-form register-form">
            <h2>Account Aanmaken</h2>
            <p>Maak een account aan om campagnes te beheren</p>
            
            <?php if (!empty($message)): ?>
                <div class="message <?= strpos($message, 'gelukt') !== false ? 'success' : 'error' ?>">
                    <?= htmlspecialchars($message) ?>
                </div>
            <?php endif; ?>

            <form method="post" action="" novalidate>
                <div class="form-group">
                    <?php if (!empty($fieldErrors['username'])): ?>
                        <div class="field-error"><?= htmlspecialchars($fieldErrors['username']) ?></div>
                    <?php endif; ?>
                    <label for="username">Gebruikersnaam</label>
                    <input 
                        id="username" 
                        name="username" 
                        type="text" 
                        required 
                        maxlength="255"
                        value="<?= htmlspecialchars($_POST['username'] ?? '') ?>"
                        placeholder="Kies een unieke gebruikersnaam"
                    />
                </div>

                <div class="form-group">
                    <?php if (!empty($fieldErrors['email'])): ?>
                        <div class="field-error"><?= htmlspecialchars($fieldErrors['email']) ?></div>
                    <?php endif; ?>
                    <label for="email">E-mailadres</label>
                    <input 
                        id="email" 
                        name="email" 
                        type="email" 
                        required 
                        maxlength="255"
                        value="<?= htmlspecialchars($_POST['email'] ?? '') ?>"
                        placeholder="Voer uw e-mailadres in"
                    />
                </div>

                <div class="form-group">
                    <?php if (!empty($fieldErrors['campaign_name'])): ?>
                        <div class="field-error"><?= htmlspecialchars($fieldErrors['campaign_name']) ?></div>
                    <?php endif; ?>
                    <label for="campaign_name">Campagne naam</label>
                    <input 
                        id="campaign_name" 
                        name="campaign_name" 
                        type="text" 
                        required 
                        maxlength="255"
                        value="<?= htmlspecialchars($_POST['campaign_name'] ?? '') ?>"
                        placeholder="Geef uw campagne een naam"
                    />
                </div>

                <div class="form-group">
                    <?php if (!empty($fieldErrors['password'])): ?>
                        <div class="field-error"><?= htmlspecialchars($fieldErrors['password']) ?></div>
                    <?php endif; ?>
                    <label for="password">Wachtwoord</label>
                    <input 
                        id="password" 
                        name="password" 
                        type="password" 
                        required 
                        minlength="8"
                        placeholder="Minimaal 8 tekens"
                    />
                    <small>Minimaal 8 tekens</small>
                </div>

                <div class="form-group">
                    <?php if (!empty($fieldErrors['password_confirm'])): ?>
                        <div class="field-error"><?= htmlspecialchars($fieldErrors['password_confirm']) ?></div>
                    <?php endif; ?>
                    <label for="password_confirm">Bevestig wachtwoord</label>
                    <input 
                        id="password_confirm" 
                        name="password_confirm" 
                        type="password" 
                        required
                        placeholder="Herhaal uw wachtwoord"
                    />
                </div>

                <div class="form-group">
                    <button type="submit" class="btn btn-primary">Account Aanmaken</button>
                </div>
            </form>

            <div class="auth-footer">
                <p>Heeft u al een account? <a href="/index.php?action=login">Log hier in</a></p>
            </div>
        </div>
    </div>

    <script src="/js/pwa.js"></script>
</body>
</html>
