<?php
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require_once __DIR__ . '/../vendor/autoload.php';

function sendSystemEmail($toEmail, $toName, $subject, $htmlBody, $attachmentData = null, $attachmentName = 'qrcode.png') {
    $mail = new PHPMailer(true);

    try {
        // SMTP Configuration
        $mail->isSMTP();
        $mail->Host       = getenv('SMTP_HOST') ?: 'smtp.gmail.com'; 
        $mail->SMTPAuth   = true;
        $mail->Username   = getenv('SMTP_USER') ?: 'your-email@gmail.com'; 
        $mail->Password   = getenv('SMTP_PASS') ?: 'your-app-password'; 
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = getenv('SMTP_PORT') ?: 587;

        // Sender & Recipient
        $mail->setFrom($mail->Username, 'EMIS System');
        $mail->addAddress($toEmail, $toName);

        // Optional Attachment (Used for QR Codes)
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
        error_log("Message could not be sent. Mailer Error: {$mail->ErrorInfo}");
        return false;
    }
}
?>