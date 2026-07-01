-- MySQL-compatibility SQL functions for PostgreSQL/Supabase.
-- These let MySQL-style queries in the app (ORDER BY FIELD(...), DATEDIFF(...),
-- IFNULL already handled in PHP) run unchanged on Postgres.

-- FIELD(needle, a, b, c, ...) -> 1-based position of needle in the list, 0 if absent.
create or replace function public.field(needle text, variadic haystack text[])
returns integer language sql immutable as $$
  select coalesce(array_position(haystack, needle), 0);
$$;

-- DATEDIFF(end, start) -> integer number of days between two dates (MySQL semantics).
create or replace function public.datediff(d_end timestamptz, d_start timestamptz)
returns integer language sql immutable as $$
  select (d_end::date - d_start::date);
$$;

create or replace function public.datediff(d_end date, d_start date)
returns integer language sql immutable as $$
  select (d_end - d_start);
$$;

-- DATE_FORMAT(ts, '%Y-%m-%d') -> to_char with MySQL specifiers mapped to Postgres.
create or replace function public.date_format(ts timestamptz, fmt text)
returns text language plpgsql immutable as $$
declare f text := fmt;
begin
  if ts is null then return null; end if;
  f := replace(f, '%Y', 'YYYY');
  f := replace(f, '%y', 'YY');
  f := replace(f, '%M', 'FMMonth');
  f := replace(f, '%b', 'Mon');
  f := replace(f, '%m', 'MM');
  f := replace(f, '%c', 'FMMM');
  f := replace(f, '%d', 'DD');
  f := replace(f, '%e', 'FMDD');
  f := replace(f, '%W', 'FMDay');
  f := replace(f, '%a', 'Dy');
  f := replace(f, '%H', 'HH24');
  f := replace(f, '%h', 'HH12');
  f := replace(f, '%I', 'HH12');
  f := replace(f, '%i', 'MI');
  f := replace(f, '%s', 'SS');
  f := replace(f, '%p', 'AM');
  f := replace(f, '%%', '%');
  return to_char(ts, f);
end;
$$;
