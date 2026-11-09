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

$myEvents = [];
foreach ($registrations as $reg) {
    $eventId = $reg['event_id'] ?? '';
    $eventData = $eventsMap[$eventId] ?? [];
    
    $myEvents[] = [
        'event_id' => $eventId,
        'title' => $eventData['title'] ?? 'Unknown Event',
        'start_datetime' => $eventData['start_datetime'] ?? '',
        'venue' => $eventData['venue'] ?? 'N/A',
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
    <title>My Events - EMIS</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
<link rel="stylesheet" href="../css/style.css">
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
    <div class="my-event-info"><h2><?= htmlspecialchars($event['title']) ?></h2><p><i class="bi bi-clock"></i><?= $event['start_datetime']?date('M d, Y · h:i A',strtotime($event['start_datetime'])):'Date unavailable' ?></p><p><i class="bi bi-geo-alt"></i><?= htmlspecialchars($event['venue']) ?></p><small>Registered <?= $event['registered_at']?date('M d, Y',strtotime($event['registered_at'])):'date unavailable' ?></small></div>
    <div class="my-event-actions"><span class="my-event-status my-event-status-<?= $statusClass ?>"><?= htmlspecialchars(ucfirst($status)) ?></span><?php if(!empty($event['event_id'])): ?><a href="check-in.php?event_id=<?= rawurlencode((string)$event['event_id']) ?>" class="my-event-checkin"><i class="bi bi-camera"></i> Check in</a><?php else: ?><span class="my-event-unavailable">Check-in unavailable</span><?php endif; ?></div>
   </article>
  <?php endforeach; ?>
  </section>
 <?php endif; ?>
</main>
</body>
</html>