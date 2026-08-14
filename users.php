<?php
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

$currentUser = requireLogin($pdo);
if (!canManageUsers($currentUser)) {
    setFlash('error', 'Hanya owner yang bisa mengelola user.');
    redirectToIndex();
}

$errors = [];
$editId = isset($_GET['edit']) ? (int)$_GET['edit'] : 0;
$editUser = $editId > 0 ? findUser($pdo, $editId) : null;

if ($editId > 0 && !$editUser) {
    setFlash('error', 'User yang ingin diedit tidak ditemukan.');
    redirectTo('users.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? 'save_user');

    if ($action === 'toggle_user') {
        $id = (int)($_POST['id'] ?? 0);
        $targetUser = $id > 0 ? findUser($pdo, $id) : null;

        if (!$targetUser) {
            setFlash('error', 'User yang ingin diubah statusnya tidak ditemukan.');
        } else {
            $nextStatus = (int)$targetUser['is_active'] === 1 ? 0 : 1;

            if ((int)$targetUser['id'] === (int)$currentUser['id'] && $nextStatus === 0) {
                setFlash('error', 'Owner yang sedang login tidak bisa menonaktifkan akunnya sendiri.');
                redirectTo('users.php');
            }

            if ($nextStatus === 0 && $targetUser['role'] === 'owner' && countActiveOwners($pdo, (int)$targetUser['id']) === 0) {
                setFlash('error', 'Minimal harus ada satu owner aktif di aplikasi.');
                redirectTo('users.php');
            }

            $statement = $pdo->prepare(
                'UPDATE users
                SET is_active = :is_active,
                    updated_at = :updated_at
                WHERE id = :id'
            );
            $statement->execute([
                'id' => (int)$targetUser['id'],
                'is_active' => $nextStatus,
                'updated_at' => (new DateTimeImmutable())->format('Y-m-d H:i:s'),
            ]);

            setFlash('success', $nextStatus === 1 ? 'User berhasil diaktifkan.' : 'User berhasil dinonaktifkan.');
        }

        redirectTo('users.php');
    }

    if ($action !== 'save_user') {
        setFlash('error', 'Aksi user tidak dikenali.');
        redirectTo('users.php');
    }

    $id = (int)($_POST['id'] ?? 0);
    $existingUser = $id > 0 ? findUser($pdo, $id) : null;
    $fullName = trim((string)($_POST['full_name'] ?? ''));
    $username = strtolower(trim((string)($_POST['username'] ?? '')));
    $role = (string)($_POST['role'] ?? 'sales');
    $password = (string)($_POST['password'] ?? '');

    if ($fullName === '') {
        $errors[] = 'Nama lengkap wajib diisi.';
    }

    if ($username === '') {
        $errors[] = 'Username wajib diisi.';
    } elseif (!preg_match('/^[a-z0-9._-]{3,30}$/', $username)) {
        $errors[] = 'Username hanya boleh berisi huruf kecil, angka, titik, garis bawah, atau tanda minus.';
    }

    if (!in_array($role, ['owner', 'admin', 'sales'], true)) {
        $errors[] = 'Role user tidak valid.';
    }

    if ($id === 0 && $password === '') {
        $errors[] = 'Password wajib diisi untuk user baru.';
    }

    if ($id > 0 && !$existingUser) {
        $errors[] = 'User yang ingin diperbarui tidak ditemukan.';
    }

    $duplicateStatement = $pdo->prepare('SELECT id FROM users WHERE username = :username AND id != :id LIMIT 1');
    $duplicateStatement->execute([
        'username' => $username,
        'id' => $id,
    ]);
    if ($duplicateStatement->fetch()) {
        $errors[] = 'Username sudah dipakai user lain.';
    }

    if ($existingUser && $existingUser['role'] === 'owner' && $role !== 'owner' && countActiveOwners($pdo, (int)$existingUser['id']) === 0) {
        $errors[] = 'Role owner terakhir tidak bisa diubah sebelum ada owner aktif lain.';
    }

    if ($errors === []) {
        $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');

        if ($id > 0) {
            $sql = 'UPDATE users
                SET full_name = :full_name,
                    username = :username,
                    role = :role,
                    updated_at = :updated_at';
            $params = [
                'id' => $id,
                'full_name' => $fullName,
                'username' => $username,
                'role' => $role,
                'updated_at' => $now,
            ];

            if ($password !== '') {
                $sql .= ', password_hash = :password_hash';
                $params['password_hash'] = password_hash($password, PASSWORD_DEFAULT);
            }

            $sql .= ' WHERE id = :id';
            $statement = $pdo->prepare($sql);
            $statement->execute($params);
            setFlash('success', 'Data user berhasil diperbarui.');
        } else {
            $statement = $pdo->prepare(
                'INSERT INTO users (full_name, username, password_hash, role, is_active, created_at, updated_at)
                VALUES (:full_name, :username, :password_hash, :role, 1, :created_at, :updated_at)'
            );
            $statement->execute([
                'full_name' => $fullName,
                'username' => $username,
                'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                'role' => $role,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            setFlash('success', 'User baru berhasil ditambahkan.');
        }

        redirectTo('users.php');
    }
}

$statement = $pdo->query(
    "SELECT
        COUNT(*) AS total_user,
        SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END) AS total_aktif,
        SUM(CASE WHEN role = 'owner' THEN 1 ELSE 0 END) AS total_owner,
        SUM(CASE WHEN role = 'admin' THEN 1 ELSE 0 END) AS total_admin,
        SUM(CASE WHEN role = 'sales' THEN 1 ELSE 0 END) AS total_sales
    FROM users"
);
$summary = $statement->fetch() ?: [];

$users = $pdo->query('SELECT * FROM users ORDER BY role ASC, full_name ASC, id ASC')->fetchAll();
$flash = getFlash();
$formData = $editUser ?? [
    'id' => 0,
    'full_name' => '',
    'username' => '',
    'role' => 'sales',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $errors !== [] && (string)($_POST['action'] ?? '') === 'save_user') {
    $formData = [
        'id' => (int)($_POST['id'] ?? 0),
        'full_name' => (string)($_POST['full_name'] ?? ''),
        'username' => (string)($_POST['username'] ?? ''),
        'role' => (string)($_POST['role'] ?? 'sales'),
    ];
}
?>
<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Kelola User - Nota Dropping</title>
    <link rel="stylesheet" href="assets/style.css">
</head>
<body>
    <main class="page">
        <section class="hero card">
            <div>
                <p class="eyebrow">Manajemen Akses</p>
                <h1>Kelola User Login</h1>
                <p class="hero-text">Atur akun dan permission dasar untuk role owner, admin, dan sales.</p>
            </div>
            <div class="hero-badges">
                <span class="badge badge-neutral"><?= htmlspecialchars($currentUser['full_name'], ENT_QUOTES, 'UTF-8') ?></span>
                <span class="badge badge-neutral"><?= roleLabel((string)$currentUser['role']) ?></span>
                <a class="badge badge-link" href="index.php">Halaman Nota</a>
                <a class="badge badge-link" href="logout.php">Logout</a>
            </div>
        </section>

        <section class="summary-grid">
            <article class="card summary-card">
                <span class="summary-label">Total User</span>
                <strong><?= (int)($summary['total_user'] ?? 0) ?></strong>
            </article>
            <article class="card summary-card success">
                <span class="summary-label">User Aktif</span>
                <strong><?= (int)($summary['total_aktif'] ?? 0) ?></strong>
            </article>
            <article class="card summary-card">
                <span class="summary-label">Owner</span>
                <strong><?= (int)($summary['total_owner'] ?? 0) ?></strong>
            </article>
            <article class="card summary-card">
                <span class="summary-label">Admin</span>
                <strong><?= (int)($summary['total_admin'] ?? 0) ?></strong>
            </article>
            <article class="card summary-card">
                <span class="summary-label">Sales</span>
                <strong><?= (int)($summary['total_sales'] ?? 0) ?></strong>
            </article>
        </section>

        <?php if ($flash): ?>
            <div class="flash <?= htmlspecialchars($flash['type'], ENT_QUOTES, 'UTF-8') ?>">
                <?= htmlspecialchars($flash['message'], ENT_QUOTES, 'UTF-8') ?>
            </div>
        <?php endif; ?>

        <?php if ($errors !== []): ?>
            <div class="flash error">
                <strong>Data user belum bisa disimpan:</strong>
                <ul>
                    <?php foreach ($errors as $error): ?>
                        <li><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <div class="layout">
            <section class="card form-card">
                <div class="section-head">
                    <h2><?= (int)$formData['id'] > 0 ? 'Edit User' : 'Tambah User Baru' ?></h2>
                    <?php if ((int)$formData['id'] > 0): ?>
                        <a class="text-link" href="users.php">Batal edit</a>
                    <?php endif; ?>
                </div>

                <form method="post" class="nota-form">
                    <input type="hidden" name="action" value="save_user">
                    <input type="hidden" name="id" value="<?= (int)$formData['id'] ?>">

                    <div class="field-grid">
                        <label>
                            <span>Nama Lengkap</span>
                            <input type="text" name="full_name" value="<?= htmlspecialchars((string)$formData['full_name'], ENT_QUOTES, 'UTF-8') ?>" required>
                        </label>

                        <label>
                            <span>Username</span>
                            <input type="text" name="username" value="<?= htmlspecialchars((string)$formData['username'], ENT_QUOTES, 'UTF-8') ?>" placeholder="contoh: sales.timur" required>
                        </label>

                        <label>
                            <span>Role</span>
                            <select name="role" required>
                                <option value="owner" <?= (string)$formData['role'] === 'owner' ? 'selected' : '' ?>>Owner</option>
                                <option value="admin" <?= (string)$formData['role'] === 'admin' ? 'selected' : '' ?>>Admin</option>
                                <option value="sales" <?= (string)$formData['role'] === 'sales' ? 'selected' : '' ?>>Sales</option>
                            </select>
                        </label>

                        <label>
                            <span>Password <?= (int)$formData['id'] > 0 ? '(kosongkan jika tidak diubah)' : '' ?></span>
                            <input type="password" name="password" <?= (int)$formData['id'] > 0 ? '' : 'required' ?>>
                        </label>
                    </div>

                    <div class="form-actions">
                        <button type="submit" class="btn btn-primary"><?= (int)$formData['id'] > 0 ? 'Simpan User' : 'Tambah User' ?></button>
                        <a class="btn btn-secondary" href="users.php">Reset</a>
                    </div>
                </form>

                <div class="auth-note">
                    <strong>Role saat ini:</strong>
                    <ul>
                        <li><strong>Owner</strong>: akses penuh termasuk kelola user dan hapus permanen arsip.</li>
                        <li><strong>Admin</strong>: kelola nota dan lihat arsip, tanpa kelola user.</li>
                        <li><strong>Sales</strong>: input, edit, bayar, dan arsip untuk nota yang dia buat sendiri.</li>
                    </ul>
                </div>
            </section>

            <section class="card table-card">
                <div class="section-head">
                    <div class="table-head-main">
                        <h2>Daftar User</h2>
                        <span class="table-count"><?= count($users) ?> user</span>
                    </div>
                </div>

                <div class="table-wrap">
                    <table class="user-table">
                        <thead>
                            <tr>
                                <th>Nama</th>
                                <th>Username</th>
                                <th>Role</th>
                                <th>Status</th>
                                <th>Dibuat</th>
                                <th>Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($users as $user): ?>
                                <tr>
                                    <td><?= htmlspecialchars($user['full_name'], ENT_QUOTES, 'UTF-8') ?></td>
                                    <td><code><?= htmlspecialchars($user['username'], ENT_QUOTES, 'UTF-8') ?></code></td>
                                    <td><?= roleLabel((string)$user['role']) ?></td>
                                    <td>
                                        <span class="status-pill <?= (int)$user['is_active'] === 1 ? 'sudah_bayar' : 'belum_bayar' ?>">
                                            <?= (int)$user['is_active'] === 1 ? 'Aktif' : 'Nonaktif' ?>
                                        </span>
                                    </td>
                                    <td><?= formatDateTimeId((string)$user['created_at']) ?></td>
                                    <td>
                                        <div class="action-group">
                                            <a class="btn btn-small btn-secondary" href="users.php?edit=<?= (int)$user['id'] ?>">Edit</a>
                                            <form method="post" onsubmit="return confirm('Ubah status user ini?');">
                                                <input type="hidden" name="action" value="toggle_user">
                                                <input type="hidden" name="id" value="<?= (int)$user['id'] ?>">
                                                <button type="submit" class="btn btn-small <?= (int)$user['is_active'] === 1 ? 'btn-warning' : 'btn-primary' ?>">
                                                    <?= (int)$user['is_active'] === 1 ? 'Nonaktifkan' : 'Aktifkan' ?>
                                                </button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </section>
        </div>
    </main>
</body>
</html>
