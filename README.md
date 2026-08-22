# Aplikasi Pencatatan Pembayaran Nota Dropping

Aplikasi web sederhana berbasis PHP untuk mencatat nota dropping saat outlet belum bisa membayar tetapi tetap ingin order barang baru, lengkap dengan login user, role permission dasar, serta dukungan database SQLite atau MySQL/MariaDB.

## Fitur

- Input data nota dropping
- Edit data nota
- Login user dengan role `owner`, `admin`, dan `sales`
- Kelola user oleh owner
- Status otomatis `Lunas` atau `Masih Hutang`
- Filter berdasarkan status bayar
- Pencarian outlet, sales, atau pengirim
- Ringkasan total data, total nilai nota, total dibayar, dan outstanding
- Bisa di-install sebagai PWA di HP Android
- Fitur arsip nota sebelum hapus permanen
- Dukungan database `SQLite` dan `MySQL/MariaDB`

## Field yang dicatat

- Kode outlet
- Nama outlet
- Tanggal nota
- Nilai nota
- Nama sales
- Status bayar otomatis
- Pengirim
- Tanggal pembuatan nota dropping otomatis

## Cara menjalankan

1. Salin file konfigurasi:
   - Windows: `copy .env.example .env`
   - Linux: `cp .env.example .env`
2. Untuk lokal cepat, biarkan `DB_CONNECTION=sqlite`.
3. Pastikan Apache XAMPP aktif.
4. Buka `http://localhost/drop/login.php`.
5. Login awal gunakan:
   - Username: `owner`
   - Password: `owner123`
6. Setelah login, owner bisa menambah user baru dari halaman `Kelola User`.
7. Data nota akan otomatis tersimpan ke database yang dipilih di `.env`.
8. Di browser Android, gunakan tombol `Install App` atau menu browser `Install app / Add to Home screen`.
9. Tombol `Arsipkan` memindahkan data dari halaman utama ke halaman arsip.
10. Hapus permanen hanya tersedia untuk role owner di halaman arsip.
11. Jika dibuka sebagai PWA di smartphone, daftar nota disembunyikan dulu dan dibuka lewat menu `Daftar Nota`.
12. Aksi `Bayar` memakai box melayang terpisah, bukan field di card input nota.

## Konfigurasi Database

### Opsi 1: SQLite

Gunakan untuk development lokal atau kebutuhan kecil:

```env
DB_CONNECTION=sqlite
DB_DATABASE=storage/database.sqlite
```

### Opsi 2: MySQL / MariaDB

Gunakan untuk production multi-user:

```env
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=drop
DB_USERNAME=drop_user
DB_PASSWORD=ganti-password-aman
DB_CHARSET=utf8mb4
SQLITE_SOURCE_PATH=storage/database.sqlite
```

Tabel akan dibuat otomatis saat aplikasi pertama kali dijalankan.

## Migrasi SQLite ke MySQL / MariaDB

1. Pastikan database MySQL / MariaDB sudah dibuat.
2. Isi `.env` dengan koneksi MySQL / MariaDB.
3. Pastikan file SQLite lama masih ada di path `SQLITE_SOURCE_PATH`.
4. Jalankan:

```bash
php migrate_sqlite_to_mysql.php
```

Script ini akan:
- membaca data dari SQLite
- mengosongkan tabel target MySQL / MariaDB
- memindahkan user dan nota dropping
- menjaga `id` lama agar relasi user tetap aman

Setelah migrasi selesai, aplikasi akan langsung memakai MySQL / MariaDB karena koneksi di `.env` sudah diarahkan ke sana.

## Catatan

- Format tampilan tanggal adalah `dd-mm-yyyy`.
- Nilai nota ditampilkan dengan pemisah ribuan titik.
- Jika nominal bayar lebih kecil dari nilai nota, status tetap `Masih Hutang`.
- Tanggal pembuatan nota dropping terisi otomatis saat data pertama kali disimpan.
- Untuk pemasangan PWA di Android, aplikasi harus dibuka dari `localhost`, IP lokal, atau domain HTTPS.
- Data arsip tidak tampil di halaman utama, tetapi masih bisa dilihat di halaman arsip sampai dihapus permanen.
- Mode daftar nota terpisah hanya aktif untuk tampilan smartphone yang dibuka sebagai PWA, bukan browser desktop biasa.
- Card input nota baru tidak lagi memiliki kolom nominal bayar; pembayaran dilakukan dari tombol `Bayar` pada daftar nota.
- Field `pengirim` tidak diisi dari form nota baru, tetapi lewat tombol `Input Pengirim` di daftar nota sesuai role yang diizinkan.
- Role `sales` hanya bisa melihat nota miliknya dan input nota baru dengan nama sales diisi manual.
- Role `admin` bisa mengelola nota dan melihat arsip, tetapi tidak bisa kelola user.
- Role `owner` punya akses penuh, termasuk kelola user dan hapus permanen arsip.
- File `.env` tidak ikut masuk Git dan harus dibuat manual di server.
