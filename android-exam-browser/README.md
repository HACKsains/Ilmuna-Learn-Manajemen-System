# 🛡️ Ilmuna Exam Browser (Android CBT Kiosk)

Aplikasi **Exam Browser Resmi (CBT Kiosk & Safe Exam)** berbasis **Kotlin** untuk platform pembelajaran digital **Ilmuna Learn**. Dirancang khusus dengan proteksi keamanan tinggi guna mencegah kecurangan saat siswa mengerjakan Ujian / Asesmen Sumatif & Formatif.

---

## 🎨 Tema Desain: Lumina Gold
- **Navy**: `#1D3B5E`
- **Teal**: `#0D7A87`
- **Sky Blue**: `#52B2DC`
- **Gold Accent**: `#F59E0B`
- Senada dengan antarmuka web Ilmuna Learn.

---

## 🔒 Fitur Keamanan Anti-Kecurangan (High-Grade Kiosk Security)

### 1. 🚫 Anti-Screenshot & Anti-Screen Recording (`FLAG_SECURE`)
- Menggunakan `WindowManager.LayoutParams.FLAG_SECURE` di seluruh Activity (`MainActivity` & `SplashActivity`).
- **Screenshot tombol fisik & gesture jari otomatis diblokir** oleh sistem Android.
- **Perekam layar (Screen Recorder) menghasilkan video hitam kosong (blank)**.
- **Tampilan aplikasi di Recent Apps / App Switcher Android disembunyikan**, mencegah siswa mengintip soal saat membuka switcher.

### 2. ✂️ Anti-Copy-Paste & Anti-Seleksi Teks
- **Mematikan Text Selection**:
  - Meng-override `startActionMode` dan `onActionModeStarted` pada Activity sehingga **floating menu konteks Android ("Salin", "Tempel", "Bagikan", "Pencarian Web") TIDAK PERNAH BISA MUNCUL**.
  - `webView.isLongClickable = false` dan `setOnLongClickListener` dikonsumsi untuk menonaktifkan klik lama.
- **Injeksi CSS Anti-Selection**:
  - Menyuntikkan aturan `-webkit-user-select: none !important; user-select: none !important;` ke seluruh elemen halaman web.
- **Injeksi JavaScript Event Blocker**:
  - Memblokir event `copy`, `cut`, `paste`, `contextmenu`, `selectstart`, dan `dragstart` pada level DOM capture.
- **Pembersihan Clipboard OS**:
  - Secara reaktif mengosongkan isi clipboard Android sistem (`ClipboardManager.setPrimaryClip`) jika ada upaya interaksi salin teks dari luar.

### 3. ❄️ Logika Keluar Aplikasi (Freeze Ujian & Auto-Logout Sisi Aplikasi)
Sesuai standar operasional ujian CBT profesional:
- **Deteksi Keluar Aplikasi**:
  - Tertangkap secara instan melalui `onUserLeaveHint()` (saat siswa menekan tombol **Home** atau **Recent Apps**), `onPause()`, atau saat aplikasi kehilangan fokus karena split screen, floating apps, atau menu notifikasi.
- **Tindakan Otomatis**:
  1. **Autosave Jawaban**: Android memicu eksekusi autosave jawaban terakhir siswa ke server.
  2. **Freeze Sesi di Database**:
     - Mengirimkan sinyal freeze ke endpoint `/api/cbt-freeze.php`.
     - **Di database, status pengerjaan TETAP `Sedang Mengerjakan`** (tidak berubah menjadi 'Selesai' dan tidak di-reset ke 'Belum Mulai').
     - **Sisa waktu ujian dibekukan** di kolom `sisa_waktu_detik` pada tabel `akses_asesmen_sumatif`, sehingga waktu siswa tidak habis sia-sia saat sesi terkunci/keluar.
     - **Paket butir soal dan jawaban yang telah dikerjakan TETAP TERSIMPAN UTUH** di database (TIDAK DIBUAT ULANG atau DI-RESET).
  3. **Auto-Logout di Sisi Aplikasi**:
     - Sesi cookies dan form data WebView di aplikasi dihapus (`CookieManager.removeAllCookies`), sehingga di perangkat siswa otomatis ter-logout.
     - WebView dimuat ulang ke gerbang login.
  4. **Layar Dialog Freeze**:
     - Saat siswa kembali membuka aplikasi, muncul dialog peringatan: *"⚠️ Sesi Ujian Dibekukan (Freeze) — Anda meninggalkan aplikasi ujian. Sesi aplikasi telah dilogout secara otomatis demi integritas ujian. Jawaban Anda tetap aman. Silakan login kembali untuk melanjutkan."*
  5. **Melanjutkan Ujian (Resume)**:
     - Ketika siswa login kembali, sistem web mendeteksi sesi `Sedang Mengerjakan` yang dibekukan, dan langsung memuat paket soal serta jawaban yang telah dijawab sebelumnya tanpa membuat sesi baru.

---

## 🌐 Integrasi JavaScript Bridge (`window.IlmunaKiosk`)

Halaman web CBT siswa dapat berinteraksi langsung dengan aplikasi Android:

```javascript
// 1. Cek apakah dibuka dari Exam Browser resmi
if (window.IlmunaKiosk && window.IlmunaKiosk.isExamBrowser()) {
    console.log("Exam Browser Resmi Terdeteksi, Versi:", window.IlmunaKiosk.getAppVersion());
}

// 2. Beritahu Android bahwa siswa mulai mengerjakan soal
// Parameter: active (boolean), id_siswa, id_asesmen, sisa_waktu_detik
if (window.IlmunaKiosk) {
    window.IlmunaKiosk.setExamActive(true, "15", "3", 3600);
}

// 3. Update sisa durasi ujian setiap detik
if (window.IlmunaKiosk) {
    window.IlmunaKiosk.updateRemainingTime(remainingSeconds);
}

// 4. Siapkan fungsi autosave di window untuk dipanggil oleh Android saat Freeze
window.autoSaveExam = function() {
    // Simpan jawaban soal aktif via AJAX
    simpanJawabanSaatIni();
};

// 5. Beritahu Android saat ujian selesai secara sah
if (window.IlmunaKiosk) {
    window.IlmunaKiosk.finishExam();
}
```

---

## 🚀 Panduan Membuka Proyek di Android Studio

1. **Buka Android Studio**:
   - Pilih menu **File** $\rightarrow$ **Open...**
   - Arahkan ke folder: `C:\laragon\www\learn\android-exam-browser`
   - Klik **OK**.
2. **Tunggu Proses Gradle Sync**:
   - Android Studio akan secara otomatis mengunduh dependensi Gradle Kotlin yang dibutuhkan.
3. **Menghubungkan ke Server Laragon di Komputer**:
   - **Jika Menggunakan Emulator Android Studio**:
     Gunakan alamat: `http://10.0.2.2/learn/exam` (atau `http://10.0.2.2/learn`)
   - **Jika Menggunakan HP Fisik (WiFi yang Sama dengan Laptop/PC Laragon)**:
     1. Buka CMD di laptop, ketik `ipconfig` untuk melihat IPv4 laptop (saat ini: `10.221.231.106`).
     2. Di aplikasi Ilmuna Exam Browser, klik **Pengaturan Server**.
     3. Masukkan PIN Pengawas: `123456`.
     4. Masukkan URL: `http://10.221.231.106/learn/exam` (tanpa akhiran `.php`).
4. **Cara Menghasilkan Berkas APK**:
   - Pada toolbar atas Android Studio, klik **Build** $\rightarrow$ **Build Bundle(s) / APK(s)** $\rightarrow$ **Build APK(s)**.
   - Setelah selesai, klik notifikasi **locate** untuk mendapatkan berkas `app-debug.apk`.
   - Pasang (install) berkas `.apk` tersebut ke perangkat Android siswa.

---

## 📁 Struktur Direktori Proyek
```
android-exam-browser/
├── app/
│   ├── src/main/
│   │   ├── java/id/ilmuna/exambrowser/
│   │   │   ├── MainActivity.kt        (Layar Kiosk Ujian, Anti-Cheat, Anti-Copy, Freeze Logic)
│   │   │   ├── SplashActivity.kt      (Gerbang Pembuka & Konfigurasi Server Ujian)
│   │   │   ├── SecurityHelper.kt      (FLAG_SECURE, Anti-Selection CSS/JS, Clipboard Cleaner)
│   │   │   └── WebAppInterface.kt     (Bridge JavaScript Web & Kotlin CBT)
│   │   ├── res/
│   │   │   ├── layout/                (Desain Antarmuka XML Lumina Gold)
│   │   │   ├── values/                (colors.xml, strings.xml, themes.xml)
│   │   │   └── xml/                   (network_security_config.xml)
│   │   └── AndroidManifest.xml
│   └── build.gradle.kts
├── gradle/
│   └── libs.versions.toml             (Dependency Version Catalog)
├── build.gradle.kts
└── settings.gradle.kts
```
