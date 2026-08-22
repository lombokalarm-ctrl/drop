<?php
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

$errors = [];
$currentUser = requireLogin($pdo);
$currentPage = (string)($_GET['page'] ?? '');
$isArchivePage = $currentPage === 'arsip';
$editId = isset($_GET['edit']) ? (int)$_GET['edit'] : 0;
$editData = $editId > 0 ? findNota($pdo, $editId) : null;
$payData = null;
$senderData = null;
$paymentFormData = [
    'payment_increment' => '',
];
$senderFormData = [
    'sender_name' => '',
];

if ($isArchivePage && !canViewArchive($currentUser)) {
    setFlash('error', 'Role Anda tidak memiliki akses ke halaman arsip.');
    redirectToIndex();
}

if ($editId > 0 && !$editData) {
    setFlash('error', 'Data yang ingin diedit tidak ditemukan.');
    redirectToIndex();
}

if ($editData && $editData['archived_at'] !== null) {
    setFlash('error', 'Data arsip tidak bisa diedit dari halaman utama.');
    redirectToIndex(['page' => 'arsip']);
}

if ($editData && !canEditNote($currentUser, $editData)) {
    setFlash('error', 'Anda tidak memiliki izin untuk mengedit nota ini.');
    redirectToIndex();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? 'save';
    $redirectPage = (string)($_POST['page'] ?? '');
    $redirectParams = $redirectPage === 'arsip' ? ['page' => 'arsip'] : [];

    if ($action === 'archive') {
        $id = (int)($_POST['id'] ?? 0);
        $existing = $id > 0 ? findNota($pdo, $id) : null;

        if (!$existing) {
            setFlash('error', 'Data yang ingin diarsipkan tidak ditemukan.');
        } elseif (!canArchiveNote($currentUser, $existing)) {
            setFlash('error', 'Anda tidak memiliki izin untuk mengarsipkan nota ini.');
        } elseif ($existing['archived_at'] === null) {
            $statement = $pdo->prepare(
                'UPDATE nota_dropping
                SET archived_at = :archived_at,
                    updated_at = :updated_at,
                    updated_by_user_id = :updated_by_user_id
                WHERE id = :id'
            );
            $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');
            $statement->execute([
                'id' => $id,
                'archived_at' => $now,
                'updated_at' => $now,
                'updated_by_user_id' => (int)$currentUser['id'],
            ]);
            setFlash('success', 'Data nota berhasil diarsipkan.');
        } else {
            setFlash('error', 'Data nota sudah berada di arsip.');
        }

        redirectToIndex($redirectParams);
    }

    if ($action === 'delete_permanent') {
        $id = (int)($_POST['id'] ?? 0);
        $existing = $id > 0 ? findNota($pdo, $id) : null;

        if (!$existing) {
            setFlash('error', 'Data arsip yang ingin dihapus tidak ditemukan.');
        } elseif (!canDeletePermanent($currentUser)) {
            setFlash('error', 'Role Anda tidak diizinkan menghapus permanen data arsip.');
        } elseif ($existing['archived_at'] !== null) {
            $statement = $pdo->prepare('DELETE FROM nota_dropping WHERE id = :id');
            $statement->execute(['id' => $id]);
            setFlash('success', 'Data arsip berhasil dihapus permanen.');
        } else {
            setFlash('error', 'Data arsip yang ingin dihapus tidak ditemukan.');
        }

        redirectToIndex($redirectParams);
    }

    if ($action === 'pay') {
        $id = (int)($_POST['id'] ?? 0);
        $payData = $id > 0 ? findNota($pdo, $id) : null;
        $paymentIncrement = (int)preg_replace('/\D+/', '', (string)($_POST['payment_increment'] ?? '0'));
        $paymentFormData = [
            'payment_increment' => preg_replace('/\D+/', '', (string)($_POST['payment_increment'] ?? '0')),
        ];

        if (!$payData) {
            $errors[] = 'Data yang ingin dibayarkan tidak ditemukan.';
        } elseif ($payData['archived_at'] !== null) {
            $errors[] = 'Data arsip tidak bisa dibayarkan.';
        } elseif (!canPayNote($currentUser, $payData)) {
            $errors[] = 'Anda tidak memiliki izin untuk mencatat pembayaran nota ini.';
        } else {
            $remainingAmount = getRemainingAmount((int)$payData['invoice_value'], (int)$payData['payment_amount']);

            if ($paymentIncrement <= 0) {
                $errors[] = 'Nominal bayar harus lebih dari 0.';
            }

            if ($paymentIncrement > $remainingAmount) {
                $errors[] = 'Nominal bayar tidak boleh melebihi sisa hutang.';
            }
        }

        if ($errors === []) {
            $newPaymentAmount = (int)$payData['payment_amount'] + $paymentIncrement;
            $paymentStatus = calculatePaymentStatus((int)$payData['invoice_value'], $newPaymentAmount);
            $statement = $pdo->prepare(
                'UPDATE nota_dropping
                SET payment_amount = :payment_amount,
                    payment_status = :payment_status,
                    updated_at = :updated_at,
                    updated_by_user_id = :updated_by_user_id
                WHERE id = :id'
            );
            $statement->execute([
                'id' => $id,
                'payment_amount' => $newPaymentAmount,
                'payment_status' => $paymentStatus,
                'updated_at' => (new DateTimeImmutable())->format('Y-m-d H:i:s'),
                'updated_by_user_id' => (int)$currentUser['id'],
            ]);

            setFlash('success', 'Pembayaran nota berhasil dicatat.');
            redirectToIndex($redirectParams);
        }
    }

    if ($action === 'update_sender') {
        $id = (int)($_POST['id'] ?? 0);
        $senderData = $id > 0 ? findNota($pdo, $id) : null;
        $senderName = trim((string)($_POST['sender_name'] ?? ''));
        $senderFormData = [
            'sender_name' => $senderName,
        ];

        if (!$senderData) {
            $errors[] = 'Data nota yang ingin diisi pengirimnya tidak ditemukan.';
        } elseif ($senderData['archived_at'] !== null) {
            $errors[] = 'Data arsip tidak bisa diubah pengirimnya dari halaman utama.';
        } elseif (!canManageSender($currentUser, $senderData)) {
            $errors[] = 'Role Anda tidak memiliki izin untuk mengisi data pengirim.';
        } elseif ($senderName === '') {
            $errors[] = 'Nama pengirim wajib diisi.';
        }

        if ($errors === []) {
            $statement = $pdo->prepare(
                'UPDATE nota_dropping
                SET sender_name = :sender_name,
                    updated_at = :updated_at,
                    updated_by_user_id = :updated_by_user_id
                WHERE id = :id'
            );
            $statement->execute([
                'id' => $id,
                'sender_name' => $senderName,
                'updated_at' => (new DateTimeImmutable())->format('Y-m-d H:i:s'),
                'updated_by_user_id' => (int)$currentUser['id'],
            ]);

            setFlash('success', 'Data pengirim berhasil disimpan.');
            redirectToIndex($redirectParams);
        }
    }

    if ($action !== 'save' && $action !== 'pay' && $action !== 'archive' && $action !== 'delete_permanent' && $action !== 'update_sender') {
        setFlash('error', 'Aksi tidak dikenali.');
        redirectToIndex($redirectParams);
    }

    if ($action === 'save') {
        $id = (int)($_POST['id'] ?? 0);
        $existingData = $id > 0 ? findNota($pdo, $id) : null;
        $outletCode = trim((string)($_POST['outlet_code'] ?? ''));
        $outletName = trim((string)($_POST['outlet_name'] ?? ''));
        $invoiceDate = trim((string)($_POST['invoice_date'] ?? ''));
        $invoiceValue = (int)preg_replace('/\D+/', '', (string)($_POST['invoice_value'] ?? '0'));
        $salesName = trim((string)($_POST['sales_name'] ?? ''));
        $paymentAmount = $existingData ? (int)$existingData['payment_amount'] : 0;
        $senderName = $existingData ? (string)$existingData['sender_name'] : '';

        if ($id === 0 && !canCreateNote($currentUser)) {
            $errors[] = 'Role Anda tidak diizinkan menambah nota baru.';
        }

        if ($outletCode === '') {
            $errors[] = 'Kode outlet wajib diisi.';
        }
        if ($outletName === '') {
            $errors[] = 'Nama outlet wajib diisi.';
        }
        if ($invoiceDate === '') {
            $errors[] = 'Tanggal nota wajib diisi.';
        }
        if ($invoiceValue <= 0) {
            $errors[] = 'Nilai nota harus lebih dari 0.';
        }
        if ($salesName === '') {
            $errors[] = 'Nama sales wajib diisi.';
        }
        if ($id > 0 && !$existingData) {
            $errors[] = 'Data yang ingin diperbarui tidak ditemukan.';
        } elseif ($existingData && !canEditNote($currentUser, $existingData)) {
            $errors[] = 'Anda tidak memiliki izin untuk memperbarui nota ini.';
        }
        if ($errors === []) {
            $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');
            $paymentStatus = calculatePaymentStatus($invoiceValue, $paymentAmount);

            if ($id > 0) {
                $statement = $pdo->prepare(
                    'UPDATE nota_dropping
                    SET outlet_code = :outlet_code,
                        outlet_name = :outlet_name,
                        invoice_date = :invoice_date,
                        invoice_value = :invoice_value,
                        payment_amount = :payment_amount,
                        sales_name = :sales_name,
                        payment_status = :payment_status,
                        sender_name = :sender_name,
                        updated_at = :updated_at,
                        updated_by_user_id = :updated_by_user_id
                    WHERE id = :id'
                );
                $statement->execute([
                    'id' => $id,
                    'outlet_code' => $outletCode,
                    'outlet_name' => $outletName,
                    'invoice_date' => $invoiceDate,
                    'invoice_value' => $invoiceValue,
                    'payment_amount' => $paymentAmount,
                    'sales_name' => $salesName,
                    'payment_status' => $paymentStatus,
                    'sender_name' => $senderName,
                    'updated_at' => $now,
                    'updated_by_user_id' => (int)$currentUser['id'],
                ]);

                setFlash('success', 'Data nota berhasil diperbarui.');
            } else {
                $statement = $pdo->prepare(
                    'INSERT INTO nota_dropping (
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
                        created_by_user_id,
                        updated_by_user_id
                    ) VALUES (
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
                        :created_by_user_id,
                        :updated_by_user_id
                    )'
                );
                $statement->execute([
                    'outlet_code' => $outletCode,
                    'outlet_name' => $outletName,
                    'invoice_date' => $invoiceDate,
                    'invoice_value' => $invoiceValue,
                    'payment_amount' => $paymentAmount,
                    'sales_name' => $salesName,
                    'payment_status' => $paymentStatus,
                    'sender_name' => $senderName,
                    'created_at' => $now,
                    'updated_at' => $now,
                    'created_by_user_id' => (int)$currentUser['id'],
                    'updated_by_user_id' => (int)$currentUser['id'],
                ]);

                setFlash('success', 'Data nota berhasil ditambahkan.');
            }

            redirectToIndex($redirectParams);
        }
    }
}

$filters = [
    'q' => trim((string)($_GET['q'] ?? '')),
    'status' => (string)($_GET['status'] ?? ''),
];

$where = [];
$params = [];

$where[] = $isArchivePage ? 'archived_at IS NOT NULL' : 'archived_at IS NULL';

[$scopeWhere, $scopeParams] = getUserScopeWhere($currentUser);
if ($scopeWhere !== '') {
    $where[] = $scopeWhere;
    $params = array_merge($params, $scopeParams);
}

if ($filters['q'] !== '') {
    $where[] = '(outlet_code LIKE :q OR outlet_name LIKE :q OR sales_name LIKE :q OR sender_name LIKE :q)';
    $params['q'] = '%' . $filters['q'] . '%';
}

if ($filters['status'] === 'belum_bayar') {
    $where[] = 'payment_amount < invoice_value';
} elseif ($filters['status'] === 'sudah_bayar') {
    $where[] = 'payment_amount >= invoice_value';
}

$sql = 'SELECT * FROM nota_dropping';
if ($where !== []) {
    $sql .= ' WHERE ' . implode(' AND ', $where);
}
$sql .= $isArchivePage
    ? ' ORDER BY archived_at DESC, updated_at DESC, id DESC'
    : ' ORDER BY created_at DESC, id DESC';

$statement = $pdo->prepare($sql);
$statement->execute($params);
$rows = $statement->fetchAll();

$summaryWhereParts = [$isArchivePage ? 'archived_at IS NOT NULL' : 'archived_at IS NULL'];
$summaryParams = [];
if ($scopeWhere !== '') {
    $summaryWhereParts[] = $scopeWhere;
    $summaryParams = array_merge($summaryParams, $scopeParams);
}
$summaryStatement = $pdo->prepare(
    'SELECT
        COUNT(*) AS total_data,
        SUM(invoice_value) AS total_nilai,
        SUM(payment_amount) AS total_dibayar,
        SUM(CASE WHEN payment_amount < invoice_value THEN 1 ELSE 0 END) AS total_belum_bayar,
        SUM(CASE WHEN invoice_value - payment_amount > 0 THEN invoice_value - payment_amount ELSE 0 END) AS total_piutang,
        SUM(CASE WHEN payment_amount >= invoice_value THEN 1 ELSE 0 END) AS total_sudah_bayar
    FROM nota_dropping
    WHERE ' . implode(' AND ', $summaryWhereParts)
);
$summaryStatement->execute($summaryParams);
$summary = $summaryStatement->fetch() ?: [];

$flash = getFlash();
$formData = $editData ?? [
    'id' => 0,
    'outlet_code' => '',
    'outlet_name' => '',
    'invoice_date' => '',
    'created_at' => (new DateTimeImmutable())->format('Y-m-d H:i:s'),
    'invoice_value' => '',
    'payment_amount' => '0',
    'sales_name' => '',
    'payment_status' => 'belum_bayar',
    'sender_name' => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $errors !== []) {
    if (($_POST['action'] ?? '') === 'save') {
        $formData = [
            'id' => (int)($_POST['id'] ?? 0),
            'outlet_code' => $_POST['outlet_code'] ?? '',
            'outlet_name' => $_POST['outlet_name'] ?? '',
            'invoice_date' => $_POST['invoice_date'] ?? '',
            'created_at' => $_POST['created_at'] ?? (new DateTimeImmutable())->format('Y-m-d H:i:s'),
            'invoice_value' => preg_replace('/\D+/', '', (string)($_POST['invoice_value'] ?? '')),
            'payment_amount' => $editData ? (string)$editData['payment_amount'] : '0',
            'sales_name' => $_POST['sales_name'] ?? '',
            'payment_status' => $editData && (int)$editData['payment_amount'] > 0
                ? calculatePaymentStatus(
                    (int)preg_replace('/\D+/', '', (string)($_POST['invoice_value'] ?? '0')),
                    (int)$editData['payment_amount']
                )
                : 'belum_bayar',
            'sender_name' => $_POST['sender_name'] ?? '',
        ];
    }
}
?>
<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#2457ff">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">
    <meta name="apple-mobile-web-app-title" content="Nota Dropping">
    <title>Pencatatan Pembayaran Nota Dropping</title>
    <link rel="manifest" href="manifest.webmanifest">
    <link rel="icon" href="assets/icon.svg" type="image/svg+xml">
    <link rel="apple-touch-icon" href="assets/icon-180.png">
    <link rel="stylesheet" href="assets/style.css">
</head>
<body data-page="<?= $isArchivePage ? 'arsip' : 'utama' ?>">
    <main class="page">
        <section class="hero card">
            <div>
                <p class="eyebrow">Aplikasi Operasional</p>
                <h1><?= $isArchivePage ? 'Arsip Nota Dropping' : 'Pencatatan Pembayaran Nota Dropping' ?></h1>
            </div>
            <div class="hero-badges">
                <span class="badge badge-neutral"><?= htmlspecialchars($currentUser['full_name'], ENT_QUOTES, 'UTF-8') ?></span>
                <?php if (canViewArchive($currentUser)): ?>
                    <a class="badge badge-link" href="<?= $isArchivePage ? 'index.php' : 'index.php?page=arsip' ?>">
                        <?= $isArchivePage ? 'Halaman Utama' : 'Halaman Arsip' ?>
                    </a>
                <?php endif; ?>
                <?php if (canManageUsers($currentUser)): ?>
                    <a class="badge badge-link" href="users.php">Kelola User</a>
                <?php endif; ?>
                <a class="badge badge-link" href="logout.php">Logout</a>
            </div>
            <div class="hero-actions">
                <button type="button" class="btn btn-primary btn-install" id="installAppButton" hidden>Install App</button>
                <p class="install-hint" id="installHint">Bisa dipasang ke layar utama Android dari browser.</p>
            </div>
        </section>

        <section class="summary-grid">
            <article class="card summary-card">
                <span class="summary-label">Total Data</span>
                <strong><?= (int)($summary['total_data'] ?? 0) ?></strong>
            </article>
            <article class="card summary-card">
                <span class="summary-label">Total Nilai Nota</span>
                <strong><?= formatNumberId((int)($summary['total_nilai'] ?? 0)) ?></strong>
            </article>
            <article class="card summary-card success">
                <span class="summary-label">Total Dibayar</span>
                <strong><?= formatNumberId((int)($summary['total_dibayar'] ?? 0)) ?></strong>
            </article>
            <article class="card summary-card warning">
                <span class="summary-label">Masih Hutang</span>
                <strong><?= (int)($summary['total_belum_bayar'] ?? 0) ?></strong>
            </article>
            <article class="card summary-card danger">
                <span class="summary-label">Outstanding</span>
                <strong><?= formatNumberId((int)($summary['total_piutang'] ?? 0)) ?></strong>
            </article>
        </section>

        <?php if ($flash): ?>
            <div class="flash <?= htmlspecialchars($flash['type'], ENT_QUOTES, 'UTF-8') ?>">
                <?= htmlspecialchars($flash['message'], ENT_QUOTES, 'UTF-8') ?>
            </div>
        <?php endif; ?>

        <?php if ($errors !== []): ?>
            <div class="flash error">
                <strong>Data belum bisa disimpan:</strong>
                <ul>
                    <?php foreach ($errors as $error): ?>
                        <li><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <?php if (!$isArchivePage): ?>
            <section class="card pwa-mobile-menu" id="pwaMobileMenu" hidden>
                <div>
                    <strong>Menu Daftar Nota</strong>
                </div>
                <button type="button" class="btn btn-primary" id="openListViewButton">Daftar Nota</button>
            </section>
        <?php endif; ?>

        <div class="layout">
            <section class="card form-card">
                <div class="section-head">
                    <h2>
                        <?php if ($isArchivePage): ?>
                            Info Arsip
                        <?php else: ?>
                            <?= (int)$formData['id'] > 0 ? 'Edit Nota' : 'Input Nota Baru' ?>
                        <?php endif; ?>
                    </h2>
                    <?php if (!$isArchivePage && (int)$formData['id'] > 0): ?>
                        <a class="text-link" href="index.php">Batal edit</a>
                    <?php endif; ?>
                </div>

                <?php if ($isArchivePage): ?>
                    <div class="archive-panel">
                        <p>Data yang diarsipkan tidak tampil lagi di halaman utama.</p>
                        <p>Gunakan tombol <strong>Hapus Permanen</strong> hanya untuk data yang benar-benar sudah tidak dibutuhkan.</p>
                        <a class="btn btn-secondary" href="index.php">Kembali ke Halaman Utama</a>
                    </div>
                <?php elseif (!canCreateNote($currentUser)): ?>
                    <div class="archive-panel">
                        <?php if ($currentUser['role'] === 'staff'): ?>
                            <p>Role Anda difokuskan untuk proses pembayaran dan pengarsipan nota.</p>
                            <p>Gunakan tombol <strong>Bayar</strong> dan <strong>Arsipkan</strong> di daftar nota. Input nota baru tidak tersedia untuk role ini.</p>
                        <?php else: ?>
                            <p>Role Anda difokuskan untuk operasional gudang.</p>
                            <p>Gunakan tombol <strong>Input Pengirim</strong> atau <strong>Edit Pengirim</strong> di daftar nota untuk mengisi nama pengirim.</p>
                        <?php endif; ?>
                    </div>
                <?php else: ?>
                    <form method="post" class="nota-form">
                        <input type="hidden" name="action" value="save">
                        <input type="hidden" name="id" value="<?= (int)$formData['id'] ?>">
                        <input type="hidden" name="page" value="">

                        <div class="field-grid">
                            <label>
                                <span>Tanggal Pembuatan</span>
                                <input type="text" value="<?= htmlspecialchars(formatDateId((string)$formData['created_at']), ENT_QUOTES, 'UTF-8') ?>" readonly>
                            </label>

                            <label>
                                <span>Nama Sales</span>
                                <input type="text" name="sales_name" value="<?= htmlspecialchars((string)$formData['sales_name'], ENT_QUOTES, 'UTF-8') ?>" placeholder="Nama sales" required>
                            </label>

                            <label>
                                <span>Kode Outlet</span>
                                <input type="text" name="outlet_code" value="<?= htmlspecialchars((string)$formData['outlet_code'], ENT_QUOTES, 'UTF-8') ?>" placeholder="Mis. OTL-001" required>
                            </label>

                            <label>
                                <span>Nama Outlet</span>
                                <input type="text" name="outlet_name" value="<?= htmlspecialchars((string)$formData['outlet_name'], ENT_QUOTES, 'UTF-8') ?>" placeholder="Nama toko / outlet" required>
                            </label>

                            <label>
                                <span>Tanggal Nota</span>
                                <input type="text" name="invoice_date" value="<?= htmlspecialchars((string)$formData['invoice_date'], ENT_QUOTES, 'UTF-8') ?>" placeholder="Tulis bebas" required>
                            </label>

                            <label>
                                <span>Nilai Nota</span>
                                <input type="text" name="invoice_value" data-currency data-role="invoice-value" value="<?= htmlspecialchars((string)$formData['invoice_value'], ENT_QUOTES, 'UTF-8') ?>" placeholder="Contoh: 1250000" inputmode="numeric" required>
                            </label>
                        </div>

                        <div class="form-actions">
                            <button type="submit" class="btn btn-primary"><?= (int)$formData['id'] > 0 ? 'Simpan Perubahan' : 'Simpan Data' ?></button>
                            <a class="btn btn-secondary" href="index.php">Reset</a>
                        </div>
                    </form>
                <?php endif; ?>
            </section>

            <section class="card table-card">
                <div class="section-head">
                    <div class="table-head-main">
                        <h2><?= $isArchivePage ? 'Daftar Arsip Nota' : 'Daftar Nota' ?></h2>
                        <span class="table-count"><?= count($rows) ?> data</span>
                    </div>
                    <?php if (!$isArchivePage): ?>
                        <button type="button" class="btn btn-secondary mobile-list-back" id="closeListViewButton">Kembali ke Input</button>
                    <?php endif; ?>
                </div>

                <form method="get" class="filter-bar">
                    <?php if ($isArchivePage): ?>
                        <input type="hidden" name="page" value="arsip">
                    <?php endif; ?>
                    <input type="search" name="q" value="<?= htmlspecialchars($filters['q'], ENT_QUOTES, 'UTF-8') ?>" placeholder="Cari outlet, sales, pengirim">
                    <select name="status">
                        <option value="">Semua Status</option>
                        <option value="belum_bayar" <?= $filters['status'] === 'belum_bayar' ? 'selected' : '' ?>>Masih Hutang</option>
                        <option value="sudah_bayar" <?= $filters['status'] === 'sudah_bayar' ? 'selected' : '' ?>>Lunas</option>
                    </select>
                    <button type="submit" class="btn btn-primary">Filter</button>
                    <a href="<?= $isArchivePage ? 'index.php?page=arsip' : 'index.php' ?>" class="btn btn-secondary">Reset</a>
                </form>

                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th>Dibuat</th>
                                <th>Outlet</th>
                                <th>Tanggal Nota</th>
                                <?php if ($isArchivePage): ?>
                                    <th>Diarsipkan</th>
                                <?php endif; ?>
                                <th>Nilai Nota</th>
                                <th>Dibayar</th>
                                <th>Sisa Hutang</th>
                                <th>Sales</th>
                                <th>Pengirim</th>
                                <th>Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($rows === []): ?>
                                <tr>
                                    <td colspan="<?= $isArchivePage ? '10' : '9' ?>" class="empty-state">
                                        <?= $isArchivePage ? 'Belum ada data arsip nota dropping.' : 'Belum ada data nota dropping.' ?>
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($rows as $row): ?>
                                    <?php $remainingAmount = getRemainingAmount((int)$row['invoice_value'], (int)$row['payment_amount']); ?>
                                    <tr>
                                        <td><?= formatDateId($row['created_at']) ?></td>
                                        <td>
                                            <?= htmlspecialchars($row['outlet_name'] . ' (' . $row['outlet_code'] . ')', ENT_QUOTES, 'UTF-8') ?>
                                        </td>
                                        <td><?= htmlspecialchars((string)$row['invoice_date'], ENT_QUOTES, 'UTF-8') ?></td>
                                        <?php if ($isArchivePage): ?>
                                            <td><?= formatDateTimeId($row['archived_at']) ?></td>
                                        <?php endif; ?>
                                        <td><?= formatNumberId((int)$row['invoice_value']) ?></td>
                                        <td><?= formatNumberId((int)$row['payment_amount']) ?></td>
                                        <td><?= formatNumberId($remainingAmount) ?></td>
                                        <td><?= htmlspecialchars($row['sales_name'], ENT_QUOTES, 'UTF-8') ?></td>
                                        <td><?= htmlspecialchars($row['sender_name'] !== '' ? $row['sender_name'] : '-', ENT_QUOTES, 'UTF-8') ?></td>
                                        <td>
                                            <div class="action-group">
                                                <?php if ($isArchivePage): ?>
                                                    <?php if (canDeletePermanent($currentUser)): ?>
                                                        <form method="post" onsubmit="return confirm('Hapus permanen data arsip ini?');">
                                                            <input type="hidden" name="action" value="delete_permanent">
                                                            <input type="hidden" name="page" value="arsip">
                                                            <input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
                                                            <button type="submit" class="btn btn-small btn-danger">Hapus Permanen</button>
                                                        </form>
                                                    <?php else: ?>
                                                        <span class="btn btn-small btn-disabled">Arsip</span>
                                                    <?php endif; ?>
                                                <?php else: ?>
                                                    <?php if ($remainingAmount > 0 && canPayNote($currentUser, $row)): ?>
                                                        <button
                                                            type="button"
                                                            class="btn btn-small btn-primary js-open-pay-modal"
                                                            data-id="<?= (int)$row['id'] ?>"
                                                            data-outlet="<?= htmlspecialchars($row['outlet_name'] . ' (' . $row['outlet_code'] . ')', ENT_QUOTES, 'UTF-8') ?>"
                                                            data-invoice-date="<?= htmlspecialchars((string)$row['invoice_date'], ENT_QUOTES, 'UTF-8') ?>"
                                                            data-created-at="<?= htmlspecialchars(formatDateId($row['created_at']), ENT_QUOTES, 'UTF-8') ?>"
                                                            data-invoice-value="<?= (int)$row['invoice_value'] ?>"
                                                            data-current-payment="<?= (int)$row['payment_amount'] ?>"
                                                            data-remaining="<?= $remainingAmount ?>"
                                                        >
                                                            Bayar
                                                        </button>
                                                    <?php elseif ($remainingAmount <= 0): ?>
                                                        <span class="btn btn-small btn-disabled">Lunas</span>
                                                    <?php else: ?>
                                                        <span class="btn btn-small btn-disabled">No Akses</span>
                                                    <?php endif; ?>
                                                    <?php if (canEditNote($currentUser, $row)): ?>
                                                        <a class="btn btn-small btn-secondary" href="index.php?edit=<?= (int)$row['id'] ?>">Edit</a>
                                                    <?php endif; ?>
                                                    <?php if (canManageSender($currentUser, $row)): ?>
                                                        <button
                                                            type="button"
                                                            class="btn btn-small btn-secondary js-open-sender-modal"
                                                            data-id="<?= (int)$row['id'] ?>"
                                                            data-outlet="<?= htmlspecialchars($row['outlet_name'] . ' (' . $row['outlet_code'] . ')', ENT_QUOTES, 'UTF-8') ?>"
                                                            data-invoice-date="<?= htmlspecialchars((string)$row['invoice_date'], ENT_QUOTES, 'UTF-8') ?>"
                                                            data-sales-name="<?= htmlspecialchars($row['sales_name'], ENT_QUOTES, 'UTF-8') ?>"
                                                            data-sender-name="<?= htmlspecialchars($row['sender_name'], ENT_QUOTES, 'UTF-8') ?>"
                                                        >
                                                            <?= $row['sender_name'] !== '' ? 'Edit Pengirim' : 'Input Pengirim' ?>
                                                        </button>
                                                    <?php endif; ?>
                                                    <?php if (canArchiveNote($currentUser, $row)): ?>
                                                        <form method="post" onsubmit="return confirm('Arsipkan data nota ini?');">
                                                            <input type="hidden" name="action" value="archive">
                                                            <input type="hidden" name="page" value="">
                                                            <input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
                                                            <button type="submit" class="btn btn-small btn-warning">Arsipkan</button>
                                                        </form>
                                                    <?php endif; ?>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </section>
        </div>

        <?php if (!$isArchivePage): ?>
            <?php
            $modalPayment = $payData ? (int)$payData['payment_amount'] : 0;
            $modalInvoice = $payData ? (int)$payData['invoice_value'] : 0;
            $modalRemaining = $payData ? getRemainingAmount($modalInvoice, $modalPayment) : 0;
            $modalIncrement = $paymentFormData['payment_increment'] !== '' ? (int)$paymentFormData['payment_increment'] : $modalRemaining;
            $modalSenderValue = $senderData ? (string)$senderData['sender_name'] : $senderFormData['sender_name'];
            ?>
            <div class="pay-modal-overlay<?= $payData ? ' is-open' : '' ?>" id="payModalOverlay" aria-hidden="<?= $payData ? 'false' : 'true' ?>">
                <div class="pay-modal card" role="dialog" aria-modal="true" aria-labelledby="payModalTitle">
                    <div class="section-head pay-modal-head">
                        <h2 id="payModalTitle">Bayar Nota</h2>
                        <button type="button" class="btn btn-secondary btn-small" id="closePayModalButton">Tutup</button>
                    </div>

                    <form method="post" class="nota-form" id="payModalForm">
                        <input type="hidden" name="action" value="pay">
                        <input type="hidden" name="page" value="">
                        <input type="hidden" name="id" id="payModalId" value="<?= $payData ? (int)$payData['id'] : 0 ?>">

                        <div class="field-grid">
                            <label>
                                <span>Outlet</span>
                                <input type="text" id="payModalOutlet" value="<?= htmlspecialchars($payData ? $payData['outlet_name'] . ' (' . $payData['outlet_code'] . ')' : '', ENT_QUOTES, 'UTF-8') ?>" readonly>
                            </label>

                            <label>
                                <span>Tanggal Nota</span>
                                <input type="text" id="payModalInvoiceDate" value="<?= htmlspecialchars($payData['invoice_date'] ?? '', ENT_QUOTES, 'UTF-8') ?>" readonly>
                            </label>

                            <label>
                                <span>Tanggal Pembuatan</span>
                                <input type="text" id="payModalCreatedAt" value="<?= htmlspecialchars($payData ? formatDateId($payData['created_at']) : '', ENT_QUOTES, 'UTF-8') ?>" readonly>
                            </label>

                            <label>
                                <span>Nilai Nota</span>
                                <input type="text" data-currency id="payModalInvoiceValue" value="<?= htmlspecialchars((string)$modalInvoice, ENT_QUOTES, 'UTF-8') ?>" readonly>
                            </label>

                            <label>
                                <span>Sudah Dibayar</span>
                                <input type="text" data-currency id="payModalCurrentPayment" value="<?= htmlspecialchars((string)$modalPayment, ENT_QUOTES, 'UTF-8') ?>" readonly>
                            </label>

                            <label>
                                <span>Sisa Hutang</span>
                                <input type="text" data-currency id="payModalRemainingBefore" value="<?= htmlspecialchars((string)$modalRemaining, ENT_QUOTES, 'UTF-8') ?>" readonly>
                            </label>

                            <label>
                                <span>Bayar Sekarang</span>
                                <input type="text" name="payment_increment" data-currency data-role="pay-increment" id="payModalIncrement" value="<?= htmlspecialchars((string)$modalIncrement, ENT_QUOTES, 'UTF-8') ?>" placeholder="Nominal pembayaran" inputmode="numeric" required>
                            </label>

                            <label>
                                <span>Status Setelah Bayar</span>
                                <input type="text" data-role="pay-status-preview" id="payModalStatusPreview" value="<?= $modalInvoice > 0 && ($modalPayment + $modalIncrement) >= $modalInvoice ? 'Lunas' : 'Masih Hutang' ?>" readonly>
                            </label>

                            <label>
                                <span>Sisa Setelah Bayar</span>
                                <input type="text" data-currency data-role="pay-remaining-after" id="payModalRemainingAfter" value="<?= htmlspecialchars((string)max(0, $modalRemaining - $modalIncrement), ENT_QUOTES, 'UTF-8') ?>" readonly>
                            </label>
                        </div>

                        <div class="form-actions">
                            <button type="submit" class="btn btn-primary">Simpan Pembayaran</button>
                            <button type="button" class="btn btn-secondary" id="cancelPayModalButton">Batal</button>
                        </div>
                    </form>
                </div>
            </div>

            <div class="pay-modal-overlay<?= $senderData ? ' is-open' : '' ?>" id="senderModalOverlay" aria-hidden="<?= $senderData ? 'false' : 'true' ?>">
                <div class="pay-modal card" role="dialog" aria-modal="true" aria-labelledby="senderModalTitle">
                    <div class="section-head pay-modal-head">
                        <h2 id="senderModalTitle">Input Pengirim</h2>
                        <button type="button" class="btn btn-secondary btn-small" id="closeSenderModalButton">Tutup</button>
                    </div>

                    <form method="post" class="nota-form" id="senderModalForm">
                        <input type="hidden" name="action" value="update_sender">
                        <input type="hidden" name="page" value="">
                        <input type="hidden" name="id" id="senderModalId" value="<?= $senderData ? (int)$senderData['id'] : 0 ?>">

                        <div class="field-grid">
                            <label>
                                <span>Outlet</span>
                                <input type="text" id="senderModalOutlet" value="<?= htmlspecialchars($senderData ? $senderData['outlet_name'] . ' (' . $senderData['outlet_code'] . ')' : '', ENT_QUOTES, 'UTF-8') ?>" readonly>
                            </label>

                            <label>
                                <span>Tanggal Nota</span>
                                <input type="text" id="senderModalInvoiceDate" value="<?= htmlspecialchars($senderData['invoice_date'] ?? '', ENT_QUOTES, 'UTF-8') ?>" readonly>
                            </label>

                            <label>
                                <span>Nama Sales</span>
                                <input type="text" id="senderModalSalesName" value="<?= htmlspecialchars($senderData['sales_name'] ?? '', ENT_QUOTES, 'UTF-8') ?>" readonly>
                            </label>

                            <label>
                                <span>Pengirim</span>
                                <input type="text" name="sender_name" id="senderModalSenderName" value="<?= htmlspecialchars($modalSenderValue, ENT_QUOTES, 'UTF-8') ?>" placeholder="Nama team gudang / pengirim" required>
                            </label>
                        </div>

                        <div class="form-actions">
                            <button type="submit" class="btn btn-primary">Simpan Pengirim</button>
                            <button type="button" class="btn btn-secondary" id="cancelSenderModalButton">Batal</button>
                        </div>
                    </form>
                </div>
            </div>
        <?php endif; ?>
    </main>

    <script>
        const currencyInputs = document.querySelectorAll('[data-currency]');
        const invoiceValueInput = document.querySelector('[data-role="invoice-value"]');
        const payModalOverlay = document.getElementById('payModalOverlay');
        const payModalIdInput = document.getElementById('payModalId');
        const payModalOutletInput = document.getElementById('payModalOutlet');
        const payModalInvoiceDateInput = document.getElementById('payModalInvoiceDate');
        const payModalCreatedAtInput = document.getElementById('payModalCreatedAt');
        const payInvoiceValueInput = document.getElementById('payModalInvoiceValue');
        const payCurrentAmountInput = document.getElementById('payModalCurrentPayment');
        const payRemainingBeforeInput = document.getElementById('payModalRemainingBefore');
        const payIncrementInput = document.getElementById('payModalIncrement');
        const payStatusPreview = document.getElementById('payModalStatusPreview');
        const payRemainingAfterInput = document.getElementById('payModalRemainingAfter');
        const closePayModalButton = document.getElementById('closePayModalButton');
        const cancelPayModalButton = document.getElementById('cancelPayModalButton');
        const payModalTriggers = document.querySelectorAll('.js-open-pay-modal');
        const senderModalOverlay = document.getElementById('senderModalOverlay');
        const senderModalIdInput = document.getElementById('senderModalId');
        const senderModalOutletInput = document.getElementById('senderModalOutlet');
        const senderModalInvoiceDateInput = document.getElementById('senderModalInvoiceDate');
        const senderModalSalesNameInput = document.getElementById('senderModalSalesName');
        const senderModalSenderNameInput = document.getElementById('senderModalSenderName');
        const closeSenderModalButton = document.getElementById('closeSenderModalButton');
        const cancelSenderModalButton = document.getElementById('cancelSenderModalButton');
        const senderModalTriggers = document.querySelectorAll('.js-open-sender-modal');

        const formatNumber = (value) => {
            const digits = value.replace(/\D/g, '');
            if (!digits) {
                return '';
            }

            return new Intl.NumberFormat('id-ID').format(Number(digits));
        };

        const parseDigits = (value) => Number((value || '').replace(/\D/g, '')) || 0;
        const countDigits = (value) => (value.match(/\d/g) || []).length;
        const getCaretFromDigitIndex = (formattedValue, digitIndex) => {
            if (digitIndex <= 0) {
                return 0;
            }

            let digitsSeen = 0;

            for (let index = 0; index < formattedValue.length; index += 1) {
                if (/\d/.test(formattedValue[index])) {
                    digitsSeen += 1;
                }

                if (digitsSeen >= digitIndex) {
                    return index + 1;
                }
            }

            return formattedValue.length;
        };

        const updatePayPreview = () => {
            if (!payInvoiceValueInput || !payCurrentAmountInput || !payIncrementInput || !payStatusPreview || !payRemainingAfterInput || !payRemainingBeforeInput) {
                return;
            }

            const invoiceValue = parseDigits(payInvoiceValueInput.value);
            const currentAmount = parseDigits(payCurrentAmountInput.value);
            const increment = parseDigits(payIncrementInput.value);
            const totalAfter = currentAmount + increment;
            const remainingAfter = Math.max(0, invoiceValue - totalAfter);

            payRemainingBeforeInput.value = formatNumber(String(Math.max(0, invoiceValue - currentAmount)));
            payRemainingAfterInput.value = formatNumber(String(remainingAfter));
            payStatusPreview.value = totalAfter >= invoiceValue ? 'Lunas' : 'Masih Hutang';
        };

        const openPayModal = (payload) => {
            if (!payModalOverlay || !payModalIdInput || !payModalOutletInput || !payModalInvoiceDateInput || !payModalCreatedAtInput || !payInvoiceValueInput || !payCurrentAmountInput || !payRemainingBeforeInput || !payIncrementInput) {
                return;
            }

            payModalIdInput.value = payload.id || '';
            payModalOutletInput.value = payload.outlet || '';
            payModalInvoiceDateInput.value = payload.invoiceDate || '';
            payModalCreatedAtInput.value = payload.createdAt || '';
            payInvoiceValueInput.value = formatNumber(String(payload.invoiceValue || 0));
            payCurrentAmountInput.value = formatNumber(String(payload.currentPayment || 0));
            payRemainingBeforeInput.value = formatNumber(String(payload.remaining || 0));
            payIncrementInput.value = formatNumber(String(payload.increment ?? payload.remaining ?? 0));

            payModalOverlay.classList.add('is-open');
            payModalOverlay.setAttribute('aria-hidden', 'false');
            document.body.classList.add('modal-open');
            updatePayPreview();
            payIncrementInput.focus();
            payIncrementInput.select();
        };

        const closePayModal = () => {
            if (!payModalOverlay) {
                return;
            }

            payModalOverlay.classList.remove('is-open');
            payModalOverlay.setAttribute('aria-hidden', 'true');
            document.body.classList.remove('modal-open');
        };

        const openSenderModal = (payload) => {
            if (!senderModalOverlay || !senderModalIdInput || !senderModalOutletInput || !senderModalInvoiceDateInput || !senderModalSalesNameInput || !senderModalSenderNameInput) {
                return;
            }

            senderModalIdInput.value = payload.id || '';
            senderModalOutletInput.value = payload.outlet || '';
            senderModalInvoiceDateInput.value = payload.invoiceDate || '';
            senderModalSalesNameInput.value = payload.salesName || '';
            senderModalSenderNameInput.value = payload.senderName || '';

            senderModalOverlay.classList.add('is-open');
            senderModalOverlay.setAttribute('aria-hidden', 'false');
            document.body.classList.add('modal-open');
            senderModalSenderNameInput.focus();
            senderModalSenderNameInput.select();
        };

        const closeSenderModal = () => {
            if (!senderModalOverlay) {
                return;
            }

            senderModalOverlay.classList.remove('is-open');
            senderModalOverlay.setAttribute('aria-hidden', 'true');

            if (!payModalOverlay || !payModalOverlay.classList.contains('is-open')) {
                document.body.classList.remove('modal-open');
            }
        };

        currencyInputs.forEach((input) => {
            input.value = formatNumber(input.value);
            input.addEventListener('input', (event) => {
                const rawValue = event.target.value;
                const caretStart = event.target.selectionStart ?? rawValue.length;
                const digitIndex = countDigits(rawValue.slice(0, caretStart));
                const formattedValue = formatNumber(rawValue);

                event.target.value = formattedValue;

                if (
                    document.activeElement === event.target
                    && typeof event.target.setSelectionRange === 'function'
                    && !event.target.readOnly
                ) {
                    const nextCaret = getCaretFromDigitIndex(formattedValue, digitIndex);
                    event.target.setSelectionRange(nextCaret, nextCaret);
                }

                updatePayPreview();
            });
        });

        updatePayPreview();

        payModalTriggers.forEach((button) => {
            button.addEventListener('click', () => {
                openPayModal({
                    id: button.dataset.id,
                    outlet: button.dataset.outlet,
                    invoiceDate: button.dataset.invoiceDate,
                    createdAt: button.dataset.createdAt,
                    invoiceValue: Number(button.dataset.invoiceValue || 0),
                    currentPayment: Number(button.dataset.currentPayment || 0),
                    remaining: Number(button.dataset.remaining || 0),
                });
            });
        });

        senderModalTriggers.forEach((button) => {
            button.addEventListener('click', () => {
                openSenderModal({
                    id: button.dataset.id,
                    outlet: button.dataset.outlet,
                    invoiceDate: button.dataset.invoiceDate,
                    salesName: button.dataset.salesName,
                    senderName: button.dataset.senderName,
                });
            });
        });

        if (closePayModalButton) {
            closePayModalButton.addEventListener('click', closePayModal);
        }

        if (cancelPayModalButton) {
            cancelPayModalButton.addEventListener('click', closePayModal);
        }

        if (payModalOverlay) {
            payModalOverlay.addEventListener('click', (event) => {
                if (event.target === payModalOverlay) {
                    closePayModal();
                }
            });
        }

        if (closeSenderModalButton) {
            closeSenderModalButton.addEventListener('click', closeSenderModal);
        }

        if (cancelSenderModalButton) {
            cancelSenderModalButton.addEventListener('click', closeSenderModal);
        }

        if (senderModalOverlay) {
            senderModalOverlay.addEventListener('click', (event) => {
                if (event.target === senderModalOverlay) {
                    closeSenderModal();
                }
            });
        }

        window.addEventListener('keydown', (event) => {
            if (event.key === 'Escape' && payModalOverlay && payModalOverlay.classList.contains('is-open')) {
                closePayModal();
            }

            if (event.key === 'Escape' && senderModalOverlay && senderModalOverlay.classList.contains('is-open')) {
                closeSenderModal();
            }
        });

        if (payModalOverlay && payModalOverlay.classList.contains('is-open')) {
            document.body.classList.add('modal-open');
            updatePayPreview();
        }

        if (senderModalOverlay && senderModalOverlay.classList.contains('is-open')) {
            document.body.classList.add('modal-open');
        }

        const installButton = document.getElementById('installAppButton');
        const installHint = document.getElementById('installHint');
        const pwaMobileMenu = document.getElementById('pwaMobileMenu');
        const openListViewButton = document.getElementById('openListViewButton');
        const closeListViewButton = document.getElementById('closeListViewButton');
        const isArchivePage = document.body.dataset.page === 'arsip';
        let deferredInstallPrompt = null;

        const isStandalone = window.matchMedia('(display-mode: standalone)').matches || window.navigator.standalone === true;
        const mobileViewport = window.matchMedia('(max-width: 720px)');

        const syncPwaMobileListMode = () => {
            const enableMobilePwaMode = isStandalone && mobileViewport.matches && !isArchivePage;

            document.body.classList.toggle('pwa-mobile-mode', enableMobilePwaMode);

            if (!enableMobilePwaMode) {
                document.body.classList.remove('pwa-list-open');
            }

            if (pwaMobileMenu) {
                pwaMobileMenu.hidden = !enableMobilePwaMode;
            }
        };

        if (isStandalone && installHint) {
            installHint.textContent = 'Aplikasi sudah terpasang di perangkat ini.';
        }

        window.addEventListener('beforeinstallprompt', (event) => {
            event.preventDefault();
            deferredInstallPrompt = event;

            if (installButton) {
                installButton.hidden = false;
            }

            if (installHint) {
                installHint.textContent = 'Tekan tombol Install App untuk memasang aplikasi di Android.';
            }
        });

        if (installButton) {
            installButton.addEventListener('click', async () => {
                if (!deferredInstallPrompt) {
                    if (installHint) {
                        installHint.textContent = 'Gunakan menu browser lalu pilih Install app atau Add to Home screen.';
                    }
                    return;
                }

                deferredInstallPrompt.prompt();
                await deferredInstallPrompt.userChoice;
                deferredInstallPrompt = null;
                installButton.hidden = true;

                if (installHint) {
                    installHint.textContent = 'Permintaan instalasi sudah dikirim ke browser.';
                }
            });
        }

        window.addEventListener('appinstalled', () => {
            if (installButton) {
                installButton.hidden = true;
            }

            if (installHint) {
                installHint.textContent = 'Aplikasi berhasil dipasang ke layar utama.';
            }
        });

        if (openListViewButton) {
            openListViewButton.addEventListener('click', () => {
                document.body.classList.add('pwa-list-open');
            });
        }

        if (closeListViewButton) {
            closeListViewButton.addEventListener('click', () => {
                document.body.classList.remove('pwa-list-open');
            });
        }

        if (typeof mobileViewport.addEventListener === 'function') {
            mobileViewport.addEventListener('change', syncPwaMobileListMode);
        } else if (typeof mobileViewport.addListener === 'function') {
            mobileViewport.addListener(syncPwaMobileListMode);
        }

        syncPwaMobileListMode();

        if ('serviceWorker' in navigator) {
            window.addEventListener('load', () => {
                navigator.serviceWorker.register('sw.js');
            });
        }
    </script>
</body>
</html>
