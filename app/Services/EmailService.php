<?php

namespace App\Services;

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
use App\apache_config\Database; // Adjust if needed

class EmailService
{
    public static function send(string $to, string $subject, string $body, string $replyTo = '', string $fromName = 'AVG Vragenlijsten'): bool
    {
        $mail = new PHPMailer(true);
        
        try {
            if (trim($_ENV['MAIL_MAILER'] ?? getenv('MAIL_MAILER') ?? '') === 'smtp') {
                $mail->isSMTP();
                $mail->Host       = $_ENV['MAIL_HOST'] ?? getenv('MAIL_HOST');
                $mail->SMTPAuth   = !empty($_ENV['MAIL_USERNAME'] ?? getenv('MAIL_USERNAME')) && ($_ENV['MAIL_USERNAME'] ?? getenv('MAIL_USERNAME')) !== 'null';
                if ($mail->SMTPAuth) {
                    $mail->Username   = $_ENV['MAIL_USERNAME'] ?? getenv('MAIL_USERNAME');
                    $mail->Password   = $_ENV['MAIL_PASSWORD'] ?? getenv('MAIL_PASSWORD');
                }
                $mailEncryption = $_ENV['MAIL_ENCRYPTION'] ?? getenv('MAIL_ENCRYPTION');
                if ($mailEncryption && $mailEncryption !== 'null') {
                    $mail->SMTPSecure = $mailEncryption;
                }
                $mail->Port       = $_ENV['MAIL_PORT'] ?? getenv('MAIL_PORT') ?: 1025;
                $mail->SMTPDebug = 0;
            }

            $mailFrom = $_ENV['MAIL_FROM_ADDRESS'] ?? getenv('MAIL_FROM_ADDRESS') ?: 'noreply@vereniging-avg.nl';

            
            $mail->setFrom($mailFrom, $fromName);
            $mail->addAddress($to);

            if (!empty($replyTo)) {
                $mail->addReplyTo($replyTo);
            }

            $mail->isHTML(true);
            $mail->Subject = $subject;
            $mail->Body    = $body;
            $mail->AltBody = strip_tags($body);

            return $mail->send();
        } catch (Exception $e) {
            error_log("EmailService Error: " . $e->getMessage());
            return false;
        }
    }
}
