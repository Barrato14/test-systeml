<?php
session_start();
require_once '../config/database.php';
require_once '../includes/auth.php';

// Ensure user is a Participant
if (!isset($_SESSION['user_id']) || strtolower($_SESSION['user_role']) !== 'participant') {
    header("Location: ../login.php");
    exit;
}

$participantId = $_SESSION['user_id'];

// 1. Fetch user's event registrations
$regResponse = supabaseRequest('event_registrations?participant_id=eq.' . $participantId, 'GET');
$registrations = ($regResponse['code'] < 400 && is_array($regResponse['data'])) ? $regResponse['data'] : [];

// 2. Fetch all events to map details cleanly
$allEventsResponse = supabaseRequest('events?order=start_datetime.asc', 'GET');
$allEvents = ($allEventsResponse['code'] < 400 && is_array($allEventsResponse['data'])) ? $allEventsResponse['data'] : [];

$eventsMap = [];
foreach ($allEvents as $evt) {
    $eventsMap[$evt['id']] = $evt;
}

// Fetch this participant's attendance records to determine the correct action.
$attendanceResponse = supabaseRequest(
    'attendance?participant_id=eq.' . rawurlencode((string)$participantId) .
    '&select=event_id,check_in_time,time_out',
    'GET'
);
$attendanceByEvent = [];
if (($attendanceResponse['code'] ?? 500) < 400 && is_array($attendanceResponse['data'] ?? null)) {
    foreach ($attendanceResponse['data'] as $attendanceRecord) {
        $attendanceByEvent[(string)($attendanceRecord['event_id'] ?? '')] = $attendanceRecord;
    }
}

$manilaTimezone = new DateTimeZone('Asia/Manila');
$now = new DateTimeImmutable('now', $manilaTimezone);

$myEvents = [];
foreach ($registrations as $reg) {
    $eventId = $reg['event_id'] ?? '';
    $eventData = $eventsMap[$eventId] ?? [];

    // Keep completed events out of My Events, matching the event availability rules.
    if (empty($eventData['end_datetime'])) {
        continue;
    }

    try {
        $eventEnd = new DateTimeImmutable($eventData['end_datetime']);
        if ($eventEnd->setTimezone($manilaTimezone) <= $now) {
            continue;
        }
    } catch (Exception $e) {
        continue;
    }

    $myEvents[] = [
        'event_id' => $eventId,
        'title' => $eventData['title'] ?? 'Unknown Event',
        'start_datetime' => $eventData['start_datetime'] ?? '',
        'venue' => $eventData['venue'] ?? 'N/A',
        'latitude' => $eventData['latitude'] ?? null,
        'longitude' => $eventData['longitude'] ?? null,
        'venue_boundary' => $eventData['venue_boundary'] ?? null,
        'registration_status' => $reg['status'] ?? 'Approved',
        'registered_at' => $reg['registered_at'] ?? date('Y-m-d')
    ];
}

// Sort by start date ascending
usort($myEvents, function($a, $b) {
    if (empty($a['start_datetime'])) return 1;
    if (empty($b['start_datetime'])) return -1;
    return strtotime($a['start_datetime']) - strtotime($b['start_datetime']);
});
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Events - Zentrix</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
<link rel="stylesheet" href="../css/style.css">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
</head>
<body class="bg-light participant-page">
<?php include '../includes/participant-nav.php'; ?>

<main class="my-events-main">
 <header class="my-events-heading"><div><span class="my-events-kicker">YOUR CAMPUS ACTIVITY</span><h1>My Events</h1><p>Keep track of the events you have joined and your check-in access.</p></div><div class="my-events-total"><i class="bi bi-ticket-perforated"></i><span><strong><?= count($myEvents) ?></strong> registered</span></div></header>
 <?php if(empty($myEvents)): ?>
  <section class="my-events-empty"><div><i class="bi bi-calendar2-plus"></i></div><h2>No registered events yet</h2><p>When you register for an event, it will show up here.</p><a href="browse-events.php">Browse events <i class="bi bi-arrow-right"></i></a></section>
 <?php else: ?>
  <section class="my-events-list">
  <?php foreach($myEvents as $event):
   $status=strtolower($event['registration_status']);
   $statusClass=$status==='pending'?'pending':($status==='rejected'?'rejected':'approved');
  ?>
   <article class="my-event-card">
    <div class="my-event-icon"><i class="bi bi-calendar-event"></i></div>
    <div class="my-event-info"><h2><?= htmlspecialchars($event['title']) ?></h2><p><i class="bi bi-clock"></i><?= $event['start_datetime']?date('M d, Y · h:i A',strtotime($event['start_datetime'])):'Date unavailable' ?></p><p class="my-event-venue"><i class="bi bi-geo-alt"></i><?= htmlspecialchars($event['venue']) ?>
     <?php if (is_numeric($event['latitude']) && is_numeric($event['longitude']) && (float)$event['latitude'] >= -90 && (float)$event['latitude'] <= 90 && (float)$event['longitude'] >= -180 && (float)$event['longitude'] <= 180): ?>
      <button type="button" class="my-event-map-link" data-my-event-map
       data-map-lat="<?= htmlspecialchars((string)$event['latitude'], ENT_QUOTES, 'UTF-8') ?>"
       data-map-lng="<?= htmlspecialchars((string)$event['longitude'], ENT_QUOTES, 'UTF-8') ?>"
       data-map-title="<?= htmlspecialchars($event['title'], ENT_QUOTES, 'UTF-8') ?>"
       data-map-boundary="<?= htmlspecialchars(json_encode($event['venue_boundary'] ?? null), ENT_QUOTES, 'UTF-8') ?>"
       aria-label="View location for <?= htmlspecialchars($event['title'], ENT_QUOTES, 'UTF-8') ?>">
       <i class="bi bi-map"></i> View map
      </button>
     <?php endif; ?>
    </p><small>Registered <?= $event['registered_at']?date('M d, Y',strtotime($event['registered_at'])):'date unavailable' ?></small></div>
    <div class="my-event-actions">
     <span class="my-event-status my-event-status-<?= $statusClass ?>"><?= htmlspecialchars(ucfirst($status)) ?></span>
     <?php
      $eventKey = (string)$event['event_id'];
      $attendanceRecord = $attendanceByEvent[$eventKey] ?? null;
      $hasCheckedIn = $attendanceRecord && !empty($attendanceRecord['check_in_time']);
      $hasCheckedOut = $hasCheckedIn && !empty($attendanceRecord['time_out']);
     ?>
     <?php if ($hasCheckedOut): ?>
      <span class="my-event-unavailable"><i class="bi bi-check2-circle"></i> Checked out</span>
     <?php elseif ($hasCheckedIn): ?>
      <a href="time-out.php?event_id=<?= rawurlencode($eventKey) ?>" class="my-event-checkin"><i class="bi bi-box-arrow-right"></i> Check out</a>
     <?php elseif ($eventKey !== ''): ?>
      <a href="check-in.php?event_id=<?= rawurlencode($eventKey) ?>" class="my-event-checkin"><i class="bi bi-camera"></i> Check in</a>
     <?php else: ?>
      <span class="my-event-unavailable">Attendance unavailable</span>
     <?php endif; ?>
    </div>
   </article>
  <?php endforeach; ?>
  </section>
 <?php endif; ?>
</main>
<div class="my-event-map-overlay" id="myEventMapOverlay" aria-hidden="true">
 <section class="my-event-map-dialog" role="dialog" aria-modal="true" aria-labelledby="myEventMapTitle">
  <header class="my-event-map-header">
   <div><span>EVENT LOCATION</span><h2 id="myEventMapTitle">Event location</h2></div>
   <button type="button" class="my-event-map-close" data-close-event-map aria-label="Close map"><i class="bi bi-x-lg"></i></button>
  </header>
  <div class="my-event-map-body">
   <div id="myEventMapFrame" class="participant-venue-map" role="img" aria-label="Event venue boundary map"></div>
   <div class="participant-map-legend"><span><i class="legend-venue"></i> Marked venue</span><span><i class="legend-buffer"></i> Allowed area (50m outside)</span><span><i class="legend-pin"></i> Event pin</span></div>
   <div class="my-event-map-actions">
    <span id="myEventMapCoordinates"></span>
    <a id="myEventGoogleMaps" href="#" target="_blank" rel="noopener noreferrer"><i class="bi bi-box-arrow-up-right"></i> Open in Google Maps</a>
   </div>
  </div>
 </section>
</div>
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script src="https://cdn.jsdelivr.net/npm/@turf/turf@6/turf.min.js"></script>
<script>
(function () {
 const overlay = document.getElementById('myEventMapOverlay');
 const mapElement = document.getElementById('myEventMapFrame');
 let venueMap = null;
 const title = document.getElementById('myEventMapTitle');
 const coordinates = document.getElementById('myEventMapCoordinates');
 const externalLink = document.getElementById('myEventGoogleMaps');

 function closeMap() {
  overlay.classList.remove('is-open');
  overlay.setAttribute('aria-hidden', 'true');
  if (venueMap) { venueMap.remove(); venueMap = null; }
  mapElement.innerHTML = '';
 }

 document.querySelectorAll('[data-my-event-map]').forEach(function (button) {
  button.addEventListener('click', function () {
   const lat = Number(button.dataset.mapLat);
   const lng = Number(button.dataset.mapLng);
   if (!Number.isFinite(lat) || !Number.isFinite(lng) || lat < -90 || lat > 90 || lng < -180 || lng > 180) return;
   const point = lat + ',' + lng;
   title.textContent = button.dataset.mapTitle || 'Event location';
   coordinates.textContent = 'Coordinates: ' + point;
if (venueMap) venueMap.remove();
            mapElement.innerHTML = '';
            venueMap = L.map(mapElement).setView([lat, lng], 17);
            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 20, attribution: '&copy; OpenStreetMap contributors' }).addTo(venueMap);
            L.marker([lat, lng]).addTo(venueMap).bindPopup('Event location');
            let boundary = null;
            try { boundary = JSON.parse(button.dataset.mapBoundary || 'null'); } catch (error) {}
            if (Array.isArray(boundary) && boundary.length >= 3) {
                const ring = boundary.map(p => [Number(p[1]), Number(p[0])]);
                if (ring.every(p => Number.isFinite(p[0]) && Number.isFinite(p[1]))) {
                    if (ring[0][0] !== ring[ring.length - 1][0] || ring[0][1] !== ring[ring.length - 1][1]) ring.push(ring[0]);
                    const feature = turf.polygon([ring]);
                    const allowedArea = turf.buffer(feature, 0.05, { units: 'kilometers' });
                    L.geoJSON(allowedArea, { style: { color: '#a78bfa', weight: 2, dashArray: '7 5', fillColor: '#8b5cf6', fillOpacity: .13 } }).addTo(venueMap);
                    L.polygon(boundary, { color: '#4ade80', weight: 3, fillColor: '#22c55e', fillOpacity: .22 }).addTo(venueMap).bindPopup('Organizer-marked venue');
                    venueMap.fitBounds(L.geoJSON(allowedArea).getBounds(), { padding: [20, 20] });
                }
            }
   externalLink.href = 'https://www.google.com/maps?q=' + encodeURIComponent(point);
   overlay.classList.add('is-open');
   overlay.setAttribute('aria-hidden', 'false');
  });
 });

 overlay.querySelectorAll('[data-close-event-map]').forEach(function (button) {
  button.addEventListener('click', closeMap);
 });
 overlay.addEventListener('click', function (event) {
  if (event.target === overlay) closeMap();
 });
 document.addEventListener('keydown', function (event) {
  if (event.key === 'Escape' && overlay.classList.contains('is-open')) closeMap();
 });
})();
</script>
<style>
.my-event-venue { display:flex; flex-wrap:wrap; align-items:center; gap:8px; }
.my-event-map-link { display:inline-flex; align-items:center; gap:5px; padding:3px 9px; border:1px solid #72509a; border-radius:7px; background:#251a32; color:#d9b9ff; font-size:11px; font-weight:700; cursor:pointer; }
.my-event-map-link:hover { background:#39244d; color:#fff; }
.my-event-map-overlay { position:fixed; inset:0; z-index:2000; display:none; align-items:center; justify-content:center; padding:20px; background:rgba(8,5,14,.78); backdrop-filter:blur(5px); }
.my-event-map-overlay.is-open { display:flex; }
.my-event-map-dialog { width:min(100%,760px); overflow:hidden; border:1px solid #49345f; border-radius:17px; background:#171222; box-shadow:0 24px 80px rgba(0,0,0,.45); }
.my-event-map-header { display:flex; align-items:center; justify-content:space-between; gap:15px; padding:18px 21px; border-bottom:1px solid #382846; }
.my-event-map-header span { color:#c4a5fd; font-size:9px; font-weight:800; letter-spacing:.16em; }
.my-event-map-header h2 { margin:5px 0 0; color:#f7f0ff; font-size:19px; font-weight:750; }
.my-event-map-close { width:34px; height:34px; border:1px solid #59436d; border-radius:9px; background:#261b32; color:#e8d8f8; cursor:pointer; }
.my-event-map-body { padding:18px; }
.participant-venue-map { display:block; width:100%; height:min(55vh,430px); min-height:260px; border:0; border-radius:11px; background:#21172e; z-index:1; }
.participant-map-legend { display:flex; flex-wrap:wrap; gap:14px; padding-top:12px; color:#cbbbdc; font-size:11px; }
.participant-map-legend span { display:inline-flex; align-items:center; gap:6px; }
.participant-map-legend i { display:inline-block; width:10px; height:10px; border-radius:3px; }
.legend-venue { background:#4ade80; } .legend-buffer { background:#a78bfa; } .legend-pin { background:#ef4444; }
.my-event-map-actions { display:flex; flex-wrap:wrap; align-items:center; justify-content:space-between; gap:12px; padding-top:14px; color:#bbaecb; font-size:11px; }
.my-event-map-actions a { display:inline-flex; align-items:center; gap:7px; padding:9px 12px; border:1px solid #76519a; border-radius:8px; color:#dfc5ff; text-decoration:none; font-weight:700; }
.my-event-map-actions a:hover { background:#30203f; color:#fff; }
@media(max-width:600px) { .my-event-map-overlay { padding:10px; } .my-event-map-body { padding:12px; } .participant-venue-map { min-height:230px; } }
</style>
</body>
</html>