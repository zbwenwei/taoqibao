Taoqibao Schedule - Supabase setup (replaces NAS + PHP + VPN)
================================================================

Your Supabase project:
  https://ghgysgopewrcusgalnay.supabase.co

index.html already has this URL and the anon (public) API key wired in
(sb = supabase.createClient(...)). The anon key is safe to ship in client
code; access is controlled by Row Level Security policies, not by keeping
the key secret.

STEP 1 - Create the database tables
------------------------------------
1. Open the Supabase dashboard > SQL Editor > New query.
2. Paste the contents of supabase/schema.sql and run it.
   This creates:
     - public.profiles   (name, role, avatar per family member)
     - public.app_state  (single shared JSON blob - same data as the old NAS build)
   and a trigger that auto-fills "profiles" whenever a matching auth user is created.

STEP 2 - Create the 4 family logins
--------------------------------------
Dashboard > Authentication > Users > Add user, for each family member.
Check "Auto Confirm User" so no confirmation email is required.
Supabase's default minimum password length (6) applies, so every account
uses the same first-use PIN for now:

  Email                 Password (PIN)
  mom@family.local       123456
  dad@family.local       123456
  jerry@family.local     123456
  henrik@family.local    123456

The trigger from STEP 1 auto-creates each person's row in "profiles"
(name/role/avatar) based on the email's local part (mom/dad/jerry/henrik).
Change these PINs after first login (PIN Settings in the app) - new PINs
must also be 6 digits.

STEP 3 - Deploy the two Edge Functions
------------------------------------------
These replace the NAS PHP server-side calls that can't be done safely
from browser JS (calendar URL fetch needs SSRF protection; resetting a
child's PIN needs the service-role key).

Install the Supabase CLI, then from this project folder:

  supabase login
  supabase link --project-ref ghgysgopewrcusgalnay
  supabase functions deploy fetch-calendar
  supabase functions deploy reset-child-pin

No extra secrets to set: SUPABASE_URL, SUPABASE_ANON_KEY and
SUPABASE_SERVICE_ROLE_KEY are automatically available to Edge Functions.

STEP 4 - Host the web page (HTTPS link, no VPN)
----------------------------------------------------
Supabase itself CANNOT serve index.html as a working page. Both Storage
and Edge Functions sit behind a platform-wide gateway that forces
Content-Type: text/plain and Content-Security-Policy: default-src 'none';
sandbox on every response. This is intentional on Supabase's side, to
stop the shared *.supabase.co domain from being used to host arbitrary
live websites (anti-phishing/anti-XSS protection for all customers) -
there's no supported way around it, on Storage or Edge Functions.

index.html is a static file with no server dependency of its own (it
only talks to Supabase's API), so any static host works. Chosen option:
keep using the NAS folder (Web/family), just without the VPN.

Host on the NAS (QNAP), HTTPS without VPN:
1. File Station > Web > family > upload the new index.html, overwriting
   the old one. api/index.php and data/ are no longer called by the
   page, so they can stay (harmless) or be deleted.
2. App Center > install/open "myQNAPcloud" (QNAP's free built-in remote
   access service) and sign in / register a device alias, e.g. "taoqibao".
3. myQNAPcloud > enable "Auto Router Configuration" (UPnP) or set up
   port forwarding manually on your router if UPnP isn't available.
4. myQNAPcloud > SSL Certificate: use the free certificate it provisions
   automatically for your alias, and turn on "Force secure connection
   (HTTPS)".
5. Your page is now reachable at:
     https://<your-alias>.myqnapcloud.com/family/
   from any network, over HTTPS, without connecting to a VPN - because
   myQNAPcloud handles the public HTTPS endpoint and forwards it to your
   NAS, and the page itself only talks to Supabase for data.

(GitHub Pages, Cloudflare Pages, Netlify or Vercel remain simpler
zero-router-config alternatives if you'd rather not expose the NAS
itself to the internet.)

Host on GitHub Pages instead (HTTPS, free, no NAS/router involved):
Already set up for this project:
  Repo:      https://github.com/zbwenwei/taoqibao
  Live URL:  https://zbwenwei.github.io/taoqibao/

To publish future changes to index.html, push them to the "master"
branch (git add / git commit / git push) - GitHub Pages redeploys
automatically within about a minute.

(General steps, for reference / other projects:
1. Create a GitHub repository (public is fine - the Supabase anon key
   is safe to expose publicly; it's protected by RLS, not secrecy, and
   no real family data lives in this repo, only in Supabase).
2. Push the folder's contents to that repository's default branch.
3. Repo Settings > Pages > Source: "Deploy from a branch" > pick the
   default branch and "/ (root)" folder > Save.
4. GitHub serves index.html at https://<username>.github.io/<repo>/ )

The api/index.php and data/ folder (SQLite) are no longer used by
index.html and can be deleted once you've confirmed the Supabase
version works end-to-end.
