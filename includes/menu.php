<?php
/**
 * ==============================================================================
 * ILMUNA LEARN — MENU CONFIGURATION (ROLE-AWARE)
 * ==============================================================================
 * File ini berisi DAFTAR MENU sidebar per role.
 * Untuk menambah, mengubah, atau menghapus menu — cukup edit file ini saja.
 *
 * Struktur setiap item:
 *   'type'    => 'category' | 'item'
 *   'label'   => Teks yang ditampilkan
 *   'href'    => (hanya type=item) URL tujuan, gunakan BASE_URL . '/path'
 *   'icon'    => (hanya type=item) Iconify icon name
 *   'route'   => (hanya type=item) Nama route untuk menentukan active state
 *   'badge'   => (opsional) ['text' => '...', 'style' => '...']
 *   'onclick' => (opsional) JS inline, misal 'ilmunaShowLogout()'
 *   'class'   => (opsional) Extra CSS class, misal 'logout-btn'
 *
 * Role yang tersedia: admin | ketua_kelas | siswa
 * ==============================================================================
 */

if (!defined('BASE_PATH')) {
    exit;
}

$sidebarMenus = [

    /* ======================================================================
     * ADMIN
     * ====================================================================== */
    'admin' => [

        // ─── Menu Utama ───────────────────────────────────────────────────────
        ['type' => 'category', 'label' => 'Menu Utama'],
        [
            'type'  => 'item',
            'label' => 'Dashboard',
            'href'  => BASE_URL . '/dashboard/admin',
            'icon'  => 'solar:widget-5-bold',
            'route' => 'index',
        ],

        // ─── Kesiswaan & Administrasi ─────────────────────────────────────────
        ['type' => 'category', 'label' => 'Kesiswaan & Administrasi'],
        [
            'type'  => 'item',
            'label' => 'Identitas Sekolah',
            'href'  => BASE_URL . '/dashboard/admin/school-profile',
            'icon'  => 'solar:diploma-bold',
            'route' => 'school-profile',
            'badge' => [
                'text'  => 'PROFIL',
                'style' => 'background: rgba(13, 122, 135, 0.15); color: #0D7A87; box-shadow: none;',
            ],
        ],
        [
            'type'  => 'item',
            'label' => 'Data Kelas (Rombel)',
            'href'  => BASE_URL . '/dashboard/admin/classes',
            'icon'  => 'solar:buildings-2-bold',
            'route' => 'classes',
        ],
        [
            'type'  => 'item',
            'label' => 'Mata Pelajaran & Rombel',
            'href'  => BASE_URL . '/dashboard/admin/subjects',
            'icon'  => 'solar:book-2-bold',
            'route' => 'subjects',
        ],
        [
            'type'  => 'item',
            'label' => 'Data Guru',
            'href'  => BASE_URL . '/dashboard/admin/teachers',
            'icon'  => 'solar:user-speak-bold',
            'route' => 'teachers',
        ],
        [
            'type'  => 'item',
            'label' => 'Data Siswa',
            'href'  => BASE_URL . '/dashboard/admin/students',
            'icon'  => 'solar:square-academic-cap-2-bold',
            'route' => 'students',
        ],
        [
            'type'  => 'item',
            'label' => 'Manajemen Pengguna',
            'href'  => BASE_URL . '/dashboard/admin/users',
            'icon'  => 'solar:shield-user-bold',
            'route' => 'users',
        ],

        // ─── CBT & Bank Asesmen ───────────────────────────────────────────────
        ['type' => 'category', 'label' => 'CBT & Bank Asesmen'],
        [
            'type'  => 'item',
            'label' => 'Bank & Master Asesmen',
            'href'  => BASE_URL . '/dashboard/admin/assessments',
            'icon'  => 'solar:document-text-bold',
            'route' => 'assessments',
        ],
        [
            'type'  => 'item',
            'label' => 'Bank Butir Soal',
            'href'  => BASE_URL . '/dashboard/admin/assessment-questions',
            'icon'  => 'solar:clipboard-list-bold',
            'route' => 'assessment-questions',
            'badge' => [
                'text'  => 'DOCS',
                'style' => 'background: rgba(13, 122, 135, 0.15); color: #0D7A87; box-shadow: none;',
            ],
        ],
        [
            'type'  => 'item',
            'label' => 'Akses Ujian Siswa (CBT)',
            'href'  => BASE_URL . '/dashboard/admin/assessment-access',
            'icon'  => 'solar:key-bold',
            'route' => 'assessment-access',
            'badge' => ['text' => 'BARU'],
        ],
        [
            'type'  => 'item',
            'label' => 'Nilai & Hasil Asesmen',
            'href'  => BASE_URL . '/dashboard/admin/assessment-results',
            'icon'  => 'solar:chart-square-bold',
            'route' => 'assessment-results',
            'badge' => [
                'text'  => 'REKAP',
                'style' => 'background: rgba(16, 185, 129, 0.15); color: #059669; box-shadow: none;',
            ],
        ],

        // ─── Presensi & Geofencing GPS ────────────────────────────────────────
        ['type' => 'category', 'label' => 'Presensi & Geofencing GPS'],
        [
            'type'  => 'item',
            'label' => 'Master Presensi QR',
            'href'  => BASE_URL . '/dashboard/admin/presensi-master',
            'icon'  => 'solar:qr-code-bold',
            'route' => 'presensi-master',
        ],
        [
            'type'  => 'item',
            'label' => 'Log Absensi & Izin',
            'href'  => BASE_URL . '/dashboard/admin/attendance',
            'icon'  => 'solar:checklist-minimalistic-bold',
            'route' => 'attendance',
        ],
        [
            'type'  => 'item',
            'label' => 'Titik Geofencing GPS',
            'href'  => BASE_URL . '/dashboard/admin/geofencing',
            'icon'  => 'solar:map-point-wave-bold',
            'route' => 'geofencing',
        ],

        // ─── Akademik & Kurikulum Merdeka ─────────────────────────────────────
        ['type' => 'category', 'label' => 'Akademik & Kurikulum Merdeka'],
        [
            'type'  => 'item',
            'label' => 'Kurikulum & Kalender',
            'href'  => BASE_URL . '/dashboard/admin/curriculum',
            'icon'  => 'solar:diploma-verified-bold',
            'route' => 'curriculum',
        ],
        [
            'type'  => 'item',
            'label' => 'Rekap Rapor & P5',
            'href'  => BASE_URL . '/dashboard/admin/reports',
            'icon'  => 'solar:document-bold',
            'route' => 'reports',
        ],
        [
            'type'  => 'item',
            'label' => 'Jadwal Pelajaran KBM',
            'href'  => BASE_URL . '/dashboard/admin/schedules',
            'icon'  => 'solar:calendar-bold',
            'route' => 'schedules',
        ],
        [
            'type'  => 'item',
            'label' => 'Prota & Prosem',
            'href'  => BASE_URL . '/dashboard/guru/prota-prosem',
            'icon'  => 'solar:calendar-mark-bold',
            'route' => 'prota-prosem',
            'badge' => [
                'text'  => 'PDP',
                'style' => 'background: rgba(245, 158, 11, 0.15); color: #B45309; box-shadow: none;',
            ],
        ],
        [
            'type'  => 'item',
            'label' => 'Materi & Modul Pembelajaran',
            'href'  => BASE_URL . '/dashboard/guru/materi',
            'icon'  => 'solar:notebook-bold',
            'route' => 'materi',
        ],
        [
            'type'  => 'item',
            'label' => 'Tugas & Portofolio',
            'href'  => BASE_URL . '/dashboard/guru/tugas',
            'icon'  => 'solar:document-text-bold',
            'route' => 'tugas',
        ],

        // ─── Monitoring Khusus ────────────────────────────────────────────────
        ['type' => 'category', 'label' => 'Monitoring Khusus'],
        [
            'type'  => 'item',
            'label' => "Mutaba'ah Ibadah Siswa",
            'href'  => BASE_URL . '/dashboard/admin/mutabaah',
            'icon'  => 'solar:heart-angle-bold',
            'route' => 'mutabaah',
            'badge' => ['text' => 'PAI'],
        ],

        // ─── Sistem ───────────────────────────────────────────────────────────
        [
            'type'  => 'item',
            'label' => 'Pindah Peran',
            'href'  => BASE_URL . '/gateway?force=1',
            'icon'  => 'solar:users-group-two-rounded-bold',
            'route' => '',
        ],
    ],

    /* ======================================================================
     * GURU
     * ====================================================================== */
    'guru' => [

        // ─── Menu Utama ───────────────────────────────────────────────────────
        ['type' => 'category', 'label' => 'Menu Utama'],
        [
            'type'  => 'item',
            'label' => 'Dashboard Guru',
            'href'  => BASE_URL . '/dashboard/guru',
            'icon'  => 'solar:widget-5-bold',
            'route' => 'index',
        ],

        // ─── CBT & Asesmen Sumatif ─────────────────────────────────────────────
        ['type' => 'category', 'label' => 'CBT & Rekap Nilai'],
        [
            'type'  => 'item',
            'label' => 'Rekap & Nilai Akhir (KKTP)',
            'href'  => BASE_URL . '/dashboard/guru/reports',
            'icon'  => 'solar:diploma-verified-bold',
            'route' => 'reports',
            'badge' => [
                'text'  => 'GURU',
                'style' => 'background: rgba(13, 122, 135, 0.15); color: #0D7A87; box-shadow: none;',
            ],
        ],
        [
            'type'  => 'item',
            'label' => 'Penilaian Afektif & Praktik',
            'href'  => BASE_URL . '/dashboard/guru/penilaian',
            'icon'  => 'solar:medal-star-circle-bold',
            'route' => 'penilaian',
            'badge' => [
                'text'  => 'BARU',
                'style' => 'background: rgba(245, 158, 11, 0.15); color: #B45309; box-shadow: none;',
            ],
        ],

        // ─── Pembelajaran & KBM ───────────────────────────────────────────────
        ['type' => 'category', 'label' => 'Pembelajaran & KBM'],
        [
            'type'  => 'item',
            'label' => 'Presensi & Absensi KBM',
            'href'  => BASE_URL . '/dashboard/guru/absensi',
            'icon'  => 'solar:checklist-minimalistic-bold',
            'route' => 'absensi',
            'badge' => [
                'text'  => 'KBM',
                'style' => 'background: rgba(13, 122, 135, 0.15); color: #0D7A87; box-shadow: none;',
            ],
        ],
        [
            'type'  => 'item',
            'label' => 'Materi & Modul Pembelajaran',
            'href'  => BASE_URL . '/dashboard/guru/materi',
            'icon'  => 'solar:notebook-bold',
            'route' => 'materi',
        ],
        [
            'type'  => 'item',
            'label' => 'Tugas & Portofolio',
            'href'  => BASE_URL . '/dashboard/guru/tugas',
            'icon'  => 'solar:document-text-bold',
            'route' => 'tugas',
        ],
        [
            'type'  => 'item',
            'label' => 'Jadwal Mengajar',
            'href'  => BASE_URL . '/dashboard/guru/schedules',
            'icon'  => 'solar:calendar-bold',
            'route' => 'schedules',
        ],
        [
            'type'  => 'item',
            'label' => 'Prota & Prosem',
            'href'  => BASE_URL . '/dashboard/guru/prota-prosem',
            'icon'  => 'solar:calendar-mark-bold',
            'route' => 'prota-prosem',
            'badge' => [
                'text'  => 'PDP',
                'style' => 'background: rgba(245, 158, 11, 0.15); color: #B45309; box-shadow: none;',
            ],
        ],

        // ─── Sistem ───────────────────────────────────────────────────────────
        ['type' => 'category', 'label' => 'Sistem'],
        [
            'type'  => 'item',
            'label' => 'Pindah Peran',
            'href'  => BASE_URL . '/gateway?force=1',
            'icon'  => 'solar:users-group-two-rounded-bold',
            'route' => '',
        ],
    ],

    /* ======================================================================
     * KETUA KELAS
     * ====================================================================== */
    'ketua_kelas' => [

        // ─── Menu Utama ───────────────────────────────────────────────────────
        ['type' => 'category', 'label' => 'Menu Utama'],
        [
            'type'  => 'item',
            'label' => 'Dashboard Kelas',
            'href'  => BASE_URL . '/dashboard/ketua-kelas',
            'icon'  => 'solar:widget-5-bold',
            'route' => 'index',
        ],

        // ─── Tugas Koordinator ────────────────────────────────────────────────
        ['type' => 'category', 'label' => 'Tugas Koordinator'],
        [
            'type'  => 'item',
            'label' => 'Verifikasi Perizinan Siswa',
            'href'  => BASE_URL . '/dashboard/ketua-kelas/presensi',
            'icon'  => 'solar:shield-check-bold',
            'route' => 'presensi',
        ],
        [
            'type'  => 'item',
            'label' => 'Monitoring Tugas Teman',
            'href'  => BASE_URL . '/dashboard/ketua-kelas/monitoring',
            'icon'  => 'solar:clipboard-list-bold',
            'route' => 'monitoring',
        ],
        [
            'type'  => 'item',
            'label' => 'Agenda & Tadarus',
            'href'  => BASE_URL . '/dashboard/ketua-kelas/agenda',
            'icon'  => 'solar:calendar-bold',
            'route' => 'agenda',
        ],

        // ─── Sistem ───────────────────────────────────────────────────────────
        ['type' => 'category', 'label' => 'Sistem'],
        [
            'type'  => 'item',
            'label' => 'Pindah Peran',
            'href'  => BASE_URL . '/gateway?force=1',
            'icon'  => 'solar:users-group-two-rounded-bold',
            'route' => '',
        ],
    ],

    /* ======================================================================
     * SISWA
     * ====================================================================== */
    'siswa' => [

        // ─── Menu Utama ───────────────────────────────────────────────────────
        ['type' => 'category', 'label' => 'Menu Utama'],
        [
            'type'  => 'item',
            'label' => 'Dashboard Siswa',
            'href'  => BASE_URL . '/dashboard/siswa',
            'icon'  => 'solar:widget-5-bold',
            'route' => 'index',
        ],
        [
            'type'  => 'item',
            'label' => 'Presensi & Absensi KBM',
            'href'  => BASE_URL . '/dashboard/siswa/absensi',
            'icon'  => 'solar:qr-code-bold',
            'route' => 'absensi',
            'badge' => ['text' => 'Live QR', 'class' => 'bg-emerald-500/10 text-emerald-600'],
        ],


        // ─── Belajar Mandiri ──────────────────────────────────────────────────
        ['type' => 'category', 'label' => 'Belajar Mandiri'],
        [
            'type'  => 'item',
            'label' => 'Modul Pembelajaran',
            'href'  => BASE_URL . '/dashboard/siswa/materi',
            'icon'  => 'solar:notebook-bold',
            'route' => 'materi',
        ],
        [
            'type'  => 'item',
            'label' => 'Tugas & Ujian PAI',
            'href'  => BASE_URL . '/dashboard/siswa/tugas',
            'icon'  => 'solar:document-text-bold',
            'route' => 'tugas',
        ],
        [
            'type'  => 'item',
            'label' => 'Rekap Nilai Saya',
            'href'  => BASE_URL . '/dashboard/siswa/nilai',
            'icon'  => 'solar:star-bold',
            'route' => 'nilai',
        ],
        [
            'type'  => 'item',
            'label' => "Mutaba'ah Yaumiyah",
            'href'  => BASE_URL . '/dashboard/siswa/mutabaah',
            'icon'  => 'solar:heart-angle-bold',
            'route' => 'mutabaah',
            'badge' => ['text' => 'Harian'],
        ],

        // ─── Sistem ───────────────────────────────────────────────────────────
        ['type' => 'category', 'label' => 'Sistem'],
        [
            'type'  => 'item',
            'label' => 'Pindah Peran',
            'href'  => BASE_URL . '/gateway?force=1',
            'icon'  => 'solar:users-group-two-rounded-bold',
            'route' => '',
        ],
    ],

];

/* ============================================================================
 * MOBILE BOTTOM NAV — Footer (role-aware)
 * ============================================================================
 * 'primary' => Tab-tab yang tampil langsung di bottom bar (max 4 item).
 *   'label' => Teks singkat
 *   'icon'  => Iconify icon name (solar:*)
 *   'href'  => URL tujuan
 *   'match' => Basename file untuk menentukan tab aktif
 *
 * 'sheet'   => Item tambahan di slide-up sheet (tombol "⋯ Menu").
 *   'label'   => Teks
 *   'icon'    => Iconify icon name (solar:*)
 *   'href'    => (opsional) URL tujuan
 *   'onclick' => (opsional) JS inline
 *   'danger'  => (opsional) true → warna merah
 * ============================================================================ */
$mobileNavMenus = [

    /* ------------------------------------------------------------------
     * ADMIN
     * ------------------------------------------------------------------ */
    'admin' => [
        'primary' => [
            ['label' => 'Dash',    'icon' => 'solar:widget-5-bold',              'href' => BASE_URL . '/dashboard/admin',            'match' => 'index'],
            ['label' => 'Kelas',   'icon' => 'solar:buildings-2-bold',           'href' => BASE_URL . '/dashboard/admin/classes',     'match' => 'classes'],
            ['label' => 'Siswa',   'icon' => 'solar:square-academic-cap-2-bold', 'href' => BASE_URL . '/dashboard/admin/students',    'match' => 'students'],
            ['label' => 'Asesmen', 'icon' => 'solar:document-text-bold',         'href' => BASE_URL . '/dashboard/admin/assessments', 'match' => 'assessments'],
        ],
        'sheet' => [
            ['label' => 'Identitas Sekolah', 'icon' => 'solar:diploma-bold',                'href' => BASE_URL . '/dashboard/admin/school-profile'],
            ['label' => 'Guru',              'icon' => 'solar:user-speak-bold',             'href' => BASE_URL . '/dashboard/admin/teachers'],
            ['label' => 'Pengguna',          'icon' => 'solar:shield-user-bold',            'href' => BASE_URL . '/dashboard/admin/users'],
            ['label' => 'Mata Pelajaran',    'icon' => 'solar:book-2-bold',                 'href' => BASE_URL . '/dashboard/admin/subjects'],
            ['label' => 'Butir Soal CBT',    'icon' => 'solar:clipboard-list-bold',         'href' => BASE_URL . '/dashboard/admin/assessment-questions'],
            ['label' => 'Akses Ujian (CBT)', 'icon' => 'solar:key-bold',                    'href' => BASE_URL . '/dashboard/admin/assessment-access'],
            ['label' => 'Hasil Asesmen',     'icon' => 'solar:chart-square-bold',           'href' => BASE_URL . '/dashboard/admin/assessment-results'],
            ['label' => 'Master Presensi QR','icon' => 'solar:qr-code-bold',                'href' => BASE_URL . '/dashboard/admin/presensi-master'],
            ['label' => 'Log Absensi Siswa', 'icon' => 'solar:checklist-minimalistic-bold', 'href' => BASE_URL . '/dashboard/admin/attendance'],
            ['label' => 'Geofencing GPS',    'icon' => 'solar:map-point-wave-bold',         'href' => BASE_URL . '/dashboard/admin/geofencing'],
            ['label' => 'Kurikulum',         'icon' => 'solar:diploma-verified-bold',        'href' => BASE_URL . '/dashboard/admin/curriculum'],
            ['label' => 'Jadwal Pelajaran',  'icon' => 'solar:calendar-bold',               'href' => BASE_URL . '/dashboard/admin/schedules'],
            ['label' => 'Modul Ajar',        'icon' => 'solar:notebook-bold',               'href' => BASE_URL . '/dashboard/guru/materi'],
            ['label' => 'Tugas & Portofolio','icon' => 'solar:document-text-bold',          'href' => BASE_URL . '/dashboard/guru/tugas'],
            ['label' => "Mutaba'ah Ibadah",  'icon' => 'solar:heart-angle-bold',            'href' => BASE_URL . '/dashboard/admin/mutabaah'],
            ['label' => 'Pindah Peran',      'icon' => 'solar:users-group-two-rounded-bold','href' => BASE_URL . '/gateway?force=1'],
            ['label' => 'Keluar',            'icon' => 'solar:logout-2-bold',               'danger' => true, 'onclick' => 'ilmunaShowLogout()'],
        ],
    ],

    /* ------------------------------------------------------------------
     * GURU
     * ------------------------------------------------------------------ */
    'guru' => [
        'primary' => [
            ['label' => 'Dash',     'icon' => 'solar:widget-5-bold',              'href' => BASE_URL . '/dashboard/guru',          'match' => 'index'],
            ['label' => 'Presensi', 'icon' => 'solar:checklist-minimalistic-bold', 'href' => BASE_URL . '/dashboard/guru/absensi', 'match' => 'absensi'],
            ['label' => 'Materi',   'icon' => 'solar:notebook-bold',               'href' => BASE_URL . '/dashboard/guru/materi',  'match' => 'materi'],
            ['label' => 'Tugas',    'icon' => 'solar:document-text-bold',          'href' => BASE_URL . '/dashboard/guru/tugas',   'match' => 'tugas'],
        ],
        'sheet' => [
            ['label' => 'Scan QR Presensi', 'icon' => 'solar:qr-code-bold',                 'href' => BASE_URL . '/dashboard/guru/scan-qr'],
            ['label' => 'Rekap Nilai KKTP', 'icon' => 'solar:diploma-verified-bold',        'href' => BASE_URL . '/dashboard/guru/reports'],
            ['label' => 'Nilai Sikap & Praktik', 'icon' => 'solar:medal-star-circle-bold', 'href' => BASE_URL . '/dashboard/guru/penilaian'],
            ['label' => 'Jadwal Mengajar',  'icon' => 'solar:calendar-bold',               'href' => BASE_URL . '/dashboard/guru/schedules'],
            ['label' => 'Pindah Peran',     'icon' => 'solar:users-group-two-rounded-bold', 'href' => BASE_URL . '/gateway?force=1'],
            ['label' => 'Beranda Utama',    'icon' => 'solar:home-2-bold',                  'href' => BASE_URL . '/'],
            ['label' => 'Keluar',           'icon' => 'solar:logout-2-bold',                'danger' => true, 'onclick' => 'ilmunaShowLogout()'],
        ],
    ],

    /* ------------------------------------------------------------------
     * KETUA KELAS
     * ------------------------------------------------------------------ */
    'ketua_kelas' => [
        'primary' => [
            ['label' => 'Dash',     'icon' => 'solar:widget-5-bold',               'href' => BASE_URL . '/dashboard/ketua-kelas',            'match' => 'index'],
            ['label' => 'Presensi', 'icon' => 'solar:checklist-minimalistic-bold', 'href' => BASE_URL . '/dashboard/ketua-kelas/presensi',   'match' => 'presensi'],
            ['label' => 'Tugas',    'icon' => 'solar:clipboard-list-bold',          'href' => BASE_URL . '/dashboard/ketua-kelas/monitoring', 'match' => 'monitoring'],
            ['label' => 'Agenda',   'icon' => 'solar:calendar-bold',               'href' => BASE_URL . '/dashboard/ketua-kelas/agenda',     'match' => 'agenda'],
        ],
        'sheet' => [
            ['label' => 'Pindah Peran',  'icon' => 'solar:users-group-two-rounded-bold', 'href' => BASE_URL . '/gateway?force=1'],
            ['label' => 'Beranda Utama', 'icon' => 'solar:home-2-bold',                  'href' => BASE_URL . '/'],
            ['label' => 'Keluar',        'icon' => 'solar:logout-2-bold',                'danger' => true, 'onclick' => 'ilmunaShowLogout()'],
        ],
    ],

    /* ------------------------------------------------------------------
     * SISWA
     * ------------------------------------------------------------------ */
    'siswa' => [
        'primary' => [
            ['label' => 'Dash',    'icon' => 'solar:widget-5-bold',      'href' => BASE_URL . '/dashboard/siswa',        'match' => 'index'],
            ['label' => 'Absensi', 'icon' => 'solar:qr-code-bold',        'href' => BASE_URL . '/dashboard/siswa/absensi', 'match' => 'absensi'],
            ['label' => 'Materi',  'icon' => 'solar:notebook-bold',      'href' => BASE_URL . '/dashboard/siswa/materi',  'match' => 'materi'],
            ['label' => 'Tugas',   'icon' => 'solar:document-text-bold', 'href' => BASE_URL . '/dashboard/siswa/tugas',   'match' => 'tugas'],
        ],
        'sheet' => [
            ['label' => 'Nilai Saya',        'icon' => 'solar:star-bold',                  'href' => BASE_URL . '/dashboard/siswa/nilai'],
            ['label' => "Mutaba'ah Yaumiyah",'icon' => 'solar:heart-angle-bold',           'href' => BASE_URL . '/dashboard/siswa/mutabaah'],
            ['label' => 'Pindah Peran',      'icon' => 'solar:users-group-two-rounded-bold', 'href' => BASE_URL . '/gateway?force=1'],
            ['label' => 'Beranda Utama',     'icon' => 'solar:home-2-bold',                  'href' => BASE_URL . '/'],
            ['label' => 'Keluar',            'icon' => 'solar:logout-2-bold',                'danger' => true, 'onclick' => 'ilmunaShowLogout()'],
        ],
    ],

];
