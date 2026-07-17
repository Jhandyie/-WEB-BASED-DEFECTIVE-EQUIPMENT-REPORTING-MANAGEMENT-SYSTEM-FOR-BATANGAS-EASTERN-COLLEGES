<?php
// config/database.php - Centralized Database Configuration
// Updated: 2026-04-17

if (!function_exists('loadDatabaseEnvFile')) {
    function loadDatabaseEnvFile(): void {
        static $loaded = false;
        if ($loaded) {
            return;
        }
        $loaded = true;

        $envPath = dirname(__DIR__) . '/.env';
        if (!is_readable($envPath)) {
            return;
        }

        $lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            return;
        }

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                continue;
            }

            [$key, $value] = explode('=', $line, 2);
            $key = trim($key);
            if ($key === '' || getenv($key) !== false) {
                continue;
            }

            $value = trim($value);
            if (
                strlen($value) >= 2 &&
                (($value[0] === '"' && substr($value, -1) === '"') || ($value[0] === "'" && substr($value, -1) === "'"))
            ) {
                $value = substr($value, 1, -1);
            }

            putenv($key . '=' . $value);
            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
        }
    }
}

loadDatabaseEnvFile();

if (!function_exists('dbEnv')) {
    function dbEnv(string $key, $default = null) {
        $value = getenv($key);
        if ($value === false || $value === '') {
            return $default;
        }
        return $value;
    }
}

if (!function_exists('getDatabaseConfig')) {
    function getDatabaseConfig(): array {
        return [
            'driver' => strtolower((string)dbEnv('DB_DRIVER', 'mysql')),
            'mysql' => [
                'host' => (string)dbEnv('MYSQL_HOST', '127.0.0.1'),
                'port' => (int)dbEnv('MYSQL_PORT', '3306'),
                'username' => (string)dbEnv('MYSQL_USERNAME', 'root'),
                'password' => (string)dbEnv('MYSQL_PASSWORD', ''),
                'database' => (string)dbEnv('MYSQL_DATABASE', 'bec_equipment_db'),
                'charset' => (string)dbEnv('MYSQL_CHARSET', 'utf8mb4'),
                'timezone' => (string)dbEnv('APP_DB_TIMEZONE', '+08:00'),
            ],
            'pgsql' => [
                'host' => (string)dbEnv('PGHOST', ''),
                'port' => (int)dbEnv('PGPORT', '5432'),
                'database' => (string)dbEnv('PGDATABASE', ''),
                'username' => (string)dbEnv('PGUSER', ''),
                'password' => (string)dbEnv('PGPASSWORD', ''),
                'sslmode' => (string)dbEnv('PGSSLMODE', 'require'),
            ],
            'supabase' => [
                'url' => (string)dbEnv('SUPABASE_URL', ''),
                'anon_key' => (string)dbEnv('SUPABASE_ANON_KEY', ''),
                'service_role_key' => (string)dbEnv('SUPABASE_SERVICE_ROLE_KEY', ''),
            ],
        ];
    }
}

if (!function_exists('getDatabaseDriver')) {
    function getDatabaseDriver(): string {
        $config = getDatabaseConfig();
        return strtolower((string)($config['driver'] ?? 'mysql'));
    }
}

if (!function_exists('isMySqlDriver')) {
    function isMySqlDriver(): bool {
        return getDatabaseDriver() === 'mysql';
    }
}

if (!function_exists('isPgSqlDriver')) {
    function isPgSqlDriver(): bool {
        return in_array(getDatabaseDriver(), ['pgsql', 'postgres', 'postgresql', 'supabase'], true);
    }
}

class PgsqlDatabase {
    private static $instance = null;
    private ?PDO $connection = null;
    private array $config;

    private function __construct() {
        $this->config = getDatabaseConfig();
    }

    public static function getInstance(): self {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function getConnection(): PDO {
        if ($this->connection instanceof PDO) {
            return $this->connection;
        }

        $pgsql = $this->config['pgsql'] ?? [];
        $host = trim((string)($pgsql['host'] ?? ''));
        $port = (int)($pgsql['port'] ?? 5432);
        $database = trim((string)($pgsql['database'] ?? ''));
        $username = trim((string)($pgsql['username'] ?? ''));
        $password = (string)($pgsql['password'] ?? '');
        $sslmode = trim((string)($pgsql['sslmode'] ?? 'require'));

        if ($host === '' || $database === '' || $username === '') {
            throw new RuntimeException('PostgreSQL/Supabase connection variables are incomplete.');
        }

        $dsn = sprintf(
            'pgsql:host=%s;port=%d;dbname=%s;sslmode=%s',
            $host,
            $port,
            $database,
            $sslmode !== '' ? $sslmode : 'require'
        );

        $this->connection = new PDO($dsn, $username, $password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);

        // NOTE: Postgres does not allow bound parameters in SET commands, and it
        // interprets a bare numeric offset like '+08:00' POSIX-style (sign inverted).
        // So we inject a sanitized literal: numeric offsets use the unambiguous
        // INTERVAL form; anything else is treated as a named zone (e.g. Asia/Manila).
        $timezone = trim((string)($this->config['mysql']['timezone'] ?? 'Asia/Manila'));
        try {
            if (preg_match('/^[+-]\d{2}:\d{2}$/', $timezone)) {
                $this->connection->exec("SET TIME ZONE INTERVAL '{$timezone}' HOUR TO MINUTE");
            } else {
                $safeZone = preg_replace('/[^A-Za-z0-9_\/+\-]/', '', $timezone);
                if ($safeZone !== '') {
                    $this->connection->exec("SET TIME ZONE " . $this->connection->quote($safeZone));
                }
            }
        } catch (\Throwable $e) {
            error_log('Could not set Postgres time zone (' . $timezone . '): ' . $e->getMessage());
        }

        return $this->connection;
    }
}

if (!function_exists('getPgsqlPdoConnection')) {
    function getPgsqlPdoConnection(): PDO {
        return PgsqlDatabase::getInstance()->getConnection();
    }
}

class Database {
    private static $instance = null;
    private $connection;
    private $is_connected = false;
    private array $config;

    private function __construct() {
        try {
            $this->config = getDatabaseConfig();

            // Supabase / PostgreSQL path: return a mysqli-compatible adapter
            // over PDO so existing mysqli-style call sites keep working.
            if (isPgSqlDriver()) {
                require_once __DIR__ . '/mysqli_compat.php';
                $this->connection = new PgMysqliConnection(getPgsqlPdoConnection());
                $this->is_connected = true;
                return;
            }

            $mysql = $this->config['mysql'];
            $this->connection = new mysqli(
                $mysql['host'],
                $mysql['username'],
                $mysql['password'],
                $mysql['database'],
                (int)$mysql['port']
            );
            if ($this->connection->connect_error) {
                throw new Exception("Connection failed: " . $this->connection->connect_error);
            }
            $this->connection->set_charset((string)$mysql['charset']);
            $this->connection->query("SET time_zone = '" . $this->connection->real_escape_string((string)$mysql['timezone']) . "';");
            $this->is_connected = true;
        } catch (Exception $e) {
            error_log("Database connection error: " . $e->getMessage());
            die("Database connection failed. Please contact support.");
        }
    }

    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new Database();
        }
        return self::$instance;
    }

    public function getConnection() {
        return $this->connection;
    }

    public function __destruct() {
        if ($this->connection && $this->is_connected) {
            try {
                if ($this->connection instanceof mysqli) {
                    $this->connection->close();
                }
            } catch (Throwable $e) {
                // Connection already closed or invalid, ignore
            }
            $this->is_connected = false;
        }
    }
}

// Helper function for backwards compatibility
function getDBConnection() {
    return Database::getInstance()->getConnection();
}

if (!function_exists('getTableColumns')) {
    function getTableColumns(string $tableName, string $schema = 'public'): array {
        static $cache = [];
        $driver = getDatabaseDriver();
        $cacheKey = strtolower($driver . ':' . $schema . '.' . $tableName);
        if (isset($cache[$cacheKey])) {
            return $cache[$cacheKey];
        }

        $columns = [];
        if (isPgSqlDriver()) {
            try {
                $pdo = getPgsqlPdoConnection();
                $sql = "SELECT column_name, data_type, is_nullable, column_default
                        FROM information_schema.columns
                        WHERE table_schema = :schema AND table_name = :table
                        ORDER BY ordinal_position";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([
                    'schema' => $schema,
                    'table' => strtolower($tableName),
                ]);
                foreach ($stmt->fetchAll() as $row) {
                    $columns[(string)$row['column_name']] = $row;
                }
            } catch (Throwable $e) {
                error_log('PostgreSQL column lookup failed for ' . $tableName . ': ' . $e->getMessage());
            }
        } else {
            $conn = getDBConnection();
            $safeTable = preg_replace('/[^A-Za-z0-9_]+/', '', $tableName);
            if ($safeTable === '') {
                return [];
            }
            $result = $conn->query("SHOW COLUMNS FROM `{$safeTable}`");
            if ($result) {
                while ($row = $result->fetch_assoc()) {
                    $columns[(string)$row['Field']] = $row;
                }
            }
        }

        $cache[$cacheKey] = $columns;
        return $columns;
    }
}

if (!function_exists('tableHasColumn')) {
    function tableHasColumn(string $tableName, string $columnName, string $schema = 'public'): bool {
        $columns = getTableColumns($tableName, $schema);
        return isset($columns[$columnName]);
    }
}

if (!function_exists('tableExists')) {
    function tableExists(string $tableName, string $schema = 'public'): bool {
        return getTableColumns($tableName, $schema) !== [];
    }
}

if (!function_exists('findUserByEmailAndRole')) {
    function findUserByEmailAndRole(string $email, string $role, array $fields = ['user_id', 'email', 'fullname', 'username', 'password', 'status', 'role']): ?array {
        $safeFields = array_values(array_filter($fields, static fn($f) => preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', (string)$f)));
        if (empty($safeFields)) {
            $safeFields = ['user_id', 'email', 'fullname', 'username', 'password', 'status', 'role'];
        }
        $fieldList = implode(', ', $safeFields);

        if (isPgSqlDriver()) {
            $pdo = getPgsqlPdoConnection();
            $stmt = $pdo->prepare("SELECT {$fieldList} FROM public.users WHERE email = :email AND role = :role ORDER BY created_at DESC LIMIT 1");
            $stmt->execute(['email' => $email, 'role' => $role]);
            $row = $stmt->fetch();
            return is_array($row) ? $row : null;
        }

        $conn = getDBConnection();
        $stmt = $conn->prepare("SELECT {$fieldList} FROM users WHERE email = ? AND role = ? ORDER BY created_at DESC LIMIT 1");
        $stmt->bind_param('ss', $email, $role);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result ? $result->fetch_assoc() : null;
        $stmt->close();
        return $row ?: null;
    }
}

if (!function_exists('userExistsByEmail')) {
    function userExistsByEmail(string $email, ?string $excludeUserId = null): bool {
        if ($email === '') {
            return false;
        }

        if (isPgSqlDriver()) {
            $pdo = getPgsqlPdoConnection();
            $sql = 'SELECT user_id FROM public.users WHERE email = :email';
            $params = ['email' => $email];
            if ($excludeUserId !== null && $excludeUserId !== '') {
                $sql .= ' AND user_id != :exclude_user_id';
                $params['exclude_user_id'] = $excludeUserId;
            }
            $sql .= ' ORDER BY created_at DESC LIMIT 1';
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            return (bool)$stmt->fetch();
        }

        $conn = getDBConnection();
        if ($excludeUserId !== null && $excludeUserId !== '') {
            $stmt = $conn->prepare('SELECT user_id FROM users WHERE email = ? AND user_id != ? ORDER BY created_at DESC LIMIT 1');
            $stmt->bind_param('ss', $email, $excludeUserId);
        } else {
            $stmt = $conn->prepare('SELECT user_id FROM users WHERE email = ? ORDER BY created_at DESC LIMIT 1');
            $stmt->bind_param('s', $email);
        }
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result ? $result->fetch_assoc() : null;
        $stmt->close();
        return (bool)$row;
    }
}

if (!function_exists('userExistsByUsername')) {
    function userExistsByUsername(string $username, ?string $excludeUserId = null): bool {
        if ($username === '') {
            return false;
        }

        if (isPgSqlDriver()) {
            $pdo = getPgsqlPdoConnection();
            $sql = 'SELECT user_id FROM public.users WHERE username = :username';
            $params = ['username' => $username];
            if ($excludeUserId !== null && $excludeUserId !== '') {
                $sql .= ' AND user_id != :exclude_user_id';
                $params['exclude_user_id'] = $excludeUserId;
            }
            $sql .= ' ORDER BY created_at DESC LIMIT 1';
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            return (bool)$stmt->fetch();
        }

        $conn = getDBConnection();
        if ($excludeUserId !== null && $excludeUserId !== '') {
            $stmt = $conn->prepare('SELECT user_id FROM users WHERE username = ? AND user_id != ? ORDER BY created_at DESC LIMIT 1');
            $stmt->bind_param('ss', $username, $excludeUserId);
        } else {
            $stmt = $conn->prepare('SELECT user_id FROM users WHERE username = ? ORDER BY created_at DESC LIMIT 1');
            $stmt->bind_param('s', $username);
        }
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result ? $result->fetch_assoc() : null;
        $stmt->close();
        return (bool)$row;
    }
}

if (!function_exists('getLatestUserIdForRolePrefix')) {
    function getLatestUserIdForRolePrefix(string $role, string $prefix): ?string {
        $prefix = trim($prefix);
        if ($role === '' || $prefix === '') {
            return null;
        }

        // Fetch all IDs for this role/prefix and compute the max numeric suffix in PHP.
        // (Avoids driver-specific SUBSTRING/CAST ordering quirks that caused PK collisions.)
        $prefixLike = $prefix . '-%';
        $ids = [];
        if (isPgSqlDriver()) {
            $pdo = getPgsqlPdoConnection();
            $stmt = $pdo->prepare("SELECT user_id FROM public.users WHERE role = :role AND user_id LIKE :prefix_like");
            $stmt->execute(['role' => $role, 'prefix_like' => $prefixLike]);
            $ids = $stmt->fetchAll(PDO::FETCH_COLUMN);
        } else {
            $conn = getDBConnection();
            $stmt = $conn->prepare("SELECT user_id FROM users WHERE role = ? AND user_id LIKE ?");
            if ($stmt) {
                $stmt->bind_param('ss', $role, $prefixLike);
                $stmt->execute();
                $result = $stmt->get_result();
                if ($result) {
                    while ($r = $result->fetch_assoc()) { $ids[] = $r['user_id']; }
                }
                $stmt->close();
            }
        }

        $latest = null; $maxNum = -1;
        foreach ($ids as $uid) {
            if (preg_match('/(\d+)$/', (string)$uid, $m) && (int)$m[1] > $maxNum) {
                $maxNum = (int)$m[1];
                $latest = (string)$uid;
            }
        }
        return $latest;
    }
}

if (!function_exists('generateNextRoleUserId')) {
    function generateNextRoleUserId(string $role): string {
        $roleMap = [
            'admin' => 'ADMIN',
            'technician' => 'TECH',
            'student' => 'STU',
            'faculty' => 'FAC',
        ];
        $prefix = $roleMap[$role] ?? strtoupper(substr($role, 0, 4));
        $latestUserId = getLatestUserIdForRolePrefix($role, $prefix);

        $number = 0;
        if ($latestUserId && preg_match('/(\d+)$/', $latestUserId, $m)) {
            $number = (int)$m[1];
        }

        // Increment until we find a genuinely free id (collision-proof).
        do {
            $number++;
            $candidate = $prefix . '-' . str_pad((string)$number, 3, '0', STR_PAD_LEFT);
        } while (findUserById($candidate) !== null);

        return $candidate;
    }
}

if (!function_exists('createUserAccount')) {
    function createUserAccount(array $userData): bool {
        $allowedFields = ['user_id', 'username', 'password', 'fullname', 'email', 'role', 'status', 'phone', 'department', 'school_id', 'course'];
        $insertData = [];
        foreach ($allowedFields as $field) {
            if (array_key_exists($field, $userData)) {
                $insertData[$field] = $userData[$field];
            }
        }

        if (empty($insertData['user_id']) || empty($insertData['username']) || empty($insertData['password']) || empty($insertData['fullname']) || empty($insertData['email']) || empty($insertData['role'])) {
            return false;
        }

        if (!isset($insertData['status']) || trim((string)$insertData['status']) === '') {
            $insertData['status'] = 'active';
        }

        if (isPgSqlDriver()) {
            $pdo = getPgsqlPdoConnection();
            $fields = array_keys($insertData);
            $sql = 'INSERT INTO public.users (' . implode(', ', $fields) . ') VALUES (:' . implode(', :', $fields) . ')';
            $stmt = $pdo->prepare($sql);
            return $stmt->execute($insertData);
        }

        $conn = getDBConnection();
        $fields = array_keys($insertData);
        $placeholders = implode(', ', array_fill(0, count($fields), '?'));
        $sql = 'INSERT INTO users (' . implode(', ', $fields) . ') VALUES (' . $placeholders . ')';
        $stmt = $conn->prepare($sql);
        $values = array_values($insertData);
        $stmt->bind_param(str_repeat('s', count($values)), ...$values);
        $ok = $stmt->execute();
        $stmt->close();
        return (bool)$ok;
    }
}

if (!function_exists('findUserById')) {
    function findUserById(string $userId, array $fields = ['user_id', 'email', 'fullname', 'username', 'password', 'status', 'role']): ?array {
        if ($userId === '') {
            return null;
        }

        $safeFields = array_values(array_filter($fields, static fn($f) => preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', (string)$f)));
        if (empty($safeFields)) {
            $safeFields = ['user_id', 'email', 'fullname', 'username', 'password', 'status', 'role'];
        }
        $fieldList = implode(', ', $safeFields);

        if (isPgSqlDriver()) {
            $pdo = getPgsqlPdoConnection();
            $stmt = $pdo->prepare("SELECT {$fieldList} FROM public.users WHERE user_id = :user_id LIMIT 1");
            $stmt->execute(['user_id' => $userId]);
            $row = $stmt->fetch();
            return is_array($row) ? $row : null;
        }

        $conn = getDBConnection();
        $stmt = $conn->prepare("SELECT {$fieldList} FROM users WHERE user_id = ? LIMIT 1");
        $stmt->bind_param('s', $userId);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result ? $result->fetch_assoc() : null;
        $stmt->close();
        return $row ?: null;
    }
}

if (!function_exists('updateUserFieldsById')) {
    function updateUserFieldsById(string $userId, array $updateData): bool {
        if ($userId === '') {
            return false;
        }

        $allowedFields = ['fullname', 'email', 'password', 'role', 'status', 'phone', 'department', 'username', 'last_login'];
        $filtered = [];
        foreach ($updateData as $field => $value) {
            if (in_array($field, $allowedFields, true)) {
                $filtered[$field] = $value;
            }
        }
        if (empty($filtered)) {
            return false;
        }

        if (isPgSqlDriver()) {
            $pdo = getPgsqlPdoConnection();
            $sets = [];
            foreach (array_keys($filtered) as $field) {
                $sets[] = "{$field} = :{$field}";
            }
            $filtered['user_id'] = $userId;
            $stmt = $pdo->prepare('UPDATE public.users SET ' . implode(', ', $sets) . ' WHERE user_id = :user_id');
            return $stmt->execute($filtered);
        }

        $conn = getDBConnection();
        $sets = [];
        foreach (array_keys($filtered) as $field) {
            $sets[] = "{$field} = ?";
        }
        $values = array_values($filtered);
        $values[] = $userId;
        $stmt = $conn->prepare('UPDATE users SET ' . implode(', ', $sets) . ' WHERE user_id = ?');
        $stmt->bind_param(str_repeat('s', count($values)), ...$values);
        $ok = $stmt->execute();
        $stmt->close();
        return (bool)$ok;
    }
}

if (!function_exists('updateUserLastLogin')) {
    function updateUserLastLogin(string $userId): bool {
        if ($userId === '') {
            return false;
        }
        if (isPgSqlDriver()) {
            $pdo = getPgsqlPdoConnection();
            $stmt = $pdo->prepare('UPDATE public.users SET last_login = now() WHERE user_id = :user_id');
            return $stmt->execute(['user_id' => $userId]);
        }
        $conn = getDBConnection();
        $stmt = $conn->prepare("UPDATE users SET last_login = NOW() WHERE user_id = ?");
        $stmt->bind_param('s', $userId);
        $ok = $stmt->execute();
        $stmt->close();
        return (bool)$ok;
    }
}

if (!function_exists('createPasswordResetRecord')) {
    function createPasswordResetRecord(string $email, string $token, string $expiresAt): bool {
        // Expiry is computed on the DATABASE clock (now() + 1 hour) so it can never be thrown off
        // by a mismatch between the PHP timezone and the database timezone. $expiresAt is ignored.
        if (isPgSqlDriver()) {
            $pdo = getPgsqlPdoConnection();
            $stmt = $pdo->prepare("INSERT INTO public.password_resets (email, token, expires_at, created_at) VALUES (:email, :token, now() + interval '1 hour', now())");
            return $stmt->execute([
                'email' => $email,
                'token' => $token,
            ]);
        }
        $conn = getDBConnection();
        $stmt = $conn->prepare("INSERT INTO password_resets (email, token, expires_at) VALUES (?, ?, NOW() + INTERVAL 1 HOUR)");
        $stmt->bind_param('ss', $email, $token);
        $ok = $stmt->execute();
        $stmt->close();
        return (bool)$ok;
    }
}

if (!function_exists('replacePasswordResetRecord')) {
    function replacePasswordResetRecord(string $email, string $token, string $expiresAt): bool {
        if ($email === '' || $token === '' || $expiresAt === '') {
            return false;
        }

        if (isPgSqlDriver()) {
            $pdo = getPgsqlPdoConnection();
            $pdo->beginTransaction();
            try {
                $delete = $pdo->prepare('DELETE FROM public.password_resets WHERE email = :email');
                $delete->execute(['email' => $email]);
                $insert = $pdo->prepare("INSERT INTO public.password_resets (email, token, expires_at, created_at) VALUES (:email, :token, now() + interval '1 hour', now())");
                $insert->execute([
                    'email' => $email,
                    'token' => $token,
                ]);
                $pdo->commit();
                return true;
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $e;
            }
        }

        $conn = getDBConnection();
        $conn->begin_transaction();
        try {
            $delete = $conn->prepare('DELETE FROM password_resets WHERE email = ?');
            $delete->bind_param('s', $email);
            $delete->execute();
            $delete->close();

            $insert = $conn->prepare('INSERT INTO password_resets (email, token, expires_at) VALUES (?, ?, NOW() + INTERVAL 1 HOUR)');
            $insert->bind_param('ss', $email, $token);
            $ok = $insert->execute();
            $insert->close();

            $conn->commit();
            return (bool)$ok;
        } catch (Throwable $e) {
            $conn->rollback();
            throw $e;
        }
    }
}

if (!function_exists('findActivePasswordResetUserByToken')) {
    function findActivePasswordResetUserByToken(string $token, ?string $role = null): ?array {
        if (isPgSqlDriver()) {
            $pdo = getPgsqlPdoConnection();
            $sql = "SELECT u.user_id, u.email
                    FROM public.password_resets pr
                    JOIN public.users u ON pr.email = u.email
                    WHERE pr.token = :token AND pr.expires_at > now()";
            $params = ['token' => $token];
            if ($role !== null && $role !== '') {
                $sql .= " AND u.role = :role";
                $params['role'] = $role;
            }
            $sql .= " ORDER BY pr.created_at DESC LIMIT 1";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $row = $stmt->fetch();
            return is_array($row) ? $row : null;
        }

        $conn = getDBConnection();
        $sql = "SELECT u.user_id, u.email
                FROM password_resets pr
                JOIN users u ON pr.email = u.email
                WHERE pr.token = ? AND pr.expires_at > NOW()";
        $types = 's';
        $args = [$token];
        if ($role !== null && $role !== '') {
            $sql .= " AND u.role = ?";
            $types .= 's';
            $args[] = $role;
        }
        $sql .= " LIMIT 1";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param($types, ...$args);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result ? $result->fetch_assoc() : null;
        $stmt->close();
        return $row ?: null;
    }
}

if (!function_exists('deletePasswordResetToken')) {
    function deletePasswordResetToken(string $token): bool {
        if (isPgSqlDriver()) {
            $pdo = getPgsqlPdoConnection();
            $stmt = $pdo->prepare('DELETE FROM public.password_resets WHERE token = :token');
            return $stmt->execute(['token' => $token]);
        }
        $conn = getDBConnection();
        $stmt = $conn->prepare("DELETE FROM password_resets WHERE token = ?");
        $stmt->bind_param('s', $token);
        $ok = $stmt->execute();
        $stmt->close();
        return (bool)$ok;
    }
}

if (!function_exists('updateUserPasswordById')) {
    function updateUserPasswordById(string $userId, string $hashedPassword): bool {
        if (isPgSqlDriver()) {
            $pdo = getPgsqlPdoConnection();
            $stmt = $pdo->prepare('UPDATE public.users SET password = :password WHERE user_id = :user_id');
            return $stmt->execute([
                'password' => $hashedPassword,
                'user_id' => $userId,
            ]);
        }
        $conn = getDBConnection();
        $stmt = $conn->prepare("UPDATE users SET password = ? WHERE user_id = ?");
        $stmt->bind_param('ss', $hashedPassword, $userId);
        $ok = $stmt->execute();
        $stmt->close();
        return (bool)$ok;
    }
}

if (!function_exists('markEmailOtpUsed')) {
    function markEmailOtpUsed(string $email): bool {
        if (isPgSqlDriver()) {
            $pdo = getPgsqlPdoConnection();
            $stmt = $pdo->prepare('UPDATE public.email_otp SET is_used = true WHERE email = :email AND is_used = false');
            return $stmt->execute(['email' => $email]);
        }
        $conn = getDBConnection();
        $stmt = $conn->prepare("UPDATE email_otp SET is_used = 1 WHERE email = ? AND is_used = 0");
        $stmt->bind_param('s', $email);
        $ok = $stmt->execute();
        $stmt->close();
        return (bool)$ok;
    }
}

if (!function_exists('insertEmailOtp')) {
    function insertEmailOtp(string $email, string $otp, string $role = 'admin', int $ttlMinutes = 10): bool {
        if (isPgSqlDriver()) {
            $pdo = getPgsqlPdoConnection();
            $stmt = $pdo->prepare("INSERT INTO public.email_otp (email, otp_code, user_role, expires_at, created_at, is_used, attempts)
                                   VALUES (:email, :otp_code, :user_role, now() + make_interval(mins => :ttl), now(), false, 0)");
            return $stmt->execute([
                'email' => $email,
                'otp_code' => $otp,
                'user_role' => $role,
                'ttl' => $ttlMinutes,
            ]);
        }
        $conn = getDBConnection();
        $stmt = $conn->prepare("INSERT INTO email_otp (email, otp_code, user_role, expires_at) VALUES (?, ?, ?, DATE_ADD(NOW(), INTERVAL {$ttlMinutes} MINUTE))");
        $stmt->bind_param('sss', $email, $otp, $role);
        $ok = $stmt->execute();
        $stmt->close();
        return (bool)$ok;
    }
}

if (!function_exists('getRecentOtpSecondsAgo')) {
    function getRecentOtpSecondsAgo(string $email, int $windowSeconds = 10): ?int {
        if (isPgSqlDriver()) {
            $pdo = getPgsqlPdoConnection();
            $stmt = $pdo->prepare("SELECT EXTRACT(EPOCH FROM (now() - created_at))::int AS seconds_ago
                                   FROM public.email_otp
                                   WHERE email = :email AND created_at > now() - make_interval(secs => :window)
                                   ORDER BY created_at DESC LIMIT 1");
            $stmt->execute(['email' => $email, 'window' => $windowSeconds]);
            $row = $stmt->fetch();
            return isset($row['seconds_ago']) ? (int)$row['seconds_ago'] : null;
        }
        $conn = getDBConnection();
        $stmt = $conn->prepare("SELECT TIMESTAMPDIFF(SECOND, created_at, NOW()) as seconds_ago FROM email_otp WHERE email = ? AND created_at > DATE_SUB(NOW(), INTERVAL {$windowSeconds} SECOND) ORDER BY created_at DESC LIMIT 1");
        $stmt->bind_param('s', $email);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result ? $result->fetch_assoc() : null;
        $stmt->close();
        return isset($row['seconds_ago']) ? (int)$row['seconds_ago'] : null;
    }
}

if (!function_exists('findEmailOtpRecord')) {
    function findEmailOtpRecord(string $email, string $otpCode, string $role = 'admin', string $state = 'valid'): ?array {
        if (isPgSqlDriver()) {
            $pdo = getPgsqlPdoConnection();
            $base = "SELECT * FROM public.email_otp WHERE email = :email AND otp_code = :otp_code AND user_role = :user_role";
            if ($state === 'valid') {
                $base .= " AND is_used = false AND expires_at > now()";
            } elseif ($state === 'expired') {
                $base .= " AND is_used = false AND expires_at <= now()";
            } elseif ($state === 'used') {
                $base .= " AND is_used = true";
            }
            $base .= " ORDER BY created_at DESC LIMIT 1";
            $stmt = $pdo->prepare($base);
            $stmt->execute([
                'email' => $email,
                'otp_code' => $otpCode,
                'user_role' => $role,
            ]);
            $row = $stmt->fetch();
            return is_array($row) ? $row : null;
        }
        $conn = getDBConnection();
        $sql = "SELECT * FROM email_otp WHERE email = ? AND otp_code = ? AND user_role = ?";
        if ($state === 'valid') {
            $sql .= " AND is_used = 0 AND expires_at > NOW()";
        } elseif ($state === 'expired') {
            $sql .= " AND is_used = 0 AND expires_at <= NOW()";
        } elseif ($state === 'used') {
            $sql .= " AND is_used = 1";
        }
        $sql .= " ORDER BY created_at DESC LIMIT 1";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('sss', $email, $otpCode, $role);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result ? $result->fetch_assoc() : null;
        $stmt->close();
        return $row ?: null;
    }
}

if (!function_exists('markOtpUsedById')) {
    function markOtpUsedById(int $otpId): bool {
        if (isPgSqlDriver()) {
            $pdo = getPgsqlPdoConnection();
            $stmt = $pdo->prepare('UPDATE public.email_otp SET is_used = true WHERE otp_id = :otp_id');
            return $stmt->execute(['otp_id' => $otpId]);
        }
        $conn = getDBConnection();
        $stmt = $conn->prepare("UPDATE email_otp SET is_used = 1 WHERE otp_id = ?");
        $stmt->bind_param('i', $otpId);
        $ok = $stmt->execute();
        $stmt->close();
        return (bool)$ok;
    }
}

if (!function_exists('deleteExpiredOtps')) {
    function deleteExpiredOtps(): int {
        if (isPgSqlDriver()) {
            $pdo = getPgsqlPdoConnection();
            $stmt = $pdo->prepare('DELETE FROM public.email_otp WHERE expires_at < now()');
            $stmt->execute();
            return (int)$stmt->rowCount();
        }
        $conn = getDBConnection();
        $result = $conn->query("DELETE FROM email_otp WHERE expires_at < NOW()");
        return $result ? (int)$conn->affected_rows : 0;
    }
}

function equipmentTableColumns($conn) {
    static $columns = null;
    if ($columns !== null) {
        return $columns;
    }

    $columns = array_fill_keys(array_keys(getTableColumns('equipment')), true);
    return $columns;
}

function equipmentIdColumn($conn) {
    $cols = equipmentTableColumns($conn);
    return isset($cols['id']) ? 'id' : 'equipment_id';
}
// ============================================
// EQUIPMENT FUNCTIONS
// ============================================

function getAllEquipment() {
    $conn = getDBConnection();

    $cols = equipmentTableColumns($conn);
    if (isset($cols['equipment_category'])) {
        $categoryExpr = "e.equipment_category AS category_name";
        $join = "";
    } elseif (isset($cols['category'])) {
        $categoryExpr = "e.category AS category_name";
        $join = "";
    } elseif (isset($cols['category_id'])) {
        $categoryExpr = "c.category_name AS category_name";
        $join = "LEFT JOIN categories c ON c.category_id = e.category_id";
    } else {
        $categoryExpr = "NULL AS category_name";
        $join = "";
    }

    $sql = "SELECT e.*, {$categoryExpr}
            FROM equipment e
            {$join}
            WHERE e.status != 'deleted'
            ORDER BY e.equipment_name ASC";
    $result = $conn->query($sql);
    return $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
}

function getAllCategories() {
    $conn = getDBConnection();
    $sql = "SELECT * FROM categories ORDER BY category_name ASC";
    $result = $conn->query($sql);
    return $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
}

function getEquipmentById($equipment_id) {
    $conn = getDBConnection();

    $cols = equipmentTableColumns($conn);
    if (isset($cols['equipment_category'])) {
        $categoryExpr = "e.equipment_category AS category_name";
        $join = "";
    } elseif (isset($cols['category'])) {
        $categoryExpr = "e.category AS category_name";
        $join = "";
    } elseif (isset($cols['category_id'])) {
        $categoryExpr = "c.category_name AS category_name";
        $join = "LEFT JOIN categories c ON c.category_id = e.category_id";
    } else {
        $categoryExpr = "NULL AS category_name";
        $join = "";
    }
    $idCol = isset($cols['id']) ? 'id' : 'equipment_id';

    $stmt = $conn->prepare("SELECT e.*, {$categoryExpr}
                            FROM equipment e
                            {$join}
                            WHERE e.{$idCol} = ?");
    $stmt->bind_param("s", $equipment_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $equipment = $result->fetch_assoc();
    $stmt->close();
    return $equipment;
}

/**
 * Get defect reports grouped by category
 */
function getAllDefectReports() {
    return getDefectReportsWithFilters('all', 'all', '');
}

function getDefectReportColumns() {
    static $columns = null;

    if ($columns !== null) {
        return $columns;
    }

    $columns = array_fill_keys(array_keys(getTableColumns('defect_reports')), true);
    return $columns;
}

/**
 * The unit ("PMO" or "ITSO") an admin belongs to, derived from their department.
 * Returns '' when the admin has no unit set (treated as "see all"). Cached per request.
 */
function adminUnitForUser(string $userId): string {
    static $cache = [];
    if ($userId === '') return '';
    if (isset($cache[$userId])) return $cache[$userId];
    $d = '';
    try {
        if (isPgSqlDriver()) {
            $pdo = getPgsqlPdoConnection();
            $st = $pdo->prepare("SELECT department FROM public.users WHERE user_id = :u LIMIT 1");
            $st->execute(['u' => $userId]); $d = (string)$st->fetchColumn();
        } else {
            $conn = getDBConnection();
            $st = $conn->prepare("SELECT department FROM users WHERE user_id = ? LIMIT 1");
            if ($st) { $st->bind_param('s', $userId); $st->execute(); $r = $st->get_result()->fetch_assoc(); $d = (string)($r['department'] ?? ''); }
        }
    } catch (\Throwable $e) { $d = ''; }
    $d = strtoupper($d);
    $unit = strpos($d, 'ITSO') !== false ? 'ITSO' : (strpos($d, 'PMO') !== false ? 'PMO' : '');
    $cache[$userId] = $unit;
    return $unit;
}

/**
 * The unit ("PMO" or "ITSO") responsible for a piece of equipment. Reads the stored
 * equipment.unit; if unset, classifies it live (computers/network → ITSO, facilities → PMO).
 */
function equipmentUnit(string $equipmentId): string {
    $equipmentId = trim($equipmentId);
    if ($equipmentId === '') return 'PMO';
    try {
        if (isPgSqlDriver()) {
            $pdo = getPgsqlPdoConnection();
            $st = $pdo->prepare("SELECT unit FROM public.equipment WHERE equipment_id = :id LIMIT 1");
            $st->execute(['id' => $equipmentId]); $u = strtoupper(trim((string)$st->fetchColumn()));
        } else {
            $conn = getDBConnection();
            $st = $conn->prepare("SELECT unit FROM equipment WHERE equipment_id = ? LIMIT 1");
            if ($st) { $st->bind_param('s', $equipmentId); $st->execute(); $r = $st->get_result()->fetch_assoc(); $u = strtoupper(trim((string)($r['unit'] ?? ''))); } else { $u = ''; }
        }
        if ($u === 'ITSO' || $u === 'PMO') return $u;
    } catch (\Throwable $e) {}
    return classifyDepartmentByEquipment($equipmentId); // fall back to live classification
}

/**
 * Save a reporter's self-provided contact details back onto their profile so the admin sees them
 * in User Management. Updates an existing BEC directory record and/or a registered user account
 * that matches the email. Only fills provided values; never creates a new record. Non-fatal.
 */
function becSyncReporterProfile(string $email, string $department = '', string $course = '', string $phone = ''): void {
    $email = strtolower(trim($email));
    if ($email === '' || !isPgSqlDriver()) return;
    $department = trim($department); $course = trim($course); $phone = trim($phone);
    if ($department === '' && $course === '' && $phone === '') return;
    try {
        $pdo = getPgsqlPdoConnection();
        // 1) BEC directory record (where guest reporters live)
        $sets = []; $p = ['e' => $email];
        if ($phone !== '')      { $sets[] = 'phone = :ph';   $p['ph'] = $phone; }
        if ($department !== '') { $sets[] = 'department = :d'; $p['d'] = $department; }
        if ($course !== '')     { $sets[] = 'program = :c';   $p['c'] = $course; }
        if ($sets) {
            $st = $pdo->prepare('UPDATE public.bec_directory SET ' . implode(', ', $sets) . ' WHERE lower(email) = :e');
            $st->execute($p);
        }
        // 2) Registered user account with the same email (if the columns exist)
        $cols = getTableColumns('users');
        $usets = []; $up = ['e' => $email];
        if ($phone !== '' && isset($cols['phone']))           { $usets[] = 'phone = :ph';   $up['ph'] = $phone; }
        if ($department !== '' && isset($cols['department']))  { $usets[] = 'department = :d'; $up['d'] = $department; }
        if ($usets) {
            $st2 = $pdo->prepare('UPDATE public.users SET ' . implode(', ', $usets) . ' WHERE lower(email) = :e');
            $st2->execute($up);
        }
    } catch (\Throwable $e) { /* non-fatal — profile sync must never block a report */ }
}

function inferDefectReportPhotoPaths(array $report) {
    $photos = [];

    foreach (['photo_path', 'photo_url', 'photo', 'image_path'] as $field) {
        $value = trim((string)($report[$field] ?? ''));
        if ($value !== '') {
            $photos[] = str_replace('\\', '/', $value);
        }
    }

    foreach (['defect_photos', 'photo_paths', 'photos'] as $field) {
        $raw = $report[$field] ?? null;
        if (empty($raw)) {
            continue;
        }

        if (is_array($raw)) {
            foreach ($raw as $path) {
                $path = trim((string)$path);
                if ($path !== '') {
                    $photos[] = str_replace('\\', '/', $path);
                }
            }
            continue;
        }

        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                foreach ($decoded as $path) {
                    $path = trim((string)$path);
                    if ($path !== '') {
                        $photos[] = str_replace('\\', '/', $path);
                    }
                }
            } else {
                $raw = trim($raw);
                if ($raw !== '') {
                    $photos[] = str_replace('\\', '/', $raw);
                }
            }
        }
    }

    $reportId = trim((string)($report['report_id'] ?? ''));
    if ($reportId !== '') {
        $patterns = [
            __DIR__ . '/../uploads/reports/' . $reportId . '.*',
            __DIR__ . '/../uploads/defect_reports/' . $reportId . '.*',
            __DIR__ . '/../uploads/defect_photos/' . $reportId . '.*',
        ];

        foreach ($patterns as $pattern) {
            foreach (glob($pattern) ?: [] as $file) {
                if (!is_file($file)) {
                    continue;
                }
                $realFile = realpath($file);
                if ($realFile === false) {
                    continue;
                }
                $relative = str_replace('\\', '/', str_replace(realpath(__DIR__ . '/..') . DIRECTORY_SEPARATOR, '', $realFile));
                $photos[] = ltrim($relative, '/');
            }
        }
    }

    $photos = array_values(array_unique(array_filter($photos, static function ($path) {
        if (!is_string($path) || trim($path) === '') {
            return false;
        }
        if (preg_match('#^https?://#i', $path)) {
            return true;
        }
        $fullPath = realpath(__DIR__ . '/..') . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, ltrim($path, '/'));
        return is_file($fullPath);
    })));

    return $photos;
}

function normalizeDefectReportRow($report) {
    if (!is_array($report)) {
        return $report;
    }

    $photos = inferDefectReportPhotoPaths($report);
    $report['photos'] = $photos;
    $report['photo_path'] = $photos[0] ?? (string)($report['photo_path'] ?? '');

    if (!isset($report['defect_photos']) || $report['defect_photos'] === null || $report['defect_photos'] === '') {
        $report['defect_photos'] = !empty($photos) ? json_encode($photos) : null;
    }

    return $report;
}

function normalizeDefectReportRows(array $reports) {
    foreach ($reports as $index => $report) {
        $reports[$index] = normalizeDefectReportRow($report);
    }

    return $reports;
}

function defectWorkflowStatuses(): array {
    return [
        'reported' => 'Submitted',
        'pmo_review' => 'Received by PMO',
        'ready_for_assignment' => 'Ready for Assignment',
        'assigned' => 'Technician Assigned',
        'accepted' => 'Received by Technician',
        'in_progress' => 'In Progress',
        'waiting_for_materials' => 'Waiting for Materials',
        'for_replacement' => 'For Replacement',
        'completed' => 'Completed',
        'verified' => 'Verified',
        'closed' => 'Closed',
        'rejected' => 'Rejected',
    ];
}

function defectResolvedStatuses(): array {
    return ['completed', 'verified', 'closed'];
}

function defectTerminalStatuses(): array {
    return ['verified', 'closed', 'rejected'];
}

function defectAssignmentReadyStatuses(): array {
    return ['ready_for_assignment', 'assigned', 'in_progress'];
}

function defectStatusLabel($status): string {
    $status = strtolower(trim((string)$status));
    $labels = defectWorkflowStatuses();
    return $labels[$status] ?? ucwords(str_replace('_', ' ', $status));
}

function defectStatusCategory($status): string {
    $status = strtolower(trim((string)$status));
    return match ($status) {
        'reported', 'pmo_review', 'ready_for_assignment' => 'pending',
        'assigned', 'accepted', 'in_progress', 'waiting_for_materials', 'for_replacement' => 'in_progress',
        'completed', 'verified', 'closed' => 'completed',
        'rejected' => 'rejected',
        default => 'pending',
    };
}

function defectTimelineSteps(array $report): array {
    $status = strtolower(trim((string)($report['status'] ?? 'reported')));
    $hasReached = static fn(array $statuses): bool => in_array($status, $statuses, true);

    $steps = [
        ['label' => 'Submitted', 'done' => true, 'active' => $status === 'reported'],
        ['label' => 'Received by PMO', 'done' => $hasReached(['ready_for_assignment', 'assigned', 'accepted', 'in_progress', 'waiting_for_materials', 'for_replacement', 'completed', 'verified', 'closed']), 'active' => $status === 'pmo_review'],
        ['label' => 'Technician Assigned', 'done' => $hasReached(['accepted', 'in_progress', 'waiting_for_materials', 'for_replacement', 'completed', 'verified', 'closed']), 'active' => in_array($status, ['ready_for_assignment', 'assigned'], true)],
        ['label' => 'Received by Technician', 'done' => $hasReached(['in_progress', 'waiting_for_materials', 'for_replacement', 'completed', 'verified', 'closed']), 'active' => $status === 'accepted'],
        ['label' => 'Repair In Progress', 'done' => $hasReached(['for_replacement', 'completed', 'verified', 'closed']), 'active' => in_array($status, ['in_progress', 'waiting_for_materials'], true)],
        ['label' => 'PMO Verification', 'done' => $hasReached(['verified', 'closed']), 'active' => $status === 'completed'],
        ['label' => 'Closed', 'done' => $hasReached(['closed']), 'active' => $status === 'verified'],
    ];

    if ($status === 'for_replacement') {
        array_splice($steps, 4, 0, [[
            'label' => 'Replacement Required',
            'done' => true,
            'active' => true,
        ]]);
    }

    if ($status === 'rejected') {
        return [
            ['label' => 'Submitted', 'done' => true, 'active' => false],
            ['label' => 'Rejected', 'done' => true, 'active' => true],
        ];
    }

    return $steps;
}

/**
 * Auto-classify responsible department from reported equipment context.
 * Returns "ITSO" or "PMO" with PMO as conservative fallback.
 */
function classifyDepartmentByEquipment($equipment_id = null, $equipment_name = '', $category_name = '', $location = '', $issue_description = '') {
    $equipment_id = (string)($equipment_id ?? '');
    $equipment_name = (string)($equipment_name ?? '');
    $category_name = (string)($category_name ?? '');
    $location = (string)($location ?? '');
    $issue_description = (string)($issue_description ?? '');

    if ($equipment_id !== '' && ($equipment_name === '' || $category_name === '' || $location === '')) {
        try {
            $conn = getDBConnection();
            if ($conn) {
                $sql = "SELECT e.equipment_name, e.location,
                               COALESCE(c.category_name, '') AS category_name
                        FROM equipment e
                        LEFT JOIN categories c ON e.category_id = c.category_id
                        WHERE e.equipment_id = ?
                        LIMIT 1";
                $stmt = $conn->prepare($sql);
                if ($stmt) {
                    $stmt->bind_param("s", $equipment_id);
                    $stmt->execute();
                    $row = $stmt->get_result()->fetch_assoc();
                    $stmt->close();
                    if ($row) {
                        if ($equipment_name === '' && !empty($row['equipment_name'])) $equipment_name = (string)$row['equipment_name'];
                        if ($category_name === '' && !empty($row['category_name'])) $category_name = (string)$row['category_name'];
                        if ($location === '' && !empty($row['location'])) $location = (string)$row['location'];
                    }
                }
            }
        } catch (Exception $e) {}
    }

    $hay = strtolower(trim($equipment_name . ' ' . $category_name . ' ' . $location . ' ' . $issue_description));
    if ($hay === '') return 'PMO';

    $itsoKeywords = [
        'computer','desktop','laptop','notebook','macbook','pc',
        'monitor','display','projector','printer','scanner','router','switch','modem',
        'wifi','network','server','ups','keyboard','mouse','cpu','system unit',
        'it lab','laboratory computer','av','audio visual','smart tv','television',
        'cctv','camera','biometric','access point'
    ];
    $pmoKeywords = [
        'chair','table','desk','cabinet','drawer','shelf','door','window','ceiling','floor',
        'wall','toilet','sink','faucet','plumbing','pipe','drain','aircon','aircon unit',
        'air conditioner','hvac','electrical','wiring','outlet','socket','breaker','light',
        'bulb','fan','facility','building','room','furniture','paint'
    ];

    $scoreItso = 0;
    foreach ($itsoKeywords as $kw) { if (strpos($hay, $kw) !== false) $scoreItso++; }
    $scorePmo = 0;
    foreach ($pmoKeywords as $kw) { if (strpos($hay, $kw) !== false) $scorePmo++; }

    if ($scoreItso > $scorePmo) return 'ITSO';
    if ($scorePmo > $scoreItso) return 'PMO';
    if (strpos($hay, 'lab') !== false || strpos($hay, 'network') !== false || strpos($hay, 'computer') !== false) return 'ITSO';
    return 'PMO';
}

// ============================================
// DEFECT REPORT FUNCTIONS
// ============================================

function addDefectReport($data) {
    $validColumns = getDefectReportColumns();

    if (isset($data['defect_photos']) && is_array($data['defect_photos'])) {
        $data['defect_photos'] = json_encode($data['defect_photos']);
    }
    if (isset($data['defect_videos']) && is_array($data['defect_videos'])) {
        $data['defect_videos'] = json_encode($data['defect_videos']);
    }

    $filtered = [];
    foreach ($data as $field => $value) {
        if (!isset($validColumns[$field])) {
            continue;
        }
        $filtered[$field] = $value;
    }

    if (empty($filtered)) {
        return false;
    }

    // PostgreSQL / Supabase (PDO) path
    if (isPgSqlDriver()) {
        $pdo = getPgsqlPdoConnection();
        $fields = array_keys($filtered);
        $extraFields = [];
        $placeholders = [];
        foreach ($fields as $f) {
            $placeholders[] = ':' . $f;
        }
        if (!isset($filtered['report_date']) && isset($validColumns['report_date'])) {
            $extraFields[] = 'report_date';
            $placeholders[] = 'now()';
        }

        $allFields = array_merge($fields, $extraFields);
        $sql = 'INSERT INTO public.defect_reports (' . implode(', ', $allFields) . ') VALUES (' . implode(', ', $placeholders) . ')';
        $stmt = $pdo->prepare($sql);
        if ($stmt === false) return false;
        $params = [];
        foreach ($filtered as $k => $v) $params[$k] = $v;
        return (bool)$stmt->execute($params);
    }

    // MySQL (mysqli) path - unchanged
    $conn = getDBConnection();
    $fields = array_keys($filtered);
    $placeholders = array_fill(0, count($fields), '?');
    $types = str_repeat('s', count($fields));

    $extraFields = [];
    $extraValues = [];
    if (!isset($filtered['report_date']) && isset($validColumns['report_date'])) {
        $extraFields[] = 'report_date';
        $extraValues[] = 'NOW()';
    }

    $sql = "INSERT INTO defect_reports (" . implode(', ', array_merge($fields, $extraFields)) . ")
            VALUES (" . implode(', ', array_merge($placeholders, $extraValues)) . ")";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return false;
    }

    $values = array_values($filtered);
    $stmt->bind_param($types, ...$values);

    $result = $stmt->execute();
    $stmt->close();

    return $result;
}

function getDefectReportById($report_id) {
    if (isPgSqlDriver()) {
        $pdo = getPgsqlPdoConnection();
        $sql = "SELECT dr.*, e.equipment_name, e.asset_tag as asset_tag,
                            COALESCE(c.category_name, CAST(e.category_id AS TEXT)) as category_name,
                            COALESCE(tu.fullname, tu.username, mt.fullname) as technician_name,
                            COALESCE(NULLIF(dr.reporter_name,''), u.fullname, u.username, d.full_name, NULLIF(dr.reporter_email,''), dr.reported_by) as reporter_name
                            FROM public.defect_reports dr
                            JOIN public.equipment e ON dr.equipment_id = e.equipment_id
                            LEFT JOIN public.categories c ON e.category_id = c.category_id
                            LEFT JOIN public.users u ON dr.reported_by = u.user_id
                            LEFT JOIN public.bec_directory d ON dr.reporter_email IS NOT NULL AND lower(d.email) = lower(dr.reporter_email)
                            LEFT JOIN public.users tu ON dr.assigned_to = tu.user_id
                            LEFT JOIN public.maintenance_technicians mt ON dr.assigned_to = mt.technician_id
                            WHERE dr.report_id = :report_id";
        $stmt = $pdo->prepare($sql);
        $stmt->execute(['report_id' => $report_id]);
        $report = $stmt->fetch();
        return normalizeDefectReportRow($report);
    }

    $conn = getDBConnection();
    $stmt = $conn->prepare("SELECT dr.*, e.equipment_name, e.asset_tag as asset_tag,
                            COALESCE(c.category_name, CAST(e.category_id AS CHAR)) as category_name,
                            COALESCE(tu.fullname, tu.username, mt.fullname) as technician_name,
                            COALESCE(NULLIF(dr.reporter_name,''), u.fullname, u.username, d.full_name, NULLIF(dr.reporter_email,''), dr.reported_by) as reporter_name
                            FROM defect_reports dr
                            JOIN equipment e ON dr.equipment_id = e.equipment_id
                            LEFT JOIN categories c ON e.category_id = c.category_id
                            LEFT JOIN users u ON dr.reported_by = u.user_id
                            LEFT JOIN bec_directory d ON dr.reporter_email IS NOT NULL AND lower(d.email) = lower(dr.reporter_email)
                            LEFT JOIN users tu ON dr.assigned_to = tu.user_id
                            LEFT JOIN maintenance_technicians mt ON dr.assigned_to = mt.technician_id
                            WHERE dr.report_id = ?");
    $stmt->bind_param("s", $report_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $report = $result->fetch_assoc();
    $stmt->close();
    return normalizeDefectReportRow($report);
}

function getReportByIdPublic($report_id) {
    return getDefectReportById($report_id);
}

/**
 * Duplicate guard: the newest OPEN report for a piece of equipment, or null.
 * Used at submission time to warn reporters before filing a second ticket
 * for the same unit.
 */
function findOpenReportForEquipment(string $equipmentId): ?array {
    $equipmentId = trim($equipmentId);
    if ($equipmentId === '') { return null; }
    $conn = getDBConnection();
    $st = $conn->prepare("SELECT report_id, status, report_date FROM defect_reports
                          WHERE equipment_id = ?
                            AND status IN ('reported','pmo_review',
                                           'ready_for_assignment','assigned','accepted','in_progress',
                                           'waiting_for_materials','for_replacement')
                          ORDER BY report_date DESC LIMIT 1");
    if (!$st) { return null; }
    $st->bind_param('s', $equipmentId);
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    $st->close();
    return $row ?: null;
}

function getUserDefectReports($user_id) {
    if (isPgSqlDriver()) {
        $pdo = getPgsqlPdoConnection();
        $sql = "SELECT dr.*, e.equipment_name, e.asset_tag as asset_tag,
                            COALESCE(NULLIF(dr.reporter_name,''), u.fullname, u.username, d.full_name, NULLIF(dr.reporter_email,''), dr.reported_by) as reporter_name
                            FROM public.defect_reports dr
                            JOIN public.equipment e ON dr.equipment_id = e.equipment_id
                            LEFT JOIN public.users u ON dr.reported_by = u.user_id
                            LEFT JOIN public.bec_directory d ON dr.reporter_email IS NOT NULL AND lower(d.email) = lower(dr.reporter_email)
                            WHERE dr.reported_by = :user_id
                            ORDER BY dr.report_date DESC";
        $stmt = $pdo->prepare($sql);
        $stmt->execute(['user_id' => $user_id]);
        $reports = $stmt->fetchAll();
        return normalizeDefectReportRows($reports);
    }

    $conn = getDBConnection();

    $stmt = $conn->prepare("SELECT dr.*, e.equipment_name, e.asset_tag as asset_tag,
                            COALESCE(NULLIF(dr.reporter_name,''), u.fullname, u.username, d.full_name, NULLIF(dr.reporter_email,''), dr.reported_by) as reporter_name
                            FROM defect_reports dr
                            JOIN equipment e ON dr.equipment_id = e.equipment_id
                            LEFT JOIN users u ON dr.reported_by = u.user_id
                            LEFT JOIN bec_directory d ON dr.reporter_email IS NOT NULL AND lower(d.email) = lower(dr.reporter_email)
                            WHERE dr.reported_by = ?
                            ORDER BY dr.report_date DESC");
    $stmt->bind_param("s", $user_id);

    $stmt->execute();
    $result = $stmt->get_result();
    $reports = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return normalizeDefectReportRows($reports);
}

function updateDefectReport($report_id, $data) {
    $validColumns = array_fill_keys(array_keys(getTableColumns('defect_reports')), true);

    $updates = [];
    $values = [];

    foreach ($data as $key => $value) {
        if (!isset($validColumns[$key])) {
            continue;
        }
        $updates[$key] = $value;
    }

    if (empty($updates)) {
        return false;
    }

    if (isPgSqlDriver()) {
        $pdo = getPgsqlPdoConnection();
        $sets = [];
        $params = [];
        foreach ($updates as $k => $v) {
            $sets[] = "{$k} = :{$k}";
            $params[$k] = $v;
        }
        $params['report_id'] = $report_id;
        $sql = 'UPDATE public.defect_reports SET ' . implode(', ', $sets) . ' WHERE report_id = :report_id';
        $stmt = $pdo->prepare($sql);
        return (bool)$stmt->execute($params);
    }

    $conn = getDBConnection();
    $types = "";
    $values = [];
    $sets = [];
    foreach ($updates as $k => $v) {
        $sets[] = "$k = ?";
        $types .= "s";
        $values[] = $v;
    }
    $values[] = $report_id;
    $types .= "s";

    $sql = "UPDATE defect_reports SET " . implode(", ", $sets) . " WHERE report_id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$values);
    $result = $stmt->execute();
    $stmt->close();

    return $result;
}

function deleteDefectReport($report_id) {
    if (isPgSqlDriver()) {
        $pdo = getPgsqlPdoConnection();
        $stmt = $pdo->prepare('DELETE FROM public.defect_reports WHERE report_id = :report_id');
        return (bool)$stmt->execute(['report_id' => $report_id]);
    }

    $conn = getDBConnection();
    $stmt = $conn->prepare("DELETE FROM defect_reports WHERE report_id = ?");
    $stmt->bind_param("s", $report_id);
    $result = $stmt->execute();
    $stmt->close();

    return $result;
}

function getDefectReportsWithFilters($status = 'all', $priority = 'all', $search = '') {
    if (isPgSqlDriver()) {
        $pdo = getPgsqlPdoConnection();
        $sql = "SELECT dr.*, e.equipment_name, e.asset_tag as asset_tag,
            COALESCE(c.category_name, CAST(e.category_id AS TEXT)) as category_name,
            COALESCE(tu.fullname, tu.username, mt.fullname) as technician_name,
            COALESCE(NULLIF(dr.reporter_name,''), u.fullname, u.username, d.full_name, NULLIF(dr.reporter_email,''), dr.reported_by) as reporter_name
            FROM public.defect_reports dr
            JOIN public.equipment e ON dr.equipment_id = e.equipment_id
            LEFT JOIN public.categories c ON e.category_id = c.category_id
            LEFT JOIN public.users tu ON dr.assigned_to = tu.user_id
            LEFT JOIN public.maintenance_technicians mt ON dr.assigned_to = mt.technician_id
            LEFT JOIN public.users u ON dr.reported_by = u.user_id
                            LEFT JOIN public.bec_directory d ON dr.reporter_email IS NOT NULL AND lower(d.email) = lower(dr.reporter_email)
            WHERE 1=1";

        $params = [];
        if ($status !== 'all') {
            $sql .= ' AND dr.status = :status';
            $params['status'] = $status;
        }
        if ($priority !== 'all') {
            $sql .= ' AND dr.priority = :priority';
            $params['priority'] = $priority;
        }
        if (!empty($search)) {
            $sql .= " AND (dr.report_id LIKE :s OR e.equipment_name LIKE :s OR c.category_name LIKE :s OR dr.issue_description LIKE :s)";
            $params['s'] = "%{$search}%";
        }
        $sql .= ' ORDER BY dr.report_date DESC';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $reports = $stmt->fetchAll();
        return normalizeDefectReportRows($reports);
    }

    $conn = getDBConnection();

    $sql = "SELECT dr.*, e.equipment_name, e.asset_tag as asset_tag,
            COALESCE(c.category_name, CAST(e.category_id AS CHAR)) as category_name,
            COALESCE(tu.fullname, tu.username, mt.fullname) as technician_name,
            COALESCE(NULLIF(dr.reporter_name,''), u.fullname, u.username, d.full_name, NULLIF(dr.reporter_email,''), dr.reported_by) as reporter_name
            FROM defect_reports dr
            JOIN equipment e ON dr.equipment_id = e.equipment_id
            LEFT JOIN categories c ON e.category_id = c.category_id
            LEFT JOIN users tu ON dr.assigned_to = tu.user_id
            LEFT JOIN maintenance_technicians mt ON dr.assigned_to = mt.technician_id
            LEFT JOIN users u ON dr.reported_by = u.user_id
                            LEFT JOIN bec_directory d ON dr.reporter_email IS NOT NULL AND lower(d.email) = lower(dr.reporter_email)
            WHERE 1=1";

    $params = [];
    $types = "";

    if ($status !== 'all') {
        $sql .= " AND dr.status = ?";
        $params[] = $status;
        $types .= "s";
    }

    if ($priority !== 'all') {
        $sql .= " AND dr.priority = ?";
        $params[] = $priority;
        $types .= "s";
    }

    if (!empty($search)) {
        $sql .= " AND (dr.report_id LIKE ? OR e.equipment_name LIKE ? OR c.category_name LIKE ? OR dr.issue_description LIKE ?)";
        $search_param = "%$search%";
        $params[] = $search_param;
        $params[] = $search_param;
        $params[] = $search_param;
        $params[] = $search_param;
        $types .= "ssss";
    }

    $sql .= " ORDER BY dr.report_date DESC";

    $stmt = $conn->prepare($sql);
    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    $reports = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    return normalizeDefectReportRows($reports);
}
/**
 * Dashboard helpers
 * These wrappers provide 7/30-day chart/stat blocks used by admin_dashboard.php.
 */
function getDefectsOverTime($days = 7) {
    $days = max(1, (int)$days);
    $all = getAllDefectReports();
    $buckets = [];

    for ($i = $days - 1; $i >= 0; $i--) {
        $d = date('Y-m-d', strtotime("-{$i} day"));
        $buckets[$d] = 0;
    }

    foreach ($all as $row) {
        $raw = $row['report_date'] ?? null;
        if (!$raw) continue;
        $d = date('Y-m-d', strtotime($raw));
        if (isset($buckets[$d])) {
            $buckets[$d]++;
        }
    }

    $out = [];
    foreach ($buckets as $date => $count) {
        $out[] = ['date' => $date, 'count' => $count];
    }
    return $out;
}

function getReservationsOverTime($days = 7) {
    $conn = getDBConnection();
    $days = max(1, (int)$days);
    $buckets = [];

    for ($i = $days - 1; $i >= 0; $i--) {
        $d = date('Y-m-d', strtotime("-{$i} day"));
        $buckets[$d] = 0;
    }

    $hasReservations = false;
    $hasReservations = tableExists('reservations');
    if (!$hasReservations) {
        $out = [];
        foreach ($buckets as $date => $count) $out[] = ['date' => $date, 'count' => $count];
        return $out;
    }

    $cols = array_fill_keys(array_keys(getTableColumns('reservations')), true);

    $dateCol = isset($cols['request_date']) ? 'request_date'
        : (isset($cols['created_at']) ? 'created_at' : null);

    if (!$dateCol) {
        $out = [];
        foreach ($buckets as $date => $count) $out[] = ['date' => $date, 'count' => $count];
        return $out;
    }

    $daysInt = max(1, (int)$days);
    $sql = "SELECT DATE($dateCol) AS d, COUNT(*) AS c
            FROM reservations
            WHERE $dateCol >= DATE_SUB(CURDATE(), INTERVAL {$daysInt} DAY)
            GROUP BY DATE($dateCol)";
    $stmt = $conn->prepare($sql);
    if ($stmt) {
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $d = $row['d'];
            if (isset($buckets[$d])) {
                $buckets[$d] = (int)$row['c'];
            }
        }
        $stmt->close();
    }

    $out = [];
    foreach ($buckets as $date => $count) {
        $out[] = ['date' => $date, 'count' => $count];
    }
    return $out;
}

function getEquipmentUsageOverTime($days = 7) {
    $conn = getDBConnection();
    $days = max(1, (int)$days);
    $buckets = [];

    for ($i = $days - 1; $i >= 0; $i--) {
        $d = date('Y-m-d', strtotime("-{$i} day"));
        $buckets[$d] = 0;
    }

    $hasReservations = false;
    $hasReservations = tableExists('reservations');
    if (!$hasReservations) {
        $out = [];
        foreach ($buckets as $date => $count) $out[] = ['date' => $date, 'count' => $count];
        return $out;
    }

    $cols = array_fill_keys(array_keys(getTableColumns('reservations')), true);

    $statusCol = isset($cols['status']) ? 'status' : null;
    $startCol = isset($cols['start_date']) ? 'start_date' : (isset($cols['request_date']) ? 'request_date' : null);
    if (!$startCol) {
        $out = [];
        foreach ($buckets as $date => $count) $out[] = ['date' => $date, 'count' => $count];
        return $out;
    }

    $daysInt = max(1, (int)$days);
    $sql = "SELECT DATE($startCol) AS d, COUNT(*) AS c
            FROM reservations
            WHERE $startCol >= DATE_SUB(CURDATE(), INTERVAL {$daysInt} DAY)";
    if ($statusCol) {
        $sql .= " AND $statusCol IN ('approved','active','completed')";
    }
    $sql .= " GROUP BY DATE($startCol)";

    $stmt = $conn->prepare($sql);
    if ($stmt) {
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $d = $row['d'];
            if (isset($buckets[$d])) {
                $buckets[$d] = (int)$row['c'];
            }
        }
        $stmt->close();
    }

    $out = [];
    foreach ($buckets as $date => $count) {
        $out[] = ['date' => $date, 'count' => $count];
    }
    return $out;
}

function getSystemStatistics() {
    $conn = getDBConnection();
    $stats = [
        'available_equipment' => 0,
        'in_use_equipment' => 0,
        'maintenance_equipment' => 0,
        'defective_equipment' => 0,
    ];

    $hasEquipment = tableExists('equipment');
    if (!$hasEquipment) return $stats;

    $statusExpr = "LOWER(COALESCE(status,''))";
    $sql = "SELECT
                SUM(CASE WHEN $statusExpr IN ('operational','available') THEN 1 ELSE 0 END) AS available_equipment,
                SUM(CASE WHEN $statusExpr IN ('in_use','in use','borrowed') THEN 1 ELSE 0 END) AS in_use_equipment,
                SUM(CASE WHEN $statusExpr IN ('under_maintenance','maintenance') THEN 1 ELSE 0 END) AS maintenance_equipment,
                SUM(CASE WHEN $statusExpr IN ('defective','faulty') THEN 1 ELSE 0 END) AS defective_equipment
            FROM equipment
            WHERE $statusExpr <> 'deleted'";

    $res = $conn->query($sql);
    if ($res && ($row = $res->fetch_assoc())) {
        $stats['available_equipment'] = (int)($row['available_equipment'] ?? 0);
        $stats['in_use_equipment'] = (int)($row['in_use_equipment'] ?? 0);
        $stats['maintenance_equipment'] = (int)($row['maintenance_equipment'] ?? 0);
        $stats['defective_equipment'] = (int)($row['defective_equipment'] ?? 0);
    }

    return $stats;
}
function getDefectReportsByCategory($category_id = null, $status_filter = 'all') {
    $conn = getDBConnection();

    $sql = "SELECT e.equipment_category as category_name,
            COUNT(dr.report_id) as total_defects,
            COUNT(CASE WHEN dr.status IN ('reported', 'assigned', 'in_progress') THEN 1 END) as pending_defects,
            COUNT(CASE WHEN dr.status = 'completed' THEN 1 END) as resolved_defects,
            COUNT(CASE WHEN dr.priority = 'critical' THEN 1 END) as critical_defects,
            COUNT(CASE WHEN dr.priority = 'high' THEN 1 END) as high_defects,
            MAX(dr.report_date) as last_defect_date
            FROM equipment e
            LEFT JOIN defect_reports dr ON e.id = dr.equipment_id";

    $params = [];
    $types = "";

    if ($category_id) {
        $sql .= " WHERE e.equipment_category = ?";
        $params[] = $category_id;
        $types .= "s";
    }

    if ($status_filter !== 'all') {
        $where_clause = $category_id ? " AND" : " WHERE";
        if ($status_filter === 'pending') {
            $sql .= "$where_clause dr.status IN ('reported', 'assigned', 'in_progress')";
        } elseif ($status_filter === 'resolved') {
            $sql .= "$where_clause dr.status = 'completed'";
        } else {
            $sql .= "$where_clause dr.status = ?";
            $params[] = $status_filter;
            $types .= "s";
        }
    }

    $sql .= " GROUP BY e.equipment_category ORDER BY e.equipment_category ASC";

    $stmt = $conn->prepare($sql);
    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    $reports = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    return $reports;
}

/**
 * Get equipment defect statistics by category
 */
function getEquipmentDefectStats() {
    $conn = getDBConnection();

    $sql = "SELECT e.equipment_category as category_name,
            COUNT(DISTINCT e.id) as total_equipment,
            COUNT(DISTINCT CASE WHEN e.status = 'defective' THEN e.id END) as defective_equipment,
            COUNT(dr.report_id) as total_defects,
            ROUND(AVG(CASE WHEN dr.status = 'completed'
                          THEN DATEDIFF(dr.completion_date, dr.report_date) END), 1) as avg_resolution_days
            FROM equipment e
            LEFT JOIN defect_reports dr ON e.id = dr.equipment_id
            GROUP BY e.equipment_category
            ORDER BY total_defects DESC, category_name ASC";

    $result = $conn->query($sql);
    return $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
}

/**
 * Get defect trends by category over time
 */
function getDefectTrendsByCategory($days = 30) {
    $conn = getDBConnection();

    $daysInt = max(1, (int)$days);
    $sql = "SELECT e.equipment_category as category_name,
            DATE(dr.report_date) as report_date,
            COUNT(dr.report_id) as defect_count
            FROM equipment e
            LEFT JOIN defect_reports dr ON e.equipment_id = dr.equipment_id
            WHERE dr.report_date >= DATE_SUB(CURDATE(), INTERVAL {$daysInt} DAY)
            GROUP BY e.equipment_category, DATE(dr.report_date)
            ORDER BY e.equipment_category ASC, report_date ASC";

    $stmt = $conn->prepare($sql);
    $trends = [];
    if ($stmt) {
        $stmt->execute();
        $result = $stmt->get_result();
        $trends = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
        $stmt->close();
    }

    return $trends;
}

// ============================================
// RESERVATION FUNCTIONS
// ============================================

function addReservation($data) {
    $conn = getDBConnection();
    
    $stmt = $conn->prepare("INSERT INTO reservations 
        (reservation_id, equipment_id, user_id, start_date, end_date, purpose, status, request_date) 
        VALUES (?, ?, ?, ?, ?, ?, ?, NOW())");
    
    $stmt->bind_param("sssssss",
        $data['reservation_id'],
        $data['equipment_id'],
        $data['user_id'],
        $data['start_date'],
        $data['end_date'],
        $data['purpose'],
        $data['status']
    );
    
    $result = $stmt->execute();
    $stmt->close();
    
    return $result;
}

function checkReservationConflict($equipment_id, $start_date, $end_date) {
    $conn = getDBConnection();
    
    $stmt = $conn->prepare("SELECT COUNT(*) as count 
                            FROM reservations 
                            WHERE equipment_id = ? 
                            AND status IN ('pending', 'approved', 'active') 
                            AND NOT (end_date < ? OR start_date > ?)");
    
    $stmt->bind_param("sss", $equipment_id, $start_date, $end_date);
    $stmt->execute();
    $result = $stmt->get_result();
    $count = $result->fetch_assoc()['count'];
    $stmt->close();
    
    return $count > 0;
}

function getUserReservations($user_id) {
    $conn = getDBConnection();
    $stmt = $conn->prepare("SELECT r.*, e.equipment_name, e.id as asset_tag 
                            FROM reservations r 
                            JOIN equipment e ON r.equipment_id = e.id 
                            WHERE r.user_id = ? 
                            ORDER BY r.request_date DESC");
    $stmt->bind_param("s", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $reservations = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $reservations;
}

// ============================================
// NOTIFICATION FUNCTIONS
// ============================================

if (!function_exists('addNotification')) {
    function addNotification($user_id, $message, $type, $related_id = null) {
        $conn = getDBConnection();
        $notification_id = 'NOT-' . uniqid();

        $stmt = $conn->prepare("INSERT INTO notifications
            (notification_id, user_id, message, type, related_id, created_date, is_read)
            VALUES (?, ?, ?, ?, ?, NOW(), false)");

        $stmt->bind_param("sssss", $notification_id, $user_id, $message, $type, $related_id);
        $result = $stmt->execute();
        $stmt->close();

        return $result ? $notification_id : false;
    }
}

if (!function_exists('buildReporterStatusEmail')) {
    /** Clean, institutional status-update email for reporters. */
    function buildReporterStatusEmail(string $reportId, string $heading, string $message, array $report = []): string {
        $rid = htmlspecialchars($reportId, ENT_QUOTES);
        $h   = htmlspecialchars($heading, ENT_QUOTES);
        $m   = nl2br(htmlspecialchars($message, ENT_QUOTES));
        $eq  = htmlspecialchars((string)($report['equipment_name'] ?? ''), ENT_QUOTES);
        $loc = htmlspecialchars((string)($report['location'] ?? ''), ENT_QUOTES);
        $meta = '';
        if ($eq !== '')  { $meta .= '<tr><td style="padding:4px 0;color:#9E8070;font-size:13px;">Equipment</td><td style="padding:4px 0;color:#1C1008;font-size:13px;font-weight:600;">' . $eq . '</td></tr>'; }
        if ($loc !== '') { $meta .= '<tr><td style="padding:4px 0;color:#9E8070;font-size:13px;">Location</td><td style="padding:4px 0;color:#1C1008;font-size:13px;font-weight:600;">' . $loc . '</td></tr>'; }
        return '<div style="background:#F8F3EA;padding:24px;font-family:Segoe UI,Arial,sans-serif;">'
            . '<div style="max-width:560px;margin:0 auto;background:#fff;border:1px solid #E8DDD0;border-radius:14px;overflow:hidden;">'
            . '<div style="background:#7B1D1D;padding:18px 24px;">'
            . '<div style="color:#fff;font-size:15px;font-weight:700;">Batangas Eastern Colleges</div>'
            . '<div style="color:#E9C77B;font-size:11px;letter-spacing:.5px;text-transform:uppercase;">Property Management Office · Defective Equipment Reporting</div>'
            . '</div>'
            . '<div style="padding:24px;">'
            . '<div style="display:inline-block;background:#FBF3DF;color:#92600A;font-size:12px;font-weight:700;padding:5px 12px;border-radius:999px;margin-bottom:12px;">Report ' . $rid . '</div>'
            . '<h2 style="margin:0 0 8px;color:#7B1D1D;font-size:19px;">' . $h . '</h2>'
            . '<p style="margin:0 0 16px;color:#5C3838;font-size:14px;line-height:1.6;">' . $m . '</p>'
            . ($meta !== '' ? '<table style="width:100%;border-top:1px solid #E8DDD0;border-bottom:1px solid #E8DDD0;margin:12px 0;padding:6px 0;">' . $meta . '</table>' : '')
            . '<p style="margin:14px 0 0;color:#9E8070;font-size:12px;line-height:1.6;">You can follow your report\'s progress anytime using your ticket number on the Track Report page.</p>'
            . '</div>'
            . '<div style="background:#FAF7F0;padding:14px 24px;border-top:1px solid #E8DDD0;color:#9E8070;font-size:11px;text-align:center;">This is an automated message from the BEC Equipment Reporting System.</div>'
            . '</div></div>';
    }
}

if (!function_exists('notifyReporter')) {
    /**
     * Notify a report's reporter through an in-app notification and (when the
     * reporter email is known) a formatted email. Safe to call anywhere.
     */
    function notifyReporter(array $report, string $shortMessage, string $emailHeading = '', string $emailBody = ''): void {
        $reportId   = (string)($report['report_id'] ?? '');
        $reportedBy = (string)($report['reported_by'] ?? '');

        if ($reportedBy !== '') {
            try { addNotification($reportedBy, $shortMessage, 'report_status', $reportId); } catch (\Throwable $e) {}
        }

        $email = trim((string)($report['reporter_email'] ?? ''));
        if ($email === '' && $reportId !== '') {
            try {
                $c = getDBConnection();
                $st = $c->prepare("SELECT reporter_email FROM defect_reports WHERE report_id = ?");
                if ($st) { $st->bind_param('s', $reportId); $st->execute(); $row = $st->get_result()->fetch_assoc(); $st->close(); $email = trim((string)($row['reporter_email'] ?? '')); }
            } catch (\Throwable $e) {}
        }

        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
            if (!function_exists('sendEmail')) { @require_once __DIR__ . '/../includes/mail_helper.php'; }
            if (function_exists('sendEmail')) {
                $subject = 'BEC Report ' . $reportId . ($emailHeading !== '' ? ' — ' . $emailHeading : ' Update');
                $body = buildReporterStatusEmail($reportId, $emailHeading !== '' ? $emailHeading : 'Report Update', $emailBody !== '' ? $emailBody : $shortMessage, $report);
                try { sendEmail($email, $subject, $body, null, 'admin'); } catch (\Throwable $e) {}
            }
        }
    }
}

// ============================================
// TECHNICIAN FUNCTIONS
// ============================================

function getAvailableTechnicians() {
    $conn = getDBConnection();
    $sql = "SELECT
                u.user_id AS technician_id,
                u.fullname,
                COALESCE(NULLIF(mt.specialization, ''), 'General') AS specialization,
                u.status
            FROM users u
            LEFT JOIN maintenance_technicians mt
                ON mt.technician_id = u.user_id
                OR mt.username = u.username
                OR mt.email = u.email
            WHERE u.role = 'technician' AND u.status = 'active'

            UNION

            SELECT
                mt.technician_id,
                mt.fullname,
                COALESCE(NULLIF(mt.specialization, ''), 'General') AS specialization,
                mt.status
            FROM maintenance_technicians mt
            LEFT JOIN users u
                ON u.user_id = mt.technician_id
                OR u.username = mt.username
                OR u.email = mt.email
            WHERE mt.status = 'active' AND u.user_id IS NULL

            ORDER BY fullname ASC";
    $result = $conn->query($sql);
    return $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
}

function assignDefectReportToTechnician(string $reportId, string $technicianId, array $options = []): array {
    $reportId = trim($reportId);
    $technicianId = trim($technicianId);

    if ($reportId === '' || $technicianId === '') {
        return ['ok' => false, 'message' => 'Report and technician are required.'];
    }

    $conn = getDBConnection();
    $actorId = trim((string)($options['actor_id'] ?? ''));
    $priority = trim((string)($options['priority'] ?? 'medium'));
    $instructions = trim((string)($options['instructions'] ?? ''));
    $department = trim((string)($options['department'] ?? ''));

    try {
        $report = getDefectReportById($reportId);
        if (!$report) {
            return ['ok' => false, 'message' => 'Report not found.'];
        }

        $currentStatus = trim((string)($report['status'] ?? ''));
        // 'pmo_review' (Received by PMO) is assignable directly — assigning auto-approves it.
        if (!in_array($currentStatus, ['ready_for_assignment', 'assigned', 'pmo_review'], true)) {
            return ['ok' => false, 'message' => 'Only reports that have been received or approved can be assigned here.'];
        }

        $technicianExists = false;
        foreach (getAvailableTechnicians() as $technician) {
            $candidateId = trim((string)($technician['technician_id'] ?? $technician['user_id'] ?? ''));
            if ($candidateId === $technicianId) {
                $technicianExists = true;
                break;
            }
        }

        if (!$technicianExists) {
            return ['ok' => false, 'message' => 'Selected technician is not active.'];
        }

        $availableCols = array_fill_keys(array_keys(getTableColumns('defect_reports')), true);

        $sets = [];
        $types = '';
        $params = [];

        if (isset($availableCols['assigned_to'])) {
            $sets[] = 'assigned_to = ?';
            $types .= 's';
            $params[] = $technicianId;
        }
        if (isset($availableCols['status'])) {
            $sets[] = "status = 'assigned'";
        }
        // Assigning a Received report auto-approves it (records the approval state).
        if (isset($availableCols['admin_approval_status'])) {
            $sets[] = "admin_approval_status = 'approved'";
        }
        if (isset($availableCols['priority'])) {
            $sets[] = 'priority = ?';
            $types .= 's';
            $params[] = $priority;
        }
        if (isset($availableCols['handler_instructions'])) {
            $sets[] = 'handler_instructions = ?';
            $types .= 's';
            $params[] = $instructions;
        }
        if ($department !== '' && isset($availableCols['department_assigned'])) {
            $sets[] = 'department_assigned = ?';
            $types .= 's';
            $params[] = $department;
        }
        if (isset($availableCols['assigned_date'])) {
            $sets[] = 'assigned_date = NOW()';
        }
        if ($actorId !== '' && isset($availableCols['assigned_by'])) {
            $sets[] = 'assigned_by = ?';
            $types .= 's';
            $params[] = $actorId;
        }

        if (empty($sets)) {
            return ['ok' => false, 'message' => 'No compatible assignment columns found in defect_reports.'];
        }

        $params[] = $reportId;
        $types .= 's';
        $sql = "UPDATE defect_reports SET " . implode(",\n                ", $sets) . "\n            WHERE report_id = ?";

        $conn->begin_transaction();
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            throw new Exception('Failed to prepare assignment update.');
        }
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $stmt->close();

        addNotification($technicianId, "New maintenance task assigned - Report #{$reportId}", 'task_assigned', $reportId);
        $conn->commit();

        // Email the technician so they know about the assignment even when logged out (non-fatal).
        try { notifyTechnicianAssignment($conn, $technicianId, $reportId, $report, $priority, $instructions); } catch (\Throwable $e) {
            error_log('assign email failed: ' . $e->getMessage());
        }

        return ['ok' => true, 'message' => "Report #{$reportId} assigned successfully."];
    } catch (Throwable $e) {
        if ($conn->errno === 0 || $conn->errno >= 0) {
            try {
                $conn->rollback();
            } catch (Throwable $ignored) {
            }
        }
        return ['ok' => false, 'message' => 'Assignment failed: ' . $e->getMessage()];
    }
}

/**
 * Branded "new task assigned" email to the technician, with a deep link that
 * opens the exact repair workspace (technician_dashboard.php?report=...).
 * Never throws into the assignment flow — callers wrap it in try/catch.
 */
function notifyTechnicianAssignment($conn, string $technicianId, string $reportId, array $report, string $priority, string $instructions): bool {
    // Resolve the technician's mailbox (users first, legacy maintenance_technicians as fallback).
    $email = ''; $name = 'Technician';
    $st = $conn->prepare("SELECT email, fullname FROM users WHERE user_id = ? LIMIT 1");
    if ($st) { $st->bind_param('s', $technicianId); $st->execute(); if ($r = $st->get_result()->fetch_assoc()) { $email = trim((string)($r['email'] ?? '')); $name = trim((string)($r['fullname'] ?? '')) ?: $name; } $st->close(); }
    if ($email === '') {
        $st = $conn->prepare("SELECT email, fullname FROM maintenance_technicians WHERE technician_id = ? LIMIT 1");
        if ($st) { $st->bind_param('s', $technicianId); $st->execute(); if ($r = $st->get_result()->fetch_assoc()) { $email = trim((string)($r['email'] ?? '')); $name = trim((string)($r['fullname'] ?? '')) ?: $name; } $st->close(); }
    }
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) { return false; }

    require_once __DIR__ . '/../includes/mail_helper.php';

    $scheme = (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off') ? 'https' : 'http';
    $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $dir    = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
    $link   = $scheme . '://' . $host . $dir . '/technician_dashboard.php?tab=my_tasks&report=' . urlencode($reportId);

    $eq   = htmlspecialchars((string)($report['equipment_name'] ?? 'Equipment'), ENT_QUOTES, 'UTF-8');
    $loc  = htmlspecialchars((string)($report['location'] ?? 'Unspecified'), ENT_QUOTES, 'UTF-8');
    $iss  = htmlspecialchars(mb_strimwidth((string)($report['issue_description'] ?? ''), 0, 220, '…'), ENT_QUOTES, 'UTF-8');
    $prio = htmlspecialchars(ucfirst($priority ?: 'medium'), ENT_QUOTES, 'UTF-8');
    $instrBlock = trim($instructions) !== ''
        ? '<div style="margin:14px 0 0;padding:10px 12px;background:#FFFBEF;border-left:3px solid #C9960C;border-radius:6px;font-size:13px;color:#5C3838;"><strong>PMO instructions:</strong> ' . htmlspecialchars($instructions, ENT_QUOTES, 'UTF-8') . '</div>'
        : '';

    $subject = 'New Task Assigned — Report ' . $reportId . ' · BEC PMO';
    $html = '<div style="font-family:Segoe UI,Arial,sans-serif;background:#F4F1EC;padding:24px;">'
      . '<div style="max-width:560px;margin:0 auto;background:#fff;border:1px solid #E8DDD0;border-radius:14px;overflow:hidden;">'
        . '<div style="background:linear-gradient(135deg,#2D0505,#7B1D1D);color:#fff;padding:20px 24px;">'
          . '<div style="font-size:11px;letter-spacing:1.5px;text-transform:uppercase;color:#F0C040;">Batangas Eastern Colleges · Property Management Office</div>'
          . '<div style="font-size:19px;font-weight:700;margin-top:4px;">New Maintenance Task Assigned</div>'
        . '</div>'
        . '<div style="padding:22px 24px;color:#1C1008;">'
          . '<p style="font-size:14px;line-height:1.6;margin:0 0 14px;">Hello ' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . ', the PMO has assigned a repair task to you.</p>'
          . '<table style="width:100%;font-size:13px;color:#5C3838;">'
            . '<tr><td style="padding:3px 0;width:110px;color:#9E8070;">Report</td><td style="font-weight:700;color:#7B1D1D;">' . htmlspecialchars($reportId, ENT_QUOTES, 'UTF-8') . '</td></tr>'
            . '<tr><td style="padding:3px 0;color:#9E8070;">Equipment</td><td>' . $eq . '</td></tr>'
            . '<tr><td style="padding:3px 0;color:#9E8070;">Location</td><td>' . $loc . '</td></tr>'
            . '<tr><td style="padding:3px 0;color:#9E8070;">Priority</td><td>' . $prio . '</td></tr>'
            . ($iss !== '' ? '<tr><td style="padding:3px 0;color:#9E8070;vertical-align:top;">Issue</td><td>' . $iss . '</td></tr>' : '')
          . '</table>'
          . $instrBlock
          . '<div style="text-align:center;margin:22px 0 6px;">'
            . '<a href="' . htmlspecialchars($link, ENT_QUOTES, 'UTF-8') . '" style="display:inline-block;background:linear-gradient(135deg,#4A0E0E,#7B1D1D);color:#fff;text-decoration:none;font-weight:700;font-size:14px;padding:13px 28px;border-radius:10px;">Open Repair Workspace</a>'
          . '</div>'
          . '<p style="font-size:12px;color:#9E8070;text-align:center;margin:6px 0 0;">Sign in with your technician account to receive and start the task.</p>'
        . '</div>'
        . '<div style="background:#F8F3EA;padding:14px 24px;font-size:11px;color:#9E8070;text-align:center;border-top:1px solid #E8DDD0;">Batangas Eastern Colleges · Property Management Office · Automated message</div>'
      . '</div></div>';

    return (bool) sendEmail($email, $subject, $html, null, 'technician');
}

function getTechnicianStatistics($technician_id) {
    $conn = getDBConnection();

    $stmt = $conn->prepare("SELECT
        COUNT(CASE WHEN status IN ('assigned', 'in_progress', 'ready_for_assignment') AND assigned_to = ? THEN 1 END) as assigned_tasks,
        COUNT(CASE WHEN status = 'in_progress' AND assigned_to = ? THEN 1 END) as in_progress,
        COUNT(CASE WHEN status = 'completed' AND DATE(completion_date) = CURDATE() AND assigned_to = ? THEN 1 END) as completed_today,
        COUNT(CASE WHEN status = 'completed' AND assigned_to = ? THEN 1 END) as total_completed
        FROM defect_reports");

    $stmt->bind_param("ssss", $technician_id, $technician_id, $technician_id, $technician_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $stats = $result->fetch_assoc();
    $stmt->close();

    // Pending for technicians should only include work assigned to them.
    $stats['pending_tasks'] = $stats['assigned_tasks'];

    return $stats;
}

function getAssignedTasks($technician_id) {
    $conn = getDBConnection();
    $equipmentIdCol = equipmentIdColumn($conn);
    $stmt = $conn->prepare("SELECT dr.*, e.equipment_name, e.{$equipmentIdCol} as asset_tag, e.location,
                            CASE WHEN dr.assigned_to IS NULL THEN 'unassigned' ELSE 'assigned' END as task_type
                            FROM defect_reports dr
                            JOIN equipment e ON dr.equipment_id = e.{$equipmentIdCol}
                            WHERE dr.assigned_to = ? AND dr.status IN ('assigned', 'in_progress', 'ready_for_assignment')
                            ORDER BY dr.priority DESC, dr.assigned_date ASC, dr.report_date ASC");
    $stmt->bind_param("s", $technician_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $tasks = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $tasks;
}

function getTechnicianWorkHistory($technician_id) {
    $conn = getDBConnection();
    $equipmentIdCol = equipmentIdColumn($conn);
    $stmt = $conn->prepare("SELECT dr.*, e.equipment_name, e.{$equipmentIdCol} as asset_tag, e.location
                            FROM defect_reports dr
                            JOIN equipment e ON dr.equipment_id = e.{$equipmentIdCol}
                            WHERE dr.assigned_to = ?
                            AND dr.status = 'completed'
                            ORDER BY dr.completion_date DESC");
    $stmt->bind_param("s", $technician_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $history = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $history;
}

function getCompletedWorkForVerification() {
    $conn = getDBConnection();
    $equipmentIdCol = equipmentIdColumn($conn);
    $sql = "SELECT dr.*, e.equipment_name, e.{$equipmentIdCol} as asset_tag,
            mt.fullname as technician_name
            FROM defect_reports dr
            JOIN equipment e ON dr.equipment_id = e.{$equipmentIdCol}
            JOIN maintenance_technicians mt ON dr.assigned_to = mt.technician_id
            WHERE dr.status = 'completed'
            ORDER BY dr.completion_date DESC";
    $result = $conn->query($sql);
    return $result ? normalizeDefectReportRows($result->fetch_all(MYSQLI_ASSOC)) : [];
}

function getUnassignedReports() {
    $conn = getDBConnection();
    $equipmentIdCol = equipmentIdColumn($conn);
    $stmt = $conn->prepare("SELECT dr.*, e.equipment_name, e.{$equipmentIdCol} as asset_tag, e.location
                            FROM defect_reports dr
                            JOIN equipment e ON dr.equipment_id = e.{$equipmentIdCol}
                            WHERE dr.status = 'ready_for_assignment' AND dr.assigned_to IS NULL
                            ORDER BY dr.priority DESC, dr.report_date ASC");
    $stmt->execute();
    $result = $stmt->get_result();
    $reports = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return normalizeDefectReportRows($reports);
}

function getAvailableTasks() {
    return getUnassignedReports();
}

function getRecentAssignedTasks($technician_id, $limit = 5) {
    $conn = getDBConnection();
    $equipmentIdCol = equipmentIdColumn($conn);
    $sql = "SELECT dr.*, e.equipment_name, e.{$equipmentIdCol} as asset_tag, e.location
            FROM defect_reports dr
            JOIN equipment e ON dr.equipment_id = e.{$equipmentIdCol}
            WHERE dr.assigned_to = ? AND dr.status IN ('assigned', 'in_progress', 'completed', 'for_replacement')
            ORDER BY dr.assigned_date DESC, dr.report_date DESC
            LIMIT ?";

    $stmt = $conn->prepare($sql);
    $limit = (int)$limit;
    $stmt->bind_param("si", $technician_id, $limit);
    $stmt->execute();
    $result = $stmt->get_result();
    $tasks = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    return normalizeDefectReportRows($tasks);
}

// ============================================
// UTILITY FUNCTIONS
// ============================================

function getPriorityClass($priority) {
    $classes = [
        'critical' => 'danger',
        'high' => 'warning',
        'medium' => 'info',
        'low' => 'secondary'
    ];
    return $classes[$priority] ?? 'secondary';
}

function getStatusClass($status) {
    $classes = [
        'reported' => 'warning',
        'pmo_review' => 'warning',
        'ready_for_assignment' => 'info',
        'assigned' => 'info',
        'in_progress' => 'primary',
        'for_replacement' => 'danger',
        'completed' => 'success',
        'verified' => 'success',
        'closed' => 'secondary',
        'rejected' => 'danger',
        'available' => 'success',
        'in-use' => 'primary',
        'maintenance' => 'warning',
        'defective' => 'danger'
    ];
    return $classes[$status] ?? 'secondary';
}

function getReservationStatusClass($status) {
    $classes = [
        'pending' => 'warning',
        'approved' => 'success',
        'active' => 'primary',
        'completed' => 'secondary',
        'rejected' => 'danger',
        'cancelled' => 'danger'
    ];
    return $classes[$status] ?? 'secondary';
}



// ============================================
// USER AUTHENTICATION FUNCTIONS
// ============================================

/**
 * Verify user login credentials
 * @param string $email
 * @param string $password
 * @param string $role (admin, pmo, dean, finance, handler, technician, faculty, student)
 * @return array|false User data or false
 */
function authenticateUser($email, $password, $role) {
    $user = findUserByEmailAndRole((string)$email, (string)$role, [
        'user_id',
        'email',
        'fullname',
        'username',
        'password',
        'status',
        'role',
        'last_login',
        'created_at',
    ]);

    if (!$user) {
        return false;
    }

    if (isset($user['status']) && trim((string)$user['status']) !== '' && $user['status'] !== 'active') {
        return false;
    }

    // Verify password
    if (password_verify($password, $user['password'])) {
        updateUserLastLogin((string)$user['user_id']);
        return $user;
    }

    return false;
}

/**
 * Get user by ID and role
 */
function getUserById($user_id, $role) {
    $conn = getDBConnection();

    $roleTableMap = [
        'admin' => ['table' => 'admins', 'id_field' => 'admin_id'],
        'pmo' => ['table' => 'users', 'id_field' => 'user_id'],
        'dean' => ['table' => 'users', 'id_field' => 'user_id'],
        'finance' => ['table' => 'users', 'id_field' => 'user_id'],
        'technician' => ['table' => 'maintenance_technicians', 'id_field' => 'technician_id'],
        'faculty' => ['table' => 'faculty_members', 'id_field' => 'faculty_id'],
        'student' => ['table' => 'students', 'id_field' => 'student_id']
    ];

    if (!isset($roleTableMap[$role])) {
        return null;
    }

    $config = $roleTableMap[$role];
    $sql = in_array($role, ['pmo', 'dean', 'finance'], true)
        ? "SELECT * FROM `users` WHERE user_id = ? AND role = ? LIMIT 1"
        : "SELECT * FROM `{$config['table']}` WHERE {$config['id_field']} = ? LIMIT 1";

    $stmt = $conn->prepare($sql);
    if (in_array($role, ['pmo', 'dean', 'finance'], true)) {
        $stmt->bind_param("ss", $user_id, $role);
    } else {
        $stmt->bind_param("s", $user_id);
    }
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows > 0) {
        return $result->fetch_assoc();
    }

    return null;
}

/**
 * Create new user account
 */
function createUser($role, $userData) {
    $conn = getDBConnection();

    $roleTableMap = [
        'admin' => ['table' => 'admins', 'id_prefix' => 'ADM'],
        'pmo' => ['table' => 'users', 'id_prefix' => 'PMO'],
        'dean' => ['table' => 'users', 'id_prefix' => 'DEAN'],
        'finance' => ['table' => 'users', 'id_prefix' => 'FIN'],
        'technician' => ['table' => 'maintenance_technicians', 'id_prefix' => 'TEC'],
        'faculty' => ['table' => 'faculty_members', 'id_prefix' => 'FAC'],
        'student' => ['table' => 'students', 'id_prefix' => 'STU']
    ];

    if (!isset($roleTableMap[$role])) {
        return false;
    }

    $config = $roleTableMap[$role];
    $table = $config['table'];
    $idField = in_array($role, ['pmo', 'dean', 'finance'], true) ? 'user_id' : $role . '_id';

    // Generate ID
    if (!isset($userData[$idField])) {
        $userData[$idField] = $config['id_prefix'] . '-' . strtoupper(substr(uniqid(), -6));
    }

    // Hash password
    if (isset($userData['password'])) {
        $userData['password'] = password_hash($userData['password'], PASSWORD_BCRYPT);
    }

    // Build INSERT query
    $fields = array_keys($userData);
    $placeholders = array_fill(0, count($fields), '?');

    $sql = "INSERT INTO `$table` (" . implode(', ', $fields) . ")
            VALUES (" . implode(', ', $placeholders) . ")";

    $stmt = $conn->prepare($sql);

    // Bind parameters dynamically
    $types = str_repeat('s', count($fields));
    $values = array_values($userData);
    $stmt->bind_param($types, ...$values);

    return $stmt->execute();
}

/**
 * Update user information
 */
function updateUser($user_id, $role, $updateData) {
    $conn = getDBConnection();

    $roleTableMap = [
        'admin' => ['table' => 'admins', 'id_field' => 'admin_id'],
        'pmo' => ['table' => 'users', 'id_field' => 'user_id'],
        'dean' => ['table' => 'users', 'id_field' => 'user_id'],
        'finance' => ['table' => 'users', 'id_field' => 'user_id'],
        'technician' => ['table' => 'maintenance_technicians', 'id_field' => 'technician_id'],
        'faculty' => ['table' => 'faculty_members', 'id_field' => 'faculty_id'],
        'student' => ['table' => 'students', 'id_field' => 'student_id']
    ];

    if (!isset($roleTableMap[$role])) {
        return false;
    }

    $config = $roleTableMap[$role];

    // Hash password if being updated
    if (isset($updateData['password'])) {
        $updateData['password'] = password_hash($updateData['password'], PASSWORD_BCRYPT);
    }

    // Build UPDATE query
    $sets = [];
    $values = [];
    foreach ($updateData as $field => $value) {
        $sets[] = "$field = ?";
        $values[] = $value;
    }
    $values[] = $user_id;

    $sql = "UPDATE `{$config['table']}` SET " . implode(', ', $sets) .
           " WHERE {$config['id_field']} = ?";

    $stmt = $conn->prepare($sql);
    $types = str_repeat('s', count($values));
    $stmt->bind_param($types, ...$values);

    return $stmt->execute();
}

/**
 * Get all users by role
 */
function getAllUsersByRole($role) {
    $conn = getDBConnection();

    $roleTableMap = [
        'admin' => 'admins',
        'pmo' => 'users',
        'dean' => 'users',
        'finance' => 'users',
        'technician' => 'maintenance_technicians',
        'faculty' => 'faculty_members',
        'student' => 'students'
    ];

    if (!isset($roleTableMap[$role])) {
        return [];
    }

    $table = $roleTableMap[$role];
    $sql = in_array($role, ['pmo', 'dean', 'finance'], true)
        ? "SELECT * FROM `users` WHERE role = ? ORDER BY fullname"
        : "SELECT * FROM `$table` ORDER BY fullname";

    if (in_array($role, ['pmo', 'dean', 'finance'], true)) {
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("s", $role);
        $stmt->execute();
        $result = $stmt->get_result();
    } else {
        $result = $conn->query($sql);
    }

    if ($result) {
        $rows = $result->fetch_all(MYSQLI_ASSOC);
        if (isset($stmt)) {
            $stmt->close();
        }
        return $rows;
    }

    return [];
}

/**
 * Check if username exists
 */
function usernameExists($username, $excludeUserId = null) {
    $conn = getDBConnection();

    $tables = ['admins', 'maintenance_technicians',
               'faculty_members', 'students'];

    foreach ($tables as $table) {
        $sql = "SELECT COUNT(*) as count FROM `$table` WHERE username = ?";

        if ($excludeUserId) {
            $sql .= " AND (admin_id != ? OR technician_id != ? OR faculty_id != ? OR student_id != ?)";
        }

        $stmt = $conn->prepare($sql);

        if ($excludeUserId) {
            $stmt->bind_param("ssssss", $username, $excludeUserId, $excludeUserId,
                            $excludeUserId, $excludeUserId, $excludeUserId);
        } else {
            $stmt->bind_param("s", $username);
        }

        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();

        if ($result['count'] > 0) {
            return true;
        }
    }

    return false;
}

/**
 * Check if email exists
 */
function emailExists($email, $excludeUserId = null) {
    $conn = getDBConnection();

    $tables = ['admins', 'maintenance_technicians',
               'faculty_members', 'students'];

    foreach ($tables as $table) {
        $sql = "SELECT COUNT(*) as count FROM `$table` WHERE email = ?";

        $stmt = $conn->prepare($sql);
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();

        if ($result['count'] > 0) {
            return true;
        }
    }

    return false;
}

/**
 * Log user activity (optional security feature)
 */
function logActivity($user_id, $user_role, $action_type, $description = '') {
    $conn = getDBConnection();
    // ip_address is an inet column — use a valid IP or NULL (never 'unknown', which CLI/cron would produce).
    $ip_address = $_SERVER['REMOTE_ADDR'] ?? '';
    if ($ip_address === '' || filter_var($ip_address, FILTER_VALIDATE_IP) === false) {
        $ip_address = null;
    }

    $sql = "INSERT INTO activity_log (user_id, user_role, action_type, action_description, ip_address)
            VALUES (?, ?, ?, ?, ?)";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param("sssss", $user_id, $user_role, $action_type, $description, $ip_address);

    return $stmt->execute();
}

/**
 * Get employees on vacation from dbrrhh database
 */
function getEmployeesOnVacation() {
    try {
        // Connect to dbrrhh database
        $dbrrhh_conn = new mysqli("localhost", "root", "", "dbrrhh");

        if ($dbrrhh_conn->connect_error) {
            error_log("dbrrhh database connection error: " . $dbrrhh_conn->connect_error);
            return [];
        }

        $dbrrhh_conn->set_charset("utf8mb4");

        // Query for current month approved leave requests
        $sql = "SELECT
            e.first_name,
            e.last_name,
            p.start_time,
            p.end_time,
            p.employee_id,
            p.start_date,
            p.end_date,
            p.total_days,
            p.total_hours
        FROM
            employees AS e,
            leave_approvals AS a,
            leave_requests AS p
        WHERE
            e.id = p.employee_id
            AND a.leave_request_id = p.id
            AND a.`status` = 'approved'
            AND year(p.start_date)=year(now()) and month(p.start_date)=month(now())
        ORDER BY p.start_date ASC";

        $result = $dbrrhh_conn->query($sql);
        $vacations = [];

        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $vacations[] = [
                    'first_name' => $row['first_name'],
                    'last_name' => $row['last_name'],
                    'start_date' => $row['start_date'],
                    'end_date' => $row['end_date'],
                    'start_time' => $row['start_time'],
                    'end_time' => $row['end_time'],
                    'total_days' => $row['total_days'],
                    'total_hours' => $row['total_hours']
                ];
            }
        }

        $dbrrhh_conn->close();
        return $vacations;

    } catch (Exception $e) {
        error_log("Error fetching vacation data: " . $e->getMessage());
        return [];
    }
}

/**
 * Create user session record
 */
function createSession($user_id, $user_role) {
    $session_id = session_id();
    $ip_address = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';

    if (isPgSqlDriver()) {
        $pdo = getPgsqlPdoConnection();
        $stmt = $pdo->prepare('INSERT INTO public.user_sessions (session_id, user_id, user_role, ip_address, user_agent) VALUES (:session_id, :user_id, :user_role, :ip_address, :user_agent)');
        return $stmt->execute([
            'session_id' => $session_id,
            'user_id' => $user_id,
            'user_role' => $user_role,
            'ip_address' => $ip_address,
            'user_agent' => $user_agent,
        ]);
    }

    $conn = getDBConnection();
    $sql = "INSERT INTO user_sessions (session_id, user_id, user_role, ip_address, user_agent)
            VALUES (?, ?, ?, ?, ?)";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param("sssss", $session_id, $user_id, $user_role, $ip_address, $user_agent);

    return $stmt->execute();
}

/**
 * Close user session
 */
function closeSession($session_id) {
    if (isPgSqlDriver()) {
        $pdo = getPgsqlPdoConnection();
        $stmt = $pdo->prepare('UPDATE public.user_sessions SET logout_time = now() WHERE session_id = :session_id');
        return $stmt->execute(['session_id' => $session_id]);
    }

    $conn = getDBConnection();
    $sql = "UPDATE user_sessions SET logout_time = NOW() WHERE session_id = ?";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param("s", $session_id);

    return $stmt->execute();
}

// ============================================
// SYSTEM STATISTICS FUNCTIONS
// ============================================







/**
 * Get total user count across all roles
 */
function getTotalUserCount() {
    // Registered accounts live in the `users` table (admins, PMO, technicians).
    // The old per-role tables (admins/maintenance_technicians/…) are unused/empty.
    try {
        if (isPgSqlDriver()) {
            $pdo = getPgsqlPdoConnection();
            $n = (int) $pdo->query("SELECT COUNT(*) FROM public.users WHERE COALESCE(status,'active') <> 'deleted'")->fetchColumn();
            // Imported BEC directory people (students/faculty/staff) are surfaced as users
            // in User Management, so count them here too.
            try { $n += (int) $pdo->query("SELECT COUNT(*) FROM public.bec_directory")->fetchColumn(); } catch (\Throwable $e) {}
            return $n;
        }
        $conn = getDBConnection();
        $res = $conn->query("SELECT COUNT(*) AS c FROM users WHERE COALESCE(status,'active') <> 'deleted'");
        return $res ? (int) $res->fetch_assoc()['c'] : 0;
    } catch (\Throwable $e) {
        error_log('getTotalUserCount failed: ' . $e->getMessage());
        return 0;
    }
}





// ============================================
// INITIALIZATION
// ============================================

/**
 * Initialize system with sample data if needed
 */
function initializeSystem() {
    global $fileStorage;

    // Check if equipment data exists
    $equipment = getAllEquipment();

    if (empty($equipment)) {
        // Generate sample data
        generateSampleData();
        return true;
    }

    return false;
}

// Auto-initialize on first run
// initializeSystem();


