import { withSupabase } from "@supabase/server";

export default {
  fetch: withSupabase({ auth: "user" }, async (_req, ctx) => {
    const { data, error } = await ctx.supabase.from("todos").select();

    if (error) {
      return Response.json(
        { message: error.message, code: error.code },
        { status: 500 },
      );
    }

    return Response.json(data);
  }),
};
