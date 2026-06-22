# Supabase Folder

This folder contains the first PostgreSQL/Supabase migration assets for this project.

Files:
- [schema.sql](/c:/xampp/htdocs/bec_equipment/supabase/schema.sql:1): first Supabase-compatible schema draft based on the current MySQL app structure

Current status:
- schema draft created
- PHP app still runs on MySQL/mysqli today
- query conversion has not started yet

Recommended next execution order:
1. Create a Supabase project
2. Run [schema.sql](/c:/xampp/htdocs/bec_equipment/supabase/schema.sql:1) in the Supabase SQL editor
3. Compare any schema errors with app expectations
4. Export/import MySQL data into the new PostgreSQL tables
5. Switch PHP config to environment-based settings
6. Convert queries feature by feature

Notes:
- This schema is intentionally compatibility-first
- Some tables are kept because the current app still references them
- Long-term cleanup should reduce duplicate role tables and normalize around `users`
