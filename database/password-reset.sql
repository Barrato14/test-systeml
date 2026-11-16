-- Run once in the Supabase SQL Editor.
-- Only a SHA-256 hash of the reset token is stored. Tokens expire after 30 minutes.

alter table public.profiles
    add column if not exists password_reset_token_hash text,
    add column if not exists password_reset_expires_at timestamptz;

create index if not exists profiles_password_reset_token_hash_idx
    on public.profiles (password_reset_token_hash)
    where password_reset_token_hash is not null;
