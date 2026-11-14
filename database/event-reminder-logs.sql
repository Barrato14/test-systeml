-- Run this once in Supabase SQL Editor.
-- Stores delivery status per participant/event so scheduled retries do not
-- create duplicate in-app notifications or send duplicate emails.

create table if not exists public.event_reminder_logs (
    id bigint generated always as identity primary key,
    event_id uuid not null,
    participant_id uuid not null,
    reminder_type text not null default '24_hours',
    in_app_sent boolean not null default false,
    email_sent boolean not null default false,
    email_skipped boolean not null default false,
    last_error text,
    created_at timestamptz not null default now(),
    updated_at timestamptz not null default now(),
    constraint event_reminder_logs_unique_recipient
        unique (event_id, participant_id, reminder_type)
);

create index if not exists event_reminder_logs_event_idx
    on public.event_reminder_logs (event_id);

-- This table is written only by the server-side service-role process.
alter table public.event_reminder_logs enable row level security;
