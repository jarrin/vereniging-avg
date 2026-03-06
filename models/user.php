<?php

require_once __DIR__ . '/../apache-config/database.php';

class User
{
    // public properties for easier access in controllers/views
    public $id;
    public $organization_id;
    public $username;
    public $email;
    public $created_at;
    public $last_login;
    public $is_active;
    public $updated_at;

    protected $pdo;


    public function __construct($data = [])
    {
        $this->pdo = Database::getConnection();

        if ($data) {
            $this->id = $data['id'] ?? null;
            $this->organization_id = $data['organization_id'] ?? null;
            $this->username = $data['username'] ?? null;
            $this->email = $data['email'] ?? null;
            $this->created_at = $data['created_at'] ?? null;
            $this->last_login = $data['last_login'] ?? null;
            $this->is_active = isset($data['is_active']) ? (int)$data['is_active'] : null;
            $this->updated_at = $data['updated_at'] ?? null;
        }
    }

    public function findByUsername(string $username): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM users WHERE username = ? OR email = ? LIMIT 1');
        $stmt->execute([$username, $username]);
        $row = $stmt->fetch();
        return $row !== false ? $row : null;
    }

    public static function findByUsernameStatic(string $username): ?self
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare('SELECT * FROM users WHERE username = ? OR email = ? LIMIT 1');
        $stmt->execute([$username, $username]);
        $row = $stmt->fetch();
        return $row ? new self($row) : null;
    }

    public function findById(string $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM users WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row !== false ? $row : null;
    }

    public static function findByIdStatic(string $id): ?self
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare('SELECT * FROM users WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ? new self($row) : null;
    }

    public function update(array $data): array
    {
        if (empty($this->id)) {
            return ['success' => false, 'message' => 'Geen gebruiker geselecteerd om te updaten.'];
        }

        $fields = [];
        $values = [];

        if (isset($data['username'])) {
            $fields[] = 'username = ?';
            $values[] = trim($data['username']);
        }
        if (isset($data['email'])) {
            $fields[] = 'email = ?';
            $values[] = trim($data['email']);
        }
        if (!empty($data['password'])) {
            $fields[] = 'password_hash = ?';
            $values[] = password_hash($data['password'], PASSWORD_DEFAULT);
        }
        if (isset($data['is_active'])) {
            $fields[] = 'is_active = ?';
            $values[] = (int)$data['is_active'];
        }

        if (empty($fields)) {
            return ['success' => false, 'message' => 'Geen velden opgegeven om te updaten.'];
        }

        $fields[] = 'updated_at = NOW()';
        $values[] = $this->id; // for WHERE clause
        $sql = 'UPDATE users SET ' . implode(', ', $fields) . ' WHERE id = ?';

        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($values);
            return ['success' => true, 'message' => 'Gebruiker bijgewerkt.'];
        } catch (\PDOException $e) {
            return ['success' => false, 'message' => 'Database fout: ' . $e->getMessage()];
        }
    }

    public static function getAllByOrganization(string $organizationId): array
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare('SELECT * FROM users WHERE organization_id = ? ORDER BY created_at DESC');
        $stmt->execute([$organizationId]);
        $rows = $stmt->fetchAll();
        $results = [];
        foreach ($rows as $r) {
            $results[] = new self($r);
        }
        return $results;
    }

    public static function register(string $username, string $email, string $password, string $organizationId): array
    {
        $username = trim($username);
        $email = trim($email);

        if ($username === '' || $email === '' || $password === '' || $organizationId === '') {
            return ['success' => false, 'id' => null, 'message' => 'Vul alle verplichte velden in.'];
        }

        // Check if username or email already exist
        $usernameExists = self::usernameExists($username);
        $emailExists = self::exists($email);
        
        if ($usernameExists || $emailExists) {
            if ($usernameExists && $emailExists) {
                $msg = 'Gebruikersnaam en e-mail bestaan al.';
            } elseif ($usernameExists) {
                $msg = 'Gebruikersnaam bestaat al.';
            } else {
                $msg = 'E-mail bestaat al.';
            }
            return [
                'success' => false,
                'id' => null,
                'message' => $msg,
                'errors' => ['username_exists' => $usernameExists, 'email_exists' => $emailExists]
            ];
        }

        $pdo = Database::getConnection();
        $password_hash = password_hash($password, PASSWORD_DEFAULT);
        $id = uniqid('', true); // Generate unique ID for UUID-like string

        $stmt = $pdo->prepare('INSERT INTO users (id, organization_id, username, email, password_hash, created_at, updated_at) VALUES (?, ?, ?, ?, ?, NOW(), NOW())');
        try {
            $stmt->execute([$id, $organizationId, $username, $email, $password_hash]);
            return ['success' => true, 'id' => $id, 'message' => 'Gebruiker geregistreerd.'];
        } catch (\PDOException $e) {
            return ['success' => false, 'id' => null, 'message' => 'Database fout: ' . $e->getMessage()];
        }
    }

    public static function authenticate(string $password, $username = null, $email = null): ?self
    {
        $pdo = Database::getConnection();

        $lookup = null;
        if (!empty($username)) {
            $lookup = $username;
        } elseif (!empty($email)) {
            $lookup = $email;
        }

        if ($lookup === null) {
            return null;
        }

        $stmt = $pdo->prepare('SELECT * FROM users WHERE username = ? OR email = ? LIMIT 1');
        $stmt->execute([$lookup, $lookup]);
        $row = $stmt->fetch();

        if (!$row) {
            return null;
        }

        if (!isset($row['password_hash']) || !password_verify($password, $row['password_hash'])) {
            return null;
        }

        // Check if account is active
        if (isset($row['is_active']) && (int)$row['is_active'] === 0) {
            return null;
        }

        // Update last_login
        try {
            $upd = $pdo->prepare('UPDATE users SET last_login = NOW() WHERE id = ?');
            $upd->execute([$row['id']]);
            // Reflect the updated last_login in the returned object
            $row['last_login'] = date('Y-m-d H:i:s');
        } catch (\PDOException $e) {
            // Ignore update failure for login
        }

        return new self($row);
    }

    public static function login(string $usernameOrEmail, string $password, bool $remember = false): array
    {
        $user = self::authenticate($password, $usernameOrEmail, $usernameOrEmail);
        if (!$user) {
            return ['success' => false, 'message' => 'Ongeldige gebruikersnaam of wachtwoord.', 'user' => null];
        }

        if (session_status() !== PHP_SESSION_ACTIVE) {
            // Set session cookie to live for 30 days if remember is on
            $lifetime = $remember ? 60 * 60 * 24 * 30 : 0;
            ini_set('session.gc_maxlifetime', 60 * 60 * 24 * 30);
            session_set_cookie_params([
                'lifetime' => $lifetime,
                'path' => '/',
                'httponly' => true,
                'samesite' => 'Lax'
            ]);
            session_start();
        }

        $_SESSION['user_id'] = $user->id;
        $_SESSION['username'] = $user->username;
        $_SESSION['email'] = $user->email;
        $_SESSION['organization_id'] = $user->organization_id;

        if ($remember) {
            $token = bin2hex(random_bytes(32));
            $selector = bin2hex(random_bytes(8));
            $cookieValue = $selector . ':' . $token;
            
            // Hash the token for database storage
            $hashedToken = password_hash($token, PASSWORD_DEFAULT);
            $expires = date('Y-m-d H:i:s', time() + 60 * 60 * 24 * 30);
            
            try {
                $pdo = Database::getConnection();
                // Note: This requires a remember_token and remember_expires column
                // You may need to add these to your users table if using remember functionality
                // $stmt = $pdo->prepare('UPDATE users SET remember_token = ?, remember_expires = ? WHERE id = ?');
                // $stmt->execute([$selector . ':' . $hashedToken, $expires, $user->id]);
                
                // Set cookie for 30 days
                setcookie('remember_me', $cookieValue, time() + 60 * 60 * 24 * 30, '/', '', false, true);
            } catch (\PDOException $e) {
                // Log error if needed
            }
        }

        return ['success' => true, 'message' => 'Inloggen gelukt.', 'user' => $user];
    }

    public static function loginWithToken(string $cookieValue): ?self
    {
        // This requires remember_token functionality to be added
        // For now, return null
        return null;
    }

    public static function exists($email): bool
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
        $stmt->execute([$email]);
        return (bool)$stmt->fetch();
    }

    public static function usernameExists($username): bool
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare('SELECT id FROM users WHERE username = ? LIMIT 1');
        $stmt->execute([$username]);
        return (bool)$stmt->fetch();
    }

    public static function getAllUsersByOrganization(string $organizationId): array
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare("SELECT * FROM users WHERE organization_id = ? ORDER BY created_at DESC");
        $stmt->execute([$organizationId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function deactivateUser(string $userId): array
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare('UPDATE users SET is_active = 0, updated_at = NOW() WHERE id = ?');
        try {
            $stmt->execute([$userId]);
            return ['success' => true, 'message' => 'Gebruiker gedeactiveerd.'];
        } catch (\PDOException $e) {
            return ['success' => false, 'message' => 'Database fout: ' . $e->getMessage()];
        }
    }

    public static function activateUser(string $userId): array
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare('UPDATE users SET is_active = 1, updated_at = NOW() WHERE id = ?');
        try {
            $stmt->execute([$userId]);
            return ['success' => true, 'message' => 'Gebruiker geactiveerd.'];
        } catch (\PDOException $e) {
            return ['success' => false, 'message' => 'Database fout: ' . $e->getMessage()];
        }
    }

    public static function delete(string $userId): array
    {
        $pdo = Database::getConnection();
        
        try {
            // Start transaction
            $pdo->beginTransaction();

            // The related tables have ON DELETE CASCADE in the schema
            // so deleting the user will automatically clean up related data.
            
            $stmt = $pdo->prepare('DELETE FROM users WHERE id = ?');
            $stmt->execute([$userId]);

            $pdo->commit();
            return ['success' => true, 'message' => 'Gebruiker permanent verwijderd.'];
        } catch (\PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            return ['success' => false, 'message' => 'Database fout: ' . $e->getMessage()];
        }
    }

    public static function logout(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
        setcookie('remember_me', '', time() - 3600, '/');
    }
}
