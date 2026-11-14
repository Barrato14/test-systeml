<?php
/**
 * EMIS automatic event reminders.
 *
 * Run from a scheduled job every 15 minutes:
 *   php send-event-reminders.php
 *
 * This script is intentionally CLI-only. It uses the server-side Supabase
 * service-role key and the existing Brevo mail helper.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Forbidden\n");
}

date_default_timezone_set('Asia/Manila');

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/mail.php';

function reminderLog($message) {
    echo '[' . date('Y-m-d H:i:s') . ' PHT] ' . $message . PHP_EOL;
}

function reminderEscape($value) {
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function reminderManilaTime($value) {
    try {
        return (new DateTimeImmutable($value))
            ->setTimezone(new DateTimeZone('Asia/Manila'))
            ->format('F j, Y, g:i A');
    } catch (Exception $e) {
        return 'To be announced';
    }
}

// A 24-hour look-ahead window ensures a missed/late cron run can still catch
// an event. Per-recipient log flags prevent repeat notifications.
$now = new DateTimeImmutable('now', new DateTimeZone('Asia/Manila'));
$cutoff = $now->modify('+24 hours');

// PostgREST requires multiple filters on the same column to be grouped
// inside an explicit "and" expression. Repeating start_datetime parameters
// can cause one boundary to override the other.
$timeWindowFilter = '(start_datetime.gt.' . $now->format('c')
    . ',start_datetime.lte.' . $cutoff->format('c') . ')';

$eventsResponse = supabaseServiceRequest(
    'events?and=' . rawurlencode($timeWindowFilter)
    . '&select=id,title,venue,start_datetime&order=start_datetime.asc&limit=500',
    'GET'
);

if (($eventsResponse['code'] ?? 500) >= 400 || !is_array($eventsResponse['data'] ?? null)) {
    reminderLog('ERROR: Unable to fetch upcoming events. ' . json_encode($eventsResponse['data'] ?? []));
    exit(1);
}

$events = $eventsResponse['data'];
if (empty($events)) {
    reminderLog('No events are within the next 24 hours.');
    exit(0);
}

$totalInApp = 0;
$totalEmails = 0;
$totalFailures = 0;

foreach ($events as $event) {
    $eventId = trim((string)($event['id'] ?? ''));
    if ($eventId === '') {
        continue;
    }

    $eventTitle = (string)($event['title'] ?? 'EMIS Event');
    $eventVenue = (string)($event['venue'] ?? 'To be announced');
    $eventStart = reminderManilaTime($event['start_datetime'] ?? '');
    try {
        $eventStartDate = (new DateTimeImmutable($event['start_datetime']))
            ->setTimezone(new DateTimeZone('Asia/Manila'))->format('Y-m-d');
    } catch (Exception $e) {
        reminderLog('Skipping event with invalid start date: ' . $eventId);
        continue;
    }
    $reminderType = ($eventStartDate === $now->format('Y-m-d')) ? 'event_day' : '24_hours';
    $reminderLabel = ($reminderType === 'event_day') ? 'today' : 'within the next 24 hours';

    $registrationsResponse = supabaseServiceRequest(
        'event_registrations?event_id=eq.' . rawurlencode($eventId) .
        '&status=eq.Approved&select=participant_id&limit=2000',
        'GET'
    );

    if (($registrationsResponse['code'] ?? 500) >= 400 || !is_array($registrationsResponse['data'] ?? null)) {
        reminderLog('ERROR: Could not retrieve approved registrations for event ' . $eventId);
        $totalFailures++;
        continue;
    }

    $recipientIds = [];
    foreach ($registrationsResponse['data'] as $registration) {
        $participantId = trim((string)($registration['participant_id'] ?? ''));
        if ($participantId !== '') {
            $recipientIds[$participantId] = true;
        }
    }

    if (empty($recipientIds)) {
        reminderLog('No approved participants for: ' . $eventTitle);
        continue;
    }

    foreach (array_keys($recipientIds) as $participantId) {
        // Load or create a per-participant reminder record. This is the
        // idempotency guard that prevents repeat in-app alerts and emails.
        $logQuery = 'event_reminder_logs?event_id=eq.' . rawurlencode($eventId) .
            '&participant_id=eq.' . rawurlencode($participantId) .
            '&reminder_type=eq.' . rawurlencode($reminderType) . '&select=id,in_app_sent,email_sent,email_skipped';

        $logResponse = supabaseServiceRequest($logQuery, 'GET');

        if (($logResponse['code'] ?? 500) >= 400) {
            reminderLog('ERROR: Could not check reminder log for participant ' . $participantId);
            $totalFailures++;
            continue;
        }

        if (empty($logResponse['data'])) {
            $createLog = supabaseServiceRequest('event_reminder_logs', 'POST', [
                'event_id' => $eventId,
                'participant_id' => $participantId,
                'reminder_type' => $reminderType,
                'in_app_sent' => false,
                'email_sent' => false,
                'email_skipped' => false
            ]);

            if (($createLog['code'] ?? 500) >= 400 || empty($createLog['data'][0]['id'])) {
                reminderLog('ERROR: Could not create reminder log for participant ' . $participantId);
                $totalFailures++;
                continue;
            }

            $logRecord = $createLog['data'][0];
        } else {
            $logRecord = $logResponse['data'][0];
        }

        $logId = $logRecord['id'];
        $inAppSent = !empty($logRecord['in_app_sent']);
        $emailSent = !empty($logRecord['email_sent']);
        $emailSkipped = !empty($logRecord['email_skipped']);

        if (!$inAppSent) {
            $notificationResponse = supabaseServiceRequest('notifications', 'POST', [
                'participant_id' => $participantId,
                'event_id' => $eventId,
                'title' => (($reminderType === 'event_day') ? 'Event Today: ' : 'Event Reminder: ') . $eventTitle,
                'message' => (($reminderType === 'event_day') ? 'Your event is happening today: "' : 'Reminder: "') . $eventTitle . '" is scheduled to start ' . $reminderLabel . ', on ' .
                    $eventStart . '. Venue: ' . $eventVenue . '. Please check the event details in EMIS.',
                'is_read' => false
            ]);

            if (($notificationResponse['code'] ?? 500) < 400) {
                $inAppSent = true;
                $totalInApp++;
            } else {
                $totalFailures++;
                reminderLog('ERROR: In-app reminder failed for participant ' . $participantId . ' / event ' . $eventId);
            }
        }

        if (!$emailSent && !$emailSkipped) {
            $profileResponse = supabaseServiceRequest(
                'profiles?id=eq.' . rawurlencode($participantId) . '&select=email',
                'GET'
            );

            $profile = (
                ($profileResponse['code'] ?? 500) < 400 &&
                is_array($profileResponse['data'] ?? null) &&
                !empty($profileResponse['data'])
            ) ? $profileResponse['data'][0] : [];

            $recipientEmail = trim((string)($profile['email'] ?? ''));

            if ($recipientEmail === '' || !filter_var($recipientEmail, FILTER_VALIDATE_EMAIL)) {
                $emailSkipped = true;
                reminderLog('Skipped email: no valid profile email for participant ' . $participantId);
            } else {
                $safeEventTitle = reminderEscape($eventTitle);
                $safeEventVenue = reminderEscape($eventVenue);
                $safeEventStart = reminderEscape($eventStart);

                $emailHtml = '
                    <div style="font-family:Arial,sans-serif;max-width:600px;margin:0 auto;color:#273449;line-height:1.6;">
                        <div style="background:#343a40;color:#ffffff;padding:22px 26px;border-radius:10px 10px 0 0;">
                            <h2 style="margin:0;color:#ffffff;">EMIS Event Reminder</h2>
                        </div>
                        <div style="padding:24px;border:1px solid #e8edf4;border-top:0;border-radius:0 0 10px 10px;">
                            <h3 style="color:#4f46e5;">Your event is coming up!</h3>
                            <p>This is a reminder that your registered event is scheduled to start ' . $reminderLabel . '.</p>
                            <p><strong>Event:</strong> ' . $safeEventTitle . '</p>
                            <p><strong>Starts:</strong> ' . $safeEventStart . ' (Philippine Time)</p>
                            <p><strong>Venue:</strong> ' . $safeEventVenue . '</p>
                            <p>Please open EMIS to review the event details and prepare for check-in.</p>
                            <p style="color:#64748b;font-size:13px;margin-top:24px;">This is an automated message from the EMIS Event System. Please do not reply to this email.</p>
                        </div>
                    </div>';

                $emailResult = sendSystemEmail(
                    $recipientEmail,
                    'Participant',
                    (($reminderType === 'event_day') ? 'Event Today: ' . $eventTitle . ' starts today' : 'Reminder: ' . $eventTitle . ' starts within 24 hours'),
                    $emailHtml
                );

                if ($emailResult === true) {
                    $emailSent = true;
                    $totalEmails++;
                } else {
                    $totalFailures++;
                    reminderLog('ERROR: Email reminder failed for participant ' . $participantId . ': ' . (string)$emailResult);
                }
            }
        }

        $patchPayload = [
            'in_app_sent' => $inAppSent,
            'email_sent' => $emailSent,
            'email_skipped' => $emailSkipped,
            'updated_at' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('c'),
            'last_error' => (!$inAppSent || (!$emailSent && !$emailSkipped))
                ? 'One or more reminder channels are pending; the scheduled job will retry.'
                : null
        ];

        $patchResponse = supabaseServiceRequest(
            'event_reminder_logs?id=eq.' . rawurlencode((string)$logId),
            'PATCH',
            $patchPayload
        );

        if (($patchResponse['code'] ?? 500) >= 400) {
            reminderLog('ERROR: Could not update reminder log ' . $logId);
            $totalFailures++;
        }
    }

    reminderLog('Processed event: ' . $eventTitle . ' (' . count($recipientIds) . ' approved participant(s)).');
}

reminderLog('Finished. In-app reminders sent: ' . $totalInApp .
    '; emails sent: ' . $totalEmails . '; errors: ' . $totalFailures . '.');
exit(0);
