ALTER TABLE public.events
ADD COLUMN IF NOT EXISTS venue_boundary jsonb;

COMMENT ON COLUMN public.events.venue_boundary IS
'Organizer-drawn venue boundary as an array of [latitude, longitude] points. Attendance is allowed inside the polygon and within 100 meters outside its edge.';
