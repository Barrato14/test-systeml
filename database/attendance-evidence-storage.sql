insert into storage.buckets (id, name, public, file_size_limit, allowed_mime_types)
values (
    'attendance-evidence',
    'attendance-evidence',
    false,
    2097152,
    array['image/jpeg']
)
on conflict (id) do update
set
    public = false,
    file_size_limit = 2097152,
    allowed_mime_types = array['image/jpeg'];
