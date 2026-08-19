-- Taoqibao Schedule - Supabase schema
-- Run once in Supabase Dashboard > SQL Editor > New query.

create table if not exists public.profiles (
  id uuid primary key references auth.users(id) on delete cascade,
  name text not null unique,
  role text not null check (role in ('parent','child')),
  avatar text not null default '🙂',
  color text not null default '#9ca3af',
  created_at timestamptz not null default now()
);

alter table public.profiles enable row level security;

-- RLS policies only take effect on top of a base GRANT; without this, authenticated
-- requests get "permission denied for table profiles" even though a policy exists.
grant usage on schema public to anon, authenticated;
grant select on public.profiles to authenticated;

drop policy if exists "profiles readable by any signed-in family member" on public.profiles;
create policy "profiles readable by any signed-in family member"
  on public.profiles for select
  to authenticated
  using (true);

create table if not exists public.app_state (
  id smallint primary key default 1 check (id = 1),
  data jsonb not null,
  updated_at timestamptz not null default now()
);

alter table public.app_state enable row level security;

grant select, update on public.app_state to authenticated;

drop policy if exists "app_state readable by any signed-in family member" on public.app_state;
create policy "app_state readable by any signed-in family member"
  on public.app_state for select
  to authenticated
  using (true);

drop policy if exists "app_state writable by any signed-in family member" on public.app_state;
create policy "app_state writable by any signed-in family member"
  on public.app_state for update
  to authenticated
  using (true)
  with check (true);

-- Seed the single shared state row (same starting data as the old NAS build).
insert into public.app_state (id, data)
values (1, '{
  "wallets": {
    "Jerry": {"points": 50, "coins": 8, "color": "#4f7cff"},
    "Henrik": {"points": 30, "coins": 5, "color": "#39a96b"},
    "Mom": {"points": 0, "coins": 0, "color": "#f59e0b"},
    "Dad": {"points": 0, "coins": 0, "color": "#8b5cf6"}
  },
  "selected": null,
  "currentWeekStart": "2026-08-10",
  "events": [
    {"id":1,"title":"Piano Practice","member":"Jerry","day":"Mon","date":"2026-08-10","time":"17:00","endTime":"17:45","points":10,"status":"pending","category":"fixed","color":"#39a96b","location":"Malmö Music School","address":"Föreningsgatan 35, Malmö","web":"https://www.google.com","email":"teacher@example.com","description":"Bring piano book and practice scales before lesson.","reminder":"30 min before"},
    {"id":2,"title":"Homework","member":"Jerry","day":"Tue","date":"2026-08-11","time":"16:30","endTime":"17:15","points":15,"status":"planned","category":"work_school","color":"#4f7cff","location":"Home","address":"","web":"","email":"","description":"Math and reading homework.","reminder":"10 min before"},
    {"id":3,"title":"Football","member":"Henrik","day":"Wed","date":"2026-08-12","time":"18:00","endTime":"19:30","points":10,"status":"planned","category":"fixed","color":"#39a96b","location":"Sports Field","address":"Stadiongatan 25, Malmö","web":"https://www.google.com","email":"coach@example.com","description":"Training session. Bring water bottle and football shoes.","reminder":"1 hour before"},
    {"id":4,"title":"Swimming","member":"Family","day":"Sat","date":"2026-08-15","time":"14:00","endTime":"16:00","points":5,"status":"planned","category":"spin_wheel","color":"#8b5cf6","location":"Hylliebadet","address":"Hyllievångsvägen 20, Malmö","web":"https://www.google.com","email":"","description":"Family swimming activity.","reminder":"1 hour before"}
  ],
  "activities": [
    {"name":"Movie Night","points":0,"weight":10},{"name":"Swimming","points":5,"weight":15},{"name":"Board Game","points":0,"weight":25},
    {"name":"Bike Ride","points":5,"weight":15},{"name":"Hiking","points":5,"weight":10},{"name":"Restaurant","points":0,"weight":5},{"name":"Ice Cream","points":0,"weight":10}
  ],
  "rewards": [
    {"name":"Ice Cream","emoji":"🍦","price":5},{"name":"Movie Choice","emoji":"🎬","price":8},{"name":"Game Time 30m","emoji":"🎮","price":10},
    {"name":"Choose Dinner","emoji":"🍕","price":15},{"name":"Small Toy","emoji":"🎁","price":30},{"name":"Theme Park","emoji":"🎢","price":100}
  ],
  "history": ["+10 Points · Piano Practice","+15 Points · Homework","-50 Points · Exchanged for 5 Coins"]
}'::jsonb)
on conflict (id) do nothing;

-- Auto-create a profile row whenever a family member auth account is created in the dashboard.
-- Relies on the auth user's email local-part (mom / dad / jerry / henrik @ any domain).
create or replace function public.handle_new_user()
returns trigger
language plpgsql
security definer
set search_path = public
as $$
declare
  v_key text := lower(split_part(new.email, '@', 1));
  v_name text;
  v_role text;
  v_avatar text;
  v_color text;
begin
  case v_key
    when 'mom' then v_name:='Mom'; v_role:='parent'; v_avatar:='👩'; v_color:='#f59e0b';
    when 'dad' then v_name:='Dad'; v_role:='parent'; v_avatar:='👨'; v_color:='#8b5cf6';
    when 'jerry' then v_name:='Jerry'; v_role:='child'; v_avatar:='👦'; v_color:='#4f7cff';
    when 'henrik' then v_name:='Henrik'; v_role:='child'; v_avatar:='👦'; v_color:='#39a96b';
    else v_name:=initcap(v_key); v_role:='child'; v_avatar:='🙂'; v_color:='#9ca3af';
  end case;

  insert into public.profiles (id, name, role, avatar, color)
  values (new.id, v_name, v_role, v_avatar, v_color)
  on conflict (id) do nothing;

  return new;
end;
$$;

drop trigger if exists on_auth_user_created on auth.users;
create trigger on_auth_user_created
  after insert on auth.users
  for each row execute function public.handle_new_user();
