<?php
require 'vendor/autoload.php';
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
$dotenv->safeLoad();

$host = $_ENV['DB_HOST'] ?? getenv('DB_HOST') ?? '127.0.0.1';
$dbname = $_ENV['DB_NAME'] ?? getenv('DB_NAME') ?? 'vereniging_avg';
$user = $_ENV['DB_USER'] ?? getenv('DB_USER') ?? 'root';
$pass = $_ENV['DB_PASS'] ?? getenv('DB_PASS') ?? '';

$pdo = new PDO('mysql:host='.$host.';dbname='.$dbname, $user, $pass);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('ALTER TABLE users ADD COLUMN pending_email VARCHAR(255) NULL AFTER email, ADD COLUMN email_verification_token VARCHAR(255) NULL AFTER pending_email, ADD COLUMN email_verification_expires_at TIMESTAMP NULL AFTER email_verification_token;');
echo "Columns added successfully";
