// Supabase Edge Function: lets a parent reset a child's 4-digit PIN.
// Changing another user's password requires the service-role key, which must never be exposed
// to the browser, so this action has to run server-side.
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

    const { child, newPin } = await req.json();
    if (!/^\d{6}$/.test(String(newPin ?? ""))) return jsonResponse({ error: "New PIN must be exactly 6 digits" }, 400);
    if (typeof child !== "string" || !child) return jsonResponse({ error: "Choose a child account" }, 400);

    const sbAdmin = createClient(
      Deno.env.get("SUPABASE_URL")!,
      Deno.env.get("SUPABASE_SERVICE_ROLE_KEY")!,
    );
    const { data: childProfile, error: childErr } = await sbAdmin
      .from("profiles").select("id,role").eq("name", child).single();
    if (childErr || !childProfile || childProfile.role !== "child") {
      return jsonResponse({ error: "Choose a child account" }, 400);
    }

    const { error: updateErr } = await sbAdmin.auth.admin.updateUserById(childProfile.id, { password: newPin });
    if (updateErr) return jsonResponse({ error: updateErr.message }, 500);

    return jsonResponse({ ok: true });
  } catch (e) {
    return jsonResponse({ error: e instanceof Error ? e.message : "Reset failed" }, 500);
  }
});
