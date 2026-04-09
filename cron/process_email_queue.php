<?php
// CRON WORKER / EMAIL QUEUE SYSTEM
// 
// Dit script is bedoeld om periodiek te draaien (bijv. elke 5 minuten via een taakplanner / crontab).
// Het haalt een beperkt aantal 'pending' emails op en verstuurt deze. 
// Dit voorkomt rate-limiting (anti-abuse throttling) van de mailserver doordat we
// niet duizenden e-mails tegelijk versturen.
// 
// Installatie (Linux / cPanel crontab):
// */5 * * * * /usr/bin/php /pad/naar/project/cron/process_email_queue.php >> / pad/naar/project/cron/email_log.txt 2>&1

require_once __DIR__ . '/../vendor/autoload.php';

use Dotenv\Dotenv;
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// Laad instellingen
$dotenv = Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->safeLoad();

require_once __DIR__ . '/../apache-config/database.php';
require_once __DIR__ . '/../models/Campaign.php';
require_once __DIR__ . '/../models/Person.php';

// Bescherming: Zorg dat dit script lokaal of via CLI draait. Optioneel te beveiligen met een secret key via URL.
if (php_sapi_name() !== 'cli' && empty($_GET['secret']) && getenv('APP_ENV') !== 'local') {
    die("Access denied. Use CLI or provide a secret.");
}

// Haal datbase connectie op
$pdo = Database::getConnection();

// --- 1. CONFIGURATIE ---
$batchLimit = 50; // Maximaal 50 emails per run versturen (anti-abuse throttling)
$appUrl = rtrim($_ENV['APP_URL'] ?? getenv('APP_URL') ?: 'http://localhost:8080', '/');

// --- 2. INITIELE EMAILS VERZENDEN ---
// Selecteer personen waarvan de e-mail nog verzonden moet worden, in een actieve campagne
$stmt = $pdo->prepare('
    SELECT p.*, c.user_id, c.name AS campaign_name, c.logo_path AS campaign_logo, c.reply_to_email, c.email_subject, c.email_body, c.end_date
    FROM persons p 
    JOIN campaigns c ON p.campaign_id = c.id
    WHERE c.status = "active" 
    AND p.email_status = "pending"
    ORDER BY p.created_at ASC
    LIMIT :limit
');
$stmt->bindValue(':limit', $batchLimit, PDO::PARAM_INT);
$stmt->execute();
$pendingPersons = $stmt->fetchAll(PDO::FETCH_ASSOC);

$countInitialSent = 0;
$countInitialFailed = 0;

foreach ($pendingPersons as $person) {
    if (sendCampaignEmail($person, 'initial', $appUrl, $pdo)) {
        $countInitialSent++;
    } else {
        $countInitialFailed++;
    }
}

// Selecteer personen waarbij herinneringen aan staan, e-mail reeds succesvol verzonden,
// nog niet gereageerd, nog geen herinnering gestuurd, en de wachttijd is verstreken.
$stmtReminders = $pdo->prepare('
    SELECT p.*, c.user_id, c.name AS campaign_name, c.logo_path AS campaign_logo, c.reply_to_email, c.reminder_subject AS email_subject, c.reminder_body AS email_body, c.end_date
    FROM persons p 
    JOIN campaigns c ON p.campaign_id = c.id
    WHERE c.status = "active" 
    AND c.reminder_enabled = 1
    AND p.email_status = "sent"
    AND p.responded_at IS NULL
    AND p.reminder_sent_at IS NULL
    AND p.first_sent_at < DATE_SUB(NOW(), INTERVAL c.reminder_days DAY)
    ORDER BY p.first_sent_at ASC
    LIMIT :limit
');
$stmtReminders->bindValue(':limit', $batchLimit, PDO::PARAM_INT);
$stmtReminders->execute();
$reminderPersons = $stmtReminders->fetchAll(PDO::FETCH_ASSOC);

$countRemindersSent = 0;
$countRemindersFailed = 0;

foreach ($reminderPersons as $person) {
    // Alleen als er daadwerkelijk een tekst is ingesteld
    if (!empty($person['email_body'])) {
        if (sendCampaignEmail($person, 'reminder', $appUrl, $pdo)) {
            $countRemindersSent++;
        } else {
            $countRemindersFailed++;
        }
    }
}

// Output log
$logMessage = "[" . date('Y-m-d H:i:s') . "] Cron Queue Worker voltooid.\n";
$logMessage .= "Nieuwe mails (pending): Gevonden = " . count($pendingPersons) . ", Verzonden = $countInitialSent, Gefaald = $countInitialFailed.\n";
$logMessage .= "Herinneringen: Gevonden = " . count($reminderPersons) . ", Verzonden = $countRemindersSent, Gefaald = $countRemindersFailed.\n";

echo $logMessage;


/**
 * Hulpfunctie om de e-mail te bouwen en via PHPMailer te versturen
 */
function sendCampaignEmail(array $person, string $type, string $appUrl, PDO $pdo): bool {
    // Stel PHPMailer in
    $mail = new PHPMailer(true);
    
    try {
        if (trim($_ENV['MAIL_MAILER'] ?? getenv('MAIL_MAILER') ?? '') === 'smtp') {
            $mail->isSMTP();
            $mail->Host       = $_ENV['MAIL_HOST'] ?? getenv('MAIL_HOST');
            $mail->SMTPAuth   = !empty($_ENV['MAIL_USERNAME']) && $_ENV['MAIL_USERNAME'] !== 'null';
            if ($mail->SMTPAuth) {
                $mail->Username   = $_ENV['MAIL_USERNAME'] ?? getenv('MAIL_USERNAME');
                $mail->Password   = $_ENV['MAIL_PASSWORD'] ?? getenv('MAIL_PASSWORD');
            }
            if (!empty($_ENV['MAIL_ENCRYPTION']) && $_ENV['MAIL_ENCRYPTION'] !== 'null') {
                $mail->SMTPSecure = $_ENV['MAIL_ENCRYPTION'] ?? getenv('MAIL_ENCRYPTION');
            }
            $mail->Port       = $_ENV['MAIL_PORT'] ?? getenv('MAIL_PORT') ?? 1025;
            
            // Zet debug uit in productie
            $mail->SMTPDebug = 0;
        }

        $mailFrom = $_ENV['MAIL_FROM_ADDRESS'] ?? getenv('MAIL_FROM_ADDRESS') ?? 'noreply@vereniging-avg.nl';
        $mailFromName = $person['campaign_name'] ?: ($_ENV['MAIL_FROM_NAME'] ?? getenv('MAIL_FROM_NAME') ?? 'AVG Vragenlijsten');
        
        $mail->setFrom($mailFrom, $mailFromName);
        $mail->addAddress($person['email'], $person['name']);

        // Set bounce address for VERP-like bounce handling
        $bounceDomain = $_ENV['BOUNCE_DOMAIN'] ?? getenv('BOUNCE_DOMAIN') ?? parse_url($appUrl, PHP_URL_HOST);
        $mail->Sender = 'bounce+' . $person['id'] . '@' . $bounceDomain;

        if (!empty($person['reply_to_email'])) {
            $mail->addReplyTo($person['reply_to_email']);
        }

        $contactPerson = $person['campaign_name'] ?: 'het bestuur';
        $logoHtml = '';
        
        // Prioriteit voor logo: 1. Campagne logo, 2. Gebruiker logo
        $finalLogoPath = $person['campaign_logo'] ?? null;
        
        if (!$finalLogoPath && !empty($person['user_id'])) {
            $stmtUser = $pdo->prepare('SELECT logo_path, contact_person FROM users WHERE id = ?');
            $stmtUser->execute([$person['user_id']]);
            $userData = $stmtUser->fetch();
            if ($userData) {
                $finalLogoPath = $userData['logo_path'] ?? null;
                if (!empty($userData['contact_person'])) {
                    $contactPerson = $userData['contact_person'];
                }
            }
        }
        
        if ($finalLogoPath) {
            // Path inside the Docker container
            $localImagePath = __DIR__ . '/../public' . $finalLogoPath;
            
            if (file_exists($localImagePath)) {
                try {
                    $mail->addEmbeddedImage($localImagePath, 'logo_cid');
                    $logoHtml = '<div style="margin-bottom: 20px;"><img src="cid:logo_cid" alt="' . htmlspecialchars($person['campaign_name']) . '" style="max-height: 100px;"></div>';
                } catch (Exception $e) {
                    // Fallback to URL if embedding fails
                    $logoUrl = $appUrl . $finalLogoPath;
                    $logoHtml = '<div style="margin-bottom: 20px;"><img src="' . htmlspecialchars($logoUrl) . '" alt="' . htmlspecialchars($person['campaign_name']) . '" style="max-height: 100px;"></div>';
                }
            } else {
                // Fallback to URL if local file not found
                $logoUrl = $appUrl . $finalLogoPath;
                $logoHtml = '<div style="margin-bottom: 20px;"><img src="' . htmlspecialchars($logoUrl) . '" alt="' . htmlspecialchars($person['campaign_name']) . '" style="max-height: 100px;"></div>';
            }
        }

        // Genereer de unieke link met token (routing gebruikt /vragenlijst, niet /vragenlijst.php)
        $linkUrl = $appUrl . '/vragenlijst?token=' . $person['token'];
        $linkHtml = '<a href="' . htmlspecialchars($linkUrl) . '" style="display: inline-block; padding: 12px 24px; background-color: #2563eb; color: #ffffff; text-decoration: none; border-radius: 6px; font-weight: bold;">Klik hier om uw gegevens te bevestigen</a>';

        // Vervang de beschikbare variabelen in de tekst
        $search = ['{naam}', '{link}', '{einddatum}', '{verenigingsnaam}', '{contactpersoon}'];
        $endDateFormatted = !empty($person['end_date']) ? date('d-m-Y', strtotime($person['end_date'])) : 'de einddatum';
        
        $replaceHtml = [
            htmlspecialchars($person['name'] ?? ''),
            $linkHtml,
            $endDateFormatted,
            htmlspecialchars($person['campaign_name'] ?? ''),
            htmlspecialchars($contactPerson)
        ];

        $replacePlain = [
            $person['name'] ?? '',
            $linkUrl,
            $endDateFormatted,
            $person['campaign_name'] ?? '',
            $contactPerson
        ];

        $bodyContent = str_replace($search, $replaceHtml, $person['email_body']);
        $subject = str_replace($search, $replacePlain, $person['email_subject']);

        // Wrap in basis HTML template voor een betere uitstraling
        $bodyHtml = '
            <html>
            <body style="font-family: Arial, sans-serif; line-height: 1.6; color: #333;">
                <div style="max-width: 600px; margin: 0 auto; padding: 20px; border: 1px solid #eee; border-radius: 10px;">
                    ' . $logoHtml . '
                    <div style="margin-bottom: 30px;">
                        ' . nl2br($bodyContent) . '
                    </div>
                    <div style="font-size: 0.8em; color: #777; margin-top: 30px; border-top: 1px dashed #eee; padding-top: 10px;">
                        Deze e-mail is verzonden via het AVG Vragenlijsten platform.
                    </div>
                </div>
            </body>
            </html>
        ';

        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = $bodyHtml;
        $mail->AltBody = strip_tags(str_replace($search, $replacePlain, $person['email_body']));

        // Verstuur de mail
        $mail->send();

        // Werk de database bij
        if ($type === 'initial') {
            $stmt = $pdo->prepare('UPDATE persons SET email_status = "sent", first_sent_at = NOW() WHERE id = ?');
        } else {
            $stmt = $pdo->prepare('UPDATE persons SET reminder_sent_at = NOW() WHERE id = ?');
        }
        $stmt->execute([$person['id']]);

        // Voeg logging toe
        $logId = uniqid('', true);
        $logStmt = $pdo->prepare('INSERT INTO email_logs (id, person_id, campaign_id, type, status, sent_at) VALUES (?, ?, ?, ?, ?, NOW())');
        $logStmt->execute([$logId, $person['id'], $person['campaign_id'], $type, 'success']);

        return true;

    } catch (Exception $e) {
        // Bij een fout:
        error_log("Worker Fout bij verzenden email naar {$person['email']}: {$mail->ErrorInfo}");

        if ($type === 'initial') {
            $stmt = $pdo->prepare('UPDATE persons SET email_status = "failed" WHERE id = ?');
            $stmt->execute([$person['id']]);
        }
        
        $logId = uniqid('', true);
        $logStmt = $pdo->prepare('INSERT INTO email_logs (id, person_id, campaign_id, type, status, sent_at) VALUES (?, ?, ?, ?, ?, NOW())');
        $logStmt->execute([$logId, $person['id'], $person['campaign_id'], $type, 'failed']);

        return false;
    }
}
