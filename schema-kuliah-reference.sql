-- phpMyAdmin SQL Dump
-- version 5.2.3
-- https://www.phpmyadmin.net/
--
-- Host: localhost:3306
-- Waktu pembuatan: 17 Okt 2026 pada 16.55
-- Versi server: 10.11.19-MariaDB-cll-lve
-- Versi PHP: 8.4.26

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Basis data: `lidgntft_wp240`
--

-- --------------------------------------------------------

--
-- Struktur dari tabel `wp3f_dosen_absensi`
--

CREATE TABLE `wp3f_dosen_absensi` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `id_kelas_matkul` bigint(20) UNSIGNED NOT NULL,
  `id_mahasiswa` bigint(20) UNSIGNED NOT NULL,
  `tanggal` date NOT NULL,
  `koordinat` varchar(100) DEFAULT NULL,
  `status` enum('H','I','S','A') NOT NULL DEFAULT 'H',
  `bukti_izin` varchar(255) DEFAULT NULL,
  `keterangan` text DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;

-- --------------------------------------------------------

--
-- Struktur dari tabel `wp3f_dosen_izin`
--

CREATE TABLE `wp3f_dosen_izin` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `id_mahasiswa` bigint(20) UNSIGNED NOT NULL,
  `id_kelas_matkul` bigint(20) UNSIGNED NOT NULL,
  `id_pertemuan` bigint(20) UNSIGNED DEFAULT NULL,
  `jenis` enum('S','I') NOT NULL DEFAULT 'I',
  `alasan` text DEFAULT NULL,
  `bukti_foto` varchar(500) DEFAULT NULL,
  `koordinat_lat` decimal(10,7) DEFAULT NULL,
  `koordinat_lng` decimal(10,7) DEFAULT NULL,
  `koordinat_akurasi` decimal(10,2) DEFAULT NULL,
  `status` enum('pending','approved_ketua','approved_pj','approved_dosen','rejected') NOT NULL DEFAULT 'pending',
  `ketua_by` bigint(20) UNSIGNED DEFAULT NULL,
  `ketua_at` datetime DEFAULT NULL,
  `pj_by` bigint(20) UNSIGNED DEFAULT NULL,
  `pj_at` datetime DEFAULT NULL,
  `dosen_by` bigint(20) UNSIGNED DEFAULT NULL,
  `dosen_at` datetime DEFAULT NULL,
  `rejected_by` bigint(20) UNSIGNED DEFAULT NULL,
  `rejected_at` datetime DEFAULT NULL,
  `alasan_reject` text DEFAULT NULL,
  `absensi_id` bigint(20) UNSIGNED DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;

-- --------------------------------------------------------

--
-- Struktur dari tabel `wp3f_dosen_jadwal`
--

CREATE TABLE `wp3f_dosen_jadwal` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `id_kelas_matkul` bigint(20) UNSIGNED NOT NULL,
  `hari` varchar(20) NOT NULL,
  `jam_mulai` time NOT NULL,
  `jam_selesai` time DEFAULT NULL,
  `jam_ke` varchar(20) DEFAULT NULL,
  `tanggal_mulai` date DEFAULT NULL,
  `ruang` varchar(50) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;

-- --------------------------------------------------------

--
-- Struktur dari tabel `wp3f_dosen_jurnal`
--

CREATE TABLE `wp3f_dosen_jurnal` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `id_kelas_matkul` bigint(20) UNSIGNED NOT NULL,
  `pertemuan_ke` int(3) NOT NULL,
  `id_materi` bigint(20) UNSIGNED DEFAULT NULL,
  `materi_text` varchar(255) DEFAULT NULL,
  `tanggal` date DEFAULT NULL,
  `catatan` text DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;

-- --------------------------------------------------------

--
-- Struktur dari tabel `wp3f_dosen_kampus_koordinat`
--

CREATE TABLE `wp3f_dosen_kampus_koordinat` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `nama_lokasi` varchar(150) NOT NULL,
  `kode_lokasi` varchar(50) DEFAULT NULL,
  `latitude` decimal(10,7) NOT NULL,
  `longitude` decimal(10,7) NOT NULL,
  `radius_meter` int(11) NOT NULL DEFAULT 100,
  `tipe` enum('kampus','gedung','ruang','lab','lainnya') NOT NULL DEFAULT 'kampus',
  `alamat` text DEFAULT NULL,
  `keterangan` text DEFAULT NULL,
  `is_aktif` tinyint(1) NOT NULL DEFAULT 1,
  `created_by` bigint(20) UNSIGNED DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;

-- --------------------------------------------------------

--
-- Struktur dari tabel `wp3f_dosen_kelas`
--

CREATE TABLE `wp3f_dosen_kelas` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `nama_kelas` varchar(100) NOT NULL,
  `semester` varchar(20) NOT NULL,
  `angkatan` varchar(10) NOT NULL,
  `tahun` varchar(10) NOT NULL,
  `created_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;

-- --------------------------------------------------------

--
-- Struktur dari tabel `wp3f_dosen_kelas_matkul`
--

CREATE TABLE `wp3f_dosen_kelas_matkul` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `id_kelas` bigint(20) UNSIGNED NOT NULL,
  `id_matkul` bigint(20) UNSIGNED NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;

-- --------------------------------------------------------

--
-- Struktur dari tabel `wp3f_dosen_kelompok`
--

CREATE TABLE `wp3f_dosen_kelompok` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `id_kelas_matkul` bigint(20) UNSIGNED NOT NULL,
  `id_rps` bigint(20) UNSIGNED NOT NULL,
  `nama_kelompok` varchar(100) NOT NULL,
  `keterangan` text DEFAULT NULL,
  `status` enum('aktif','nonaktif') NOT NULL DEFAULT 'aktif',
  `created_by` bigint(20) UNSIGNED DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;

-- --------------------------------------------------------

--
-- Struktur dari tabel `wp3f_dosen_kelompok_anggota`
--

CREATE TABLE `wp3f_dosen_kelompok_anggota` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `id_kelompok` bigint(20) UNSIGNED NOT NULL,
  `id_mahasiswa` bigint(20) UNSIGNED NOT NULL,
  `peran` varchar(30) NOT NULL DEFAULT 'anggota',
  `status` enum('aktif','pindah','keluar') NOT NULL DEFAULT 'aktif',
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;

-- --------------------------------------------------------

--
-- Struktur dari tabel `wp3f_dosen_kelompok_kehadiran`
--

CREATE TABLE `wp3f_dosen_kelompok_kehadiran` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `id_kelompok` bigint(20) UNSIGNED NOT NULL,
  `id_pertemuan` bigint(20) UNSIGNED NOT NULL,
  `id_mahasiswa` bigint(20) UNSIGNED NOT NULL,
  `status` enum('hadir','tidak_hadir','izin','sakit','pindah') NOT NULL DEFAULT 'hadir',
  `id_kelompok_pindah` bigint(20) UNSIGNED DEFAULT NULL,
  `peran_saat_itu` varchar(30) DEFAULT NULL,
  `keterangan` text DEFAULT NULL,
  `dicatat_by` bigint(20) UNSIGNED DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;

-- --------------------------------------------------------

--
-- Struktur dari tabel `wp3f_dosen_konten_kelas`
--

CREATE TABLE `wp3f_dosen_konten_kelas` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `id_kelas_matkul` bigint(20) UNSIGNED NOT NULL,
  `judul` varchar(200) DEFAULT NULL,
  `link_konten` varchar(255) NOT NULL,
  `status` enum('publish','pending','ditolak') NOT NULL DEFAULT 'pending',
  `tanggal_upload` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;

-- --------------------------------------------------------

--
-- Struktur dari tabel `wp3f_dosen_mahasiswa`
--

CREATE TABLE `wp3f_dosen_mahasiswa` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `id_kelas` bigint(20) UNSIGNED NOT NULL,
  `nim` varchar(30) NOT NULL,
  `nama_lengkap` varchar(150) NOT NULL,
  `no_hp` varchar(20) DEFAULT NULL,
  `gmail` varchar(150) DEFAULT NULL,
  `alamat` text DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;

-- --------------------------------------------------------

--
-- Struktur dari tabel `wp3f_dosen_materi`
--

CREATE TABLE `wp3f_dosen_materi` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `id_kelas_matkul` bigint(20) UNSIGNED NOT NULL,
  `judul` varchar(200) NOT NULL,
  `link_materi` varchar(500) NOT NULL,
  `deskripsi` text DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;

-- --------------------------------------------------------

--
-- Struktur dari tabel `wp3f_dosen_matkul`
--

CREATE TABLE `wp3f_dosen_matkul` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `nama_mk` varchar(150) NOT NULL,
  `sks` tinyint(2) NOT NULL DEFAULT 0,
  `semester` varchar(20) NOT NULL,
  `tahun` varchar(10) NOT NULL,
  `created_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;

-- --------------------------------------------------------

--
-- Struktur dari tabel `wp3f_dosen_penugasan`
--

CREATE TABLE `wp3f_dosen_penugasan` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `id_tugas` bigint(20) UNSIGNED NOT NULL,
  `id_mahasiswa` bigint(20) UNSIGNED NOT NULL,
  `tanggal_pengumpulan` datetime DEFAULT current_timestamp(),
  `link_pengumpulan` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;

-- --------------------------------------------------------

--
-- Struktur dari tabel `wp3f_dosen_pertemuan`
--

CREATE TABLE `wp3f_dosen_pertemuan` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `id_kelas_matkul` bigint(20) UNSIGNED NOT NULL,
  `id_rps_pertemuan` bigint(20) UNSIGNED DEFAULT NULL,
  `pertemuan_ke` int(3) NOT NULL,
  `tanggal` date NOT NULL,
  `jenis_pertemuan` enum('KBM','UTS','UAS') NOT NULL DEFAULT 'KBM',
  `hari` varchar(20) DEFAULT NULL,
  `jam_mulai` time DEFAULT NULL,
  `jam_selesai` time DEFAULT NULL,
  `ruang` varchar(50) DEFAULT NULL,
  `catatan` text DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;

-- --------------------------------------------------------

--
-- Struktur dari tabel `wp3f_dosen_presensi`
--

CREATE TABLE `wp3f_dosen_presensi` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `id_presensi` varchar(64) NOT NULL,
  `id_mahasiswa` bigint(20) UNSIGNED NOT NULL,
  `id_kelas_matkul` bigint(20) UNSIGNED NOT NULL,
  `tanggal_created` datetime NOT NULL DEFAULT current_timestamp(),
  `expired_at` datetime DEFAULT NULL,
  `status` enum('aktif','terpakai','expired') NOT NULL DEFAULT 'aktif'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;

-- --------------------------------------------------------

--
-- Struktur dari tabel `wp3f_dosen_roles`
--

CREATE TABLE `wp3f_dosen_roles` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `slug` varchar(50) NOT NULL,
  `nama` varchar(100) NOT NULL,
  `deskripsi` varchar(255) DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;

-- --------------------------------------------------------

--
-- Struktur dari tabel `wp3f_dosen_rps`
--

CREATE TABLE `wp3f_dosen_rps` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `id_kelas_matkul` bigint(20) UNSIGNED NOT NULL,
  `judul_rps` varchar(200) NOT NULL DEFAULT 'RPS',
  `deskripsi` text DEFAULT NULL,
  `capaian_pembelajaran_lulusan` text DEFAULT NULL,
  `capaian_pembelajaran_matkul` text DEFAULT NULL,
  `tahun_akademik` varchar(20) DEFAULT NULL,
  `dosen_pengampu` varchar(200) DEFAULT NULL,
  `bobot_sks` tinyint(2) DEFAULT NULL,
  `buku_referensi` text DEFAULT NULL,
  `status` enum('draft','aktif','arsip') NOT NULL DEFAULT 'draft',
  `created_by` bigint(20) UNSIGNED DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;

-- --------------------------------------------------------

--
-- Struktur dari tabel `wp3f_dosen_rps_pertemuan`
--

CREATE TABLE `wp3f_dosen_rps_pertemuan` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `id_rps` bigint(20) UNSIGNED NOT NULL,
  `id_pertemuan` bigint(20) UNSIGNED NOT NULL,
  `materi_pokok` varchar(255) NOT NULL,
  `sub_materi` text DEFAULT NULL,
  `capaian` text DEFAULT NULL,
  `indikator` text DEFAULT NULL,
  `metode` varchar(200) DEFAULT NULL,
  `aktivitas` text DEFAULT NULL,
  `waktu_menit` int(4) DEFAULT 100,
  `referensi` text DEFAULT NULL,
  `bobot_nilai` decimal(5,2) DEFAULT NULL,
  `catatan` text DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;

-- --------------------------------------------------------

--
-- Struktur dari tabel `wp3f_dosen_tugas`
--

CREATE TABLE `wp3f_dosen_tugas` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `id_kelas_matkul` bigint(20) UNSIGNED NOT NULL,
  `id_pertemuan` bigint(20) UNSIGNED DEFAULT NULL,
  `tugas_ke` int(3) NOT NULL,
  `bentuk_pengumpulan` varchar(30) NOT NULL,
  `skema_tugas` varchar(30) NOT NULL,
  `deadline` datetime DEFAULT NULL,
  `link_folder_gdrive` varchar(255) DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;

-- --------------------------------------------------------

--
-- Struktur dari tabel `wp3f_dosen_users`
--

CREATE TABLE `wp3f_dosen_users` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `id_mahasiswa` bigint(20) UNSIGNED NOT NULL,
  `id_kelas` bigint(20) UNSIGNED NOT NULL,
  `username` varchar(60) NOT NULL,
  `password` varchar(255) NOT NULL,
  `email_google` varchar(150) DEFAULT NULL,
  `google_id` varchar(100) DEFAULT NULL,
  `foto_profil` varchar(255) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `last_login` datetime DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;

-- --------------------------------------------------------

--
-- Struktur dari tabel `wp3f_dosen_user_roles`
--

CREATE TABLE `wp3f_dosen_user_roles` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `id_user` bigint(20) UNSIGNED NOT NULL,
  `id_role` bigint(20) UNSIGNED NOT NULL,
  `id_kelas_matkul` bigint(20) UNSIGNED DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;

--
-- Indeks untuk tabel yang dibuang
--

--
-- Indeks untuk tabel `wp3f_dosen_absensi`
--
ALTER TABLE `wp3f_dosen_absensi`
  ADD PRIMARY KEY (`id`),
  ADD KEY `id_mahasiswa` (`id_mahasiswa`),
  ADD KEY `tanggal` (`tanggal`),
  ADD KEY `id_kelas_matkul` (`id_kelas_matkul`);

--
-- Indeks untuk tabel `wp3f_dosen_izin`
--
ALTER TABLE `wp3f_dosen_izin`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_mhs` (`id_mahasiswa`),
  ADD KEY `idx_km` (`id_kelas_matkul`),
  ADD KEY `idx_pertemuan` (`id_pertemuan`),
  ADD KEY `idx_status` (`status`),
  ADD KEY `idx_created` (`created_at`);

--
-- Indeks untuk tabel `wp3f_dosen_jadwal`
--
ALTER TABLE `wp3f_dosen_jadwal`
  ADD PRIMARY KEY (`id`),
  ADD KEY `hari` (`hari`),
  ADD KEY `id_kelas_matkul` (`id_kelas_matkul`),
  ADD KEY `tanggal_mulai` (`tanggal_mulai`);

--
-- Indeks untuk tabel `wp3f_dosen_jurnal`
--
ALTER TABLE `wp3f_dosen_jurnal`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unik_pertemuan` (`id_kelas_matkul`,`pertemuan_ke`),
  ADD KEY `id_kelas_matkul` (`id_kelas_matkul`),
  ADD KEY `id_materi` (`id_materi`),
  ADD KEY `tanggal` (`tanggal`);

--
-- Indeks untuk tabel `wp3f_dosen_kampus_koordinat`
--
ALTER TABLE `wp3f_dosen_kampus_koordinat`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unik_kode` (`kode_lokasi`),
  ADD KEY `idx_tipe` (`tipe`),
  ADD KEY `idx_aktif` (`is_aktif`),
  ADD KEY `idx_latlng` (`latitude`,`longitude`);

--
-- Indeks untuk tabel `wp3f_dosen_kelas`
--
ALTER TABLE `wp3f_dosen_kelas`
  ADD PRIMARY KEY (`id`),
  ADD KEY `semester` (`semester`),
  ADD KEY `tahun` (`tahun`);

--
-- Indeks untuk tabel `wp3f_dosen_kelas_matkul`
--
ALTER TABLE `wp3f_dosen_kelas_matkul`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `relasi_unik` (`id_kelas`,`id_matkul`),
  ADD KEY `id_kelas` (`id_kelas`),
  ADD KEY `id_matkul` (`id_matkul`);

--
-- Indeks untuk tabel `wp3f_dosen_kelompok`
--
ALTER TABLE `wp3f_dosen_kelompok`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_kelas_matkul` (`id_kelas_matkul`),
  ADD KEY `idx_rps` (`id_rps`),
  ADD KEY `idx_status` (`status`),
  ADD KEY `idx_created_by` (`created_by`);

--
-- Indeks untuk tabel `wp3f_dosen_kelompok_anggota`
--
ALTER TABLE `wp3f_dosen_kelompok_anggota`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unik_anggota` (`id_kelompok`,`id_mahasiswa`),
  ADD KEY `idx_kelompok` (`id_kelompok`),
  ADD KEY `idx_mahasiswa` (`id_mahasiswa`),
  ADD KEY `idx_peran` (`peran`),
  ADD KEY `idx_status` (`status`);

--
-- Indeks untuk tabel `wp3f_dosen_kelompok_kehadiran`
--
ALTER TABLE `wp3f_dosen_kelompok_kehadiran`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unik_kehadiran` (`id_kelompok`,`id_pertemuan`,`id_mahasiswa`),
  ADD KEY `idx_kelompok` (`id_kelompok`),
  ADD KEY `idx_pertemuan` (`id_pertemuan`),
  ADD KEY `idx_mahasiswa` (`id_mahasiswa`),
  ADD KEY `idx_status` (`status`),
  ADD KEY `idx_kelompok_pindah` (`id_kelompok_pindah`),
  ADD KEY `idx_dicatat_by` (`dicatat_by`);

--
-- Indeks untuk tabel `wp3f_dosen_konten_kelas`
--
ALTER TABLE `wp3f_dosen_konten_kelas`
  ADD PRIMARY KEY (`id`),
  ADD KEY `id_kelas_matkul` (`id_kelas_matkul`),
  ADD KEY `status` (`status`);

--
-- Indeks untuk tabel `wp3f_dosen_mahasiswa`
--
ALTER TABLE `wp3f_dosen_mahasiswa`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `nim` (`nim`),
  ADD KEY `id_kelas` (`id_kelas`);

--
-- Indeks untuk tabel `wp3f_dosen_materi`
--
ALTER TABLE `wp3f_dosen_materi`
  ADD PRIMARY KEY (`id`),
  ADD KEY `id_kelas_matkul` (`id_kelas_matkul`),
  ADD KEY `created_at` (`created_at`);

--
-- Indeks untuk tabel `wp3f_dosen_matkul`
--
ALTER TABLE `wp3f_dosen_matkul`
  ADD PRIMARY KEY (`id`),
  ADD KEY `semester` (`semester`),
  ADD KEY `tahun` (`tahun`);

--
-- Indeks untuk tabel `wp3f_dosen_penugasan`
--
ALTER TABLE `wp3f_dosen_penugasan`
  ADD PRIMARY KEY (`id`),
  ADD KEY `id_mahasiswa` (`id_mahasiswa`),
  ADD KEY `id_tugas` (`id_tugas`);

--
-- Indeks untuk tabel `wp3f_dosen_pertemuan`
--
ALTER TABLE `wp3f_dosen_pertemuan`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unik_pertemuan` (`id_kelas_matkul`,`pertemuan_ke`),
  ADD KEY `id_kelas_matkul` (`id_kelas_matkul`),
  ADD KEY `tanggal` (`tanggal`),
  ADD KEY `jenis_pertemuan` (`jenis_pertemuan`),
  ADD KEY `idx_rps_pertemuan` (`id_rps_pertemuan`);

--
-- Indeks untuk tabel `wp3f_dosen_presensi`
--
ALTER TABLE `wp3f_dosen_presensi`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unik_presensi` (`id_presensi`),
  ADD KEY `id_mahasiswa` (`id_mahasiswa`),
  ADD KEY `id_kelas_matkul` (`id_kelas_matkul`),
  ADD KEY `tanggal_created` (`tanggal_created`),
  ADD KEY `expired_at` (`expired_at`),
  ADD KEY `status` (`status`);

--
-- Indeks untuk tabel `wp3f_dosen_roles`
--
ALTER TABLE `wp3f_dosen_roles`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `slug` (`slug`);

--
-- Indeks untuk tabel `wp3f_dosen_rps`
--
ALTER TABLE `wp3f_dosen_rps`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unik_rps_per_km` (`id_kelas_matkul`),
  ADD KEY `idx_status` (`status`),
  ADD KEY `idx_tahun` (`tahun_akademik`);

--
-- Indeks untuk tabel `wp3f_dosen_rps_pertemuan`
--
ALTER TABLE `wp3f_dosen_rps_pertemuan`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unik_rps_pertemuan` (`id_rps`,`id_pertemuan`),
  ADD KEY `idx_rps` (`id_rps`),
  ADD KEY `idx_pertemuan` (`id_pertemuan`);

--
-- Indeks untuk tabel `wp3f_dosen_tugas`
--
ALTER TABLE `wp3f_dosen_tugas`
  ADD PRIMARY KEY (`id`),
  ADD KEY `id_kelas_matkul` (`id_kelas_matkul`),
  ADD KEY `skema_tugas` (`skema_tugas`),
  ADD KEY `deadline` (`deadline`),
  ADD KEY `idx_pertemuan` (`id_pertemuan`);

--
-- Indeks untuk tabel `wp3f_dosen_users`
--
ALTER TABLE `wp3f_dosen_users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `username` (`username`),
  ADD UNIQUE KEY `id_mahasiswa` (`id_mahasiswa`),
  ADD KEY `id_kelas` (`id_kelas`),
  ADD KEY `google_id` (`google_id`),
  ADD KEY `email_google` (`email_google`);

--
-- Indeks untuk tabel `wp3f_dosen_user_roles`
--
ALTER TABLE `wp3f_dosen_user_roles`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `kombinasi_unik` (`id_user`,`id_role`,`id_kelas_matkul`),
  ADD KEY `id_user` (`id_user`),
  ADD KEY `id_role` (`id_role`),
  ADD KEY `id_kelas_matkul` (`id_kelas_matkul`);

--
-- AUTO_INCREMENT untuk tabel yang dibuang
--

--
-- AUTO_INCREMENT untuk tabel `wp3f_dosen_absensi`
--
ALTER TABLE `wp3f_dosen_absensi`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT untuk tabel `wp3f_dosen_izin`
--
ALTER TABLE `wp3f_dosen_izin`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT untuk tabel `wp3f_dosen_jadwal`
--
ALTER TABLE `wp3f_dosen_jadwal`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT untuk tabel `wp3f_dosen_jurnal`
--
ALTER TABLE `wp3f_dosen_jurnal`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT untuk tabel `wp3f_dosen_kampus_koordinat`
--
ALTER TABLE `wp3f_dosen_kampus_koordinat`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT untuk tabel `wp3f_dosen_kelas`
--
ALTER TABLE `wp3f_dosen_kelas`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT untuk tabel `wp3f_dosen_kelas_matkul`
--
ALTER TABLE `wp3f_dosen_kelas_matkul`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT untuk tabel `wp3f_dosen_kelompok`
--
ALTER TABLE `wp3f_dosen_kelompok`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT untuk tabel `wp3f_dosen_kelompok_anggota`
--
ALTER TABLE `wp3f_dosen_kelompok_anggota`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT untuk tabel `wp3f_dosen_kelompok_kehadiran`
--
ALTER TABLE `wp3f_dosen_kelompok_kehadiran`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT untuk tabel `wp3f_dosen_konten_kelas`
--
ALTER TABLE `wp3f_dosen_konten_kelas`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT untuk tabel `wp3f_dosen_mahasiswa`
--
ALTER TABLE `wp3f_dosen_mahasiswa`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT untuk tabel `wp3f_dosen_materi`
--
ALTER TABLE `wp3f_dosen_materi`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT untuk tabel `wp3f_dosen_matkul`
--
ALTER TABLE `wp3f_dosen_matkul`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT untuk tabel `wp3f_dosen_penugasan`
--
ALTER TABLE `wp3f_dosen_penugasan`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT untuk tabel `wp3f_dosen_pertemuan`
--
ALTER TABLE `wp3f_dosen_pertemuan`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT untuk tabel `wp3f_dosen_presensi`
--
ALTER TABLE `wp3f_dosen_presensi`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT untuk tabel `wp3f_dosen_roles`
--
ALTER TABLE `wp3f_dosen_roles`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT untuk tabel `wp3f_dosen_rps`
--
ALTER TABLE `wp3f_dosen_rps`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT untuk tabel `wp3f_dosen_rps_pertemuan`
--
ALTER TABLE `wp3f_dosen_rps_pertemuan`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT untuk tabel `wp3f_dosen_tugas`
--
ALTER TABLE `wp3f_dosen_tugas`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT untuk tabel `wp3f_dosen_users`
--
ALTER TABLE `wp3f_dosen_users`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT untuk tabel `wp3f_dosen_user_roles`
--
ALTER TABLE `wp3f_dosen_user_roles`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
