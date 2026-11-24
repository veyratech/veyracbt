# 📘 Panduan Penggunaan CBT Online v1.0

**Computer Based Test — Ujian Berbasis Komputer Multi-Tenant**  
Versi 1.1 | Diperbarui: November 2026

---

## Daftar Isi

1. [Tentang Sistem](#1-tentang-sistem)
2. [Persyaratan Sistem](#2-persyaratan-sistem)
3. [Instalasi & Konfigurasi Awal](#3-instalasi--konfigurasi-awal)
4. [Login Pertama & Kredensial Default](#4-login-pertama--kredensial-default)
5. [Panduan Superadmin Platform](#5-panduan-superadmin-platform)
6. [Panduan Admin Sekolah / Guru](#6-panduan-admin-sekolah--guru)
7. [Panduan Siswa](#7-panduan-siswa)
8. [Live Monitor (Proctoring Real-Time)](#8-live-monitor-proctoring-real-time)
9. [Konfigurasi Lanjutan (.env, Mail, SSL)](#9-konfigurasi-lanjutan-env-mail-ssl)
10. [Troubleshooting Umum](#10-troubleshooting-umum)
11. [Keamanan & Best Practice](#11-keamanan--best-practice)

---

## 1. Tentang Sistem

CBT Online adalah aplikasi ujian berbasis komputer multi-tenant yang dibangun dengan **PHP Native + MySQL (PDO)**. Satu instalasi server dapat melayani banyak sekolah (tenant) dengan pemisahan data ketat.

### Fitur Utama

| Role | Kemampuan |
|------|-----------|
| **Superadmin** | Kelola platform, tambah sekolah, lihat semua log audit, backup database |
| **Admin Sekolah** | CRUD siswa, kelas, mata pelajaran; kelola bank soal; buat & jadwalkan ujian; live monitor |
| **Guru** | Akses terbatas: input soal, buat bank soal, lihat hasil ujian siswa sendiri |
| **Siswa** | Login NIS + password/token; pengerjaan ujian; latihan mandiri; riwayat nilai |
| **Proctor** | Pantau ujian secara real-time tanpa akses konfigurasi |

### Arsitektur Multi-Tenant
- Setiap sekolah memiliki `tenant_id` unik
- Semua query dibatasi per-tenant (mencegah IDOR lintas-sekolah)
- Session & CSRF token terikat tenant aktif

---

## 2. Persyaratan Sistem

### Server (Production / Hosting)
| Komponen | Minimum | Disarankan |
|----------|---------|------------|
| PHP | 8.1 | 8.3+ |
| MySQL / MariaDB | 5.7 / 10.5 | 8.0 / 10.11 |
| Web Server | Apache / Nginx | Apache (membutuhkan `.htaccess`) |
| Ekstensi PHP | `pdo_mysql`, `fileinfo` | `pdo_mysql`, `mbstring`, `fileinfo` |
| Memory | 256 MB | 512 MB+ |

### Browser Klien (Siswa & Admin)
- Chrome 90+, Firefox 88+, Safari 14+, Edge 90+
- Kamera diaktifkan untuk fitur proctoring (opsional)
- JavaScript aktif (wajib)
- Pop-up blocker dinonaktifkan untuk halaman ujian

---

## 3. Instalasi & Konfigurasi Awal

### A. Di XAMPP (Local Development)

```
1. Extract folder CBT Online ke C:\xampp\htdocs\cbtonline\
2. Buka XAMPP Control Panel → Start Apache + MySQL
3. Buka phpMyAdmin: http://localhost/phpmyadmin
4. Buat database baru, misalnya: cbtonline_db
5. Import file database/schema.sql ke database tersebut
6. Import file database/seed-data.sql untuk data contoh (opsional)
7. Buka browser: http://localhost/cbtonline/install.php
8. Ikuti wizard instalasi → selesai
```

### B. Di Hosting / VPS Production

```
1. Upload seluruh isi folder project ke root domain Anda (public_html / htdocs)
   Pastikan struktur folder tetap utuh seperti di package deploy.
2. Buat database via cPanel / CLI dan import schema.sql + seed-data.sql
3. Edit file .env atau config/database.php sesuai kredensial DB hosting:
     CBT_DB_HOST=localhost
     CBT_DB_NAME=nama_database
     CBT_DB_USER=user_database
     CBT_DB_PASS=sandi_database
4. Buka https://domain-anda.com/install.php → ikuti wizard
5. Setelah berhasil, installer terkunci otomatis (installed.lock)
```

### C. Konfigurasi Email (Opsional — untuk notifikasi)

Edit file `.env`:

```env
CBT_MAIL_FROM=noreply@sekolah.sch.id
CBT_MAIL_NAME=CBT SMA Teladan
CBT_SMTP_HOST=smtp.gmail.com
CBT_SMTP_PORT=587
CBT_SMTP_USER=your-email@gmail.com
CBT_SMTP_PASS=your-app-password
```

> Gmail menggunakan App Password, bukan sandi akun biasa. Aktifkan 2FA terlebih dahulu.

---

## 4. Login Pertama & Kredensial Default

Setelah instalasi, gunakan kredensial berikut (dari seed data):

### Superadmin Platform
| Field | Nilai |
|-------|-------|
| Username | `admin` |
| Sandi | `password` |
| URL | `https://domain.kamu/superadmin` |

### Admin Sekolah (Demo Tenant: SMA Teladan Unggulan)
| Field | Nilai |
|-------|-------|
| Username | `admin_smatel` |
| Sandi | `password` |
| URL | `https://domain.kamu/admin` |

### Guru (Demo)
| Field | Nilai |
|-------|-------|
| Username | `guru_smatel` |
| Sandi | `password` |

### Siswa (Demo)
| Field | Nilai |
|-------|-------|
| NIS | `2024001` |
| Sandi | `password` |
| URL | `https://domain.kamu/student` |

> ⚠️ **PENTING:** Segera ganti semua sandi default setelah instalasi sebelum digunakan di lingkungan produksi.

---

## 5. Panduan Superadmin Platform

Akses panel superadmin di `/superadmin`.

### 5.1 Dashboard
Menampilkan ringkasan: jumlah total tenant/sekolah, siswa aktif, ujian berjalan, dan grafikus penggunaan paket.

### 5.2 Kelola Tenant (Sekolah)
- **Tambah Tenant:** Isi kode, slug (untuk subdomain), nama, alamat, no HP, kuota siswa, dan paket (free/pro).
- **Aktif/Nonaktif:** Matikan tenant yang berhenti berlangganan tanpa menghapus datanya.
- **Impersonate:** Masuk sebagai admin tenant tertentu untuk troubleshooting.

### 5.3 Log Aktivitas (`/superadmin/logs.php`)
Audit trail seluruh aktivitas platform: login, pembuatan ujian, perubahan data, dll.
- Filter berdasarkan rentang tanggal
- Cari kata kunci di pesan log
- Export ke CSV untuk pelaporan
- Lihat IP address pengirim log

### 5.4 Pembersihan Log (`/superadmin/logs-cleanup.php`)
Hapus log lama sesuai retensi yang dipilih (30 / 60 / 90 / 180 hari). Konfirmasi dialog muncul sebelum eksekusi.

### 5.5 Backup Database
Download dump `.sql` lengkap database CBT saat ini dari menu backup di panel superadmin.

---

## 6. Panduan Admin Sekolah / Guru

Akses panel admin di `/admin`.

### 6.1 Pengaturan Profil Sekolah
Masuk ke **Pengaturan → Profil Sekolah**:
- Ubah nama sekolah, alamat, telepon
- Upload logo sekolah (max 2MB, format PNG/JPG)
- Atur timezone dan bahasa antarmuka

### 6.2 Manajemen Kelas
Menu **Kelas**: Tambah kelas baru (contoh: "X MIPA 1", "XI IPS 2"). Setiap kelas terkait satu tenant.

### 6.3 Manajemen Mata Pelajaran
Menu **Mata Pelajaran**: Tambah mapel dengan kode singkat (contoh: `BINDO-X` untuk Bahasa Indonesia kelas X).

### 6.4 Jenis Ujian
Menu **Jenis Ujian**: Definisikan kategori ujian (mis. `UTS`, `UAS`, `Quiz`, `Tryout`). Ini menentukan jenis tag pada bank soal dan jurnal.

### 6.5 Manajemen Siswa
Menu **Siswa**:
- **Tambah Manual:** Isi NIS, nama, kelas, gender, email, sandi
- **Impor Massal:** Upload file CSV/XLSX sesuai template yang tersedia
- **Ekspor:** Download data siswa dalam format CSV
- Pencarian & pagination untuk ribuan siswa
- Nonaktifkan siswa tanpa menghapus data

### 6.6 Bank Soal
Menu **Bank Soal**:
- Buat bank soal baru: pilih mata pelajaran, tentukan jumlah soal
- Tambah butir soal manual (pilihan ganda A–E, essay, beserta gambar)
- Impor soal massal via CSV atau Excel (.xlsx)
- Setiap soal: teks, 5 opsi, jawaban benar, poin, pembahasan (opsional)
- Gambar soal diupload ke `storage/uploads/questions/`

### 6.7 Jadwal Ujian
Menu **Jadwal Ujian** → **Buat Ujian Baru**:

| Field | Keterangan |
|-------|-----------|
| Judul Ujian | Contoh: "UTS Bahasa Indonesia Kelas X" |
| Bank Soal | Pilih bank soal sumber |
| Kelas | Kosongkan = semua kelas; isi = hanya kelas terpilih |
| Durasi | Menit (min. 5 menit) |
| Token | Kode unik 6 karakter, siswa masukkan saat login ujian |
| Waktu Mulai / Selesai | Jendela waktu ketersediaan ujian |
| Acak Soal | ✅ = urutan soal diacak tiap siswa |
| Acak Opsi | ✅ = posisi pilihan A-E diacak (analisis butir soal tetap akurat) |
| Jumlah Soal | Kosongkan = semua soal; isi angka = ambil N soal acak dari bank |
| Tampilkan Nilai Langsung | ✅ = siswa lihat skor segera setelah selesai |
| Batas Pelanggaran | Jumlah tab-switch max sebelum layar terkunci (default: 3) |

### 6.8 Rekap Nilai & Analisis
Menu **Hasil Ujian**:
- Tabel rekap nilai semua peserta (NIS, nama, kelas, skor, status)
- **Analisis Butir Soal:** persentase menjawab benar per soal, tingkat kesukaran, distractor analysis (disediakan hanya jika opsi tidak diacak)
- **Cetak Rekap:** generate PDF rekap nilai kelas tertentu
- **Download Excel:** export data nilai ke .xlsx

### 6.9 Monitor Live (Real-Time Proctoring)
Lihat bagian 8 untuk panduan detail.

### 6.10 Keamanan 2 Faktor (TOTP)
Setiap akun admin/guru dapat mengaktifkan Two-Factor Authentication:
- Menu **Profil → Aktifkan 2FA**
- Scan QR code dengan Google Authenticator / Authy
- Masukkan kode 6 digit saat login berikutnya

---

## 7. Panduan Siswa

### 7.1 Login Ujian
1. Buka halaman siswa: `https://domain.sekolah.sch.id/student`
2. Masukkan **NIS** dan **sandim**
3. Jika 2FA aktif, masukkan kode TOTP
4. Di halaman ujian, masukkan **Token Ujian** yang diberikan guru
5. Klik **Mulai Ujian**

### 7.2 Saat Ujian Berlangsung
Halaman ujian menampilkan:
- **Timer** hitung mundur (server-side verified, tidak bisa dimanipulasi)
- **Nomor soal** dan teks pertanyaan
- **Pilihan ganda A–E** (radio button)
- **Navigasi:** tombol Prev / Next / Tandai Ragu-ragu
- **Grid nomor soal** (kwartir): hijau = sudah dijawab, kuning = ragu-ragu, abu-abu = belum
- **Indikator koneksi** (Online / Idle / Offline)
- **Mode Gelap/Terang** toggle
- **Perbesar huruf** (+/-) untuk aksesibilitas

### 7.3 Fitur Anti-Curang
- **Tab Switch Detection:** Pindah ke tab lain atau minimalkan browser dihitung sebagai pelanggaran
- Pelanggaran melebihi batas → layar dikunci otomatis (status: Blocked)
- **Webcam Capture:** Foto taken setiap beberapa detik; admin dapat melihat feed langsung
- **Fullscreen强制:** Saat ujian dimulai, browser akan diminta fullscreen
- **Autosave Jawaban:** Jawaban tersimpan real-time setiap memilih opsi (API save)

### 7.4 Mode Latihan (Tanpa Token)
- Pilih menu "Latihan Mandiri" di dashboard siswa
- Pilih bank soal → kerjakan tanpa batasan waktu & tanpa token
- Nilai & pembahasan langsung ditampilkan setelah selesai
- Tersimpan di riwayat latihan

### 7.5 Hasil & Riwayat
Setelah ujian selesai:
- Skor langsung ditampilkan (jika guru mengaktifkan "Tampilkan Nilai")
- Pembahasan setiap soal (jika guru mengisi explanation)
- Riwayat ujian & latihan tersimpan permanen
- Dapat mencetak/download hasil sebagai PDF

### 7.6 Mengganti Sandi
Menu **Profil → Ganti Sandi**. Sandi lama diperlukan.

---

## 8. Live Monitor (Proctoring Real-Time)

Akses melalui **Monitor Live** di panel admin (`/admin/monitor.php`).

### 8.1 Tampilan Halaman
Halaman monitor terdiri dari tiga area utama:

```
┌─────────────────────────────────────────────────────────────┐
│  [Selector Ujian]  [📢 Pengumuman]  [⚡ Aksi Massal]  [📄 BAU] │
│  Auto Refresh: ☑ 5dtk        ● Connected                    │
├─────────────────────────────────────────────────────────────┤
│  KPI: Total 12  Sedang 8  Online 6  Offline 2  Blokir 1  Selesai 3           │
│  Filter: [Semua(12)] [🟢 Mengerjakan(8)] [🔴 Blokir(1)] [⚠️ Offline(2)] [✅ Selesai(3)]│
│  View: [📋 Tabel] [📹 CCTV Grid]          Token: ABC123 | Max Viol: 3x          │
├──────────────────────────────────────┬──────────────────────┤
│  TABEL PESERTA / CCTV GRID          │  LIVE ACTIVITY FEED  │
│                                      │  • Ahmad - menjawab #5│
│  ┌──────┬──────┬──────┬──────┬──────┬──────┐              │
│  │NIS    │Nama  │Progress│Sisa  │Status│Aksi   │             │
│  ├──────┼──────┼──────┼──────┼──────┼──────┤             │
│  │2024001│Ahmad │ 5/10  │45:23 │ 🟢   │[Kam][+W][Psn][Blo]│
│  │2024002│Siti  │10/10  │--    │ ✅   │-       │             │
│  └──────┴──────┴──────┴──────┴──────┴──────┘             │
└──────────────────────────────────────┴──────────────────────┘
```

### 8.2 Kontrol Monitor

| Tombol | Fungsi |
|--------|--------|
| 📢 **Kirim Pengumuman** | Broadcast pesan ke semua siswa yang sedang ujian |
| ⚡ **Aksi Massal** | Kirim pesan ke semua / block/unblock massal |
| 📄 **Cetak Berita Acara (BAU)** | Generate dokumen resmi pelaksanaan ujian |
| ☑ **Auto Refresh** | Polling ulang data otomatis setiap 5 detik |
| Filter chips | Tampilkan hanya peserta dengan status tertentu |
| 📋 / 📹 View Toggle | Tampilan tabel tradisional atau grid kamera CCTV |
| 📷 **Kamera** | Buka modal feed webcam live siswa |
| **+Waktu** | Berikan kompensasi waktu ekstra (menit) |
| **Pesan** | Kirim pesan privat ke satu siswa |
| **Blokir/Buka Kunci** | Kunci/buka layar siswa yang melanggar |
| **Kumpulkan** | Paksa kumpulkan jawaban siswa sekarang |
| **Reset** | Hapus seluruh sesi & jawaban, siswa bisa ulangi |

### 8.3 Notifikasi Suara
Monitor memutar audio berbeda secara otomatis:
- **Beep pendek** → siswa baru masuk ujian
- **Nada naik** → siswa menyelesaikan ujian
- **Nada turun** → siswa terblokir karena pelanggaran

### 8.4 Cetak Berita Acara Ujian (BAU)
URL khusus: `?print_bau=1` menghasilkan dokumen PDF berisi:
- Identitas ujian, kelas, mata pelajaran
- Daftar hadir peserta (nama, NIS, tanda tangan)
- Rekap pelanggaran
- Tanda tangan pengawas & kepala sekolah

---

## 9. Konfigurasi Lanjutan (.env, Mail, SSL)

### 9.1 File .env
Duplikat `.env.example` menjadi `.env` dan isi:

```env
# Database
CBT_DB_HOST=localhost
CBT_DB_NAME=cbtonline_db
CBT_DB_USER=root
CBT_DB_PASS=

# Base URLs (WAJIB diisi untuk production)
CBT_BASE_URL=https://cbt.sekolah.sch.id
CURRENT_APP_URL=https://cbt.sekolah.sch.id

# Session
CBT_SESSION_LIFETIME=1440

# Mail (SMTP)
CBT_MAIL_FROM=noreply@sekolah.sch.id
CBT_MAIL_NAME=CBT SMA Teladan
CBT_SMTP_HOST=smtp.gmail.com
CBT_SMTP_PORT=587
CBT_SMTP_USER=your@email.com
CBT_SMTP_PASS=xxxx xxxx xxxx xxxx
```

### 9.2 HTTPS / SSL di Cloudflare
Untuk domain seperti `cbt.veyracreative.site`:
1. Daftarkan domain di Cloudflare
2. Set DNS A record: `cbt` → IP server hosting
3. Di Cloudflare SSL/TLS settings, pilih **Full** atau **Flexible**
4. Untuk API ping working normal, pastikan origin server memiliki valid SSL certificate

### 9.3 Rate Limiting
Login gagal 5 kali dalam 15 menit → IP di-rate-limit (HTTP 429). Reset otomatis setelah 15 menit.

---

## 10. Troubleshooting Umum

### Masalah: "Error: Call to undefined function jsesc()"
**Penyebab:** Versi PHP < 8.1 atau ekstensi missing.  
**Solusi:** Upgrade PHP ke 8.1+. Pada PHP 8.3+ masalah sudah terselesaikan (sudah diganti `htmlspecialchars`).

### Masalah: "ERR_INTERNET_DISCONNECTED" pada `/api/ping.php`
**Penyebab:** Cloudflare DNS A-record `cbt.` belum mengarah ke IP hosting, atau mode SSL/TLS salah.  
**Solusi:** Periksa Cloudflare Dashboard → DNS → pastikan `cbt.veyracreative.site` point ke IP hosting. Set SSL/TLS ke **Full**.

### Masalah: "525 SSL Handshake Failed"
**Penyebab:** Origin server tidak memiliki sertifikat SSL yang valid.  
**Solusi:** Pasang Let's Encrypt di server hosting, atau set Cloudflare SSL/TLS ke **Flexible** (hanya jika tidak ada SSL di origin).

### Masalah: CSP block beacon Cloudflare
**Penyebab:** Content Security Policy di `.htaccess` memblokir request ke `cloudflareinsights.com`.  
**Solusi:** Pastikan baris berikut ada di `.htaccess`:
```
Header set Content-Security-Policy "script-src 'self' 'unsafe-inline' https://static.cloudflareinsights.com;"
```

### Masalah: Webcam tidak muncul di monitor
**Penyebab:** Siswa menolak izin kamera saat ujian.  
**Solusi:** Cek badge "Izin Kamera Ditolak" di kartu CCTV. Admin dapat memberikan instruksi ulang ke siswa.

### Masalah: Soal tidak tersimpan / jawaban hilang
**Penyebab:** Koneksi internet putus saat ujian.  
**Solusi:** Engine memiliki offline queue — jawaban disimpan lokal dan dikirim saat koneksi pulih. Pastikan koneksi stabil selama ujian.

### Masalah: Installer tidak bisa diakses setelah instalasi
**Penyebab:** File `config/installed.lock` sudah dibuat. Ini perilaku aman.  
**Solusi:** Jangan hapus lock file. Gunakan panel admin untuk mengubah konfigurasi.

### Masalah: Zoom kamera siswa tampil hitam
**Penyebab:** Frame kamera belum tersinkronisasi (delay ≤ 20 detik).  
**Solusi:** Tunggu beberapa saat; frame akan muncul otomatis ketika siswa mengirim foto pertama.

---

## 11. Keamanan & Best Practice

### Untuk Admin / Operator
1. **Ganti semua sandi default** segera setelah instalasi
2. **Aktifkan 2FA** pada semua akun admin & guru
3. **Batasi akses IP** di `.htaccess` untuk folder `admin/` jika memungkinkan
4. **Backup database rutin** via menu superadmin atau cron job
5. **Jangan bagikan token ujian** secara publik (mis. grup WhatsApp terbuka)

### Untuk Guru
1. Verifikasi bank soal sebelum membuat jadwal ujian
2. Gunakan **acak soal** dan **acak opsi** untuk meminimalkan kecurangan
3. Atur **batas pelanggaran** sesuai ketetapan sekolah (3x rekomendasi)
4. Pantau monitor live selama ujian berlangsung
5. Cetak BAU sebagai dokumentasi resmi setelah ujian berakhir

### Untuk Siswa
1. Pastikan koneksi internet stabil sebelum memulai ujian
2. Jangan pindah tab atau minimalkan browser selama ujian
3. Izinkan akses kamera jika diminta (untuk proctoring)
4. Kerjakan soal dengan teliti — jawaban tersimpan otomatis
5. Kumpulkan ujian sebelum waktu habis

### Untuk Superadmin
1. Review log aktivitas secara berkala
2. Nonaktifkan tenant yang tidak aktif (>6 bulan)
3. Lakukan pembersihan log sesuai kebijakan retensi (30/60/90 hari)
4. Monitor penggunaan kuota siswa per tenant
5. Simpan backup database minimal mingguan

---

*Catatan: Dokumen ini dibuat berdasarkan versi CBT Online v1.1. Fitur dan tampilan dapat berubah sesuai perkembangan versi berikutnya.*
