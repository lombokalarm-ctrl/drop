<?php
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

$currentUser = requireLogin($pdo);
$errors = [];
$flash = getFlash();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $currentPassword = (string)($_POST['current_password'] ?? '');
    $newPassword = (string)($_POST['new_password'] ?? '');
    $confirmPassword = (string)($_POST['confirm_password'] ?? '');

    if ($currentPassword === '') {
        $errors[] = 'Password saat ini wajib diisi.';
    } elseif (!password_verify($currentPassword, (string)$currentUser['password_hash'])) {
        $errors[] = 'Password saat ini tidak sesuai.';
    }

    if ($newPassword === '') {
        $errors[] = 'Password baru wajib diisi.';
    } elseif (strlen($newPassword) < 8) {
        $errors[] = 'Password baru minimal 8 karakter.';
    }

    if ($confirmPassword === '') {
        $errors[] = 'Konfirmasi password wajib diisi.';
    } elseif ($newPassword !== $confirmPassword) {
        $errors[] = 'Konfirmasi password tidak sama.';
    }

    if ($errors === []) {
        $statement = $pdo->prepare(
            'UPDATE users
            SET password_hash = :password_hash,
                updated_at = :updated_at
            WHERE id = :id'
        );
        $statement->execute([
            'id' => (int)$currentUser['id'],
            'password_hash' => password_hash($newPassword, PASSWORD_DEFAULT),
            'updated_at' => (new DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);

        setFlash('success', 'Password berhasil diganti.');
        redirectTo('account.php');
    }
}
?>
<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Akun Saya - Nota Dropping</title>
    <link rel="stylesheet" href="assets/style.css">
</head>
<body>
    <main class="page">
        <section class="hero card">
            <div>
                <p class="eyebrow">Akun Login</p>
                <h1>Akun Saya</h1>
                <p class="hero-text">Ganti password akun Anda sendiri agar akses tetap aman.</p>
            </div>
            <div class="hero-badges">
                <span class="badge badge-neutral"><?= htmlspecialchars($currentUser['full_name'], ENT_QUOTES, 'UTF-8') ?></span>
                <span class="badge badge-neutral"><?= roleLabel((string)$currentUser['role']) ?></span>
                <a class="badge badge-link" href="index.php">Halaman Nota</a>
                <?php if (canManageUsers($currentUser)): ?>
                    <a class="badge badge-link" href="users.php">Kelola User</a>
                <?php endif; ?>
                <a class="badge badge-link" href="logout.php">Logout</a>
            </div>
        </section>

        <?php if ($flash): ?>
            <div class="flash <?= htmlspecialchars($flash['type'], ENT_QUOTES, 'UTF-8') ?>">
                <?= htmlspecialchars($flash['message'], ENT_QUOTES, 'UTF-8') ?>
            </div>
        <?php endif; ?>

        <?php if ($errors !== []): ?>
            <div class="flash error">
                <strong>Password belum bisa diganti:</strong>
                <ul>
                    <?php foreach ($errors as $error): ?>
                        <li><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <section class="card form-card" style="max-width: 760px; margin: 0 auto;">
            <div class="section-head">
                <h2>Ganti Password</h2>
            </div>

            <form method="post" class="nota-form">
                <div class="field-grid">
                    <label>
                        <span>Username</span>
                        <input type="text" value="<?= htmlspecialchars((string)$currentUser['username'], ENT_QUOTES, 'UTF-8') ?>" readonly>
                    </label>

                    <label>
                        <span>Role</span>
                        <input type="text" value="<?= htmlspecialchars(roleLabel((string)$currentUser['role']), ENT_QUOTES, 'UTF-8') ?>" readonly>
                    </label>

                    <label>
                        <span>Password Saat Ini</span>
                        <input type="password" name="current_password" required>
                    </label>

                    <label>
                        <span>Password Baru</span>
                        <input type="password" name="new_password" required>
                    </label>

                    <label class="full">
                        <span>Konfirmasi Password</span>
                        <input type="password" name="confirm_password" required>
                    </label>
                </div>

                <div class="form-actions">
                    <button type="submit" class="btn btn-primary">Ganti Password</button>
                    <a class="btn btn-secondary" href="index.php">Kembali</a>
                </div>
            </form>
        </section>
    </main>
</body>
</html>
