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

// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Setup Twig
$loader = new FilesystemLoader(__DIR__ . '/../resources/views');
$twig = new Environment($loader, [
    'cache' => false, // Set to __DIR__ . '/../storage/cache' in production
    'debug' => true,
]);
$twig->addGlobal('session', $_SESSION);

// Check if user is logged in
require_once __DIR__ . '/../controllers/Authcontroller.php';
$isLoggedIn = AuthController::isLoggedIn();

// Basic routing (very simple for now)
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$action = $_GET['action'] ?? null;

// Handle logout — must come before other route checks
if ($path === '/logout') {
    $ctrl = new AuthController();
    $ctrl->logout(); // destroys session and redirects to login
    exit;
}

// Pseudo-cron (Web Cron) - Runs background tasks without needing server bash/crontab
if ($path === '/run-cron') {
    // A simple secret to prevent external abuse (hardcoded for simplicity, can be in .env)
    $secret = $_GET['secret'] ?? '';
    if ($secret !== 'avg_secret_123') {
        die(json_encode(['success' => false, 'error' => 'Invalid secret']));
    }
    
    // Run the cron scripts
    // Gebruik output buffering om te voorkomen dat hun printjes de JSON stukmaken
    ob_start();
    try {
        require_once __DIR__ . '/../cron/process_email_queue.php';
        require_once __DIR__ . '/../cron/generate_reports.php';
        require_once __DIR__ . '/../cron/auto_delete.php';
    } catch (\Throwable $e) {
        error_log("Web-cron fout: " . $e->getMessage());
    }
    $cronOutput = ob_get_clean();
    
    header('Content-Type: application/json');
    echo json_encode(['success' => true, 'output' => $cronOutput]);
    exit;
}

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
            header('Location: /dashboard');
            exit;
        }

        // Handle errors
        $message = $res['message'] ?? null;
        $messageType = $res['success'] ? 'success' : 'error';
        $errors = $res['errors'] ?? null;
    }

    echo $twig->render('login.twig', [
        'title' => 'Inloggen - AVG Verenigingen',
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
        'title' => 'Account Aanmaken - AVG Verenigingen',
        'message' => $message,
        'messageType' => $messageType,
        'fieldErrors' => $fieldErrors,
        'post' => $_POST,
    ]);
    exit;
} elseif ($path === '/vragenlijst') {
    // Public questionnaire responsive page
    $token = $_GET['token'] ?? null;
    require_once __DIR__ . '/../models/Person.php';
    require_once __DIR__ . '/../models/Campaign.php';
    
    $person = null;
    if ($token === 'preview') {
        // Dummy data for previewing the layout
        $person = new Person(['name' => 'Demo Lid', 'campaign_id' => '0']);
        $campaign = new Campaign(['name' => 'Voorbeeld Campagne AVG']);
        $questions = [
            ['id' => '1', 'question_text' => 'Gaat u akkoord met het publiceren van foto\'s op de website?'],
            ['id' => '2', 'question_text' => 'Mogen wij uw telefoonnummer delen met andere leden?']
        ];
    } elseif ($token) {
        $person = Person::findByToken($token);
    }
    
    if (!$person && $token !== 'preview') {
        echo $twig->render('vragenlijst.twig', [
            'title' => 'Ongeldige of verlopen link',
            'error' => 'Deze link is ongeldig, niet gevonden of u heeft de vragenlijst reeds ingevuld.'
        ]);
        exit;
    }
    
    if ($token !== 'preview') {
        $campaign = Campaign::findById($person->campaign_id);
    }
    
    if ($person->responded_at && $token !== 'preview') {
        echo $twig->render('vragenlijst.twig', [
            'title' => 'Reeds ingevuld',
            'success' => 'U heeft deze vragenlijst al beantwoord op ' . date('d-m-Y', strtotime($person->responded_at)) . '. Hartelijk dank!'
        ]);
        exit;
    }
    
    if ($token !== 'preview') {
        $pdo = Database::getConnection();
        $stmtQ = $pdo->prepare('SELECT * FROM questions WHERE campaign_id = ? ORDER BY sort_order ASC');
        $stmtQ->execute([$campaign->id]);
        $questions = $stmtQ->fetchAll();
    }
    
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if ($token === 'preview') {
            echo $twig->render('vragenlijst.twig', [
                'title' => 'Bedankt! (Preview)',
                'success' => 'Omdat dit een voorbeeld is, zijn uw antwoorden niet echt opgeslagen. Het formulier werkt helemaal!'
            ]);
            exit;
        }
        
        $answers = $_POST['answers'] ?? [];
        
        // Save answers
        foreach ($questions as $q) {
            $val = isset($answers[$q['id']]) && $answers[$q['id']] === '1' ? 1 : 0;
            $stmtA = $pdo->prepare('INSERT INTO answers (id, person_id, question_id, answer) VALUES (?, ?, ?, ?)');
            $stmtA->execute([uniqid('', true), $person->id, $q['id'], $val]);
        }
        
        $person->markAsResponded();
        $person->expireToken();

        // ---------------------------------------------------------
        // SEND CONFIRMATION EMAIL
        // ---------------------------------------------------------
        require_once __DIR__ . '/../app/Services/EmailService.php';
        $appUrl = rtrim($_ENV['APP_URL'] ?? getenv('APP_URL') ?: 'http://localhost:8080', '/');
        
        // Haal logo op: Prioriteit 1: Campagne logo, Prioriteit 2: Gebruiker logo
        $logoHtml = '';
        $finalLogoPath = $campaign->logo_path;
        
        if (!$finalLogoPath) {
            $stmtUser = $pdo->prepare('SELECT logo_path FROM users WHERE id = ?');
            $stmtUser->execute([$campaign->user_id]);
            $userData = $stmtUser->fetch();
            if ($userData && !empty($userData['logo_path'])) {
                $finalLogoPath = $userData['logo_path'];
            }
        }
        
        if ($finalLogoPath) {
            $logoUrl = $appUrl . $finalLogoPath;
            $logoHtml = '<div style="margin-bottom: 20px;"><img src="' . htmlspecialchars($logoUrl) . '" alt="' . htmlspecialchars($campaign->name) . '" style="max-height: 100px;"></div>';
        }

        $confSubject = 'Bevestiging van uw antwoorden - ' . $campaign->name;
        $confBody = '
            <html>
            <body style="font-family: Arial, sans-serif; line-height: 1.6; color: #333;">
                <div style="max-width: 600px; margin: 0 auto; padding: 20px; border: 1px solid #eee; border-radius: 10px;">
                    ' . $logoHtml . '
                    <h3>Bedankt voor uw reactie</h3>
                    <p>Beste ' . htmlspecialchars($person->name) . ',</p>
                    <p>Hartelijk dank voor het invullen van de AVG-vragenlijst voor <strong>' . htmlspecialchars($campaign->name) . '</strong>. Uw antwoorden zijn succesvol verwerkt en opgeslagen.</p>
                    <p>U hoeft verder geen actie meer te ondernemen.</p>
                    <br>
                    <p>Met vriendelijke groet,</p>
                    <p>' . htmlspecialchars($campaign->name) . '</p>
                    <div style="font-size: 0.8em; color: #777; border-top: 1px solid #eee; padding-top: 20px; margin-top: 30px;">
                        Deze bevestiging is automatisch verzonden namens ' . htmlspecialchars($campaign->name) . '.
                    </div>
                </div>
            </body>
            </html>
        ';
        
        \App\Services\EmailService::send($person->email, $confSubject, $confBody, $campaign->reply_to_email, $campaign->name);
        // ---------------------------------------------------------
        
        echo $twig->render('vragenlijst.twig', [
            'title' => 'Bedankt!',
            'success' => 'Uw antwoorden zijn succesvol opgeslagen. U ontvangt per e-mail een bevestiging. Hartelijk dank voor uw medewerking!'
        ]);
        exit;
    }
    
    echo $twig->render('vragenlijst.twig', [
        'title' => 'Toestemming - ' . $campaign->name,
        'person' => $person,
        'campaign' => $campaign,
        'questions' => $questions
    ]);
    exit;
}

if ($path === '/' || $path === '/index.php') {
    echo $twig->render('home.twig', [
        'title' => 'AVG Verenigingen - Toestemmingsbeheer vereenvoudigd',
    ]);
} elseif ($path === '/dashboard') {
    // Protected page - require login
    if (!$isLoggedIn) {
        header('Location: /index.php?action=login');
        exit;
    }
    
    require_once __DIR__ . '/../models/Campaign.php';
    $pdo = Database::getConnection();
    $stmt = $pdo->prepare('SELECT * FROM campaigns WHERE user_id = ? ORDER BY created_at DESC');
    $stmt->execute([$_SESSION['user_id']]);
    $campaigns = $stmt->fetchAll();
    
    // Add stats for each campaign
    foreach ($campaigns as &$campaign) {
        $stmtTotal = $pdo->prepare('SELECT COUNT(*) as total FROM persons WHERE campaign_id = ?');
        $stmtTotal->execute([$campaign['id']]);
        $campaign['total_members'] = $stmtTotal->fetch()['total'];
        
        $stmtResponded = $pdo->prepare('SELECT COUNT(*) as responded FROM persons WHERE campaign_id = ? AND responded_at IS NOT NULL');
        $stmtResponded->execute([$campaign['id']]);
        $campaign['responded_count'] = $stmtResponded->fetch()['responded'];
        
        $stmtFailed = $pdo->prepare('SELECT COUNT(*) as failed FROM persons WHERE campaign_id = ? AND email_status = "failed"');
        $stmtFailed->execute([$campaign['id']]);
        $campaign['failed_count'] = $stmtFailed->fetch()['failed'];
        
        $campaign['response_pct'] = $campaign['total_members'] > 0 ? round(($campaign['responded_count'] / $campaign['total_members']) * 100) : 0;
        $campaign['failed_pct'] = $campaign['total_members'] > 0 ? round(($campaign['failed_count'] / $campaign['total_members']) * 100) : 0;
    }
    
    // General stats
    $stmtStats = $pdo->prepare('
        SELECT 
            COUNT(p.id) as total_members,
            SUM(CASE WHEN p.responded_at IS NOT NULL THEN 1 ELSE 0 END) as total_responded,
            (SELECT COUNT(*) FROM campaigns WHERE user_id = ? AND status = "active") as active_campaigns
        FROM campaigns c
        LEFT JOIN persons p ON c.id = p.campaign_id
        WHERE c.user_id = ? AND c.status != "deleted"
    ');
    $stmtStats->execute([$_SESSION['user_id'], $_SESSION['user_id']]);
    $stats = $stmtStats->fetch();
    
    $totalMembers = $stats['total_members'] ?? 0;
    $totalResponded = $stats['total_responded'] ?? 0;
    $totalWaiting = $totalMembers - $totalResponded;
    $activeCampaigns = $stats['active_campaigns'] ?? 0;
    
    $respondedPct = $totalMembers > 0 ? round(($totalResponded / $totalMembers) * 100) : 0;
    $waitingPct = $totalMembers > 0 ? round(($totalWaiting / $totalMembers) * 100) : 100;

    echo $twig->render('dashboard.twig', [
        'title' => 'Dashboard - AVG Verenigingen',
        'campaigns' => $campaigns,
        'total_members' => $totalMembers,
        'total_responded' => $totalResponded,
        'total_waiting' => $totalWaiting,
        'responded_pct' => $respondedPct,
        'waiting_pct' => $waitingPct,
        'active_campaigns_count' => $activeCampaigns,
        'message' => $_GET['message'] ?? null,
    ]);
} elseif ($path === '/campagne/nieuw') {
    header('Location: /campagne/form/verenigingsgegevens');
    exit;
} elseif ($path === '/campagne/form/verenigingsgegevens') {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $_SESSION['new_campaign'] = array_merge($_SESSION['new_campaign'] ?? [], $_POST);
        
        // Handle logo upload
        if (!empty($_FILES['logo']['name'])) {
            $uploadDir = __DIR__ . '/uploads/logos/';
            if (!is_dir($uploadDir)) mkdir($uploadDir, 0777, true);
            
            $fileExtension = strtolower(pathinfo($_FILES['logo']['name'], PATHINFO_EXTENSION));
            $newFileName = uniqid('logo_') . '.' . $fileExtension;
            $targetPath = $uploadDir . $newFileName;
            
            if (move_uploaded_file($_FILES['logo']['tmp_name'], $targetPath)) {
                $_SESSION['new_campaign']['logo_path'] = '/uploads/logos/' . $newFileName;
            }
        } elseif (!empty($_POST['existing_logo'])) {
             $_SESSION['new_campaign']['logo_path'] = $_POST['existing_logo'];
        }
        
        header('Location: /campagne/form/vragen');
        exit;
    }
    echo $twig->render('nieuwe_campagne/verenigingsgegevens.twig', [
        'title' => 'Verenigingsgegevens - AVG Verenigingen',
    ]);
} elseif ($path === '/campagne/form/vragen') {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $_SESSION['new_campaign']['questions'] = $_POST['questions'] ?? [];
        header('Location: /campagne/form/email-tekst');
        exit;
    }
    echo $twig->render('nieuwe_campagne/vragen.twig', [
        'title' => 'Vragen - AVG Verenigingen',
    ]);
} elseif ($path === '/campagne/form/email-tekst') {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $_SESSION['new_campaign'] = array_merge($_SESSION['new_campaign'] ?? [], $_POST);
        header('Location: /campagne/form/ledenlijst');
        exit;
    }
    echo $twig->render('nieuwe_campagne/email-tekst.twig', [
        'title' => 'E-mail Tekst - AVG Verenigingen',
    ]);
} elseif ($path === '/campagne/form/ledenlijst') {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        // Collect all data
        $data = $_SESSION['new_campaign'] ?? [];
        $data = array_merge($data, $_POST);
        
        // 1. Create OR Update Campaign
        require_once __DIR__ . '/../models/Campaign.php';
        
        $editing_id = $data['editing_id'] ?? null;
        if ($editing_id) {
            $campaignId = $editing_id;
            $res = ['success' => true, 'id' => $campaignId];
            // No need to "create", we'll just update below
        } else {
            $res = Campaign::create(
                $_SESSION['user_id'], 
                $data['org_name'] ?? 'Nieuwe Campagne',
                $data['reply_to_email'] ?? '',
                $data['email_subject'] ?? '',
                $data['email_body'] ?? '',
                $data['logo_path'] ?? ''
            );
        }
        
        if ($res['success']) {
            $campaignId = $res['id'];
            $campaign = Campaign::findById($campaignId);
            $campaign->name = $data['org_name'] ?? $campaign->name;
            $campaign->reply_to_email = $data['reply_to_email'] ?? $campaign->reply_to_email;
            $campaign->email_subject = $data['email_subject'] ?? $campaign->email_subject;
            $campaign->email_body = $data['email_body'] ?? $campaign->email_body;
            $campaign->logo_path = $data['logo_path'] ?? $campaign->logo_path;
            $campaign->end_date = $data['end_date'] ?? null;
            $campaign->reminder_subject = $data['reminder_subject'] ?? '';
            $campaign->reminder_body = $data['email_signature'] ?? ''; 
            
            $action = $data['action_no_response'] ?? 'no_action';
            if ($action === 'reminder') {
                $campaign->non_response_action = 'send_reminder';
            } elseif ($action === 'default_no') {
                $campaign->non_response_action = 'default_no';
            } else {
                $campaign->non_response_action = 'no_action';
            }
            
            $campaign->reminder_days = $data['reminder_days'] ?? 7;
            $campaign->reminder_enabled = ($campaign->non_response_action === 'send_reminder');
            $campaign->status = 'active'; 
            $campaign->update();
            
            // 2. Add Questions (Clear first if editing)
            $pdo = Database::getConnection();
            if ($editing_id) {
                $pdo->prepare('DELETE FROM questions WHERE campaign_id = ?')->execute([$campaignId]);
            }
            $questions = $data['questions'] ?? [];
            foreach ($questions as $index => $qText) {
                if (trim($qText) === '') continue;
                $qId = uniqid('', true);
                // Schema has no 'type' column, only id, campaign_id, question_text, sort_order, created_at
                $stmt = $pdo->prepare('INSERT INTO questions (id, campaign_id, question_text, sort_order, created_at) VALUES (?, ?, ?, ?, NOW())');
                $stmt->execute([$qId, $campaignId, $qText, $index]);
            }
            
            // 3. Process Member List (Excel/CSV or Manual)
            require_once __DIR__ . '/../models/Person.php';
            
            if (!empty($_FILES['member_list']['name'])) {
                try {
                    $filePath = $_FILES['member_list']['tmp_name'];
                    $ext = strtolower(pathinfo($_FILES['member_list']['name'], PATHINFO_EXTENSION));
                    
                    if ($ext === 'csv') {
                        if (($handle = fopen($filePath, "r")) !== FALSE) {
                            while (($row = fgetcsv($handle, 1000, ",")) !== FALSE) {
                                if (count($row) >= 2 && !empty($row[0]) && !empty($row[1])) {
                                    Person::register($row[0], $row[1], $campaignId);
                                }
                            }
                            fclose($handle);
                        }
                    } else {
                        // Excel processing with PhpSpreadsheet
                        $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($filePath);
                        $worksheet = $spreadsheet->getActiveSheet();
                        $rows = $worksheet->toArray();
                        foreach ($rows as $index => $row) {
                            if ($index === 0) continue; // Skip header
                            if (!empty($row[0]) && !empty($row[1])) {
                                Person::register($row[0], $row[1], $campaignId);
                            }
                        }
                    }
                } catch (\Exception $e) {
                    error_log("Ledenlijst upload fout: " . $e->getMessage());
                }
            } elseif (!empty($data['manual_list'])) {
                // Process manual list from textarea
                // Repair broken copy-paste where a newline immediately follows a comma
                $manualList = preg_replace('/,\s*[\r\n]+/', ',', $data['manual_list']);
                $lines = explode("\n", $manualList);
                
                foreach ($lines as $line) {
                    $line = trim($line);
                    if (empty($line)) continue;
                    
                    // Allow comma, semicolon OR tab
                    if (strpos($line, "\t") !== false) {
                        $separator = "\t";
                    } else {
                        $separator = strpos($line, ';') !== false ? ';' : ',';
                    }
                    
                    $parts = explode($separator, $line);
                    
                    if (count($parts) >= 2) {
                        $name = trim($parts[0]);
                        $email = trim($parts[1]);
                        if (!empty($name) && !empty($email) && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                            Person::register($name, $email, $campaignId);
                        }
                    }
                }
            }
            
            unset($_SESSION['new_campaign']);
            header('Location: /dashboard?message=campaign_created');
            exit;
        } else {
             die("Campaign creation failed: " . $res['message']);
        }
    }
    echo $twig->render('nieuwe_campagne/ledenlijst.twig', [
        'title' => 'Ledenlijst - AVG Verenigingen',
    ]);
} elseif ($path === '/rapportages') {
    if (!$isLoggedIn) { header('Location: /index.php?action=login'); exit; }
    $pdo = Database::getConnection();
    
    // Fetch campaigns
    $stmt = $pdo->prepare("SELECT * FROM campaigns WHERE user_id = ? AND status != 'deleted' ORDER BY created_at DESC");
    $stmt->execute([$_SESSION['user_id']]);
    $campaigns = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($campaigns as &$c) {
        $stmtTotal = $pdo->prepare("SELECT COUNT(*) as total FROM persons WHERE campaign_id = ?");
        $stmtTotal->execute([$c['id']]);
        $c['total_members'] = $stmtTotal->fetch()['total'];
    }
    
    echo $twig->render('rapportages.twig', [
        'title' => 'Rapportages - AVG Verenigingen',
        'campaigns' => $campaigns,
        'active_page' => 'rapportages'
    ]);
} elseif (preg_match('/^\/campagne\/view\/(.+)$/', $path, $matches)) {
    if (!$isLoggedIn) { header('Location: /index.php?action=login'); exit; }
    require_once __DIR__ . '/../models/Campaign.php';
    require_once __DIR__ . '/../models/Person.php';
    $campaignId = $matches[1];
    $campaign = Campaign::findById($campaignId);
    if (!$campaign || $campaign->user_id !== $_SESSION['user_id']) { die("Campagne niet gevonden."); }
    
    $pdo = Database::getConnection();
    // Get questions
    $stmtQ = $pdo->prepare('SELECT * FROM questions WHERE campaign_id = ? ORDER BY sort_order ASC');
    $stmtQ->execute([$campaignId]);
    $questions = $stmtQ->fetchAll();
    
    // Get persons
    $persons = Person::getAllByCampaign($campaignId);
    
    echo $twig->render('campaign_view.twig', [
        'title' => 'Campagne Details - ' . $campaign->name,
        'campaign' => $campaign,
        'questions' => $questions,
        'persons' => $persons,
        'message' => $_GET['message'] ?? null
    ]);
} elseif ($path === '/campagne/lid/toevoegen') {
    if (!$isLoggedIn) { header('Location: /index.php?action=login'); exit; }
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        require_once __DIR__ . '/../models/Person.php';
        $campaignId = $_POST['campaign_id'];
        $name = trim($_POST['name']);
        $email = trim($_POST['email']);
        
        if (!empty($name) && !empty($email) && filter_var($email, FILTER_VALIDATE_EMAIL)) {
            Person::register($name, $email, $campaignId);
            header("Location: /campagne/view/$campaignId?message=lid_toegevoegd");
        } else {
            header("Location: /campagne/view/$campaignId?error=invalid_data");
        }
        exit;
    }
} elseif (preg_match('/^\/campagne\/edit\/(.+)$/', $path, $matches)) {
    if (!$isLoggedIn) { header('Location: /index.php?action=login'); exit; }
    require_once __DIR__ . '/../models/Campaign.php';
    $campaignId = $matches[1];
    $campaign = Campaign::findById($campaignId);
    if (!$campaign || $campaign->user_id !== $_SESSION['user_id']) { die("Campagne niet gevonden."); }
    
    // Fill session with current data to "edit" using the same wizard
    $_SESSION['new_campaign'] = [
        'org_name' => $campaign->name,
        'reply_to_email' => $campaign->reply_to_email,
        'email_subject' => $campaign->email_subject,
        'email_body' => $campaign->email_body,
        'reminder_subject' => $campaign->reminder_subject,
        'email_signature' => $campaign->reminder_body,
        'action_no_response' => $campaign->non_response_action === 'send_reminder' ? 'reminder' : 'no_action',
        'reminder_days' => $campaign->reminder_days,
        'end_date' => $campaign->end_date,
        'editing_id' => $campaign->id
    ];
    
    // Add questions to session
    $pdo = Database::getConnection();
    $stmtQ = $pdo->prepare('SELECT question_text FROM questions WHERE campaign_id = ? ORDER BY sort_order ASC');
    $stmtQ->execute([$campaignId]);
    $_SESSION['new_campaign']['questions'] = $stmtQ->fetchAll(PDO::FETCH_COLUMN);
    
    header('Location: /campagne/form/verenigingsgegevens');
    exit;
} elseif (preg_match('/^\/campagne\/report\/(.+)$/', $path, $matches)) {
    if (!$isLoggedIn) { header('Location: /index.php?action=login'); exit; }
    require_once __DIR__ . '/../models/Campaign.php';
    $campaignId = $matches[1];
    $campaign = Campaign::findById($campaignId);
    if (!$campaign || $campaign->user_id !== $_SESSION['user_id']) { die("Campagne niet gevonden."); }
    
    $pdo = Database::getConnection();
    
    // Kijk of er al een automatisch gegenereerd rapport is in de reports tabel
    $stmtR = $pdo->prepare('SELECT file_path FROM reports WHERE campaign_id = ? ORDER BY generated_at DESC LIMIT 1');
    $stmtR->execute([$campaignId]);
    $report = $stmtR->fetch(PDO::FETCH_ASSOC);
    
    if ($report && !empty($report['file_path'])) {
        $fullPath = __DIR__ . '/../' . $report['file_path'];
        if (file_exists($fullPath)) {
            // Update downloaded_at
            $pdo->prepare('UPDATE reports SET downloaded_at = NOW(), downloaded_by = ? WHERE file_path = ?')
                ->execute([$_SESSION['user_id'], $report['file_path']]);
                
            // Plan de automatische verwijdering van AVG-data (als het nog niet gepland is)
            // We geven de gebruiker nog 24 uur om het eventueel opnieuw te downloaden
            $pdo->prepare('UPDATE campaigns SET auto_delete_at = DATE_ADD(NOW(), INTERVAL 24 HOUR) WHERE id = ? AND auto_delete_at IS NULL')
                ->execute([$campaignId]);
                
            if (ob_get_level() > 0) ob_end_clean();
            $safeFileName = preg_replace('/[^a-zA-Z0-9_\-\.]/', '_', basename($fullPath));
            header('Content-Type: text/csv');
            header('Content-Disposition: attachment; filename="' . $safeFileName . '"');
            readfile($fullPath);
            exit;
        }
    }
    
    // Als er nog géén rapport is (bijv. campagne nog bezig), genereer live on-the-fly:
    
    // Get questions
    $stmtQ = $pdo->prepare('SELECT id, question_text FROM questions WHERE campaign_id = ? ORDER BY sort_order ASC');
    $stmtQ->execute([$campaignId]);
    $questions = $stmtQ->fetchAll();
    
    // Get responses
    $stmtP = $pdo->prepare('SELECT * FROM persons WHERE campaign_id = ?');
    $stmtP->execute([$campaignId]);
    $persons = $stmtP->fetchAll();
    
    if (ob_get_level() > 0) ob_end_clean();
    $safeName = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $campaign->name ?? 'campagne');
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="live_rapport_' . strtolower($safeName) . '.csv"');
    
    $output = fopen('php://output', 'w');
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
    
    // Header
    $header = ['Naam', 'E-mail', 'Status', 'Gereageerd op'];
    foreach ($questions as $q) { $header[] = $q['question_text']; }
    fputcsv($output, $header, ';');
    
    foreach ($persons as $person) {
        $row = [$person['name'], $person['email'], $person['email_status'], $person['responded_at']];
        foreach ($questions as $q) {
            $stmtA = $pdo->prepare('SELECT answer FROM answers WHERE person_id = ? AND question_id = ?');
            $stmtA->execute([$person['id'], $q['id']]);
            $ans = $stmtA->fetch();
            $row[] = $ans ? ($ans['answer'] ? 'Ja' : 'Nee') : '-';
        }
        fputcsv($output, $row, ';');
    }
    fclose($output);
    exit;
} elseif ($path === '/instellingen') {
    if (!$isLoggedIn) {
        header('Location: /index.php?action=login');
        exit;
    }
    
    // Fetch latest user data from DB to ensure session is up to date
    $pdo = Database::getConnection();
    $stmt = $pdo->prepare('SELECT * FROM users WHERE id = ?');
    $stmt->execute([$_SESSION['user_id']]);
    $dbUser = $stmt->fetch();
    
    echo $twig->render('instellingen.twig', [
        'title' => 'Instellingen - AVG Verenigingen',
        'user' => $dbUser ?: $_SESSION,
    ]);
} elseif ($path === '/instellingen/update-profile') {
    if (!$isLoggedIn) { header('Location: /index.php?action=login'); exit; }
    
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $pdo = Database::getConnection();
        $username = $_POST['username'] ?? '';
        $new_email = trim($_POST['email'] ?? '');
        $contact_person = $_POST['contact_person'] ?? '';
        $phone = $_POST['phone'] ?? '';
        $use_phone = isset($_POST['use_phone']) ? 1 : 0;
        
        // Handle logo upload
        $logoPath = null;
        if (!empty($_FILES['logo']['name'])) {
            $uploadDir = __DIR__ . '/uploads/logos/';
            if (!is_dir($uploadDir)) mkdir($uploadDir, 0777, true);
            
            $ext = strtolower(pathinfo($_FILES['logo']['name'], PATHINFO_EXTENSION));
            if (in_array($ext, ['jpg', 'jpeg'])) {
                $newFileName = 'logo_' . $_SESSION['user_id'] . '_' . time() . '.' . $ext;
                if (move_uploaded_file($_FILES['logo']['tmp_name'], $uploadDir . $newFileName)) {
                    $logoPath = '/uploads/logos/' . $newFileName;
                }
            }
        }
        
        if ($logoPath) {
            $stmt = $pdo->prepare('UPDATE users SET username = ?, contact_person = ?, phone = ?, use_phone = ?, logo_path = ? WHERE id = ?');
            $stmt->execute([$username, $contact_person, $phone, $use_phone, $logoPath, $_SESSION['user_id']]);
        } else {
            $stmt = $pdo->prepare('UPDATE users SET username = ?, contact_person = ?, phone = ?, use_phone = ? WHERE id = ?');
            $stmt->execute([$username, $contact_person, $phone, $use_phone, $_SESSION['user_id']]);
        }
        
        // Handle email change
        $stmtUser = $pdo->prepare("SELECT email FROM users WHERE id = ?");
        $stmtUser->execute([$_SESSION['user_id']]);
        $currentEmail = $stmtUser->fetchColumn();

        if (!empty($new_email) && $new_email !== $currentEmail && filter_var($new_email, FILTER_VALIDATE_EMAIL)) {
            $token = bin2hex(random_bytes(32));
            $stmtEmail = $pdo->prepare("UPDATE users SET pending_email = ?, email_verification_token = ?, email_verification_expires_at = DATE_ADD(NOW(), INTERVAL 24 HOUR) WHERE id = ?");
            $stmtEmail->execute([$new_email, $token, $_SESSION['user_id']]);

            require_once __DIR__ . '/../app/Services/EmailService.php';
            $appUrl = rtrim($_ENV['APP_URL'] ?? getenv('APP_URL') ?: 'http://localhost:8080', '/');
            $verifyLink = $appUrl . "/instellingen/verify-email?token=" . $token;

            $subject = "Bevestig uw nieuwe e-mailadres voor AVG Verenigingen";
            $body = '
            <html>
            <body style="font-family: Arial, sans-serif; line-height: 1.6; color: #333;">
                <div style="max-width: 600px; margin: 0 auto; padding: 20px; border: 1px solid #eee; border-radius: 10px;">
                    <h3>Bevestig E-mailadres</h3>
                    <p>Beste gebruiker,</p>
                    <p>U heeft onlangs aangevraagd om uw e-mailadres voor AVG Verenigingen te wijzigen naar dit adres.</p>
                    <p>Klik op de onderstaande link om deze wijziging te bevestigen:</p>
                    <p><a href="' . htmlspecialchars($verifyLink) . '" style="display:inline-block; padding: 10px 20px; background-color: #14b8a6; color: #fff; text-decoration: none; border-radius: 5px;">E-mailadres bevestigen</a></p>
                    <p>Deze link is 24 uur geldig.</p>
                </div>
            </body>
            </html>';

            \App\Services\EmailService::send($new_email, $subject, $body);
            $_SESSION['pending_email'] = $new_email;
            header('Location: /instellingen?message=profile_updated_email_pending');
            exit;
        }

        // Update session
        $_SESSION['username'] = $username;
        if ($logoPath) {
            $_SESSION['logo_path'] = $logoPath;
        }
        
        header('Location: /instellingen?message=profile_updated');
        exit;
    }
} elseif ($path === '/instellingen/verify-email') {
    if (!$isLoggedIn) { header('Location: /index.php?action=login'); exit; }
    $token = $_GET['token'] ?? '';
    if (!$token) {
        die("Geen token opgegeven.");
    }

    $pdo = Database::getConnection();
    $stmt = $pdo->prepare("SELECT id, pending_email FROM users WHERE email_verification_token = ? AND email_verification_expires_at > NOW()");
    $stmt->execute([$token]);
    $userRow = $stmt->fetch();

    if ($userRow && !empty($userRow['pending_email'])) {
        // Complete the update
        $updateStmt = $pdo->prepare("UPDATE users SET email = ?, pending_email = NULL, email_verification_token = NULL, email_verification_expires_at = NULL WHERE id = ?");
        $updateStmt->execute([$userRow['pending_email'], $userRow['id']]);

        if ($userRow['id'] === $_SESSION['user_id']) {
            $_SESSION['email'] = $userRow['pending_email'];
            unset($_SESSION['pending_email']);
        }
        
        header('Location: /instellingen?message=email_verified_success');
        exit;
    } else {
        die("Deze verificatielink is ongeldig of verlopen.");
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
            'title' => 'AVG Verenigingen - Toestemmingsbeheer vereenvoudigd',
        ]);
    } else {
        header("HTTP/1.0 404 Not Found");
        echo "404 Not Found";
    }
}
