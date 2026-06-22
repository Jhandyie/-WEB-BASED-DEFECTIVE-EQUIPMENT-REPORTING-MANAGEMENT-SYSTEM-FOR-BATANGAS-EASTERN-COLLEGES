import { withSupabase } from "@supabase/server";

export default {
  fetch: withSupabase({ auth: "none" }, async (_req, ctx) => {
    return Response.json({
      status: "ok",
      authMode: ctx.authMode,
      time: new Date().toISOString(),
    });
  }),
};
