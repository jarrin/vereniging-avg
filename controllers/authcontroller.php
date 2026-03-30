<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

$path = __DIR__ . '/../apache-config/database.php';
if (!file_exists($path)) {
    die("database.php NOT found at: $path");
}
require_once $path;

if (!class_exists('Database')) {
    die("Class Database not found");
}

require_once __DIR__ . '/../models/User.php';
require_once __DIR__ . '/../models/Person.php';
require_once __DIR__ . '/../models/Campaign.php';

class AuthController
{
	protected $pdo;

	public function __construct()
	{
		$this->pdo = Database::getConnection();
	}

	public function showRegister()
	{
		// Get all active campaigns for registration
		$campaigns = Campaign::getActive();
		require __DIR__ . '/../resources/views/auth/register.php';
	}

	public function showLogin(string $message = null)
	{
		// Make $message available to the included view
		require __DIR__ . '/../resources/views/auth/login.php';
	}

	public function register(array $data): array
	{
		$name = trim($data['name'] ?? '');
		$email = trim($data['email'] ?? '');
		$campaign_id = trim($data['campaign_id'] ?? '');

		if (!$name || !$email || !$campaign_id) {
			return ['success' => false, 'message' => 'Vul alle verplichte velden in.', 'errors' => []];
		}

		// Validate email format
		if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
			return ['success' => false, 'message' => 'Ongeldig e-mailadres.', 'errors' => ['email' => true]];
		}

		// Verify campaign exists
		$campaign = Campaign::findById($campaign_id);
		if (!$campaign) {
			return [
				'success' => false,
				'message' => 'Geselecteerde campagne bestaat niet.',
				'errors' => ['campaign_id' => true]
			];
		}

		// Register the person to the campaign
		$res = Person::register($name, $email, $campaign_id);
		return $res;
	}

	public function registerUser(array $data): array
	{
		$username = trim($data['username'] ?? '');
		$email = trim($data['email'] ?? '');
		$password = $data['password'] ?? '';
		$passwordConfirm = $data['password_confirm'] ?? '';

		if (!$username || !$email || !$password) {
			return ['success' => false, 'message' => 'Vul alle verplichte velden in.', 'errors' => []];
		}

		// Check password confirmation
		if ($password !== $passwordConfirm) {
			return [
				'success' => false,
				'message' => 'Wachtwoorden komen niet overeen.',
				'errors' => ['password_confirm' => true]
			];
		}

		// Check password length
		if (strlen($password) < 8) {
			return [
				'success' => false,
				'message' => 'Wachtwoord moet minimaal 8 tekens lang zijn.',
				'errors' => ['password_length' => true]
			];
		}

		// Register the user
		$userRes = User::register($username, $email, $password);
		if (!$userRes['success']) {
			return $userRes;
		}

		return ['success' => true, 'message' => 'Account aangemaakt!', 'user_id' => $userRes['id']];
	}

	public function login(array $data): array
	{
		$username = trim($data['username'] ?? '');
		$password = $data['password'] ?? '';
		$remember = !empty($data['remember']);

		if (!$username || !$password) {
			return ['success' => false, 'message' => 'Vul gebruikersnaam en wachtwoord in.'];
		}

		// Use User::login() to handle authentication + session centrally
		$res = User::login($username, $password, $remember);
		return $res;
	}

	public function logout(): void
	{
		User::logout();
		header('Location: /index.php?action=login');
		exit;
	}

	public static function isLoggedIn(): bool
	{
		if (session_status() !== PHP_SESSION_ACTIVE) {
			session_start();
		}
		return isset($_SESSION['user_id']) && !empty($_SESSION['user_id']);
	}

	public static function getCurrentUser(): ?User
	{
		if (!self::isLoggedIn()) {
			return null;
		}
		return User::findByIdStatic($_SESSION['user_id']);
	}
}
