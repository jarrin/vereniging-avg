<?php

require_once __DIR__ . '/../apache-config/database.php';

class Campaign
{
    public $id;
    public $user_id;
    public $name;
    public $reply_to_email;
    public $email_subject;
    public $email_body;
    public $reminder_subject;
    public $reminder_body;
    public $reminder_enabled;
    public $reminder_days;
    public $non_response_action;
    public $start_date;
    public $end_date;
    public $report_generated_at;
    public $auto_delete_at;
    public $status;
    public $created_at;

    public function __construct(array $data = [])
    {
        if (!empty($data)) {
            $this->id = $data['id'] ?? null;
            $this->user_id = $data['user_id'] ?? null;
            $this->name = $data['name'] ?? null;
            $this->reply_to_email = $data['reply_to_email'] ?? null;
            $this->email_subject = $data['email_subject'] ?? null;
            $this->email_body = $data['email_body'] ?? null;
            $this->reminder_subject = $data['reminder_subject'] ?? null;
            $this->reminder_body = $data['reminder_body'] ?? null;
            $this->reminder_enabled = $data['reminder_enabled'] ?? false;
            $this->reminder_days = $data['reminder_days'] ?? 7;
            $this->non_response_action = $data['non_response_action'] ?? 'no_action';
            $this->start_date = $data['start_date'] ?? null;
            $this->end_date = $data['end_date'] ?? null;
            $this->report_generated_at = $data['report_generated_at'] ?? null;
            $this->auto_delete_at = $data['auto_delete_at'] ?? null;
            $this->status = $data['status'] ?? 'draft';
            $this->created_at = $data['created_at'] ?? null;
        }
    }

    /**
     * Get all campaigns
     */
    public static function getAll(): array
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare('SELECT * FROM campaigns WHERE status != "deleted" ORDER BY created_at DESC');
        $stmt->execute();
        $results = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        $campaigns = [];
        foreach ($results as $row) {
            $campaigns[] = new self($row);
        }
        return $campaigns;
    }

    /**
     * Get active campaigns only
     */
    public static function getActive(): array
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare('SELECT * FROM campaigns WHERE status = "active" ORDER BY created_at DESC');
        $stmt->execute();
        $results = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        $campaigns = [];
        foreach ($results as $row) {
            $campaigns[] = new self($row);
        }
        return $campaigns;
    }

    /**
     * Get campaigns by user ID
     */
    public static function getByUserId(string $user_id): array
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare('SELECT * FROM campaigns WHERE user_id = ? AND status != "deleted" ORDER BY created_at DESC');
        $stmt->execute([$user_id]);
        $results = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        $campaigns = [];
        foreach ($results as $row) {
            $campaigns[] = new self($row);
        }
        return $campaigns;
    }

    /**
     * Find campaign by ID
     */
    public static function findById(string $id): ?self
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare('SELECT * FROM campaigns WHERE id = ?');
        $stmt->execute([$id]);
        $data = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $data ? new self($data) : null;
    }

    /**
     * Create a new campaign
     */
    public static function create(string $user_id, string $name, string $reply_to_email = '', string $email_subject = '', string $email_body = ''): array
    {
        if (!$user_id || !$name) {
            return ['success' => false, 'message' => 'Vul alle verplichte velden in.'];
        }

        $pdo = Database::getConnection();
        $id = uniqid('', true);

        try {
            $stmt = $pdo->prepare('INSERT INTO campaigns (id, user_id, name, reply_to_email, email_subject, email_body, status, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())');
            $stmt->execute([$id, $user_id, $name, $reply_to_email, $email_subject, $email_body, 'draft']);
            return ['success' => true, 'message' => 'Campagne aangemaakt.', 'id' => $id];
        } catch (\PDOException $e) {
            return ['success' => false, 'message' => 'Database fout: ' . $e->getMessage()];
        }
    }

    /**
     * Update campaign
     */
    public function update(): array
    {
        try {
            $pdo = Database::getConnection();
            $stmt = $pdo->prepare('UPDATE campaigns SET name = ?, reply_to_email = ?, email_subject = ?, email_body = ?, reminder_subject = ?, reminder_body = ?, status = ?, reminder_enabled = ?, reminder_days = ?, non_response_action = ?, start_date = ?, end_date = ? WHERE id = ?');
            $stmt->execute([
                $this->name,
                $this->reply_to_email,
                $this->email_subject,
                $this->email_body,
                $this->reminder_subject,
                $this->reminder_body,
                $this->status,
                $this->reminder_enabled ? 1 : 0,
                $this->reminder_days,
                $this->non_response_action,
                $this->start_date,
                $this->end_date,
                $this->id
            ]);
            return ['success' => true, 'message' => 'Campagne bijgewerkt.'];
        } catch (\PDOException $e) {
            return ['success' => false, 'message' => 'Database fout: ' . $e->getMessage()];
        }
    }
}
