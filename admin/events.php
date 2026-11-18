<?php
session_start();
date_default_timezone_set('Asia/Manila');

require_once '../config/database.php';
require_once '../includes/auth.php';
require_once '../includes/mail.php';

// Keep the same Zentrix roles and permissions.
$allowedRoles = ['administrator', 'event organizer'];
$currentRole = strtolower($_SESSION['user_role'] ?? '');

if (!isset($_SESSION['user_id']) || !in_array($currentRole, $allowedRoles, true)) {
    header("Location: ../login.php");
    exit;
}

$successMsg = '';
$errorMsg = '';

function h($value) {
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function formatManilaTime($value) {
    if (empty($value)) return 'N/A';

    try {
        $date = new DateTime($value);
        $date->setTimezone(new DateTimeZone('Asia/Manila'));
        return $date->format('M d, Y g:i A');
    } catch (Exception $e) {
        return 'Invalid Date';
    }
}

function toManilaDateTimeInput($value) {
    if (empty($value)) return '';

    try {
        $date = new DateTime($value);
        $date->setTimezone(new DateTimeZone('Asia/Manila'));
        return $date->format('Y-m-d\TH:i');
    } catch (Exception $e) {
        return '';
    }
}

// Calculate status from the event's schedule using Philippine time.
function getEventStatus($startValue, $endValue, $savedStatus = 'Upcoming') {
    if (empty($startValue) || empty($endValue)) {
        return $savedStatus ?: 'Upcoming';
    }

    try {
        $timezone = new DateTimeZone('Asia/Manila');
        $now = new DateTime('now', $timezone);
        $start = new DateTime($startValue);
        $end = new DateTime($endValue);

        $start->setTimezone($timezone);
        $end->setTimezone($timezone);

        if ($now < $start) {
            return 'Upcoming';
        }

        if ($now <= $end) {
            return 'Ongoing';
        }

        return 'Completed';
    } catch (Exception $e) {
        return $savedStatus ?: 'Upcoming';
    }
}

// Convert the datetime-local value (Philippine local time) to an ISO value
// with an explicit +08:00 offset for Supabase.
function toSupabaseDateTime($value) {
    if (empty($value)) return null;

    $date = DateTime::createFromFormat(
        'Y-m-d\TH:i',
        $value,
        new DateTimeZone('Asia/Manila')
    );

    return $date ? $date->format('Y-m-d\TH:i:sP') : null;
}

function getSupabaseEventError($response, $actionLabel) {
    $data = $response['data'] ?? null;
    $message = is_array($data) ? (string)($data['message'] ?? $data['details'] ?? '') : '';
    $code = is_array($data) ? (string)($data['code'] ?? '') : '';

    if (stripos($message, 'venue_boundary') !== false ||
        stripos($message, 'schema cache') !== false ||
        $code === 'PGRST204' ||
        $code === '42703') {
        return 'The venue boundary database update is missing or not active yet. Open Supabase SQL Editor, run database/venue-geofence.sql, wait a moment, then try again.';
    }

    if ($message !== '') {
        error_log('Zentrix event ' . $actionLabel . ' failed (HTTP ' . ($response['code'] ?? 'unknown') . ', ' . $code . '): ' . $message);
        return 'Failed to ' . $actionLabel . ' event: ' . $message;
    }

    error_log('Zentrix event ' . $actionLabel . ' failed with HTTP ' . ($response['code'] ?? 'unknown'));
    return 'Failed to ' . $actionLabel . ' event (server response ' . ($response['code'] ?? 'unknown') . '). Check the server error log.';
}

function parseVenueBoundary($raw) {
    if (trim((string)$raw) === '') return null;
    $points = json_decode($raw, true);
    if (!is_array($points) || count($points) < 3 || count($points) > 200) {
        throw new Exception('Please draw a valid venue boundary with at least 3 points.');
    }
    foreach ($points as $point) {
        if (!is_array($point) || count($point) < 2 ||
            !is_numeric($point[0]) || !is_numeric($point[1]) ||
            (float)$point[0] < -90 || (float)$point[0] > 90 ||
            (float)$point[1] < -180 || (float)$point[1] > 180) {
            throw new Exception('The venue boundary contains invalid map coordinates.');
        }
    }
    return array_values($points);
}

function buildEventPayload() {
    $start = toSupabaseDateTime($_POST['start_datetime'] ?? '');
    $end = toSupabaseDateTime($_POST['end_datetime'] ?? '');

    if (!$start || !$end) {
        throw new Exception('Please provide valid start and end dates.');
    }

    if (strtotime($end) <= strtotime($start)) {
        throw new Exception('The event end date and time must be after its start date and time.');
    }

    $venueBoundary = parseVenueBoundary($_POST['venue_boundary'] ?? '');
    if (!$venueBoundary) {
        throw new Exception('Please draw and save the venue boundary on the map before saving this event.');
    }

    $latitude = trim($_POST['latitude'] ?? '');
    $longitude = trim($_POST['longitude'] ?? '');

    return [
        'title' => trim($_POST['title'] ?? ''),
        'description' => trim($_POST['description'] ?? ''),
        'venue' => trim($_POST['venue'] ?? ''),
        'start_datetime' => $start,
        'end_datetime' => $end,
        'capacity' => (int)($_POST['capacity'] ?? 1),
        'status' => $_POST['status'] ?? 'Upcoming',
        'image_url' => trim($_POST['image_url'] ?? ''),
        'latitude' => $latitude !== '' ? (float)$latitude : null,
        'longitude' => $longitude !== '' ? (float)$longitude : null,
        'venue_boundary' => $venueBoundary
    ];
}

// CREATE / EDIT / DELETE: same events table and fields as the original Zentrix page.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    try {
        if ($action === 'delete') {
            if (!in_array($currentRole, ['administrator', 'event organizer'], true)) {
                throw new Exception('Permission denied. Staff cannot delete events.');
            }

            $deleteId = trim($_POST['event_id'] ?? '');
            if ($deleteId === '') {
                throw new Exception('Missing event ID.');
            }

            if ($currentRole === 'event organizer') {
                $ownerCheck = supabaseRequest(
                    'events?id=eq.' . rawurlencode($deleteId)
                    . '&organizer_id=eq.' . rawurlencode((string)$_SESSION['user_id'])
                    . '&select=id',
                    'GET'
                );
                if (($ownerCheck['code'] ?? 500) >= 400 || empty($ownerCheck['data'])) {
                    throw new Exception('You can only delete events assigned to you.');
                }
            }

            $deleteBeforeResponse = supabaseRequest('events?id=eq.' . rawurlencode($deleteId) . '&select=*', 'GET');
            $deleteBefore = $deleteBeforeResponse['data'][0] ?? [];
            $response = supabaseRequest('events?id=eq.' . rawurlencode($deleteId), 'DELETE');

            if (($response['code'] ?? 500) >= 400) {
                throw new Exception('Failed to delete event. It may be linked to existing attendance records.');
            }

            logAudit($_SESSION['user_id'], 'event_deleted', 'Event deleted: ' . ($deleteBefore['title'] ?? $deleteId) . ' | Previous record: ' . json_encode($deleteBefore));
            $successMsg = 'Event deleted successfully!';
        } elseif ($action === 'send_notification') {
            if (!in_array($currentRole, ['administrator', 'event organizer'], true)) {
                throw new Exception('Permission denied. Only administrators and event organizers can notify participants.');
            }

            $notifyEventId = trim((string)($_POST['event_id'] ?? ''));
            $notificationTitle = trim((string)($_POST['notification_title'] ?? ''));
            $notificationMessage = trim((string)($_POST['notification_message'] ?? ''));

            if ($notifyEventId === '' || $notificationTitle === '' || $notificationMessage === '') {
                throw new Exception('Please provide a notification title and message.');
            }

            // Confirm the event exists before sending anything.
            $eventCheckQuery = 'events?id=eq.' . rawurlencode($notifyEventId)
                . '&select=id,title,venue,start_datetime';
            if ($currentRole === 'event organizer') {
                $eventCheckQuery .= '&organizer_id=eq.' . rawurlencode((string)$_SESSION['user_id']);
            }
            $eventCheck = supabaseRequest($eventCheckQuery, 'GET');

            if (($eventCheck['code'] ?? 500) >= 400 || empty($eventCheck['data'])) {
                throw new Exception('The selected event could not be found.');
            }

            // Only participants with an Approved registration receive this announcement.
            $recipientsResponse = supabaseRequest(
                'event_registrations?event_id=eq.' . rawurlencode($notifyEventId) .
                '&status=eq.Approved&select=participant_id',
                'GET'
            );

            if (($recipientsResponse['code'] ?? 500) >= 400 || !is_array($recipientsResponse['data'] ?? null)) {
                throw new Exception('Unable to retrieve approved participants. No notifications were sent.');
            }

            $recipientIds = [];
            foreach ($recipientsResponse['data'] as $recipient) {
                $recipientId = trim((string)($recipient['participant_id'] ?? ''));
                if ($recipientId !== '') {
                    $recipientIds[$recipientId] = true;
                }
            }

            if (empty($recipientIds)) {
                throw new Exception('There are no approved participants registered for this event.');
            }

            $inAppSentCount = 0;
            $inAppFailedCount = 0;
            $emailSentCount = 0;
            $emailFailedCount = 0;
            $emailSkippedCount = 0;

            $selectedEvent = $eventCheck['data'][0];
            $eventTitleForEmail = (string)($selectedEvent['title'] ?? 'Zentrix Event');
            $eventVenueForEmail = (string)($selectedEvent['venue'] ?? '');
            $eventStartForEmail = formatManilaTime($selectedEvent['start_datetime'] ?? '');

            // Send an in-app notification and a Brevo email to each approved participant.
            foreach (array_keys($recipientIds) as $recipientId) {
                $notificationPayload = [
                    'participant_id' => $recipientId,
                    'event_id' => $notifyEventId,
                    'title' => $notificationTitle,
                    'message' => $notificationMessage,
                    'is_read' => false
                ];

                $sendResponse = supabaseRequest('notifications', 'POST', $notificationPayload);

                if (($sendResponse['code'] ?? 500) < 400) {
                    $inAppSentCount++;
                } else {
                    $inAppFailedCount++;
                }

                // The profile ID matches the participant_id used by registrations.
                // Select only email so this does not depend on unverified profile columns.
                $profileResponse = supabaseRequest(
                    'profiles?id=eq.' . rawurlencode($recipientId) . '&select=email',
                    'GET'
                );

                $profile = (
                    ($profileResponse['code'] ?? 500) < 400 &&
                    is_array($profileResponse['data'] ?? null) &&
                    !empty($profileResponse['data'])
                ) ? $profileResponse['data'][0] : [];

                $recipientEmail = trim((string)($profile['email'] ?? ''));
                if ($recipientEmail === '' || !filter_var($recipientEmail, FILTER_VALIDATE_EMAIL)) {
                    $emailSkippedCount++;
                    continue;
                }

                $safeTitle = h($notificationTitle);
                $safeEventTitle = h($eventTitleForEmail);
                $safeMessage = nl2br(h($notificationMessage));
                $safeVenue = h($eventVenueForEmail);
                $safeStart = h($eventStartForEmail);

                $emailHtml = '
                    <div style="font-family:Arial,sans-serif;max-width:600px;margin:0 auto;color:#273449;line-height:1.6;">
                        <div style="background:#343a40;color:#ffffff;padding:22px 26px;border-radius:10px 10px 0 0;">
                            <h2 style="margin:0;color:#ffffff;">Zentrix Event Notification</h2>
                        </div>
                        <div style="padding:24px;border:1px solid #e8edf4;border-top:0;border-radius:0 0 10px 10px;">
                            <h3 style="color:#198754;">' . $safeTitle . '</h3>
                            <p>' . $safeMessage . '</p>
                            <hr style="border:0;border-top:1px solid #e8edf4;margin:22px 0;">
                            <p><strong>Event:</strong> ' . $safeEventTitle . '</p>
                            ' . ($safeVenue !== '' ? '<p><strong>Venue:</strong> ' . $safeVenue . '</p>' : '') . '
                            ' . ($safeStart !== 'N/A' ? '<p><strong>Starts:</strong> ' . $safeStart . '</p>' : '') . '
                            <p style="color:#64748b;font-size:13px;margin-top:24px;">This is an automated message from the Zentrix. Please do not reply to this email.</p>
                        </div>
                    </div>';

                // sendSystemEmail returns true on success, otherwise an error string.
                $emailResult = sendSystemEmail(
                    $recipientEmail,
                    'Participant',
                    $notificationTitle . ' - Zentrix Event Notification',
                    $emailHtml
                );

                if ($emailResult === true) {
                    $emailSentCount++;
                } else {
                    $emailFailedCount++;
                    error_log('Zentrix Brevo notification failed for participant ' . $recipientId . ': ' . (string)$emailResult);
                }
            }

            logAudit($_SESSION['user_id'], 'event_notification_sent', 'Notification sent for event ' . ($selectedEvent['title'] ?? $notifyEventId) . '. Recipients: ' . count($recipientIds) . '; in-app sent: ' . $inAppSentCount . '; email sent: ' . $emailSentCount . '.');
            $successMsg = "In-app: {$inAppSentCount} sent, {$inAppFailedCount} failed. ";
            $successMsg .= "Email: {$emailSentCount} sent, {$emailFailedCount} failed, {$emailSkippedCount} skipped (missing/invalid email).";

            if ($inAppSentCount === 0 && $emailSentCount === 0) {
                throw new Exception('No notifications or emails were delivered. Check your Supabase profile email values and the server error log for Brevo details.');
            }
        } elseif ($action === 'create') {
            $payload = buildEventPayload();

            if ($payload['title'] === '' || $payload['venue'] === '') {
                throw new Exception('Please complete the event title and venue.');
            }

            if ($currentRole === 'event organizer') {
                $payload['organizer_id'] = (string)$_SESSION['user_id'];
            }

            $response = supabaseRequest('events', 'POST', $payload);

            if (($response['code'] ?? 500) >= 400) {
                throw new Exception(getSupabaseEventError($response, 'create'));
            }

            $createdEvent = is_array($response['data'] ?? null) && isset($response['data'][0])
                ? $response['data'][0]
                : null;

            logAudit($_SESSION['user_id'], 'event_created', 'Event created: ' . ($createdEvent['title'] ?? $payload['title']) . ' | Event record: ' . json_encode($createdEvent ?: $payload));
            $successMsg = 'Event created successfully!';

            // Automatically notify verified participant accounts only.
            // Staff, event organizers, and administrators are excluded by the role filter.
            $participantsResponse = supabaseRequest(
                'profiles?role=eq.Participant&is_verified=eq.true'
                . '&select=id,email,first_name,last_name,is_active&limit=1000',
                'GET'
            );

            if (($participantsResponse['code'] ?? 500) < 400 && is_array($participantsResponse['data'] ?? null)) {
                $automaticAnnouncementTitle = 'New Event Added: ' . $payload['title'];
                $automaticAnnouncementMessage = 'A new event has been added to Zentrix. '
                    . 'Date: ' . formatManilaTime($payload['start_datetime']) . '. '
                    . 'Venue: ' . ($payload['venue'] ?: 'To be announced') . '. '
                    . 'Open Browse Events to view the full details and register.';

                $announcementSent = 0;
                $announcementFailed = 0;
                $announcementEmailSent = 0;
                $announcementEmailFailed = 0;
                $announcementEmailSkipped = 0;

                $safeAnnouncementTitle = h($automaticAnnouncementTitle);
                $safeEventTitle = h($payload['title']);
                $safeEventVenue = h($payload['venue'] ?: 'To be announced');
                $safeEventDate = h(formatManilaTime($payload['start_datetime']));
                $safeAnnouncementMessage = nl2br(h($automaticAnnouncementMessage));

                foreach ($participantsResponse['data'] as $participant) {
                    // Match User Management: a missing is_active value is treated as active.
                    $isActive = !array_key_exists('is_active', $participant)
                        || $participant['is_active'] === null
                        || !in_array($participant['is_active'], [false, 0, '0', 'false'], true);

                    if (!$isActive) {
                        continue;
                    }

                    $participantId = trim((string)($participant['id'] ?? ''));
                    if ($participantId === '') {
                        continue;
                    }

                    $announcementPayload = [
                        'participant_id' => $participantId,
                        'event_id' => $createdEvent['id'] ?? null,
                        'title' => $automaticAnnouncementTitle,
                        'message' => $automaticAnnouncementMessage,
                        'is_read' => false
                    ];

                    $announcementResponse = supabaseRequest('notifications', 'POST', $announcementPayload);

                    if (($announcementResponse['code'] ?? 500) < 400) {
                        $announcementSent++;
                    } else {
                        $announcementFailed++;
                    }

                    $recipientEmail = trim((string)($participant['email'] ?? ''));
                    if ($recipientEmail === '' || !filter_var($recipientEmail, FILTER_VALIDATE_EMAIL)) {
                        $announcementEmailSkipped++;
                        continue;
                    }

                    $recipientName = trim(
                        (string)($participant['first_name'] ?? '') . ' ' .
                        (string)($participant['last_name'] ?? '')
                    );

                    $emailHtml = '
                        <div style="font-family:Arial,sans-serif;max-width:600px;margin:0 auto;color:#273449;line-height:1.6;">
                            <div style="background:#343a40;color:#ffffff;padding:22px 26px;border-radius:10px 10px 0 0;">
                                <h2 style="margin:0;color:#ffffff;">Zentrix - New Event Announcement</h2>
                            </div>
                            <div style="padding:24px;border:1px solid #e8edf4;border-top:0;border-radius:0 0 10px 10px;">
                                <h3 style="color:#198754;">' . $safeAnnouncementTitle . '</h3>
                                <p>' . $safeAnnouncementMessage . '</p>
                                <hr style="border:0;border-top:1px solid #e8edf4;margin:22px 0;">
                                <p><strong>Event:</strong> ' . $safeEventTitle . '</p>
                                <p><strong>Date &amp; time:</strong> ' . $safeEventDate . '</p>
                                <p><strong>Venue:</strong> ' . $safeEventVenue . '</p>
                                <p>Log in to Zentrix and open Browse Events to view the details and register.</p>
                                <p style="color:#64748b;font-size:13px;margin-top:24px;">This is an automated message from the Zentrix. Please do not reply to this email.</p>
                            </div>
                        </div>';

                    $emailResult = sendSystemEmail(
                        $recipientEmail,
                        $recipientName !== '' ? $recipientName : 'Participant',
                        $automaticAnnouncementTitle . ' - Zentrix',
                        $emailHtml
                    );

                    if ($emailResult === true) {
                        $announcementEmailSent++;
                    } else {
                        $announcementEmailFailed++;
                        error_log(
                            'Zentrix new-event email failed for participant ' . $participantId . ': ' .
                            (string)$emailResult
                        );
                    }
                }

                $successMsg .= " New-event in-app notifications: {$announcementSent} sent, {$announcementFailed} failed.";
                $successMsg .= " Email: {$announcementEmailSent} sent, {$announcementEmailFailed} failed, {$announcementEmailSkipped} skipped (missing/invalid email).";
            } else {
                $successMsg .= ' The event was created, but automatic participant notifications could not be sent because the participant list could not be retrieved.';
            }
        } elseif ($action === 'edit') {
            $updateId = trim($_POST['event_id'] ?? '');
            if ($updateId === '') {
                throw new Exception('Missing event ID.');
            }

            $beforeEditResponse = supabaseRequest('events?id=eq.' . rawurlencode($updateId) . '&select=*', 'GET');
            $beforeEdit = $beforeEditResponse['data'][0] ?? [];
            $payload = buildEventPayload();

            if ($payload['title'] === '' || $payload['venue'] === '') {
                throw new Exception('Please complete the event title and venue.');
            }

            if ($currentRole === 'event organizer') {
                $ownerCheck = supabaseRequest(
                    'events?id=eq.' . rawurlencode($updateId)
                    . '&organizer_id=eq.' . rawurlencode((string)$_SESSION['user_id'])
                    . '&select=id',
                    'GET'
                );
                if (($ownerCheck['code'] ?? 500) >= 400 || empty($ownerCheck['data'])) {
                    throw new Exception('You can only edit events assigned to you.');
                }
            }

            $response = supabaseRequest('events?id=eq.' . rawurlencode($updateId), 'PATCH', $payload);

            if (($response['code'] ?? 500) >= 400) {
                throw new Exception(getSupabaseEventError($response, 'update'));
            }

            logAudit($_SESSION['user_id'], 'event_updated', 'Event ' . ($beforeEdit['title'] ?? $updateId) . ' updated. Previous: ' . json_encode($beforeEdit) . ' | New: ' . json_encode($payload));
            $successMsg = 'Event updated successfully!';
        }
    } catch (Exception $e) {
        $errorMsg = $e->getMessage();
    }
}

// Fetch the original Zentrix events. No category table or category_id is required.
$eventQuery = 'events?select=*&order=start_datetime.asc';

$getResponse = supabaseRequest($eventQuery, 'GET');

$events = (($getResponse['code'] ?? 500) < 400 && is_array($getResponse['data'] ?? null))
    ? $getResponse['data']
    : [];

$calendarEvents = [];
foreach ($events as $event) {
    $status = getEventStatus(
        $event['start_datetime'] ?? '',
        $event['end_datetime'] ?? '',
        $event['status'] ?? 'Upcoming'
    );

    $color = '#4f46e5';
    if ($status === 'Ongoing') $color = '#0f9d75';
    if ($status === 'Completed') $color = '#64748b';

    $calendarEvents[] = [
        'id' => (string)($event['id'] ?? ''),
        'title' => $event['title'] ?? 'Untitled Event',
        'start' => $event['start_datetime'] ?? null,
        'end' => $event['end_datetime'] ?? null,
        'backgroundColor' => $color,
        'borderColor' => $color,
        'extendedProps' => [
            'description' => $event['description'] ?? '',
            'start_datetime' => $event['start_datetime'] ?? null,
            'end_datetime' => $event['end_datetime'] ?? null,
            'venue' => $event['venue'] ?? '',
            'capacity' => $event['capacity'] ?? '',
            'status' => $status,
            'image_url' => $event['image_url'] ?? '',
            'latitude' => $event['latitude'] ?? null,
            'longitude' => $event['longitude'] ?? null,
            'venue_boundary' => $event['venue_boundary'] ?? null
        ]
    ];
}

$calendarJson = json_encode(
    $calendarEvents,
    JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT
);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Event Management - Zentrix</title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/leaflet.draw/1.0.4/leaflet.draw.css">
    <script src="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.19/index.global.min.js"></script>

    <style>
        body { background: #f4f6fb; color: #273449; }
        .sidebar { min-height: 100vh; background: #343a40; color: white; }
        .sidebar a {
            color: #cfd8dc; text-decoration: none; padding: 12px 20px; display: block;
        }
        .sidebar a:hover, .sidebar a.active {
            background: #495057; color: white; border-left: 4px solid #0d6efd;
        }
        .content-area { padding: 30px; background: #f4f6fb; min-height: 100vh; }
        .page-card {
            background: #fff; border: 1px solid #e8edf4; border-radius: 15px;
            box-shadow: 0 4px 20px rgba(30, 50, 80, .05);
        }
        .section-heading { font-weight: 700; color: #273449; }
        #calendar {
            background: #fff; padding: 24px; border: 1px solid #e8edf4;
            border-radius: 15px; box-shadow: 0 4px 20px rgba(30, 50, 80, .05);
        }
        .calendar-legend {
            display: flex; flex-wrap: wrap; gap: 16px; align-items: center;
            padding: 13px 17px; margin-bottom: 16px; background: #fff;
            border: 1px solid #e8edf4; border-radius: 11px;
        }
        .legend-item { display: flex; align-items: center; gap: 7px; color: #64748b; font-size: .85rem; }
        .legend-dot { width: 10px; height: 10px; border-radius: 50%; display: inline-block; }
        .fc .fc-toolbar { margin-bottom: 22px !important; gap: 12px; }
        .fc .fc-toolbar-title { font-size: 1.55rem; font-weight: 700; color: #273449; }
        .fc .fc-button-primary {
            background: #fff; border: 1px solid #dce3ed; color: #475569;
            border-radius: 8px; padding: 8px 12px; font-weight: 600; box-shadow: none !important;
        }
        .fc .fc-button-primary:hover { background: #eef2ff; border-color: #c7d2fe; color: #4f46e5; }
        .fc .fc-button-primary:active, .fc .fc-button-primary.fc-button-active {
            background: #4f46e5 !important; border-color: #4f46e5 !important; color: #fff !important;
        }
        .fc .fc-col-header-cell { background: #f8fafc; padding: 11px 0; }
        .fc .fc-col-header-cell-cushion {
            color: #64748b; font-size: .78rem; font-weight: 700;
            text-transform: uppercase; letter-spacing: .6px; text-decoration: none;
        }
        .fc .fc-daygrid-day-number {
            color: #475569; font-size: .88rem; font-weight: 600; padding: 9px; text-decoration: none;
        }
        .fc .fc-day-today { background: #eef2ff !important; }
        .fc .fc-day-today .fc-daygrid-day-number { color: #4f46e5; font-weight: 800; }
        .fc .fc-day-other .fc-daygrid-day-number { color: #cbd5e1; }
        .fc-theme-standard td, .fc-theme-standard th { border-color: #edf0f5; }
        .fc-theme-standard .fc-scrollgrid { border: 1px solid #edf0f5; border-radius: 10px; overflow: hidden; }
        .fc .fc-daygrid-event {
            border: 0; border-left: 4px solid rgba(0,0,0,.16); border-radius: 6px;
            padding: 4px 6px; margin: 3px 5px; cursor: pointer;
            transition: transform .15s ease, box-shadow .15s ease;
        }
        .fc .fc-daygrid-event:hover { transform: translateY(-1px); box-shadow: 0 3px 8px rgba(0,0,0,.12); }
        .fc .fc-event-title { font-size: .78rem; font-weight: 600; }
        .fc .fc-event-time { font-size: .72rem; font-weight: 500; }
        .fc .fc-daygrid-more-link { color: #4f46e5; font-weight: 600; text-decoration: none; }
        #searchEvents {
            min-height: 44px; border: 1px solid #dce3ed; border-radius: 9px;
            padding: 10px 14px; box-shadow: none;
        }
        #searchEvents:focus { border-color: #818cf8; box-shadow: 0 0 0 3px rgba(99,102,241,.12); }
        /* Keep the event details dialog content-sized instead of stretching it to the viewport. */
        #eventDetailsModal {
            overflow-y: auto;
        }
        #eventDetailsModal .modal-dialog-centered {
            min-height: 0;
            align-items: flex-start;
            margin: 1.5rem auto;
        }
        #eventDetailsModal .event-modal-content {
            height: auto !important;
            min-height: 0 !important;
            max-height: none !important;
            border: 0;
            border-radius: 16px;
            overflow: hidden;
        }
        .event-modal-header {
            background: linear-gradient(135deg, #4338ca, #6366f1);
            border: 0; padding: 20px 25px; color: white;
            flex: 0 0 auto;
        }
        .event-modal-header .modal-title { color: white; font-weight: 700; }
        #eventDetailsModal .event-modal-body {
            height: auto;
            min-height: 0;
            padding: 20px 25px;
            overflow: visible !important;
            flex: 0 0 auto;
        }
        #eventDetailsModal .event-description {
            display: block !important;
            position: static !important;
            box-sizing: border-box;
            width: 100%;
            height: auto !important;
            min-height: 0 !important;
            padding: 16px !important;
            margin: 0 0 16px !important;
            background: #f8fafc;
            border: 1px solid #edf0f5;
            border-radius: 10px;
            white-space: normal;
            overflow-wrap: anywhere;
        }
        #eventDetailsModal .event-description h6 {
            display: block;
            margin: 0 0 12px !important;
            padding: 0;
            line-height: 1.3;
        }
        #eventDetailsModal .event-description p {
            display: block;
            margin: 0 !important;
            padding: 0;
            line-height: 1.5;
            white-space: pre-wrap;
        }
        #eventDetailsModal .modal-footer { flex: 0 0 auto; }
        .event-info-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
        .event-info-item {
            padding: 14px; border: 1px solid #edf0f5; border-radius: 10px;
            display: flex; flex-direction: column; gap: 5px;
        }
        .event-info-label { color: #94a3b8; font-size: .7rem; font-weight: 700; letter-spacing: .7px; }
        .event-info-item strong { color: #334155; font-size: .9rem; overflow-wrap: anywhere; }
        #modalMap { width: 100%; height: 220px; border: 0; border-radius: 10px; display: none; }
        .event-location-map {
            width: 100%;
            height: 320px;
            border: 1px solid #dce3ed;
            border-radius: 10px;
            z-index: 1;
        }
        .location-picker-help { color: #64748b; font-size: .85rem; }
        @media (max-width: 900px) {
            .sidebar { width: 205px !important; }
            .content-area { padding: 20px; }
        }
        @media (max-width: 768px) {
            .d-flex.page-layout { display: block !important; }
            .sidebar { min-height: auto; width: 100% !important; }
            .sidebar nav { display: flex; flex-wrap: wrap; }
            .sidebar a { padding: 9px 12px; }
            .content-area { padding: 15px; }
            #calendar { padding: 12px; }
            .fc .fc-toolbar { flex-direction: column; align-items: stretch; }
            .fc .fc-toolbar-title { font-size: 1.25rem; text-align: center; }
            .fc .fc-toolbar-chunk { display: flex; justify-content: center; }
            .event-info-grid { grid-template-columns: 1fr; }
        }
    </style>
    <link rel="stylesheet" href="../css/portal-theme.css?v=20261118-premium">
</head>
<body class="admin-portal">
<?php require_once __DIR__ . '/../includes/admin-nav.php'; ?>
<main class="content-area admin-main-content">

        <div class="admin-page-heading admin-section-heading d-flex justify-content-between align-items-center flex-wrap gap-3 mb-4">
            <div>
                <h2 class="mb-1">Event Management</h2>
                <p class="text-muted mb-0">View your schedule and manage Zentrix events.</p>
            </div>
            <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#createEventModal">
                <i class="bi bi-plus-circle me-2"></i> Create Event
            </button>
        </div>

        <?php if ($successMsg): ?>
            <div class="alert alert-success alert-dismissible fade show"><?= h($successMsg) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>
        <?php if ($errorMsg): ?>
            <div class="alert alert-danger alert-dismissible fade show"><?= h($errorMsg) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>
        <?php if (($getResponse['code'] ?? 500) >= 400): ?>
            <div class="alert alert-danger">Unable to retrieve events from Supabase. HTTP Status: <?= h($getResponse['code'] ?? 'Unknown') ?></div>
        <?php endif; ?>

        <!-- Calendar controls -->
        <section class="mb-4">
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
                <div>
                    <h4 class="section-heading mb-1">Event Calendar</h4>
                    <small class="text-muted">Select an event to view its details.</small>
                </div>
                <div class="d-flex align-items-center gap-2">
                    <i class="bi bi-search text-muted"></i>
                    <input type="search" id="searchEvents" class="form-control" style="width:min(320px,70vw);" placeholder="Search events...">
                </div>
            </div>

            <div class="calendar-legend">
                <strong class="me-1">Event Status:</strong>
                <span class="legend-item"><span class="legend-dot" style="background:#4f46e5"></span> Upcoming</span>
                <span class="legend-item"><span class="legend-dot" style="background:#0f9d75"></span> Ongoing</span>
                <span class="legend-item"><span class="legend-dot" style="background:#64748b"></span> Completed</span>
            </div>

            <div id="calendar"></div>
        </section>

        <!-- Existing Zentrix event table -->
        <section class="page-card">
            <div class="p-3 p-md-4 border-bottom">
                <h4 class="section-heading mb-1">All Events</h4>
                <small class="text-muted">Your existing event records and management actions.</small>
            </div>
            <div class="table-responsive">
                <table class="table table-hover mb-0 align-middle" id="eventsTable">
                    <thead class="table-light">
                        <tr>
                            <th class="ps-3">Title</th>
                            <th>Venue</th>
                            <th>Date &amp; Time (PHT)</th>
                            <th>Capacity</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (empty($events)): ?>
                        <tr class="empty-row"><td colspan="6" class="text-center py-4 text-muted">No events found. Create one above!</td></tr>
                    <?php else: ?>
                        <?php foreach ($events as $event):
                            $eventId = (string)($event['id'] ?? '');
                            $status = getEventStatus(
                                $event['start_datetime'] ?? '',
                                $event['end_datetime'] ?? '',
                                $event['status'] ?? 'Upcoming'
                            );
                            $badgeClass = $status === 'Upcoming' ? 'success' : ($status === 'Ongoing' ? 'warning text-dark' : 'secondary');
                        ?>
                            <tr class="event-table-row"
                                data-search="<?= h(strtolower(($event['title'] ?? '') . ' ' . ($event['venue'] ?? '') . ' ' . ($event['description'] ?? ''))) ?>">
                                <td class="ps-3 fw-semibold text-primary"><?= h($event['title'] ?? '') ?></td>
                                <td><?= h($event['venue'] ?? '') ?></td>
                                <td>
                                    <small class="d-block text-muted">Start: <?= h(formatManilaTime($event['start_datetime'] ?? '')) ?></small>
                                    <small class="d-block text-muted">End: <?= h(formatManilaTime($event['end_datetime'] ?? '')) ?></small>
                                </td>
                                <td><?= h($event['capacity'] ?? '') ?></td>
                                <td><span class="badge bg-<?= h($badgeClass) ?>"><?= h($status) ?></span></td>
                                <td>
                                    <div class="d-flex align-items-center gap-2">
                                        <?php if (in_array($currentRole, ['administrator', 'event organizer'], true)): ?>
                                            <button class="btn btn-sm btn-outline-success notify-event-button" type="button"
                                                data-bs-toggle="modal" data-bs-target="#notifyParticipantsModal"
                                                data-id="<?= h($eventId) ?>"
                                                data-title="<?= h($event['title'] ?? '') ?>"
                                                title="Notify Approved Participants">
                                                <i class="bi bi-bell"></i>
                                            </button>
                                        <?php endif; ?>
                                        <button class="btn btn-sm btn-outline-secondary copy-event-id" type="button" data-id="<?= h($eventId) ?>" title="Copy Event ID">
                                            <i class="bi bi-clipboard"></i>
                                        </button>
                                        <button class="btn btn-sm btn-outline-primary edit-event-button" type="button"
                                            data-bs-toggle="modal" data-bs-target="#editEventModal"
                                            data-id="<?= h($eventId) ?>"
                                            title="Edit Event">
                                            <i class="bi bi-pencil"></i>
                                        </button>
                                        <?php if (in_array($currentRole, ['administrator', 'event organizer'], true)): ?>
                                            <form method="POST" class="mb-0" onsubmit="return confirm('Delete this event?');">
                                                <input type="hidden" name="action" value="delete">
                                                <input type="hidden" name="event_id" value="<?= h($eventId) ?>">
                                                <button type="submit" class="btn btn-sm btn-outline-danger" title="Delete Event">
                                                    <i class="bi bi-trash"></i>
                                                </button>
                                            </form>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>
    


<!-- Calendar event details modal -->
<div class="modal fade" id="eventDetailsModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content event-modal-content">
            <div class="modal-header event-modal-header">
                <div>
                    <span class="badge bg-light text-primary" id="modalStatus">Upcoming</span>
                    <h4 class="modal-title mt-2 mb-0" id="modalTitle">Event title</h4>
                </div>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body event-modal-body">
                <div class="event-description mb-3">
                    <h6 class="fw-bold">Description</h6>
                    <p id="modalDescription" class="mb-0">No description provided.</p>
                </div>
                <div class="event-info-grid mb-3">
                    <div class="event-info-item">
                        <span class="event-info-label">START</span>
                        <strong id="modalStart"></strong>
                    </div>
                    <div class="event-info-item">
                        <span class="event-info-label">END</span>
                        <strong id="modalEnd"></strong>
                    </div>
                    <div class="event-info-item">
                        <span class="event-info-label">VENUE</span>
                        <strong id="modalVenue"></strong>
                    </div>
                    <div class="event-info-item">
                        <span class="event-info-label">CAPACITY</span>
                        <strong id="modalCapacity"></strong>
                    </div>
                </div>
                <img id="modalImage" src="" alt="Event cover" class="img-fluid rounded mb-3" style="display:none;max-height:260px;width:100%;object-fit:cover;">
                <iframe id="modalMap" loading="lazy" referrerpolicy="no-referrer-when-downgrade" title="Event location map"></iframe>
                <a id="modalMapLink" href="#" target="_blank" rel="noopener" class="btn btn-outline-primary btn-sm mt-2" style="display:none;">
                    <i class="bi bi-geo-alt me-1"></i> Open location in Google Maps
                </a>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<!-- Send notification to approved participants -->
<div class="modal fade" id="notifyParticipantsModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow">
            <div class="modal-header bg-success text-white">
                <div>
                    <h5 class="modal-title text-white mb-1"><i class="bi bi-bell me-2"></i>Notify Participants</h5>
                    <small class="text-white-50">Send an in-app announcement to approved registrants.</small>
                </div>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <input type="hidden" name="action" value="send_notification">
                    <input type="hidden" name="event_id" id="notify-event-id">
                    <div class="mb-3">
                        <label class="form-label fw-bold">Event</label>
                        <input type="text" id="notify-event-title" class="form-control" readonly>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold">Notification Title</label>
                        <input type="text" name="notification_title" class="form-control" maxlength="150"
                               required placeholder="e.g., Important Event Update">
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold">Message</label>
                        <textarea name="notification_message" class="form-control" rows="4" maxlength="3000"
                                  required placeholder="Write your announcement here..."></textarea>
                    </div>
                    <div class="alert alert-info small mb-0">
                        <i class="bi bi-info-circle me-1"></i>
                        This will create an in-app notification and send an email through Brevo to every participant with an Approved registration and a valid email address.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-success">
                        <i class="bi bi-send me-1"></i> Send Notification
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Create Event Modal: original Zentrix fields retained -->
<div class="modal fade" id="createEventModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-dark text-white">
                <h5 class="modal-title text-white">Create New Event</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <input type="hidden" name="action" value="create">
                    <div class="mb-3">
                        <label class="form-label fw-bold">Event Title</label>
                        <input type="text" name="title" class="form-control" required placeholder="e.g., Annual Tech Symposium">
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold">Description</label>
                        <textarea name="description" class="form-control" rows="3" required></textarea>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold text-primary">Cover Image URL (Optional)</label>
                        <input type="url" name="image_url" class="form-control" placeholder="https://example.com/image.jpg">
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-bold">Venue Name</label>
                            <input type="text" name="venue" class="form-control" required placeholder="e.g., Main Auditorium">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-bold">Participant Capacity</label>
                            <input type="number" name="capacity" class="form-control" required min="1" value="100">
                        </div>
                    </div>
                    <div class="mb-3 p-3 bg-light rounded border">
                        <label class="form-label fw-bold text-primary"><i class="bi bi-geo-alt-fill me-1"></i> Select Event Location (Optional)</label>
                        <p class="location-picker-help mb-2">Click to set the event pin, then use the polygon drawing tool to mark the entire venue boundary. Participants can attend inside it or up to 50m outside.</p>
                        <div class="input-group mb-2">
                            <input type="search" id="create-location-search" class="form-control" placeholder="Search a place, street, or landmark..." aria-label="Search event location">
                            <button type="button" id="create-location-search-button" class="btn btn-outline-primary"><i class="bi bi-search me-1"></i> Search</button>
                        </div>
                        <div id="createLocationMap" class="event-location-map"></div>
                        <input type="hidden" name="latitude" id="create-lat">
                        <input type="hidden" name="longitude" id="create-lng">
                        <input type="hidden" name="venue_boundary" id="create-boundary">
                        <small id="create-location-status" class="text-muted d-block mt-2">Set a pin and draw the venue boundary.</small>
                    </div>
                    <div class="row">
                        <div class="col-md-4 mb-3">
                            <label class="form-label fw-bold">Status</label>
                            <select name="status" class="form-select">
                                <option value="Upcoming">Upcoming</option>
                                <option value="Ongoing">Ongoing</option>
                                <option value="Completed">Completed</option>
                            </select>
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label fw-bold">Start Date &amp; Time (PHT)</label>
                            <input type="datetime-local" name="start_datetime" class="form-control" required>
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label fw-bold">End Date &amp; Time (PHT)</label>
                            <input type="datetime-local" name="end_datetime" class="form-control" required>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Save Event</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Event Modal -->
<div class="modal fade" id="editEventModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title text-white">Edit Event</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <input type="hidden" name="action" value="edit">
                    <input type="hidden" name="event_id" id="edit-id">
                    <div class="mb-3">
                        <label class="form-label fw-bold">Event Title</label>
                        <input type="text" name="title" id="edit-title" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold">Description</label>
                        <textarea name="description" id="edit-desc" class="form-control" rows="3" required></textarea>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold text-primary">Cover Image URL (Optional)</label>
                        <input type="url" name="image_url" id="edit-image" class="form-control">
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-bold">Venue Name</label>
                            <input type="text" name="venue" id="edit-venue" class="form-control" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-bold">Participant Capacity</label>
                            <input type="number" name="capacity" id="edit-cap" class="form-control" required min="1">
                        </div>
                    </div>
                    <div class="mb-3 p-3 bg-light rounded border">
                        <label class="form-label fw-bold text-primary"><i class="bi bi-geo-alt-fill me-1"></i> Select Event Location (Optional)</label>
                        <p class="location-picker-help mb-2">Set the event pin, then use the polygon drawing tool to mark the entire venue boundary. Participants can attend inside it or up to 50m outside.</p>
                        <div class="input-group mb-2">
                            <input type="search" id="edit-location-search" class="form-control" placeholder="Search a place, street, or landmark..." aria-label="Search event location">
                            <button type="button" id="edit-location-search-button" class="btn btn-outline-primary"><i class="bi bi-search me-1"></i> Search</button>
                        </div>
                        <div id="editLocationMap" class="event-location-map"></div>
                        <input type="hidden" name="latitude" id="edit-lat">
                        <input type="hidden" name="longitude" id="edit-lng">
                        <input type="hidden" name="venue_boundary" id="edit-boundary">
                        <small id="edit-location-status" class="text-muted d-block mt-2">Set a pin and draw the venue boundary.</small>
                    </div>
                    <div class="row">
                        <div class="col-md-4 mb-3">
                            <label class="form-label fw-bold">Status</label>
                            <select name="status" id="edit-status" class="form-select">
                                <option value="Upcoming">Upcoming</option>
                                <option value="Ongoing">Ongoing</option>
                                <option value="Completed">Completed</option>
                            </select>
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label fw-bold">Start Date &amp; Time (PHT)</label>
                            <input type="datetime-local" name="start_datetime" id="edit-start" class="form-control" required>
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label fw-bold">End Date &amp; Time (PHT)</label>
                            <input type="datetime-local" name="end_datetime" id="edit-end" class="form-control" required>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Update Event</button>
                </div>
            </form>
        </div>
    </div>

</main>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/leaflet.draw/1.0.4/leaflet.draw.js"></script>
<script>
const emisEvents = <?= $calendarJson ?: '[]' ?>;

// Build an edit-modal lookup from the same event records already fetched by PHP.
const editEventData = <?= json_encode(array_values($events), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?: '[]' ?>;

// Interactive event location pickers. These only collect coordinates;
// the existing Google Maps embeds and links elsewhere on this page remain unchanged.
const eventLocationPickers = {};

function createEventLocationPicker(mapId, latitudeId, longitudeId, boundaryId, statusId, searchInputId, searchButtonId) {
    const mapElement = document.getElementById(mapId);
    const latitudeInput = document.getElementById(latitudeId);
    const longitudeInput = document.getElementById(longitudeId);
    const boundaryInput = document.getElementById(boundaryId);
    const statusElement = document.getElementById(statusId);
    const searchInput = document.getElementById(searchInputId);
    const searchButton = document.getElementById(searchButtonId);
    const map = L.map(mapElement).setView([15.225, 120.57], 13);
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        maxZoom: 19,
        attribution: '&copy; <a href="https://www.openstreetmap.org/copyright" target="_blank" rel="noopener">OpenStreetMap</a> contributors'
    }).addTo(map);

    const drawnItems = new L.FeatureGroup().addTo(map);
    const drawControl = new L.Control.Draw({
        draw: { polygon: { allowIntersection: false, showArea: true }, rectangle: true, polyline: false, circle: false, circlemarker: false, marker: false },
        edit: { featureGroup: drawnItems, remove: true }
    });
    map.addControl(drawControl);
    let marker = null;

    function saveBoundary() {
        const layers = drawnItems.getLayers();
        if (!layers.length) {
            boundaryInput.value = '';
            statusElement.textContent = 'No venue boundary drawn. Draw the actual venue area to enable attendance geofencing.';
            return;
        }
        const layer = layers[0];
        const latlngs = layer.getLatLngs();
        const ring = Array.isArray(latlngs[0]) ? latlngs[0] : latlngs;
        const points = ring.map(point => [Number(point.lat.toFixed(7)), Number(point.lng.toFixed(7))]);
        boundaryInput.value = JSON.stringify(points);
        statusElement.textContent = 'Venue boundary saved with ' + points.length + ' points. Attendance allowed inside and up to 50m outside.';
    }

    function updateSelection(latitude, longitude, shouldPan = false) {
        const lat = Number(latitude), lng = Number(longitude);
        if (!Number.isFinite(lat) || !Number.isFinite(lng)) return;
        latitudeInput.value = lat.toFixed(7);
        longitudeInput.value = lng.toFixed(7);
        const pos = [lat, lng];
        if (!marker) {
            marker = L.marker(pos, { draggable: true }).addTo(map);
            marker.on('dragend', function () {
                const p = marker.getLatLng();
                updateSelection(p.lat, p.lng);
            });
        } else marker.setLatLng(pos);
        if (shouldPan) map.setView(pos, Math.max(map.getZoom(), 16));
    }

    map.on(L.Draw.Event.CREATED, function (event) {
        drawnItems.clearLayers();
        drawnItems.addLayer(event.layer);
        saveBoundary();
    });
    map.on(L.Draw.Event.EDITED, saveBoundary);
    map.on(L.Draw.Event.DELETED, saveBoundary);
    map.on('click', function (event) { updateSelection(event.latlng.lat, event.latlng.lng); });

    async function searchLocation() {
        const query = searchInput.value.trim();
        if (!query) { statusElement.textContent = 'Enter a place or address to search.'; return; }
        const original = searchButton.innerHTML;
        searchButton.disabled = true;
        searchButton.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Searching...';
        try {
            const response = await fetch('https://nominatim.openstreetmap.org/search?format=jsonv2&limit=1&q=' + encodeURIComponent(query), { headers: { 'Accept': 'application/json' } });
            if (!response.ok) throw new Error('The location search service is unavailable.');
            const results = await response.json();
            if (!results.length) { statusElement.textContent = 'No matching place found.'; return; }
            updateSelection(Number(results[0].lat), Number(results[0].lon), true);
            statusElement.textContent = 'Found: ' + (results[0].display_name || query) + '. Draw the venue boundary.';
        } catch (error) { statusElement.textContent = error.message || 'Location search failed.'; }
        finally { searchButton.disabled = false; searchButton.innerHTML = original; }
    }
    searchButton.addEventListener('click', searchLocation);
    searchInput.addEventListener('keydown', event => { if (event.key === 'Enter') { event.preventDefault(); searchLocation(); } });

    return {
        map,
        updateSelection,
        loadBoundary(points) {
            drawnItems.clearLayers();
            if (Array.isArray(points) && points.length >= 3) {
                const polygon = L.polygon(points);
                drawnItems.addLayer(polygon);
                map.fitBounds(polygon.getBounds(), { padding: [20, 20] });
                saveBoundary();
            } else {
                boundaryInput.value = '';
                statusElement.textContent = 'No venue boundary saved. Draw one on the map.';
            }
        },
        clearSelection() {
            if (marker) { map.removeLayer(marker); marker = null; }
            latitudeInput.value = '';
            longitudeInput.value = '';
            drawnItems.clearLayers();
            boundaryInput.value = '';
            statusElement.textContent = 'Set a pin and draw the venue boundary.';
        },
        refresh() { map.invalidateSize(); }
    };
}


function initAdminEventsPage() {
    const calendarElement = document.getElementById('calendar');
    if (!calendarElement || calendarElement.dataset.emisInitialized === 'true') return;
    calendarElement.dataset.emisInitialized = 'true';

    const createModalElement = document.getElementById('createEventModal');
    const editModalElement = document.getElementById('editEventModal');

    createModalElement.addEventListener('shown.bs.modal', function () {
        if (!eventLocationPickers.create) {
            eventLocationPickers.create = createEventLocationPicker(
                'createLocationMap', 'create-lat', 'create-lng', 'create-boundary', 'create-location-status',
                'create-location-search', 'create-location-search-button'
            );
        }
        eventLocationPickers.create.refresh();
    });

    editModalElement.addEventListener('shown.bs.modal', function () {
        if (!eventLocationPickers.edit) {
            eventLocationPickers.edit = createEventLocationPicker(
                'editLocationMap', 'edit-lat', 'edit-lng', 'edit-boundary', 'edit-location-status',
                'edit-location-search', 'edit-location-search-button'
            );
        }
        eventLocationPickers.edit.refresh();

        const latitude = document.getElementById('edit-lat').value;
        const longitude = document.getElementById('edit-lng').value;
        if (latitude !== '' && longitude !== '') {
            eventLocationPickers.edit.updateSelection(latitude, longitude, true);
        }
        const savedBoundary = document.getElementById('edit-boundary').value;
        if (savedBoundary) {
            try { eventLocationPickers.edit.loadBoundary(JSON.parse(savedBoundary)); }
            catch (error) { eventLocationPickers.edit.loadBoundary(null); }
        } else if (latitude === '' || longitude === '') {
            eventLocationPickers.edit.clearSelection();
        }
    });

    const searchInput = document.getElementById('searchEvents');
    const eventModalElement = document.getElementById('eventDetailsModal');
    const detailsModal = bootstrap.Modal.getOrCreateInstance(eventModalElement);

    // Centered, theme-matched date jump dialog.
    const jumpDialogStyle = document.createElement('style');
    jumpDialogStyle.textContent = `
        .calendar-jump-backdrop{position:fixed;inset:0;z-index:2000;display:none;align-items:center;justify-content:center;padding:20px;background:rgba(5,3,10,.72);backdrop-filter:blur(4px)}
        .calendar-jump-backdrop.is-open{display:flex}
        .calendar-jump-dialog{width:min(100%,390px);padding:24px;border:1px solid var(--admin-border,#49375e);border-radius:18px;background:var(--admin-surface,#1a1328);color:var(--admin-text,#f4efff);box-shadow:0 24px 70px rgba(0,0,0,.5)}
        .calendar-jump-heading{margin:0 0 6px;font-size:18px;font-weight:800;color:var(--admin-text,#fff)}
        .calendar-jump-help{margin:0 0 18px;color:var(--admin-muted,#aaa0ba);font-size:13px}
        .calendar-jump-date{display:block;width:100%;min-height:48px;padding:10px 12px;border:1px solid var(--admin-border,#49375e);border-radius:10px;background:#120d1d;color:var(--admin-text,#fff);color-scheme:dark;font:inherit;font-size:15px}
        .calendar-jump-actions{display:flex;justify-content:flex-end;gap:10px;margin-top:20px}
        .calendar-jump-actions button{min-height:40px;padding:9px 16px;border-radius:9px;font:inherit;font-size:13px;font-weight:750;cursor:pointer}
        .calendar-jump-cancel{border:1px solid var(--admin-border,#49375e);background:transparent;color:var(--admin-text,#f4efff)}
        .calendar-jump-go{border:1px solid #8054c0;background:#7140b5;color:#fff}
        .calendar-jump-actions button:hover{filter:brightness(1.12)}
        @media(max-width:480px){.calendar-jump-dialog{padding:20px}}
    `;
    document.head.appendChild(jumpDialogStyle);

    const jumpBackdrop = document.createElement('div');
    jumpBackdrop.className = 'calendar-jump-backdrop';
    jumpBackdrop.innerHTML = `
        <section class="calendar-jump-dialog" role="dialog" aria-modal="true" aria-labelledby="calendarJumpTitle">
            <h3 class="calendar-jump-heading" id="calendarJumpTitle">Jump to a date</h3>
            <p class="calendar-jump-help">Choose a year, month, and day to go directly to that part of the calendar.</p>
            <input class="calendar-jump-date" type="date" id="calendarJumpDate" aria-label="Choose calendar date">
            <div class="calendar-jump-actions">
                <button type="button" class="calendar-jump-cancel" id="calendarJumpCancel">Cancel</button>
                <button type="button" class="calendar-jump-go" id="calendarJumpGo">Go to date</button>
            </div>
        </section>
    `;
    document.body.appendChild(jumpBackdrop);

    const calendarJumpPicker = jumpBackdrop.querySelector('#calendarJumpDate');
    const closeCalendarJump = () => {
        jumpBackdrop.classList.remove('is-open');
    };
    const openCalendarJump = () => {
        calendarJumpPicker.value = calendar.formatDate(calendar.getDate(), { formatStr: 'yyyy-MM-dd' });
        jumpBackdrop.classList.add('is-open');
        requestAnimationFrame(() => calendarJumpPicker.focus());
    };
    const applyCalendarJump = () => {
        if (!calendarJumpPicker.value) {
            calendarJumpPicker.focus();
            return;
        }
        calendar.gotoDate(calendarJumpPicker.value);
        closeCalendarJump();
    };

    jumpBackdrop.addEventListener('click', event => {
        if (event.target === jumpBackdrop) closeCalendarJump();
    });
    jumpBackdrop.querySelector('#calendarJumpCancel').addEventListener('click', closeCalendarJump);
    jumpBackdrop.querySelector('#calendarJumpGo').addEventListener('click', applyCalendarJump);
    calendarJumpPicker.addEventListener('keydown', event => {
        if (event.key === 'Enter') applyCalendarJump();
        if (event.key === 'Escape') closeCalendarJump();
    });
    document.addEventListener('keydown', event => {
        if (event.key === 'Escape' && jumpBackdrop.classList.contains('is-open')) closeCalendarJump();
    });

    const calendar = new FullCalendar.Calendar(calendarElement, {
        initialView: 'dayGridMonth',
        timeZone: 'Asia/Manila',
        height: 'auto',
        fixedWeekCount: false,
        dayMaxEvents: true,
        eventDisplay: 'block',
        customButtons: {
            jumpToDate: {
                text: 'Jump',
                click: function () {
                    openCalendarJump();
                }
            }
        },
        headerToolbar: {
            left: 'prev,next',
            center: 'title',
            right: 'jumpToDate today'
        },
        events: emisEvents,
        eventClick: function (info) {
            info.jsEvent.preventDefault();

            const event = info.event;
            const props = event.extendedProps;

            document.getElementById('modalTitle').textContent = event.title;
            document.getElementById('modalDescription').textContent = props.description || 'No description provided.';
            document.getElementById('modalVenue').textContent = props.venue || 'Not specified';
            document.getElementById('modalCapacity').textContent = props.capacity || 'Not specified';
            document.getElementById('modalStatus').textContent = props.status || 'Upcoming';

            const dateOptions = { timeZone: 'Asia/Manila', year: 'numeric', month: 'short', day: 'numeric' };
            const timeOptions = { timeZone: 'Asia/Manila', hour: 'numeric', minute: '2-digit' };

            // Format the original Supabase ISO timestamps directly.
            // Do not use FullCalendar's event.start/event.end here: with named
            // timezones and no timezone plugin, those Date objects can be UTC-coerced.
            const formatEventManilaTime = value => {
                if (!value) return 'Not specified';
                const date = new Date(value);
                if (Number.isNaN(date.getTime())) return 'Not specified';
                return new Intl.DateTimeFormat('en-PH', {
                    timeZone: 'Asia/Manila',
                    year: 'numeric',
                    month: 'short',
                    day: 'numeric',
                    hour: 'numeric',
                    minute: '2-digit'
                }).format(date);
            };

            document.getElementById('modalStart').textContent =
                formatEventManilaTime(props.start_datetime);
            document.getElementById('modalEnd').textContent =
                formatEventManilaTime(props.end_datetime);

            const image = document.getElementById('modalImage');
            if (props.image_url) {
                image.src = props.image_url;
                image.style.display = 'block';
                image.onerror = () => { image.style.display = 'none'; };
            } else {
                image.removeAttribute('src');
                image.style.display = 'none';
            }

            const map = document.getElementById('modalMap');
            const mapLink = document.getElementById('modalMapLink');
            if (props.latitude !== null && props.latitude !== '' &&
                props.longitude !== null && props.longitude !== '') {
                const coordinates = `${props.latitude},${props.longitude}`;
                map.src = `https://maps.google.com/maps?q=${encodeURIComponent(coordinates)}&z=15&output=embed`;
                map.style.display = 'block';
                mapLink.href = `https://www.google.com/maps?q=${encodeURIComponent(coordinates)}`;
                mapLink.style.display = 'inline-block';
            } else {
                map.removeAttribute('src');
                map.style.display = 'none';
                mapLink.style.display = 'none';
            }

            detailsModal.show();
        }
    });

    calendar.render();

    // Search filters both the calendar events and the table rows.
    searchInput.addEventListener('input', function () {
        const query = this.value.trim().toLowerCase();

        calendar.getEvents().forEach(function (event) {
            const props = event.extendedProps;
            const searchableText = [
                event.title,
                props.venue,
                props.description,
                props.status
            ].join(' ').toLowerCase();

            event.setProp('display', searchableText.includes(query) ? 'auto' : 'none');
        });

        document.querySelectorAll('.event-table-row').forEach(function (row) {
            row.style.display = row.dataset.search.includes(query) ? '' : 'none';
        });
    });

    // Populate the existing Zentrix edit form from the same events data.
    document.querySelectorAll('.edit-event-button').forEach(function (button) {
        button.addEventListener('click', function () {
            const id = this.dataset.id;
            const event = editEventData.find(item => String(item.id) === String(id));
            if (!event) return;

            const toLocalInput = value => {
                if (!value) return '';
                const date = new Date(value);
                const parts = new Intl.DateTimeFormat('en-CA', {
                    timeZone: 'Asia/Manila',
                    year: 'numeric', month: '2-digit', day: '2-digit',
                    hour: '2-digit', minute: '2-digit', hourCycle: 'h23'
                }).formatToParts(date);
                const get = type => parts.find(part => part.type === type)?.value || '';
                return `${get('year')}-${get('month')}-${get('day')}T${get('hour')}:${get('minute')}`;
            };

            document.getElementById('edit-id').value = event.id ?? '';
            document.getElementById('edit-title').value = event.title ?? '';
            document.getElementById('edit-desc').value = event.description ?? '';
            document.getElementById('edit-venue').value = event.venue ?? '';
            document.getElementById('edit-start').value = toLocalInput(event.start_datetime);
            document.getElementById('edit-end').value = toLocalInput(event.end_datetime);
            document.getElementById('edit-cap').value = event.capacity ?? 1;
            document.getElementById('edit-status').value = event.status ?? 'Upcoming';
            document.getElementById('edit-image').value = event.image_url ?? '';
            document.getElementById('edit-lat').value = event.latitude ?? '';
            document.getElementById('edit-lng').value = event.longitude ?? '';
            document.getElementById('edit-boundary').value = event.venue_boundary
                ? (typeof event.venue_boundary === 'string' ? event.venue_boundary : JSON.stringify(event.venue_boundary))
                : '';
        });
    });

    // Populate the notification modal with the selected event.
    document.querySelectorAll('.notify-event-button').forEach(function (button) {
        button.addEventListener('click', function () {
            document.getElementById('notify-event-id').value = this.dataset.id || '';
            document.getElementById('notify-event-title').value = this.dataset.title || 'Selected event';
        });
    });

    document.querySelectorAll('.copy-event-id').forEach(function (button) {
        button.addEventListener('click', async function () {
            try {
                await navigator.clipboard.writeText(this.dataset.id);
                this.innerHTML = '<i class="bi bi-check2"></i>';
                setTimeout(() => this.innerHTML = '<i class="bi bi-clipboard"></i>', 1200);
            } catch (error) {
                alert('Could not copy the event ID. Please copy it manually.');
            }
        });
    });
}

document.addEventListener('DOMContentLoaded', initAdminEventsPage);
document.addEventListener('turbo:load', initAdminEventsPage);
if (document.readyState !== 'loading') initAdminEventsPage();
</script>
</body>
</html>
