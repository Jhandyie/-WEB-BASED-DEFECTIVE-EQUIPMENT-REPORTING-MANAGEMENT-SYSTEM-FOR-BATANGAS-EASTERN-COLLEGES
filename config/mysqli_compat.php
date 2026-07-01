<?php
/**
 * mysqli_compat.php
 * ------------------------------------------------------------------
 * A thin compatibility layer that lets the existing mysqli-style
 * application code run on PostgreSQL / Supabase via PDO, with minimal
 * changes to the ~547 call sites across the codebase.
 *
 * It exposes objects that mimic the small slice of the mysqli API the
 * app actually uses:
 *   connection: query(), prepare(), real_escape_string(),
 *               begin_transaction(), commit(), rollback(),
 *               set_charset(), close(), ->affected_rows, ->insert_id,
 *               ->error, ->errno, ->connect_error
 *   statement:  bind_param(), execute(), get_result(), bind_result(),
 *               fetch(), store_result(), close(),
 *               ->affected_rows, ->insert_id, ->num_rows, ->error
 *   result:     fetch_assoc(), fetch_array(), fetch_row(),
 *               fetch_object(), fetch_all(), data_seek(), free(),
 *               ->num_rows
 *
 * The PDO connection is created with EMULATE_PREPARES = true on purpose:
 * values are substituted as quoted literals client-side, so PostgreSQL
 * coerces "unknown" literals to the target column type. This makes
 * MySQL-isms such as `WHERE int_col = ?`, `WHERE bool_col = '0'`, and
 * mixed string/int binding work without per-call-site type juggling.
 * PDO still quotes correctly, so this remains safe against injection.
 *
 * SQL dialect differences that a literal substitution cannot fix
 * (e.g. SHOW TABLES/COLUMNS, backtick identifiers, MySQL LIMIT
 * offset syntax, IFNULL, CURDATE) are handled by pgTranslateSql().
 * More complex MySQL-only SQL (DATE_FORMAT, GROUP_CONCAT,
 * ON DUPLICATE KEY, etc.) still needs per-query review.
 * ------------------------------------------------------------------
 */

if (!defined('MYSQLI_ASSOC')) { define('MYSQLI_ASSOC', 1); }
if (!defined('MYSQLI_NUM')) { define('MYSQLI_NUM', 2); }
if (!defined('MYSQLI_BOTH')) { define('MYSQLI_BOTH', 3); }

if (!function_exists('pgSafeLastInsertId')) {
    /**
     * Get lastInsertId only for INSERTs, guarded so it can NEVER abort a
     * transaction. On Postgres, lastInsertId() runs lastval(), which throws
     * "lastval is not yet defined" when no sequence was touched — and inside a
     * transaction that error aborts the whole transaction. A SAVEPOINT isolates it.
     */
    function pgSafeLastInsertId(PDO $pdo, string $sql) {
        if (stripos(ltrim($sql), 'INSERT') !== 0) {
            return 0;
        }
        try {
            if ($pdo->inTransaction()) {
                $pdo->exec('SAVEPOINT bec_liid');
                try {
                    $id = $pdo->lastInsertId();
                    $pdo->exec('RELEASE SAVEPOINT bec_liid');
                    return $id;
                } catch (\Throwable $e) {
                    try { $pdo->exec('ROLLBACK TO SAVEPOINT bec_liid'); } catch (\Throwable $e2) {}
                    return 0;
                }
            }
            return $pdo->lastInsertId();
        } catch (\Throwable $e) {
            return 0;
        }
    }
}

if (!function_exists('pgTranslateSql')) {
    /**
     * Best-effort translation of common MySQL SQL into PostgreSQL.
     * Handles the patterns that appear throughout this codebase.
     */
    function pgTranslateSql(string $sql): string {
        // Remove MySQL backtick identifier quoting (identifiers are lower-case here).
        $sql = str_replace('`', '', $sql);

        // IFNULL(a, b) -> COALESCE(a, b)
        $sql = preg_replace('/\bIFNULL\s*\(/i', 'COALESCE(', $sql);

        // CURDATE() / CURTIME() -> CURRENT_DATE / CURRENT_TIME
        $sql = preg_replace('/\bCURDATE\s*\(\s*\)/i', 'CURRENT_DATE', $sql);
        $sql = preg_replace('/\bCURTIME\s*\(\s*\)/i', 'CURRENT_TIME', $sql);

        // MySQL interval literal: INTERVAL 7 DAY -> INTERVAL '7 day'
        $sql = preg_replace_callback(
            '/\bINTERVAL\s+(\d+)\s+(MICROSECOND|SECOND|MINUTE|HOUR|DAY|WEEK|MONTH|YEAR)S?\b/i',
            static fn($m) => "INTERVAL '" . $m[1] . ' ' . strtolower($m[2]) . "'",
            $sql
        );
        // DATE_SUB / DATE_ADD(expr, INTERVAL '...') -> (expr -/+ INTERVAL '...')
        $sql = preg_replace('/\bDATE_SUB\s*\(\s*([^,]+?)\s*,\s*(INTERVAL\s+\'[^\']+\')\s*\)/i', '($1 - $2)', $sql);
        $sql = preg_replace('/\bDATE_ADD\s*\(\s*([^,]+?)\s*,\s*(INTERVAL\s+\'[^\']+\')\s*\)/i', '($1 + $2)', $sql);
        // TIMESTAMPDIFF(SECOND, a, b) -> EXTRACT(EPOCH FROM (b - a))::bigint
        $sql = preg_replace(
            '/\bTIMESTAMPDIFF\s*\(\s*SECOND\s*,\s*([^,]+?)\s*,\s*(NOW\s*\(\s*\)|CURRENT_TIMESTAMP|[^,()]+)\s*\)/i',
            'EXTRACT(EPOCH FROM ($2 - $1))::bigint',
            $sql
        );
        // DATE(expr) -> CAST(expr AS DATE)  (DATE_SUB/DATE_ADD/DATEDIFF already handled above)
        $sql = preg_replace('/\bDATE\s*\(\s*([^()]+?)\s*\)/i', 'CAST($1 AS DATE)', $sql);

        // MySQL cast targets -> Postgres: CHAR -> TEXT, UNSIGNED/SIGNED -> INTEGER
        $sql = preg_replace('/\bAS\s+CHAR\s*(?:\(\s*\d+\s*\))?/i', 'AS TEXT', $sql);
        $sql = preg_replace('/\bAS\s+(?:UNSIGNED|SIGNED)(?:\s+INTEGER)?/i', 'AS INTEGER', $sql);

        // MySQL `LIMIT offset, count` -> `LIMIT count OFFSET offset`
        $sql = preg_replace_callback(
            '/\bLIMIT\s+(\d+)\s*,\s*(\d+)/i',
            static fn($m) => 'LIMIT ' . $m[2] . ' OFFSET ' . $m[1],
            $sql
        );

        // Known boolean columns compared with integer literals in raw SQL.
        $sql = preg_replace('/\b(is_read|is_used)\s*=\s*1\b/i', '$1 = true', $sql);
        $sql = preg_replace('/\b(is_read|is_used)\s*=\s*0\b/i', '$1 = false', $sql);

        return $sql;
    }
}

/**
 * Statement wrapper mimicking mysqli_stmt.
 */
class PgMysqliStmt {
    /** @var PDO */
    private $pdo;
    /** @var PDOStatement */
    private $stmt;
    private string $types = '';
    /** @var array references to bound input variables */
    private array $boundParams = [];
    /** @var array references to bound output variables (bind_result) */
    private array $boundResults = [];
    /** @var array|null buffered rows after a SELECT execute */
    private ?array $buffer = null;
    private int $cursor = 0;

    public int $affected_rows = 0;
    public $insert_id = 0;
    public int $num_rows = 0;
    public string $error = '';
    public int $errno = 0;

    public function __construct(PDO $pdo, PDOStatement $stmt) {
        $this->pdo = $pdo;
        $this->stmt = $stmt;
    }

    /** mysqli bind_param: variables are bound by reference. */
    public function bind_param(string $types, &...$vars): bool {
        $this->types = $types;
        $this->boundParams = [];
        foreach ($vars as $k => $v) {
            $this->boundParams[$k] = &$vars[$k];
        }
        return true;
    }

    public function bind_result(&...$vars): bool {
        $this->boundResults = [];
        foreach ($vars as $k => $v) {
            $this->boundResults[$k] = &$vars[$k];
        }
        return true;
    }

    /**
     * Execute. Accepts an optional positional array (PHP 8.1 mysqli style);
     * otherwise uses variables captured via bind_param().
     */
    public function execute($params = null): bool {
        $values = is_array($params) ? array_values($params) : array_values($this->boundParams);
        try {
            $this->stmt->execute($values);
            $this->affected_rows = $this->stmt->rowCount();
            $this->insert_id = pgSafeLastInsertId($this->pdo, (string)$this->stmt->queryString);

            // Buffer rows if this produced a result set.
            if ($this->stmt->columnCount() > 0) {
                $this->buffer = $this->stmt->fetchAll(PDO::FETCH_ASSOC);
                $this->num_rows = count($this->buffer);
                $this->cursor = 0;
            } else {
                $this->buffer = null;
                $this->num_rows = 0;
            }
            $this->error = '';
            $this->errno = 0;
            return true;
        } catch (\PDOException $e) {
            $this->error = $e->getMessage();
            $this->errno = (int)($e->getCode());
            error_log('PgMysqliStmt execute error: ' . $e->getMessage());
            return false;
        }
    }

    /** Returns a result wrapper over the buffered rows. */
    public function get_result() {
        if ($this->buffer === null) {
            return false;
        }
        return new PgMysqliResult($this->buffer);
    }

    /** store_result: buffering already happened during execute(). */
    public function store_result(): bool {
        return true;
    }

    /** Iterate rows assigning into bind_result() targets. */
    public function fetch(): ?bool {
        if ($this->buffer === null || $this->cursor >= count($this->buffer)) {
            return null;
        }
        $row = array_values($this->buffer[$this->cursor]);
        $this->cursor++;
        foreach ($this->boundResults as $i => &$ref) {
            $ref = $row[$i] ?? null;
        }
        unset($ref);
        return true;
    }

    public function free_result(): void { $this->buffer = null; }
    public function close(): bool { $this->buffer = null; return true; }
}

/**
 * Result wrapper mimicking mysqli_result.
 */
class PgMysqliResult {
    /** @var array assoc rows */
    private array $rows;
    private int $cursor = 0;
    public int $num_rows = 0;

    public function __construct(array $rows) {
        $this->rows = $rows;
        $this->num_rows = count($rows);
    }

    public function fetch_assoc(): ?array {
        if ($this->cursor >= $this->num_rows) {
            return null;
        }
        return $this->rows[$this->cursor++];
    }

    public function fetch_row(): ?array {
        $row = $this->fetch_assoc();
        return $row === null ? null : array_values($row);
    }

    public function fetch_array(int $mode = MYSQLI_BOTH) {
        if ($this->cursor >= $this->num_rows) {
            return null;
        }
        $assoc = $this->rows[$this->cursor++];
        if ($mode === MYSQLI_ASSOC) {
            return $assoc;
        }
        if ($mode === MYSQLI_NUM) {
            return array_values($assoc);
        }
        return array_merge($assoc, array_values($assoc)); // BOTH (approx; numeric keys appended)
    }

    public function fetch_object(?string $class = null) {
        $row = $this->fetch_assoc();
        if ($row === null) {
            return null;
        }
        if ($class && class_exists($class)) {
            $obj = new $class();
            foreach ($row as $k => $v) { $obj->$k = $v; }
            return $obj;
        }
        return (object)$row;
    }

    /** mysqli default is MYSQLI_NUM; callers in this app usually pass MYSQLI_ASSOC. */
    public function fetch_all(int $mode = MYSQLI_NUM): array {
        $remaining = array_slice($this->rows, $this->cursor);
        $this->cursor = $this->num_rows;
        if ($mode === MYSQLI_ASSOC) {
            return $remaining;
        }
        return array_map('array_values', $remaining);
    }

    public function data_seek(int $offset): bool {
        if ($offset < 0 || $offset > $this->num_rows) {
            return false;
        }
        $this->cursor = $offset;
        return true;
    }

    public function free(): void { $this->rows = []; $this->num_rows = 0; }
    public function close(): void { $this->free(); }
    public function free_result(): void { $this->free(); }
}

/**
 * Connection wrapper mimicking mysqli.
 */
class PgMysqliConnection {
    /** @var PDO */
    private $pdo;
    private int $txDepth = 0;

    public int $affected_rows = 0;
    public $insert_id = 0;
    public string $error = '';
    public int $errno = 0;
    public $connect_error = null;

    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
        // Literal substitution client-side -> Postgres coerces unknown literals.
        $this->pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, true);
    }

    public function getPdo(): PDO { return $this->pdo; }

    /** Direct query. Returns PgMysqliResult for result sets, true for writes, false on error. */
    public function query(string $sql) {
        // SHOW TABLES LIKE 'x'  ->  information_schema lookup (preserves ->num_rows)
        if (preg_match("/^\s*SHOW\s+TABLES\s+LIKE\s+'([^']+)'/i", $sql, $m)) {
            $name = strtolower(str_replace('%', '', $m[1]));
            return $this->runSelect(
                "SELECT table_name FROM information_schema.tables WHERE table_schema = 'public' AND table_name = :t",
                ['t' => $name]
            );
        }
        // SHOW COLUMNS FROM x  ->  information_schema, aliased to mysqli column names
        if (preg_match('/^\s*SHOW\s+(?:FULL\s+)?COLUMNS\s+FROM\s+`?([A-Za-z0-9_]+)`?/i', $sql, $m)) {
            $name = strtolower($m[1]);
            return $this->runSelect(
                'SELECT column_name AS "Field", data_type AS "Type", is_nullable AS "Null", '
                . "'' AS \"Key\", column_default AS \"Default\", '' AS \"Extra\" "
                . "FROM information_schema.columns WHERE table_schema = 'public' AND table_name = :t ORDER BY ordinal_position",
                ['t' => $name]
            );
        }

        $translated = pgTranslateSql($sql);
        try {
            $stmt = $this->pdo->query($translated);
            $this->error = '';
            $this->errno = 0;
            if ($stmt->columnCount() > 0) {
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
                return new PgMysqliResult($rows);
            }
            $this->affected_rows = $stmt->rowCount();
            $this->insert_id = pgSafeLastInsertId($this->pdo, $translated);
            return true;
        } catch (\PDOException $e) {
            $this->error = $e->getMessage();
            $this->errno = (int)$e->getCode();
            error_log('PgMysqliConnection query error: ' . $e->getMessage() . ' | SQL: ' . $translated);
            return false;
        }
    }

    private function runSelect(string $sql, array $params): PgMysqliResult {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return new PgMysqliResult($stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    public function prepare(string $sql) {
        $translated = pgTranslateSql($sql);
        try {
            $stmt = $this->pdo->prepare($translated);
            return new PgMysqliStmt($this->pdo, $stmt);
        } catch (\PDOException $e) {
            $this->error = $e->getMessage();
            $this->errno = (int)$e->getCode();
            error_log('PgMysqliConnection prepare error: ' . $e->getMessage() . ' | SQL: ' . $translated);
            return false;
        }
    }

    public function real_escape_string(string $value): string {
        $quoted = $this->pdo->quote($value);
        // Strip the surrounding quotes PDO adds; callers wrap with their own quotes.
        if (strlen($quoted) >= 2 && $quoted[0] === "'" ) {
            return substr($quoted, 1, -1);
        }
        return $value;
    }
    public function escape_string(string $value): string { return $this->real_escape_string($value); }

    public function begin_transaction(): bool {
        if ($this->txDepth === 0) {
            $this->pdo->beginTransaction();
        }
        $this->txDepth++;
        return true;
    }
    public function commit(): bool {
        if ($this->txDepth > 0) {
            $this->txDepth--;
            if ($this->txDepth === 0 && $this->pdo->inTransaction()) {
                $this->pdo->commit();
            }
        }
        return true;
    }
    public function rollback(): bool {
        if ($this->txDepth > 0) {
            $this->txDepth = 0;
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
        }
        return true;
    }

    public function set_charset(string $charset): bool { return true; }
    public function close(): bool { return true; }
}
