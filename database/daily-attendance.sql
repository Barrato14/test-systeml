-- Daily attendance records for events spanning more than one Manila calendar day.
-- Existing single-day attendance remains in public.attendance unchanged.
CREATE TABLE IF NOT EXISTS public.daily_attendance (
    id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    event_id uuid NOT NULL REFERENCES public.events(id) ON DELETE CASCADE,
    participant_id uuid NOT NULL REFERENCES public.profiles(id) ON DELETE CASCADE,
    attendance_date date NOT NULL,
    check_in_time timestamptz NOT NULL,
    time_out timestamptz NULL,
    latitude double precision NULL,
    longitude double precision NULL,
    photo_url text NULL,
    timeout_latitude double precision NULL,
    timeout_longitude double precision NULL,
    timeout_photo_url text NULL,
    status text NOT NULL DEFAULT 'Present',
    created_at timestamptz NOT NULL DEFAULT now(),
    CONSTRAINT daily_attendance_event_participant_date_unique UNIQUE (event_id, participant_id, attendance_date)
);
CREATE INDEX IF NOT EXISTS daily_attendance_event_date_idx ON public.daily_attendance (event_id, attendance_date);
CREATE INDEX IF NOT EXISTS daily_attendance_participant_idx ON public.daily_attendance (participant_id, attendance_date DESC);
COMMENT ON TABLE public.daily_attendance IS 'One attendance row per participant per calendar day for multi-day Zentrix events. Single-day events continue using public.attendance.';
