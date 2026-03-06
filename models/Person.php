<?php

require_once __DIR__ . '/../apache-config/database.php';

class Person
{
    public $id;
    public $campaign_id;
    public $name;
    public $email;
    public $email_status;
    public $token;
    public $token_expired;
    public $first_sent_at;
    public $reminder_sent_at;
    public $responded_at;
    public $created_at;

    public function __construct(array $data = [])
    {
        if (!empty($data)) {
            $this->id = $data['id'] ?? null;
            $this->campaign_id = $data['campaign_id'] ?? null;
            $this->name = $data['name'] ?? null;
            $this->email = $data['email'] ?? null;
            $this->email_status = $data['email_status'] ?? 'pending';
            $this->token = $data['token'] ?? null;
            $this->token_expired = $data['token_expired'] ?? false;
            $this->first_sent_at = $data['first_sent_at'] ?? null;
            $this->reminder_sent_at = $data['reminder_sent_at'] ?? null;
            $this->responded_at = $data['responded_at'] ?? null;
            $this->created_at = $data['created_at'] ?? null;
        }
    }

    /**
     * Register a new person to a campaign
     */
    public static function register(string $name, string $email, string $campaign_id): array
    {
        $name = trim($name);
        $email = trim($email);

        if ($name === '' || $email === '' || $campaign_id === '') {
            return ['success' => false, 'message' => 'Vul alle verplichte velden in.'];
        }

        // Validate email format
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['success' => false, 'message' => 'Ongeldig e-mailadres.'];
        }

        // Check if person already exists in this campaign
        if (self::existsInCampaign($email, $campaign_id)) {
            return ['success' => false, 'message' => 'Dit e-mailadres is al geregistreerd voor deze campagne.'];
        }

        // Verify campaign exists
        $campaign = Campaign::findById($campaign_id);
        if (!$campaign) {
            return ['success' => false, 'message' => 'Geselecteerde campagne bestaat niet.'];
        }

        $pdo = Database::getConnection();
        $id = uniqid('', true);
        $token = bin2hex(random_bytes(32));

        $stmt = $pdo->prepare('INSERT INTO persons (id, campaign_id, name, email, token, email_status, created_at) VALUES (?, ?, ?, ?, ?, ?, NOW())');
        
        try {
            $stmt->execute([$id, $campaign_id, $name, $email, $token, 'pending']);
            return ['success' => true, 'id' => $id, 'message' => 'Persoon registratie voltooid. Controleer uw e-mail voor verdere stappen.', 'token' => $token];
        } catch (\PDOException $e) {
            return ['success' => false, 'message' => 'Database fout: ' . $e->getMessage()];
        }
    }

    /**
     * Check if person exists in a specific campaign
     */
    public static function existsInCampaign(string $email, string $campaign_id): bool
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare('SELECT id FROM persons WHERE email = ? AND campaign_id = ? LIMIT 1');
        $stmt->execute([$email, $campaign_id]);
        return (bool)$stmt->fetch();
    }

    /**
     * Find person by ID
     */
    public static function findById(string $id): ?self
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare('SELECT * FROM persons WHERE id = ?');
        $stmt->execute([$id]);
        $data = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $data ? new self($data) : null;
    }

    /**
     * Find person by token
     */
    public static function findByToken(string $token): ?self
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare('SELECT * FROM persons WHERE token = ? AND token_expired = 0');
        $stmt->execute([$token]);
        $data = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $data ? new self($data) : null;
    }

    /**
     * Get all persons in a campaign
     */
    public static function getAllByCampaign(string $campaign_id): array
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare('SELECT * FROM persons WHERE campaign_id = ? ORDER BY created_at DESC');
        $stmt->execute([$campaign_id]);
        $results = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        $persons = [];
        foreach ($results as $row) {
            $persons[] = new self($row);
        }
        return $persons;
    }

    /**
     * Update person's response status
     */
    public function markAsResponded(): array
    {
        try {
            $pdo = Database::getConnection();
            $stmt = $pdo->prepare('UPDATE persons SET responded_at = NOW() WHERE id = ?');
            $stmt->execute([$this->id]);
            return ['success' => true, 'message' => 'Antwoord opgeslagen.'];
        } catch (\PDOException $e) {
            return ['success' => false, 'message' => 'Database fout: ' . $e->getMessage()];
        }
    }

    /**
     * Mark token as expired
     */
    public function expireToken(): array
    {
        try {
            $pdo = Database::getConnection();
            $stmt = $pdo->prepare('UPDATE persons SET token_expired = 1 WHERE id = ?');
            $stmt->execute([$this->id]);
            return ['success' => true, 'message' => 'Token verlopen.'];
        } catch (\PDOException $e) {
            return ['success' => false, 'message' => 'Database fout: ' . $e->getMessage()];
        }
    }
}
