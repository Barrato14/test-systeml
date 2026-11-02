<?php
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// Make sure Composer's autoloader is included in your project
require_once __DIR__ . '/../vendor/autoload.php';

function sendSystemEmail($toEmail, $toName, $subject, $htmlBody, $attachmentData = null, $attachmentName = 'qrcode.png') {
    $mail = new PHPMailer(true);

    try {
        // Server settings for Gmail SMTP
        $mail->isSMTP();
        $mail->Host       = 'smtp.gmail.com';
        $mail->SMTPAuth   = true;
        
        // Put your actual Gmail address here
        $mail->Username   = 'barratobien@gmail.com'; 
        
        // Put your 16-character Google App Password here (no spaces)
        $mail->Password   = 'nnku anga dkpp fxxr'; 
        
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = 587;

        // Recipients
        $mail->setFrom('barratobien@gmail.com', 'EMIS Event System');
        $mail->addAddress($toEmail, $toName);

        // Content
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = $htmlBody;

        // Handle attachments (like QR codes) if passed into the function
        if ($attachmentData !== null) {
            $mail->addStringAttachment($attachmentData, $attachmentName);
        }

        $mail->send();
        return true;
    } catch (Exception $e) {
        return "Gmail SMTP Error: " . $mail->ErrorInfo;
    }
}
?>