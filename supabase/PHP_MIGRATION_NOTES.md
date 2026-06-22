# PHP Migration Notes

Current status:
- the app still runs through `mysqli`
- PostgreSQL/Supabase `PDO` support is now available in [config/database.php](/c:/xampp/htdocs/bec_equipment/config/database.php:1)
- shared schema helpers now exist for incremental migration

## New Helpers Added

Available now:
- `getDatabaseDriver()`
- `isMySqlDriver()`
- `isPgSqlDriver()`
- `getPgsqlPdoConnection()`
- `getTableColumns($tableName, $schema = 'public')`
- `tableHasColumn($tableName, $columnName, $schema = 'public')`
- `tableExists($tableName, $schema = 'public')`

## Intended Use

### While the app is still on MySQL

Keep existing code using:
- `getDBConnection()`
- `mysqli`

### For new PostgreSQL migration work

Use:
- `getPgsqlPdoConnection()`
- prepared statements through `PDO`

Example pattern:

```php
$pdo = getPgsqlPdoConnection();
$stmt = $pdo->prepare('select * from public.users where email = :email limit 1');
$stmt->execute(['email' => $email]);
$row = $stmt->fetch();
```

## Recommended Conversion Order

Convert feature groups in this order:
1. users/auth lookup queries
2. equipment reads
3. defect report reads/writes
4. notifications
5. reservations
6. technician/admin dashboards
7. analytics and imports

## Important Rule

Do not switch `DB_DRIVER=pgsql` for the full app yet.

Reason:
- most files still assume `mysqli`
- many queries still use MySQL-only SQL
- several pages type-hint `mysqli`

The safe path is:
- migrate functions one group at a time
- verify them
- then switch the main runtime later
