<?php
// CRON WORKER / BOUNCE HANDLER
//
// Dit is bedoeld om periodiek te draaien elke 10 minuten via een taakplanner / crontab.
// Het controleert de bounce mailbox voor onbestelbare e-mails en markeert deze als 'bounced' in de database.
//
// IMAP extensie voor PHP moet geïnstalleerd zijn.
// Configureer de bounce mailbox in .env: BOUNCE_IMAP_HOST, BOUNCE_IMAP_PORT, BOUNCE_EMAIL, BOUNCE_PASSWORD

require_once __DIR__ . '/../vendor/autoload.php';

use Dotenv\Dotenv;

// Laad instellingen
$dotenv = Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->safeLoad();

require_once __DIR__ . '/../apache-config/database.php';

// Bescherming: Zorg dat dit script lokaal of via CLI draait.
if (php_sapi_name() !== 'cli' && getenv('APP_ENV') !== 'local') {
    die("Access denied. Use CLI.");
}

// Haal database connectie op
$pdo = Database::getConnection();

// --- CONFIGURATIE ---
$bounceImapHost = $_ENV['BOUNCE_IMAP_HOST'] ?? getenv('BOUNCE_IMAP_HOST');
$bounceImapPort = $_ENV['BOUNCE_IMAP_PORT'] ?? getenv('BOUNCE_IMAP_PORT') ?? 993;
$bounceEmail = $_ENV['BOUNCE_EMAIL'] ?? getenv('BOUNCE_EMAIL');
$bouncePassword = $_ENV['BOUNCE_PASSWORD'] ?? getenv('BOUNCE_PASSWORD');
$bounceDomain = $_ENV['BOUNCE_DOMAIN'] ?? getenv('BOUNCE_DOMAIN');

if (!$bounceImapHost || !$bounceEmail || !$bouncePassword) {
    error_log("Bounce handler: Missing IMAP configuration. Skipping.");
    exit(0);
}

// Verbind met IMAP
$imapMailbox = '{' . $bounceImapHost . ':' . $bounceImapPort . '/imap/ssl}INBOX';
$imapConnection = imap_open($imapMailbox, $bounceEmail, $bouncePassword);

if (!$imapConnection) {
    error_log("Bounce handler: Failed to connect to IMAP: " . imap_last_error());
    exit(1);
}

// Zoek naar nieuwe (ongelezen) berichten
$searchCriteria = 'UNSEEN';
$emails = imap_search($imapConnection, $searchCriteria);

$countProcessed = 0;
$countBounced = 0;

if ($emails) {
    foreach ($emails as $emailNumber) {
        $header = imap_headerinfo($imapConnection, $emailNumber);
        $toAddress = $header->toaddress ?? '';

        // Controleer of het een bounce is via VERP (bounce+person_id@domain)
        if (preg_match('/bounce\+([a-f0-9\-]+)@' . preg_quote($bounceDomain, '/') . '/i', $toAddress, $matches)) {
            $personId = $matches[1];

            // Markeer als bounced in database
            $stmt = $pdo->prepare('UPDATE persons SET email_status = "bounced" WHERE id = ? AND email_status != "bounced"');
            $stmt->execute([$personId]);

            if ($stmt->rowCount() > 0) {
                // Voeg logging toe
                $logId = uniqid('', true);
                $logStmt = $pdo->prepare('INSERT INTO email_logs (id, person_id, campaign_id, type, status, sent_at) VALUES (?, ?, (SELECT campaign_id FROM persons WHERE id = ?), "initial", "bounced", NOW())');
                $logStmt->execute([$logId, $personId, $personId]);

                $countBounced++;
            }

            // Markeer als gelezen/verwerkt
            imap_setflag_full($imapConnection, $emailNumber, '\\Seen');
            $countProcessed++;
        } else {
            // Geen bounce, misschien handmatig markeren als gelezen of negeren
            imap_setflag_full($imapConnection, $emailNumber, '\\Seen');
        }
    }
}

// Sluit IMAP verbinding
imap_close($imapConnection);

// Output log
$logMessage = "[" . date('Y-m-d H:i:s') . "] Bounce Handler voltooid.\n";
$logMessage .= "Nieuwe berichten: " . ($emails ? count($emails) : 0) . ", Verwerkt = $countProcessed, Bounced = $countBounced.\n";

echo $logMessage;

?>