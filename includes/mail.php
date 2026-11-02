<?php
function sendSystemEmail($toEmail, $toName, $subject, $htmlBody, $attachmentData = null, $attachmentName = 'qrcode.png') {
    $apiKey = getenv('RESEND_API_KEY');
    
    if (!$apiKey) {
        return "Resend Error: RESEND_API_KEY environment variable is missing on Render.";
    }

    $url = 'https://api.resend.com/emails';
    
    $data = [
        // When testing for free, Resend uses onboarding@resend.dev as the sender
        'from' => 'EMIS Event System <onboarding@resend.dev>',
        'to' => [$toEmail],
        'subject' => $subject,
        'html' => $htmlBody
    ];

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $apiKey,
        'Content-Type: application/json'
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError) {
        return "cURL Connection Error: " . $curlError;
    }

    if ($httpCode >= 200 && $httpCode < 300) {
        return true;
    } else {
        return "Resend API Error (HTTP $httpCode): " . $response;
    }
}
?>