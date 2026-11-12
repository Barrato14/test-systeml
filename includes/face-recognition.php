<?php

function validateFaceDescriptor($descriptor) {
    if (!is_array($descriptor) || count($descriptor) !== 128) {
        return false;
    }

    foreach ($descriptor as $value) {
        if (!is_numeric($value) || !is_finite((float)$value)) {
            return false;
        }
    }

    return true;
}

function verifyParticipantFace($participantId, $submittedDescriptor) {
    if (!validateFaceDescriptor($submittedDescriptor)) {
        return ['success' => false, 'message' => 'A valid face scan is required. Please position your face in front of the camera and try again.'];
    }

    $profileResponse = supabaseServiceRequest(
        'participant_face_profiles?participant_id=eq.' . rawurlencode((string)$participantId) . '&select=face_descriptor,consent_given',
        'GET'
    );

    if (($profileResponse['code'] ?? 500) >= 400) {
        return ['success' => false, 'message' => 'Unable to verify your registered face right now. Please try again later.'];
    }

    if (empty($profileResponse['data'][0])) {
        return ['success' => false, 'message' => 'No face is registered to your account. Please register your face from the Face Registration page first.'];
    }

    $saved = $profileResponse['data'][0];
    if (($saved['consent_given'] ?? false) !== true && ($saved['consent_given'] ?? '') !== 'true') {
        return ['success' => false, 'message' => 'Your face registration is not active. Please register your face again.'];
    }

    $storedDescriptor = $saved['face_descriptor'] ?? null;
    if (!validateFaceDescriptor($storedDescriptor)) {
        return ['success' => false, 'message' => 'Your saved face registration is invalid. Please register your face again.'];
    }

    $sum = 0.0;
    for ($i = 0; $i < 128; $i++) {
        $difference = (float)$submittedDescriptor[$i] - (float)$storedDescriptor[$i];
        $sum += $difference * $difference;
    }

    $distance = sqrt($sum);
    $threshold = 0.52;

    if ($distance > $threshold) {
        return ['success' => false, 'message' => 'Face does not match the face registered to your account. Please try again.'];
    }

    return ['success' => true, 'message' => 'Face verified.'];
}
