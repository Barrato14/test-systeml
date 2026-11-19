BEGIN;

DO $$
DECLARE
    table_name text;
    table_names text[] := ARRAY[
        'profiles',
        'audit_logs',
        'event_registrations',
        'events',
        'attendance',
        'notifications',
        'equipment_items',
        'equipment_borrow_requests',
        'participant_face_profiles',
        'event_reminder_logs',
        'daily_attendance'
    ];
BEGIN
    FOREACH table_name IN ARRAY table_names LOOP
        EXECUTE format('ALTER TABLE public.%I ENABLE ROW LEVEL SECURITY', table_name);
        EXECUTE format('REVOKE ALL PRIVILEGES ON TABLE public.%I FROM anon, authenticated', table_name);
        EXECUTE format('GRANT ALL PRIVILEGES ON TABLE public.%I TO service_role', table_name);
        EXECUTE format('DROP POLICY IF EXISTS deny_client_roles ON public.%I', table_name);
        EXECUTE format(
            'CREATE POLICY deny_client_roles ON public.%I FOR ALL TO anon, authenticated USING (false) WITH CHECK (false)',
            table_name
        );
    END LOOP;
END
$$;

REVOKE ALL PRIVILEGES ON ALL TABLES IN SCHEMA public FROM anon, authenticated;
GRANT ALL PRIVILEGES ON ALL TABLES IN SCHEMA public TO service_role;

REVOKE ALL PRIVILEGES ON ALL SEQUENCES IN SCHEMA public FROM anon, authenticated;
GRANT USAGE, SELECT, UPDATE ON ALL SEQUENCES IN SCHEMA public TO service_role;

ALTER DEFAULT PRIVILEGES FOR ROLE postgres IN SCHEMA public
    REVOKE ALL ON TABLES FROM anon, authenticated;
ALTER DEFAULT PRIVILEGES FOR ROLE postgres IN SCHEMA public
    GRANT ALL ON TABLES TO service_role;

ALTER DEFAULT PRIVILEGES FOR ROLE postgres IN SCHEMA public
    REVOKE ALL ON SEQUENCES FROM anon, authenticated;
ALTER DEFAULT PRIVILEGES FOR ROLE postgres IN SCHEMA public
    GRANT USAGE, SELECT, UPDATE ON SEQUENCES TO service_role;

COMMIT;
