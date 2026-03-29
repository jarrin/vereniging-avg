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
$appUrl = rtrim(getenv('APP_URL') ?: 'http://localhost/vereniging-avg', '/');

// --- 2. INITIELE EMAILS VERZENDEN ---
// Selecteer personen waarvan de e-mail nog verzonden moet worden, in een actieve campagne
$stmt = $pdo->prepare('
    SELECT p.*, c.name AS campaign_name, c.reply_to_email, c.email_subject, c.email_body, c.end_date
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

// --- 3. HERINNERINGEN VERZENDEN ---
// Selecteer personen waarbij herinneringen aan staan, e-mail reeds succesvol verzonden,
// nog niet gereageerd, nog geen herinnering gestuurd, en de wachttijd is verstreken.
$stmtReminders = $pdo->prepare('
    SELECT p.*, c.name AS campaign_name, c.reply_to_email, c.reminder_subject AS email_subject, c.reminder_body AS email_body, c.end_date
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
        if (getenv('MAIL_MAILER') === 'smtp') {
            $mail->isSMTP();
            $mail->Host       = getenv('MAIL_HOST');
            $mail->SMTPAuth   = !empty(getenv('MAIL_USERNAME')) && getenv('MAIL_USERNAME') !== 'null';
            if ($mail->SMTPAuth) {
                $mail->Username   = getenv('MAIL_USERNAME');
                $mail->Password   = getenv('MAIL_PASSWORD');
            }
            if (getenv('MAIL_ENCRYPTION') && getenv('MAIL_ENCRYPTION') !== 'null') {
                $mail->SMTPSecure = getenv('MAIL_ENCRYPTION');
            }
            $mail->Port       = getenv('MAIL_PORT') ?: 1025;
            
            // Zet debug uit in productie
            $mail->SMTPDebug = 0;
        }

        $mailFrom = getenv('MAIL_FROM_ADDRESS') ?: 'noreply@vereniging-avg.nl';
        $mailFromName = getenv('MAIL_FROM_NAME') ?: 'AVG Vragenlijsten';
        
        $mail->setFrom($mailFrom, $mailFromName);
        $mail->addAddress($person['email'], $person['name']);

        if (!empty($person['reply_to_email'])) {
            $mail->addReplyTo($person['reply_to_email']);
        }

        // Genereer de unieke link met token
        $linkUrl = $appUrl . '/vragenlijst.php?token=' . $person['token']; // Pas .php aan naargelang routing setup
        $linkHtml = '<a href="' . htmlspecialchars($linkUrl) . '">Klik hier om uw gegevens te bevestigen</a>';

        // Vervang de beschikbare variabelen in de tekst
        // Beschikbare variabelen volgens de frontend: {naam}, {verenigingsnaam}, {contactpersoon}, {link}, {einddatum}
        // Noot: verenigingsnaam/contactpersoon halen we momenteel niet direct op, we vervangen het met standaard of de campagnenaam.
        $search = ['{naam}', '{link}', '{einddatum}', '{verenigingsnaam}'];
        $endDateFormatted = !empty($person['end_date']) ? date('d-m-Y', strtotime($person['end_date'])) : 'onbekend';
        
        $replaceHtml = [
            htmlspecialchars($person['name']),
            $linkHtml,
            $endDateFormatted,
            htmlspecialchars($person['campaign_name'])
        ];

        $replacePlain = [
            $person['name'],
            $linkUrl,
            $endDateFormatted,
            $person['campaign_name']
        ];

        $bodyHtml = str_replace($search, $replaceHtml, $person['email_body']);
        $bodyPlain = str_replace($search, $replacePlain, $person['email_body']);
        $subject = str_replace($search, $replacePlain, $person['email_subject']);

        // Voeg basis styling toe indien gewenst
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = nl2br($bodyHtml);
        $mail->AltBody = strip_tags($bodyPlain);

        // Verstuur de mail
        $mail->send();

        // Werk de database bij
        if ($type === 'initial') {
            $stmt = $pdo->prepare('UPDATE persons SET email_status = "sent", first_sent_at = NOW() WHERE id = ?');
        } else {
            // we gaan er van uit dat status "sent" blijft bij een herinnering
            $stmt = $pdo->prepare('UPDATE persons SET reminder_sent_at = NOW() WHERE id = ?');
        }
        $stmt->execute([$person['id']]);

        // Voeg logging toe voor geslaagde verzending
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
