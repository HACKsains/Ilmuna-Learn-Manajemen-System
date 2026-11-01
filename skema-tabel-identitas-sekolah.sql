-- ==============================================================================
-- SKEMA TABEL: identitas_sekolah & VIEW sekolah
-- Deskripsi: Tabel profil dan identitas lembaga pendidikan lengkap mencakup
--            legalitas, kontak, lokasi, branding/aset digital (logo, stempel, kop),
--            hingga pimpinan sekolah (Kepala Sekolah, Waka Kurikulum,
--            Waka Kesiswaan, Waka Sarpras, Waka Humas, Kepala TU, Bendahara, Proktor CBT).
-- ==============================================================================

CREATE TABLE IF NOT EXISTS `identitas_sekolah` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    
    -- 1. IDENTITAS POKOK & LEGALITAS SEKOLAH
    `nama_sekolah` VARCHAR(255) NOT NULL COMMENT 'Nama resmi sekolah lengkap sesuai izin operasional',
    `nama_singkat` VARCHAR(100) NULL COMMENT 'Nama singkatan/alias sekolah (misal: SMAN 1 Bojonegoro)',
    `npsn` VARCHAR(20) NULL UNIQUE COMMENT 'Nomor Pokok Sekolah Nasional (8 digit)',
    `nss` VARCHAR(30) NULL COMMENT 'Nomor Statistik Sekolah',
    `nds` VARCHAR(30) NULL COMMENT 'Nomor Data Sekolah',
    `jenjang` ENUM('SD', 'MI', 'SMP', 'MTs', 'SMA', 'MA', 'SMK', 'MAK', 'PKBM', 'Perguruan Tinggi', 'Lainnya') NOT NULL DEFAULT 'SMA',
    `status_sekolah` ENUM('Negeri', 'Swasta') NOT NULL DEFAULT 'Swasta',
    `akreditasi` ENUM('A', 'B', 'C', 'Belum Terakreditasi') NOT NULL DEFAULT 'A',
    `kurikulum_utama` VARCHAR(100) NOT NULL DEFAULT 'Kurikulum Merdeka',
    `sk_pendirian` VARCHAR(150) NULL COMMENT 'Nomor SK Pendirian Sekolah',
    `tanggal_sk_pendirian` DATE NULL,
    `sk_izin_operasional` VARCHAR(150) NULL COMMENT 'Nomor SK Izin Operasional',
    `tanggal_sk_izin_operasional` DATE NULL,
    `kementerian_pembina` ENUM('Kemendikbudristek', 'Kemenag', 'Kemenperin', 'Lainnya') NOT NULL DEFAULT 'Kemendikbudristek',

    -- 2. ALAMAT & GEOLOKASI LENGKAP
    `alamat_jalan` TEXT NULL COMMENT 'Alamat jalan, nomor, gang, RT/RW',
    `rt` VARCHAR(10) NULL,
    `rw` VARCHAR(10) NULL,
    `dusun_lingkungan` VARCHAR(100) NULL,
    `desa_kelurahan` VARCHAR(100) NULL,
    `kecamatan` VARCHAR(100) NULL,
    `kabupaten_kota` VARCHAR(100) NULL,
    `provinsi` VARCHAR(100) NULL,
    `kode_pos` VARCHAR(10) NULL,
    `latitude` DECIMAL(10, 8) NULL COMMENT 'Titik koordinat GPS latitude',
    `longitude` DECIMAL(11, 8) NULL COMMENT 'Titik koordinat GPS longitude',
    `radius_presensi_meter` INT NOT NULL DEFAULT 100 COMMENT 'Radius toleransi presensi GPS (meter)',

    -- 3. KONTAK & MEDIA SOSIAL RESMI
    `telepon` VARCHAR(30) NULL,
    `no_hp_wa` VARCHAR(25) NULL COMMENT 'Nomor WhatsApp resmi humas/sekolah',
    `fax` VARCHAR(30) NULL,
    `email` VARCHAR(150) NULL,
    `website` VARCHAR(150) NULL,
    `instagram` VARCHAR(100) NULL,
    `facebook` VARCHAR(100) NULL,
    `youtube` VARCHAR(150) NULL,
    `tiktok` VARCHAR(100) NULL,

    -- 4. LOGO, BRANDING & ASET VISUAL DOKUMEN (KOP, STEMPEL, TTD)
    `logo_sekolah` VARCHAR(255) NULL COMMENT 'File path logo sekolah',
    `logo_kementerian` VARCHAR(255) NULL COMMENT 'File path logo Kemendikbud/Kemenag/Pemda',
    `kop_surat_path` VARCHAR(255) NULL COMMENT 'Gambar banner kop surat resmi siap cetak',
    `cap_stempel_path` VARCHAR(255) NULL COMMENT 'Gambar stempel basah transparan digital (PNG)',
    `semboyan_motto` VARCHAR(255) NULL COMMENT 'Motto / Slogan sekolah',
    `visi` TEXT NULL,
    `misi` TEXT NULL,

    -- 5. KEPALA SEKOLAH
    `nama_kepala_sekolah` VARCHAR(150) NULL,
    `gelar_kepala_sekolah` VARCHAR(50) NULL,
    `nip_kepala_sekolah` VARCHAR(50) NULL,
    `nuptk_kepala_sekolah` VARCHAR(50) NULL,
    `ttd_kepala_sekolah` MEDIUMTEXT NULL COMMENT 'Tanda tangan digital (SVG Vektor murni atau path gambar)',

    -- 6. WAKIL KEPALA SEKOLAH (WAKA) & PEJABAT STRUKTURAL
    -- a. Waka Kurikulum (Bidang Akademik & Pengajaran)
    `nama_waka_kurikulum` VARCHAR(150) NULL,
    `nip_waka_kurikulum` VARCHAR(50) NULL,
    `nuptk_waka_kurikulum` VARCHAR(50) NULL,
    `no_hp_waka_kurikulum` VARCHAR(25) NULL,
    `ttd_waka_kurikulum` MEDIUMTEXT NULL COMMENT 'Tanda tangan digital (SVG Vektor murni atau path gambar)',

    -- b. Waka Kesiswaan (Bidang Kesiswaan & Kedisiplinan)
    `nama_waka_kesiswaan` VARCHAR(150) NULL,
    `nip_waka_kesiswaan` VARCHAR(50) NULL,
    `nuptk_waka_kesiswaan` VARCHAR(50) NULL,
    `no_hp_waka_kesiswaan` VARCHAR(25) NULL,
    `ttd_waka_kesiswaan` MEDIUMTEXT NULL COMMENT 'Tanda tangan digital (SVG Vektor murni atau path gambar)',

    -- c. Waka Sarana & Prasarana (Sarpras)
    `nama_waka_sarpras` VARCHAR(150) NULL,
    `nip_waka_sarpras` VARCHAR(50) NULL,
    `nuptk_waka_sarpras` VARCHAR(50) NULL,
    `no_hp_waka_sarpras` VARCHAR(25) NULL,
    `ttd_waka_sarpras` MEDIUMTEXT NULL COMMENT 'Tanda tangan digital (SVG Vektor murni atau path gambar)',

    -- d. Waka Hubungan Masyarakat (Humas) / Hubin
    `nama_waka_humas` VARCHAR(150) NULL,
    `nip_waka_humas` VARCHAR(50) NULL,
    `nuptk_waka_humas` VARCHAR(50) NULL,
    `no_hp_waka_humas` VARCHAR(25) NULL,
    `ttd_waka_humas` MEDIUMTEXT NULL COMMENT 'Tanda tangan digital (SVG Vektor murni atau path gambar)',

    -- e. Kepala Tata Usaha (TU)
    `nama_kepala_tu` VARCHAR(150) NULL,
    `nip_kepala_tu` VARCHAR(50) NULL,
    `nuptk_kepala_tu` VARCHAR(50) NULL,
    `no_hp_kepala_tu` VARCHAR(25) NULL,
    `ttd_kepala_tu` MEDIUMTEXT NULL COMMENT 'Tanda tangan digital (SVG Vektor murni atau path gambar)',

    -- f. Bendahara Sekolah / Keuangan
    `nama_bendahara` VARCHAR(150) NULL,
    `nip_bendahara` VARCHAR(50) NULL,
    `nuptk_bendahara` VARCHAR(50) NULL,
    `no_hp_bendahara` VARCHAR(25) NULL,
    `ttd_bendahara` MEDIUMTEXT NULL COMMENT 'Tanda tangan digital (SVG Vektor murni atau path gambar)',

    -- g. Koordinator CBT / Proktor Utama Ujian
    `nama_proktor_cbt` VARCHAR(150) NULL,
    `nip_proktor_cbt` VARCHAR(50) NULL,
    `no_hp_proktor_cbt` VARCHAR(25) NULL,

    -- 7. PARAMETER DOKUMEN, RAPOR & TITIMANGSA
    `titimangsa_tempat` VARCHAR(100) NOT NULL DEFAULT 'Bojonegoro' COMMENT 'Kota penerbitan dokumen/rapor/ijazah',
    `tahun_pelajaran_aktif` VARCHAR(20) NOT NULL DEFAULT '2026/2027',
    `semester_aktif` ENUM('Ganjil', 'Genap') NOT NULL DEFAULT 'Ganjil',
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,

    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Profil & Identitas Lengkap Sekolah Beserta Pimpinan dan Pejabat Struktural';

-- Alias VIEW agar query pemanggilan tabel 'sekolah' atau 'identitas_sekolah' keduanya dapat berjalan
CREATE OR REPLACE VIEW `sekolah` AS 
SELECT * FROM `identitas_sekolah`;

