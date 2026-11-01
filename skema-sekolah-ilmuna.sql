-- ==============================================================================
-- ILMUNA LEARN — SKEMA DATABASE SIAKAD & LMS SEKOLAH (PENDIDIKAN AGAMA ISLAM)
-- Diadaptasi & Disesuaikan dari Struktur Akademik Kuliah menjadi Struktur Sekolah
-- Target: learn.ilmuna.my.id (MySQL / MariaDB & SQLite)
-- ==============================================================================

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+07:00";

-- --------------------------------------------------------
-- 1. ROLES & HAK AKSES PENGGUNA
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS `roles` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `code` varchar(50) NOT NULL UNIQUE,
  `slug` varchar(50) NOT NULL UNIQUE,
  `name` varchar(100) NOT NULL,
  `nama` varchar(100) NOT NULL,
  `description` text DEFAULT NULL,
  `deskripsi` varchar(255) DEFAULT NULL,
  `badge_color` varchar(30) DEFAULT 'teal',
  `icon` varchar(50) DEFAULT 'fas fa-user',
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- 2. USERS (AKUN PENGGUNA TERPUSAT)
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS `users` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `username` varchar(60) NOT NULL UNIQUE,
  `password_hash` varchar(255) NOT NULL,
  `password` varchar(255) NOT NULL, -- Fallback compatibility
  `email` varchar(150) NOT NULL UNIQUE,
  `email_google` varchar(150) DEFAULT NULL,
  `google_id` varchar(100) DEFAULT NULL,
  `full_name` varchar(150) NOT NULL,
  `nama_lengkap` varchar(150) DEFAULT NULL,
  `nisn_nip` varchar(50) DEFAULT NULL,
  `gender` varchar(10) DEFAULT 'L',
  `phone` varchar(30) DEFAULT NULL,
  `no_hp` varchar(30) DEFAULT NULL,
  `avatar` varchar(255) DEFAULT NULL,
  `foto_profil` varchar(255) DEFAULT NULL,
  `status` varchar(20) DEFAULT 'active',
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `last_login` datetime DEFAULT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- 3. USER_ROLES (MULTI-ROLE: 1 USER BISA MEMILIKI 1-3 ATAU LEBIH ROLE)
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS `user_roles` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) UNSIGNED NOT NULL,
  `id_user` bigint(20) UNSIGNED NOT NULL,
  `role_id` bigint(20) UNSIGNED NOT NULL,
  `id_role` bigint(20) UNSIGNED NOT NULL,
  `id_kelas_mapel` bigint(20) UNSIGNED DEFAULT NULL,
  `is_primary` tinyint(1) DEFAULT 0,
  `assigned_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `kombinasi_unik` (`id_user`,`id_role`,`id_kelas_mapel`),
  KEY `idx_user` (`user_id`),
  KEY `idx_role` (`role_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- 4. KELAS / ROMBEL SEKOLAH (Menggantikan wp3f_dosen_kelas)
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS `classes` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `nama_kelas` varchar(100) NOT NULL,
  `grade_level` varchar(20) NOT NULL, -- X, XI, XII / 7, 8, 9
  `tingkat` varchar(20) NOT NULL,
  `jurusan` varchar(50) DEFAULT 'Umum',
  `academic_year` varchar(30) DEFAULT '2026/2027',
  `tahun_ajaran` varchar(30) DEFAULT '2026/2027',
  `ketua_kelas_id` bigint(20) UNSIGNED DEFAULT NULL,
  `wali_kelas_id` bigint(20) UNSIGNED DEFAULT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- 5. SISWA / PESERTA DIDIK (Menggantikan wp3f_dosen_mahasiswa)
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS `siswa` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `id_user` bigint(20) UNSIGNED NOT NULL,
  `id_kelas` bigint(20) UNSIGNED NOT NULL,
  `nisn` varchar(30) NOT NULL UNIQUE,
  `nama_lengkap` varchar(150) NOT NULL,
  `jenis_kelamin` enum('L','P') NOT NULL DEFAULT 'L',
  `no_hp` varchar(20) DEFAULT NULL,
  `email` varchar(150) DEFAULT NULL,
  `alamat` text DEFAULT NULL,
  `nama_wali` varchar(150) DEFAULT NULL,
  `no_hp_wali` varchar(20) DEFAULT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_kelas` (`id_kelas`),
  KEY `idx_user` (`id_user`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- 6. CLASS MEMBERS (Relasi Anggota Rombel)
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS `class_members` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `class_id` bigint(20) UNSIGNED NOT NULL,
  `user_id` bigint(20) UNSIGNED NOT NULL,
  `joined_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unik_member` (`class_id`, `user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- 7. MATA PELAJARAN (MAPEL) (Menggantikan wp3f_dosen_matkul)
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS `mapel` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `kode_mapel` varchar(50) NOT NULL UNIQUE,
  `nama_mapel` varchar(150) NOT NULL,
  `kategori` varchar(100) DEFAULT 'PAI & Budi Pekerti',
  `kkm` tinyint(3) NOT NULL DEFAULT 75, -- Kriteria Ketuntasan Minimal
  `tingkat` varchar(20) DEFAULT 'Semua',
  `deskripsi` text DEFAULT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- 8. KELAS_MAPEL (Relasi Rombel + Mapel + Guru Pengampu)
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS `kelas_mapel` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `id_kelas` bigint(20) UNSIGNED NOT NULL,
  `id_mapel` bigint(20) UNSIGNED NOT NULL,
  `id_guru` bigint(20) UNSIGNED NOT NULL, -- User ID Guru Pengampu
  `tahun_ajaran` varchar(30) DEFAULT '2026/2027',
  `semester` enum('Ganjil','Genap') DEFAULT 'Ganjil',
  PRIMARY KEY (`id`),
  UNIQUE KEY `relasi_unik_km` (`id_kelas`,`id_mapel`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- 9. JADWAL PELAJARAN (Menggantikan wp3f_dosen_jadwal)
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS `jadwal_pelajaran` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `id_kelas_mapel` bigint(20) UNSIGNED NOT NULL,
  `hari` varchar(20) NOT NULL, -- Senin, Selasa, Rabu, Kamis, Jumat, Sabtu
  `jam_ke` varchar(20) DEFAULT '1-2',
  `jam_mulai` time NOT NULL,
  `jam_selesai` time DEFAULT NULL,
  `ruang` varchar(50) DEFAULT 'Ruang Kelas',
  `tanggal_mulai` date DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_km_jadwal` (`id_kelas_mapel`),
  KEY `idx_hari` (`hari`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- 10. MODUL AJAR & RPP KURIKULUM MERDEKA (Menggantikan wp3f_dosen_rps)
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS `modul_ajar_rpp` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `id_kelas_mapel` bigint(20) UNSIGNED NOT NULL,
  `judul_modul` varchar(200) NOT NULL DEFAULT 'Modul Ajar PAI',
  `fase` varchar(10) DEFAULT 'E', -- Fase E (Kelas X), Fase F (Kelas XI/XII)
  `capaian_pembelajaran` text DEFAULT NULL,
  `tujuan_pembelajaran` text DEFAULT NULL,
  `profil_pelajar_pancasila` text DEFAULT NULL, -- Beriman, Bertakwa kpd Tuhan YME, Berakhlak Mulia
  `tahun_ajaran` varchar(20) DEFAULT '2026/2027',
  `buku_referensi` text DEFAULT NULL,
  `status` enum('draft','aktif','arsip') NOT NULL DEFAULT 'aktif',
  `created_by` bigint(20) UNSIGNED DEFAULT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_km_rpp` (`id_kelas_mapel`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- 11. DETAIL PERTEMUAN MODUL AJAR (Menggantikan wp3f_dosen_rps_pertemuan)
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS `modul_ajar_pertemuan` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `id_modul` bigint(20) UNSIGNED NOT NULL,
  `pertemuan_ke` int(3) NOT NULL,
  `materi_pokok` varchar(255) NOT NULL,
  `sub_materi` text DEFAULT NULL,
  `tujuan_pertemuan` text DEFAULT NULL,
  `indikator` text DEFAULT NULL,
  `metode_pembelajaran` varchar(200) DEFAULT 'Tadarus, Ceramah, Diskusi, & Praktik',
  `aktivitas_belajar` text DEFAULT NULL,
  `alokasi_menit` int(4) DEFAULT 90,
  `bobot_nilai` decimal(5,2) DEFAULT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_modul` (`id_modul`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- 12. PERTEMUAN KBM (Menggantikan wp3f_dosen_pertemuan)
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS `pertemuan` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `id_kelas_mapel` bigint(20) UNSIGNED NOT NULL,
  `pertemuan_ke` int(3) NOT NULL,
  `tanggal` date NOT NULL,
  `jenis_pertemuan` enum('KBM','PTS','PAS','PAT','Tadarus_Bersama') NOT NULL DEFAULT 'KBM',
  `hari` varchar(20) DEFAULT NULL,
  `jam_mulai` time DEFAULT NULL,
  `jam_selesai` time DEFAULT NULL,
  `ruang` varchar(50) DEFAULT 'Kelas',
  `catatan` text DEFAULT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unik_pertemuan_sekolah` (`id_kelas_mapel`,`pertemuan_ke`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- 13. JURNAL MENGAJAR GURU (Menggantikan wp3f_dosen_jurnal)
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS `jurnal_guru` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `id_kelas_mapel` bigint(20) UNSIGNED NOT NULL,
  `pertemuan_ke` int(3) NOT NULL,
  `id_materi` bigint(20) UNSIGNED DEFAULT NULL,
  `materi_text` varchar(255) DEFAULT NULL,
  `tanggal` date DEFAULT NULL,
  `catatan_kegiatan` text DEFAULT NULL,
  `catatan` text DEFAULT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_km_jurnal` (`id_kelas_mapel`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- 14. ABSENSI HARIAN SISWA (Menggantikan wp3f_dosen_absensi)
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS `absensi` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `id_kelas_mapel` bigint(20) UNSIGNED NOT NULL,
  `id_siswa` bigint(20) UNSIGNED NOT NULL,
  `id_pertemuan` bigint(20) UNSIGNED DEFAULT NULL,
  `tanggal` date NOT NULL,
  `koordinat` varchar(100) DEFAULT NULL,
  `status` enum('H','I','S','A') NOT NULL DEFAULT 'H', -- Hadir, Izin, Sakit, Alpa
  `bukti_izin` varchar(255) DEFAULT NULL,
  `keterangan` text DEFAULT NULL,
  `dicatat_oleh` bigint(20) UNSIGNED DEFAULT NULL, -- Guru atau Ketua Kelas
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_siswa_absensi` (`id_siswa`),
  KEY `idx_tanggal` (`tanggal`),
  KEY `idx_km_absensi` (`id_kelas_mapel`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- 15. PERIZINAN SISWA DENGAN WORKFLOW APPROVAL KETUA KELAS & GURU (wp3f_dosen_izin)
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS `perizinan_siswa` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `id_siswa` bigint(20) UNSIGNED NOT NULL,
  `id_kelas_mapel` bigint(20) UNSIGNED NOT NULL,
  `id_pertemuan` bigint(20) UNSIGNED DEFAULT NULL,
  `jenis` enum('S','I') NOT NULL DEFAULT 'I', -- S = Sakit, I = Izin
  `alasan` text DEFAULT NULL,
  `bukti_foto` varchar(500) DEFAULT NULL, -- Foto surat dokter/izin orang tua
  `koordinat_lat` decimal(10,7) DEFAULT NULL,
  `koordinat_lng` decimal(10,7) DEFAULT NULL,
  `koordinat_akurasi` decimal(10,2) DEFAULT NULL,
  `status` enum('pending','approved_ketua','approved_guru','rejected') NOT NULL DEFAULT 'pending',
  `ketua_by` bigint(20) UNSIGNED DEFAULT NULL,
  `ketua_at` datetime DEFAULT NULL,
  `guru_by` bigint(20) UNSIGNED DEFAULT NULL,
  `guru_at` datetime DEFAULT NULL,
  `rejected_by` bigint(20) UNSIGNED DEFAULT NULL,
  `rejected_at` datetime DEFAULT NULL,
  `alasan_reject` text DEFAULT NULL,
  `absensi_id` bigint(20) UNSIGNED DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_izin_siswa` (`id_siswa`),
  KEY `idx_izin_km` (`id_kelas_mapel`),
  KEY `idx_izin_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- 16. KELOMPOK SISWA & DISKUSI (Menggantikan wp3f_dosen_kelompok)
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS `kelompok_siswa` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `id_kelas_mapel` bigint(20) UNSIGNED NOT NULL,
  `nama_kelompok` varchar(100) NOT NULL, -- Kelompok 1, Abu Bakar Ash-Shiddiq, dll.
  `keterangan` text DEFAULT NULL,
  `status` enum('aktif','nonaktif') NOT NULL DEFAULT 'aktif',
  `created_by` bigint(20) UNSIGNED DEFAULT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_km_kelompok` (`id_kelas_mapel`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `kelompok_siswa_anggota` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `id_kelompok` bigint(20) UNSIGNED NOT NULL,
  `id_siswa` bigint(20) UNSIGNED NOT NULL,
  `peran` varchar(30) NOT NULL DEFAULT 'anggota', -- ketua_kelompok, anggota
  `status` enum('aktif','pindah','keluar') NOT NULL DEFAULT 'aktif',
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unik_anggota_kel` (`id_kelompok`,`id_siswa`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- 17. MATERI PEMBELAJARAN PAI (Menggantikan wp3f_dosen_materi)
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS `materials` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `title` varchar(255) NOT NULL,
  `judul` varchar(255) DEFAULT NULL,
  `category` varchar(100) NOT NULL, -- Al-Qur'an & Hadits, Akidah Akhlak, Fiqih, SKI
  `class_id` bigint(20) UNSIGNED DEFAULT NULL,
  `id_kelas_mapel` bigint(20) UNSIGNED DEFAULT NULL,
  `author_id` bigint(20) UNSIGNED NOT NULL,
  `summary` text DEFAULT NULL,
  `content` longtext DEFAULT NULL,
  `deskripsi` text DEFAULT NULL,
  `attachment_path` varchar(255) DEFAULT NULL,
  `link_materi` varchar(500) DEFAULT NULL,
  `youtube_url` varchar(255) DEFAULT NULL,
  `status` varchar(20) DEFAULT 'published',
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- 18. TUGAS & UJIAN SISWA (Menggantikan wp3f_dosen_tugas)
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS `assignments` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `title` varchar(255) NOT NULL,
  `judul` varchar(255) DEFAULT NULL,
  `category` varchar(100) DEFAULT 'Tugas Mandiri',
  `class_id` bigint(20) UNSIGNED NOT NULL,
  `id_kelas_mapel` bigint(20) UNSIGNED DEFAULT NULL,
  `id_pertemuan` bigint(20) UNSIGNED DEFAULT NULL,
  `author_id` bigint(20) UNSIGNED NOT NULL,
  `description` text DEFAULT NULL,
  `tugas_ke` int(3) DEFAULT 1,
  `bentuk_pengumpulan` varchar(30) DEFAULT 'teks_file',
  `skema_tugas` varchar(30) DEFAULT 'mandiri', -- mandiri, kelompok
  `due_date` datetime NOT NULL,
  `deadline` datetime DEFAULT NULL,
  `max_score` int(11) DEFAULT 100,
  `link_folder_gdrive` varchar(255) DEFAULT NULL,
  `status` varchar(20) DEFAULT 'active',
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- 19. PENGUMPULAN TUGAS (Menggantikan wp3f_dosen_penugasan)
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS `submissions` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `assignment_id` bigint(20) UNSIGNED NOT NULL,
  `id_tugas` bigint(20) UNSIGNED DEFAULT NULL,
  `student_id` bigint(20) UNSIGNED NOT NULL,
  `id_siswa` bigint(20) UNSIGNED DEFAULT NULL,
  `submitted_text` longtext DEFAULT NULL,
  `link_pengumpulan` varchar(255) DEFAULT NULL,
  `file_path` varchar(255) DEFAULT NULL,
  `score` int(11) DEFAULT NULL,
  `nilai` int(11) DEFAULT NULL,
  `teacher_feedback` text DEFAULT NULL,
  `catatan_guru` text DEFAULT NULL,
  `submitted_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `tanggal_pengumpulan` datetime DEFAULT CURRENT_TIMESTAMP,
  `graded_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_sub_assign` (`assignment_id`),
  KEY `idx_sub_student` (`student_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- 20. GEOFENCING / KOORDINAT SEKOLAH & MASJID (wp3f_dosen_kampus_koordinat)
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS `sekolah_koordinat` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `nama_lokasi` varchar(150) NOT NULL,
  `kode_lokasi` varchar(50) DEFAULT NULL,
  `latitude` decimal(10,7) NOT NULL,
  `longitude` decimal(10,7) NOT NULL,
  `radius_meter` int(11) NOT NULL DEFAULT 100,
  `tipe` enum('sekolah','gedung','kelas','masjid','lab','lapangan') NOT NULL DEFAULT 'sekolah',
  `alamat` text DEFAULT NULL,
  `keterangan` text DEFAULT NULL,
  `is_aktif` tinyint(1) NOT NULL DEFAULT 1,
  `created_by` bigint(20) UNSIGNED DEFAULT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unik_kode_lokasi` (`kode_lokasi`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- 21. PRESENSI DIGITAL & TOKEN QR (Menggantikan wp3f_dosen_presensi)
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS `presensi_token` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `id_presensi` varchar(64) NOT NULL UNIQUE,
  `id_siswa` bigint(20) UNSIGNED NOT NULL,
  `id_kelas_mapel` bigint(20) UNSIGNED NOT NULL,
  `tanggal_created` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `expired_at` datetime DEFAULT NULL,
  `status` enum('aktif','terpakai','expired') NOT NULL DEFAULT 'aktif',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- 22. JURNAL IBADAH YAUMIYAH SISWA (KHUSUS PAI SEKOLAH)
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS `mutabaah_logs` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `student_id` bigint(20) UNSIGNED NOT NULL,
  `id_siswa` bigint(20) UNSIGNED DEFAULT NULL,
  `log_date` date NOT NULL,
  `subuh` tinyint(1) DEFAULT 0,
  `dzuhur` tinyint(1) DEFAULT 0,
  `ashar` tinyint(1) DEFAULT 0,
  `maghrib` tinyint(1) DEFAULT 0,
  `isya` tinyint(1) DEFAULT 0,
  `dhuha` tinyint(1) DEFAULT 0,
  `tahajud` tinyint(1) DEFAULT 0,
  `tilawah_surah` varchar(100) DEFAULT NULL,
  `tilawah_ayat` varchar(50) DEFAULT NULL,
  `puasa_sunnah` tinyint(1) DEFAULT 0,
  `infaq` tinyint(1) DEFAULT 0,
  `catatan` text DEFAULT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unik_mutabaah_harian` (`student_id`, `log_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- 23. ANNOUNCEMENTS / PENGUMUMAN & AGENDA TADARUS
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS `announcements` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `title` varchar(255) NOT NULL,
  `content` text NOT NULL,
  `class_id` bigint(20) UNSIGNED DEFAULT NULL,
  `target_role` varchar(50) DEFAULT 'all',
  `author_id` bigint(20) UNSIGNED NOT NULL,
  `is_pinned` tinyint(1) DEFAULT 0,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

COMMIT;
