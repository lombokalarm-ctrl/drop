<?php
declare(strict_types=1);

$storagePath = __DIR__ . '/storage';
$sessionPath = $storagePath . '/sessions';
$databasePath = $storagePath . '/database.sqlite';
$dsn = 'sqlite:' . $databasePath;

if (!is_dir($storagePath)) {
    mkdir($storagePath, 0777, true);
}

if (!is_dir($sessionPath)) {
    mkdir($sessionPath, 0777, true);
}

session_save_path($sessionPath);
session_start();

$pdo = new PDO($dsn);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

$pdo->exec(
    'CREATE TABLE IF NOT EXISTS users (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        full_name TEXT NOT NULL,
        username TEXT NOT NULL UNIQUE,
        password_hash TEXT NOT NULL,
        role TEXT NOT NULL CHECK(role IN ("owner", "admin", "sales")),
        is_active INTEGER NOT NULL DEFAULT 1,
        created_at TEXT NOT NULL,
        updated_at TEXT NOT NULL
    )'
);

$pdo->exec(
    'CREATE TABLE IF NOT EXISTS nota_dropping (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        outlet_code TEXT NOT NULL,
        outlet_name TEXT NOT NULL,
        invoice_date TEXT NOT NULL,
        invoice_value INTEGER NOT NULL,
        payment_amount INTEGER NOT NULL DEFAULT 0,
        sales_name TEXT NOT NULL,
        payment_status TEXT NOT NULL CHECK(payment_status IN ("belum_bayar", "sudah_bayar")),
        sender_name TEXT NOT NULL,
        created_at TEXT NOT NULL,
        updated_at TEXT NOT NULL
    )'
);

$noteColumns = $pdo->query('PRAGMA table_info(nota_dropping)')->fetchAll();
$noteColumnNames = array_column($noteColumns, 'name');

if (!in_array('payment_amount', $noteColumnNames, true)) {
    $pdo->exec('ALTER TABLE nota_dropping ADD COLUMN payment_amount INTEGER NOT NULL DEFAULT 0');
    $pdo->exec(
        'UPDATE nota_dropping
        SET payment_amount = CASE
            WHEN payment_status = "sudah_bayar" THEN invoice_value
            ELSE 0
        END'
    );
}

if (!in_array('archived_at', $noteColumnNames, true)) {
    $pdo->exec('ALTER TABLE nota_dropping ADD COLUMN archived_at TEXT DEFAULT NULL');
}

if (!in_array('created_by_user_id', $noteColumnNames, true)) {
    $pdo->exec('ALTER TABLE nota_dropping ADD COLUMN created_by_user_id INTEGER DEFAULT NULL');
}

if (!in_array('updated_by_user_id', $noteColumnNames, true)) {
    $pdo->exec('ALTER TABLE nota_dropping ADD COLUMN updated_by_user_id INTEGER DEFAULT NULL');
}

$pdo->exec(
    'UPDATE nota_dropping
    SET payment_status = CASE
        WHEN payment_amount >= invoice_value THEN "sudah_bayar"
        ELSE "belum_bayar"
    END
    WHERE payment_status != CASE
        WHEN payment_amount >= invoice_value THEN "sudah_bayar"
        ELSE "belum_bayar"
    END'
);

$userCount = (int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
if ($userCount === 0) {
    $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');
    $statement = $pdo->prepare(
        'INSERT INTO users (full_name, username, password_hash, role, is_active, created_at, updated_at)
        VALUES (:full_name, :username, :password_hash, :role, 1, :created_at, :updated_at)'
    );
    $statement->execute([
        'full_name' => 'Owner',
        'username' => 'owner',
        'password_hash' => password_hash('owner123', PASSWORD_DEFAULT),
        'role' => 'owner',
        'created_at' => $now,
        'updated_at' => $now,
    ]);
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

    $dateTime = DateTime::createFromFormat('Y-m-d', $date);

    return $dateTime ? $dateTime->format('d-m-Y') : $date;
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
        'admin' => 'Admin',
        'sales' => 'Sales',
        default => ucfirst($role),
    };
}

function canManageUsers(array $user): bool
{
    return $user['role'] === 'owner';
}

function canViewArchive(array $user): bool
{
    return in_array($user['role'], ['owner', 'admin'], true);
}

function canDeletePermanent(array $user): bool
{
    return $user['role'] === 'owner';
}

function canArchiveNote(array $user, array $note): bool
{
    if (in_array($user['role'], ['owner', 'admin'], true)) {
        return true;
    }

    return $user['role'] === 'sales' && (int)($note['created_by_user_id'] ?? 0) === (int)$user['id'];
}

function canEditNote(array $user, array $note): bool
{
    if (in_array($user['role'], ['owner', 'admin'], true)) {
        return true;
    }

    return $user['role'] === 'sales' && (int)($note['created_by_user_id'] ?? 0) === (int)$user['id'];
}

function canPayNote(array $user, array $note): bool
{
    if (in_array($user['role'], ['owner', 'admin'], true)) {
        return true;
    }

    return $user['role'] === 'sales' && (int)($note['created_by_user_id'] ?? 0) === (int)$user['id'];
}

function canCreateNote(array $user): bool
{
    return in_array($user['role'], ['owner', 'admin', 'sales'], true);
}

function getUserScopeWhere(array $user, string $tableAlias = ''): array
{
    $prefix = $tableAlias !== '' ? $tableAlias . '.' : '';
    if ($user['role'] === 'sales') {
        return [$prefix . 'created_by_user_id = :current_user_id', ['current_user_id' => (int)$user['id']]];
    }

    return ['', []];
}

function countActiveOwners(PDO $pdo, ?int $excludeUserId = null): int
{
    $sql = 'SELECT COUNT(*) FROM users WHERE role = "owner" AND is_active = 1';
    $params = [];
    if ($excludeUserId !== null) {
        $sql .= ' AND id != :exclude_user_id';
        $params['exclude_user_id'] = $excludeUserId;
    }

    $statement = $pdo->prepare($sql);
    $statement->execute($params);

    return (int)$statement->fetchColumn();
}
