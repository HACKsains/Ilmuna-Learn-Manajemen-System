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

            if ($row['nilai_angka'] >= $row['nilai_kktp']) {
                return ['success' => false, 'message' => 'Nilai siswa sudah mencapai/melebihi KKTP, tidak memerlukan persetujuan remedial.'];
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
                'message' => 'Remedial berhasil disetujui! Nilai efektif menjadi setara KKTP (' . number_format($kktp, 2) . ') tanpa mengubah nilai asli.',
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
 * Generator Nilai Akhir Rapor & Simpan ke Tabel rekap_nilai_siswa_mapel
 * Sesuai Wewenang Penuh Guru Pengampu (Kurikulum Merdeka)
 *
 * @param PDO $pdo
 * @param int $idKelas
 * @param int $idKmp
 * @param string $semester ('Ganjil' | 'Genap')
 * @param string $tahunPelajaran ('2026/2027')
 * @return array
 */
function ilmuna_generate_rekap_nilai_akhir($pdo, $idKelas, $idKmp, $semester = 'Ganjil', $tahunPelajaran = '2026/2027') {
    $idKelas = (int)$idKelas;
    $idKmp = (int)$idKmp;

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
                // Prioritaskan nilai_akhir_remedial jika disetujui, lalu nilai_angka
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

        // 8. Hitung Nilai Akhir & Simpan ke rekap_nilai_siswa_mapel
        $stmtUpsert = $pdo->prepare("
            INSERT INTO rekap_nilai_siswa_mapel
            (
                id_siswa, id_kelas_mata_pelajaran, semester, tahun_pelajaran,
                rata_diagnostik, rata_formatif, rata_lkpd, rata_sumatif_materi,
                nilai_sts, nilai_asas, rata_tugas, nilai_proyek,
                nilai_akhir_semester, predikat_akhir, status_kelulusan_kktp,
                deskripsi_capaian_tertinggi, deskripsi_capaian_perlu_bimbingan,
                updated_at
            )
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
            ON DUPLICATE KEY UPDATE
                rata_diagnostik = VALUES(rata_diagnostik),
                rata_formatif = VALUES(rata_formatif),
                rata_lkpd = VALUES(rata_lkpd),
                rata_sumatif_materi = VALUES(rata_sumatif_materi),
                nilai_sts = VALUES(nilai_sts),
                nilai_asas = VALUES(nilai_asas),
                rata_tugas = VALUES(rata_tugas),
                nilai_proyek = VALUES(nilai_proyek),
                nilai_akhir_semester = VALUES(nilai_akhir_semester),
                predikat_akhir = VALUES(predikat_akhir),
                status_kelulusan_kktp = VALUES(status_kelulusan_kktp),
                deskripsi_capaian_tertinggi = VALUES(deskripsi_capaian_tertinggi),
                deskripsi_capaian_perlu_bimbingan = VALUES(deskripsi_capaian_perlu_bimbingan),
                updated_at = NOW()
        ");

        $totalSiswa = count($siswaList);
        $totalTuntas = 0;
        $totalBelum = 0;
        $rekapResults = [];

        foreach ($siswaList as $s) {
            $sId = (int)$s['id'];

            // Komponen Rata-rata
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

            // STS
            $nSts = null;
            foreach ($smStsIds as $sid) {
                if (isset($sumatifScores[$sId][$sid])) {
                    $nSts = $sumatifScores[$sId][$sid];
                    break;
                }
            }

            // SAS / ASAS
            $nSas = null;
            foreach ($smSasIds as $aid) {
                if (isset($sumatifScores[$sId][$aid])) {
                    $nSas = $sumatifScores[$sId][$aid];
                    break;
                }
            }

            // KALKULASI NILAI AKHIR SEMESTER (Kurikulum Merdeka)
            // Rumus fleksibel proporsional:
            // 1. Jika ada STS dan SAS: NA = (2 * LM + STS + SAS) / 4
            // 2. Jika hanya ada STS: NA = (2 * LM + STS) / 3
            // 3. Jika hanya ada LM: NA = LM
            // 4. Jika belum ada sumatif namun ada formatif/tugas: ambil rata-rata tugas & formatif
            $na = null;
            if ($rSmLm !== null) {
                $pembilang = $rSmLm * 2;
                $penyebut = 2;

                if ($nSts !== null) {
                    $pembilang += $nSts;
                    $penyebut += 1;
                }
                if ($nSas !== null) {
                    $pembilang += $nSas;
                    $penyebut += 1;
                }

                $na = round($pembilang / $penyebut, 2);
            } elseif ($rFmt !== null || $rTgs !== null) {
                $components = array_filter([$rFmt, $rTgs], function($v){ return $v !== null; });
                $na = !empty($components) ? round(array_sum($components) / count($components), 2) : 0.00;
            } else {
                $na = 0.00;
            }

            // Predikat Akhir
            $predikat = 'D';
            if ($na >= 90) $predikat = 'A';
            elseif ($na >= 80) $predikat = 'B';
            elseif ($na >= 70) $predikat = 'C';
            else $predikat = 'D';

            // Status Kelulusan KKTP
            $statusKktp = ($na >= $kktpAcuan) ? 'Tercapai' : 'Belum Tercapai';
            if ($statusKktp === 'Tercapai') $totalTuntas++;
            else $totalBelum++;

            // Deskripsi Capaian Kompetensi (Kurikulum Merdeka)
            $mapelName = $mapelInfo['nama_mata_pelajaran'];
            if ($na >= 85) {
                $deskTertinggi = "Menunjukkan pemahaman yang sangat istimewa dan konsisten dalam menguasai seluruh capaian pembelajaran {$mapelName}.";
                $deskPerlu     = "Perlu mempertahankan ketekunan belajar dan memperkaya literasi materi pengayaan lanjutan.";
            } elseif ($na >= $kktpAcuan) {
                $deskTertinggi = "Menunjukkan penguasaan capaian pembelajaran yang baik serta telah melampaui standar kriteria ketercapaian tujuan pembelajaran pada {$mapelName}.";
                $deskPerlu     = "Perlu meningkatkan ketelitian dalam asesmen pemecahan masalah dan analisis materi yang kompleks.";
            } else {
                $deskTertinggi = "Mulai mampu memahami konsep dasar pembelajaran {$mapelName} dengan bimbingan terarah.";
                $deskPerlu     = "Memerlukan bimbingan intensif dan tindak lanjut remedial pada lingkup materi yang belum tuntas.";
            }

            $stmtUpsert->execute([
                $sId, $idKmp, $semester, $tahunPelajaran,
                $rDiag, $rFmt, $rLkpd, $rSmLm,
                $nSts, $nSas, $rTgs, $nProy,
                $na, $predikat, $statusKktp,
                $deskTertinggi, $deskPerlu
            ]);

            $rekapResults[$sId] = [
                'id_siswa' => $sId,
                'nama_lengkap' => $s['nama_lengkap'],
                'rata_formatif' => $rFmt,
                'rata_tugas' => $rTgs,
                'rata_sumatif_materi' => $rSmLm,
                'nilai_sts' => $nSts,
                'nilai_asas' => $nSas,
                'nilai_akhir_semester' => $na,
                'predikat_akhir' => $predikat,
                'status_kelulusan_kktp' => $statusKktp
            ];
        }

        return [
            'success' => true,
            'message' => "Nilai Akhir Rapor berhasil di-generate & disimpan ke rekap_nilai_siswa_mapel untuk {$totalSiswa} siswa ({$totalTuntas} Tercapai, {$totalBelum} Belum Tercapai).",
            'data' => [
                'kelas' => $mapelInfo['nama_kelas'],
                'mapel' => $mapelInfo['nama_mata_pelajaran'],
                'kktp' => $kktpAcuan,
                'semester' => $semester,
                'tahun_pelajaran' => $tahunPelajaran,
                'total_siswa' => $totalSiswa,
                'total_tuntas' => $totalTuntas,
                'total_belum_tuntas' => $totalBelum,
                'rekap_siswa' => $rekapResults
            ]
        ];
    } catch (Throwable $e) {
        return ['success' => false, 'message' => 'Gagal generate nilai akhir: ' . $e->getMessage()];
    }
}
