<?php
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

$currentUser = getCurrentUser($pdo);
if ($currentUser !== null) {
    redirectToIndex();
}

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = strtolower(trim((string)($_POST['username'] ?? '')));
    $password = (string)($_POST['password'] ?? '');

    if ($username === '') {
        $errors[] = 'Username wajib diisi.';
    }

    if ($password === '') {
        $errors[] = 'Password wajib diisi.';
    }

    if ($errors === []) {
        $statement = $pdo->prepare('SELECT * FROM users WHERE username = :username LIMIT 1');
        $statement->execute(['username' => $username]);
        $user = $statement->fetch();

        if (!$user || (int)$user['is_active'] !== 1 || !password_verify($password, (string)$user['password_hash'])) {
            $errors[] = 'Username atau password tidak valid.';
        } else {
            $_SESSION['user_id'] = (int)$user['id'];
            setFlash('success', 'Login berhasil. Selamat bekerja.');
            redirectToIndex();
        }
    }
}

$flash = getFlash();
?>
<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Login - Nota Dropping</title>
    <link rel="stylesheet" href="assets/style.css">
</head>
<body class="auth-page">
    <main class="auth-shell">
        <section class="card auth-card">
            <p class="eyebrow">Akses Aplikasi</p>
            <h1>Login Nota Dropping</h1>
            <p class="hero-text">Masuk dengan akun user untuk mengatur akses owner, admin, dan sales.</p>

            <?php if ($flash): ?>
                <div class="flash <?= htmlspecialchars($flash['type'], ENT_QUOTES, 'UTF-8') ?>">
                    <?= htmlspecialchars($flash['message'], ENT_QUOTES, 'UTF-8') ?>
                </div>
            <?php endif; ?>

            <?php if ($errors !== []): ?>
                <div class="flash error">
                    <strong>Login belum berhasil:</strong>
                    <ul>
                        <?php foreach ($errors as $error): ?>
                            <li><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <form method="post" class="nota-form">
                <div class="field-grid">
                    <label>
                        <span>Username</span>
                        <input type="text" name="username" value="<?= htmlspecialchars((string)($_POST['username'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" autocomplete="username" required>
                    </label>

                    <label>
                        <span>Password</span>
                        <input type="password" name="password" autocomplete="current-password" required>
                    </label>
                </div>

                <div class="form-actions">
                    <button type="submit" class="btn btn-primary">Login</button>
                </div>
            </form>

            <div class="auth-note">
                <strong>Akun awal otomatis:</strong>
                <div>Username: <code>owner</code></div>
                <div>Password: <code>owner123</code></div>
                <p>Setelah login, owner bisa menambah user baru dan mengganti password dari halaman kelola user.</p>
            </div>
        </section>
    </main>
</body>
</html>
