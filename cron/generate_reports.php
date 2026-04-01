<?php
/**
 * CRON WORKER / REPORT GENERATOR
 * 
 * Dit script controleert dagelijks of er campagnes zijn waarvan de einddatum
 * (end_date) is gepasseerd. Als dat zo is, wordt de campagne afgesloten ('completed')
 * en wordt er automatisch een Excel/CSV rapport weggeschreven op de server. 
 * Het record wordt opgeslagen in de 'reports' tabel.
 * 
 * Installatie (Linux / cPanel crontab):
 * 0 1 * * * /usr/bin/php /pad/naar/project/cron/generate_reports.php >> /pad/naar/project/cron/report_log.txt 2>&1
 * (Draait elke nacht om 01:00)
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

// Haal campagnes op waarvan de einddatum is verstreken, die nog actief zijn
$stmt = $pdo->prepare("
    SELECT * FROM campaigns 
    WHERE status = 'active' 
    AND end_date IS NOT NULL 
    AND end_date < CURDATE()
");
$stmt->execute();
$campaigns = $stmt->fetchAll(PDO::FETCH_ASSOC);

$baseDir = __DIR__ . '/../storage/reports';
if (!is_dir($baseDir)) {
    mkdir($baseDir, 0777, true);
}

$generatedCount = 0;

foreach ($campaigns as $campaign) {
    try {
        $campaignId = $campaign['id'];
        
        // 1. Haal alle vragen op
        $stmtQ = $pdo->prepare('SELECT id, question_text FROM questions WHERE campaign_id = ? ORDER BY sort_order ASC');
        $stmtQ->execute([$campaignId]);
        $questions = $stmtQ->fetchAll(PDO::FETCH_ASSOC);
        
        // 2. Haal alle personen op
        $stmtP = $pdo->prepare('SELECT * FROM persons WHERE campaign_id = ?');
        $stmtP->execute([$campaignId]);
        $persons = $stmtP->fetchAll(PDO::FETCH_ASSOC);
        
        // --- 2b. PAS NON-RESPONSE LOGICA TOE (GDPR 'Default to No') ---
        if ($campaign['non_response_action'] === 'default_no') {
            foreach ($persons as &$person) {
                if ($person['responded_at'] === null) {
                    // Maak fictieve 'Nee' antwoorden aan in de database voor deze persoon
                    foreach ($questions as $q) {
                        // Check eerst of er stiekem toch al een antwoord is (zou niet moeten bij responded_at IS NULL)
                        $stmtCheck = $pdo->prepare('SELECT id FROM answers WHERE person_id = ? AND question_id = ?');
                        $stmtCheck->execute([$person['id'], $q['id']]);
                        if (!$stmtCheck->fetch()) {
                            $stmtA = $pdo->prepare('INSERT INTO answers (id, person_id, question_id, answer, answered_at) VALUES (?, ?, ?, 0, NOW())');
                            $stmtA->execute([uniqid('', true), $person['id'], $q['id']]);
                        }
                    }
                    // Markeer als responded (datum van nu, omdat de deadline is verstreken)
                    $pdo->prepare('UPDATE persons SET responded_at = NOW(), token_expired = 1 WHERE id = ?')
                        ->execute([$person['id']]);
                    
                    $person['responded_at'] = date('Y-m-d H:i:s'); // Update lokale array voor de CSV hieronder
                }
            }
        }
        // ------------------------------------------------------------------

        // 3. Bestandsnaam genereren
        $fileName = 'rapport_' . strtolower(preg_replace('/[^a-zA-Z0-9]+/', '_', $campaign['name'])) . '_' . date('Ymd_His') . '.csv';
        $fullPath = $baseDir . '/' . $fileName;
        $relativePath = 'storage/reports/' . $fileName; // Pad voor in de unificatie web url / database
        
        $output = fopen($fullPath, 'w');
        
        fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF)); // Write UTF-8 BOM
        
        // 4. CSV Header schrijven
        $header = ['Naam', 'E-mail', 'Status', 'Gereageerd op'];
        foreach ($questions as $q) { 
            $header[] = $q['question_text']; 
        }
        fputcsv($output, $header, ';');
        
        // 5. Data rijen schrijven
        foreach ($persons as $person) {
            $row = [
                $person['name'], 
                $person['email'], 
                $person['email_status'], 
                $person['responded_at'] ? date('d-m-Y H:i', strtotime($person['responded_at'])) : '-'
            ];
            
            foreach ($questions as $q) {
                $stmtA = $pdo->prepare('SELECT answer FROM answers WHERE person_id = ? AND question_id = ?');
                $stmtA->execute([$person['id'], $q['id']]);
                $ans = $stmtA->fetch(PDO::FETCH_ASSOC);
                
                // Omzetten van boolean database waarde naar Ja / Nee
                if ($ans) {
                    $row[] = $ans['answer'] ? 'Ja' : 'Nee';
                } else {
                    $row[] = '-'; // Nog geen antwoord
                }
            }
            fputcsv($output, $row, ';');
        }
        
        fclose($output);
        
        // 6. Opslaan in de 'reports' tabel
        $reportId = uniqid('', true);
        $insertReport = $pdo->prepare('
            INSERT INTO reports (id, campaign_id, file_path, generated_at) 
            VALUES (?, ?, ?, NOW())
        ');
        $insertReport->execute([$reportId, $campaignId, $relativePath]);
        
        // 7. Werk de status van de campagne bij naar completed
        $updateCamp = $pdo->prepare("
            UPDATE campaigns 
            SET status = 'completed', report_generated_at = NOW() 
            WHERE id = ?
        ");
        $updateCamp->execute([$campaignId]);
        
        $generatedCount++;
        
    } catch (\Exception $e) {
        error_log("Fout bij genereren rapport voor campagne ID {$campaign['id']}: " . $e->getMessage());
    }
}

echo "[" . date('Y-m-d H:i:s') . "] Rapport(en) controle voltooid. Aantal nieuw gegenereerde rapporten: $generatedCount.\n";
