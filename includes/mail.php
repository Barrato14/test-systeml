<?php
function sendSystemEmail($toEmail, $toName, $subject, $htmlBody, $attachmentData = null, $attachmentName = 'qrcode.png') {
    $apiKey = getenv('BREVO_API_KEY');
    
    if (!$apiKey) {
        return "Brevo Error: BREVO_API_KEY environment variable is missing on Render.";
    }

    $url = 'https://api.brevo.com/v3/smtp/email';
    
    $data = [
        'sender' => [
            'name' => 'EMIS Event System',
            'email' => 'barratobien@gmail.com' 
        ],
        'to' => [
            [
                'email' => $toEmail,
                'name' => $toName ?: 'User'
            ]
        ],
        'subject' => $subject,
        'htmlContent' => $htmlBody
    ];

    if ($attachmentData !== null) {
        $data['attachment'] = [
            [
                'content' => base64_encode($attachmentData),
                'name' => $attachmentName
            ]
        ];
    }

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'api-key: ' . $apiKey,
        'Content-Type: application/json',
        'accept: application/json'
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
        return "Brevo API Error (HTTP $httpCode): " . $response;
    }
}
?>