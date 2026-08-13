# Aplikasi Pencatatan Pembayaran Nota Dropping

Aplikasi web sederhana berbasis PHP + SQLite untuk mencatat nota dropping saat outlet belum bisa membayar tetapi tetap ingin order barang baru, lengkap dengan login user dan role permission dasar.

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
- Penyimpanan lokal otomatis ke `storage/database.sqlite`

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

1. Pastikan Apache XAMPP aktif.
2. Buka `http://localhost/drop/login.php`.
3. Login awal gunakan:
   - Username: `owner`
   - Password: `owner123`
4. Setelah login, owner bisa menambah user baru dari halaman `Kelola User`.
5. Data nota akan otomatis tersimpan ke SQLite saat form disubmit.
6. Di browser Android, gunakan tombol `Install App` atau menu browser `Install app / Add to Home screen`.
7. Tombol `Arsipkan` memindahkan data dari halaman utama ke halaman arsip.
8. Hapus permanen hanya tersedia untuk role owner di halaman arsip.
9. Jika dibuka sebagai PWA di smartphone, daftar nota disembunyikan dulu dan dibuka lewat menu `Daftar Nota`.
10. Aksi `Bayar` memakai box melayang terpisah, bukan field di card input nota.

## Catatan

- Format tampilan tanggal adalah `dd-mm-yyyy`.
- Nilai nota ditampilkan dengan pemisah ribuan titik.
- Jika nominal bayar lebih kecil dari nilai nota, status tetap `Masih Hutang`.
- Tanggal pembuatan nota dropping terisi otomatis saat data pertama kali disimpan.
- Untuk pemasangan PWA di Android, aplikasi harus dibuka dari `localhost`, IP lokal, atau domain HTTPS.
- Data arsip tidak tampil di halaman utama, tetapi masih bisa dilihat di halaman arsip sampai dihapus permanen.
- Mode daftar nota terpisah hanya aktif untuk tampilan smartphone yang dibuka sebagai PWA, bukan browser desktop biasa.
- Card input nota baru tidak lagi memiliki kolom nominal bayar; pembayaran dilakukan dari tombol `Bayar` pada daftar nota.
- Role `sales` hanya bisa melihat dan mengelola nota yang dia buat sendiri.
- Role `admin` bisa mengelola nota dan melihat arsip, tetapi tidak bisa kelola user.
- Role `owner` punya akses penuh, termasuk kelola user dan hapus permanen arsip.
