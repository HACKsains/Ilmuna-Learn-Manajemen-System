<?php
/**
 * ==============================================================================
 * ILMUNA LEARN — ENGINE KALKULASI & REKAPITULASI NILAI AKHIR RAPOR (GURU)
 * ==============================================================================
 * Mengelola:
 *   1. Persetujuan Remedial Guru (Nilai Remidi -> Disetujui Sebesar KKTP / Remidi Ulang)
 *   2. Generator Nilai Akhir Rapor & Kelulusan KKTP (rekap_nilai_siswa_mapel)
 *   3. Deskripsi Capaian Kompetensi Kurikulum Merdeka
 * ==============================================================================
 */

if (!defined('BASE_PATH')) {
    require_once __DIR__ . '/../config.php';
}
if (!function_exists('getDBConnection')) {
    require_once __DIR__ . '/../database.php';
}

/**
 * Persetujuan Remedial oleh Guru Pengampu
 * Nilai asli (nilai_angka/nilai) TETAP UTUH (tidak dirubah).
 * Nilai akhir yang diakui setelah disetujui berubah menjadi nilai = KKTP.
 *
 * @param PDO $pdo
 * @param string $tipe ('sumatif' | 'formatif' | 'tugas')
 * @param int $itemId (id_asesmen_sumatif | id_asesmen_formatif | id_tugas)
 * @param int $idSiswa
 * @param float $kktp
 * @param string $catatan
 * @return array
 */
function ilmuna_setujui_remedial($pdo, $tipe, $itemId, $idSiswa, $kktp, $catatan = '') {
    $tipe = strtolower(trim($tipe));
    $itemId = (int)$itemId;
    $idSiswa = (int)$idSiswa;
    $kktp = (float)$kktp;
    if ($kktp <= 0) $kktp = 75.00;

    $catatan = trim($catatan) ?: 'Remedial telah disetujui Guru Pengampu. Nilai diakui setara batas KKTP.';

    try {
        if ($tipe === 'sumatif') {
            $stmtSmMeta = $pdo->prepare("SELECT jenis_sumatif, kktp FROM asesmen_sumatif WHERE id = ? LIMIT 1");
            $stmtSmMeta->execute([$itemId]);
            $smMeta = $stmtSmMeta->fetch(PDO::FETCH_ASSOC);
            $isPan = $smMeta ? isAssessmentPAN('sumatif', $smMeta['jenis_sumatif'] ?? '') : false;
            $namaBatas = $isPan ? 'Norma Kelas (PAN)' : 'KKTP (PAP)';

            $check = $pdo->prepare("
                SELECT id, nilai_angka, nilai_remedial, nilai_kktp, status_remedial 
                FROM nilai_asesmen_sumatif 
                WHERE id_asesmen_sumatif = ? AND id_siswa = ? 
                LIMIT 1
            ");
            $check->execute([$itemId, $idSiswa]);
            $row = $check->fetch(PDO::FETCH_ASSOC);

            if (!$row) {
                return ['success' => false, 'message' => 'Data nilai asesmen sumatif siswa tidak ditemukan.'];
            }

            $ambangBatasDb = (float)($row['nilai_kktp'] ?? $kktp);
            if ($row['nilai_angka'] >= $ambangBatasDb) {
                return ['success' => false, 'message' => "Nilai siswa sudah mencapai/melebihi batas {$namaBatas}, tidak memerlukan persetujuan remedial."];
            }

            if ($row['nilai_remedial'] === null || $row['nilai_remedial'] === '') {
                return ['success' => false, 'message' => 'Siswa belum memiliki catatan nilai remedial untuk disetujui.'];
            }

            $stmtUp = $pdo->prepare("
                UPDATE nilai_asesmen_sumatif
                SET nilai_akhir_remedial = ?,
                    status_remedial = 'Disetujui',
                    status_tuntas = 'Tuntas',
                    catatan_remedial = ?,
                    waktu_penilaian = NOW()
                WHERE id = ?
            ");
            $stmtUp->execute([$kktp, $catatan, $row['id']]);

            return [
                'success' => true,
                'message' => "Remedial berhasil disetujui! Nilai efektif menjadi setara {$namaBatas} (" . number_format($kktp, 2) . ") tanpa mengubah nilai asli.",
                'data' => [
                    'id_siswa' => $idSiswa,
                    'id_item' => $itemId,
                    'nilai_asli' => (float)$row['nilai_angka'],
                    'nilai_remedial' => (float)$row['nilai_remedial'],
                    'nilai_akhir_remedial' => $kktp,
                    'status_remedial' => 'Disetujui',
                    'status_tuntas' => 'Tuntas'
                ]
            ];
        } elseif ($tipe === 'formatif') {
            $check = $pdo->prepare("
                SELECT id, nilai_angka, nilai_remedial, nilai_kktp 
                FROM nilai_asesmen_formatif 
                WHERE id_asesmen_formatif = ? AND id_siswa = ? 
                LIMIT 1
            ");
            $check->execute([$itemId, $idSiswa]);
            $row = $check->fetch(PDO::FETCH_ASSOC);

            if (!$row) {
                return ['success' => false, 'message' => 'Data nilai formatif siswa tidak ditemukan.'];
            }

            $stmtUp = $pdo->prepare("
                UPDATE nilai_asesmen_formatif
                SET nilai_akhir_remedial = ?,
                    status_remedial = 'Disetujui',
                    status_tuntas = 'Tuntas',
                    catatan_remedial = ?,
                    waktu_penilaian = NOW()
                WHERE id = ?
            ");
            $stmtUp->execute([$kktp, $catatan, $row['id']]);

            return [
                'success' => true,
                'message' => 'Remedial formatif berhasil disetujui!',
                'data' => [
                    'id_siswa' => $idSiswa,
                    'id_item' => $itemId,
                    'nilai_asli' => (float)$row['nilai_angka'],
                    'nilai_remedial' => (float)$row['nilai_remedial'],
                    'nilai_akhir_remedial' => $kktp,
                    'status_remedial' => 'Disetujui',
                    'status_tuntas' => 'Tuntas'
                ]
            ];
        } elseif ($tipe === 'tugas') {
            $check = $pdo->prepare("
                SELECT id, nilai, nilai_remedial 
                FROM nilai_tugas 
                WHERE id_tugas = ? AND id_siswa = ? 
                LIMIT 1
            ");
            $check->execute([$itemId, $idSiswa]);
            $row = $check->fetch(PDO::FETCH_ASSOC);

            if (!$row) {
                return ['success' => false, 'message' => 'Data nilai tugas siswa tidak ditemukan.'];
            }

            $stmtUp = $pdo->prepare("
                UPDATE nilai_tugas
                SET nilai_akhir_remedial = ?,
                    status_remedial = 'Disetujui',
                    status_penilaian = 'Tuntas',
                    catatan_remedial = ?,
                    waktu_penilaian = NOW()
                WHERE id = ?
            ");
            $stmtUp->execute([$kktp, $catatan, $row['id']]);

            return [
                'success' => true,
                'message' => 'Remedial tugas berhasil disetujui!',
                'data' => [
                    'id_siswa' => $idSiswa,
                    'id_item' => $itemId,
                    'nilai_asli' => (float)$row['nilai'],
                    'nilai_remedial' => (float)$row['nilai_remedial'],
                    'nilai_akhir_remedial' => $kktp,
                    'status_remedial' => 'Disetujui',
                    'status_tuntas' => 'Tuntas'
                ]
            ];
        }

        return ['success' => false, 'message' => 'Tipe asesmen tidak didukung.'];
    } catch (Throwable $e) {
        return ['success' => false, 'message' => 'Gagal menyetujui remedial: ' . $e->getMessage()];
    }
}

/**
 * Instruksikan Siswa untuk Mengikuti Remedial Ulang
 * Mengatur status ke 'Remedial Ulang' dan mereset nilai_akhir_remedial ke NULL.
 *
 * @param PDO $pdo
 * @param string $tipe
 * @param int $itemId
 * @param int $idSiswa
 * @param string $catatan
 * @return array
 */
function ilmuna_remidi_ulang($pdo, $tipe, $itemId, $idSiswa, $catatan = '') {
    $tipe = strtolower(trim($tipe));
    $itemId = (int)$itemId;
    $idSiswa = (int)$idSiswa;
    $catatan = trim($catatan) ?: 'Hasil remedial belum memenuhi syarat. Wajib mengikuti remedial ulang.';

    try {
        if ($tipe === 'sumatif') {
            $stmtUp = $pdo->prepare("
                UPDATE nilai_asesmen_sumatif
                SET status_remedial = 'Remedial Ulang',
                    nilai_akhir_remedial = NULL,
                    status_tuntas = 'Belum Tuntas',
                    catatan_remedial = ?,
                    waktu_penilaian = NOW()
                WHERE id_asesmen_sumatif = ? AND id_siswa = ?
            ");
            $stmtUp->execute([$catatan, $itemId, $idSiswa]);

            // Jika ada sesi ujian CBT remedial yang terkait, buka statusnya agar bisa ujian ulang
            try {
                $pdo->prepare("
                    UPDATE cbt_sesi_ujian 
                    SET status_sesi = 'Dibuka Ulang', is_frozen = 0, is_locked_admin = 0
                    WHERE tipe_asesmen = 'remedial' AND id_siswa = ?
                ")->execute([$idSiswa]);
                $pdo->prepare("
                    UPDATE akses_pengayaan_remedial
                    SET status_akses = 'Belum Mulai'
                    WHERE id_siswa = ? AND status_akses = 'Selesai'
                ")->execute([$idSiswa]);
            } catch (Throwable $ex) {}

            return [
                'success' => true,
                'message' => 'Status disetel ke Remedial Ulang. Siswa harus mengulang sesi perbaikan nilai.',
                'data' => [
                    'id_siswa' => $idSiswa,
                    'id_item' => $itemId,
                    'status_remedial' => 'Remedial Ulang',
                    'status_tuntas' => 'Belum Tuntas'
                ]
            ];
        } elseif ($tipe === 'formatif') {
            $stmtUp = $pdo->prepare("
                UPDATE nilai_asesmen_formatif
                SET status_remedial = 'Remedial Ulang',
                    nilai_akhir_remedial = NULL,
                    status_tuntas = 'Belum Tuntas',
                    catatan_remedial = ?,
                    waktu_penilaian = NOW()
                WHERE id_asesmen_formatif = ? AND id_siswa = ?
            ");
            $stmtUp->execute([$catatan, $itemId, $idSiswa]);

            return [
                'success' => true,
                'message' => 'Status formatif disetel ke Remedial Ulang.',
                'data' => [
                    'id_siswa' => $idSiswa,
                    'id_item' => $itemId,
                    'status_remedial' => 'Remedial Ulang',
                    'status_tuntas' => 'Belum Tuntas'
                ]
            ];
        } elseif ($tipe === 'tugas') {
            $stmtUp = $pdo->prepare("
                UPDATE nilai_tugas
                SET status_remedial = 'Remedial Ulang',
                    nilai_akhir_remedial = NULL,
                    status_penilaian = 'Perlu Revisi',
                    catatan_remedial = ?,
                    waktu_penilaian = NOW()
                WHERE id_tugas = ? AND id_siswa = ?
            ");
            $stmtUp->execute([$catatan, $itemId, $idSiswa]);

            return [
                'success' => true,
                'message' => 'Tugas disetel ke Remedial Ulang / Perlu Revisi.',
                'data' => [
                    'id_siswa' => $idSiswa,
                    'id_item' => $itemId,
                    'status_remedial' => 'Remedial Ulang',
                    'status_tuntas' => 'Belum Tuntas'
                ]
            ];
        }

        return ['success' => false, 'message' => 'Tipe asesmen tidak didukung.'];
    } catch (Throwable $e) {
        return ['success' => false, 'message' => 'Gagal menyetel remedial ulang: ' . $e->getMessage()];
    }
}

/**
 * Generator & Pratinjau Nilai Akhir Rapor (Kurikulum Merdeka)
 * Bobot Penilaian Akhir:
 *   - 50% Kognitif (Tugas, Proyek, Formatif, Sumatif LM, STS, SAS)
 *   - 40% Afektif (Observasi Sikap Profil Pelajar Pancasila)
 *   - 10% Psikomotorik (Penilaian Kinerja / Unjuk Kerja Praktik)
 * Nilai Maksimal Sempurna = 100 (0.50*100 + 0.40*100 + 0.10*100 = 100)
 *
 * Dilengkapi Kebijakan Toleransi Guru Per Siswa (Bagi Siswa Belum Tuntas):
 *   - Nilai toleransi diinput per siswa yang belum mencapai KKTP / bernilai kosong.
 *   - Batas maksimal nilai akhir hasil toleransi = KKTP (tidak boleh dilebihkan).
 *   - Terekam resmi di database (kolom `nilai_toleransi`).
 *
 * @param PDO $pdo
 * @param int $idKelas
 * @param int $idKmp
 * @param string $semester ('Ganjil' | 'Genap')
 * @param string $tahunPelajaran ('2026/2027')
 * @param array|float|null $toleransiInput (array per siswa [id_siswa => toleransi] atau null)
 * @param bool $doSave (true: simpan ke database, false: hanya preview)
 * @return array
 */
function ilmuna_generate_rekap_nilai_akhir($pdo, $idKelas, $idKmp, $semester = 'Ganjil', $tahunPelajaran = '2026/2027', $toleransiInput = null, $doSave = true) {
    $idKelas = (int)$idKelas;
    $idKmp = (int)$idKmp;
    $doSave = (bool)$doSave;

    if ($idKelas <= 0 || $idKmp <= 0) {
        return ['success' => false, 'message' => 'ID Kelas atau ID Kelas Mata Pelajaran tidak valid.'];
    }

    try {
        // 1. Ambil Info Mapel, Kelas, dan KKTP
        $stmtM = $pdo->prepare("
            SELECT kmp.id, kmp.id_mata_pelajaran, mp.nama_mata_pelajaran, mp.kode_mapel, mp.kktp,
                   k.nama_kelas, k.tahun_pelajaran AS k_tp
            FROM kelas_mata_pelajaran kmp
            JOIN mata_pelajaran mp ON kmp.id_mata_pelajaran = mp.id
            JOIN kelas k ON kmp.id_kelas = k.id
            WHERE kmp.id = ? LIMIT 1
        ");
        $stmtM->execute([$idKmp]);
        $mapelInfo = $stmtM->fetch(PDO::FETCH_ASSOC);

        if (!$mapelInfo) {
            return ['success' => false, 'message' => 'Informasi rombel mata pelajaran tidak ditemukan.'];
        }

        $idMapel = (int)$mapelInfo['id_mata_pelajaran'];
        $kktpAcuan = (float)($mapelInfo['kktp'] ?? 75.00);
        if ($kktpAcuan <= 0) $kktpAcuan = 75.00;

        if (empty($tahunPelajaran)) {
            $tahunPelajaran = $mapelInfo['k_tp'] ?: '2026/2027';
        }
        $semester = in_array(ucfirst(strtolower($semester)), ['Ganjil', 'Genap'], true) ? ucfirst(strtolower($semester)) : 'Ganjil';

        // Ambil Data Toleransi yang Pernah Tersimpan Sebelumnya di Database
        $savedToleransi = [];
        try {
            $stmtSavedTol = $pdo->prepare("SELECT id_siswa, nilai_toleransi FROM rekap_nilai_siswa_mapel WHERE id_kelas_mata_pelajaran = ?");
            $stmtSavedTol->execute([$idKmp]);
            while ($rTol = $stmtSavedTol->fetch(PDO::FETCH_ASSOC)) {
                $savedToleransi[(int)$rTol['id_siswa']] = (float)$rTol['nilai_toleransi'];
            }
        } catch (Throwable $eTol) {}

        // 2. Ambil Seluruh Siswa Rombel Ini
        $stmtS = $pdo->prepare("
            SELECT id, nis, nisn, nama_lengkap 
            FROM siswa 
            WHERE id_kelas = ? 
            ORDER BY nama_lengkap ASC
        ");
        $stmtS->execute([$idKelas]);
        $siswaList = $stmtS->fetchAll(PDO::FETCH_ASSOC);

        if (empty($siswaList)) {
            return ['success' => false, 'message' => 'Tidak ada siswa pada rombel kelas ini.'];
        }

        // 3. Kumpulkan Seluruh Asesmen Sumatif (termasuk multi-kelas)
        $stmtSm = $pdo->prepare("
            SELECT DISTINCT asum.id, asum.judul_asesmen, asum.jenis_sumatif, asum.kktp
            FROM asesmen_sumatif asum
            LEFT JOIN asesmen_sumatif_kelas ask ON asum.id = ask.id_asesmen_sumatif
            LEFT JOIN kelas_mata_pelajaran kmp_as ON asum.id_kelas_mata_pelajaran = kmp_as.id
            LEFT JOIN kelas_mata_pelajaran ask_mp ON ask.id_kelas_mata_pelajaran = ask_mp.id
            WHERE (
                asum.id_kelas_mata_pelajaran = :id_kmp 
                OR ask.id_kelas_mata_pelajaran = :id_kmp
                OR (
                    (kmp_as.id_kelas = :id_kelas AND kmp_as.id_mata_pelajaran = :id_mapel)
                    OR (ask_mp.id_kelas = :id_kelas AND ask_mp.id_mata_pelajaran = :id_mapel)
                )
            )
            ORDER BY asum.id ASC
        ");
        $stmtSm->execute([
            ':id_kmp'   => $idKmp,
            ':id_kelas' => $idKelas,
            ':id_mapel' => $idMapel
        ]);
        $sumatifList = $stmtSm->fetchAll(PDO::FETCH_ASSOC);

        $smLmIds  = [];
        $smStsIds = [];
        $smSasIds = [];
        foreach ($sumatifList as $sm) {
            $j = trim($sm['jenis_sumatif'] ?? '');
            if ($j === 'Sumatif Tengah Semester') {
                $smStsIds[] = (int)$sm['id'];
            } elseif ($j === 'Sumatif Akhir Semester') {
                $smSasIds[] = (int)$sm['id'];
            } else {
                $smLmIds[] = (int)$sm['id'];
            }
        }

        // 4. Ambil Nilai Sumatif Siswa (menggunakan nilai efektif: nilai_akhir_remedial ?? nilai_remedial ?? nilai_angka)
        $allSmIds = array_column($sumatifList, 'id');
        $sumatifScores = []; // [id_siswa][id_asesmen] = nilai_efektif
        if (!empty($allSmIds)) {
            $inSm = implode(',', array_fill(0, count($allSmIds), '?'));
            $stmtNlSm = $pdo->prepare("
                SELECT ns.id_siswa, ns.id_asesmen_sumatif, ns.nilai_angka, ns.nilai_remedial, ns.nilai_akhir_remedial, ns.status_remedial
                FROM nilai_asesmen_sumatif ns
                JOIN siswa s ON ns.id_siswa = s.id
                WHERE s.id_kelas = ? AND ns.id_asesmen_sumatif IN ($inSm)
            ");
            $stmtNlSm->execute(array_merge([$idKelas], $allSmIds));
            while ($row = $stmtNlSm->fetch(PDO::FETCH_ASSOC)) {
                $sId = (int)$row['id_siswa'];
                $aId = (int)$row['id_asesmen_sumatif'];
                $valEff = ($row['nilai_akhir_remedial'] !== null) ? (float)$row['nilai_akhir_remedial'] : (float)$row['nilai_angka'];
                if (!isset($sumatifScores[$sId][$aId]) || $valEff > $sumatifScores[$sId][$aId]) {
                    $sumatifScores[$sId][$aId] = $valEff;
                }
            }
        }

        // 5. Ambil Nilai Formatif Siswa
        $formatifScores = []; // [id_siswa][] = float
        $stmtNlFmt = $pdo->prepare("
            SELECT nf.id_siswa, nf.nilai_angka, nf.nilai_remedial, nf.nilai_akhir_remedial
            FROM nilai_asesmen_formatif nf
            JOIN siswa s ON nf.id_siswa = s.id
            LEFT JOIN kelas_mata_pelajaran kmp ON nf.id_kelas_mata_pelajaran = kmp.id
            WHERE s.id_kelas = ? AND (nf.id_kelas_mata_pelajaran = ? OR (kmp.id_kelas = ? AND kmp.id_mata_pelajaran = ?))
        ");
        $stmtNlFmt->execute([$idKelas, $idKmp, $idKelas, $idMapel]);
        while ($row = $stmtNlFmt->fetch(PDO::FETCH_ASSOC)) {
            $sId = (int)$row['id_siswa'];
            $valEff = ($row['nilai_akhir_remedial'] !== null) ? (float)$row['nilai_akhir_remedial'] : (float)$row['nilai_angka'];
            $formatifScores[$sId][] = $valEff;
        }

        // 6. Ambil Nilai Tugas & Proyek Siswa
        $tugasScores = [];   // [id_siswa][] = float
        $proyekScores = [];  // [id_siswa][] = float
        $stmtNlTgs = $pdo->prepare("
            SELECT nt.id_siswa, nt.nilai, nt.nilai_remedial, nt.nilai_akhir_remedial, t.jenis_penugasan
            FROM nilai_tugas nt
            JOIN tugas t ON nt.id_tugas = t.id
            JOIN siswa s ON nt.id_siswa = s.id
            LEFT JOIN kelas_mata_pelajaran kmp ON nt.id_kelas_mata_pelajaran = kmp.id
            WHERE s.id_kelas = ? AND (nt.id_kelas_mata_pelajaran = ? OR (kmp.id_kelas = ? AND kmp.id_mata_pelajaran = ?))
        ");
        $stmtNlTgs->execute([$idKelas, $idKmp, $idKelas, $idMapel]);
        while ($row = $stmtNlTgs->fetch(PDO::FETCH_ASSOC)) {
            $sId = (int)$row['id_siswa'];
            $valEff = ($row['nilai_akhir_remedial'] !== null) ? (float)$row['nilai_akhir_remedial'] : (float)$row['nilai'];
            $j = strtolower(trim($row['jenis_penugasan'] ?? ''));
            if (strpos($j, 'proyek') !== false) {
                $proyekScores[$sId][] = $valEff;
            } else {
                $tugasScores[$sId][] = $valEff;
            }
        }

        // 7. Ambil Nilai Diagnostik & LKPD Siswa (jika ada)
        $diagScores = [];
        try {
            $stmtDiag = $pdo->prepare("
                SELECT nd.id_siswa, nd.nilai_angka
                FROM nilai_asesmen_diagnostik nd
                JOIN siswa s ON nd.id_siswa = s.id
                WHERE s.id_kelas = ?
            ");
            $stmtDiag->execute([$idKelas]);
            while ($row = $stmtDiag->fetch(PDO::FETCH_ASSOC)) {
                $diagScores[(int)$row['id_siswa']][] = (float)$row['nilai_angka'];
            }
        } catch (Throwable $e) {}

        $lkpdScores = [];
        try {
            $stmtLkpd = $pdo->prepare("
                SELECT nl.id_siswa, nl.nilai
                FROM nilai_lkpd nl
                JOIN siswa s ON nl.id_siswa = s.id
                WHERE s.id_kelas = ?
            ");
            $stmtLkpd->execute([$idKelas]);
            while ($row = $stmtLkpd->fetch(PDO::FETCH_ASSOC)) {
                $lkpdScores[(int)$row['id_siswa']][] = (float)$row['nilai'];
            }
        } catch (Throwable $e) {}

        // 7b. Ambil Nilai Asesmen Afektif (Observasi Sikap Siswa)
        $afektifScores = [];
        $afektifNotes  = [];
        try {
            $stmtAfk = $pdo->prepare("
                SELECT na.id_siswa, na.nilai_angka, na.catatan_perilaku
                FROM nilai_asesmen_afektif na
                JOIN siswa s ON na.id_siswa = s.id
                WHERE s.id_kelas = ? AND na.id_kelas_mata_pelajaran = ?
            ");
            $stmtAfk->execute([$idKelas, $idKmp]);
            while ($row = $stmtAfk->fetch(PDO::FETCH_ASSOC)) {
                $sId = (int)$row['id_siswa'];
                if ($row['nilai_angka'] !== null && $row['nilai_angka'] !== '') {
                    $afektifScores[$sId][] = (float)$row['nilai_angka'];
                }
                $c = trim($row['catatan_perilaku'] ?? '');
                if ($c !== '' && $c !== '-') {
                    $afektifNotes[$sId][] = $c;
                }
            }
        } catch (Throwable $e) {}

        // 7c. Ambil Nilai Asesmen Psikomotorik (Unjuk Kerja / Praktik Siswa)
        $psikomotorikScores = [];
        $psikomotorikNotes  = [];
        try {
            $stmtPsk = $pdo->prepare("
                SELECT np.id_siswa, COALESCE(np.nilai_akhir_remedial, np.nilai_angka) AS nilai_eff, np.catatan_kinerja
                FROM nilai_asesmen_psikomotorik np
                JOIN siswa s ON np.id_siswa = s.id
                WHERE s.id_kelas = ? AND np.id_kelas_mata_pelajaran = ?
            ");
            $stmtPsk->execute([$idKelas, $idKmp]);
            while ($row = $stmtPsk->fetch(PDO::FETCH_ASSOC)) {
                $sId = (int)$row['id_siswa'];
                if ($row['nilai_eff'] !== null && $row['nilai_eff'] !== '') {
                    $psikomotorikScores[$sId][] = (float)$row['nilai_eff'];
                }
                $ck = trim($row['catatan_kinerja'] ?? '');
                if ($ck !== '' && $ck !== '-') {
                    $psikomotorikNotes[$sId][] = $ck;
                }
            }
        } catch (Throwable $e) {}

        // 8. Prepared Statement Upsert (Hanya dijalankan jika $doSave == true)
        $stmtUpsert = null;
        if ($doSave) {
            $stmtUpsert = $pdo->prepare("
                INSERT INTO rekap_nilai_siswa_mapel
                (
                    id_siswa, id_kelas_mata_pelajaran, semester, tahun_pelajaran,
                    rata_diagnostik, rata_formatif, rata_lkpd, rata_sumatif_materi,
                    nilai_sts, nilai_asas, rata_tugas, nilai_proyek,
                    rata_afektif, predikat_afektif, deskripsi_afektif,
                    rata_psikomotorik, predikat_psikomotorik, deskripsi_psikomotorik,
                    nilai_akhir_semester, nilai_toleransi, predikat_akhir, status_kelulusan_kktp,
                    deskripsi_capaian_tertinggi, deskripsi_capaian_perlu_bimbingan,
                    updated_at
                )
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
                ON DUPLICATE KEY UPDATE
                    rata_diagnostik = VALUES(rata_diagnostik),
                    rata_formatif = VALUES(rata_formatif),
                    rata_lkpd = VALUES(rata_lkpd),
                    rata_sumatif_materi = VALUES(rata_sumatif_materi),
                    nilai_sts = VALUES(nilai_sts),
                    nilai_asas = VALUES(nilai_asas),
                    rata_tugas = VALUES(rata_tugas),
                    nilai_proyek = VALUES(nilai_proyek),
                    rata_afektif = VALUES(rata_afektif),
                    predikat_afektif = VALUES(predikat_afektif),
                    deskripsi_afektif = VALUES(deskripsi_afektif),
                    rata_psikomotorik = VALUES(rata_psikomotorik),
                    predikat_psikomotorik = VALUES(predikat_psikomotorik),
                    deskripsi_psikomotorik = VALUES(deskripsi_psikomotorik),
                    nilai_akhir_semester = VALUES(nilai_akhir_semester),
                    nilai_toleransi = VALUES(nilai_toleransi),
                    predikat_akhir = VALUES(predikat_akhir),
                    status_kelulusan_kktp = VALUES(status_kelulusan_kktp),
                    deskripsi_capaian_tertinggi = VALUES(deskripsi_capaian_tertinggi),
                    deskripsi_capaian_perlu_bimbingan = VALUES(deskripsi_capaian_perlu_bimbingan),
                    updated_at = NOW()
            ");
        }

        $totalSiswa = count($siswaList);
        $totalTuntasMurni = 0;
        $totalTerbantuToleransi = 0;
        $totalBelumTuntas = 0;
        $rekapResults = [];

        foreach ($siswaList as $s) {
            $sId = (int)$s['id'];

            // Komponen Rata-rata Kognitif Mentah
            $rDiag = !empty($diagScores[$sId]) ? round(array_sum($diagScores[$sId]) / count($diagScores[$sId]), 2) : null;
            $rFmt  = !empty($formatifScores[$sId]) ? round(array_sum($formatifScores[$sId]) / count($formatifScores[$sId]), 2) : null;
            $rLkpd = !empty($lkpdScores[$sId]) ? round(array_sum($lkpdScores[$sId]) / count($lkpdScores[$sId]), 2) : null;
            $rTgs  = !empty($tugasScores[$sId]) ? round(array_sum($tugasScores[$sId]) / count($tugasScores[$sId]), 2) : null;
            $nProy = !empty($proyekScores[$sId]) ? round(array_sum($proyekScores[$sId]) / count($proyekScores[$sId]), 2) : null;

            // Sumatif Lingkup Materi
            $lmVals = [];
            foreach ($smLmIds as $lid) {
                if (isset($sumatifScores[$sId][$lid])) {
                    $lmVals[] = $sumatifScores[$sId][$lid];
                }
            }
            $rSmLm = !empty($lmVals) ? round(array_sum($lmVals) / count($lmVals), 2) : null;

            // STS & SAS
            $nSts = null;
            foreach ($smStsIds as $sid) {
                if (isset($sumatifScores[$sId][$sid])) {
                    $nSts = $sumatifScores[$sId][$sid];
                    break;
                }
            }
            $nSas = null;
            foreach ($smSasIds as $aid) {
                if (isset($sumatifScores[$sId][$aid])) {
                    $nSas = $sumatifScores[$sId][$aid];
                    break;
                }
            }

            // 1. ASPEK KOGNITIF (BOBOT 50%)
            $sumatifScore = null;
            if ($rSmLm !== null) {
                $pembilang = $rSmLm * 2;
                $penyebut  = 2;
                if ($nSts !== null) { $pembilang += $nSts; $penyebut += 1; }
                if ($nSas !== null) { $pembilang += $nSas; $penyebut += 1; }
                $sumatifScore = round($pembilang / $penyebut, 2);
            } elseif ($nSts !== null || $nSas !== null) {
                $smParts = array_filter([$nSts, $nSas], function($v){ return $v !== null; });
                $sumatifScore = round(array_sum($smParts) / count($smParts), 2);
            }

            $kogElements = [];
            if ($sumatifScore !== null) { $kogElements[] = $sumatifScore; }
            if ($rFmt !== null)         { $kogElements[] = $rFmt; }
            if ($rTgs !== null)         { $kogElements[] = $rTgs; }
            if ($nProy !== null)        { $kogElements[] = $nProy; }
            if ($rLkpd !== null && empty($rTgs)) { $kogElements[] = $rLkpd; }

            $rKog = !empty($kogElements) ? round(array_sum($kogElements) / count($kogElements), 2) : 0.00;

            // 2. ASPEK AFEKTIF (BOBOT 40%)
            $rAfk = !empty($afektifScores[$sId]) ? round(array_sum($afektifScores[$sId]) / count($afektifScores[$sId]), 2) : 0.00;
            $predAfk = '-';
            $deskAfk = !empty($afektifNotes[$sId]) ? implode('; ', array_unique($afektifNotes[$sId])) : null;
            if (!empty($afektifScores[$sId])) {
                if ($rAfk >= 88.0)     $predAfk = 'SB';
                elseif ($rAfk >= 75.0) $predAfk = 'B';
                elseif ($rAfk >= 60.0) $predAfk = 'C';
                else                   $predAfk = 'K';
            }

            // 3. ASPEK PSIKOMOTORIK (BOBOT 10%)
            $rPsk = !empty($psikomotorikScores[$sId]) ? round(array_sum($psikomotorikScores[$sId]) / count($psikomotorikScores[$sId]), 2) : 0.00;
            $predPsk = '-';
            $deskPsk = !empty($psikomotorikNotes[$sId]) ? implode('; ', array_unique($psikomotorikNotes[$sId])) : null;
            if (!empty($psikomotorikScores[$sId])) {
                if ($rPsk >= 90.0)     $predPsk = 'A';
                elseif ($rPsk >= 80.0) $predPsk = 'B';
                elseif ($rPsk >= 70.0) $predPsk = 'C';
                elseif ($rPsk >= 60.0) $predPsk = 'D';
                else                   $predPsk = 'E';
            }

            // 4. KALKULASI NILAI AKHIR MURNI: 50% Kognitif + 40% Afektif + 10% Psikomotorik
            // Jika ketiga aspek = 100, maka 50 + 40 + 10 = 100.00 (Sempurna)
            $kontribusiKog = round($rKog * 0.50, 2);
            $kontribusiAfk = round($rAfk * 0.40, 2);
            $kontribusiPsk = round($rPsk * 0.10, 2);
            $naMurni = round($kontribusiKog + $kontribusiAfk + $kontribusiPsk, 2);

            // 5. TOLERANSI PER SISWA (HANYA UNTUK SISWA BELUM LULUS / NILAI KOSONG)
            // Batas maksimal toleransi siswa ini: agar nilai total maksimal = KKTP
            $maxToleransiBisaDiisi = max(0.0, round($kktpAcuan - $naMurni, 2));

            // Ambil toleransi yang diajukan untuk siswa ini
            $tolDiajukan = null;
            if (is_array($toleransiInput)) {
                if (isset($toleransiInput[$sId])) {
                    $tolDiajukan = (float)$toleransiInput[$sId];
                } elseif (isset($toleransiInput["siswa_{$sId}"])) {
                    $tolDiajukan = (float)$toleransiInput["siswa_{$sId}"];
                }
            } elseif (is_numeric($toleransiInput)) {
                // Jika dikirim angka tunggal (persen dari KKTP)
                $tolDiajukan = round(((float)$toleransiInput / 100.0) * $kktpAcuan, 2);
            }

            // Jika tidak ada input toleransi yang dikirim, gunakan yang sudah tersimpan di database
            if ($tolDiajukan === null && isset($savedToleransi[$sId])) {
                $tolDiajukan = (float)$savedToleransi[$sId];
            }
            if ($tolDiajukan === null) {
                $tolDiajukan = 0.0;
            }

            $naFinal = $naMurni;
            $toleransiEfektif = 0.00;
            $statusToleransi = 'Tuntas Murni';

            if ($naMurni >= $kktpAcuan) {
                // Siswa sudah tuntas mandiri, tidak memerlukan toleransi
                $toleransiEfektif = 0.00;
                $naFinal = $naMurni;
                $statusToleransi = 'Tuntas Murni';
                $totalTuntasMurni++;
            } else {
                // Siswa belum tuntas / nilai kosong
                // ATURAN KETAT: Toleransi maksimal tidak boleh membuat nilai melebihi KKTP!
                $toleransiEfektif = max(0.0, min($maxToleransiBisaDiisi, (float)$tolDiajukan));
                $naFinal = round($naMurni + $toleransiEfektif, 2);

                // Proteksi akhir Capping = KKTP
                if ($naFinal > $kktpAcuan) {
                    $naFinal = $kktpAcuan;
                    $toleransiEfektif = $maxToleransiBisaDiisi;
                }

                if ($toleransiEfektif > 0) {
                    if ($naFinal >= $kktpAcuan) {
                        $statusToleransi = 'Terbantu Toleransi (Cap KKTP)';
                        $totalTerbantuToleransi++;
                    } else {
                        $statusToleransi = 'Terbantu Sebagian (Belum Tuntas)';
                        $totalBelumTuntas++;
                    }
                } else {
                    $statusToleransi = 'Belum Tercapai';
                    $totalBelumTuntas++;
                }
            }

            // 6. PREDIKAT AKHIR & STATUS KKTP
            $predikat = 'D';
            if ($naFinal >= 90)     $predikat = 'A';
            elseif ($naFinal >= 80) $predikat = 'B';
            elseif ($naFinal >= 70) $predikat = 'C';
            else                    $predikat = 'D';

            $statusKktp = ($naFinal >= $kktpAcuan) ? 'Tercapai' : 'Belum Tercapai';

            // 7. DESKRIPSI CAPAIAN KOMPETENSI (KURIKULUM MERDEKA)
            $mapelName = $mapelInfo['nama_mata_pelajaran'];
            if ($naFinal >= 85) {
                $deskTertinggi = "Menunjukkan pemahaman yang sangat istimewa dan konsisten dalam menguasai seluruh capaian pembelajaran {$mapelName}.";
                $deskPerlu     = "Perlu mempertahankan ketekunan belajar dan memperkaya literasi materi pengayaan lanjutan.";
            } elseif ($naFinal >= $kktpAcuan) {
                $deskTertinggi = "Menunjukkan penguasaan capaian pembelajaran yang baik serta telah melampaui standar kriteria ketercapaian tujuan pembelajaran pada {$mapelName}.";
                $deskPerlu     = "Perlu meningkatkan ketelitian dalam asesmen pemecahan masalah dan analisis materi yang kompleks.";
            } else {
                $deskTertinggi = "Mulai mampu memahami konsep dasar pembelajaran {$mapelName} dengan bimbingan terarah.";
                $deskPerlu     = "Memerlukan bimbingan intensif dan tindak lanjut remedial pada lingkup materi yang belum tuntas.";
            }

            // 8. SIMPAN KE DATABASE JIKA MODE SAVE AKTIF (TERMASUK nilia_toleransi)
            if ($doSave && $stmtUpsert) {
                $stmtUpsert->execute([
                    $sId, $idKmp, $semester, $tahunPelajaran,
                    $rDiag, $rFmt, $rLkpd, $rSmLm,
                    $nSts, $nSas, $rTgs, $nProy,
                    $rAfk, $predAfk, $deskAfk,
                    $rPsk, $predPsk, $deskPsk,
                    $naFinal, $toleransiEfektif, $predikat, $statusKktp,
                    $deskTertinggi, $deskPerlu
                ]);
            }

            $rekapResults[$sId] = [
                'id_siswa'             => $sId,
                'nis'                  => $s['nis'] ?: '-',
                'nisn'                 => $s['nisn'] ?: '-',
                'nama_lengkap'         => $s['nama_lengkap'],
                'rata_kognitif'        => $rKog,
                'kontribusi_kognitif'  => $kontribusiKog,
                'rata_afektif'         => $rAfk,
                'kontribusi_afektif'   => $kontribusiAfk,
                'predikat_afektif'     => $predAfk,
                'rata_psikomotorik'    => $rPsk,
                'kontribusi_psikomotorik' => $kontribusiPsk,
                'predikat_psikomotorik'=> $predPsk,
                'nilai_akhir_murni'    => $naMurni,
                'nilai_toleransi'      => $toleransiEfektif,
                'max_toleransi'        => $maxToleransiBisaDiisi,
                'is_tuntas_murni'      => ($naMurni >= $kktpAcuan),
                'nilai_akhir'          => $naFinal,
                'predikat_akhir'       => $predikat,
                'status_kelulusan_kktp'=> $statusKktp,
                'status_toleransi'     => $statusToleransi
            ];
        }

        $totalTuntasKeseluruhan = $totalTuntasMurni + $totalTerbantuToleransi;
        $msg = $doSave 
            ? "Nilai Akhir Rapor resmi disimpan untuk {$totalSiswa} siswa ({$totalTuntasMurni} Tuntas Murni, {$totalTerbantuToleransi} Terbantu Toleransi, {$totalBelumTuntas} Belum Tuntas)."
            : "Pratinjau kalkulasi nilai akhir berhasil dimuat.";

        return [
            'success' => true,
            'message' => $msg,
            'data' => [
                'kelas'                    => $mapelInfo['nama_kelas'],
                'mapel'                    => $mapelInfo['nama_mata_pelajaran'],
                'kktp'                     => $kktpAcuan,
                'semester'                 => $semester,
                'tahun_pelajaran'          => $tahunPelajaran,
                'bobot'                    => ['kognitif' => 50, 'afektif' => 40, 'psikomotorik' => 10],
                'total_siswa'              => $totalSiswa,
                'total_tuntas_murni'       => $totalTuntasMurni,
                'total_terbantu_toleransi' => $totalTerbantuToleransi,
                'total_tuntas_semua'       => $totalTuntasKeseluruhan,
                'total_belum_tuntas'       => $totalBelumTuntas,
                'rekap_siswa'              => array_values($rekapResults)
            ]
        ];
    } catch (Throwable $e) {
        return ['success' => false, 'message' => 'Gagal mengkalkulasi nilai akhir: ' . $e->getMessage()];
    }
}

/**
 * Pratinjau Kalkulasi Nilai Akhir (Read-Only Preview Tanpa Menyimpan ke Database)
 */
function ilmuna_preview_rekap_nilai_akhir($pdo, $idKelas, $idKmp, $semester = 'Ganjil', $tahunPelajaran = '2026/2027', $toleransiInput = null) {
    return ilmuna_generate_rekap_nilai_akhir($pdo, $idKelas, $idKmp, $semester, $tahunPelajaran, $toleransiInput, false);
}
