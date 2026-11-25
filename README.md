# CBT Online — Ujian Berbasis Komputer Multi-Tenant

Sistem ujian/ujian sekolah (Computer Based Test) multi-tenant berbasis PHP native + MySQL.
Satu instalasi melayani banyak sekolah (tenant) dengan pemisahan data ketat, portal guru/admin,
portal siswa, proctoring live, dan panel superadmin platform.

## Fitur Utama

**Superadmin Platform**
- Kelola sekolah (tenant): paket, kuota, aktif/nonaktif, impersonate admin sekolah
- Log aktivitas platform (audit trail) & backup database (.sql)
- Filter log berdasarkan rentang tanggal, kata kunci, dan sekolah
- Ekspor log ke CSV
- Pembersihan log lama (superadmin only) — hapus log di atas periode tertentu (30/60/90/180 hari)

**Admin Sekolah / Guru**
- Master data: kelas, mata pelajaran, siswa (CRUD + search + pagination + impor/ekspor CSV/Excel)
- Bank soal + butir soal: pilihan ganda A–E, gambar soal, pembahasan, poin
- Impor butir soal massal via CSV atau Excel .xlsx (ada template)
- Jadwal ujian: token (bisa di-regenerate), durasi, jendela waktu, acak soal & opsi,
  batasi jumlah soal acak per siswa (mis. 40 dari 100), batas pelanggaran
- Live monitor: polling real-time 5 detik, indikator koneksi Connected/Disconnected,
  notifikasi suara saat siswa selesai atau terblokir, animasi baris berubah tanpa reload
- Rekap nilai + analisis butir soal (% benar, kesukaran; distribusi A–E otomatis
  disembunyikan saat opsi ujian diacak) + cetak rekap
- Pengaturan sekolah (profil + logo), akun guru/admin, profil & ganti sandi

**Siswa**
- Login per NIS, join ujian dengan token
- Autosave jawaban, timer server-side, deteksi pelanggaran (tab switch)
- Hasil ujian + pembahasan setelah selesai (jika guru mengaktifkannya) + cetak/unduh hasil
- Mode latihan tanpa token: nilai + pembahasan langsung, tersimpan di riwayat latihan
- Riwayat ujian + riwayat latihan (progres per bank), ganti kata sandi

**Keamanan**
- CSRF token di seluruh form, rate-limit login (5 gagal / 15 menit → 429)
- Password hash bcrypt, session regenerate saat login
- `.htaccess` proteksi folder inti (`config/`, `core/`, `database/`) dan blokir eksekusi PHP di upload
- Installer terkunci oleh `config/installed.lock`

## Fitur Baru: Monitor & Logging

### Live Monitor (admin/monitor.php)
- **Polling fetch API**: endpoint `admin/api-monitor.php` mengembalikan JSON peserta ujian setiap 5 detik
- **Indikator koneksi**: titik hijau (Connected) / merah (Disconnected) di header monitor
- **Notifikasi suara**: beep saat siswa baru masuk, nada naik saat selesai, nada turun saat terblokir
- **Animasi baris**: CSS transition `rowFlash` menandai baris yang berubah tanpa reload halaman penuh
- **Auto-refresh toggle**: checkbox menyimpan status di localStorage, polling aktif saat dicentang

### Endpoint API Monitor
- `GET admin/api-monitor.php?exam_id=N`
- Mengembalikan JSON: `exam_id`, `exam_title`, `exam_token`, `total_questions`, `last_update`, `participant_count`, `participants[]`
- Setiap peserta: `id`, `student_name`, `nis`, `class_name`, `answered_count`, `status`, `total_violations`, `start_time`, `ip_address`
- Hanya dapat diakses admin/teacher tenant yang terautentikasi (tenant isolation)

### Log Aktivitas Superadmin (superadmin/logs.php)
- **Filter rentang tanggal**: input `from_date` dan `to_date`
- **Pencarian kata kunci**: kolom `search` mencocokkan bagian pesan log
- **Ekspor CSV**: tombol "Ekspor CSV" mengunduh semua log yang difilter
- **Kolom IP Address**: menampilkan alamat IP pengirim log jika tersedia
- **Jump-to-page**: input angka + tombol Go untuk lompat ke halaman tertentu

### Pembersihan Log (superadmin/logs-cleanup.php)
- Pilih periode retensi: 30, 60, 90, atau 180 hari
- Menampilkan jumlah log yang akan dihapus sebelum eksekusi
- Konfirmasi dialog sebelum penghapusan
- Redirect kembali ke halaman cleanup setelah berhasil

## Persyaratan

- PHP >= 8.1 (dikembangkan & diuji di PHP 8.3, XAMPP)
- MySQL / MariaDB
- ext-pdo_mysql, ext-fileinfo (bawaan XAMPP)

## Instalasi (XAMPP)

1. Salin folder project ke `C:\xampp\htdocs\cbtonline` (atau `htdocs` lain).
2. Buat database, misal `cbtonline_db`, lalu import `database/schema.sql`
   (termasuk data seed sekolah + akun demo).
3. Pastikan kredensial DB di `config/database.php` sesuai XAMPP (`root`, kosong).
4. Buka `http://localhost/cbtonline/install.php` dari browser — instalasi akan
   membuat `config/installed.lock` otomatis. Setelah terkunci, akses installer
   ditolak (403).
5. Selesai. Login dari halaman depan `index.php`.

> Catatan: folder `config/`, `core/`, dan `database/` dilindungi `.htaccess`
> (Apache). Server dev `php -S` tidak memproses `.htaccess` — gunakan Apache
> untuk produksi.

## Kredensial Demo (seed)

> ⚠️ **PENTING**: Ganti semua sandi bawaan setelah instalasi sebelum digunakan di lingkungan produksi.

| Role        | Login                        | Catatan                          |
|-------------|------------------------------|----------------------------------|
| Superadmin  | sesuai konfigurasi           | Panel platform                   |
| Admin sekolah | sesuai konfigurasi         | Tenant sekolah                   |
| Siswa       | NIS dan sandi                | Sesuaikan dengan data yang ditanamkan |

Kredensial default ditetapkan saat instalasi. Segera ubah melalui profil masing-masing akun.

## Struktur Singkat

```
admin/           Panel admin & guru (tenant)
  api-monitor.php  Endpoint JSON untuk polling monitor real-time
  monitor.php      Halaman monitoring live dengan fetch API
superadmin/      Panel pemilik platform
  logs-cleanup.php Halaman pembersihan log lama
  logs.php         Audit trail dengan filter & ekspor CSV
student/         Portal siswa + engine ujian
core/            Database, Auth, Tenant, Helper
config/          Kredensial DB + installed.lock
assets/          CSS/JS + upload (gambar soal, logo)
database/        schema.sql (DDL + seed)
tests/         smoke-test.php, unit-*.php (unit test), load-test.php
install.php      Installer awal (terkunci setelah sukses)
```

## Menjalankan Smoke Test

Smoke test menguji alur lengkap via HTTP (login semua role, CRUD, CSV, CSRF,
rate-limit, sesi ujian, pelanggaran, unblock, upload gambar soal).

```bash
# 1. Nyalakan server dev di root project
php -S 127.0.0.1:8099

# 2. Jalankan test (dari root project)
php tests/smoke-test.php
```

Variabel opsional: `CBT_BASE_URL`, `CBT_DB_HOST`, `CBT_DB_NAME`, `CBT_DB_USER`,
`CBT_DB_PASS`.

> Test mengubah data sementara (buat/hapus kelas, sesi ujian, dll) lalu
> mengembalikannya. Jangan jalankan pada data produksi.

## Menjalankan Unit Test

Unit test berjalan tanpa server HTTP (kecuali Auth/Tenant yang butuh DB).

```bash
# Helper — metode murni, tidak perlu server
php tests/unit-helper.php

# Auth — perlu database terhubung
php tests/unit-auth.php

# Tenant — perlu database terhubung
php tests/unit-tenant.php

# Shuffle options (sudah ada)
php tests/unit-shuffle.php
```

Variabel opsional: `CBT_DB_HOST`, `CBT_DB_NAME`, `CBT_DB_USER`, `CBT_DB_PASS`.
Setiap test melaporkan `PASS` / `FAIL` dan exit code 0 (semua lolos) atau 1 (ada gagal).

## Menjalankan Load Test

Load test membuat request GET ke endpoint-endpoint kunci secara concurrent
menggunakan `curl_multi`. **Tidak mengubah data** (read-only).

```bash
# 1. Nyalakan server dev
php -S 127.0.0.1:8099

# 2. Jalankan load test
php tests/load-test.php --url http://127.0.0.1:8099 --requests 100 --concurrent 10

# Dengan autentikasi siswa
php tests/load-test.php --url http://127.0.0.1:8099 --requests 50 --concurrent 5 \
    --user 2024001 --password password
```

Parameter CLI:
| Parameter       | Default   | Keterangan                          |
|-----------------|-----------|-------------------------------------|
| `--url`         | http://127.0.0.1:8099 | Base URL server dev        |
| `--requests`    | 50        | Jumlah total request per endpoint   |
| `--concurrent`  | 10        | Batch concurrent per request (info)|
| `--user`        | –         | NIS siswa untuk pre-login cookie    |
| `--password`    | –         | Sandi siswa                         |

Variabel environment pengganti: `CBT_LOAD_URL`, `CBT_LOAD_REQUESTS`,
`CBT_LOAD_CONCURRENT`, `CBT_LOAD_USER`, `CBT_LOAD_PASSWORD`.

Output: progress bar + tabel per endpoint (Avg, p95, p99 ms) + ringkasan total.

## Catatan Implementasi

- Randomisasi: urutan soal diacak & disimpan di `exam_sessions.question_order`;
  opsi jawaban diacak deterministik (seed sesi+soal) sehingga render dan
  koreksi server selalu konsisten.
- `exam_sessions` tidak memiliki kolom `bank_id` — relasi ke bank soal lewat `exams`.
- PDO memakai emulated prepares; `LIMIT/OFFSET` di-interpolasi sebagai integer
  hasil cast (aman karena bukan dari input pengguna).
- Polling monitor menggunakan `setInterval` 5000ms dengan fetch API; status
  koneksi dilacakvia error handling dan indikator visual.
- Audio notifikasi menggunakan Web Audio API (OscillatorNode) tanpa file eksternal.
- Log cleanup menggunakan query `DELETE` berbasis `created_at < cutoff_date`.
#   v e y r a c b t  
 