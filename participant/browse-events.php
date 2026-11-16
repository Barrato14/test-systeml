<?php
session_start();
require_once '../config/database.php';
require_once '../includes/auth.php';

if (!isset($_SESSION['user_id']) || strtolower($_SESSION['user_role']) !== 'participant') {
    header("Location: ../login.php");
    exit;
}

$participantId = $_SESSION['user_id'];
$successMsg = '';
$errorMsg = '';

// Load this participant's existing registrations so event cards can show their current status.
$participantRegistrationsResponse = supabaseRequest(
    'event_registrations?participant_id=eq.' . rawurlencode((string)$participantId) . '&select=event_id,status',
    'GET'
);
$registeredEvents = [];
if (($participantRegistrationsResponse['code'] ?? 500) < 400 && is_array($participantRegistrationsResponse['data'] ?? null)) {
    foreach ($participantRegistrationsResponse['data'] as $registration) {
        $registeredEvents[(string)($registration['event_id'] ?? '')] = $registration['status'] ?? 'Approved';
    }
}

$manilaTimezone = new DateTimeZone('Asia/Manila');

function eventHasEnded($event, $timezone) {
    if (empty($event['end_datetime'])) {
        return true;
    }

    try {
        $eventEnd = new DateTimeImmutable($event['end_datetime']);
        $eventEnd = $eventEnd->setTimezone($timezone);
        $now = new DateTimeImmutable('now', $timezone);

        return $eventEnd <= $now;
    } catch (Exception $e) {
        return true;
    }
}

// Handle Registration Action
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['event_id'])) {
    $eventId = trim((string)$_POST['event_id']);

    if ($eventId === '') {
        $errorMsg = "Invalid event selected.";
    } else {
        $eventCheck = supabaseRequest(
            'events?id=eq.' . rawurlencode($eventId) . '&select=id,title,start_datetime,end_datetime',
            'GET'
        );

        if (($eventCheck['code'] ?? 500) >= 400 || empty($eventCheck['data'])) {
            $errorMsg = "Event not found. Registration is unavailable.";
        } elseif (eventHasEnded($eventCheck['data'][0], $manilaTimezone)) {
            $errorMsg = "Registration is closed. This event has already ended.";
        } elseif (isset($registeredEvents[$eventId])) {
            $errorMsg = "You are already registered for this event.";
        } else {
            $payload = [
                'participant_id' => $participantId,
                'event_id' => $eventId,
                'status' => 'Approved'
            ];

            $response = supabaseRequest('event_registrations', 'POST', $payload);
            if (($response['code'] ?? 500) >= 400) {
                $errorMsg = "You are already registered for this event or an error occurred.";
            } else {
                $successMsg = "Successfully registered for the event!";
                $registeredEvents[$eventId] = 'Approved';
            }
        }
    }
}

// Fetch events and hide ended or invalidly scheduled events, matching the participant dashboard.
$getResponse = supabaseRequest('events?order=start_datetime.asc', 'GET');
$allEvents = (($getResponse['code'] ?? 500) < 400 && is_array($getResponse['data'] ?? null))
    ? $getResponse['data'] : [];

$events = [];
foreach ($allEvents as $event) {
    if (!eventHasEnded($event, $manilaTimezone)) {
        $events[] = $event;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#0d0916">
    <title>Browse Events - Zentrix</title>
    <link rel="stylesheet" href="../css/style.css">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
</head>
<body class="participant-page browse-events-page">
<?php include '../includes/participant-nav.php'; ?>

<main class="browse-events-main">
    <section class="portal-page-heading">
<div class="portal-page-heading-copy"><span class="portal-page-eyebrow"><i class="bi bi-calendar2-week"></i> PARTICIPANT PORTAL</span><h1>Discover campus events</h1><p>Find something happening on campus, explore the details, and save your spot.</p></div>

</section>
<section class="browse-events-summary" aria-label="Available events summary">
        <div class="browse-summary-icon"><i class="bi bi-calendar-check"></i></div>
        <div>
            <span class="browse-summary-label">OPEN FOR PARTICIPATION</span>
            <strong><?= count($events) ?> <span><?= count($events) === 1 ? 'event' : 'events' ?> available</span></strong>
        </div>
        <span class="browse-summary-note"><i class="bi bi-clock"></i> Upcoming and ongoing</span>
    </section>

    <?php if ($successMsg): ?>
        <div class="browse-feedback browse-feedback-success" role="status"><i class="bi bi-check-circle-fill"></i><?= htmlspecialchars($successMsg) ?></div>
    <?php endif; ?>
    <?php if ($errorMsg): ?>
        <div class="browse-feedback browse-feedback-error" role="alert"><i class="bi bi-exclamation-circle-fill"></i><?= htmlspecialchars($errorMsg) ?></div>
    <?php endif; ?>

    <section class="browse-events-section">
        <div class="browse-section-heading">
            <div>
                <span class="browse-section-kicker">WHAT'S HAPPENING</span>
                <h2>Available events</h2>
            </div>
            <span class="browse-event-count"><?= count($events) ?> listed</span>
        </div>

        <?php if (empty($events)): ?>
            <div class="browse-empty-state">
                <div class="browse-empty-icon"><i class="bi bi-calendar2-x"></i></div>
                <h3>No available events right now</h3>
                <p>There are no upcoming or ongoing events to join at the moment. Check back later for new campus activities.</p>
                
            </div>
        <?php else: ?>
            <div class="browse-event-grid">
                <?php foreach ($events as $event):
                    $eventTitle = $event['title'] ?? 'Untitled Event';
                    $eventVenue = $event['venue'] ?? 'Venue to be announced';
                    $eventIdForCard = (string)($event['id'] ?? '');
                    $isAlreadyRegistered = array_key_exists($eventIdForCard, $registeredEvents);
                    $registrationStatus = $registeredEvents[$eventIdForCard] ?? 'Approved';
                    $eventLatitude = $event['latitude'] ?? null;
                    $eventLongitude = $event['longitude'] ?? null;
                    $hasValidEventCoordinates = is_numeric($eventLatitude) && is_numeric($eventLongitude)
                        && (float)$eventLatitude >= -90 && (float)$eventLatitude <= 90
                        && (float)$eventLongitude >= -180 && (float)$eventLongitude <= 180;
                    $eventDescription = trim((string)($event['description'] ?? ''));
                    $eventImage = trim((string)($event['image_url'] ?? ''));
                    $eventDateLabel = 'Schedule unavailable';
                    $eventTimeLabel = '';

                    if (!empty($event['start_datetime'])) {
                        try {
                            $eventStart = (new DateTimeImmutable($event['start_datetime']))->setTimezone($manilaTimezone);
                            $eventDateLabel = $eventStart->format('M d, Y');
                            $eventTimeLabel = $eventStart->format('g:i A');
                        } catch (Exception $e) {
                            $eventDateLabel = 'Schedule unavailable';
                        }
                    }
                ?>
                    <article class="browse-event-card">
                        <div class="browse-event-visual">
                            <?php if ($eventImage !== ''): ?>
                                <img src="<?= htmlspecialchars($eventImage, ENT_QUOTES, 'UTF-8') ?>" alt="<?= htmlspecialchars($eventTitle, ENT_QUOTES, 'UTF-8') ?>" loading="lazy">
                            <?php else: ?>
                                <div class="browse-event-image-fallback"><i class="bi bi-calendar2-event"></i><span>Zentrix CAMPUS EVENT</span></div>
                            <?php endif; ?>
                            <?php if ($isAlreadyRegistered): ?>
                                <span class="browse-event-status browse-event-status-registered"><i class="bi bi-check-circle-fill"></i> Registered</span>
                            <?php else: ?>
                                <span class="browse-event-status"><span></span> Available</span>
                            <?php endif; ?>
                        </div>
                        <div class="browse-event-content">
                            <div class="browse-event-date"><i class="bi bi-calendar3"></i><?= htmlspecialchars($eventDateLabel) ?><?php if ($eventTimeLabel !== ''): ?><span class="browse-date-divider">·</span><?= htmlspecialchars($eventTimeLabel) ?><?php endif; ?></div>
                            <h3><?= htmlspecialchars($eventTitle) ?></h3>
                            <p class="browse-event-description"><?= htmlspecialchars($eventDescription !== '' ? $eventDescription : 'No event description has been provided yet.') ?></p>
                            <div class="browse-event-venue"><i class="bi bi-geo-alt"></i><span><?= htmlspecialchars($eventVenue) ?></span>
                                <?php if ($hasValidEventCoordinates): ?>
                                    <button type="button" class="browse-event-map-button" data-browse-event-map
                                        data-map-lat="<?= htmlspecialchars((string)$eventLatitude, ENT_QUOTES, 'UTF-8') ?>"
                                        data-map-lng="<?= htmlspecialchars((string)$eventLongitude, ENT_QUOTES, 'UTF-8') ?>"
                                        data-map-title="<?= htmlspecialchars($eventTitle, ENT_QUOTES, 'UTF-8') ?>"
                                        data-map-boundary="<?= htmlspecialchars(json_encode($event['venue_boundary'] ?? null), ENT_QUOTES, 'UTF-8') ?>">
                                        <i class="bi bi-map"></i> View map
                                    </button>
                                <?php endif; ?>
                            </div>
                            <?php if ($isAlreadyRegistered): ?>
                                <div class="browse-register-form">
                                    <span class="browse-already-registered"><i class="bi bi-check2-circle"></i> Already registered · <?= htmlspecialchars(ucfirst((string)$registrationStatus)) ?></span>
                                </div>
                            <?php else: ?>
                                <form method="POST" class="browse-register-form">
                                    <input type="hidden" name="event_id" value="<?= htmlspecialchars($eventIdForCard, ENT_QUOTES, 'UTF-8') ?>">
                                    <button type="submit" class="browse-register-button"><span>Register for event</span><i class="bi bi-arrow-up-right"></i></button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>
</main>
<div class="browse-map-overlay" id="browseEventMapOverlay" aria-hidden="true">
    <section class="browse-map-dialog" role="dialog" aria-modal="true" aria-labelledby="browseEventMapTitle">
        <header class="browse-map-header">
            <div><span>EVENT LOCATION</span><h2 id="browseEventMapTitle">Event location</h2></div>
            <button type="button" class="browse-map-close" data-close-browse-map aria-label="Close map"><i class="bi bi-x-lg"></i></button>
        </header>
        <div class="browse-map-body">
            <div id="browseEventMapFrame" class="participant-venue-map" role="img" aria-label="Event venue boundary map"></div>
            <div class="participant-map-legend"><span><i class="legend-venue"></i> Marked venue</span><span><i class="legend-buffer"></i> Allowed area (50m outside)</span><span><i class="legend-pin"></i> Event pin</span></div>
            <div class="browse-map-actions">
                <span id="browseEventMapCoordinates"></span>
                <a id="browseEventGoogleMaps" href="#" target="_blank" rel="noopener noreferrer"><i class="bi bi-box-arrow-up-right"></i> Open in Google Maps</a>
            </div>
        </div>
    </section>
</div>
<style>
.browse-event-status-registered { display:inline-flex!important; align-items:center; gap:6px; background:rgba(34,197,94,.16)!important; color:#86efac!important; }
.browse-event-map-button { display:inline-flex; align-items:center; gap:5px; margin-left:8px; padding:4px 9px; border:1px solid #72509a; border-radius:7px; background:#251a32; color:#d9b9ff; font-size:11px; font-weight:700; cursor:pointer; }
.browse-event-map-button:hover { background:#39244d; color:#fff; }
.browse-already-registered { display:inline-flex; align-items:center; justify-content:center; gap:7px; width:100%; padding:12px 15px; border:1px solid rgba(34,197,94,.35); border-radius:10px; background:rgba(34,197,94,.1); color:#86efac; font-size:12px; font-weight:750; }
.browse-map-overlay { position:fixed; inset:0; z-index:2000; display:none; align-items:center; justify-content:center; padding:20px; background:rgba(8,5,14,.78); backdrop-filter:blur(5px); }
.browse-map-overlay.is-open { display:flex; }
.browse-map-dialog { width:min(100%,760px); overflow:hidden; border:1px solid #49345f; border-radius:17px; background:#171222; box-shadow:0 24px 80px rgba(0,0,0,.45); }
.browse-map-header { display:flex; align-items:center; justify-content:space-between; gap:15px; padding:18px 21px; border-bottom:1px solid #382846; }
.browse-map-header span { color:#c4a5fd; font-size:9px; font-weight:800; letter-spacing:.16em; }
.browse-map-header h2 { margin:5px 0 0; color:#f7f0ff; font-size:19px; font-weight:750; }
.browse-map-close { width:34px; height:34px; border:1px solid #59436d; border-radius:9px; background:#261b32; color:#e8d8f8; cursor:pointer; }
.browse-map-body { padding:18px; }
.participant-venue-map { display:block; width:100%; height:min(55vh,430px); min-height:260px; border:0; border-radius:11px; background:#21172e; z-index:1; }
.participant-map-legend { display:flex; flex-wrap:wrap; gap:14px; padding-top:12px; color:#cbbbdc; font-size:11px; }
.participant-map-legend span { display:inline-flex; align-items:center; gap:6px; }
.participant-map-legend i { display:inline-block; width:10px; height:10px; border-radius:3px; }
.legend-venue { background:#4ade80; } .legend-buffer { background:#a78bfa; } .legend-pin { background:#ef4444; }
.browse-map-actions { display:flex; flex-wrap:wrap; align-items:center; justify-content:space-between; gap:12px; padding-top:14px; color:#bbaecb; font-size:11px; }
.browse-map-actions a { display:inline-flex; align-items:center; gap:7px; padding:9px 12px; border:1px solid #76519a; border-radius:8px; color:#dfc5ff; text-decoration:none; font-weight:700; }
.browse-map-actions a:hover { background:#30203f; color:#fff; }
@media(max-width:600px) { .browse-map-overlay { padding:10px; } .browse-map-body { padding:12px; } .participant-venue-map { min-height:230px; } }
</style>
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script src="https://cdn.jsdelivr.net/npm/@turf/turf@6/turf.min.js"></script>
<script>
(function () {
    const overlay = document.getElementById('browseEventMapOverlay');
    const mapElement = document.getElementById('browseEventMapFrame');
    let venueMap = null;
    const title = document.getElementById('browseEventMapTitle');
    const coordinates = document.getElementById('browseEventMapCoordinates');
    const externalLink = document.getElementById('browseEventGoogleMaps');

    function closeMap() {
        overlay.classList.remove('is-open');
        overlay.setAttribute('aria-hidden', 'true');
        if (venueMap) { venueMap.remove(); venueMap = null; }
        mapElement.innerHTML = '';
    }

    document.querySelectorAll('[data-browse-event-map]').forEach(function (button) {
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

    overlay.querySelectorAll('[data-close-browse-map]').forEach(function (button) {
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
</body>
</html>