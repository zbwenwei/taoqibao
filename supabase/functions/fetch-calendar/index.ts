// Supabase Edge Function: fetches an .ics calendar URL server-side.
// Mirrors the SSRF protections from the old NAS PHP endpoint (blocks localhost/private-network hosts,
// re-validates redirects, caps response size, requires an authenticated parent account).
import { createClient } from "https://esm.sh/@supabase/supabase-js@2";

const corsHeaders = {
  "Access-Control-Allow-Origin": "*",
  "Access-Control-Allow-Headers": "authorization, x-client-info, apikey, content-type",
};

function jsonResponse(body: unknown, status = 200) {
  return new Response(JSON.stringify(body), {
    status,
    headers: { ...corsHeaders, "Content-Type": "application/json" },
  });
}

function isPrivateIp(ip: string): boolean {
  const v4 = ip.match(/^(\d+)\.(\d+)\.(\d+)\.(\d+)$/);
  if (v4) {
    const a = Number(v4[1]), b = Number(v4[2]);
    if (a === 10 || a === 127 || a === 0) return true;
    if (a === 169 && b === 254) return true;
    if (a === 172 && b >= 16 && b <= 31) return true;
    if (a === 192 && b === 168) return true;
    return false;
  }
  const low = ip.toLowerCase();
  if (low === "::1" || low === "::") return true;
  if (low.startsWith("fc") || low.startsWith("fd")) return true; // unique local
  if (low.startsWith("fe80")) return true; // link-local
  return false;
}

async function resolveHost(host: string): Promise<string[]> {
  if (/^\d+\.\d+\.\d+\.\d+$/.test(host) || host.includes(":")) return [host];
  const ips: string[] = [];
  try { ips.push(...await Deno.resolveDns(host, "A")); } catch { /* no A record */ }
  try { ips.push(...await Deno.resolveDns(host, "AAAA")); } catch { /* no AAAA record */ }
  return ips;
}

async function validateCalendarUrl(rawUrl: string): Promise<URL> {
  let url: URL;
  try { url = new URL(rawUrl); } catch { throw new Error("Invalid calendar URL"); }
  if (!["http:", "https:"].includes(url.protocol)) throw new Error("Calendar URL must use http or https");
  if (url.username || url.password) throw new Error("Calendar URL must not contain embedded credentials");
  const host = url.hostname.toLowerCase();
  if (host === "localhost" || host.endsWith(".local")) throw new Error("Local/private calendar URLs are not allowed");
  const ips = await resolveHost(host);
  if (!ips.length) throw new Error("Could not resolve calendar host");
  for (const ip of ips) if (isPrivateIp(ip)) throw new Error("Local/private calendar URLs are not allowed");
  return url;
}

async function fetchCalendar(rawUrl: string): Promise<string> {
  const maxBytes = 3 * 1024 * 1024;
  let current = rawUrl;
  for (let hop = 0; hop < 5; hop++) {
    const url = await validateCalendarUrl(current);
    const res = await fetch(url.toString(), {
      redirect: "manual",
      headers: {
        "Accept": "text/calendar, application/ics, text/plain;q=0.9, */*;q=0.1",
        "User-Agent": "TaoqibaoCalendarImporter/1.0",
      },
    });
    if ([301, 302, 303, 307, 308].includes(res.status)) {
      const location = res.headers.get("location");
      if (!location) throw new Error("Redirect without location header");
      current = new URL(location, url).toString();
      continue;
    }
    if (!res.ok) throw new Error(`Calendar server returned HTTP ${res.status}`);
    const buf = await res.arrayBuffer();
    if (buf.byteLength > maxBytes) throw new Error("Calendar file is too large (maximum 3 MB)");
    const body = new TextDecoder().decode(buf);
    if (!/BEGIN:VCALENDAR/i.test(body)) throw new Error("The URL did not return an iCalendar (.ics) calendar");
    return body;
  }
  throw new Error("Too many calendar URL redirects");
}

Deno.serve(async (req: Request) => {
  if (req.method === "OPTIONS") return new Response("ok", { headers: corsHeaders });
  try {
    const sbUser = createClient(
      Deno.env.get("SUPABASE_URL")!,
      Deno.env.get("SUPABASE_ANON_KEY")!,
      { global: { headers: { Authorization: req.headers.get("Authorization") ?? "" } } },
    );
    const { data: userData, error: userErr } = await sbUser.auth.getUser();
    if (userErr || !userData?.user) return jsonResponse({ error: "Login required" }, 401);

    const { data: profile, error: profErr } = await sbUser
      .from("profiles").select("role").eq("id", userData.user.id).single();
    if (profErr || profile?.role !== "parent") return jsonResponse({ error: "Parent account required" }, 403);

    const { url } = await req.json();
    if (!url || typeof url !== "string") return jsonResponse({ error: "Calendar URL is required" }, 400);

    let rawUrl = url.trim();
    if (/^webcal:\/\//i.test(rawUrl)) rawUrl = "https://" + rawUrl.slice(9);

    const ics = await fetchCalendar(rawUrl);
    return jsonResponse({ ok: true, ics });
  } catch (e) {
    return jsonResponse({ error: e instanceof Error ? e.message : "Calendar fetch failed" }, 502);
  }
});
