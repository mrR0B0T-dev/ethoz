# Sistem Informasi RKAP HC

Sistem monitoring Rencana Kerja dan Anggaran Perusahaan (biaya personil) tahunan
untuk Divisi Human Capital & Corporate Secretary. Data awal diimpor dari workbook
**BDP RKAP 2026 HCM DEPARTMENT (Alt7)**.

Dibangun dengan Laravel 13, Inertia.js, Vue 3, dan Tailwind CSS 4.

## Fitur

| Halaman | URL | Deskripsi |
| --- | --- | --- |
| Dashboard | `/hc-rkap` | KPI, tren bulanan, komposisi jenis biaya, ranking unit, sorotan manajemen; filter periode/jenis biaya/unit kerja + perbandingan YoY |
| RKAP Detail | `/hc-rkap/detail` | Matriks kategori → komponen → unit × 12 bulan, edit inline, export Excel |
| Realisasi & Monitoring | `/hc-rkap/realisasi` | Input realisasi bulanan, serapan YTD, status pagu per kategori |
| Pegawai & Biaya | `/hc-rkap/pegawai` | 483 pegawai per status (tetap/kontrak/honor) dengan estimasi biaya tahunan |
| Asumsi | `/hc-rkap/asumsi` | Kenaikan gaji per status, tunjangan, pajak, fee, BPJS |
| Master Data | `/hc-rkap/master` | Tahun anggaran (generate RKAP tahun baru dari asumsi), unit kerja hierarkis, jenis biaya |

## Kebutuhan

- PHP >= 8.3 dengan ekstensi `pdo_sqlite`
- Composer
- Node.js >= 20 dan npm

## Cara Menjalankan

```bash
# 1. Dependensi PHP
composer install

# 2. Konfigurasi environment (default sudah memakai SQLite)
cp .env.example .env
php artisan key:generate

# 3. Buat database + tabel + data awal (RKAP 2025 & 2026, 483 pegawai, asumsi, master)
touch database/database.sqlite
php artisan migrate --seed

# 4. Dependensi & build frontend
npm install
npm run build

# 5. Jalankan server
php artisan serve
```

Buka **http://127.0.0.1:8000** — Anda akan diarahkan ke halaman login.

### Akun bawaan

| Email | Kata sandi |
| --- | --- |
| `admin@rkap-hc.test` | `admin123` |

> Ganti kata sandi akun ini (atau ubah di `database/seeders/UserSeeder.php`)
> sebelum dipakai di lingkungan produksi.

### Mode pengembangan

Untuk hot-reload saat mengubah kode frontend, jalankan `npm run dev` di terminal
terpisah (menggantikan `npm run build` pada langkah 4).
