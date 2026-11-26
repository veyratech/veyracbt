# CBT Online — Panduan Deploy ke cPanel Shared Hosting

## Persiapan di GitHub

Semua commit sudah di-push ke:
```
https://github.com/veyratech/veyracbt.git
```

Pastikan cPanel kamu punya akses **Git Version Control** (biasanya di menu cPanel → Software → Git Version Control).

---

## Langkah 1 — Buat Database di cPanel

1. Buka **cPanel → Databases → MySQL® Databases**
2. Buat database baru, contoh: `username_cbtonline`
3. Buat DB user baru, contoh: `username_cbtuser`, password: `(buat password kuat)`
4. Klik **Add User To Database** → beri privilege **All Privileges**

Catat:
- **DB Name:** `username_cbtonline`
- **DB User:** `username_cbtuser`
- **DB Password:** `(password yang kamu buat)`

---

## Langkah 2 — Setup Git di cPanel

1. Buka **cPanel → Software → Git Version Control**
2. Klik **Add Repository**
3. Isi form:
   - **Repository URL:** `https://github.com/veyratech/veyracbt.git`
   - **Target Directory:** `public_html/cbtonline` (atau `public_html` kalau mau di root domain)
   - **Authentication:** pilih **Personal Access Token**
   - **Token:** (buat PAT di GitHub → Settings → Developer settings → Personal access tokens → Generate new token, centang `repo`)
4. Klik **Create Repository**

> ⚠️ **PENTING:** cPanel akan `git clone` seluruh repo termasuk `vendor/`. Tidak masalah — shared hosting memang butuh `vendor/` karena tidak bisa jalan `composer install`.

---

## Langkah 3 — Buat File `.env` di Hosting

File `.env` **tidak di-commit** ke git (karena tiap server punya kredensial DB berbeda).

1. Buka **cPanel → File Manager** → masuk ke folder `public_html/cbtonline`
2. Klik **New File** → nama: `.env`
3. Buka file `.env` → edit → paste template berikut, sesuaikan nilai:

```ini
# ==============================================================================
# CBT ONLINE — PRODUCTION ENVIRONMENT (cPanel Shared Hosting)
# ==============================================================================

# Database — SESUAIKAN dengan kredensial MySQL di cPanel Anda
# DB host di cPanel biasanya "localhost" atau "127.0.0.1"
CBT_DB_HOST=localhost
CBT_DB_PORT=3306
CBT_DB_NAME=username_cbtonline
CBT_DB_USER=username_cbtuser
CBT_DB_PASS=password_kuat_kamu_di_sini

# Environment Mode
CBT_APP_ENV=production
CBT_APP_DEBUG=false

# Base URL — isi domain CBT kamu (atau kosongkan untuk auto-detect)
# Contoh: https://cbt.sekolah.sch.id  atau  https://veyracreative.site/cbtonline
CBT_BASE_URL=

# Domain aplikasi — sama dengan CBT_BASE_URL tanpa trailing slash
CURRENT_APP_URL=

# Mail / SMTP (opsional — kosongkan jika tidak pakai email)
CBT_SMTP_HOST=smtp.gmail.com
CBT_SMTP_PORT=587
CBT_SMTP_USER=
CBT_SMTP_PASS=
CBT_MAIL_FROM=[EMAIL_REDACTED]
CBT_MAIL_NAME="Veyra CBT Online"

# SSO Secret Key — SAMA dengan SSO_SECRET_KEY di Veyra Educate
SSO_SECRET_KEY=d65585e5da280c4f036b8f1b6e9ff6cfe4c061b59515c379b6842f7023f21ebb9a1b2c3d4e5f60718293a4b5c6d7e8f9
```

4. Simpan file `.env`

---

## Langkah 4 — Buat Direktori & Set Permissions

Di **cPanel → File Manager**, pastikan folder berikut ada dan bisa ditulis:

| Direktori | Permission |
|-----------|------------|
| `.sessions/` | 755 |
| `storage/rate_limits/` | 755 |
| `assets/uploads/` | 755 |
| `assets/uploads/logos/` | 755 |
| `assets/uploads/students/` | 755 |
| `assets/uploads/webcam/` | 755 |

> Folder-folder ini sudah ada di repo (via `.gitkeep`), jadi cukup set permission-nya saja.

Untuk set permission massal:
1. File Manager → centang folder-folder di atas
2. Klik **Change Permissions** → set ke `755`

---

## Langkah 5 — Jalankan Installer (satu kali)

Buka browser, akses:
```
https://domain-kamu.com/cbtonline/install.php
```

Klik **Mulai Instalasi Otomatis Sekarang**

Installer akan:
- ✅ Membuat semua tabel database
- ✅ Seed data demo (2 tenant sekolah, admin, guru, siswa, soal, ujian)
- ✅ Membuat `config/installed.lock` (mengunci installer)

### Kredensial Demo Setelah Install

| Role | Username | Password |
|------|----------|----------|
| Superadmin | `admin` | `admin123` |
| Admin SMA Teladan | `admin_smatel` | `password` |
| Guru SMA Teladan | `guru_smatel` | `password` |
| Siswa (NIS) | `2024001` | `password` |
| Admin SMK Nusantara | `admin_smknus` | `password` |
| Siswa SMK (NIS) | `2024101` | `password` |

> ⚠️ **Ganti semua password demo sebelum digunakan di produksi!**

---

## Langkah 6 — Update Kode (Setiap Ada Perubahan)

Setiap kali kamu push commit baru ke GitHub:

**Opsi A — Manual:**
1. cPanel → Git Version Control → klik repo kamu
2. Klik **Deploy** atau **Pull**

**Opsi B — Auto (cron job):**
1. cPanel → Software → Cron Jobs
2. Buat cron:
   ```
   */10 * * * * cd /home/USERNAME/public_html/cbtonline && /usr/bin/git pull origin main 2>&1 | mail -s "CBT Deploy" username@email.com
   ```
   *(Ganti `USERNAME`, `username@email.com`, dan path sesuai cPanel kamu)*

---

## Struktur Direktori di Hosting

```
public_html/
└── cbtonline/            ← root aplikasi
    ├── .env              ← BUAT MANUAL (tidak di-git)
    ├── .sessions/        ← session files (writable)
    ├── admin/            ← panel admin & guru
    ├── api/              ← API endpoints
    ├── assets/
    │   ├── css/
    │   ├── js/
    │   └── uploads/      ← logo, foto siswa, webcam (writable)
    ├── core/             ← class PHP utama
    ├── database/         ← schema & migration SQL
    ├── student/          ← portal siswa
    ├── superadmin/       ← panel superadmin
    ├── vendor/           ← dependency composer (SUDAH di-git)
    ├── config/
    │   └── database.php  ← baca .env otomatis
    ├── install.php       ← installer (sekali jalan)
    ├── index.php         ← halaman utama
    └── .htaccess         ← security rules
```

---

## Troubleshooting

### "Koneksi Database Gagal"
- Cek `.env` — pastikan `CBT_DB_NAME`, `CBT_DB_USER`, `CBT_DB_PASS` benar
- Cek di cPanel → MySQL Databases apakah user punya akses ke database
- Pastikan `CBT_DB_HOST=localhost` (bukan `127.0.0.1` di beberapa cPanel)

### "Permission denied" saat upload file
- Pastikan folder `assets/uploads/` dan sub-foldernya punya permission 755
- Pastikan folder `.sessions/` dan `storage/rate_limits/` writable

### Halaman blank / error 500
- Cek cPanel → Metrics → Errors
- Biasanya karena PHP version tidak sesuai — set ke **PHP 8.1+** di cPanel → Select PHP Version

### Installer terkunci
- Hapus file `config/installed.lock` via File Manager untuk instal ulang
