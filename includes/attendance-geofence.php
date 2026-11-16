<?php
/**
 * Zentrix venue geofence.
 * A participant is allowed inside the organizer-drawn polygon or within 50m
 * outside its boundary. Legacy events without a polygon retain the 50m pin rule.
 */
function checkEventGeofence($event, $latitude, $longitude, $accuracy) {
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

    $lat = (float)$latitude;
    $lng = (float)$longitude;
    $boundary = $event['venue_boundary'] ?? null;
    if (is_string($boundary)) $boundary = json_decode($boundary, true);

    if (is_array($boundary) && count($boundary) >= 3) {
        $points = [];
        foreach ($boundary as $point) {
            if (!is_array($point) || count($point) < 2 || !is_numeric($point[0]) || !is_numeric($point[1])
                || (float)$point[0] < -90 || (float)$point[0] > 90
                || (float)$point[1] < -180 || (float)$point[1] > 180) {
                return ['allowed' => false, 'message' => 'The saved venue boundary is invalid. Please contact the event organizer.'];
            }
            $points[] = [(float)$point[0], (float)$point[1]];
        }

        $inside = false;
        $count = count($points);
        for ($i = 0, $j = $count - 1; $i < $count; $j = $i++) {
            [$yi, $xi] = $points[$i];
            [$yj, $xj] = $points[$j];
            if ((($yi > $lat) !== ($yj > $lat)) &&
                ($lng < ($xj - $xi) * ($lat - $yi) / (($yj - $yi) ?: 1e-15) + $xi)) {
                $inside = !$inside;
            }
        }

        if ($inside) {
            return ['allowed' => true, 'distance' => 0, 'message' => 'Location verified. You are inside the marked event venue.'];
        }

        $earthRadius = 6371000;
        $cosLat = cos(deg2rad($lat));
        $px = deg2rad($lng) * $earthRadius * $cosLat;
        $py = deg2rad($lat) * $earthRadius;
        $minDistance = INF;
        for ($i = 0; $i < $count; $i++) {
            $next = ($i + 1) % $count;
            $ax = deg2rad($points[$i][1]) * $earthRadius * $cosLat;
            $ay = deg2rad($points[$i][0]) * $earthRadius;
            $bx = deg2rad($points[$next][1]) * $earthRadius * $cosLat;
            $by = deg2rad($points[$next][0]) * $earthRadius;
            $dx = $bx - $ax;
            $dy = $by - $ay;
            $lengthSquared = $dx * $dx + $dy * $dy;
            $t = $lengthSquared > 0 ? max(0, min(1, (($px - $ax) * $dx + ($py - $ay) * $dy) / $lengthSquared)) : 0;
            $distance = hypot($px - ($ax + $t * $dx), $py - ($ay + $t * $dy));
            $minDistance = min($minDistance, $distance);
        }

        if ($minDistance <= 50) {
            return ['allowed' => true, 'distance' => (int)round($minDistance), 'message' => 'Location verified. You are within 50 meters of the marked venue boundary.'];
        }
        return ['allowed' => false, 'distance' => (int)round($minDistance), 'message' => 'You are approximately ' . (int)round($minDistance) . ' meters outside the event venue. Attendance is allowed inside the marked venue or within 50 meters outside it.'];
    }

    // Backward compatibility for events created before venue boundaries were introduced.
    $eventLatitude = $event['latitude'] ?? null;
    $eventLongitude = $event['longitude'] ?? null;
    if (!is_numeric($eventLatitude) || !is_numeric($eventLongitude)
        || (float)$eventLatitude < -90 || (float)$eventLatitude > 90
        || (float)$eventLongitude < -180 || (float)$eventLongitude > 180) {
        return ['allowed' => false, 'message' => 'This event has no valid venue boundary or GPS pin. Please contact the event organizer.'];
    }
    $lat1 = deg2rad((float)$eventLatitude);
    $lat2 = deg2rad($lat);
    $dLat = deg2rad($lat - (float)$eventLatitude);
    $dLng = deg2rad($lng - (float)$eventLongitude);
    $a = sin($dLat / 2) ** 2 + cos($lat1) * cos($lat2) * sin($dLng / 2) ** 2;
    $distance = 6371000 * 2 * atan2(sqrt($a), sqrt(max(0, 1 - $a)));
    if ($distance > 50) {
        return ['allowed' => false, 'distance' => (int)round($distance), 'message' => 'You are approximately ' . (int)round($distance) . ' meters away from the event pin. Attendance is only allowed within 100 meters.'];
    }
    return ['allowed' => true, 'distance' => (int)round($distance), 'message' => 'Location verified within the 50-meter event area.'];
}
