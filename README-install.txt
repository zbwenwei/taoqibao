Taoqibao QNAP v11 - Mobile editor / Google autofill fix

Taoqibao Schedule - QNAP V2
================================

Install target:
  /Web/family/

Files:
  index.html
  api/index.php
  data/.htaccess
  .htaccess

How to install:
1. In QNAP File Station open Web/family.
2. Back up your current index.html if needed.
3. Upload ALL contents of this package into Web/family.
4. Open:
     http://<NAS-IP>/family/
5. The first visit to the API automatically creates:
     Web/family/data/family.sqlite

First-use PINs:
  Mom    2580
  Dad    3580
  Jerry  1234
  Henrik 5678

Important:
- Change the default PINs after the first successful login.
- This V2 stores schedule/reward data centrally on the NAS.
- PIN hashes and login sessions are handled by PHP on the NAS.
- Do not expose this HTTP site directly to the public Internet.
- For remote access, use a VPN/Tailscale or configure HTTPS/reverse proxy later.

If the page says "NAS: PHP/API error":
Open in a browser:
  http://<NAS-IP>/family/api/index.php?action=session
If the error mentions SQLite/PDO, PHP SQLite support is missing/disabled and needs to be enabled before this build can run.

V2 note:
This first NAS version centralizes the app state in SQLite while keeping the existing interface and behavior. It is intended as a low-load family application for the TS-212P3.

NEW IN THIS VERSION
-------------------
1. Parent accounts can delete Schedule events from Event Details > Delete Event.
2. Parent accounts can import .ics/.ical calendar files exported by Google Calendar, Apple Calendar, Outlook and other calendar apps.
3. Imported events are previewed before import and saved to the same NAS SQLite family database.
4. Re-importing the same ICS event is detected by UID/date/time and skipped when possible.

Calendar export examples:
- Google Calendar: Settings > Import & export > Export (.ics inside the downloaded ZIP).
- Apple Calendar (Mac): File > Export > Export... (.ics).
- Outlook: save/export a calendar as iCalendar (.ics), depending on Outlook version.

v5 update:
- Parents can copy an existing schedule event to another family member.
- Open an event -> Details -> Copy Event, choose the target member, then Save.
- The copied event is saved as a new event; the original event is unchanged.


V5 update: Google Maps address links are now normalized and robust. Plain addresses, special characters, coordinates, Google Maps share URLs, and Location fallback are supported. Address text is also clickable.

V6 update: Calendar URL import
------------------------------
- Schedule > Import Calendar now supports both .ics/.ical file upload and calendar subscription URLs.
- Supported URL schemes: https://, http:// and webcal:// (webcal is converted to https).
- The NAS fetches the calendar server-side to avoid browser CORS restrictions.
- Public calendar subscription URLs from Google Calendar, Apple/iCloud, Outlook and other iCalendar providers can be imported when they return standard ICS data.
- For security, localhost/private-network URLs are blocked, redirects are re-validated, and downloaded calendar data is limited to 3 MB.
- URL import is available to parent accounts only.

v13 changes
- Calendar load/import ignores time conflicts completely. Every loaded row is displayed in chronological order.
- Every checked calendar row is imported; no time-based deletion, replacement, merging, or skipping.
- Today and weekly Schedule views explicitly sort events by start time.
- Manual Schedule events can repeat Daily, Weekly, or Monthly for 2-100 occurrences.
- Each repeated occurrence is stored as a separate event, so one occurrence can later be edited or deleted independently.
