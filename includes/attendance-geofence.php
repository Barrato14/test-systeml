<?php
/**
 * Zentrix attendance geofence.
 * Checks the participant's reported GPS point against the event's saved coordinates.
 */
function checkEventGeofence($event, $latitude, $longitude, $accuracy) {
    $eventLatitude = $event['latitude'] ?? null;
    $eventLongitude = $event['longitude'] ?? null;

    if (!is_numeric($eventLatitude) || !is_numeric($eventLongitude)
        || (float)$eventLatitude < -90 || (float)$eventLatitude > 90
        || (float)$eventLongitude < -180 || (float)$eventLongitude > 180) {
        return ['allowed' => false, 'message' => 'This event does not have a valid GPS location. Please contact the event organizer.'];
    }

    if (!is_numeric($latitude) || !is_numeric($longitude)
        || (float)$latitude < -90 || (float)$latitude > 90
        || (float)$longitude < -180 || (float)$longitude > 180) {
        return ['allowed' => false, 'message' => 'Your GPS location could not be verified. Please enable location services and try again.'];
    }

    if (!is_numeric($accuracy) || (float)$accuracy <= 0) {
        return ['allowed' => false, 'message' => 'GPS accuracy could not be verified. Please refresh your location and try again.'];
    }

    if ((float)$accuracy > 50) {
        return ['allowed' => false, 'message' => 'Your GPS signal is too inaccurate (' . round((float)$accuracy) . ' m). Move to an area with a clearer GPS signal and try again.'];
    }

    $earthRadius = 6371000;
    $lat1 = deg2rad((float)$eventLatitude);
    $lat2 = deg2rad((float)$latitude);
    $deltaLat = deg2rad((float)$latitude - (float)$eventLatitude);
    $deltaLon = deg2rad((float)$longitude - (float)$eventLongitude);

    $a = sin($deltaLat / 2) ** 2
        + cos($lat1) * cos($lat2) * sin($deltaLon / 2) ** 2;
    $distance = $earthRadius * 2 * atan2(sqrt($a), sqrt(max(0, 1 - $a)));

    if ($distance > 100) {
        return [
            'allowed' => false,
            'distance' => (int)round($distance),
            'message' => 'You are approximately ' . (int)round($distance) . ' meters away from the event. Attendance is only allowed within 100 meters of the event location.'
        ];
    }

    return [
        'allowed' => true,
        'distance' => (int)round($distance),
        'message' => 'Location verified. You are within the 100-meter event attendance area.'
    ];
}
