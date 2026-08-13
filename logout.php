<?php
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

unset($_SESSION['user_id']);
setFlash('success', 'Anda sudah logout dari aplikasi.');
redirectToLogin();
