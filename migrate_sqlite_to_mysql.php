<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Script ini hanya boleh dijalankan dari CLI.\n");
    exit(1);
}

require __DIR__ . '/bootstrap.php';

$targetDriver = (string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
if ($targetDriver !== 'mysql') {
    fwrite(STDERR, "Set DB_CONNECTION=mysql di file .env sebelum menjalankan migrasi.\n");
    exit(1);
}

$sourcePath = $appConfig['sqlite_source_path'] ?? (__DIR__ . '/storage/database.sqlite');
if (!is_file($sourcePath)) {
    fwrite(STDERR, "File SQLite sumber tidak ditemukan di: {$sourcePath}\n");
    exit(1);
}

$source = new PDO('sqlite:' . $sourcePath, null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

$sourceUserColumns = sqliteTableColumns($source, 'users');
$sourceNoteColumns = sqliteTableColumns($source, 'nota_dropping');

if ($sourceUserColumns === [] || $sourceNoteColumns === []) {
    fwrite(STDERR, "Tabel SQLite sumber belum lengkap. Pastikan users dan nota_dropping tersedia.\n");
    exit(1);
}

$users = $source->query('SELECT * FROM users ORDER BY id ASC')->fetchAll();
$notes = $source->query('SELECT * FROM nota_dropping ORDER BY id ASC')->fetchAll();

$pdo->beginTransaction();

try {
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
    $pdo->exec('TRUNCATE TABLE nota_dropping');
    $pdo->exec('TRUNCATE TABLE users');

    $insertUser = $pdo->prepare(
        'INSERT INTO users (id, full_name, username, password_hash, role, is_active, created_at, updated_at)
        VALUES (:id, :full_name, :username, :password_hash, :role, :is_active, :created_at, :updated_at)'
    );

    $maxUserId = 0;
    foreach ($users as $user) {
        $maxUserId = max($maxUserId, (int)$user['id']);
        $insertUser->execute([
            'id' => (int)$user['id'],
            'full_name' => (string)$user['full_name'],
            'username' => (string)$user['username'],
            'password_hash' => (string)$user['password_hash'],
            'role' => (string)$user['role'],
            'is_active' => (int)($user['is_active'] ?? 1),
            'created_at' => normalizeDateTimeValue((string)($user['created_at'] ?? '')),
            'updated_at' => normalizeDateTimeValue((string)($user['updated_at'] ?? '')),
        ]);
    }

    $insertNote = $pdo->prepare(
        'INSERT INTO nota_dropping (
            id,
            outlet_code,
            outlet_name,
            invoice_date,
            invoice_value,
            payment_amount,
            sales_name,
            payment_status,
            sender_name,
            created_at,
            updated_at,
            archived_at,
            created_by_user_id,
            updated_by_user_id
        ) VALUES (
            :id,
            :outlet_code,
            :outlet_name,
            :invoice_date,
            :invoice_value,
            :payment_amount,
            :sales_name,
            :payment_status,
            :sender_name,
            :created_at,
            :updated_at,
            :archived_at,
            :created_by_user_id,
            :updated_by_user_id
        )'
    );

    $maxNoteId = 0;
    foreach ($notes as $note) {
        $maxNoteId = max($maxNoteId, (int)$note['id']);
        $insertNote->execute([
            'id' => (int)$note['id'],
            'outlet_code' => (string)$note['outlet_code'],
            'outlet_name' => (string)$note['outlet_name'],
            'invoice_date' => normalizeDateValue((string)$note['invoice_date']),
            'invoice_value' => (int)$note['invoice_value'],
            'payment_amount' => (int)($note['payment_amount'] ?? 0),
            'sales_name' => (string)$note['sales_name'],
            'payment_status' => normalizePaymentStatusValue((string)($note['payment_status'] ?? 'belum_bayar')),
            'sender_name' => (string)$note['sender_name'],
            'created_at' => normalizeDateTimeValue((string)($note['created_at'] ?? '')),
            'updated_at' => normalizeDateTimeValue((string)($note['updated_at'] ?? '')),
            'archived_at' => in_array('archived_at', $sourceNoteColumns, true) ? nullableDateTimeValue($note['archived_at'] ?? null) : null,
            'created_by_user_id' => in_array('created_by_user_id', $sourceNoteColumns, true) && $note['created_by_user_id'] !== null ? (int)$note['created_by_user_id'] : null,
            'updated_by_user_id' => in_array('updated_by_user_id', $sourceNoteColumns, true) && $note['updated_by_user_id'] !== null ? (int)$note['updated_by_user_id'] : null,
        ]);
    }

    $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');

    if ($maxUserId > 0) {
        $pdo->exec('ALTER TABLE users AUTO_INCREMENT = ' . ($maxUserId + 1));
    }

    if ($maxNoteId > 0) {
        $pdo->exec('ALTER TABLE nota_dropping AUTO_INCREMENT = ' . ($maxNoteId + 1));
    }

    $pdo->commit();

    fwrite(STDOUT, "Migrasi berhasil.\n");
    fwrite(STDOUT, 'User dipindahkan: ' . count($users) . "\n");
    fwrite(STDOUT, 'Nota dipindahkan: ' . count($notes) . "\n");
    fwrite(STDOUT, 'Sumber SQLite: ' . $sourcePath . "\n");
} catch (Throwable $exception) {
    $pdo->rollBack();
    try {
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
    } catch (Throwable) {
    }

    fwrite(STDERR, "Migrasi gagal: {$exception->getMessage()}\n");
    exit(1);
}

function sqliteTableColumns(PDO $pdo, string $tableName): array
{
    $statement = $pdo->query('PRAGMA table_info(' . $tableName . ')');
    $columns = $statement->fetchAll();

    return array_column($columns, 'name');
}

function normalizeDateValue(string $value): string
{
    $parsed = DateTimeImmutable::createFromFormat('Y-m-d', $value);

    if ($parsed === false) {
        throw new RuntimeException('Tanggal nota tidak valid: ' . $value);
    }

    return $parsed->format('Y-m-d');
}

function normalizeDateTimeValue(string $value): string
{
    $parsed = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $value);

    if ($parsed === false) {
        throw new RuntimeException('Tanggal waktu tidak valid: ' . $value);
    }

    return $parsed->format('Y-m-d H:i:s');
}

function nullableDateTimeValue(?string $value): ?string
{
    if ($value === null || $value === '') {
        return null;
    }

    return normalizeDateTimeValue($value);
}

function normalizePaymentStatusValue(string $value): string
{
    return $value === 'sudah_bayar' ? 'sudah_bayar' : 'belum_bayar';
}
