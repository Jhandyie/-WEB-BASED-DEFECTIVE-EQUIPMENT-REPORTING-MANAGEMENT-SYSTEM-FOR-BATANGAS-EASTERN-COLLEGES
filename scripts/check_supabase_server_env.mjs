import { createSupabaseContext } from "@supabase/server";

const required = [
  "SUPABASE_URL",
  "SUPABASE_PUBLISHABLE_KEY",
  "SUPABASE_SECRET_KEY",
  "SUPABASE_JWKS_URL",
];

const missing = required.filter((key) => !process.env[key]);
if (missing.length > 0) {
  console.error(`Missing environment variables: ${missing.join(", ")}`);
  process.exit(1);
}

const req = new Request("http://localhost/health");
const { data, error } = await createSupabaseContext(req, { auth: "none" });

if (error) {
  console.error(`Supabase server SDK check failed: ${error.message}`);
  process.exit(1);
}

console.log(`Supabase server SDK env is readable for ${data.authMode} auth mode.`);
