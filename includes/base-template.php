<?php
/**
 * ==============================================================================
 * ILMUNA LEARN — FUNCTIONAL BASE TEMPLATE RENDERER
 * ==============================================================================
 * Diadaptasi dari render_dosen_dosen_page di base-template-dosen.code-snippets.php
 * Menyediakan fungsi render_ilmuna_dashboard()
 * ==============================================================================
 */

if (!defined('BASE_PATH')) {
    require_once __DIR__ . '/../config.php';
}
require_once BASE_PATH . '/auth.php';

if (!function_exists('render_ilmuna_dashboard')) {
    function render_ilmuna_dashboard($content = '', $active_tab = 'dashboard', $customTitle = null, $customIcon = null) {
        requireAuth();

        $tabMeta = [
            'dashboard'   => ['title' => 'Dashboard Utama',        'icon' => 'solar:widget-5-bold'],
            'classes'     => ['title' => 'Data Kelas',             'icon' => 'solar:notebook-bookmark-bold'],
            'students'    => ['title' => 'Data Siswa',             'icon' => 'solar:square-academic-cap-2-bold'],
            'users'       => ['title' => 'User',                   'icon' => 'solar:shield-user-bold'],
            'assignments' => ['title' => 'Tugas',                  'icon' => 'solar:document-text-bold'],
            'attendance'  => ['title' => 'Absensi (Monitoring)',   'icon' => 'solar:checklist-minimalistic-bold'],
            'materials'   => ['title' => 'Materi',                 'icon' => 'solar:notebook-bold'],
            'subjects'    => ['title' => 'Data Mata Pelajaran',    'icon' => 'solar:book-2-bold'],
            'schedules'   => ['title' => 'Jadwal',                 'icon' => 'solar:calendar-bold'],
            'mutabaah'    => ['title' => 'Mutaba\'ah Ibadah Siswa', 'icon' => 'solar:heart-angle-bold'],
            'presensi'    => ['title' => 'Input Presensi Harian',   'icon' => 'solar:checklist-minimalistic-bold'],
            'monitoring'  => ['title' => 'Monitoring Tugas Teman',  'icon' => 'solar:clipboard-list-bold'],
            'agenda'      => ['title' => 'Agenda & Tadarus',        'icon' => 'solar:calendar-bold'],
            'nilai'       => ['title' => 'Rekap Nilai Siswa',       'icon' => 'solar:star-bold'],
            'profile'     => ['title' => 'Profil Saya',             'icon' => 'solar:user-circle-bold'],
        ];

        $pageTitle = $customTitle ?? ($tabMeta[$active_tab]['title'] ?? 'Dashboard');
        $pageIcon  = $customIcon  ?? ($tabMeta[$active_tab]['icon']  ?? 'solar:widget-5-bold');

        require_once BASE_PATH . '/includes/header.php';
        echo $content;
        require_once BASE_PATH . '/includes/footer.php';
    }
}
