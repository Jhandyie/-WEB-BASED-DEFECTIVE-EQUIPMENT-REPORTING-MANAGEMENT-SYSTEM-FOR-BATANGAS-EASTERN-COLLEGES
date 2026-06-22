<?php
require_once __DIR__ . '/../config/database.php';

try {
    if (!isPgSqlDriver()) {
        fwrite(STDERR, "DB_DRIVER must be set to supabase, pgsql, postgres, or postgresql.\n");
        exit(1);
    }

    $pdo = getPgsqlPdoConnection();
    $row = $pdo->query('SELECT current_database() AS database_name, current_user AS user_name, now() AS server_time')->fetch();

    echo "Connected to Supabase/PostgreSQL\n";
    echo "Database: " . ($row['database_name'] ?? '') . "\n";
    echo "User: " . ($row['user_name'] ?? '') . "\n";
    echo "Server time: " . ($row['server_time'] ?? '') . "\n";
} catch (Throwable $e) {
    fwrite(STDERR, "Connection failed: " . $e->getMessage() . "\n");
    exit(1);
}
