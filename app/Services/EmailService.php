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
                $mail->SMTPDebug = 0;
            }

            $mailFrom = getenv('MAIL_FROM_ADDRESS') ?: 'noreply@vereniging-avg.nl';
            
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
