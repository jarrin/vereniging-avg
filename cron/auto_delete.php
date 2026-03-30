<?php
/**
 * CRON WORKER / AUTO DELETE PII DATA (AVG)
 * 
 * Dit script controleert continu of er campagnes zijn waarvan het rapport is gedownload
 * en de bewaartermijn (auto_delete_at) verstreken is.
 * Zodra dit het geval is, wist dit script de persoonsgegevens, antwoorden, email-logs
 * en de bijbehorende fysieke bronbestanden conform AVG richtlijnen (dataminimalisatie).
 * 
 * Installatie (Linux / cPanel crontab):
 * 0 * * * * /usr/bin/php /pad/naar/project/cron/auto_delete.php >> /pad/naar/project/cron/delete_log.txt 2>&1
 * (Draait elk uur)
 */

require_once __DIR__ . '/../vendor/autoload.php';

use Dotenv\Dotenv;

// Laad instellingen
$dotenv = Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->safeLoad();

require_once __DIR__ . '/../apache-config/database.php';

// Bescherming: Zorg dat dit script lokaal of via CLI draait.
if (php_sapi_name() !== 'cli' && empty($_GET['secret']) && getenv('APP_ENV') !== 'local') {
    die("Access denied. Use CLI or provide a secret.");
}

$pdo = Database::getConnection();

// Haal campagnes op waarvan de auto_delete_at datum in het verleden ligt
$stmt = $pdo->prepare("
    SELECT * FROM campaigns 
    WHERE auto_delete_at IS NOT NULL 
    AND auto_delete_at <= NOW()
    AND status != 'deleted'
");
$stmt->execute();
$campaigns = $stmt->fetchAll(PDO::FETCH_ASSOC);

$deletedCount = 0;

foreach ($campaigns as $campaign) {
    try {
        $pdo->beginTransaction();
        $campaignId = $campaign['id'];
        
        // 1. Verwijder gegenereerde fysieke rapporten (CSV/Excel)
        $stmtReports = $pdo->prepare('SELECT id, file_path FROM reports WHERE campaign_id = ? AND deleted_at IS NULL');
        $stmtReports->execute([$campaignId]);
        $reports = $stmtReports->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($reports as $report) {
            if (!empty($report['file_path'])) {
                $fullPath = __DIR__ . '/../' . ltrim($report['file_path'], '/');
                if (file_exists($fullPath)) {
                    unlink($fullPath);
                }
            }
            // Markeer rapport in DB als gewist, en haal file_path leeg
            $pdo->prepare("UPDATE reports SET deleted_at = NOW(), file_path = '' WHERE id = ?")
                ->execute([$report['id']]);
        }
        
        // 2. Verwijder bron-uploads (Ledenlijst CSV bestanden) om PII in brondata te dichten
        $stmtUploads = $pdo->prepare('SELECT id, file_path FROM file_uploads WHERE campaign_id = ?');
        $stmtUploads->execute([$campaignId]);
        $uploads = $stmtUploads->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($uploads as $upload) {
            if (!empty($upload['file_path'])) {
                $fullPath = __DIR__ . '/../' . ltrim($upload['file_path'], '/');
                if (file_exists($fullPath)) {
                    unlink($fullPath);
                }
            }
        }
        // Brondata uit db (afhankelijk van of je log wilt behouden, momenteel wissen we de gehele regel)
        $pdo->prepare("DELETE FROM file_uploads WHERE campaign_id = ?")->execute([$campaignId]);

        // 3. Wis alle persoonsgegevens uit de database (door FOREIGN KEY CASCADE worden ook de answers en email_logs hiermee verwijderd!)
        $pdo->prepare("DELETE FROM persons WHERE campaign_id = ?")->execute([$campaignId]);
        
        // 4. Update de campaign status
        $pdo->prepare("UPDATE campaigns SET status = 'deleted' WHERE id = ?")->execute([$campaignId]);
        
        $pdo->commit();
        $deletedCount++;
        
        // Logboek update
        error_log("AVG Wipe succesvol uitgevoerd voor campagne: " . $campaign['name'] . " (ID: $campaignId)");
        
    } catch (\Exception $e) {
        $pdo->rollBack();
        error_log("Fout bij het wissen van gegevens voor campagne ID {$campaign['id']}: " . $e->getMessage());
    }
}

echo "[" . date('Y-m-d H:i:s') . "] Auto-Delete / Data-Minimization voltooid. Aantal opgeschoonde campagnes: $deletedCount.\n";
