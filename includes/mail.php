<?php
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require_once __DIR__ . '/../vendor/autoload.php';

function sendSystemEmail($toEmail, $toName, $subject, $htmlBody, $attachmentData = null, $attachmentName = 'qrcode.png') {
    $mail = new PHPMailer(true);

    try {
        // Server settings
        $mail->isSMTP();
        $mail->Host       = getenv('SMTP_HOST') ?: 'smtp.gmail.com'; 
        $mail->SMTPAuth   = true;
        $mail->Username   = getenv('SMTP_USER'); // Must be your full Gmail address
        $mail->Password   = getenv('SMTP_PASS'); // Must be your 16-character Google App Password
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = getenv('SMTP_PORT') ?: 587;

        // Recipients
        $mail->setFrom(getenv('SMTP_USER'), 'EMIS Event System');
        $mail->addAddress($toEmail, $toName);

        // Optional Attachment (for QR codes later)
        if ($attachmentData !== null) {
            $mail->addStringAttachment($attachmentData, $attachmentName);
        }

        // Content
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = $htmlBody;
        $mail->AltBody = strip_tags($htmlBody);

        $mail->send();
        return true;
    } catch (Exception $e) {
        // Log the exact error to your server logs so you can see it in Render
        error_log("Mailer Error: {$mail->ErrorInfo}");
        return false;
    }
}
?>