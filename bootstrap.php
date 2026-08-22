<?php
declare(strict_types=1);

$storagePath = __DIR__ . '/storage';
$sessionPath = $storagePath . '/sessions';

if (!is_dir($storagePath)) {
    mkdir($storagePath, 0777, true);
}

if (!is_dir($sessionPath)) {
    mkdir($sessionPath, 0777, true);
}

loadEnvFile(__DIR__ . '/.env');

$appConfig = getAppConfig(__DIR__, $storagePath, $sessionPath);

if (PHP_SAPI !== 'cli') {
    session_save_path($appConfig['session_path']);
    session_start();
}

$pdo = createDatabaseConnection($appConfig);
initializeDatabase($pdo);

function loadEnvFile(string $path): void
{
    if (!is_file($path)) {
        return;
    }

    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) {
        return;
    }

    foreach ($lines as $line) {
        $trimmed = trim($line);
        if ($trimmed === '' || str_starts_with($trimmed, '#') || !str_contains($trimmed, '=')) {
            continue;
        }

        [$key, $value] = explode('=', $trimmed, 2);
        $key = trim($key);
        $value = trim($value);

        if ($key === '') {
            continue;
        }

        if (
            (str_starts_with($value, '"') && str_ends_with($value, '"'))
            || (str_starts_with($value, "'") && str_ends_with($value, "'"))
        ) {
            $value = substr($value, 1, -1);
        }

        putenv($key . '=' . $value);
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
    }
}

function envValue(string $key, ?string $default = null): ?string
{
    $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);

    if ($value === false || $value === null || $value === '') {
        return $default;
    }

    return (string)$value;
}

function resolveProjectPath(string $projectRoot, string $path): string
{
    if ($path === '') {
        return $projectRoot;
    }

    if (preg_match('/^[A-Za-z]:[\\\\\\/]/', $path) === 1 || str_starts_with($path, '/') || str_starts_with($path, '\\\\')) {
        return $path;
    }

    return rtrim($projectRoot, '/\\') . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);
}

function getAppConfig(string $projectRoot, string $storagePath, string $sessionPath): array
{
    $connection = strtolower(envValue('DB_CONNECTION', 'sqlite') ?? 'sqlite');

    $config = [
        'project_root' => $projectRoot,
        'storage_path' => $storagePath,
        'session_path' => $sessionPath,
        'db_connection' => $connection,
        'sqlite_source_path' => resolveProjectPath($projectRoot, envValue('SQLITE_SOURCE_PATH', 'storage/database.sqlite') ?? 'storage/database.sqlite'),
    ];

    if ($connection === 'mysql' || $connection === 'mariadb') {
        $config['db_connection'] = 'mysql';
        $config['db_host'] = envValue('DB_HOST', '127.0.0.1') ?? '127.0.0.1';
        $config['db_port'] = envValue('DB_PORT', '3306') ?? '3306';
        $config['db_name'] = envValue('DB_DATABASE', '') ?? '';
        $config['db_user'] = envValue('DB_USERNAME', '') ?? '';
        $config['db_password'] = envValue('DB_PASSWORD', '') ?? '';
        $config['db_charset'] = envValue('DB_CHARSET', 'utf8mb4') ?? 'utf8mb4';

        return $config;
    }

    $config['db_connection'] = 'sqlite';
    $config['db_path'] = resolveProjectPath($projectRoot, envValue('DB_DATABASE', 'storage/database.sqlite') ?? 'storage/database.sqlite');

    return $config;
}

function createDatabaseConnection(array $config): PDO
{
    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ];

    if ($config['db_connection'] === 'mysql') {
        if (($config['db_name'] ?? '') === '' || ($config['db_user'] ?? '') === '') {
            throw new RuntimeException('Konfigurasi MySQL belum lengkap. Isi DB_DATABASE, DB_USERNAME, dan DB_PASSWORD di file .env.');
        }

        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=%s',
            $config['db_host'],
            $config['db_port'],
            $config['db_name'],
            $config['db_charset']
        );

        return new PDO($dsn, $config['db_user'], $config['db_password'], $options);
    }

    $databaseDirectory = dirname($config['db_path']);
    if (!is_dir($databaseDirectory)) {
        mkdir($databaseDirectory, 0777, true);
    }

    $pdo = new PDO('sqlite:' . $config['db_path'], null, null, $options);
    $pdo->exec('PRAGMA foreign_keys = ON');

    return $pdo;
}

function initializeDatabase(PDO $pdo): void
{
    $driver = (string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

    createUsersTable($pdo, $driver);
    synchronizeUsersTable($pdo, $driver);
    createNotaDroppingTable($pdo, $driver);
    synchronizeLegacyColumns($pdo, $driver);
    normalizeUserRoles($pdo);
    normalizePaymentStatus($pdo);
    seedDefaultUsers($pdo);
}

function createUsersTable(PDO $pdo, string $driver): void
{
    if ($driver === 'mysql') {
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS users (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                full_name VARCHAR(150) NOT NULL,
                username VARCHAR(50) NOT NULL,
                password_hash VARCHAR(255) NOT NULL,
                role VARCHAR(20) NOT NULL,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                UNIQUE KEY uq_users_username (username),
                KEY idx_users_role (role)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        return;
    }

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            full_name TEXT NOT NULL,
            username TEXT NOT NULL UNIQUE,
            password_hash TEXT NOT NULL,
            role TEXT NOT NULL,
            is_active INTEGER NOT NULL DEFAULT 1,
            created_at TEXT NOT NULL,
            updated_at TEXT NOT NULL
        )"
    );
}

function synchronizeUsersTable(PDO $pdo, string $driver): void
{
    if ($driver !== 'sqlite') {
        return;
    }

    $schemaSql = (string)$pdo->query("SELECT sql FROM sqlite_master WHERE type = 'table' AND name = 'users'")->fetchColumn();
    if ($schemaSql === '' || !str_contains(strtolower($schemaSql), 'check(role in')) {
        return;
    }

    $pdo->beginTransaction();

    try {
        $pdo->exec('ALTER TABLE users RENAME TO users_legacy');
        $pdo->exec(
            "CREATE TABLE users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                full_name TEXT NOT NULL,
                username TEXT NOT NULL UNIQUE,
                password_hash TEXT NOT NULL,
                role TEXT NOT NULL,
                is_active INTEGER NOT NULL DEFAULT 1,
                created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL
            )"
        );
        $pdo->exec(
            "INSERT INTO users (id, full_name, username, password_hash, role, is_active, created_at, updated_at)
            SELECT id, full_name, username, password_hash, role, is_active, created_at, updated_at
            FROM users_legacy"
        );
        $pdo->exec('DROP TABLE users_legacy');
        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        throw $exception;
    }
}

function createNotaDroppingTable(PDO $pdo, string $driver): void
{
    if ($driver === 'mysql') {
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS nota_dropping (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                outlet_code VARCHAR(50) NOT NULL,
                outlet_name VARCHAR(180) NOT NULL,
                invoice_date VARCHAR(100) NOT NULL,
                invoice_value BIGINT UNSIGNED NOT NULL,
                payment_amount BIGINT UNSIGNED NOT NULL DEFAULT 0,
                sales_name VARCHAR(150) NOT NULL,
                payment_status VARCHAR(20) NOT NULL,
                sender_name VARCHAR(150) NOT NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                archived_at DATETIME NULL DEFAULT NULL,
                created_by_user_id INT UNSIGNED NULL DEFAULT NULL,
                updated_by_user_id INT UNSIGNED NULL DEFAULT NULL,
                KEY idx_nota_invoice_date (invoice_date),
                KEY idx_nota_archived_at (archived_at),
                KEY idx_nota_created_by (created_by_user_id),
                KEY idx_nota_updated_by (updated_by_user_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        return;
    }

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS nota_dropping (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            outlet_code TEXT NOT NULL,
            outlet_name TEXT NOT NULL,
            invoice_date TEXT NOT NULL,
            invoice_value INTEGER NOT NULL,
            payment_amount INTEGER NOT NULL DEFAULT 0,
            sales_name TEXT NOT NULL,
            payment_status TEXT NOT NULL,
            sender_name TEXT NOT NULL,
            created_at TEXT NOT NULL,
            updated_at TEXT NOT NULL,
            archived_at TEXT DEFAULT NULL,
            created_by_user_id INTEGER DEFAULT NULL,
            updated_by_user_id INTEGER DEFAULT NULL
        )"
    );
}

function synchronizeLegacyColumns(PDO $pdo, string $driver): void
{
    $noteColumns = getTableColumns($pdo, 'nota_dropping');

    if (!in_array('payment_amount', $noteColumns, true)) {
        $pdo->exec($driver === 'mysql'
            ? 'ALTER TABLE nota_dropping ADD COLUMN payment_amount BIGINT UNSIGNED NOT NULL DEFAULT 0'
            : 'ALTER TABLE nota_dropping ADD COLUMN payment_amount INTEGER NOT NULL DEFAULT 0');
        $pdo->exec(
            "UPDATE nota_dropping
            SET payment_amount = CASE
                WHEN payment_status = 'sudah_bayar' THEN invoice_value
                ELSE 0
            END"
        );
    }

    if (!in_array('archived_at', $noteColumns, true)) {
        $pdo->exec($driver === 'mysql'
            ? 'ALTER TABLE nota_dropping ADD COLUMN archived_at DATETIME NULL DEFAULT NULL'
            : 'ALTER TABLE nota_dropping ADD COLUMN archived_at TEXT DEFAULT NULL');
    }

    if (!in_array('created_by_user_id', $noteColumns, true)) {
        $pdo->exec($driver === 'mysql'
            ? 'ALTER TABLE nota_dropping ADD COLUMN created_by_user_id INT UNSIGNED NULL DEFAULT NULL'
            : 'ALTER TABLE nota_dropping ADD COLUMN created_by_user_id INTEGER DEFAULT NULL');
    }

    if (!in_array('updated_by_user_id', $noteColumns, true)) {
        $pdo->exec($driver === 'mysql'
            ? 'ALTER TABLE nota_dropping ADD COLUMN updated_by_user_id INT UNSIGNED NULL DEFAULT NULL'
            : 'ALTER TABLE nota_dropping ADD COLUMN updated_by_user_id INTEGER DEFAULT NULL');
    }

    if ($driver === 'mysql') {
        $invoiceDateColumn = $pdo->query("SHOW COLUMNS FROM `nota_dropping` LIKE 'invoice_date'")->fetch();
        $invoiceDateType = strtolower((string)($invoiceDateColumn['Type'] ?? ''));

        if ($invoiceDateType !== '' && !str_contains($invoiceDateType, 'char') && !str_contains($invoiceDateType, 'text')) {
            $pdo->exec('ALTER TABLE nota_dropping MODIFY COLUMN invoice_date VARCHAR(100) NOT NULL');
        }
    }
}

function getTableColumns(PDO $pdo, string $tableName): array
{
    $driver = (string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

    if ($driver === 'mysql') {
        $statement = $pdo->query('SHOW COLUMNS FROM `' . $tableName . '`');
        $columns = $statement->fetchAll();

        return array_column($columns, 'Field');
    }

    $statement = $pdo->query('PRAGMA table_info(' . $tableName . ')');
    $columns = $statement->fetchAll();

    return array_column($columns, 'name');
}

function normalizePaymentStatus(PDO $pdo): void
{
    $pdo->exec(
        "UPDATE nota_dropping
        SET payment_status = CASE
            WHEN payment_amount >= invoice_value THEN 'sudah_bayar'
            ELSE 'belum_bayar'
        END
        WHERE payment_status <> CASE
            WHEN payment_amount >= invoice_value THEN 'sudah_bayar'
            ELSE 'belum_bayar'
        END"
    );
}

function normalizeUserRoles(PDO $pdo): void
{
    $pdo->exec("UPDATE users SET role = 'staff' WHERE role = 'admin'");
}

function hasOwnerAccess(array $user): bool
{
    return in_array((string)($user['role'] ?? ''), ['owner', 'manager'], true);
}

function seedDefaultUsers(PDO $pdo): void
{
    $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');
    $defaultUsers = [
        [
            'full_name' => 'Staff Admin',
            'username' => 'admin',
            'role' => 'staff',
        ],
        [
            'full_name' => 'Sales',
            'username' => 'sales',
            'role' => 'sales',
        ],
        [
            'full_name' => 'Gudang',
            'username' => 'gudang',
            'role' => 'gudang',
        ],
        [
            'full_name' => 'Manager',
            'username' => 'manager',
            'role' => 'manager',
        ],
    ];

    $findStatement = $pdo->prepare('SELECT id FROM users WHERE username = :username LIMIT 1');
    $insertStatement = $pdo->prepare(
        'INSERT INTO users (full_name, username, password_hash, role, is_active, created_at, updated_at)
        VALUES (:full_name, :username, :password_hash, :role, 1, :created_at, :updated_at)'
    );

    foreach ($defaultUsers as $user) {
        $findStatement->execute(['username' => $user['username']]);
        if ($findStatement->fetch()) {
            continue;
        }

        $insertStatement->execute([
            'full_name' => $user['full_name'],
            'username' => $user['username'],
            'password_hash' => password_hash('madani123', PASSWORD_DEFAULT),
            'role' => $user['role'],
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }
}

function redirectTo(string $path, array $params = []): never
{
    $query = $params ? '?' . http_build_query($params) : '';
    header('Location: ' . $path . $query);
    exit;
}

function redirectToIndex(array $params = []): never
{
    redirectTo('index.php', $params);
}

function redirectToLogin(array $params = []): never
{
    redirectTo('login.php', $params);
}

function setFlash(string $type, string $message): void
{
    $_SESSION['flash'] = [
        'type' => $type,
        'message' => $message,
    ];
}

function getFlash(): ?array
{
    if (!isset($_SESSION['flash'])) {
        return null;
    }

    $flash = $_SESSION['flash'];
    unset($_SESSION['flash']);

    return $flash;
}

function formatDateId(?string $date): string
{
    if (!$date) {
        return '-';
    }

    $normalized = trim($date);
    $dateOnly = explode(' ', $normalized)[0];
    $dateTime = DateTime::createFromFormat('Y-m-d', $dateOnly);

    return $dateTime ? $dateTime->format('d-m-Y') : $date;
}

function parseDateIdInput(?string $date): ?string
{
    $value = trim((string)$date);
    if ($value === '') {
        return null;
    }

    $dateTime = DateTimeImmutable::createFromFormat('d-m-Y', $value);
    $errors = DateTimeImmutable::getLastErrors();

    if (
        $dateTime === false
        || ($errors !== false && (($errors['warning_count'] ?? 0) > 0 || ($errors['error_count'] ?? 0) > 0))
    ) {
        return null;
    }

    return $dateTime->format('Y-m-d');
}

function formatDateTimeId(?string $dateTime): string
{
    if (!$dateTime) {
        return '-';
    }

    $parsed = DateTime::createFromFormat('Y-m-d H:i:s', $dateTime);

    return $parsed ? $parsed->format('d-m-Y H:i') : $dateTime;
}

function formatCurrency(int $amount): string
{
    return 'Rp ' . number_format($amount, 0, ',', '.');
}

function formatNumberId(int $amount): string
{
    return number_format($amount, 0, ',', '.');
}

function calculatePaymentStatus(int $invoiceValue, int $paymentAmount): string
{
    return $paymentAmount >= $invoiceValue ? 'sudah_bayar' : 'belum_bayar';
}

function getRemainingAmount(int $invoiceValue, int $paymentAmount): int
{
    return max(0, $invoiceValue - $paymentAmount);
}

function findNota(PDO $pdo, int $id): ?array
{
    $statement = $pdo->prepare('SELECT * FROM nota_dropping WHERE id = :id');
    $statement->execute(['id' => $id]);
    $row = $statement->fetch();

    return $row ?: null;
}

function findUser(PDO $pdo, int $id): ?array
{
    $statement = $pdo->prepare('SELECT * FROM users WHERE id = :id');
    $statement->execute(['id' => $id]);
    $row = $statement->fetch();

    return $row ?: null;
}

function getCurrentUser(PDO $pdo): ?array
{
    static $currentUser = false;

    if ($currentUser !== false) {
        return $currentUser;
    }

    $userId = (int)($_SESSION['user_id'] ?? 0);
    if ($userId <= 0) {
        $currentUser = null;
        return null;
    }

    $statement = $pdo->prepare('SELECT * FROM users WHERE id = :id AND is_active = 1');
    $statement->execute(['id' => $userId]);
    $user = $statement->fetch() ?: null;

    if ($user === null) {
        unset($_SESSION['user_id']);
    }

    $currentUser = $user;
    return $user;
}

function requireLogin(PDO $pdo): array
{
    $user = getCurrentUser($pdo);
    if ($user === null) {
        setFlash('error', 'Silakan login terlebih dahulu.');
        redirectToLogin();
    }

    return $user;
}

function roleLabel(string $role): string
{
    return match ($role) {
        'owner' => 'Owner',
        'manager' => 'Manager',
        'admin', 'staff' => 'Staff',
        'sales' => 'Sales',
        'gudang' => 'Gudang',
        default => ucfirst($role),
    };
}

function canManageUsers(array $user): bool
{
    return hasOwnerAccess($user);
}

function canViewArchive(array $user): bool
{
    return in_array($user['role'], ['owner', 'manager', 'staff'], true);
}

function canDeletePermanent(array $user): bool
{
    return hasOwnerAccess($user);
}

function canArchiveNote(array $user, array $note): bool
{
    if (in_array($user['role'], ['owner', 'manager', 'staff'], true)) {
        return true;
    }

    return false;
}

function canEditNote(array $user, array $note): bool
{
    return hasOwnerAccess($user);
}

function canPayNote(array $user, array $note): bool
{
    if (in_array($user['role'], ['owner', 'manager', 'staff'], true)) {
        return true;
    }

    return false;
}

function canManageSender(array $user, array $note): bool
{
    if (in_array($user['role'], ['owner', 'manager', 'gudang'], true)) {
        return true;
    }

    return false;
}

function canCreateNote(array $user): bool
{
    return in_array($user['role'], ['owner', 'manager', 'sales'], true);
}

function getUserScopeWhere(array $user, string $tableAlias = ''): array
{
    $prefix = $tableAlias !== '' ? $tableAlias . '.' : '';
    if ($user['role'] === 'sales') {
        return [$prefix . 'created_by_user_id = :current_user_id', ['current_user_id' => (int)$user['id']]];
    }

    return ['', []];
}

function countActivePrivilegedUsers(PDO $pdo, ?int $excludeUserId = null): int
{
    $sql = "SELECT COUNT(*) FROM users WHERE role IN ('owner', 'manager') AND is_active = 1";
    $params = [];
    if ($excludeUserId !== null) {
        $sql .= ' AND id != :exclude_user_id';
        $params['exclude_user_id'] = $excludeUserId;
    }

    $statement = $pdo->prepare($sql);
    $statement->execute($params);

    return (int)$statement->fetchColumn();
}
