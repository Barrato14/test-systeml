<?php
function isMultiDayAttendanceEvent(array $event): bool {
    if (empty($event['start_datetime']) || empty($event['end_datetime'])) return false;
    try {
        $tz = new DateTimeZone('Asia/Manila');
        $start = (new DateTimeImmutable($event['start_datetime']))->setTimezone($tz);
        $end = (new DateTimeImmutable($event['end_datetime']))->setTimezone($tz);
        return $start->format('Y-m-d') !== $end->format('Y-m-d');
    } catch (Throwable $e) {
        return false;
    }
}
