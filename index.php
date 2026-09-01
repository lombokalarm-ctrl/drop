<?php
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

$errors = [];
$currentUser = requireLogin($pdo);
$currentPage = (string)($_GET['page'] ?? '');
$isPrintMode = (string)($_GET['print'] ?? '') === '1';
$keepListOpen = (string)($_GET['keep_list'] ?? '') === '1';
$isArchivePage = $currentPage === 'arsip';
$isPaymentPage = $currentPage === 'pembayaran';
$isMainPage = !$isArchivePage && !$isPaymentPage;
$editId = isset($_GET['edit']) ? (int)$_GET['edit'] : 0;
$editData = $editId > 0 ? findNota($pdo, $editId) : null;
$payData = null;
$editPaymentData = null;
$senderData = null;
$paymentFormData = [
    'payment_increment' => '',
];
$editPaymentFormData = [
    'payment_amount' => '',
];
$senderFormData = [
    'sender_name' => '',
];
$noteCategoryOptions = getNoteCategoryOptions();

if ($isArchivePage && !canViewArchive($currentUser)) {
    setFlash('error', 'Role Anda tidak memiliki akses ke halaman arsip.');
    redirectToIndex();
}

if ($isPaymentPage && !canViewPaymentStatus($currentUser)) {
    setFlash('error', 'Status pembayaran hanya bisa dibuka oleh owner dan admin.');
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
    $redirectParams = match ($redirectPage) {
        'arsip' => ['page' => 'arsip'],
        'pembayaran' => ['page' => 'pembayaran'],
        default => [],
    };

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
            if ($redirectPage === '' && $currentUser['role'] === 'staff') {
                $redirectParams['keep_list'] = '1';
            }
            redirectToIndex($redirectParams);
        }
    }

    if ($action === 'edit_payment') {
        $id = (int)($_POST['id'] ?? 0);
        $editPaymentData = $id > 0 ? findNota($pdo, $id) : null;
        $paymentAmount = (int)preg_replace('/\D+/', '', (string)($_POST['payment_amount'] ?? '0'));
        $editPaymentFormData = [
            'payment_amount' => preg_replace('/\D+/', '', (string)($_POST['payment_amount'] ?? '0')),
        ];

        if (!$editPaymentData) {
            $errors[] = 'Data pembayaran yang ingin diperbarui tidak ditemukan.';
        } elseif ($editPaymentData['archived_at'] !== null) {
            $errors[] = 'Data arsip tidak bisa diubah pembayarannya.';
        } elseif (!canPayNote($currentUser, $editPaymentData)) {
            $errors[] = 'Role Anda tidak memiliki izin untuk mengubah pembayaran nota ini.';
        } elseif ($paymentAmount > (int)$editPaymentData['invoice_value']) {
            $errors[] = 'Total pembayaran tidak boleh melebihi nilai nota.';
        }

        if ($errors === []) {
            $paymentStatus = calculatePaymentStatus((int)$editPaymentData['invoice_value'], $paymentAmount);
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
                'payment_amount' => $paymentAmount,
                'payment_status' => $paymentStatus,
                'updated_at' => (new DateTimeImmutable())->format('Y-m-d H:i:s'),
                'updated_by_user_id' => (int)$currentUser['id'],
            ]);

            setFlash('success', 'Data pembayaran berhasil diperbarui.');
            redirectToIndex($redirectParams);
        }
    }

    if ($action === 'update_sender') {
        $id = (int)($_POST['id'] ?? 0);
        $senderData = $id > 0 ? findNota($pdo, $id) : null;
        $senderName = normalizeLowercaseName((string)($_POST['sender_name'] ?? ''));
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
            if ($redirectPage === '' && $currentUser['role'] === 'gudang') {
                $redirectParams['keep_list'] = '1';
            }
            redirectToIndex($redirectParams);
        }
    }

    if ($action !== 'save' && $action !== 'pay' && $action !== 'edit_payment' && $action !== 'archive' && $action !== 'delete_permanent' && $action !== 'update_sender') {
        setFlash('error', 'Aksi tidak dikenali.');
        redirectToIndex($redirectParams);
    }

    if ($action === 'save') {
        $id = (int)($_POST['id'] ?? 0);
        $existingData = $id > 0 ? findNota($pdo, $id) : null;
        $outletCode = trim((string)($_POST['outlet_code'] ?? ''));
        $outletName = normalizeLowercaseName((string)($_POST['outlet_name'] ?? ''));
        $noteCategory = normalizeNoteCategory((string)($_POST['note_category'] ?? ''));
        $invoiceDate = trim((string)($_POST['invoice_date'] ?? ''));
        $invoiceValue = (int)preg_replace('/\D+/', '', (string)($_POST['invoice_value'] ?? '0'));
        $salesName = normalizeLowercaseName((string)($_POST['sales_name'] ?? ''));
        $paymentAmount = $existingData ? (int)$existingData['payment_amount'] : 0;
        $senderName = $existingData ? normalizeLowercaseName((string)$existingData['sender_name']) : '';

        if ($id === 0 && !canCreateNote($currentUser)) {
            $errors[] = 'Role Anda tidak diizinkan menambah nota baru.';
        }

        if ($outletCode === '') {
            $errors[] = 'Kode outlet wajib diisi.';
        }
        if ($outletName === '') {
            $errors[] = 'Nama outlet wajib diisi.';
        }
        if ($noteCategory === '') {
            $errors[] = 'Keterangan wajib dipilih.';
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
                        note_category = :note_category,
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
                    'note_category' => $noteCategory,
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
                        note_category,
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
                        :note_category,
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
                    'note_category' => $noteCategory,
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
    'created_from' => trim((string)($_GET['created_from'] ?? '')),
    'created_until' => trim((string)($_GET['created_until'] ?? '')),
];
$filterErrors = [];
$createdFromFilter = parseDateIdInput($filters['created_from']);
$createdUntilFilter = parseDateIdInput($filters['created_until']);

if ($filters['created_from'] !== '' && $createdFromFilter === null) {
    $filterErrors[] = 'Tanggal mulai harus memakai format DD-MM-YYYY.';
}

if ($filters['created_until'] !== '' && $createdUntilFilter === null) {
    $filterErrors[] = 'Tanggal akhir harus memakai format DD-MM-YYYY.';
}

if ($createdFromFilter !== null && $createdUntilFilter !== null && $createdFromFilter > $createdUntilFilter) {
    $filterErrors[] = 'Tanggal mulai tidak boleh melebihi tanggal akhir.';
    $createdFromFilter = null;
    $createdUntilFilter = null;
}

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

if ($createdFromFilter !== null) {
    $where[] = 'created_at >= :created_from';
    $params['created_from'] = $createdFromFilter . ' 00:00:00';
}

if ($createdUntilFilter !== null) {
    $where[] = 'created_at <= :created_until';
    $params['created_until'] = $createdUntilFilter . ' 23:59:59';
}

$sql = 'SELECT * FROM nota_dropping';
if ($where !== []) {
    $sql .= ' WHERE ' . implode(' AND ', $where);
}
if ($isPrintMode) {
    $sql .= ' ORDER BY created_at ASC, id ASC';
} else {
    $sql .= $isArchivePage
        ? ' ORDER BY archived_at DESC, updated_at DESC, id DESC'
        : ' ORDER BY created_at DESC, id DESC';
}

$statement = $pdo->prepare($sql);
$statement->execute($params);
$rows = $statement->fetchAll();

$summaryWhereParts = [$isArchivePage ? 'archived_at IS NOT NULL' : 'archived_at IS NULL'];
$summaryParams = [];
if ($scopeWhere !== '') {
    $summaryWhereParts[] = $scopeWhere;
    $summaryParams = array_merge($summaryParams, $scopeParams);
}
if ($createdFromFilter !== null) {
    $summaryWhereParts[] = 'created_at >= :created_from';
    $summaryParams['created_from'] = $createdFromFilter . ' 00:00:00';
}
if ($createdUntilFilter !== null) {
    $summaryWhereParts[] = 'created_at <= :created_until';
    $summaryParams['created_until'] = $createdUntilFilter . ' 23:59:59';
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
    'note_category' => '',
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
            'outlet_name' => normalizeLowercaseName((string)($_POST['outlet_name'] ?? '')),
            'note_category' => normalizeNoteCategory((string)($_POST['note_category'] ?? '')),
            'invoice_date' => $_POST['invoice_date'] ?? '',
            'created_at' => $_POST['created_at'] ?? (new DateTimeImmutable())->format('Y-m-d H:i:s'),
            'invoice_value' => preg_replace('/\D+/', '', (string)($_POST['invoice_value'] ?? '')),
            'payment_amount' => $editData ? (string)$editData['payment_amount'] : '0',
            'sales_name' => normalizeLowercaseName((string)($_POST['sales_name'] ?? '')),
            'payment_status' => $editData && (int)$editData['payment_amount'] > 0
                ? calculatePaymentStatus(
                    (int)preg_replace('/\D+/', '', (string)($_POST['invoice_value'] ?? '0')),
                    (int)$editData['payment_amount']
                )
                : 'belum_bayar',
            'sender_name' => normalizeLowercaseName((string)($_POST['sender_name'] ?? '')),
        ];
    }
}

$listTitle = $isArchivePage ? 'Daftar Arsip Nota' : ($isPaymentPage ? 'Status Pembayaran' : 'Daftar Nota');
$printTitle = $isArchivePage ? 'Cetak Arsip Nota' : ($isPaymentPage ? 'Cetak Status Pembayaran' : 'Cetak Daftar Nota');
$printBaseParams = [];

if ($currentPage !== '') {
    $printBaseParams['page'] = $currentPage;
}
if ($filters['q'] !== '') {
    $printBaseParams['q'] = $filters['q'];
}
if ($filters['status'] !== '') {
    $printBaseParams['status'] = $filters['status'];
}
if ($filters['created_from'] !== '') {
    $printBaseParams['created_from'] = $filters['created_from'];
}
if ($filters['created_until'] !== '') {
    $printBaseParams['created_until'] = $filters['created_until'];
}

$printUrl = 'index.php?' . http_build_query(array_merge($printBaseParams, ['print' => '1']));
$backFromPrintUrl = 'index.php' . ($printBaseParams !== [] ? '?' . http_build_query($printBaseParams) : '');
$printRowsByDate = [];
$printRowNumber = 1;
$printColumnCount = 9 + ($isArchivePage ? 1 : 0) + ($isPaymentPage ? 1 : 0);

if ($isPrintMode) {
    foreach ($rows as $row) {
        $dateGroupLabel = formatDateId((string)$row['created_at']);
        if (!isset($printRowsByDate[$dateGroupLabel])) {
            $printRowsByDate[$dateGroupLabel] = [];
        }
        $printRowsByDate[$dateGroupLabel][] = $row;
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
    <title>Nota Dropping dan Tunai</title>
    <link rel="manifest" href="manifest.webmanifest?v=4">
    <link rel="icon" href="assets/icon.svg" type="image/svg+xml">
    <link rel="apple-touch-icon" href="assets/icon-180.png">
    <link rel="stylesheet" href="assets/style.css">
</head>
<body class="<?= $isPrintMode ? 'print-mode' : '' ?>" data-page="<?= $isArchivePage ? 'arsip' : ($isPaymentPage ? 'pembayaran' : 'utama') ?>" data-keep-list-open="<?= $keepListOpen ? 'true' : 'false' ?>">
    <main class="page">
        <section class="hero card">
            <div>
                <p class="eyebrow">Aplikasi Operasional</p>
                <h1><?= $isPrintMode ? $printTitle : ($isArchivePage ? 'Arsip Nota Dropping' : ($isPaymentPage ? 'Status Pembayaran Nota' : 'Nota Dropping dan Tunai')) ?></h1>
            </div>
            <div class="hero-badges">
                <?php if ($isPrintMode): ?>
                    <span class="badge badge-neutral"><?= htmlspecialchars($currentUser['full_name'], ENT_QUOTES, 'UTF-8') ?></span>
                    <a class="badge badge-link" href="<?= htmlspecialchars($backFromPrintUrl, ENT_QUOTES, 'UTF-8') ?>">Kembali ke Daftar</a>
                <?php else: ?>
                    <span class="badge badge-neutral"><?= htmlspecialchars($currentUser['full_name'], ENT_QUOTES, 'UTF-8') ?></span>
                    <?php if (canViewArchive($currentUser)): ?>
                        <a class="badge badge-link" href="<?= $isArchivePage ? 'index.php' : 'index.php?page=arsip' ?>">
                            <?= $isArchivePage ? 'Halaman Utama' : 'Halaman Arsip' ?>
                        </a>
                    <?php endif; ?>
                    <?php if (canViewPaymentStatus($currentUser)): ?>
                        <a class="badge badge-link" href="<?= $isPaymentPage ? 'index.php' : 'index.php?page=pembayaran' ?>">
                            <?= $isPaymentPage ? 'Input Nota' : 'Status Pembayaran' ?>
                        </a>
                    <?php endif; ?>
                    <?php if (canManageUsers($currentUser)): ?>
                        <a class="badge badge-link" href="users.php">Kelola User</a>
                    <?php endif; ?>
                    <a class="badge badge-link" href="logout.php">Logout</a>
                <?php endif; ?>
            </div>
            <div class="hero-actions">
                <?php if ($isPrintMode): ?>
                    <button type="button" class="btn btn-primary" onclick="window.print()">Print Sekarang</button>
                    <p class="install-hint">Format cetak A4 landscape dengan pemisah per tanggal pembuatan.</p>
                <?php else: ?>
                    <button type="button" class="btn btn-primary btn-install" id="installAppButton" hidden>Install App</button>
                    <p class="install-hint" id="installHint">Bisa dipasang ke layar utama Android dari browser.</p>
                <?php endif; ?>
            </div>
        </section>

        <?php if (!$isPrintMode): ?>
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
        <?php endif; ?>

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

        <?php if ($filterErrors !== []): ?>
            <div class="flash error">
                <strong>Filter tanggal belum bisa dipakai:</strong>
                <ul>
                    <?php foreach ($filterErrors as $error): ?>
                        <li><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <?php if ($isPrintMode): ?>
            <section class="card print-sheet">
                <div class="print-sheet-head">
                    <div>
                        <h2><?= htmlspecialchars($printTitle, ENT_QUOTES, 'UTF-8') ?></h2>
                        <p>Urutan cetak berdasarkan tanggal pembuatan nota.</p>
                    </div>
                    <div class="print-meta">
                        <span>Total data: <?= (int)count($rows) ?></span>
                        <span>Dicetak: <?= htmlspecialchars(formatDateTimeId((new DateTimeImmutable())->format('Y-m-d H:i:s')), ENT_QUOTES, 'UTF-8') ?></span>
                        <span>Filter status: <?= htmlspecialchars($filters['status'] === '' ? 'Semua Status' : ($filters['status'] === 'sudah_bayar' ? 'Lunas' : 'Masih Hutang'), ENT_QUOTES, 'UTF-8') ?></span>
                        <span>Range tanggal: <?= htmlspecialchars(($filters['created_from'] !== '' || $filters['created_until'] !== '') ? (($filters['created_from'] !== '' ? $filters['created_from'] : '...') . ' s/d ' . ($filters['created_until'] !== '' ? $filters['created_until'] : '...')) : 'Semua tanggal', ENT_QUOTES, 'UTF-8') ?></span>
                        <span>Cari: <?= htmlspecialchars($filters['q'] !== '' ? $filters['q'] : 'Semua data', ENT_QUOTES, 'UTF-8') ?></span>
                    </div>
                </div>

                <div class="table-wrap print-table-wrap">
                    <table class="print-table">
                        <thead>
                            <tr>
                                <th>No</th>
                                <th>Outlet</th>
                                <th>Ket.</th>
                                <th>Tanggal Nota</th>
                                <?php if ($isArchivePage): ?>
                                    <th>Diarsipkan</th>
                                <?php endif; ?>
                                <th>Nilai Nota</th>
                                <th>Dibayar</th>
                                <th>Sisa Hutang</th>
                                <?php if ($isPaymentPage): ?>
                                    <th>Status</th>
                                <?php endif; ?>
                                <th>Sales</th>
                                <th>Pengirim</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($rows === []): ?>
                                <tr>
                                    <td colspan="<?= $printColumnCount ?>" class="empty-state">Belum ada data untuk dicetak.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($printRowsByDate as $printDateLabel => $printDateRows): ?>
                                    <tr class="print-date-row">
                                        <td colspan="<?= $printColumnCount ?>">
                                            Tanggal Pembuatan: <?= htmlspecialchars($printDateLabel, ENT_QUOTES, 'UTF-8') ?>
                                        </td>
                                    </tr>
                                    <?php foreach ($printDateRows as $row): ?>
                                        <?php
                                        $remainingAmount = getRemainingAmount((int)$row['invoice_value'], (int)$row['payment_amount']);
                                        $outletLabel = $row['outlet_name'] . ' (' . $row['outlet_code'] . ')';
                                        ?>
                                        <tr>
                                            <td><?= $printRowNumber ?></td>
                                            <td><?= htmlspecialchars($outletLabel, ENT_QUOTES, 'UTF-8') ?></td>
                                            <td><?= htmlspecialchars($row['note_category'] !== '' ? (string)$row['note_category'] : '-', ENT_QUOTES, 'UTF-8') ?></td>
                                            <td><?= htmlspecialchars((string)$row['invoice_date'], ENT_QUOTES, 'UTF-8') ?></td>
                                            <?php if ($isArchivePage): ?>
                                                <td><?= htmlspecialchars(formatDateTimeId((string)$row['archived_at']), ENT_QUOTES, 'UTF-8') ?></td>
                                            <?php endif; ?>
                                            <td class="print-number-cell"><?= formatNumberId((int)$row['invoice_value']) ?></td>
                                            <td class="print-number-cell"><?= formatNumberId((int)$row['payment_amount']) ?></td>
                                            <td class="print-number-cell"><?= formatNumberId($remainingAmount) ?></td>
                                            <?php if ($isPaymentPage): ?>
                                                <td><?= htmlspecialchars((string)$row['payment_status'] === 'sudah_bayar' ? 'Lunas' : 'Masih Hutang', ENT_QUOTES, 'UTF-8') ?></td>
                                            <?php endif; ?>
                                            <td><?= htmlspecialchars((string)$row['sales_name'], ENT_QUOTES, 'UTF-8') ?></td>
                                            <td><?= htmlspecialchars($row['sender_name'] !== '' ? (string)$row['sender_name'] : '-', ENT_QUOTES, 'UTF-8') ?></td>
                                        </tr>
                                        <?php $printRowNumber++; ?>
                                    <?php endforeach; ?>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </section>
        <?php else: ?>
            <?php if ($isMainPage): ?>
                <section class="card pwa-mobile-menu" id="pwaMobileMenu" hidden>
                    <button type="button" class="btn btn-primary" id="openListViewButton">Daftar Nota</button>
                </section>
            <?php endif; ?>

            <div class="layout">
            <section class="card form-card">
                <?php if ($isMainPage): ?>
                    <div class="section-head">
                        <h2><?= (int)$formData['id'] > 0 ? 'Edit Nota' : 'Input Nota Baru' ?></h2>
                        <?php if ((int)$formData['id'] > 0): ?>
                            <a class="text-link" href="index.php">Batal edit</a>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

                <?php if ($isArchivePage): ?>
                    <div class="archive-panel">
                        <a class="btn btn-secondary" href="index.php">Kembali ke Halaman Utama</a>
                    </div>
                <?php elseif ($isPaymentPage): ?>
                    <div class="archive-panel">
                        <a class="btn btn-secondary" href="index.php">Kembali ke Input Nota</a>
                    </div>
                <?php elseif (!canCreateNote($currentUser)): ?>
                    <div class="archive-panel">
                    </div>
                <?php else: ?>
                    <form method="post" class="nota-form">
                        <input type="hidden" name="action" value="save">
                        <input type="hidden" name="id" value="<?= (int)$formData['id'] ?>">
                        <input type="hidden" name="page" value="">

                        <div class="field-grid">
                            <label>
                                <span>Tanggal</span>
                                <input type="text" value="<?= htmlspecialchars(formatDateId((string)$formData['created_at']), ENT_QUOTES, 'UTF-8') ?>" readonly>
                            </label>

                            <label>
                                <span>Nama Sales</span>
                                <input type="text" name="sales_name" data-lowercase-name value="<?= htmlspecialchars((string)$formData['sales_name'], ENT_QUOTES, 'UTF-8') ?>" placeholder="Nama sales" autocapitalize="off" required>
                            </label>

                            <label>
                                <span>Kode Outlet</span>
                                <input type="text" name="outlet_code" value="<?= htmlspecialchars((string)$formData['outlet_code'], ENT_QUOTES, 'UTF-8') ?>" placeholder="Mis. OTL-001" required>
                            </label>

                            <label>
                                <span>Nama Outlet</span>
                                <input type="text" name="outlet_name" data-lowercase-name value="<?= htmlspecialchars((string)$formData['outlet_name'], ENT_QUOTES, 'UTF-8') ?>" placeholder="Nama toko / outlet" autocapitalize="off" required>
                            </label>

                            <label>
                                <span>Tanggal Nota</span>
                                <input type="text" name="invoice_date" value="<?= htmlspecialchars((string)$formData['invoice_date'], ENT_QUOTES, 'UTF-8') ?>" placeholder="Tulis bebas" required>
                            </label>

                            <label>
                                <span>Nilai Nota</span>
                                <input type="text" name="invoice_value" data-currency data-role="invoice-value" value="<?= htmlspecialchars((string)$formData['invoice_value'], ENT_QUOTES, 'UTF-8') ?>" placeholder="Contoh: 1250000" inputmode="numeric" required>
                            </label>

                            <label>
                                <span>Keterangan</span>
                                <select name="note_category" required>
                                    <option value="">Pilih keterangan</option>
                                    <?php foreach ($noteCategoryOptions as $noteCategoryValue => $noteCategoryLabel): ?>
                                        <option value="<?= htmlspecialchars($noteCategoryValue, ENT_QUOTES, 'UTF-8') ?>" <?= (string)$formData['note_category'] === $noteCategoryValue ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($noteCategoryLabel, ENT_QUOTES, 'UTF-8') ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
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
                        <h2><?= $isArchivePage ? 'Daftar Arsip Nota' : ($isPaymentPage ? 'Status Pembayaran' : 'Daftar Nota') ?></h2>
                        <span class="table-count"><?= count($rows) ?> data</span>
                    </div>
                    <?php if ($isMainPage): ?>
                        <button type="button" class="btn btn-secondary mobile-list-back" id="closeListViewButton">Kembali ke Input</button>
                    <?php endif; ?>
                </div>

                <form method="get" class="filter-bar">
                    <?php if ($isArchivePage): ?>
                        <input type="hidden" name="page" value="arsip">
                    <?php elseif ($isPaymentPage): ?>
                        <input type="hidden" name="page" value="pembayaran">
                    <?php endif; ?>
                    <input type="search" name="q" value="<?= htmlspecialchars($filters['q'], ENT_QUOTES, 'UTF-8') ?>" placeholder="Cari outlet, sales, pengirim" class="filter-search">
                    <select name="status" class="filter-status">
                        <option value="">Semua Status</option>
                        <option value="belum_bayar" <?= $filters['status'] === 'belum_bayar' ? 'selected' : '' ?>>Masih Hutang</option>
                        <option value="sudah_bayar" <?= $filters['status'] === 'sudah_bayar' ? 'selected' : '' ?>>Lunas</option>
                    </select>
                    <div class="filter-date-range">
                        <input type="text" name="created_from" value="<?= htmlspecialchars($filters['created_from'], ENT_QUOTES, 'UTF-8') ?>" placeholder="Dari DD-MM-YYYY" inputmode="numeric">
                        <input type="text" name="created_until" value="<?= htmlspecialchars($filters['created_until'], ENT_QUOTES, 'UTF-8') ?>" placeholder="Sampai DD-MM-YYYY" inputmode="numeric">
                    </div>
                    <div class="filter-actions-row">
                        <button type="submit" class="btn btn-primary">Filter</button>
                        <a href="<?= $isArchivePage ? 'index.php?page=arsip' : ($isPaymentPage ? 'index.php?page=pembayaran' : 'index.php') ?>" class="btn btn-secondary">Reset</a>
                        <a href="<?= htmlspecialchars($printUrl, ENT_QUOTES, 'UTF-8') ?>" class="btn btn-secondary" target="_blank" rel="noopener">Print</a>
                    </div>
                </form>

                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th>Dibuat</th>
                                <th>Outlet</th>
                                <th>Ket.</th>
                                <th>Tanggal Nota</th>
                                <?php if ($isArchivePage): ?>
                                    <th>Diarsipkan</th>
                                <?php endif; ?>
                                <th>Nilai Nota</th>
                                <th>Dibayar</th>
                                <th>Sisa Hutang</th>
                                <?php if ($isPaymentPage): ?>
                                    <th>Status</th>
                                <?php endif; ?>
                                <th>Sales</th>
                                <th>Pengirim</th>
                                <th>Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($rows === []): ?>
                                <tr>
                                    <td colspan="<?= $isArchivePage ? '11' : ($isPaymentPage ? '11' : '10') ?>" class="empty-state">
                                        <?= $isArchivePage ? 'Belum ada data arsip nota dropping.' : ($isPaymentPage ? 'Belum ada data status pembayaran.' : 'Belum ada data nota dropping.') ?>
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($rows as $row): ?>
                                    <?php $remainingAmount = getRemainingAmount((int)$row['invoice_value'], (int)$row['payment_amount']); ?>
                                    <?php $outletLabel = $row['outlet_name'] . ' (' . $row['outlet_code'] . ')'; ?>
                                    <?php $deleteConfirmMessage = 'Hapus permanen data arsip outlet ' . $outletLabel . '?'; ?>
                                    <?php $archiveConfirmMessage = 'Arsipkan data nota outlet ' . $outletLabel . '?'; ?>
                                    <tr>
                                        <td><?= formatDateId($row['created_at']) ?></td>
                                        <td>
                                            <?= htmlspecialchars($outletLabel, ENT_QUOTES, 'UTF-8') ?>
                                        </td>
                                        <td><?= htmlspecialchars($row['note_category'] !== '' ? (string)$row['note_category'] : '-', ENT_QUOTES, 'UTF-8') ?></td>
                                        <td><?= htmlspecialchars((string)$row['invoice_date'], ENT_QUOTES, 'UTF-8') ?></td>
                                        <?php if ($isArchivePage): ?>
                                            <td><?= formatDateTimeId($row['archived_at']) ?></td>
                                        <?php endif; ?>
                                        <td><?= formatNumberId((int)$row['invoice_value']) ?></td>
                                        <td><?= formatNumberId((int)$row['payment_amount']) ?></td>
                                        <td><?= formatNumberId($remainingAmount) ?></td>
                                        <?php if ($isPaymentPage): ?>
                                            <td>
                                                <span class="status-pill <?= htmlspecialchars((string)$row['payment_status'], ENT_QUOTES, 'UTF-8') ?>">
                                                    <?= (string)$row['payment_status'] === 'sudah_bayar' ? 'Lunas' : 'Masih Hutang' ?>
                                                </span>
                                            </td>
                                        <?php endif; ?>
                                        <td><?= htmlspecialchars($row['sales_name'], ENT_QUOTES, 'UTF-8') ?></td>
                                        <td><?= htmlspecialchars($row['sender_name'] !== '' ? $row['sender_name'] : '-', ENT_QUOTES, 'UTF-8') ?></td>
                                        <td>
                                            <div class="action-group">
                                                <?php if ($isArchivePage): ?>
                                                    <?php if (canDeletePermanent($currentUser)): ?>
                                                        <form method="post" onsubmit="return confirm(<?= htmlspecialchars(json_encode($deleteConfirmMessage, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP), ENT_QUOTES, 'UTF-8') ?>);">
                                                            <input type="hidden" name="action" value="delete_permanent">
                                                            <input type="hidden" name="page" value="arsip">
                                                            <input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
                                                            <button type="submit" class="btn btn-small btn-danger">Hapus Permanen</button>
                                                        </form>
                                                    <?php else: ?>
                                                        <span class="btn btn-small btn-disabled">Arsip</span>
                                                    <?php endif; ?>
                                                <?php else: ?>
                                                    <?php if ((int)$row['payment_amount'] > 0 && canPayNote($currentUser, $row)): ?>
                                                        <button
                                                            type="button"
                                                            class="btn btn-small btn-secondary js-open-edit-payment-modal"
                                                            data-id="<?= (int)$row['id'] ?>"
                                                            data-outlet="<?= htmlspecialchars($row['outlet_name'] . ' (' . $row['outlet_code'] . ')', ENT_QUOTES, 'UTF-8') ?>"
                                                            data-invoice-date="<?= htmlspecialchars((string)$row['invoice_date'], ENT_QUOTES, 'UTF-8') ?>"
                                                            data-created-at="<?= htmlspecialchars(formatDateId($row['created_at']), ENT_QUOTES, 'UTF-8') ?>"
                                                            data-invoice-value="<?= (int)$row['invoice_value'] ?>"
                                                            data-current-payment="<?= (int)$row['payment_amount'] ?>"
                                                        >
                                                            Edit Pembayaran
                                                        </button>
                                                    <?php endif; ?>
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
                                                            data-outlet="<?= htmlspecialchars($outletLabel, ENT_QUOTES, 'UTF-8') ?>"
                                                            data-invoice-date="<?= htmlspecialchars((string)$row['invoice_date'], ENT_QUOTES, 'UTF-8') ?>"
                                                            data-sales-name="<?= htmlspecialchars($row['sales_name'], ENT_QUOTES, 'UTF-8') ?>"
                                                            data-sender-name="<?= htmlspecialchars($row['sender_name'], ENT_QUOTES, 'UTF-8') ?>"
                                                        >
                                                            <?= $row['sender_name'] !== '' ? 'Edit Pengirim' : 'Input Pengirim' ?>
                                                        </button>
                                                    <?php endif; ?>
                                                    <?php if (canArchiveNote($currentUser, $row)): ?>
                                                        <form method="post" onsubmit="return confirm(<?= htmlspecialchars(json_encode($archiveConfirmMessage, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP), ENT_QUOTES, 'UTF-8') ?>);">
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
        <?php endif; ?>

        <?php if (!$isArchivePage && !$isPrintMode): ?>
            <?php
            $modalPayment = $payData ? (int)$payData['payment_amount'] : 0;
            $modalInvoice = $payData ? (int)$payData['invoice_value'] : 0;
            $modalRemaining = $payData ? getRemainingAmount($modalInvoice, $modalPayment) : 0;
            $modalIncrement = $paymentFormData['payment_increment'] !== '' ? (int)$paymentFormData['payment_increment'] : $modalRemaining;
            $editModalPayment = $editPaymentData ? (int)$editPaymentData['payment_amount'] : 0;
            $editModalInvoice = $editPaymentData ? (int)$editPaymentData['invoice_value'] : 0;
            $editModalRemaining = $editPaymentData ? getRemainingAmount($editModalInvoice, $editModalPayment) : 0;
            $editModalAmount = $editPaymentFormData['payment_amount'] !== '' ? (int)$editPaymentFormData['payment_amount'] : $editModalPayment;
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
                        <input type="hidden" name="page" value="<?= $isPaymentPage ? 'pembayaran' : '' ?>">
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
                                <span>Tanggal</span>
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

            <div class="pay-modal-overlay<?= $editPaymentData ? ' is-open' : '' ?>" id="editPaymentModalOverlay" aria-hidden="<?= $editPaymentData ? 'false' : 'true' ?>">
                <div class="pay-modal card" role="dialog" aria-modal="true" aria-labelledby="editPaymentModalTitle">
                    <div class="section-head pay-modal-head">
                        <h2 id="editPaymentModalTitle">Edit Pembayaran</h2>
                        <button type="button" class="btn btn-secondary btn-small" id="closeEditPaymentModalButton">Tutup</button>
                    </div>

                    <form method="post" class="nota-form" id="editPaymentModalForm">
                        <input type="hidden" name="action" value="edit_payment">
                        <input type="hidden" name="page" value="<?= $isPaymentPage ? 'pembayaran' : '' ?>">
                        <input type="hidden" name="id" id="editPaymentModalId" value="<?= $editPaymentData ? (int)$editPaymentData['id'] : 0 ?>">

                        <div class="field-grid">
                            <label>
                                <span>Outlet</span>
                                <input type="text" id="editPaymentModalOutlet" value="<?= htmlspecialchars($editPaymentData ? $editPaymentData['outlet_name'] . ' (' . $editPaymentData['outlet_code'] . ')' : '', ENT_QUOTES, 'UTF-8') ?>" readonly>
                            </label>

                            <label>
                                <span>Tanggal Nota</span>
                                <input type="text" id="editPaymentModalInvoiceDate" value="<?= htmlspecialchars($editPaymentData['invoice_date'] ?? '', ENT_QUOTES, 'UTF-8') ?>" readonly>
                            </label>

                            <label>
                                <span>Tanggal</span>
                                <input type="text" id="editPaymentModalCreatedAt" value="<?= htmlspecialchars($editPaymentData ? formatDateId($editPaymentData['created_at']) : '', ENT_QUOTES, 'UTF-8') ?>" readonly>
                            </label>

                            <label>
                                <span>Nilai Nota</span>
                                <input type="text" data-currency id="editPaymentModalInvoiceValue" value="<?= htmlspecialchars((string)$editModalInvoice, ENT_QUOTES, 'UTF-8') ?>" readonly>
                            </label>

                            <label>
                                <span>Total Dibayar</span>
                                <input type="text" name="payment_amount" data-currency data-role="edit-payment-amount" id="editPaymentModalAmount" value="<?= htmlspecialchars((string)$editModalAmount, ENT_QUOTES, 'UTF-8') ?>" placeholder="Total pembayaran" inputmode="numeric" required>
                            </label>

                            <label>
                                <span>Status Setelah Edit</span>
                                <input type="text" data-role="edit-payment-status-preview" id="editPaymentModalStatusPreview" value="<?= $editModalInvoice > 0 && $editModalAmount >= $editModalInvoice ? 'Lunas' : 'Masih Hutang' ?>" readonly>
                            </label>

                            <label>
                                <span>Sisa Setelah Edit</span>
                                <input type="text" data-currency data-role="edit-payment-remaining-after" id="editPaymentModalRemainingAfter" value="<?= htmlspecialchars((string)max(0, $editModalInvoice - $editModalAmount), ENT_QUOTES, 'UTF-8') ?>" readonly>
                            </label>

                            <label>
                                <span>Sisa Saat Ini</span>
                                <input type="text" data-currency id="editPaymentModalRemainingBefore" value="<?= htmlspecialchars((string)$editModalRemaining, ENT_QUOTES, 'UTF-8') ?>" readonly>
                            </label>
                        </div>

                        <div class="form-actions">
                            <button type="submit" class="btn btn-primary">Simpan Edit Pembayaran</button>
                            <button type="button" class="btn btn-secondary" id="cancelEditPaymentModalButton">Batal</button>
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
                        <input type="hidden" name="page" value="<?= $isPaymentPage ? 'pembayaran' : '' ?>">
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
                                <input type="text" name="sender_name" id="senderModalSenderName" data-lowercase-name value="<?= htmlspecialchars($modalSenderValue, ENT_QUOTES, 'UTF-8') ?>" placeholder="Nama team gudang / pengirim" autocapitalize="off" required>
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
        const editPaymentModalOverlay = document.getElementById('editPaymentModalOverlay');
        const editPaymentModalIdInput = document.getElementById('editPaymentModalId');
        const editPaymentModalOutletInput = document.getElementById('editPaymentModalOutlet');
        const editPaymentModalInvoiceDateInput = document.getElementById('editPaymentModalInvoiceDate');
        const editPaymentModalCreatedAtInput = document.getElementById('editPaymentModalCreatedAt');
        const editPaymentModalInvoiceValueInput = document.getElementById('editPaymentModalInvoiceValue');
        const editPaymentModalAmountInput = document.getElementById('editPaymentModalAmount');
        const editPaymentModalStatusPreview = document.getElementById('editPaymentModalStatusPreview');
        const editPaymentModalRemainingBeforeInput = document.getElementById('editPaymentModalRemainingBefore');
        const editPaymentModalRemainingAfterInput = document.getElementById('editPaymentModalRemainingAfter');
        const closeEditPaymentModalButton = document.getElementById('closeEditPaymentModalButton');
        const cancelEditPaymentModalButton = document.getElementById('cancelEditPaymentModalButton');
        const editPaymentModalTriggers = document.querySelectorAll('.js-open-edit-payment-modal');
        const senderModalOverlay = document.getElementById('senderModalOverlay');
        const senderModalIdInput = document.getElementById('senderModalId');
        const senderModalOutletInput = document.getElementById('senderModalOutlet');
        const senderModalInvoiceDateInput = document.getElementById('senderModalInvoiceDate');
        const senderModalSalesNameInput = document.getElementById('senderModalSalesName');
        const senderModalSenderNameInput = document.getElementById('senderModalSenderName');
        const closeSenderModalButton = document.getElementById('closeSenderModalButton');
        const cancelSenderModalButton = document.getElementById('cancelSenderModalButton');
        const senderModalTriggers = document.querySelectorAll('.js-open-sender-modal');
        const lowercaseNameInputs = document.querySelectorAll('[data-lowercase-name]');

        const formatNumber = (value) => {
            const digits = value.replace(/\D/g, '');
            if (!digits) {
                return '';
            }

            return new Intl.NumberFormat('id-ID').format(Number(digits));
        };

        const parseDigits = (value) => Number((value || '').replace(/\D/g, '')) || 0;
        const normalizeLowercaseName = (value) => value.toLocaleLowerCase('id-ID');
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

        const updateEditPaymentPreview = () => {
            if (!editPaymentModalInvoiceValueInput || !editPaymentModalAmountInput || !editPaymentModalStatusPreview || !editPaymentModalRemainingAfterInput || !editPaymentModalRemainingBeforeInput) {
                return;
            }

            const invoiceValue = parseDigits(editPaymentModalInvoiceValueInput.value);
            const totalPayment = parseDigits(editPaymentModalAmountInput.value);
            const remainingAfter = Math.max(0, invoiceValue - totalPayment);

            editPaymentModalRemainingAfterInput.value = formatNumber(String(remainingAfter));
            editPaymentModalStatusPreview.value = totalPayment >= invoiceValue ? 'Lunas' : 'Masih Hutang';
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

        const openEditPaymentModal = (payload) => {
            if (!editPaymentModalOverlay || !editPaymentModalIdInput || !editPaymentModalOutletInput || !editPaymentModalInvoiceDateInput || !editPaymentModalCreatedAtInput || !editPaymentModalInvoiceValueInput || !editPaymentModalAmountInput || !editPaymentModalRemainingBeforeInput) {
                return;
            }

            editPaymentModalIdInput.value = payload.id || '';
            editPaymentModalOutletInput.value = payload.outlet || '';
            editPaymentModalInvoiceDateInput.value = payload.invoiceDate || '';
            editPaymentModalCreatedAtInput.value = payload.createdAt || '';
            editPaymentModalInvoiceValueInput.value = formatNumber(String(payload.invoiceValue || 0));
            editPaymentModalAmountInput.value = formatNumber(String(payload.currentPayment || 0));
            editPaymentModalRemainingBeforeInput.value = formatNumber(String(Math.max(0, (payload.invoiceValue || 0) - (payload.currentPayment || 0))));

            editPaymentModalOverlay.classList.add('is-open');
            editPaymentModalOverlay.setAttribute('aria-hidden', 'false');
            document.body.classList.add('modal-open');
            updateEditPaymentPreview();
            editPaymentModalAmountInput.focus();
            editPaymentModalAmountInput.select();
        };

        const closePayModal = () => {
            if (!payModalOverlay) {
                return;
            }

            payModalOverlay.classList.remove('is-open');
            payModalOverlay.setAttribute('aria-hidden', 'true');
            if ((!senderModalOverlay || !senderModalOverlay.classList.contains('is-open')) && (!editPaymentModalOverlay || !editPaymentModalOverlay.classList.contains('is-open'))) {
                document.body.classList.remove('modal-open');
            }
        };

        const closeEditPaymentModal = () => {
            if (!editPaymentModalOverlay) {
                return;
            }

            editPaymentModalOverlay.classList.remove('is-open');
            editPaymentModalOverlay.setAttribute('aria-hidden', 'true');

            if ((!payModalOverlay || !payModalOverlay.classList.contains('is-open')) && (!senderModalOverlay || !senderModalOverlay.classList.contains('is-open'))) {
                document.body.classList.remove('modal-open');
            }
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

            if ((!payModalOverlay || !payModalOverlay.classList.contains('is-open')) && (!editPaymentModalOverlay || !editPaymentModalOverlay.classList.contains('is-open'))) {
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
                updateEditPaymentPreview();
            });
        });

        lowercaseNameInputs.forEach((input) => {
            input.addEventListener('input', (event) => {
                const element = event.target;
                const caretStart = element.selectionStart ?? element.value.length;
                const normalizedValue = normalizeLowercaseName(element.value);

                element.value = normalizedValue;

                if (typeof element.setSelectionRange === 'function') {
                    element.setSelectionRange(caretStart, caretStart);
                }
            });
        });

        updatePayPreview();
        updateEditPaymentPreview();

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

        editPaymentModalTriggers.forEach((button) => {
            button.addEventListener('click', () => {
                openEditPaymentModal({
                    id: button.dataset.id,
                    outlet: button.dataset.outlet,
                    invoiceDate: button.dataset.invoiceDate,
                    createdAt: button.dataset.createdAt,
                    invoiceValue: Number(button.dataset.invoiceValue || 0),
                    currentPayment: Number(button.dataset.currentPayment || 0),
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

        if (closeEditPaymentModalButton) {
            closeEditPaymentModalButton.addEventListener('click', closeEditPaymentModal);
        }

        if (cancelEditPaymentModalButton) {
            cancelEditPaymentModalButton.addEventListener('click', closeEditPaymentModal);
        }

        if (editPaymentModalOverlay) {
            editPaymentModalOverlay.addEventListener('click', (event) => {
                if (event.target === editPaymentModalOverlay) {
                    closeEditPaymentModal();
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

            if (event.key === 'Escape' && editPaymentModalOverlay && editPaymentModalOverlay.classList.contains('is-open')) {
                closeEditPaymentModal();
            }

            if (event.key === 'Escape' && senderModalOverlay && senderModalOverlay.classList.contains('is-open')) {
                closeSenderModal();
            }
        });

        if (payModalOverlay && payModalOverlay.classList.contains('is-open')) {
            document.body.classList.add('modal-open');
            updatePayPreview();
        }

        if (editPaymentModalOverlay && editPaymentModalOverlay.classList.contains('is-open')) {
            document.body.classList.add('modal-open');
            updateEditPaymentPreview();
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
        const isPaymentPage = document.body.dataset.page === 'pembayaran';
        const shouldKeepListOpen = document.body.dataset.keepListOpen === 'true';
        let deferredInstallPrompt = null;

        const isStandalone = window.matchMedia('(display-mode: standalone)').matches || window.navigator.standalone === true;
        const mobileViewport = window.matchMedia('(max-width: 720px)');
        const landscapeViewport = window.matchMedia('(orientation: landscape)');

        const syncPwaMobileListMode = () => {
            const enableMobilePwaMode = isStandalone && mobileViewport.matches && !landscapeViewport.matches && !isArchivePage && !isPaymentPage;

            document.body.classList.toggle('pwa-mobile-mode', enableMobilePwaMode);

            if (!enableMobilePwaMode) {
                document.body.classList.remove('pwa-list-open');
            }

            if (pwaMobileMenu) {
                pwaMobileMenu.hidden = !enableMobilePwaMode;
            }
        };

        if (isStandalone && installHint) {
            installHint.textContent = '';
            installHint.hidden = true;
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

        if (typeof landscapeViewport.addEventListener === 'function') {
            landscapeViewport.addEventListener('change', syncPwaMobileListMode);
        } else if (typeof landscapeViewport.addListener === 'function') {
            landscapeViewport.addListener(syncPwaMobileListMode);
        }

        syncPwaMobileListMode();

        if (shouldKeepListOpen && document.body.classList.contains('pwa-mobile-mode')) {
            document.body.classList.add('pwa-list-open');

            if (window.history && typeof window.history.replaceState === 'function') {
                const nextUrl = new URL(window.location.href);
                nextUrl.searchParams.delete('keep_list');
                window.history.replaceState({}, document.title, nextUrl.toString());
            }
        }

        if (document.body.classList.contains('print-mode')) {
            window.addEventListener('load', () => {
                window.setTimeout(() => {
                    window.print();
                }, 150);
            });
        }

        if ('serviceWorker' in navigator) {
            window.addEventListener('load', () => {
                navigator.serviceWorker.register('sw.js');
            });
        }
    </script>
</body>
</html>
