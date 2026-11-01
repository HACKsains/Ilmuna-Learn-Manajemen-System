<?php
/**
 * ==============================================================================
 * ILMUNA LEARN — MANAJEMEN KURIKULUM & KALENDER PENDIDIKAN (ADMINISTRATOR)
 * ==============================================================================
 * Sistem Kalender Pendidikan Interaktif (Kaldik) Terintegrasi:
 *   1. Kalender Pendidikan Sekolah (Kaldik Interaktif Masehi + Hijriyah / Islami)
 *      - Mode Kuas (Quick Paint) & Drag-to-Select Rentang Tanggal
 *      - Integrasi Otomatis Libur Nasional (API) & Hari Besar Islam (Ramadhan, Idul Fitri, dll)
 *      - Sinkronisasi Otomatis ke Seluruh Rombel & Mata Pelajaran (hari_efektif & pekan_efektif)
 *   2. Analisis Rincian Pekan Efektif (RPE Sekolah) — Terhitung Realtime dari Kaldik
 *   3. Dokumen Standar Capaian Pembelajaran (CP) & Elemen Kurikulum Merdeka (BSKAP)
 *   4. Struktur Kurikulum & Pemetaan Beban Belajar (Alokasi JP & Guru Pengampu)
 * ==============================================================================
 */

date_default_timezone_set('Asia/Jakarta');

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../auth.php';
require_once __DIR__ . '/../../database.php';

requireRole('admin');

$pdo = getDBConnection();
$pdo->exec("SET time_zone = '+07:00'");
$pageTitle = 'Kurikulum & Kalender Pendidikan (Kaldik)';
$pageIcon  = 'solar:calendar-date-bold-duotone';

/* ==============================================================================
 * HELPER: NAMA HARI & BULAN INDONESIA
 * ============================================================================== */
if (!function_exists('getNamaHariIndo')) {
    function getNamaHariIndo($dateStr) {
        $days = [
            'Sunday'=>'Minggu','Monday'=>'Senin','Tuesday'=>'Selasa',
            'Wednesday'=>'Rabu','Thursday'=>'Kamis','Friday'=>'Jumat','Saturday'=>'Sabtu'
        ];
        return $days[date('l', strtotime($dateStr))] ?? 'Senin';
    }
}
if (!function_exists('getNamaBulanIndo')) {
    function getNamaBulanIndo($mNum) {
        $months = [1=>'Januari',2=>'Februari',3=>'Maret',4=>'April',5=>'Mei',6=>'Juni',
                   7=>'Juli',8=>'Agustus',9=>'September',10=>'Oktober',11=>'November',12=>'Desember'];
        return $months[(int)$mNum] ?? '';
    }
}

/* ==============================================================================
 * HELPER: FORMAT TANGGAL HIJRIYAH (KALENDER ISLAMI UMM AL-QURA)
 * ============================================================================== */
if (!function_exists('getTanggalHijriyah')) {
    function getTanggalHijriyah($dateStr, $format = 'd MMM') {
        static $fmtShort = null;
        static $fmtFull = null;

        if (!class_exists('IntlDateFormatter')) {
            return '';
        }

        if ($format === 'full') {
            if ($fmtFull === null) {
                $fmtFull = new IntlDateFormatter(
                    "id_ID@calendar=islamic-umalqura",
                    IntlDateFormatter::NONE,
                    IntlDateFormatter::NONE,
                    'Asia/Jakarta',
                    IntlDateFormatter::TRADITIONAL,
                    "d MMMM y"
                );
            }
            return $fmtFull->format(new DateTime($dateStr));
        }

        if ($fmtShort === null) {
            $fmtShort = new IntlDateFormatter(
                "id_ID@calendar=islamic-umalqura",
                IntlDateFormatter::NONE,
                IntlDateFormatter::NONE,
                'Asia/Jakarta',
                IntlDateFormatter::TRADITIONAL,
                "d MMM"
            );
        }
        return $fmtShort->format(new DateTime($dateStr));
    }
}

/* ==============================================================================
 * HELPER: DETEKSI PERISTIWA / HARI BESAR ISLAM DARI TANGGAL HIJRIYAH
 * Mengacu pada Kalender Islam Lembaga Falakiyah PBNU (NU Online) & Kemenag RI
 * ============================================================================== */
if (!function_exists('getEventIslami')) {
    function getEventIslami($dateStr) {
        $hijriFull = getTanggalHijriyah($dateStr, 'full');
        if (empty($hijriFull)) return null;

        if (!preg_match('/^(\d+)\s+([a-zA-Z\']+)\s+(\d+)/', trim($hijriFull), $m)) {
            return null;
        }

        $day   = (int)$m[1];
        $month = strtolower($m[2]);
        $year  = (int)$m[3];

        // 1. Tahun Baru Islam 1 Muharram & Hari Asyura
        if ($month === 'muharam') {
            if ($day === 1)  return ['nama' => "Tahun Baru Islam 1 Muharram $year H (Kalender Islam NU Online)", 'status' => 'Libur Nasional', 'color' => 'red'];
            if ($day === 10) return ['nama' => "Hari Asyura 10 Muharram $year H (Kalender Islam Falakiyah NU)", 'status' => 'Libur Khusus Keagamaan', 'color' => 'purple'];
        }

        // 2. Maulid Nabi Muhammad SAW (12 Rabiul Awal)
        if ($month === 'rabiulawal' && $day === 12) {
            return ['nama' => 'Maulid Nabi Muhammad SAW (Kalender Islam NU Online)', 'status' => 'Libur Nasional', 'color' => 'red'];
        }

        // 3. Isra Mi'raj Nabi Muhammad SAW (27 Rajab)
        if ($month === 'rajab' && $day === 27) {
            return ['nama' => 'Isra\' Mi\'raj Nabi Muhammad SAW (Kalender Islam NU Online)', 'status' => 'Libur Nasional', 'color' => 'red'];
        }

        // 4. Malam Nisfu Sya'ban (15 Sya'ban)
        if (($month === 'syakban' || $month === 'syaban' || strpos($month, 'ban') !== false) && $day === 15) {
            return ['nama' => 'Malam Nisfu Sya\'ban (Kalender Islam Falakiyah NU)', 'status' => 'Libur Khusus Keagamaan', 'color' => 'purple'];
        }

        // 5. Ramadhan (Awal Puasa, Nuzulul Qur'an, Akhir Ramadhan)
        if ($month === 'ramadan') {
            if ($day === 1)  return ['nama' => "Awal Puasa Ramadhan $year H (Kalender Islam Falakiyah NU Online)", 'status' => 'Libur Khusus Keagamaan', 'color' => 'purple'];
            if ($day === 17) return ['nama' => "Peringatan Nuzulul Qur'an 17 Ramadhan (Kalender Islam NU Online)", 'status' => 'Libur Khusus Keagamaan', 'color' => 'purple'];
            if ($day >= 28)  return ['nama' => "Libur Akhir Ramadhan & Persiapan Hari Raya (Kalender Islam NU)", 'status' => 'Libur Khusus Keagamaan', 'color' => 'purple'];
        }

        // 6. Hari Raya Idul Fitri (1 & 2 Syawal) & Cuti Bersama
        if ($month === 'syawal') {
            if ($day === 1) return ['nama' => "Hari Raya Idul Fitri $year H (Kalender Islam NU Online)", 'status' => 'Libur Nasional', 'color' => 'red'];
            if ($day === 2) return ['nama' => "Hari Raya Idul Fitri Hari ke-2 (Kalender Islam NU Online)", 'status' => 'Libur Nasional', 'color' => 'red'];
            if ($day >= 3 && $day <= 5) return ['nama' => "Cuti Bersama Hari Raya Idul Fitri (Kalender Islam NU)", 'status' => 'Libur Nasional', 'color' => 'red'];
        }

        // 7. Hari Raya Idul Adha (10 Dzulhijjah) & Hari Tasyrik (11-13 Dzulhijjah)
        if ($month === 'zulhijah') {
            if ($day === 10) return ['nama' => "Hari Raya Idul Adha $year H (Kalender Islam NU Online)", 'status' => 'Libur Nasional', 'color' => 'red'];
            if ($day >= 11 && $day <= 13) return ['nama' => "Hari Tasyrik (Kalender Islam Falakiyah NU)", 'status' => 'Libur Khusus Keagamaan', 'color' => 'purple'];
        }

        return null;
    }
}

/* ==============================================================================
 * HELPER: FETCH KONTEN URL DENGAN TIMEOUT & USER AGENT
 * ============================================================================== */
if (!function_exists('fetchUrlContent')) {
    function fetchUrlContent($url) {
        $resp = false;
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 12,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)'
            ]);
            $resp = curl_exec($ch);
            curl_close($ch);
        }
        if (!$resp) {
            $ctx = stream_context_create([
                'http' => ['timeout' => 12, 'user_agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)', 'ignore_errors' => true],
                'ssl'  => ['verify_peer' => false, 'verify_peer_name' => false]
            ]);
            $resp = @file_get_contents($url, false, $ctx);
        }
        return $resp;
    }
}

/* ==============================================================================
 * HELPER: FETCH LIBUR NASIONAL DARI API PUBLIK RESMI INDONESIA (SKB 3 MENTERI)
 * Menggunakan multi-source fallback (day-off-api, guangrei holidays, api-harilibur)
 * ============================================================================== */
if (!function_exists('fetchLiburNasionalFromAPI')) {
    function fetchLiburNasionalFromAPI($year) {
        $year = (int)$year;
        $results = [];

        // Sumber 1: day-off-api.vercel.app (SKB 3 Menteri resmi)
        $url1 = "https://day-off-api.vercel.app/api?year=" . $year;
        $resp1 = fetchUrlContent($url1);
        if ($resp1) {
            $data1 = json_decode($resp1, true);
            if (is_array($data1) && !empty($data1)) {
                foreach ($data1 as $item) {
                    $rawDate = trim($item['tanggal'] ?? '');
                    if (!$rawDate) continue;
                    $p = explode('-', $rawDate);
                    if (count($p) === 3) {
                        $dFmt = sprintf('%04d-%02d-%02d', (int)$p[0], (int)$p[1], (int)$p[2]);
                        $isCuti = !empty($item['is_cuti']);
                        $ket = trim($item['keterangan'] ?? 'Libur Nasional');
                        $results[$dFmt] = [
                            'holiday_date' => $dFmt,
                            'holiday_name' => $ket . ($isCuti ? ' (Cuti Bersama)' : ''),
                            'is_cuti'      => $isCuti,
                            'is_national_holiday' => true
                        ];
                    }
                }
                if (!empty($results)) return array_values($results);
            }
        }

        // Sumber 2: guangrei GitHub API Hari Libur
        $url2 = "https://raw.githubusercontent.com/guangrei/APIHariLibur_V2/main/holidays.json";
        $resp2 = fetchUrlContent($url2);
        if ($resp2) {
            $data2 = json_decode($resp2, true);
            if (is_array($data2) && !empty($data2)) {
                foreach ($data2 as $dt => $info) {
                    if (strpos($dt, "$year-") === 0) {
                        $results[$dt] = [
                            'holiday_date' => $dt,
                            'holiday_name' => $info['summary'] ?? 'Libur Nasional',
                            'is_cuti'      => false,
                            'is_national_holiday' => true
                        ];
                    }
                }
                if (!empty($results)) return array_values($results);
            }
        }

        return array_values($results);
    }
}

/* ==============================================================================
 * HELPER: SINKRONISASI CEPAT KALDIK KE SELURUH KELAS & MAPEL (TRANSACTION-WRAPPED)
 * ============================================================================== */
if (!function_exists('syncKaldikToHariDanPekanEfektif')) {
function syncKaldikToHariDanPekanEfektif($pdo, $tahun, $semester) {
    try {
        $allKmp = $pdo->query("SELECT id FROM kelas_mata_pelajaran")->fetchAll(PDO::FETCH_COLUMN);
        if (empty($allKmp)) return 0;

        $stmtK = $pdo->prepare("SELECT * FROM kalender_pendidikan WHERE tahun_pelajaran = ? AND semester = ? ORDER BY tanggal ASC");
        $stmtK->execute([$tahun, $semester]);
        $kaldikList = $stmtK->fetchAll(PDO::FETCH_ASSOC);
        if (empty($kaldikList)) return 0;

        $pdo->beginTransaction();

        $stmtHe = $pdo->prepare("
            INSERT INTO hari_efektif 
                (id_kelas_mata_pelajaran, tahun_pelajaran, semester, tanggal, hari, status_hari, keterangan)
            VALUES (?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE 
                hari = VALUES(hari),
                status_hari = VALUES(status_hari),
                keterangan = VALUES(keterangan),
                semester = VALUES(semester),
                tahun_pelajaran = VALUES(tahun_pelajaran)
        ");

        foreach ($kaldikList as $k) {
            foreach ($allKmp as $kmpId) {
                $stmtHe->execute([
                    $kmpId, $k['tahun_pelajaran'], $k['semester'], $k['tanggal'],
                    $k['hari'], $k['status_hari'], $k['keterangan']
                ]);
            }
        }

        // Kalkulasi ulang Pekan Efektif untuk semua KMP
        $bulanList = ($semester === 'Ganjil')
            ? [1=>['Juli',7],2=>['Agustus',8],3=>['September',9],4=>['Oktober',10],5=>['November',11],6=>['Desember',12]]
            : [7=>['Januari',1],8=>['Februari',2],9=>['Maret',3],10=>['April',4],11=>['Mei',5],12=>['Juni',6]];

        $stmtPe = $pdo->prepare("
            INSERT INTO pekan_efektif
                (id_kelas_mata_pelajaran, tahun_pelajaran, semester, bulan, urutan_bulan,
                 senin, selasa, rabu, kamis, jumat, sabtu, minggu,
                 total_hari_efektif, jumlah_pekan_total, jumlah_pekan_efektif, jumlah_pekan_tidak_efektif, keterangan_tidak_efektif)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                urutan_bulan = VALUES(urutan_bulan),
                senin = VALUES(senin), selasa = VALUES(selasa), rabu = VALUES(rabu),
                kamis = VALUES(kamis), jumat = VALUES(jumat), sabtu = VALUES(sabtu), minggu = VALUES(minggu),
                total_hari_efektif = VALUES(total_hari_efektif),
                jumlah_pekan_total = VALUES(jumlah_pekan_total),
                jumlah_pekan_efektif = VALUES(jumlah_pekan_efektif),
                jumlah_pekan_tidak_efektif = VALUES(jumlah_pekan_tidak_efektif),
                keterangan_tidak_efektif = VALUES(keterangan_tidak_efektif)
        ");

        foreach ($allKmp as $kmpId) {
            foreach ($bulanList as $urutan => $bInfo) {
                $namaBulan = $bInfo[0]; $mDigit = $bInfo[1];

                $stmtH = $pdo->prepare("
                    SELECT hari, COUNT(*) as jml FROM hari_efektif
                    WHERE id_kelas_mata_pelajaran = ? AND semester = ?
                      AND status_hari = 'Efektif KBM' AND MONTH(tanggal) = ?
                    GROUP BY hari
                ");
                $stmtH->execute([$kmpId, $semester, $mDigit]);
                $counts = $stmtH->fetchAll(PDO::FETCH_KEY_PAIR);

                $senin  = (int)($counts['Senin'] ?? 0);
                $selasa = (int)($counts['Selasa'] ?? 0);
                $rabu   = (int)($counts['Rabu'] ?? 0);
                $kamis  = (int)($counts['Kamis'] ?? 0);
                $jumat  = (int)($counts['Jumat'] ?? 0);
                $sabtu  = (int)($counts['Sabtu'] ?? 0);
                $minggu = (int)($counts['Minggu'] ?? 0);

                $totalHe = $senin + $selasa + $rabu + $kamis + $jumat + $sabtu;
                $pekanTotal = in_array($mDigit, [7,8,10,12,1,3,5], true) ? 5 : 4;
                $pekanEfektif = $totalHe === 0 ? 0 : min($pekanTotal, max(1, (int)round($totalHe / 5)));
                $pekanTidakEfektif = max(0, $pekanTotal - $pekanEfektif);

                $stmtKet = $pdo->prepare("
                    SELECT GROUP_CONCAT(DISTINCT keterangan SEPARATOR '; ')
                    FROM hari_efektif
                    WHERE id_kelas_mata_pelajaran = ? AND semester = ?
                      AND status_hari != 'Efektif KBM' AND MONTH(tanggal) = ?
                ");
                $stmtKet->execute([$kmpId, $semester, $mDigit]);
                $ketNon = $stmtKet->fetchColumn() ?: ($pekanTidakEfektif > 0 ? 'Kegiatan Non-KBM / Libur Semester' : 'KBM Efektif Penuh');

                $stmtPe->execute([
                    $kmpId, $tahun, $semester, $namaBulan, $urutan,
                    $senin, $selasa, $rabu, $kamis, $jumat, $sabtu, $minggu,
                    $totalHe, $pekanTotal, $pekanEfektif, $pekanTidakEfektif, $ketNon
                ]);
            }
        }

        $pdo->commit();
        return count($allKmp);
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log("Gagal sync kaldik: " . $e->getMessage());
        return 0;
    }
}
}

/* ==============================================================================
 * HELPER: AMBIL STATE LENGKAP KALDIK & RPE (UNTUK LAZY LOAD & IN-PLACE UPDATE)
 * ============================================================================== */
if (!function_exists('getKaldikStateData')) {
    function getKaldikStateData($pdo, $tahun, $semester) {
        $stmt = $pdo->prepare("SELECT * FROM kalender_pendidikan WHERE tahun_pelajaran = ? AND semester = ? ORDER BY tanggal ASC");
        $stmt->execute([$tahun, $semester]);
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $itemsByDate = [];
        $countHE = 0; $countLiburNas = 0; $countLiburSem = 0; $countKegiatan = 0; $countLiburAgama = 0;
        foreach ($items as &$it) {
            $it['hijri'] = getTanggalHijriyah($it['tanggal'], 'd MMM y');
            $itemsByDate[$it['tanggal']] = $it;
            if ($it['status_hari'] === 'Efektif KBM') $countHE++;
            elseif ($it['status_hari'] === 'Libur Nasional') $countLiburNas++;
            elseif ($it['status_hari'] === 'Libur Semester') $countLiburSem++;
            elseif ($it['status_hari'] === 'Kegiatan Sekolah/Ujian') $countKegiatan++;
            elseif ($it['status_hari'] === 'Libur Khusus Keagamaan') $countLiburAgama++;
        }
        unset($it);

        $bulanList = ($semester === 'Ganjil')
            ? [1=>['Juli',7],2=>['Agustus',8],3=>['September',9],4=>['Oktober',10],5=>['November',11],6=>['Desember',12]]
            : [7=>['Januari',1],8=>['Februari',2],9=>['Maret',3],10=>['April',4],11=>['Mei',5],12=>['Juni',6]];

        $rpeSekolah = [];
        $totSen=0;$totSel=0;$totRab=0;$totKam=0;$totJum=0;$totSab=0;
        $grandHe=0;$grandPekanTotal=0;$grandPe=0;$grandPte=0;

        foreach ($bulanList as $urutan => $bInfo) {
            $namaBulan = $bInfo[0];
            $mDigit = $bInfo[1];

            $stmtKCount = $pdo->prepare("
                SELECT hari, COUNT(*) as jml FROM kalender_pendidikan
                WHERE tahun_pelajaran = ? AND semester = ?
                  AND status_hari = 'Efektif KBM' AND MONTH(tanggal) = ?
                GROUP BY hari
            ");
            $stmtKCount->execute([$tahun, $semester, $mDigit]);
            $countsK = $stmtKCount->fetchAll(PDO::FETCH_KEY_PAIR);

            $senin  = (int)($countsK['Senin'] ?? 0);
            $selasa = (int)($countsK['Selasa'] ?? 0);
            $rabu   = (int)($countsK['Rabu'] ?? 0);
            $kamis  = (int)($countsK['Kamis'] ?? 0);
            $jumat  = (int)($countsK['Jumat'] ?? 0);
            $sabtu  = (int)($countsK['Sabtu'] ?? 0);

            $totalHe = $senin + $selasa + $rabu + $kamis + $jumat + $sabtu;
            $pekanTotal = in_array($mDigit, [7,8,10,12,1,3,5], true) ? 5 : 4;
            $pekanEfektif = $totalHe === 0 ? 0 : min($pekanTotal, max(1, (int)round($totalHe / 5)));
            $pekanTidakEfektif = max(0, $pekanTotal - $pekanEfektif);

            $stmtKet = $pdo->prepare("
                SELECT GROUP_CONCAT(DISTINCT keterangan SEPARATOR '; ')
                FROM kalender_pendidikan
                WHERE tahun_pelajaran = ? AND semester = ?
                  AND status_hari != 'Efektif KBM' AND MONTH(tanggal) = ?
            ");
            $stmtKet->execute([$tahun, $semester, $mDigit]);
            $ketAgenda = $stmtKet->fetchColumn() ?: ($pekanTidakEfektif > 0 ? 'Kegiatan Non-KBM / Libur Semester' : 'KBM Efektif Penuh');

            $rpeSekolah[] = [
                'urutan' => $urutan,
                'bulan'  => $namaBulan,
                'mDigit' => $mDigit,
                'senin'  => $senin,
                'selasa' => $selasa,
                'rabu'   => $rabu,
                'kamis'  => $kamis,
                'jumat'  => $jumat,
                'sabtu'  => $sabtu,
                'total_he' => $totalHe,
                'pekan_total' => $pekanTotal,
                'pekan_efektif' => $pekanEfektif,
                'pekan_tidak_efektif' => $pekanTidakEfektif,
                'keterangan' => $ketAgenda
            ];

            $totSen += $senin; $totSel += $selasa; $totRab += $rabu;
            $totKam += $kamis; $totJum += $jumat; $totSab += $sabtu;
            $grandHe += $totalHe;
            $grandPekanTotal += $pekanTotal;
            $grandPe += $pekanEfektif;
            $grandPte += $pekanTidakEfektif;
        }

        return [
            'items'         => $items,
            'items_by_date' => $itemsByDate,
            'stats' => [
                'count_he'        => $countHE,
                'count_libur_nas' => $countLiburNas,
                'count_libur_sem' => $countLiburSem,
                'count_kegiatan'  => $countKegiatan,
                'count_keagamaan' => $countLiburAgama,
                'total_dates'     => count($items),
                'grand_he'        => $grandHe,
                'grand_pe'        => $grandPe,
                'grand_pte'       => $grandPte,
                'grand_pekan_total' => $grandPekanTotal
            ],
            'rpe' => $rpeSekolah,
            'rpe_totals' => [
                'senin' => $totSen, 'selasa' => $totSel, 'rabu' => $totRab,
                'kamis' => $totKam, 'jumat' => $totJum, 'sabtu' => $totSab,
                'total_he' => $grandHe, 'pekan_total' => $grandPekanTotal,
                'pekan_efektif' => $grandPe, 'pekan_tidak_efektif' => $grandPte
            ]
        ];
    }
}

/* ==============================================================================
 * HELPER: GENERATE PRE-RENDERED SEMESTER HTML & STATE UNTUK LAZY LOAD INSTAN
 * ============================================================================== */
if (!function_exists('getSemesterFullData')) {
    function getSemesterFullData($pdo, $tahun, $semester) {
        $activeMonths = ($semester === 'Ganjil')
            ? [7=>'Juli', 8=>'Agustus', 9=>'September', 10=>'Oktober', 11=>'November', 12=>'Desember']
            : [1=>'Januari', 2=>'Februari', 3=>'Maret', 4=>'April', 5=>'Mei', 6=>'Juni'];

        $parts = explode('/', $tahun);
        $yearGanjil = (int)($parts[0] ?? date('Y'));
        $yearGenap  = (int)($parts[1] ?? ($yearGanjil + 1));
        $calYear    = ($semester === 'Ganjil') ? $yearGanjil : $yearGenap;

        $curRealMonth = (int)date('n');
        if ($semester === 'Ganjil') {
            $defaultActiveMonth = in_array($curRealMonth, [7,8,9,10,11,12], true) ? $curRealMonth : 7;
        } else {
            $defaultActiveMonth = in_array($curRealMonth, [1,2,3,4,5,6], true) ? $curRealMonth : 1;
        }

        $state = getKaldikStateData($pdo, $tahun, $semester);
        $kaldikMap = $state['items_by_date'] ?? [];
        $rpeSekolah = $state['rpe'] ?? [];

        // 1. Month Pills HTML
        ob_start();
        foreach ($activeMonths as $mNum => $mName):
            $rpeKey = ($semester === 'Ganjil') ? ($mNum - 6) : $mNum;
            $mHe = $rpeSekolah[$rpeKey - 1]['total_he'] ?? 0;
        ?>
            <button type="button" class="kaldik-month-pill-btn <?= ($mNum === $defaultActiveMonth) ? 'is-active' : '' ?>" data-month="<?= $mNum ?>">
                <span><?= $mName ?></span>
                <small class="kaldik-pill-he"><?= $mHe ?> HE</small>
            </button>
        <?php
        endforeach;
        $pillsHtml = ob_get_clean();

        // 2. Month Panes HTML
        ob_start();
        $todayStr = date('Y-m-d');
        foreach ($activeMonths as $mNum => $mName):
            $daysInMonth = cal_days_in_month(CAL_GREGORIAN, $mNum, $calYear);
            $firstDayOfWeek = (int)date('w', strtotime("$calYear-$mNum-01"));
            $firstCol = ($firstDayOfWeek + 6) % 7;

            $dFirst = sprintf('%04d-%02d-01', $calYear, $mNum);
            $dLast  = sprintf('%04d-%02d-%02d', $calYear, $mNum, $daysInMonth);
            $hijriSpanStr = "🌙 " . getTanggalHijriyah($dFirst, 'd MMM') . " – " . getTanggalHijriyah($dLast, 'd MMM y');
            $rpeIdx = ($semester === 'Ganjil') ? ($mNum - 6) : $mNum;
            $heBulan = $rpeSekolah[$rpeIdx - 1]['total_he'] ?? 0;
        ?>
            <div class="kaldik-month-pane <?= ($mNum === $defaultActiveMonth) ? 'is-active' : '' ?>" id="monthPane-<?= $mNum ?>"
                 data-month="<?= $mNum ?>"
                 data-title="<?= $mName ?> <?= $calYear ?>"
                 data-hijri="<?= htmlspecialchars($hijriSpanStr) ?>"
                 data-he="<?= $heBulan ?>"
                 style="display: <?= ($mNum === $defaultActiveMonth) ? 'block' : 'none' ?>;">

                <table class="kaldik-single-table">
                    <thead>
                        <tr>
                            <th>Senin</th>
                            <th>Selasa</th>
                            <th>Rabu</th>
                            <th>Kamis</th>
                            <th>Jumat</th>
                            <th>Sabtu</th>
                            <th class="th-sun">Minggu</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $dayCounter = 1;
                        for ($row = 0; $row < 6; $row++):
                            if ($dayCounter > $daysInMonth) break;
                        ?>
                            <tr>
                                <?php for ($col = 0; $col < 7; $col++): ?>
                                    <?php if ($row === 0 && $col < $firstCol): ?>
                                        <td class="cell-empty">&bull;</td>
                                    <?php elseif ($dayCounter <= $daysInMonth): ?>
                                        <?php
                                        $dateStr = sprintf('%04d-%02d-%02d', $calYear, $mNum, $dayCounter);
                                        $dItem = $kaldikMap[$dateStr] ?? null;
                                        $isToday = ($dateStr === $todayStr);
                                        $isSun = ($col === 6);

                                        $hijriShort = getTanggalHijriyah($dateStr, 'd MMM');
                                        $hijriFull  = getTanggalHijriyah($dateStr, 'full');

                                        $st = $dItem['status_hari'] ?? ($isSun ? 'Libur Semester' : 'Efektif KBM');
                                        $ket = $dItem['keterangan'] ?? ($isSun ? 'Libur Akhir Pekan (Minggu)' : 'KBM Reguler');
                                        if ($isSun && ($st === 'Efektif KBM' || empty($dItem))) {
                                            $st = 'Libur Semester';
                                            $ket = 'Libur Akhir Pekan (Minggu)';
                                        }
                                        $chipCls = 'rbc-chip--kbm';
                                        if ($st === 'Libur Nasional') $chipCls = 'rbc-chip--libur';
                                        elseif ($st === 'Libur Semester') $chipCls = 'rbc-chip--sem';
                                        elseif ($st === 'Kegiatan Sekolah/Ujian') $chipCls = 'rbc-chip--kegiatan';
                                        elseif ($st === 'Libur Khusus Keagamaan') $chipCls = 'rbc-chip--keagamaan';
                                        elseif (stripos($ket, 'P5') !== false) $chipCls = 'rbc-chip--p5';
                                        ?>
                                        <td class="kaldik-cell <?= $isToday ? 'is-today' : '' ?> <?= $isSun ? 'is-sunday' : '' ?>"
                                            data-date="<?= $dateStr ?>"
                                            data-id="<?= $dItem['id'] ?? 0 ?>"
                                            data-status="<?= htmlspecialchars($st) ?>"
                                            data-ket="<?= htmlspecialchars($ket) ?>"
                                            data-hijri="<?= htmlspecialchars($hijriFull) ?>"
                                            title="<?= date('d M Y', strtotime($dateStr)) ?> (<?= $hijriFull ?>)&#10;Status: <?= $st ?>&#10;Agenda: <?= $ket ?>">
                                            <div class="kaldik-cell-top">
                                                <div style="display: flex; align-items: center; gap: 5px;">
                                                    <span class="kaldik-masehi-num"><?= $dayCounter ?></span>
                                                    <?php if ($isToday): ?>
                                                        <span style="font-size: 9.5px; font-weight: 800; background: #0D7A87; color: #FFFFFF; padding: 1px 5px; border-radius: 4px;">Hari Ini</span>
                                                    <?php endif; ?>
                                                </div>
                                                <span class="kaldik-hijri-num" title="Kalender Hijriyah Umm al-Qura">
                                                    <iconify-icon icon="solar:moon-bold" width="11"></iconify-icon>
                                                    <span><?= htmlspecialchars($hijriShort) ?></span>
                                                </span>
                                            </div>
                                            <div class="rbc-chips-wrap">
                                                <div class="rbc-event-chip <?= $chipCls ?>" data-date="<?= $dateStr ?>">
                                                    <span class="rbc-chip-dot"></span>
                                                    <span class="rbc-chip-title"><?= htmlspecialchars($ket) ?></span>
                                                </div>
                                            </div>
                                        </td>
                                        <?php $dayCounter++; ?>
                                    <?php else: ?>
                                        <td class="cell-empty">&bull;</td>
                                    <?php endif; ?>
                                <?php endfor; ?>
                            </tr>
                        <?php endfor; ?>
                    </tbody>
                </table>
            </div>
        <?php
        endforeach;
        $panesHtml = ob_get_clean();

        // 3. RPE Table Rows HTML
        ob_start();
        if (empty($rpeSekolah)):
        ?>
            <tr>
                <td colspan="13" style="text-align: center; padding: 36px 20px; color: #64748B;">
                    Belum ada data kalender pendidikan. Silakan generate Kaldik terlebih dahulu.
                </td>
            </tr>
        <?php
        else:
            $noR = 1;
            foreach ($rpeSekolah as $r):
        ?>
            <tr id="rpeRow-<?= $r['urutan'] ?>">
                <td style="text-align: center; font-weight: 700; color: #64748B;"><?= $noR++ ?></td>
                <td><strong style="color: #1E293B; font-size: 13px;"><?= htmlspecialchars($r['bulan']) ?></strong></td>
                <td class="rpe-cell-senin" style="text-align: center; font-weight: 600;"><?= $r['senin'] ?></td>
                <td class="rpe-cell-selasa" style="text-align: center; font-weight: 600;"><?= $r['selasa'] ?></td>
                <td class="rpe-cell-rabu" style="text-align: center; font-weight: 600;"><?= $r['rabu'] ?></td>
                <td class="rpe-cell-kamis" style="text-align: center; font-weight: 600;"><?= $r['kamis'] ?></td>
                <td class="rpe-cell-jumat" style="text-align: center; font-weight: 600;"><?= $r['jumat'] ?></td>
                <td class="rpe-cell-sabtu" style="text-align: center; font-weight: 600;"><?= $r['sabtu'] ?></td>
                <td class="rpe-cell-total-he" style="text-align: center; font-weight: 800; background: #F0FDF4; color: #047857; font-size: 13.5px;"><?= $r['total_he'] ?></td>
                <td class="rpe-cell-pekan-total" style="text-align: center; font-weight: 600;"><?= $r['pekan_total'] ?></td>
                <td class="rpe-cell-pekan-efektif" style="text-align: center; font-weight: 800; background: #F0F9FF; color: #0284C7; font-size: 13.5px;"><?= $r['pekan_efektif'] ?></td>
                <td class="rpe-cell-pekan-non-efektif" style="text-align: center; font-weight: 700; color: #B91C1C;"><?= $r['pekan_tidak_efektif'] ?></td>
                <td class="rpe-cell-keterangan" style="font-size: 12px; color: #475569;"><?= htmlspecialchars($r['keterangan']) ?></td>
            </tr>
        <?php
            endforeach;
        endif;
        $rpeRowsHtml = ob_get_clean();

        return [
            'tahun'              => $tahun,
            'semester'           => $semester,
            'calYear'            => $calYear,
            'activeMonths'       => $activeMonths,
            'monthKeys'          => array_values(array_keys($activeMonths)),
            'defaultActiveMonth' => $defaultActiveMonth,
            'state'              => $state,
            'pillsHtml'          => $pillsHtml,
            'panesHtml'          => $panesHtml,
            'rpeRowsHtml'        => $rpeRowsHtml,
            'rpeTotals'          => $state['rpe_totals'] ?? []
        ];
    }
}

// Handler GET AJAX Lazy Load State
if (isset($_GET['ajax']) && $_GET['ajax'] === 'get_kaldik_state') {
    header('Content-Type: application/json; charset=utf-8');
    $tahun    = trim($_GET['tahun'] ?? '2026/2027');
    $semester = in_array($_GET['semester'] ?? '', ['Ganjil', 'Genap'], true) ? $_GET['semester'] : 'Ganjil';
    echo json_encode(['success' => true, 'data' => ['state' => getKaldikStateData($pdo, $tahun, $semester)]]);
    exit;
}

// Handler GET AJAX Full Semester Switch (Pills, Panes, RPE, State in one fast call)
if (isset($_GET['ajax']) && $_GET['ajax'] === 'get_semester_full') {
    header('Content-Type: application/json; charset=utf-8');
    $tahun    = trim($_GET['tahun'] ?? '2026/2027');
    $semester = in_array($_GET['semester'] ?? '', ['Ganjil', 'Genap'], true) ? $_GET['semester'] : 'Ganjil';
    echo json_encode(['success' => true, 'data' => getSemesterFullData($pdo, $tahun, $semester)]);
    exit;
}

/* ==============================================================================
 * 1. BACKEND AJAX API HANDLERS (ADMIN KALDIK & KURIKULUM)
 * ============================================================================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json; charset=utf-8');
    $act = $_POST['action'];

    try {
        if ($act === 'get_kaldik_state') {
            $tahun    = trim($_POST['tahun_pelajaran'] ?? '2026/2027');
            $semester = in_array($_POST['semester'] ?? '', ['Ganjil', 'Genap'], true) ? $_POST['semester'] : 'Ganjil';
            echo json_encode(['success' => true, 'data' => ['state' => getKaldikStateData($pdo, $tahun, $semester)]]);
            exit;
        }

        /* ------------------------------------------------------------------
         * 1.1 SAVE SINGLE DATE KALDIK
         * ------------------------------------------------------------------ */
        if ($act === 'save_kaldik_hari') {
            $idHari   = (int)($_POST['id'] ?? 0);
            $tahun    = trim($_POST['tahun_pelajaran'] ?? '2026/2027');
            $semester = in_array($_POST['semester'] ?? '', ['Ganjil', 'Genap'], true) ? $_POST['semester'] : 'Ganjil';
            $tanggal  = trim($_POST['tanggal'] ?? '');
            $status   = trim($_POST['status_hari'] ?? 'Efektif KBM');
            $ket      = trim($_POST['keterangan'] ?? 'KBM Reguler');

            if ($tanggal === '') {
                echo json_encode(['success' => false, 'data' => ['message' => 'Tanggal kalender wajib diisi.']]);
                exit;
            }
            $namaHari = getNamaHariIndo($tanggal);

            // Proteksi: Hari Minggu WAJIB TETAP LIBUR (tidak ada KBM di hari Minggu)
            if ($namaHari === 'Minggu' && $status === 'Efektif KBM') {
                $status = 'Libur Semester';
                $ket = 'Libur Akhir Pekan (Minggu)';
            }

            if ($idHari > 0) {
                $stmt = $pdo->prepare("
                    UPDATE kalender_pendidikan 
                    SET tahun_pelajaran = ?, semester = ?, tanggal = ?, hari = ?, status_hari = ?, keterangan = ?
                    WHERE id = ?
                ");
                $stmt->execute([$tahun, $semester, $tanggal, $namaHari, $status, $ket, $idHari]);
                $msg = "Tanggal " . date('d-m-Y', strtotime($tanggal)) . " berhasil diperbarui.";
            } else {
                $stmt = $pdo->prepare("
                    INSERT INTO kalender_pendidikan
                        (tahun_pelajaran, semester, tanggal, hari, status_hari, keterangan)
                    VALUES (?, ?, ?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE
                        status_hari = VALUES(status_hari),
                        keterangan  = VALUES(keterangan),
                        hari        = VALUES(hari)
                ");
                $stmt->execute([$tahun, $semester, $tanggal, $namaHari, $status, $ket]);
                $msg = "Tanggal " . date('d-m-Y', strtotime($tanggal)) . " berhasil disimpan ke Kaldik.";
            }

            syncKaldikToHariDanPekanEfektif($pdo, $tahun, $semester);

            echo json_encode([
                'success' => true,
                'data' => [
                    'message' => $msg,
                    'state'   => getKaldikStateData($pdo, $tahun, $semester)
                ]
            ]);
            exit;
        }

        /* ------------------------------------------------------------------
         * 1.2 SAVE BATCH / RANGE DATES (DRAG-TO-SELECT / KUAS PEWARNA)
         * ------------------------------------------------------------------ */
        if ($act === 'save_kaldik_batch') {
            $tahun    = trim($_POST['tahun_pelajaran'] ?? '2026/2027');
            $semester = in_array($_POST['semester'] ?? '', ['Ganjil', 'Genap'], true) ? $_POST['semester'] : 'Ganjil';
            $status   = trim($_POST['status_hari'] ?? 'Efektif KBM');
            $ket      = trim($_POST['keterangan'] ?? 'KBM Reguler');

            $dates = [];
            if (!empty($_POST['dates']) && is_array($_POST['dates'])) {
                $dates = $_POST['dates'];
            } elseif (!empty($_POST['start_date']) && !empty($_POST['end_date'])) {
                $start = strtotime($_POST['start_date']);
                $end   = strtotime($_POST['end_date']);
                if ($start > $end) { $tmp = $start; $start = $end; $end = $tmp; }
                while ($start <= $end) {
                    $dates[] = date('Y-m-d', $start);
                    $start = strtotime('+1 day', $start);
                }
            }

            if (empty($dates)) {
                echo json_encode(['success' => false, 'data' => ['message' => 'Tidak ada tanggal yang dipilih.']]);
                exit;
            }

            $stmt = $pdo->prepare("
                INSERT INTO kalender_pendidikan
                    (tahun_pelajaran, semester, tanggal, hari, status_hari, keterangan)
                VALUES (?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                    status_hari = VALUES(status_hari),
                    keterangan  = VALUES(keterangan),
                    hari        = VALUES(hari)
            ");

            if ($status === 'DELETE') {
                $pdo->beginTransaction();
                $stmtDelK = $pdo->prepare("DELETE FROM kalender_pendidikan WHERE tahun_pelajaran = ? AND semester = ? AND tanggal = ?");
                $stmtDelH = $pdo->prepare("DELETE FROM hari_efektif WHERE tahun_pelajaran = ? AND semester = ? AND tanggal = ?");
                foreach ($dates as $d) {
                    $stmtDelK->execute([$tahun, $semester, $d]);
                    $stmtDelH->execute([$tahun, $semester, $d]);
                }
                $pdo->commit();

                syncKaldikToHariDanPekanEfektif($pdo, $tahun, $semester);

                echo json_encode([
                    'success' => true,
                    'data' => [
                        'message' => count($dates) . " tanggal berhasil di-reset / dihapus dari Kaldik.",
                        'state'   => getKaldikStateData($pdo, $tahun, $semester)
                    ]
                ]);
                exit;
            }

            $pdo->beginTransaction();
            foreach ($dates as $d) {
                $namaHari = getNamaHariIndo($d);
                $stToApply = $status;
                $ketToApply = $ket;

                // Proteksi: Hari Minggu WAJIB TETAP LIBUR (tidak ada KBM atau kegiatan sekolah di hari Minggu)
                if ($namaHari === 'Minggu' && $stToApply === 'Efektif KBM') {
                    $stToApply = 'Libur Semester';
                    $ketToApply = 'Libur Akhir Pekan (Minggu)';
                }

                $stmt->execute([$tahun, $semester, $d, $namaHari, $stToApply, $ketToApply]);
            }
            $pdo->commit();

            syncKaldikToHariDanPekanEfektif($pdo, $tahun, $semester);

            echo json_encode([
                'success' => true,
                'data' => [
                    'message' => count($dates) . " tanggal berhasil diatur menjadi [$status: $ket].",
                    'state'   => getKaldikStateData($pdo, $tahun, $semester)
                ]
            ]);
            exit;
        }

        /* ------------------------------------------------------------------
         * 1.3 DELETE SINGLE DATE FROM KALDIK
         * ------------------------------------------------------------------ */
        if ($act === 'delete_kaldik_hari') {
            $idHari = (int)($_POST['id'] ?? 0);
            if ($idHari > 0) {
                $stmtGet = $pdo->prepare("SELECT tanggal, tahun_pelajaran, semester FROM kalender_pendidikan WHERE id = ?");
                $stmtGet->execute([$idHari]);
                $row = $stmtGet->fetch(PDO::FETCH_ASSOC);

                if ($row) {
                    $pdo->prepare("DELETE FROM kalender_pendidikan WHERE id = ?")->execute([$idHari]);
                    $pdo->prepare("DELETE FROM hari_efektif WHERE tanggal = ? AND tahun_pelajaran = ? AND semester = ?")
                        ->execute([$row['tanggal'], $row['tahun_pelajaran'], $row['semester']]);

                    syncKaldikToHariDanPekanEfektif($pdo, $row['tahun_pelajaran'], $row['semester']);

                    echo json_encode([
                        'success' => true,
                        'data' => [
                            'message' => 'Tanggal berhasil dihapus dari Kalender Pendidikan.',
                            'deleted_id' => $idHari,
                            'deleted_date' => $row['tanggal'],
                            'state' => getKaldikStateData($pdo, $row['tahun_pelajaran'], $row['semester'])
                        ]
                    ]);
                    exit;
                }
            }
            echo json_encode(['success' => false, 'data' => ['message' => 'ID agenda tidak valid.']]);
            exit;
        }

        /* ------------------------------------------------------------------
         * 1.4 GENERATE 1 SEMESTER PENUH
         * ------------------------------------------------------------------ */
        if ($act === 'generate_kaldik_semester') {
            $tahun      = trim($_POST['tahun_pelajaran'] ?? '2026/2027');
            $semester   = in_array($_POST['semester'] ?? '', ['Ganjil', 'Genap'], true) ? $_POST['semester'] : 'Ganjil';
            $liburSabtu = !empty($_POST['libur_sabtu']) && $_POST['libur_sabtu'] == '1';

            $parts = explode('/', $tahun);
            $startYear = (int)($parts[0] ?? date('Y'));
            $endYear   = (int)($parts[1] ?? ($startYear + 1));

            if ($semester === 'Ganjil') {
                $startDate = "$startYear-07-01";
                $endDate   = "$startYear-12-31";
            } else {
                $startDate = "$endYear-01-01";
                $endDate   = "$endYear-06-30";
            }

            $current = strtotime($startDate);
            $last    = strtotime($endDate);
            $inserted = 0;

            $stmtInsert = $pdo->prepare("
                INSERT INTO kalender_pendidikan
                    (tahun_pelajaran, semester, tanggal, hari, status_hari, keterangan)
                VALUES (?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                    hari = VALUES(hari)
            ");

            $pdo->beginTransaction();
            while ($current <= $last) {
                $dateStr = date('Y-m-d', $current);
                $dayName = getNamaHariIndo($dateStr);
                $status  = 'Efektif KBM';
                $ket     = 'KBM Reguler';

                if ($dayName === 'Minggu') {
                    $status = 'Libur Semester';
                    $ket    = 'Libur Akhir Pekan (Minggu)';
                } elseif ($dayName === 'Sabtu' && $liburSabtu) {
                    $status = 'Libur Semester';
                    $ket    = 'Libur Akhir Pekan (Sabtu)';
                }

                $stmtInsert->execute([$tahun, $semester, $dateStr, $dayName, $status, $ket]);
                if ($stmtInsert->rowCount() > 0) $inserted++;
                $current = strtotime('+1 day', $current);
            }
            $pdo->commit();

            syncKaldikToHariDanPekanEfektif($pdo, $tahun, $semester);

            echo json_encode([
                'success' => true,
                'data' => [
                    'message' => "Kalender Semester $semester berhasil digenerate ($inserted tanggal diproses dan disinkronkan ke seluruh kelas).",
                    'state'   => getKaldikStateData($pdo, $tahun, $semester)
                ]
            ]);
            exit;
        }

        /* ------------------------------------------------------------------
         * 1.5 IMPORT LIBUR NASIONAL & HARI BESAR ISLAM
         * ------------------------------------------------------------------ */
        if ($act === 'import_libur_nasional') {
            $tahun    = trim($_POST['tahun_pelajaran'] ?? '2026/2027');
            $semester = in_array($_POST['semester'] ?? '', ['Ganjil', 'Genap'], true) ? $_POST['semester'] : 'Ganjil';

            $parts   = explode('/', $tahun);
            $startYr = (int)($parts[0] ?? date('Y'));
            $endYr   = (int)($parts[1] ?? ($startYr + 1));
            if ($semester === 'Ganjil') { $rStart = "$startYr-07-01"; $rEnd = "$startYr-12-31"; }
            else                        { $rStart = "$endYr-01-01";  $rEnd = "$endYr-06-30"; }

            // 1. Ambil dari API Resmi Libur Nasional Indonesia (SKB 3 Menteri)
            $all = array_merge(fetchLiburNasionalFromAPI($startYr), fetchLiburNasionalFromAPI($endYr));
            $holidays = [];
            foreach ($all as $h) {
                $d = $h['holiday_date'] ?? '';
                if (!$d || $d < $rStart || $d > $rEnd) continue;
                $holidays[$d] = [
                    'tanggal' => $d,
                    'nama'    => $h['holiday_name'] ?? 'Libur Nasional',
                    'status'  => 'Libur Nasional'
                ];
            }

            // 2. Integrasikan Kalender Islam Falakiyah NU Online & Standar Kemenag RI
            $cur = strtotime($rStart);
            $end = strtotime($rEnd);
            while ($cur <= $end) {
                $ds = date('Y-m-d', $cur);
                $islami = getEventIslami($ds);
                if ($islami) {
                    if (!isset($holidays[$ds])) {
                        $holidays[$ds] = [
                            'tanggal' => $ds,
                            'nama'    => $islami['nama'],
                            'status'  => $islami['status']
                        ];
                    } else {
                        $holidays[$ds]['nama'] .= ' [' . $islami['nama'] . ']';
                    }
                }
                $cur = strtotime('+1 day', $cur);
            }

            $stmtUp = $pdo->prepare("
                INSERT INTO kalender_pendidikan
                    (tahun_pelajaran, semester, tanggal, hari, status_hari, keterangan)
                VALUES (?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                    status_hari = VALUES(status_hari),
                    keterangan  = VALUES(keterangan),
                    hari        = VALUES(hari)
            ");

            $pdo->beginTransaction();
            $imported = 0;
            foreach ($holidays as $h) {
                $d  = $h['tanggal'];
                $nm = $h['nama'];
                $st = $h['status'];
                $hr = getNamaHariIndo($d);
                $stmtUp->execute([$tahun, $semester, $d, $hr, $st, $nm]);
                $imported++;
            }
            $pdo->commit();

            syncKaldikToHariDanPekanEfektif($pdo, $tahun, $semester);

            echo json_encode([
                'success' => true,
                'data' => [
                    'message' => "$imported tanggal Libur Nasional (API SKB 3 Menteri) & Hari Besar Islam (Falakiyah NU Online) berhasil diterapkan ke Kaldik.",
                    'state'   => getKaldikStateData($pdo, $tahun, $semester)
                ]
            ]);
            exit;
        }

        /* ------------------------------------------------------------------
         * 1.6 SINKRONISASI MANUAL KE SELURUH KBM
         * ------------------------------------------------------------------ */
        if ($act === 'sync_kaldik_ke_kbm') {
            $tahun    = trim($_POST['tahun_pelajaran'] ?? '2026/2027');
            $semester = in_array($_POST['semester'] ?? '', ['Ganjil', 'Genap'], true) ? $_POST['semester'] : 'Ganjil';

            $jmlSynced = syncKaldikToHariDanPekanEfektif($pdo, $tahun, $semester);

            echo json_encode([
                'success' => true,
                'data' => [
                    'message' => "Kalender Pendidikan berhasil disinkronkan ke seluruh $jmlSynced kelas/mapel KBM aktif.",
                    'state'   => getKaldikStateData($pdo, $tahun, $semester)
                ]
            ]);
            exit;
        }

        /* ------------------------------------------------------------------
         * 1.7 CRUD CP & ELEMEN
         * ------------------------------------------------------------------ */
        if ($act === 'save_elemen') {
            $rawKmps  = $_POST['id_kelas_mata_pelajaran'] ?? [];
            if (!is_array($rawKmps)) {
                $rawKmps = !empty($rawKmps) ? explode(',', (string)$rawKmps) : [];
            }
            $cleanKmps = array_filter(array_map('intval', $rawKmps));
            $nama      = trim($_POST['nama_elemen'] ?? '');
            $fase      = trim($_POST['fase'] ?? 'Fase F');
            $desk      = trim($_POST['deskripsi'] ?? '');

            if (empty($cleanKmps) || empty($nama)) {
                echo json_encode(['success' => false, 'data' => ['message' => 'Pilih minimal satu Rombel/Mata Pelajaran dan isi Nama Elemen.']]);
                exit;
            }

            $stmtIns = $pdo->prepare("INSERT INTO elemen (id_kelas_mata_pelajaran, nama_elemen, fase, deskripsi) VALUES (?, ?, ?, ?)");
            $newIds = [];
            foreach ($cleanKmps as $kmpId) {
                $stmtIns->execute([$kmpId, $nama, $fase, $desk ?: "Elemen $nama"]);
                $newIds[] = (int)$pdo->lastInsertId();
            }

            $placeholders = implode(',', array_fill(0, count($newIds), '?'));
            $stmtInfo = $pdo->prepare("SELECT e.*, k.nama_kelas, mp.nama_mata_pelajaran FROM elemen e JOIN kelas_mata_pelajaran kmp ON e.id_kelas_mata_pelajaran = kmp.id JOIN kelas k ON kmp.id_kelas = k.id JOIN mata_pelajaran mp ON kmp.id_mata_pelajaran = mp.id WHERE e.id IN ($placeholders) ORDER BY e.id ASC");
            $stmtInfo->execute($newIds);
            $elList = $stmtInfo->fetchAll(PDO::FETCH_ASSOC);

            $msg = count($newIds) > 1 
                ? count($newIds) . ' Elemen "' . $nama . '" berhasil ditambahkan untuk rombel terpilih.'
                : 'Elemen "' . $nama . '" berhasil ditambahkan.';

            echo json_encode([
                'success' => true,
                'data' => [
                    'message'     => $msg,
                    'id'          => $newIds[0],
                    'nama'        => $nama,
                    'fase'        => $fase,
                    'kmp'         => $cleanKmps[0],
                    'elemen'      => $elList[0] ?? null,
                    'elemen_list' => $elList
                ]
            ]);
            exit;
        }

        if ($act === 'delete_elemen') {
            $idElemen = (int)($_POST['id'] ?? 0);
            $cek = $pdo->prepare("SELECT COUNT(*) FROM cp WHERE id_elemen = ?");
            $cek->execute([$idElemen]);
            if ((int)$cek->fetchColumn() > 0) {
                echo json_encode(['success' => false, 'data' => ['message' => 'Elemen masih dipakai oleh dokumen CP. Hapus CP terkait terlebih dahulu.']]);
                exit;
            }
            $pdo->prepare("DELETE FROM elemen WHERE id = ?")->execute([$idElemen]);
            echo json_encode(['success' => true, 'data' => ['message' => 'Elemen berhasil dihapus.', 'id' => $idElemen]]);
            exit;
        }

        if ($act === 'save_cp') {
            $idCp     = (int)($_POST['id'] ?? 0);
            $rawKmps  = $_POST['id_kelas_mata_pelajaran'] ?? [];
            if (!is_array($rawKmps)) {
                $rawKmps = !empty($rawKmps) ? explode(',', (string)$rawKmps) : [];
            }
            $cleanKmps = array_filter(array_map('intval', $rawKmps));
            $idElemen  = (int)($_POST['id_elemen'] ?? 0);
            $teksCp    = trim($_POST['cp'] ?? '');

            if (empty($cleanKmps) || empty($teksCp) || $idElemen <= 0) {
                echo json_encode(['success' => false, 'data' => ['message' => 'Pilih minimal satu Rombel/Mata Pelajaran, Elemen, dan isi naskah CP.']]);
                exit;
            }

            if ($idCp > 0) {
                $targetKmp = reset($cleanKmps);
                $pdo->prepare("UPDATE cp SET id_kelas_mata_pelajaran = ?, id_elemen = ?, cp = ? WHERE id = ?")
                    ->execute([$targetKmp, $idElemen, $teksCp, $idCp]);
                $msg = 'Dokumen Capaian Pembelajaran (CP) berhasil diperbarui.';
                $targetIds = [$idCp];
            } else {
                $targetIds = [];
                $stmtIns = $pdo->prepare("INSERT INTO cp (id_kelas_mata_pelajaran, id_elemen, cp) VALUES (?, ?, ?)");
                foreach ($cleanKmps as $kmpId) {
                    $stmtIns->execute([$kmpId, $idElemen, $teksCp]);
                    $targetIds[] = (int)$pdo->lastInsertId();
                }
                $msg = count($targetIds) > 1
                    ? count($targetIds) . ' Dokumen Capaian Pembelajaran (CP) berhasil ditambahkan untuk rombel terpilih.'
                    : 'Dokumen Capaian Pembelajaran (CP) baru berhasil ditambahkan.';
            }

            $placeholders = implode(',', array_fill(0, count($targetIds), '?'));
            $stmtRow = $pdo->prepare("SELECT c.*, e.nama_elemen, e.fase, k.nama_kelas, mp.nama_mata_pelajaran FROM cp c LEFT JOIN elemen e ON c.id_elemen = e.id JOIN kelas_mata_pelajaran kmp ON c.id_kelas_mata_pelajaran = kmp.id JOIN kelas k ON kmp.id_kelas = k.id JOIN mata_pelajaran mp ON kmp.id_mata_pelajaran = mp.id WHERE c.id IN ($placeholders) ORDER BY c.id ASC");
            $stmtRow->execute($targetIds);
            $cpRows = $stmtRow->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode([
                'success' => true,
                'data' => [
                    'message' => $msg,
                    'cp'      => $cpRows[0] ?? null,
                    'cp_list' => $cpRows,
                    'is_edit' => ($idCp > 0)
                ]
            ]);
            exit;
        }

        if ($act === 'delete_cp') {
            $idCp = (int)($_POST['id'] ?? 0);
            if ($idCp > 0) {
                $pdo->prepare("DELETE FROM cp WHERE id = ?")->execute([$idCp]);
                echo json_encode(['success' => true, 'data' => ['message' => 'Capaian Pembelajaran (CP) berhasil dihapus.', 'id' => $idCp]]);
            }
            exit;
        }

        if ($act === 'bulk_delete_cp') {
            $rawIds = $_POST['ids'] ?? [];
            if (is_string($rawIds)) {
                $rawIds = json_decode($rawIds, true) ?: explode(',', $rawIds);
            }
            $cleanIds = array_filter(array_map('intval', (array)$rawIds));
            if (!empty($cleanIds)) {
                $placeholders = implode(',', array_fill(0, count($cleanIds), '?'));
                $stmtDel = $pdo->prepare("DELETE FROM cp WHERE id IN ($placeholders)");
                $stmtDel->execute($cleanIds);
                echo json_encode([
                    'success' => true,
                    'data' => [
                        'message' => count($cleanIds) . ' Capaian Pembelajaran (CP) berhasil dihapus.',
                        'deleted_ids' => $cleanIds
                    ]
                ]);
            } else {
                echo json_encode(['success' => false, 'data' => ['message' => 'Tidak ada CP yang dipilih untuk dihapus.']]);
            }
            exit;
        }

        echo json_encode(['success' => false, 'data' => ['message' => 'Aksi tidak dikenali.']]);
        exit;
    } catch (Throwable $e) {
        echo json_encode(['success' => false, 'data' => ['message' => 'Terjadi kesalahan sistem: ' . $e->getMessage()]]);
        exit;
    }
}

/* ==============================================================================
 * 2. DATA QUERY & PREPARATION
 * ============================================================================== */
$selectedSem   = in_array($_GET['semester'] ?? '', ['Ganjil', 'Genap'], true) ? $_GET['semester'] : 'Ganjil';
$selectedTahun = trim($_GET['tahun'] ?? '2026/2027');

// 2.1 Ambil Data Kaldik & RPE Sekolah via Helper Terpusat
$semData = getSemesterFullData($pdo, $selectedTahun, $selectedSem);
$kaldikList       = $semData['state']['items'];
$kaldikMap        = $semData['state']['items_by_date'];
$countHE          = $semData['state']['stats']['count_he'];
$countLiburNas    = $semData['state']['stats']['count_libur_nas'];
$countLiburSem    = $semData['state']['stats']['count_libur_sem'];
$countKegiatan    = $semData['state']['stats']['count_kegiatan'];
$countLiburAgama  = $semData['state']['stats']['count_keagamaan'];
$rpeSekolah       = $semData['state']['rpe'];
$totSen           = $semData['rpeTotals']['senin'] ?? 0;
$totSel           = $semData['rpeTotals']['selasa'] ?? 0;
$totRab           = $semData['rpeTotals']['rabu'] ?? 0;
$totKam           = $semData['rpeTotals']['kamis'] ?? 0;
$totJum           = $semData['rpeTotals']['jumat'] ?? 0;
$totSab           = $semData['rpeTotals']['sabtu'] ?? 0;
$grandHe          = $semData['rpeTotals']['total_he'] ?? 0;
$grandPekanTotal  = $semData['rpeTotals']['pekan_total'] ?? 0;
$grandPe          = $semData['rpeTotals']['pekan_efektif'] ?? 0;
$grandPte         = $semData['rpeTotals']['pekan_tidak_efektif'] ?? 0;
$activeMonths     = $semData['activeMonths'];
$calYear          = $semData['calYear'];
$defaultActiveMonth = $semData['defaultActiveMonth'];
if (!empty($_GET['bulan']) && isset($activeMonths[(int)$_GET['bulan']])) {
    $defaultActiveMonth = (int)$_GET['bulan'];
}

// 2.3 Master Data CP & Elemen
$kmpList = $pdo->query("
    SELECT kmp.id, kmp.id_kelas, kmp.id_mata_pelajaran,
           k.nama_kelas, COALESCE(k.tahun_pelajaran, '2026/2027') AS tahun_pelajaran,
           mp.nama_mata_pelajaran, COALESCE(mp.semester, 'Ganjil') AS semester
    FROM kelas_mata_pelajaran kmp
    JOIN kelas k ON kmp.id_kelas = k.id
    JOIN mata_pelajaran mp ON kmp.id_mata_pelajaran = mp.id
    ORDER BY k.nama_kelas ASC, mp.nama_mata_pelajaran ASC
")->fetchAll(PDO::FETCH_ASSOC);

$cpList = $pdo->query("
    SELECT c.*, e.nama_elemen, e.fase, e.deskripsi as deskripsi_elemen,
           k.nama_kelas, mp.nama_mata_pelajaran
    FROM cp c
    LEFT JOIN elemen e ON c.id_elemen = e.id
    JOIN kelas_mata_pelajaran kmp ON c.id_kelas_mata_pelajaran = kmp.id
    JOIN kelas k ON kmp.id_kelas = k.id
    JOIN mata_pelajaran mp ON kmp.id_mata_pelajaran = mp.id
    ORDER BY c.id ASC
")->fetchAll(PDO::FETCH_ASSOC);

$elemenList = $pdo->query("
    SELECT e.id, e.id_kelas_mata_pelajaran, e.nama_elemen, e.fase, e.deskripsi,
           kmp.id_mata_pelajaran, k.nama_kelas, mp.nama_mata_pelajaran
    FROM elemen e
    LEFT JOIN kelas_mata_pelajaran kmp ON e.id_kelas_mata_pelajaran = kmp.id
    LEFT JOIN kelas k ON kmp.id_kelas = k.id
    LEFT JOIN mata_pelajaran mp ON kmp.id_mata_pelajaran = mp.id
    ORDER BY e.nama_elemen ASC, k.nama_kelas ASC
")->fetchAll(PDO::FETCH_ASSOC);

// 2.4 Struktur Kurikulum & Beban Belajar
$strukturMapel = $pdo->query("
    SELECT kmp.id as id_kmp, k.nama_kelas, mp.nama_mata_pelajaran, mp.kode_mapel,
           COALESCE(u.full_name, u.username, 'Belum Ditugaskan') as nama_guru,
           (SELECT COUNT(*) FROM cp WHERE cp.id_kelas_mata_pelajaran = kmp.id) as total_cp,
           (SELECT COUNT(*) FROM elemen WHERE elemen.id_kelas_mata_pelajaran = kmp.id) as total_elemen
    FROM kelas_mata_pelajaran kmp
    JOIN kelas k ON kmp.id_kelas = k.id
    JOIN mata_pelajaran mp ON kmp.id_mata_pelajaran = mp.id
    LEFT JOIN users u ON kmp.id_guru_mata_pelajaran = u.id
    ORDER BY k.nama_kelas ASC, mp.nama_mata_pelajaran ASC
")->fetchAll(PDO::FETCH_ASSOC);

$cssVersion = file_exists(BASE_PATH . '/assets/css/admin-assessment-access.css') ? filemtime(BASE_PATH . '/assets/css/admin-assessment-access.css') : '1.3';
$extraHead = '<link rel="stylesheet" href="' . BASE_URL . '/assets/css/admin-assessment-access.css?v=' . $cssVersion . '">
<style>
.ilmuna-curriculum-app { max-width: 1480px; margin: 0 auto; padding: 20px 20px 80px; font-family: \'Plus Jakarta Sans\', system-ui, -apple-system, sans-serif; color: #1E293B; }

/* ===== CURRICULUM MAIN TABS (SEGMENTED CONTROL, 100% ANTI-TIMPANG TINDIH & ANTI-OVERLAP) ===== */
.curriculum-main-tabs {
    display: flex !important;
    align-items: center !important;
    gap: 8px !important;
    background: #F1F5F9 !important;
    border: 1px solid #E2E8F0 !important;
    border-radius: 14px !important;
    padding: 6px !important;
    margin-bottom: 22px !important;
    overflow-x: auto !important;
    scrollbar-width: none !important;
    -ms-overflow-style: none !important;
    box-sizing: border-box !important;
    width: 100% !important;
}
.curriculum-main-tabs::-webkit-scrollbar {
    display: none !important;
}

.curriculum-tab-btn {
    display: inline-flex !important;
    align-items: center !important;
    justify-content: center !important;
    flex-direction: row !important;
    gap: 8px !important;
    padding: 10px 18px !important;
    border-radius: 10px !important;
    border: 1px solid transparent !important;
    background: transparent !important;
    color: #475569 !important;
    font-size: 13px !important;
    font-weight: 700 !important;
    cursor: pointer !important;
    white-space: nowrap !important;
    flex: 1 1 0% !important;
    min-width: max-content !important;
    transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1) !important;
    user-select: none !important;
    line-height: 1 !important;
    box-shadow: none !important;
    text-align: center !important;
    margin: 0 !important;
    outline: none !important;
    text-decoration: none !important;
}

.curriculum-tab-btn:hover {
    color: #0D7A87 !important;
    background: rgba(255, 255, 255, 0.7) !important;
}

.curriculum-tab-btn.is-active {
    background: #FFFFFF !important;
    color: #0D7A87 !important;
    border-color: #CBD5E1 !important;
    box-shadow: 0 2px 8px rgba(15, 23, 42, 0.08) !important;
}

.curriculum-tab-btn iconify-icon {
    font-size: 17px !important;
    flex-shrink: 0 !important;
    color: currentColor !important;
    margin: 0 !important;
}

.curriculum-tab-label {
    white-space: nowrap !important;
    font-weight: inherit !important;
    color: inherit !important;
    line-height: 1 !important;
}

.curriculum-tab-counter {
    display: inline-flex !important;
    align-items: center !important;
    justify-content: center !important;
    min-width: 22px !important;
    height: 20px !important;
    padding: 0 7px !important;
    border-radius: 10px !important;
    background: #E2E8F0 !important;
    color: #475569 !important;
    font-size: 11px !important;
    font-weight: 800 !important;
    flex-shrink: 0 !important;
    margin-left: 2px !important;
    transition: all 0.2s ease !important;
    line-height: 1 !important;
}

.curriculum-tab-btn.is-active .curriculum-tab-counter {
    background: #CCFBF1 !important;
    color: #0F766E !important;
}

/* ===== SEGMENTED SEMESTER PILLS (1 TAHUN DIBAGI 2 SEMESTER) ===== */
.ilmuna-sem-pill-group {
    display: inline-flex;
    align-items: center;
    background: #F1F5F9;
    border: 1px solid #E2E8F0;
    border-radius: 10px;
    padding: 3px;
    gap: 3px;
}
.ilmuna-sem-pill-btn {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 6px 14px;
    border-radius: 8px;
    font-size: 12.5px;
    font-weight: 700;
    color: #64748B;
    text-decoration: none;
    transition: all 0.15s ease;
    border: none;
    background: transparent;
    cursor: pointer;
}
.ilmuna-sem-pill-btn:hover {
    color: #0F172A;
    background: rgba(255, 255, 255, 0.7);
}
.ilmuna-sem-pill-btn.is-active {
    background: #FFFFFF;
    color: #0D7A87;
    box-shadow: 0 2px 6px rgba(15, 23, 42, 0.08);
}
.ilmuna-sem-pill-btn.is-active.is-genap {
    color: #0284C7;
}

/* ===== PALET WARNA & QUICK PAINTER TOOLBAR ===== */
.kaldik-painter-card {
    background: #FFFFFF;
    border: 1px solid #E2E8F0;
    border-radius: 14px;
    padding: 14px 18px;
    margin-bottom: 18px;
    box-shadow: 0 4px 14px rgba(15, 23, 42, 0.03);
}
.kaldik-swatch-group {
    display: flex;
    align-items: center;
    gap: 8px;
    flex-wrap: wrap;
}
.kaldik-swatch-btn {
    display: inline-flex;
    align-items: center;
    gap: 7px;
    padding: 7px 13px;
    border-radius: 8px;
    font-size: 12.5px;
    font-weight: 700;
    cursor: pointer;
    border: 1.5px solid transparent;
    background: #F8FAFC;
    transition: all 0.15s ease;
}
.kaldik-swatch-btn:hover {
    transform: translateY(-1px);
    box-shadow: 0 3px 8px rgba(0,0,0,0.06);
}
.kaldik-swatch-btn.is-selected {
    box-shadow: 0 0 0 2.5px #0F172A, 0 4px 10px rgba(0,0,0,0.1);
    transform: scale(1.02);
}
.kaldik-swatch-btn--kbm { color: #065F46; background: #ECFDF5; border-color: #A7F3D0; }
.kaldik-swatch-btn--libur { color: #991B1B; background: #FEF2F2; border-color: #FECACA; }
.kaldik-swatch-btn--sem { color: #475569; background: #F1F5F9; border-color: #CBD5E1; }
.kaldik-swatch-btn--kegiatan { color: #92400E; background: #FFFBEB; border-color: #FDE68A; }
.kaldik-swatch-btn--keagamaan { color: #6B21A8; background: #FAF5FF; border-color: #E9D5FF; }
.kaldik-swatch-btn--khusus { color: #075985; background: #F0F9FF; border-color: #BAE6FD; }

/* ===== SINGLE RESPONSIVE CALENDAR CONTAINER (1 KALENDER SESUAI LUAS LAYAR) ===== */
.kaldik-single-wrapper {
    background: #FFFFFF;
    border: 1px solid #E2E8F0;
    border-radius: 16px;
    overflow: hidden;
    box-shadow: 0 4px 20px rgba(15, 23, 42, 0.04);
    margin-bottom: 24px;
    user-select: none;
}

/* ==============================================================================
 * REACT-BIG-CALENDAR (RBC) INSPIRED MODERN DESIGN SYSTEM
 * ============================================================================== */
.rbc-card-wrapper {
    background: #FFFFFF;
    border: 1px solid #E2E8F0;
    border-radius: 20px;
    box-shadow: 0 4px 20px rgba(15, 23, 42, 0.04);
    overflow: hidden;
    margin-bottom: 24px;
}

/* 1. RBC TOOLBAR */
.rbc-toolbar-box {
    padding: 16px 22px;
    background: #FFFFFF;
    border-bottom: 1px solid #E2E8F0;
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 14px;
}
.rbc-toolbar-left {
    display: flex;
    align-items: center;
    gap: 12px;
    min-width: 250px;
}
.rbc-toolbar-icon-box {
    width: 44px;
    height: 44px;
    border-radius: 12px;
    background: rgba(13, 122, 135, 0.09);
    color: #0D7A87;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 22px;
    flex-shrink: 0;
}
.rbc-toolbar-title-wrap {
    display: flex;
    flex-direction: column;
    gap: 3px;
}
.rbc-toolbar-title {
    font-size: 19px;
    font-weight: 800;
    color: #0F172A;
    letter-spacing: -0.02em;
    line-height: 1.2;
}
.rbc-toolbar-hijri {
    font-size: 12.5px;
    font-weight: 700;
    color: #0D7A87;
    background: rgba(13, 122, 135, 0.08);
    padding: 2px 9px;
    border-radius: 12px;
    display: inline-flex;
    align-items: center;
    gap: 5px;
    width: fit-content;
}

/* Nav Group: Prev, Today, Next */
.rbc-nav-group {
    display: inline-flex;
    align-items: center;
    background: #F8FAFC;
    border: 1px solid #E2E8F0;
    border-radius: 10px;
    padding: 3px;
    gap: 2px;
}
.rbc-nav-btn {
    border: none;
    background: transparent;
    padding: 6px 12px;
    border-radius: 7px;
    font-size: 12.5px;
    font-weight: 700;
    color: #475569;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    transition: all 0.15s ease;
}
.rbc-nav-btn:hover {
    background: #FFFFFF;
    color: #0F172A;
    box-shadow: 0 1px 3px rgba(0,0,0,0.06);
}
.rbc-nav-btn:active {
    transform: scale(0.97);
}

/* View Switchers: Bulan, Minggu, Hari, Agenda */
.rbc-view-group {
    display: inline-flex;
    align-items: center;
    background: #F1F5F9;
    border-radius: 10px;
    padding: 3px;
    gap: 2px;
}
.rbc-view-btn {
    border: none;
    background: transparent;
    padding: 7px 14px;
    border-radius: 8px;
    font-size: 12.5px;
    font-weight: 700;
    color: #64748B;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    transition: all 0.15s ease;
}
.rbc-view-btn:hover {
    color: #0F172A;
}
.rbc-view-btn.is-active {
    background: #FFFFFF;
    color: #0D7A87;
    font-weight: 800;
    box-shadow: 0 2px 6px rgba(15, 23, 42, 0.08);
}

/* 2. QUICK MONTH PILLS (6 BULAN SEMESTER) */
.kaldik-month-pills-bar {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
    flex-wrap: wrap;
    padding: 10px 18px;
    background: #F8FAFC;
    border-bottom: 1px solid #E2E8F0;
}
.kaldik-month-pill-btn {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 6px 14px;
    border-radius: 9px;
    font-size: 12.5px;
    font-weight: 700;
    background: #FFFFFF;
    color: #475569;
    border: 1px solid #CBD5E1;
    cursor: pointer;
    transition: all 0.15s ease;
}
.kaldik-month-pill-btn:hover {
    background: #F1F5F9;
    color: #0D7A87;
    border-color: #94A3B8;
}
.kaldik-month-pill-btn.is-active {
    background: #0D7A87;
    color: #FFFFFF;
    border-color: #0D7A87;
    box-shadow: 0 3px 8px rgba(13, 122, 135, 0.25);
}
.kaldik-pill-he {
    font-size: 10.5px;
    font-weight: 800;
    opacity: 0.85;
}

/* 2.1 SMOOTH MONTH PANE TRANSITIONS */
.kaldik-month-pane {
    display: none;
}
.kaldik-month-pane.is-active {
    display: block;
    animation: kaldikPaneFade 0.22s cubic-bezier(0.16, 1, 0.3, 1) forwards;
}
@keyframes kaldikPaneFade {
    0% {
        opacity: 0;
        transform: translateY(6px);
    }
    100% {
        opacity: 1;
        transform: translateY(0);
    }
}

/* Quick Duration Shortcuts in Modal */
.btn-quick-dur {
    border: 1px solid #CBD5E1;
    background: #F8FAFC;
    color: #334155;
    font-size: 11px;
    font-weight: 700;
    padding: 4px 10px;
    border-radius: 6px;
    cursor: pointer;
    transition: all 0.15s ease;
    user-select: none;
}
.btn-quick-dur:hover {
    background: #0D7A87;
    color: #FFFFFF;
    border-color: #0D7A87;
}
.btn-quick-dur:active {
    transform: scale(0.96);
}

/* 3. MONTH GRID VIEW */
.kaldik-single-table {
    width: 100%;
    border-collapse: collapse;
    table-layout: fixed;
}
.kaldik-single-table th {
    padding: 12px 6px;
    font-size: 12px;
    font-weight: 800;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    color: #475569;
    background: #F8FAFC;
    border-bottom: 1.5px solid #E2E8F0;
    border-right: 1px solid #E2E8F0;
    text-align: center;
    width: 14.285%;
}
.kaldik-single-table th:last-child { border-right: none; }
.kaldik-single-table th.th-sun {
    color: #DC2626 !important;
    background: #FEF2F2 !important;
}

.kaldik-cell {
    height: 120px;
    min-height: 120px;
    border-right: 1px solid #E2E8F0;
    border-bottom: 1px solid #E2E8F0;
    padding: 8px 10px;
    vertical-align: top;
    cursor: pointer;
    position: relative;
    background: #FFFFFF;
    transition: background 0.12s, box-shadow 0.12s;
}
.kaldik-cell:last-child { border-right: none; }
.kaldik-cell:hover {
    box-shadow: inset 0 0 0 2px #0D7A87;
    z-index: 2;
}
.kaldik-cell.is-sunday {
    background: #FFF5F5 !important;
}
.kaldik-cell.is-sunday .kaldik-masehi-num {
    color: #DC2626 !important;
    font-weight: 800 !important;
}
.kaldik-cell.is-sunday .kaldik-hijri-num {
    color: #B91C1C !important;
    background: rgba(220, 38, 38, 0.08) !important;
}
.is-kuas-active .kaldik-cell {
    cursor: cell !important;
}
.is-kuas-active .kaldik-cell:hover {
    box-shadow: inset 0 0 0 2.5px #10B981, 0 4px 12px rgba(16, 185, 129, 0.15) !important;
    background: rgba(16, 185, 129, 0.04) !important;
    z-index: 4;
}
.kaldik-cell.is-today {
    border-top: 2.5px solid #0D7A87 !important;
    background: rgba(13, 122, 135, 0.035) !important;
}
.kaldik-cell.is-selecting {
    outline: 2.5px dashed #0284C7 !important;
    background: rgba(2, 132, 199, 0.18) !important;
    z-index: 5;
}
.kaldik-cell-top {
    display: flex;
    justify-content: space-between;
    align-items: center;
    line-height: 1;
    margin-bottom: 6px;
}
.kaldik-masehi-num {
    font-size: 16px;
    font-weight: 800;
    color: #0F172A;
}
.kaldik-cell.is-today .kaldik-masehi-num {
    color: #0D7A87;
}
.kaldik-hijri-num {
    font-size: 10.5px;
    font-weight: 700;
    color: #0D7A87;
    background: rgba(13, 122, 135, 0.09);
    padding: 2px 6px;
    border-radius: 6px;
    display: inline-flex;
    align-items: center;
    gap: 3px;
}
.cell-empty {
    background: #F8FAFC;
    border-right: 1px solid #E2E8F0;
    border-bottom: 1px solid #E2E8F0;
    cursor: default;
    opacity: 0.5;
}

/* RBC EVENT CHIPS (PERSIS SEPERTI REACT CustomEvent) */
.rbc-chips-wrap {
    display: flex;
    flex-direction: column;
    gap: 4px;
    margin-top: 4px;
}
.rbc-event-chip {
    display: flex;
    align-items: center;
    gap: 6px;
    padding: 3.5px 7px;
    border-radius: 6px;
    font-size: 11px;
    font-weight: 700;
    line-height: 1.3;
    cursor: pointer;
    transition: all 0.12s ease;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    border-left: 3px solid transparent;
}
.rbc-event-chip:hover {
    transform: translateY(-1px);
    box-shadow: 0 2px 5px rgba(0,0,0,0.08);
}
.rbc-chip-dot {
    width: 6px;
    height: 6px;
    border-radius: 50%;
    flex-shrink: 0;
}
.rbc-chip-title {
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    flex: 1;
}

/* Chip Colors */
.rbc-chip--kbm {
    background: rgba(16, 185, 129, 0.12);
    color: #065F46;
    border-left-color: #10B981;
}
.rbc-chip--kbm .rbc-chip-dot { background: #10B981; }

.rbc-chip--libur {
    background: rgba(239, 68, 68, 0.12);
    color: #991B1B;
    border-left-color: #EF4444;
}
.rbc-chip--libur .rbc-chip-dot { background: #EF4444; }

.rbc-chip--sem {
    background: rgba(100, 116, 139, 0.12);
    color: #334155;
    border-left-color: #64748B;
}
.rbc-chip--sem .rbc-chip-dot { background: #64748B; }

.rbc-chip--kegiatan {
    background: rgba(245, 158, 11, 0.14);
    color: #92400E;
    border-left-color: #F59E0B;
}
.rbc-chip--kegiatan .rbc-chip-dot { background: #F59E0B; }

.rbc-chip--keagamaan {
    background: rgba(139, 92, 246, 0.14);
    color: #5B21B6;
    border-left-color: #8B5CF6;
}
.rbc-chip--keagamaan .rbc-chip-dot { background: #8B5CF6; }

.rbc-chip--p5 {
    background: rgba(2, 132, 199, 0.14);
    color: #075985;
    border-left-color: #0284C7;
}
.rbc-chip--p5 .rbc-chip-dot { background: #0284C7; }

/* 4. WEEK VIEW GRID */
.rbc-week-grid {
    display: grid;
    grid-template-columns: repeat(7, 1fr);
    min-height: 480px;
    background: #FFFFFF;
}
@media (max-width: 900px) {
    .rbc-week-grid { grid-template-columns: repeat(2, 1fr); }
}
@media (max-width: 520px) {
    .rbc-week-grid { grid-template-columns: 1fr; }
}
.rbc-week-col {
    border-right: 1px solid #E2E8F0;
    display: flex;
    flex-direction: column;
}
.rbc-week-col:last-child { border-right: none; }
.rbc-week-header {
    padding: 12px 10px;
    background: #F8FAFC;
    border-bottom: 1.5px solid #E2E8F0;
    text-align: center;
}
.rbc-week-col.is-today .rbc-week-header {
    background: rgba(13, 122, 135, 0.08);
    border-bottom-color: #0D7A87;
}
.rbc-week-day-name {
    font-size: 12px;
    font-weight: 800;
    text-transform: uppercase;
    color: #475569;
}
.rbc-week-day-num {
    font-size: 18px;
    font-weight: 800;
    color: #0F172A;
    margin: 2px 0;
}
.rbc-week-day-hijri {
    font-size: 10.5px;
    color: #0D7A87;
    font-weight: 700;
}
.rbc-week-body {
    padding: 10px;
    flex: 1;
    display: flex;
    flex-direction: column;
    gap: 8px;
    background: #FFFFFF;
}
.rbc-week-add-btn {
    margin-top: auto;
    padding: 6px;
    border-radius: 8px;
    border: 1px dashed #CBD5E1;
    background: #F8FAFC;
    color: #64748B;
    font-size: 11px;
    font-weight: 700;
    cursor: pointer;
    text-align: center;
    transition: all 0.15s;
}
.rbc-week-add-btn:hover {
    border-color: #0D7A87;
    color: #0D7A87;
    background: rgba(13, 122, 135, 0.05);
}

/* 5. DAY HERO VIEW */
.rbc-day-hero-wrap {
    padding: 24px;
    background: #FFFFFF;
}
.rbc-day-hero-card {
    background: #F8FAFC;
    border: 1px solid #E2E8F0;
    border-radius: 16px;
    padding: 24px;
    max-width: 680px;
    margin: 0 auto;
}
.rbc-day-hero-top {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 16px;
    margin-bottom: 20px;
    flex-wrap: wrap;
}
.rbc-day-hero-date {
    font-size: 26px;
    font-weight: 800;
    color: #0F172A;
    line-height: 1.2;
}
.rbc-day-hero-sub {
    font-size: 14px;
    color: #64748B;
    font-weight: 600;
    margin-top: 4px;
}
.rbc-day-quick-grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 10px;
    margin-top: 18px;
}
@media (max-width: 600px) {
    .rbc-day-quick-grid { grid-template-columns: 1fr; }
}
.rbc-day-quick-btn {
    padding: 10px 14px;
    border-radius: 10px;
    border: 1px solid #E2E8F0;
    background: #FFFFFF;
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 12.5px;
    font-weight: 700;
    cursor: pointer;
    transition: all 0.15s ease;
    text-align: left;
}
.rbc-day-quick-btn:hover {
    border-color: #0D7A87;
    transform: translateY(-1px);
    box-shadow: 0 3px 8px rgba(0,0,0,0.06);
}

/* 6. MODAL TYPE PICKER (PERSIS SEPERTI REACT EventModal) */
.rbc-type-picker-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 8px;
}
@media (max-width: 480px) {
    .rbc-type-picker-grid { grid-template-columns: 1fr; }
}
.rbc-type-card {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 9px 12px;
    border-radius: 10px;
    border: 1.5px solid #E2E8F0;
    background: #FFFFFF;
    cursor: pointer;
    transition: all 0.15s ease;
}
.rbc-type-card:hover {
    border-color: #CBD5E1;
    background: #F8FAFC;
}
.rbc-type-card.is-active {
    border-color: #0D7A87;
    background: rgba(13, 122, 135, 0.05);
    box-shadow: 0 0 0 1px #0D7A87;
}
.rbc-type-dot {
    width: 12px;
    height: 12px;
    border-radius: 50%;
    flex-shrink: 0;
}
.rbc-type-text {
    line-height: 1.2;
    overflow: hidden;
}
.rbc-type-text strong {
    display: block;
    font-size: 12px;
    color: #0F172A;
    font-weight: 700;
}
.rbc-type-text small {
    display: block;
    font-size: 10.5px;
    color: #64748B;
    margin-top: 2px;
}

.tab-pane { display: none; }
.tab-pane.is-active { display: block; animation: fadeIn 0.25s ease; }
@keyframes fadeIn { from { opacity: 0; transform: translateY(4px); } to { opacity: 1; transform: translateY(0); } }

/* FLOATING DRAG CONFIRMATION BAR */
.kaldik-drag-floating-bar {
    position: fixed;
    bottom: 24px;
    left: 50%;
    transform: translateX(-50%) translateY(30px);
    background: #0F172A;
    color: #FFFFFF;
    border-radius: 16px;
    padding: 12px 20px;
    box-shadow: 0 12px 36px rgba(0,0,0,0.35);
    display: none;
    align-items: center;
    gap: 14px;
    z-index: 9999;
    font-size: 13px;
    transition: transform 0.25s ease, opacity 0.25s ease;
    opacity: 0;
    max-width: 95vw;
    flex-wrap: wrap;
}
.kaldik-drag-floating-bar.show {
    display: flex;
    transform: translateX(-50%) translateY(0);
    opacity: 1;
}
</style>';

require_once BASE_PATH . '/includes/header.php';
?>

<div class="ilmuna-curriculum-app" id="appCurriculum">

    <!-- HEADER TITLE & ROLE BADGE -->
    <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 16px; flex-wrap: wrap; gap: 12px;">
        <div>
            <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 4px;">
                <h1 style="font-size: 22px; font-weight: 800; color: #0F172A; margin: 0; letter-spacing: -0.02em;">
                    Manajemen Kurikulum &amp; Kalender Pendidikan (Kaldik)
                </h1>
                <span style="background: #EEF2FF; color: #4338CA; border: 1px solid #C7D2FE; font-weight: 800; font-size: 11px; padding: 3px 8px; border-radius: 6px;">Administrator Sekolah</span>
            </div>
            <p style="font-size: 13px; color: #64748B; margin: 0;">
                Sistem Kalender Pendidikan Interaktif (Masehi &amp; Hijriyah), Analisis RPE Sekolah, Standar Capaian Pembelajaran (CP &amp; Elemen), dan Struktur Kurikulum.
            </p>
        </div>
        <div style="display: flex; align-items: center; gap: 8px;">
            <button type="button" class="ilmuna-btn ilmuna-btn--teal ilmuna-btn--sm" id="btnSyncKaldikTop" title="Sinkronkan Kaldik Sekolah ke Seluruh Rombel &amp; Mapel KBM">
                <iconify-icon icon="solar:refresh-bold" width="16"></iconify-icon>
                <span>Sinkronkan ke Seluruh KBM</span>
            </button>
        </div>
    </div>

    <!-- TABS STRIP (SEGMENTED CONTROL: ANTI-OVERLAP, TIDAK TIMPANG TINDIH, HORIZONTAL SMOOTH) -->
    <div class="curriculum-main-tabs" id="curriculumTabs" role="tablist">
        <button type="button" class="curriculum-tab-btn is-active" data-tab="kaldik">
            <iconify-icon icon="solar:calendar-date-bold"></iconify-icon>
            <span class="curriculum-tab-label">Kalender Pendidikan (Kaldik)</span>
            <span class="curriculum-tab-counter" id="badgeTabKaldik"><?= count($kaldikList) ?></span>
        </button>
        <button type="button" class="curriculum-tab-btn" data-tab="rpe">
            <iconify-icon icon="solar:calendar-mark-bold"></iconify-icon>
            <span class="curriculum-tab-label">Analisis Pekan Efektif (RPE)</span>
            <span class="curriculum-tab-counter" id="badgeTabRpe"><?= $grandPe ?> Pekan</span>
        </button>
        <button type="button" class="curriculum-tab-btn" data-tab="cp">
            <iconify-icon icon="solar:diploma-verified-bold"></iconify-icon>
            <span class="curriculum-tab-label">Standar Capaian (CP &amp; Elemen)</span>
            <span class="curriculum-tab-counter" id="badgeTabCP"><?= count($cpList) ?></span>
        </button>
        <button type="button" class="curriculum-tab-btn" data-tab="struktur">
            <iconify-icon icon="solar:layers-bold"></iconify-icon>
            <span class="curriculum-tab-label">Struktur Kurikulum &amp; Beban JP</span>
            <span class="curriculum-tab-counter" id="badgeTabStruktur"><?= count($strukturMapel) ?></span>
        </button>
    </div>

    <!-- ================================ TAB 1: KALENDER PENDIDIKAN (KALDIK INTERAKTIF) ================================ -->
    <div class="tab-pane is-active" id="tabPaneKaldik">

        <!-- CONTROL TOOLBAR: TAHUN PELAJARAN & 2 SEMESTER (GANJIL / GENAP) - HANYA MUNCUL DI TAB KALDIK -->
        <div id="kaldikControlToolbar" style="background: #FFFFFF; border: 1px solid #E2E8F0; border-radius: 14px; padding: 12px 18px; margin-bottom: 18px; display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 12px;">
            <div style="display: flex; align-items: center; gap: 12px; flex-wrap: wrap;">
                <div style="display: flex; align-items: center; gap: 6px;">
                    <label style="font-size: 12px; font-weight: 700; color: #475569; display: flex; align-items: center; gap: 4px;">
                        <iconify-icon icon="solar:calendar-bold" width="16" style="color: #0D7A87;"></iconify-icon> Tahun:
                    </label>
                    <select id="filterTahun" class="ilmuna-select" style="height: 34px; font-size: 12.5px; border-radius: 8px; padding: 0 10px; border: 1px solid #CBD5E1; background: #F8FAFC; font-weight: 700; color: #0F172A;">
                        <option value="2026/2027" <?= ($selectedTahun === '2026/2027') ? 'selected' : '' ?>>2026/2027</option>
                        <option value="2025/2026" <?= ($selectedTahun === '2025/2026') ? 'selected' : '' ?>>2025/2026</option>
                        <option value="2027/2028" <?= ($selectedTahun === '2027/2028') ? 'selected' : '' ?>>2027/2028</option>
                    </select>
                </div>

                <!-- TOGGLE 2 SEMESTER (BERLAKU 1 TAHUN & DIPISAH 2 SEMESTER) -->
                <div class="ilmuna-sem-pill-group">
                    <a href="?tahun=<?= urlencode($selectedTahun) ?>&semester=Ganjil" data-semester="Ganjil" class="ilmuna-sem-pill-btn <?= ($selectedSem === 'Ganjil') ? 'is-active is-ganjil' : '' ?>" title="Semester Ganjil: Bulan Juli s/d Desember">
                        <iconify-icon icon="solar:calendar-bold" width="15"></iconify-icon>
                        <span>Semester Ganjil (Juli – Des)</span>
                    </a>
                    <a href="?tahun=<?= urlencode($selectedTahun) ?>&semester=Genap" data-semester="Genap" class="ilmuna-sem-pill-btn <?= ($selectedSem === 'Genap') ? 'is-active is-genap' : '' ?>" title="Semester Genap: Bulan Januari s/d Juni">
                        <iconify-icon icon="solar:calendar-bold" width="15"></iconify-icon>
                        <span>Semester Genap (Jan – Jun)</span>
                    </a>
                </div>
            </div>

            <div style="display: flex; align-items: center; gap: 8px; flex-wrap: wrap;">
                <button type="button" class="ilmuna-btn ilmuna-btn--light-outline ilmuna-btn--sm" id="btnAutoLiburAll" style="height: 34px; padding: 0 12px; color: #B91C1C; border-color: #FECACA;">
                    <iconify-icon icon="solar:flag-bold" width="15"></iconify-icon>
                    <span>Tarik Libur Nasional &amp; Kalender Islam NU Online (API)</span>
                </button>
                <button type="button" class="ilmuna-btn ilmuna-btn--sky ilmuna-btn--sm" id="btnOpenBulkGen" style="height: 34px; padding: 0 12px;">
                    <iconify-icon icon="solar:magic-stick-3-bold" width="15"></iconify-icon>
                    <span>Generate 1 Semester</span>
                </button>
                <button type="button" class="ilmuna-btn ilmuna-btn--gold ilmuna-btn--sm" id="btnOpenModalRange" style="height: 34px; padding: 0 14px;">
                    <iconify-icon icon="solar:calendar-add-bold" width="16"></iconify-icon>
                    <span>+ Rentang Tanggal</span>
                </button>
                <button type="button" class="ilmuna-btn ilmuna-btn--sm" id="btnExportKaldikExcel" style="height: 34px; padding: 0 12px; background: #059669; color: #FFFFFF; border: 1px solid #047857; font-weight: 700; border-radius: 8px; display: inline-flex; align-items: center; gap: 6px; cursor: pointer; transition: all 0.15s ease;" title="Ekspor Kalender Pendidikan 1 Tahun &amp; RPE ke Excel (.xlsx)">
                    <iconify-icon icon="solar:file-spreadsheet-bold" width="16"></iconify-icon>
                    <span>Export Excel</span>
                </button>
                <button type="button" class="ilmuna-btn ilmuna-btn--sm" id="btnExportKaldikPdf" style="height: 34px; padding: 0 12px; background: #0D7A87; color: #FFFFFF; border: 1px solid #095C66; font-weight: 700; border-radius: 8px; display: inline-flex; align-items: center; gap: 6px; cursor: pointer; transition: all 0.15s ease;" title="Cetak / Unduh PDF Kalender Pendidikan 1 Tahun &amp; RPE">
                    <iconify-icon icon="solar:printer-bold" width="16"></iconify-icon>
                    <span>Export PDF</span>
                </button>
            </div>
        </div>

        <!-- PALET KUAS STATUS EDIT KALENDER & INSTRUKSI DRAG -->
        <div class="kaldik-painter-card">
            <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 10px; flex-wrap: wrap; gap: 10px;">
                <div style="display: flex; align-items: center; gap: 8px;">
                    <iconify-icon icon="solar:brush-bold" width="18" style="color: #0D7A87;"></iconify-icon>
                    <strong style="font-size: 13.5px; color: #0F172A;">Palet Pewarna Status Kaldik:</strong>
                </div>
                <div style="display: flex; align-items: center; gap: 10px; font-size: 12px; color: #64748B; flex-wrap: wrap;">
                    <button type="button" id="btnTogglePaintMode" class="ilmuna-btn ilmuna-btn--sm" style="height: 30px; font-size: 11.5px; background: #F1F5F9; color: #334155; border: 1px solid #CBD5E1; border-radius: 6px; font-weight: 700; display: inline-flex; align-items: center; gap: 6px;">
                        <iconify-icon icon="solar:brush-bold" width="14" style="color: #64748B;"></iconify-icon>
                        <span id="paintModeLabel">Kuas Cepat: Nonaktif</span>
                    </button>
                    <span>Pilih status lalu <strong>seret (drag) tanggal</strong> atau aktifkan Kuas untuk klik langsung</span>
                </div>
            </div>

            <div style="display: flex; align-items: center; justify-content: space-between; gap: 14px; flex-wrap: wrap;">
                <div class="kaldik-swatch-group" id="swatchGroup">
                    <button type="button" class="kaldik-swatch-btn kaldik-swatch-btn--kbm is-selected" data-status="Efektif KBM" data-default="KBM Reguler">
                        <span style="width: 10px; height: 10px; border-radius: 50%; background: #10B981;"></span>
                        <span>Efektif KBM (Hijau)</span>
                    </button>
                    <button type="button" class="kaldik-swatch-btn kaldik-swatch-btn--libur" data-status="Libur Nasional" data-default="Libur Nasional">
                        <span style="width: 10px; height: 10px; border-radius: 50%; background: #EF4444;"></span>
                        <span>Libur Nasional (Merah)</span>
                    </button>
                    <button type="button" class="kaldik-swatch-btn kaldik-swatch-btn--sem" data-status="Libur Semester" data-default="Libur Semester">
                        <span style="width: 10px; height: 10px; border-radius: 50%; background: #64748B;"></span>
                        <span>Libur Semester / Pekan (Abu-abu)</span>
                    </button>
                    <button type="button" class="kaldik-swatch-btn kaldik-swatch-btn--kegiatan" data-status="Kegiatan Sekolah/Ujian" data-default="Asesmen / Ujian Sekolah">
                        <span style="width: 10px; height: 10px; border-radius: 50%; background: #F59E0B;"></span>
                        <span>Ujian &amp; Kegiatan (Emas)</span>
                    </button>
                    <button type="button" class="kaldik-swatch-btn kaldik-swatch-btn--keagamaan" data-status="Libur Khusus Keagamaan" data-default="Awal Puasa Ramadhan / Hari Raya">
                        <span style="width: 10px; height: 10px; border-radius: 50%; background: #8B5CF6;"></span>
                        <span>Keagamaan &amp; Ramadhan (Ungu)</span>
                    </button>
                    <button type="button" class="kaldik-swatch-btn kaldik-swatch-btn--khusus" data-status="Kegiatan Sekolah/Ujian" data-default="Class Meeting / P5">
                        <span style="width: 10px; height: 10px; border-radius: 50%; background: #0284C7;"></span>
                        <span>Class Meeting &amp; P5 (Biru)</span>
                    </button>
                </div>

                <!-- Input Keterangan Agenda Aktif -->
                <div style="display: flex; align-items: center; gap: 8px; flex: 1; min-width: 260px;">
                    <label style="font-size: 12px; font-weight: 700; color: #475569; white-space: nowrap;">Uraian Agenda:</label>
                    <input type="text" id="activeAgendaText" class="ilmuna-input" value="KBM Reguler" style="height: 34px; font-size: 12px;" placeholder="Misal: KBM Reguler, MPLS, Asesmen Tengah Semester, Libur Idul Fitri">
                </div>
            </div>
        </div>

        <!-- STATS BARIS CEPAT & VIEW SWITCHER -->
        <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 14px; flex-wrap: wrap; gap: 10px;">
            <div style="display: flex; align-items: center; gap: 14px; font-size: 12.5px; font-weight: 700; flex-wrap: wrap;">
                <span style="color: #047857; display: flex; align-items: center; gap: 5px;">
                    <span style="width: 10px; height: 10px; border-radius: 50%; background: #10B981;"></span>
                    Efektif KBM: <span id="statCountHE"><?= $countHE ?></span> Hari
                </span>
                <span style="color: #B91C1C; display: flex; align-items: center; gap: 5px;">
                    <span style="width: 10px; height: 10px; border-radius: 50%; background: #EF4444;"></span>
                    Libur Nasional: <span id="statCountLiburNas"><?= $countLiburNas ?></span> Hari
                </span>
                <span style="color: #475569; display: flex; align-items: center; gap: 5px;">
                    <span style="width: 10px; height: 10px; border-radius: 50%; background: #64748B;"></span>
                    Libur Semester: <span id="statCountLiburSem"><?= $countLiburSem ?></span> Hari
                </span>
                <span style="color: #B45309; display: flex; align-items: center; gap: 5px;">
                    <span style="width: 10px; height: 10px; border-radius: 50%; background: #F59E0B;"></span>
                    Ujian / Kegiatan: <span id="statCountKegiatan"><?= $countKegiatan ?></span> Hari
                </span>
                <span style="color: #7E22CE; display: flex; align-items: center; gap: 5px;">
                    <span style="width: 10px; height: 10px; border-radius: 50%; background: #8B5CF6;"></span>
                    Keagamaan: <span id="statCountKeagamaan"><?= $countLiburAgama ?></span> Hari
                </span>
            </div>

        </div>

        <!-- =========================================================================
         * REACT-BIG-CALENDAR UNIFIED VIEW CONTAINER (BULAN / MINGGU / HARI / AGENDA)
         * ========================================================================= -->
        <div id="kaldikInteractiveView">
            <div class="rbc-card-wrapper">
                <!-- TOP RBC TOOLBAR (PERSIS SEPERTI REACT CalendarToolbar) -->
                <div class="rbc-toolbar-box">
                    <div class="rbc-toolbar-left">
                        <div class="rbc-toolbar-icon-box">
                            <iconify-icon icon="solar:calendar-bold"></iconify-icon>
                        </div>
                        <div class="rbc-toolbar-title-wrap">
                            <div class="rbc-toolbar-title" id="displayActiveMonthTitle">
                                <?= $activeMonths[$defaultActiveMonth] ?> <?= $calYear ?>
                            </div>
                            <div class="rbc-toolbar-hijri" id="displayActiveHijriSpan">
                                <?php
                                $dFirst = sprintf('%04d-%02d-01', $calYear, $defaultActiveMonth);
                                $daysInDef = cal_days_in_month(CAL_GREGORIAN, $defaultActiveMonth, $calYear);
                                $dLast  = sprintf('%04d-%02d-%02d', $calYear, $defaultActiveMonth, $daysInDef);
                                echo "🌙 " . getTanggalHijriyah($dFirst, 'd MMM') . " – " . getTanggalHijriyah($dLast, 'd MMM y');
                                ?>
                            </div>
                        </div>
                    </div>

                    <!-- Navigation controls: Prev, Today, Next -->
                    <div class="rbc-nav-group">
                        <button type="button" class="rbc-nav-btn" id="btnRbcPrev" title="Sebelumnya">
                            <iconify-icon icon="solar:arrow-left-bold" width="15"></iconify-icon>
                        </button>
                        <button type="button" class="rbc-nav-btn" id="btnRbcToday" title="Lompat ke Hari Ini">
                            <iconify-icon icon="solar:calendar-bold" width="14"></iconify-icon>
                            <span>Hari Ini</span>
                        </button>
                        <button type="button" class="rbc-nav-btn" id="btnRbcNext" title="Selanjutnya">
                            <iconify-icon icon="solar:arrow-right-bold" width="15"></iconify-icon>
                        </button>
                    </div>

                    <!-- View switchers: Bulan, Minggu, Hari, Agenda -->
                    <div class="rbc-view-group" id="rbcViewSwitchers">
                        <button type="button" class="rbc-view-btn is-active" data-view="month">
                            <iconify-icon icon="solar:calendar-minimalistic-bold" width="15"></iconify-icon>
                            <span>Bulan</span>
                        </button>
                        <button type="button" class="rbc-view-btn" data-view="week">
                            <iconify-icon icon="solar:calendar-date-bold" width="15"></iconify-icon>
                            <span>Minggu</span>
                        </button>
                        <button type="button" class="rbc-view-btn" data-view="day">
                            <iconify-icon icon="solar:clock-circle-bold" width="15"></iconify-icon>
                            <span>Hari</span>
                        </button>
                        <button type="button" class="rbc-view-btn" data-view="agenda">
                            <iconify-icon icon="solar:list-bold" width="15"></iconify-icon>
                            <span>Agenda</span>
                        </button>
                    </div>
                </div>

                <!-- QUICK MONTH PILLS (6 BULAN PER SEMESTER) -->
                <div class="kaldik-month-pills-bar" id="rbcMonthPillsBar">
                    <?= $semData['pillsHtml'] ?>
                </div>

                <!-- VIEW 1: BULAN (MONTH VIEW GRID) -->
                <div id="rbcViewMonth" class="rbc-view-pane" style="display: block;">
                    <?= $semData['panesHtml'] ?>
                </div>

                <!-- VIEW 2: MINGGU (WEEK VIEW GRID - 7 COLUMNS) -->
                <div id="rbcViewWeek" class="rbc-view-pane" style="display: none;">
                    <div class="rbc-week-grid" id="rbcWeekGridContent"></div>
                </div>

                <!-- VIEW 3: HARI (DAY HERO VIEW) -->
                <div id="rbcViewDay" class="rbc-view-pane" style="display: none;">
                    <div class="rbc-day-hero-wrap" id="rbcDayHeroContent"></div>
                </div>

                <!-- VIEW 4: AGENDA (AGENDA TABLE VIEW) -->
                <div id="rbcViewAgenda" class="rbc-view-pane" style="display: none;">
                    <div class="ilmuna-table-card" style="border: none; border-radius: 0; box-shadow: none;">
                        <div style="padding: 14px 20px; border-bottom: 1px solid #E2E8F0; display: flex; justify-content: space-between; align-items: center; background: #F8FAFC;">
                            <div style="display: flex; align-items: center; gap: 8px;">
                                <iconify-icon icon="solar:calendar-date-bold" width="20" style="color: #0D7A87;"></iconify-icon>
                                <strong style="font-size: 14px; color: #1E293B;" id="agendaKaldikSemTitle">Rincian Agenda Kaldik Semester <?= htmlspecialchars($selectedSem) ?></strong>
                                <span class="ilmuna-badge ilmuna-badge--teal" style="font-size: 11px;" id="agendaTotalCounter"><?= count($kaldikList) ?> Tanggal</span>
                            </div>
                            <div style="width: 240px;">
                                <input type="text" id="agendaSearchInput" class="ilmuna-input" style="height: 32px; font-size: 12px;" placeholder="Cari tanggal atau agenda...">
                            </div>
                        </div>

                        <div class="ilmuna-table-wrap" style="overflow-x: auto;">
                            <table class="ilmuna-table" id="tableKaldikAgenda">
                                <thead>
                                    <tr>
                                        <th style="width: 50px; text-align: center;">No</th>
                                        <th style="width: 140px;">Tanggal Masehi</th>
                                        <th style="width: 150px;">Tanggal Hijriyah</th>
                                        <th style="width: 90px;">Hari</th>
                                        <th style="width: 170px;">Status Hari</th>
                                        <th>Uraian / Keterangan Agenda Satuan Pendidikan</th>
                                        <th style="width: 90px; text-align: center;">Aksi</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($kaldikList)): ?>
                                        <tr>
                                            <td colspan="7" style="text-align: center; padding: 36px 20px; color: #64748B;">
                                                Belum ada data kalender pendidikan. Klik "Generate 1 Semester" di atas.
                                            </td>
                                        </tr>
                                    <?php else: ?>
                                        <?php $no = 1; foreach ($kaldikList as $kh): ?>
                                            <?php
                                            $hijri = getTanggalHijriyah($kh['tanggal'], 'full');
                                            $st = $kh['status_hari'];
                                            $badgeSt = 'badge-status--kbm';
                                            if ($st === 'Libur Nasional') $badgeSt = 'badge-status--libur';
                                            elseif ($st === 'Libur Semester') $badgeSt = 'badge-status--libur';
                                            elseif ($st === 'Kegiatan Sekolah/Ujian') $badgeSt = 'badge-status--kegiatan';
                                            elseif ($st === 'Libur Khusus Keagamaan') $badgeSt = 'badge-status--keagamaan';
                                            ?>
                                            <tr data-date="<?= $kh['tanggal'] ?>" data-ket="<?= htmlspecialchars(strtolower($kh['keterangan'] ?? '')) ?>">
                                                <td style="text-align: center; font-weight: 700; color: #64748B;"><?= $no++ ?></td>
                                                <td><strong style="color: #1E293B; font-family: monospace; font-size: 13px;"><?= date('d M Y', strtotime($kh['tanggal'])) ?></strong></td>
                                                <td><span class="kaldik-hijri-num" style="font-size: 11px;"><?= htmlspecialchars($hijri) ?></span></td>
                                                <td><span class="ilmuna-badge ilmuna-badge--teal" style="font-size: 11px;"><?= htmlspecialchars($kh['hari']) ?></span></td>
                                                <td><span class="badge-status <?= $badgeSt ?>"><?= htmlspecialchars($kh['status_hari']) ?></span></td>
                                                <td><strong style="color: #334155; font-size: 13px;"><?= htmlspecialchars($kh['keterangan'] ?: '-') ?></strong></td>
                                                <td style="text-align: center;">
                                                    <div style="display: flex; align-items: center; justify-content: center; gap: 4px;">
                                                        <button type="button" class="ilmuna-btn-icon btn-agenda-edit" data-date="<?= $kh['tanggal'] ?>" data-id="<?= $kh['id'] ?>" title="Edit Agenda">
                                                            <iconify-icon icon="solar:pen-bold" width="15" style="color: #0D7A87;"></iconify-icon>
                                                        </button>
                                                        <button type="button" class="ilmuna-btn-icon btn-delete-single" data-id="<?= $kh['id'] ?>" title="Hapus">
                                                            <iconify-icon icon="solar:trash-bin-trash-bold" width="15" style="color: #EF4444;"></iconify-icon>
                                                        </button>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

            </div>
        </div>

    </div>

    <!-- ================================ TAB 2: ANALISIS PEKAN EFEKTIF (RPE SEKOLAH) ================================ -->
    <div class="tab-pane" id="tabPaneRpe">
        <div class="ilmuna-table-card" style="background: #FFFFFF; border: 1px solid #E2E8F0; border-radius: 14px; overflow: hidden; box-shadow: 0 4px 14px rgba(15, 23, 42, 0.04);">
            <div style="padding: 14px 20px; border-bottom: 1px solid #E2E8F0; display: flex; justify-content: space-between; align-items: center; background: #F8FAFC;">
                <div style="display: flex; align-items: center; gap: 8px;">
                    <iconify-icon icon="solar:calendar-mark-bold" width="20" style="color: #0284C7;"></iconify-icon>
                    <strong style="font-size: 14px; color: #1E293B;">Rincian Pekan Efektif (RPE) Tingkat Satuan Pendidikan</strong>
                    <span class="ilmuna-badge ilmuna-badge--sky" id="badgeRpeSemesterTitle" style="font-size: 11px;">Semester <?= htmlspecialchars($selectedSem) ?> <?= htmlspecialchars($selectedTahun) ?></span>
                </div>
                <div style="font-size: 12px; color: #64748B;">
                    * Otomatis terakumulasi realtime dari Kalender Pendidikan (Kaldik)
                </div>
            </div>

            <div class="ilmuna-table-wrap" style="overflow-x: auto;">
                <table class="ilmuna-table" id="tableRpe">
                    <thead>
                        <tr>
                            <th rowspan="2" style="width: 45px; text-align: center; vertical-align: middle;">No</th>
                            <th rowspan="2" style="width: 130px; vertical-align: middle;">Bulan</th>
                            <th colspan="6" style="text-align: center; border-bottom: 1px solid #E2E8F0; background: #F1F5F9;">Rincian Hari Efektif KBM</th>
                            <th rowspan="2" style="width: 110px; text-align: center; vertical-align: middle; background: #ECFDF5; color: #047857;">Total HE</th>
                            <th rowspan="2" style="width: 90px; text-align: center; vertical-align: middle;">Jml Pekan</th>
                            <th rowspan="2" style="width: 100px; text-align: center; vertical-align: middle; background: #F0F9FF; color: #0284C7;">Pekan Efektif</th>
                            <th rowspan="2" style="width: 100px; text-align: center; vertical-align: middle; color: #B91C1C;">Pekan Non-Efektif</th>
                            <th rowspan="2" style="vertical-align: middle;">Uraian Kegiatan / Alasan Non-Efektif</th>
                        </tr>
                        <tr style="font-size: 11px;">
                            <th style="width: 50px; text-align: center;">Sen</th>
                            <th style="width: 50px; text-align: center;">Sel</th>
                            <th style="width: 50px; text-align: center;">Rab</th>
                            <th style="width: 50px; text-align: center;">Kam</th>
                            <th style="width: 50px; text-align: center;">Jum</th>
                            <th style="width: 50px; text-align: center;">Sab</th>
                        </tr>
                    </thead>
                    <tbody id="rpeTableBody">
                        <?= $semData['rpeRowsHtml'] ?>
                    </tbody>
                    <tfoot>
                        <tr style="background: #F8FAFC; font-weight: 800; border-top: 2px solid #CBD5E1;">
                            <td colspan="2" style="text-align: center; color: #1E293B;">JUMLAH TOTAL</td>
                            <td id="rpeTotalSen" style="text-align: center;"><?= $totSen ?></td>
                            <td id="rpeTotalSel" style="text-align: center;"><?= $totSel ?></td>
                            <td id="rpeTotalRab" style="text-align: center;"><?= $totRab ?></td>
                            <td id="rpeTotalKam" style="text-align: center;"><?= $totKam ?></td>
                            <td id="rpeTotalJum" style="text-align: center;"><?= $totJum ?></td>
                            <td id="rpeTotalSab" style="text-align: center;"><?= $totSab ?></td>
                            <td id="rpeTotalHE" style="text-align: center; background: #DCFCE7; color: #14532D; font-size: 14px;"><?= $grandHe ?> Hari</td>
                            <td id="rpeTotalPekan" style="text-align: center;"><?= $grandPekanTotal ?></td>
                            <td id="rpeTotalPE" style="text-align: center; background: #E0F2FE; color: #0369A1; font-size: 14px;"><?= $grandPe ?> Pekan</td>
                            <td id="rpeTotalPTE" style="text-align: center; color: #B91C1C;"><?= $grandPte ?></td>
                            <td id="rpeFooterSemTitle" style="font-size: 12px; color: #0D7A87;">Rekapitulasi Kaldik Semester <?= htmlspecialchars($selectedSem) ?></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    </div>

    <!-- ================================ TAB 3: STANDAR CP & ELEMEN ================================ -->
    <div class="tab-pane" id="tabPaneCP">
        <div style="background: #FFFBEB; border: 1px solid #FCD34D; border-radius: 12px; padding: 14px 18px; margin-bottom: 16px; display: flex; align-items: flex-start; gap: 12px;">
            <iconify-icon icon="solar:info-circle-bold" width="22" style="color: #D97706; flex-shrink: 0; margin-top: 2px;"></iconify-icon>
            <div style="font-size: 12.5px; color: #92400E; line-height: 1.5;">
                <strong>Standar Capaian Pembelajaran Kurikulum Merdeka (Keputusan BSKAP Kemendikbudristek):</strong><br>
                Administrator menetapkan <strong>Elemen Kurikulum</strong> dan <strong>Dokumen Capaian Pembelajaran (CP)</strong> sebagai acuan resmi sekolah.
                Adapun <em>Tujuan Pembelajaran (TP)</em>, <em>Alur Tujuan Pembelajaran (ATP)</em>, dan <em>Indikator (ITP / IKTP)</em> diturunkan serta dikembangkan secara mandiri oleh <strong>Guru Mata Pelajaran</strong> pada modul guru.
            </div>
        </div>

        <div class="ilmuna-table-card" style="background: #FFFFFF; border: 1px solid #E2E8F0; border-radius: 14px; overflow: hidden; box-shadow: 0 4px 14px rgba(15, 23, 42, 0.04);">
            <div style="padding: 14px 20px; border-bottom: 1px solid #E2E8F0; display: flex; justify-content: space-between; align-items: center; background: #F8FAFC; flex-wrap: wrap; gap: 10px;">
                <div style="display: flex; align-items: center; gap: 8px;">
                    <iconify-icon icon="solar:diploma-verified-bold" width="20" style="color: #D97706;"></iconify-icon>
                    <strong style="font-size: 14px; color: #1E293B;">Master Dokumen Capaian Pembelajaran (CP)</strong>
                    <span class="ilmuna-tab-counter" id="badgeTabCP" style="background:#FEF3C7; color:#92400E;"><?= count($cpList) ?> Capaian</span>
                </div>
                <div style="display: flex; gap: 8px; flex-wrap: wrap;">
                    <button type="button" class="ilmuna-btn ilmuna-btn--teal ilmuna-btn--sm" id="btnTambahElemenTop" style="display: inline-flex; align-items: center; gap: 6px;">
                        <iconify-icon icon="solar:add-circle-bold" width="15"></iconify-icon>
                        <span>+ Tambah Elemen</span>
                    </button>
                    <button type="button" class="ilmuna-btn ilmuna-btn--light-outline ilmuna-btn--sm" id="btnKelolaElemen" style="display: inline-flex; align-items: center; gap: 6px;">
                        <iconify-icon icon="solar:layers-bold" width="15"></iconify-icon>
                        <span>Kelola Elemen</span>
                    </button>
                    <button type="button" class="ilmuna-btn ilmuna-btn--gold ilmuna-btn--sm" id="btnTambahCPInline" style="display: inline-flex; align-items: center; gap: 6px;">
                        <iconify-icon icon="solar:diploma-verified-bold" width="15"></iconify-icon>
                        <span>+ Capaian (CP) Baru</span>
                    </button>
                </div>
            </div>

            <!-- BULK ACTIONS TOOLBAR UNTUK CP -->
            <div id="cpBulkBar" style="display: none; background: #0F172A; color: #FFFFFF; padding: 10px 18px; border-radius: 10px; margin: 14px 20px 0; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap; box-shadow: 0 4px 14px rgba(15, 23, 42, 0.15);">
                <div style="display: flex; align-items: center; gap: 10px;">
                    <span id="cpBulkCountBadge" style="background: #D97706; color: #FFF; font-weight: 800; padding: 3px 10px; border-radius: 8px; font-size: 12px;">0 CP Dipilih</span>
                    <span style="font-size: 13px; font-weight: 600; color: #F1F5F9;">Pilih aksi untuk seluruh data CP yang dicentang:</span>
                </div>
                <div style="display: flex; align-items: center; gap: 8px; flex-wrap: wrap;">
                    <button type="button" class="ilmuna-btn ilmuna-btn--sm" id="btnBulkDeleteCP" style="background: #EF4444; color: #FFF; border: none; padding: 6px 14px; font-weight: 700; border-radius: 8px; display: inline-flex; align-items: center; gap: 6px; cursor: pointer; transition: all 0.15s ease;">
                        <iconify-icon icon="solar:trash-bin-trash-bold" width="15"></iconify-icon>
                        <span>Hapus Terpilih</span>
                    </button>
                    <button type="button" class="ilmuna-btn ilmuna-btn--sm" id="btnBulkDeselectCP" style="background: rgba(255,255,255,0.15); color: #FFF; border: none; padding: 6px 12px; font-weight: 600; border-radius: 8px; cursor: pointer;">
                        Batalkan Pilihan
                    </button>
                </div>
            </div>

            <div class="ilmuna-table-wrap" style="overflow-x: auto;">
                <table class="ilmuna-table" id="tableCP">
                    <thead>
                        <tr>
                            <th style="width: 44px; text-align: center;">
                                <input type="checkbox" id="checkAllCP" title="Pilih Semua CP" style="cursor: pointer; width: 16px; height: 16px; accent-color: #0D7A87;">
                            </th>
                            <th style="width: 50px; text-align: center;">No</th>
                            <th style="width: 220px;">Elemen &amp; Fase</th>
                            <th>Uraian Naskah Capaian Pembelajaran (CP Standar BSKAP)</th>
                            <th style="width: 220px;">Mata Pelajaran &amp; Rombel</th>
                            <th style="width: 100px; text-align: center;">Aksi</th>
                        </tr>
                    </thead>
                    <tbody id="tbodyCP">
                        <?php if (empty($cpList)): ?>
                            <tr>
                                <td colspan="6" style="text-align: center; padding: 36px 20px; color: #64748B;">
                                    Belum ada Capaian Pembelajaran (CP). Tambahkan Elemen lalu input CP.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php $noC = 1; foreach ($cpList as $c): ?>
                                <tr class="row-cp" data-id="<?= $c['id'] ?>">
                                    <td style="text-align: center;">
                                        <input type="checkbox" class="check-cp-item" value="<?= $c['id'] ?>" style="cursor: pointer; width: 16px; height: 16px; accent-color: #0D7A87;">
                                    </td>
                                    <td style="text-align: center; font-weight: 700; color: #64748B;"><?= $noC++ ?></td>
                                    <td>
                                        <div style="font-weight: 700; color: #0D7A87; font-size: 13.5px;"><?= htmlspecialchars($c['nama_elemen'] ?: 'Umum / Terpadu') ?></div>
                                        <div style="margin-top: 4px;">
                                            <span class="ilmuna-tab-counter" style="background:#CCFBF1; color:#0F766E; font-size:10px;"><?= htmlspecialchars($c['fase'] ?: 'Fase E/F') ?></span>
                                        </div>
                                    </td>
                                    <td><div style="color: #1E293B; font-size: 13px; line-height: 1.55;"><?= nl2br(htmlspecialchars($c['cp'])) ?></div></td>
                                    <td>
                                        <div style="font-weight: 700; color: #334155; font-size: 12.5px;"><?= htmlspecialchars($c['nama_mata_pelajaran']) ?></div>
                                        <div style="color: #94A3B8; font-size: 11.5px; margin-top: 2px;"><?= htmlspecialchars($c['nama_kelas']) ?></div>
                                    </td>
                                    <td style="text-align: center;">
                                        <div style="display: inline-flex; gap: 4px;">
                                            <button type="button" class="ilmuna-btn-icon btn-edit-cp"
                                                    data-id="<?= $c['id'] ?>"
                                                    data-kmp="<?= $c['id_kelas_mata_pelajaran'] ?>"
                                                    data-elemen="<?= $c['id_elemen'] ?>"
                                                    data-cp="<?= htmlspecialchars($c['cp']) ?>"
                                                    title="Edit CP">
                                                <iconify-icon icon="solar:pen-new-square-bold" width="16" style="color: #0D7A87;"></iconify-icon>
                                            </button>
                                            <button type="button" class="ilmuna-btn-icon btn-delete-cp" data-id="<?= $c['id'] ?>" title="Hapus CP">
                                                <iconify-icon icon="solar:trash-bin-trash-bold" width="16" style="color: #EF4444;"></iconify-icon>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- ================================ TAB 4: STRUKTUR KURIKULUM & BEBAN BELAJAR ================================ -->
    <div class="tab-pane" id="tabPaneStruktur">
        <div style="background: linear-gradient(135deg, rgba(13, 122, 135, 0.08) 0%, rgba(245, 158, 11, 0.08) 100%); border: 1px solid rgba(13, 122, 135, 0.2); border-radius: 14px; padding: 18px 22px; margin-bottom: 20px;">
            <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 8px;">
                <iconify-icon icon="solar:shield-check-bold" width="22" style="color: #0D7A87;"></iconify-icon>
                <strong style="font-size: 14.5px; color: #0F172A;">Struktur Tata Kelola Kurikulum Merdeka</strong>
            </div>
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 14px; font-size: 12.5px; line-height: 1.5; color: #334155; margin-top: 10px;">
                <div style="background: #FFFFFF; border-radius: 10px; padding: 14px; border-left: 4px solid #4338CA;">
                    <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 6px;">
                        <strong style="color: #4338CA;">Wewenang Administrator</strong>
                        <span style="background: #EEF2FF; color: #4338CA; font-weight: 800; font-size: 10px; padding: 2px 6px; border-radius: 4px;">Admin</span>
                    </div>
                    <ul style="margin: 0; padding-left: 18px; color: #475569;">
                        <li>Menetapkan <strong>Kalender Pendidikan Sekolah (Kaldik)</strong>.</li>
                        <li>Merekapitulasi <strong>Pekan Efektif Sekolah (RPE)</strong>.</li>
                        <li>Menyimpan dokumen <strong>Standar CP &amp; Elemen</strong> BSKAP.</li>
                        <li>Memetakan <strong>Struktur Rombel &amp; Guru Pengampu</strong>.</li>
                    </ul>
                </div>
                <div style="background: #FFFFFF; border-radius: 10px; padding: 14px; border-left: 4px solid #15803D;">
                    <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 6px;">
                        <strong style="color: #15803D;">Wewenang Guru Pengampu</strong>
                        <span style="background: #F0FDF4; color: #15803D; font-weight: 800; font-size: 10px; padding: 2px 6px; border-radius: 4px;">Guru</span>
                    </div>
                    <ul style="margin: 0; padding-left: 18px; color: #475569;">
                        <li>Menurunkan CP menjadi <strong>Tujuan Pembelajaran (TP)</strong>.</li>
                        <li>Menyusun urutan <strong>Alur Tujuan (ATP)</strong> &amp; Indikator (ITP).</li>
                        <li>Menghitung Alokasi Mengajar <strong>Jam Tatap Muka (JTM)</strong>.</li>
                        <li>Menyusun <strong>PROTA, PROSEM</strong>, &amp; <strong>Modul Ajar Digital</strong>.</li>
                    </ul>
                </div>
            </div>
        </div>

        <div class="ilmuna-table-card" style="background: #FFFFFF; border: 1px solid #E2E8F0; border-radius: 14px; overflow: hidden; box-shadow: 0 4px 14px rgba(15, 23, 42, 0.04);">
            <div style="padding: 14px 20px; border-bottom: 1px solid #E2E8F0; display: flex; justify-content: space-between; align-items: center; background: #F8FAFC;">
                <div style="display: flex; align-items: center; gap: 8px;">
                    <iconify-icon icon="solar:layers-bold" width="20" style="color: #0D7A87;"></iconify-icon>
                    <strong style="font-size: 14px; color: #1E293B;">Pemetaan Struktur Kurikulum &amp; Alokasi Beban Mapel</strong>
                    <span class="ilmuna-tab-counter" style="background:#CCFBF1; color:#0F766E;"><?= count($strukturMapel) ?> Rombel Mapel</span>
                </div>
                <a href="<?= BASE_URL ?>/dashboard/admin/subjects" class="ilmuna-btn ilmuna-btn--light-outline ilmuna-btn--sm">
                    <iconify-icon icon="solar:book-2-bold" width="14"></iconify-icon> Kelola Mata Pelajaran
                </a>
            </div>

            <div class="ilmuna-table-wrap" style="overflow-x: auto;">
                <table class="ilmuna-table">
                    <thead>
                        <tr>
                            <th style="width: 50px; text-align: center;">No</th>
                            <th style="width: 130px;">Kode Mapel</th>
                            <th>Nama Mata Pelajaran</th>
                            <th style="width: 140px;">Kelas / Rombel</th>
                            <th style="width: 220px;">Guru Pengampu</th>
                            <th style="width: 120px; text-align: center;">Elemen BSKAP</th>
                            <th style="width: 120px; text-align: center;">Capaian (CP)</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($strukturMapel)): ?>
                            <tr>
                                <td colspan="7" style="text-align: center; padding: 36px 20px; color: #64748B;">
                                    Belum ada data rombel dan mata pelajaran terdaftar.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php $noS = 1; foreach ($strukturMapel as $sm): ?>
                                <tr>
                                    <td style="text-align: center; font-weight: 700; color: #64748B;"><?= $noS++ ?></td>
                                    <td><span class="ilmuna-tab-counter" style="font-family: monospace; font-size: 11px;"><?= htmlspecialchars($sm['kode_mapel'] ?: 'MAPEL-' . $sm['id_kmp']) ?></span></td>
                                    <td><strong style="color: #1E293B; font-size: 13px;"><?= htmlspecialchars($sm['nama_mata_pelajaran']) ?></strong></td>
                                    <td><span style="font-weight: 700; color: #334155;"><?= htmlspecialchars($sm['nama_kelas']) ?></span></td>
                                    <td>
                                        <div style="display: flex; align-items: center; gap: 6px;">
                                            <iconify-icon icon="solar:user-bold" width="14" style="color: #64748B;"></iconify-icon>
                                            <span style="font-size: 12.5px; color: #0F172A; font-weight: 600;"><?= htmlspecialchars($sm['nama_guru']) ?></span>
                                        </div>
                                    </td>
                                    <td style="text-align: center;">
                                        <span class="ilmuna-tab-counter" style="background:#DCFCE7; color:#15803D;">
                                            <?= $sm['total_elemen'] ?> Elemen
                                        </span>
                                    </td>
                                    <td style="text-align: center;">
                                        <span class="ilmuna-tab-counter" style="background:#E0F2FE; color:#0369A1;">
                                            <?= $sm['total_cp'] ?> CP Standar
                                        </span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

</div>

<!-- ==============================================================================
 * FLOATING DRAG CONFIRMATION BAR (MUNCUL SAAT USER SELESAI MENYERET TANGGAL)
 * ============================================================================== -->
<div class="kaldik-drag-floating-bar" id="dragFloatingBar">
    <div style="display: flex; align-items: center; gap: 8px;">
        <span id="dragFloatingCountBadge" style="background: #0284C7; color: #FFF; font-weight: 800; padding: 2px 10px; border-radius: 8px; font-size: 12.5px;">0</span>
        <span id="dragFloatingMsg" style="font-weight: 700;">Tanggal Diseret</span>
    </div>

    <!-- Quick Status Palette on Floating Bar -->
    <div style="display: flex; align-items: center; gap: 6px; flex-wrap: wrap;">
        <button type="button" class="btn-drag-quick-status" data-status="Efektif KBM" data-default="KBM Reguler" style="background: #10B981; color: #FFF; border: none; padding: 5px 10px; border-radius: 6px; font-size: 12px; font-weight: 700; cursor: pointer;">
            🟢 KBM
        </button>
        <button type="button" class="btn-drag-quick-status" data-status="Libur Nasional" data-default="Libur Nasional" style="background: #EF4444; color: #FFF; border: none; padding: 5px 10px; border-radius: 6px; font-size: 12px; font-weight: 700; cursor: pointer;">
            🔴 Libur
        </button>
        <button type="button" class="btn-drag-quick-status" data-status="Libur Semester" data-default="Libur Akhir Pekan / Semester" style="background: #64748B; color: #FFF; border: none; padding: 5px 10px; border-radius: 6px; font-size: 12px; font-weight: 700; cursor: pointer;">
            🔘 Libur Sem
        </button>
        <button type="button" class="btn-drag-quick-status" data-status="Kegiatan Sekolah/Ujian" data-default="Asesmen / Ujian Sekolah" style="background: #F59E0B; color: #FFF; border: none; padding: 5px 10px; border-radius: 6px; font-size: 12px; font-weight: 700; cursor: pointer;">
            🟡 Ujian
        </button>
        <button type="button" class="btn-drag-quick-status" data-status="Libur Khusus Keagamaan" data-default="Awal Puasa Ramadhan / Libur Keagamaan" style="background: #8B5CF6; color: #FFF; border: none; padding: 5px 10px; border-radius: 6px; font-size: 12px; font-weight: 700; cursor: pointer;">
            🟣 Keagamaan
        </button>
        <button type="button" class="btn-drag-quick-status" data-status="DELETE" data-default="" style="background: #475569; color: #F1F5F9; border: none; padding: 5px 10px; border-radius: 6px; font-size: 12px; font-weight: 700; cursor: pointer;" title="Reset / Hapus tanggal dari Kaldik">
            🗑️ Reset
        </button>
    </div>

    <div style="display: flex; align-items: center; gap: 8px;">
        <input type="text" id="dragFloatingKetInput" placeholder="Keterangan agenda..." style="height: 30px; font-size: 12px; padding: 0 10px; border-radius: 6px; border: 1px solid #475569; background: #1E293B; color: #FFFFFF; width: 170px;">
        <button type="button" class="ilmuna-btn ilmuna-btn--sm" id="btnApplyDragged" style="background: #0D7A87; color: #FFF; border: none; padding: 6px 14px; font-weight: 700; border-radius: 8px;">
            <iconify-icon icon="solar:check-circle-bold" width="14"></iconify-icon> Terapkan
        </button>
        <button type="button" class="ilmuna-btn ilmuna-btn--sm" id="btnCancelDragged" style="background: rgba(255,255,255,0.15); color: #FFF; border: none; padding: 6px 10px; border-radius: 8px;">
            Batal
        </button>
    </div>
</div>

<!-- ==============================================================================
 * MODAL DETAIL & TAMBAH AGENDA KALDIK (PERSIS REACT EventModal)
 * ============================================================================== -->
<div class="ilmuna-access-modal" id="modalEventKaldik">
    <div class="ilmuna-access-modal__overlay" data-close="1"></div>
    <div class="ilmuna-access-modal__box" style="max-width: 580px; border-radius: 20px;">
        <div class="ilmuna-access-modal__head" style="border-bottom: 1px solid #E2E8F0; padding: 18px 24px;">
            <div style="display: flex; align-items: center; gap: 12px;">
                <div style="width: 38px; height: 38px; border-radius: 10px; background: rgba(13, 122, 135, 0.1); color: #0D7A87; display: flex; align-items: center; justify-content: center; font-size: 20px;">
                    <iconify-icon icon="solar:calendar-mark-bold"></iconify-icon>
                </div>
                <div>
                    <h3 style="margin: 0; font-size: 17px; font-weight: 800; color: #0F172A;" id="modalEventTitle">Detail Agenda Kaldik</h3>
                    <div style="font-size: 12px; color: #64748B; margin-top: 2px;" id="modalEventSubtitle">Atur agenda satuan pendidikan pada kalender</div>
                </div>
            </div>
            <button type="button" class="ilmuna-access-modal__close" data-close="1">&times;</button>
        </div>
        <form id="formEventKaldik" class="ilmuna-access-modal__body" style="padding: 20px 24px;" onsubmit="return false;">
            <input type="hidden" id="eventKaldikId" value="0">
            <input type="hidden" id="eventKaldikStatus" value="Efektif KBM">

            <!-- Rentang Tanggal (Start & End) -->
            <div class="ilmuna-form-row" style="margin-bottom: 8px;">
                <div class="ilmuna-form-field" style="margin-bottom: 0;">
                    <label for="eventStartDate" style="font-size: 12px; font-weight: 700; color: #334155; display: flex; align-items: center; justify-content: space-between; margin-bottom: 6px;">
                        <span>Tanggal Mulai</span>
                        <span id="startDayNameBadge" style="font-size: 11px; font-weight: 800; color: #0D7A87; background: #EEF2FF; padding: 2px 8px; border-radius: 6px;">-</span>
                    </label>
                    <div style="position: relative; display: flex; align-items: center;">
                        <input type="date" id="eventStartDate" name="eventStartDate" class="ilmuna-input" required style="height: 42px; border-radius: 10px; cursor: pointer; padding: 8px 12px; font-weight: 700; font-size: 13px; color: #0F172A; background: #FFFFFF; border: 1.5px solid #CBD5E1;">
                    </div>
                </div>
                <div class="ilmuna-form-field" style="margin-bottom: 0;">
                    <label for="eventEndDate" style="font-size: 12px; font-weight: 700; color: #334155; display: flex; align-items: center; justify-content: space-between; margin-bottom: 6px;">
                        <span>Tanggal Selesai</span>
                        <span id="rangeDurationBadge" style="font-size: 11px; font-weight: 800; color: #047857; background: #ECFDF5; padding: 2px 8px; border-radius: 6px;">1 Hari Saja</span>
                    </label>
                    <div style="position: relative; display: flex; align-items: center;">
                        <input type="date" id="eventEndDate" name="eventEndDate" class="ilmuna-input" required style="height: 42px; border-radius: 10px; cursor: pointer; padding: 8px 12px; font-weight: 700; font-size: 13px; color: #0F172A; background: #FFFFFF; border: 1.5px solid #CBD5E1;">
                    </div>
                </div>
            </div>

            <!-- Quick Duration Shortcuts -->
            <div style="display: flex; align-items: center; gap: 6px; margin-bottom: 16px; flex-wrap: wrap;">
                <span style="font-size: 11px; font-weight: 700; color: #64748B;">Pintas Durasi:</span>
                <button type="button" class="btn-quick-dur" data-days="1">1 Hari Saja</button>
                <button type="button" class="btn-quick-dur" data-days="2">+1 Hari (2 Hari)</button>
                <button type="button" class="btn-quick-dur" data-days="3">+2 Hari (3 Hari)</button>
                <button type="button" class="btn-quick-dur" data-days="5">5 Hari KBM</button>
                <button type="button" class="btn-quick-dur" data-days="7">1 Pekan (7 Hari)</button>
                <button type="button" class="btn-quick-dur" data-action="weekend">S/d Sabtu</button>
            </div>

            <!-- Selector Kategori Event (Grid Cards persis seperti React design) -->
            <div style="margin-bottom: 16px;">
                <label style="font-size: 12px; font-weight: 700; color: #334155; display: block; margin-bottom: 8px;">Kategori / Tipe Hari:</label>
                <div class="rbc-type-picker-grid" id="modalEventTypePicker">
                    <div class="rbc-type-card is-active" data-status="Efektif KBM" data-default="KBM Reguler">
                        <div class="rbc-type-dot" style="background: #10B981;"></div>
                        <div class="rbc-type-text">
                            <strong>Efektif KBM</strong>
                            <small>Akademik &amp; KBM Reguler</small>
                        </div>
                    </div>
                    <div class="rbc-type-card" data-status="Libur Nasional" data-default="Libur Nasional">
                        <div class="rbc-type-dot" style="background: #EF4444;"></div>
                        <div class="rbc-type-text">
                            <strong>Libur Nasional</strong>
                            <small>Hari Libur Resmi / Cuti</small>
                        </div>
                    </div>
                    <div class="rbc-type-card" data-status="Libur Semester" data-default="Libur Semester">
                        <div class="rbc-type-dot" style="background: #64748B;"></div>
                        <div class="rbc-type-text">
                            <strong>Libur Semester</strong>
                            <small>Libur Akhir Semester / Pekan</small>
                        </div>
                    </div>
                    <div class="rbc-type-card" data-status="Kegiatan Sekolah/Ujian" data-default="Asesmen / Ujian Sekolah">
                        <div class="rbc-type-dot" style="background: #F59E0B;"></div>
                        <div class="rbc-type-text">
                            <strong>Ujian &amp; Kegiatan</strong>
                            <small>Asesmen Sumatif / Event</small>
                        </div>
                    </div>
                    <div class="rbc-type-card" data-status="Libur Khusus Keagamaan" data-default="Hari Besar Islam / Ramadhan">
                        <div class="rbc-type-dot" style="background: #8B5CF6;"></div>
                        <div class="rbc-type-text">
                            <strong>Keagamaan</strong>
                            <small>Hari Besar Islam &amp; Puasa</small>
                        </div>
                    </div>
                    <div class="rbc-type-card" data-status="Projek P5" data-default="Projek P5 / Class Meeting">
                        <div class="rbc-type-dot" style="background: #0284C7;"></div>
                        <div class="rbc-type-text">
                            <strong>Projek P5 / Khusus</strong>
                            <small>Kokurikuler P5 / Jeda</small>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Uraian / Keterangan Agenda -->
            <div class="ilmuna-form-field" style="margin-bottom: 8px;">
                <label style="font-size: 12px; font-weight: 700; color: #334155;">Judul / Uraian Agenda Sekolah</label>
                <input type="text" id="eventKeterangan" class="ilmuna-input" style="height: 40px; border-radius: 10px; font-size: 13px;" placeholder="Misal: Asesmen Sumatif Akhir Semester, MPLS, Libur Hari Raya">
            </div>

            <div class="ilmuna-access-modal__footer" style="padding: 16px 0 0; border-top: 1px solid #F1F5F9; display: flex; justify-content: space-between; align-items: center; margin-top: 16px;">
                <button type="button" class="ilmuna-btn ilmuna-btn--sm" id="btnDeleteEventKaldik" style="background: #FEF2F2; color: #DC2626; border: 1px solid #FECACA; border-radius: 8px; display: none;">
                    <iconify-icon icon="solar:trash-bin-trash-bold" width="14"></iconify-icon> Hapus Agenda
                </button>
                <div style="display: flex; align-items: center; gap: 8px; margin-left: auto;">
                    <button type="button" class="ilmuna-btn ilmuna-btn--light-outline ilmuna-btn--sm" data-close="1" style="border-radius: 8px;">Batal</button>
                    <button type="button" class="ilmuna-btn ilmuna-btn--teal ilmuna-btn--sm" id="btnSaveEventKaldik" style="border-radius: 8px;">
                        <iconify-icon icon="solar:diskette-bold" width="15"></iconify-icon> <span>Simpan Agenda</span>
                    </button>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- ==============================================================================
 * MODAL RENTANG TANGGAL KALDIK
 * ============================================================================== -->
<div class="ilmuna-access-modal" id="modalRangeKaldik">
    <div class="ilmuna-access-modal__overlay" data-close="1"></div>
    <div class="ilmuna-access-modal__box" style="max-width: 520px;">
        <div class="ilmuna-access-modal__head">
            <h3>
                <iconify-icon icon="solar:calendar-add-bold" width="22" style="color: #0D7A87;"></iconify-icon>
                <span>Atur Status Rentang Tanggal Kaldik</span>
            </h3>
            <button type="button" class="ilmuna-access-modal__close" data-close="1">&times;</button>
        </div>
        <form id="formRangeKaldik" class="ilmuna-access-modal__body" onsubmit="return false;">
            <div class="ilmuna-form-row">
                <div class="ilmuna-form-field">
                    <label>Tanggal Mulai</label>
                    <input type="date" id="rangeStartDate" class="ilmuna-input" required value="<?= date('Y-m-d') ?>">
                </div>
                <div class="ilmuna-form-field">
                    <label>Tanggal Selesai</label>
                    <input type="date" id="rangeEndDate" class="ilmuna-input" required value="<?= date('Y-m-d') ?>">
                </div>
            </div>

            <div class="ilmuna-form-field">
                <label>Status Hari / KBM</label>
                <select id="rangeStatus" class="ilmuna-input">
                    <option value="Efektif KBM">🟢 Efektif KBM (Hari Belajar)</option>
                    <option value="Libur Nasional">🔴 Libur Nasional / Cuti Bersama</option>
                    <option value="Libur Semester">🔘 Libur Semester / Akhir Pekan</option>
                    <option value="Kegiatan Sekolah/Ujian">🟡 Ujian / Asesmen / Kegiatan Sekolah</option>
                    <option value="Libur Khusus Keagamaan">🟣 Libur Keagamaan &amp; Ramadhan</option>
                </select>
            </div>

            <div class="ilmuna-form-field">
                <label>Uraian / Keterangan Agenda</label>
                <input type="text" id="rangeKeterangan" class="ilmuna-input" placeholder="Misal: Libur Akhir Semester Ganjil, MPLS, Asesmen Sumatif">
            </div>

            <div style="background: #F0FDF4; border: 1px solid #BBF7D0; border-radius: 8px; padding: 10px 12px; font-size: 11.5px; color: #166534;">
                * Seluruh tanggal dalam rentang otomatis tersinkron ke semua rombel KBM.
            </div>

            <div class="ilmuna-access-modal__footer" style="padding: 16px 0 0; display: flex; justify-content: flex-end; gap: 8px;">
                <button type="button" class="ilmuna-btn ilmuna-btn--light-outline" data-close="1">Batal</button>
                <button type="button" class="ilmuna-btn ilmuna-btn--teal" id="btnSubmitRange">
                    <iconify-icon icon="solar:diskette-bold" width="16"></iconify-icon> Terapkan Rentang Tanggal
                </button>
            </div>
        </form>
    </div>
</div>

<!-- ==============================================================================
 * MODAL BULK GENERATE KALDIK
 * ============================================================================== -->
<div class="ilmuna-access-modal" id="modalBulkGen">
    <div class="ilmuna-access-modal__overlay" data-close="1"></div>
    <div class="ilmuna-access-modal__box" style="max-width: 480px;">
        <div class="ilmuna-access-modal__head">
            <h3>
                <iconify-icon icon="solar:magic-stick-3-bold" width="22" style="color: #0284C7;"></iconify-icon>
                <span>Generate Kaldik 1 Semester Otomatis</span>
            </h3>
            <button type="button" class="ilmuna-access-modal__close" data-close="1">&times;</button>
        </div>
        <div class="ilmuna-access-modal__body">
            <p style="font-size: 13px; color: #475569; margin-top: 0;">
                Sistem akan membuat seluruh tanggal semester aktif di Kaldik Sekolah dan menyinkronkan data hari &amp; pekan efektif ke seluruh rombel KBM.
            </p>

            <div class="ilmuna-form-field">
                <label>Pola Hari Kerja Sekolah</label>
                <select id="genLiburSabtu" class="ilmuna-input">
                    <option value="1">5 Hari Sekolah (Sabtu &amp; Minggu Libur)</option>
                    <option value="0">6 Hari Sekolah (Sabtu Masuk KBM, Minggu Libur)</option>
                </select>
            </div>

            <div class="ilmuna-access-modal__footer" style="padding: 16px 0 0; display: flex; justify-content: flex-end; gap: 8px;">
                <button type="button" class="ilmuna-btn ilmuna-btn--light-outline" data-close="1">Batal</button>
                <button type="button" class="ilmuna-btn ilmuna-btn--sky" id="btnSubmitBulkGen">
                    <iconify-icon icon="solar:magic-stick-3-bold" width="16"></iconify-icon> Generate Sekarang
                </button>
            </div>
        </div>
    </div>
</div>

<!-- ==============================================================================
 * MODAL CP & ELEMEN
 * ============================================================================== -->
<div class="ilmuna-access-modal" id="modalCP">
    <div class="ilmuna-access-modal__overlay" data-close="1"></div>
    <div class="ilmuna-access-modal__box" style="max-width: 600px;">
        <div class="ilmuna-access-modal__head">
            <h3>
                <iconify-icon icon="solar:diploma-verified-bold" width="22" style="color: #D97706;"></iconify-icon>
                <span id="modalCPTitle">Tambah Capaian Pembelajaran (CP)</span>
            </h3>
            <button type="button" class="ilmuna-access-modal__close" data-close="1">&times;</button>
        </div>
        <form id="formCP" class="ilmuna-access-modal__body" onsubmit="return false;">
            <input type="hidden" id="cpId" value="0">

            <div class="ilmuna-form-field">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 6px;">
                    <label style="margin: 0; font-weight: 700; color: #1E293B; font-size: 12.5px;">
                        Rombel &amp; Mata Pelajaran (Pilihan Berganda) <span style="color: #DC2626;">*</span>
                    </label>
                    <div style="display: flex; gap: 6px; align-items: center;">
                        <button type="button" id="btnSelectAllCpKmp" class="ilmuna-btn ilmuna-btn--sm" style="height: 24px; padding: 0 8px; font-size: 11px; background: #E0F2FE; color: #0369A1; font-weight: 700; border: none; border-radius: 4px; cursor: pointer;">Pilih Semua</button>
                        <button type="button" id="btnDeselectAllCpKmp" class="ilmuna-btn ilmuna-btn--sm" style="height: 24px; padding: 0 8px; font-size: 11px; background: #F1F5F9; color: #64748B; font-weight: 600; border: none; border-radius: 4px; cursor: pointer;">Batal Semua</button>
                        <span id="cpKmpSelectedCount" style="background: #FEF3C7; color: #92400E; font-size: 11px; font-weight: 700; padding: 2px 8px; border-radius: 10px;">0 dipilih</span>
                    </div>
                </div>

                <div style="margin-bottom: 6px; position: relative;">
                    <input type="text" id="searchCpKmp" placeholder="Cari rombel / mata pelajaran..." class="ilmuna-input" style="height: 32px; font-size: 12px; padding-left: 28px;">
                    <iconify-icon icon="solar:magnifer-linear" width="14" style="position: absolute; left: 8px; top: 9px; color: #94A3B8;"></iconify-icon>
                </div>

                <div id="cpKmpMultiBox" style="max-height: 160px; overflow-y: auto; border: 1px solid #CBD5E1; border-radius: 8px; background: #FFFFFF; padding: 4px;">
                    <?php foreach ($kmpList as $kmp): ?>
                        <label class="cp-kmp-item" style="display: flex; align-items: center; gap: 8px; padding: 6px 10px; border-radius: 6px; cursor: pointer; transition: background 0.15s; font-size: 12.5px; user-select: none;" onmouseover="this.style.background='#F1F5F9'" onmouseout="this.style.background='transparent'">
                            <input type="checkbox" name="cp_kmp[]" class="cp-kmp-checkbox" value="<?= $kmp['id'] ?>" data-kelas="<?= htmlspecialchars($kmp['nama_kelas']) ?>" data-mapel="<?= htmlspecialchars($kmp['nama_mata_pelajaran']) ?>" style="cursor: pointer; width: 16px; height: 16px; accent-color: #0D7A87;">
                            <span style="font-weight: 700; color: #0D7A87;"><?= htmlspecialchars($kmp['nama_kelas']) ?></span>
                            <span style="color: #64748B;">&bull;</span>
                            <span style="color: #1E293B;"><?= htmlspecialchars($kmp['nama_mata_pelajaran']) ?></span>
                        </label>
                    <?php endforeach; ?>
                </div>
                <div style="font-size: 11px; color: #64748B; margin-top: 4px;">
                    Centang satu atau beberapa rombel sekaligus untuk menerapkan dokumen CP ke seluruh kelas terpilih.
                </div>
            </div>

            <div class="ilmuna-form-field">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 6px;">
                    <label style="margin: 0; font-weight: 700; color: #1E293B; font-size: 12.5px;">
                        Elemen Kurikulum Merdeka <span style="color: #DC2626;">*</span>
                    </label>
                    <button type="button" class="ilmuna-btn ilmuna-btn--sm" id="btnTambahElemenDariCP" style="height: 28px; padding: 0 10px; font-size: 11.5px; background: #0D7A87; color: #FFFFFF; font-weight: 700; border-radius: 6px; display: inline-flex; align-items: center; gap: 4px; cursor: pointer;">
                        <iconify-icon icon="solar:add-circle-bold" width="14"></iconify-icon>
                        <span>+ Tambah Elemen</span>
                    </button>
                </div>
                <div style="display: flex; gap: 8px; align-items: center;">
                    <select id="cpElemen" class="ilmuna-input" required style="font-weight: 600; flex: 1;">
                        <option value="0">-- Pilih Elemen Kurikulum Merdeka --</option>
                        <?php foreach ($elemenList as $el): 
                            $labelFase = !empty($el['fase']) ? ' [' . $el['fase'] . ']' : '';
                            $labelRombel = !empty($el['nama_kelas']) ? ' (' . $el['nama_kelas'] . ')' : '';
                        ?>
                            <option value="<?= $el['id'] ?>" data-kmp="<?= $el['id_kelas_mata_pelajaran'] ?>" data-nama="<?= htmlspecialchars($el['nama_elemen']) ?>" data-fase="<?= htmlspecialchars($el['fase'] ?? '') ?>">
                                <?= htmlspecialchars($el['nama_elemen']) ?><?= $labelFase ?><?= $labelRombel ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <button type="button" class="ilmuna-btn ilmuna-btn--teal ilmuna-btn--sm" id="btnQuickTambahElemen" style="height: 38px; padding: 0 12px; font-weight: 700; white-space: nowrap; border-radius: 8px;" title="Tambah Elemen Baru">
                        <iconify-icon icon="solar:add-circle-bold" width="16"></iconify-icon>
                        <span>+ Elemen</span>
                    </button>
                </div>
                <div style="font-size: 11px; color: #64748B; margin-top: 4px;">
                    Elemen bertanda (★) sesuai dengan rombel yang dipilih. Anda juga dapat memilih elemen kurikulum lainnya atau klik <strong>+ Tambah Elemen</strong>.
                </div>
            </div>

            <div class="ilmuna-form-field">
                <label>Naskah Capaian Pembelajaran (CP Standar BSKAP)</label>
                <textarea id="cpTeks" class="ilmuna-input" rows="5" placeholder="Salin naskah Capaian Pembelajaran (CP) resmi dari Keputusan BSKAP Kemendikbudristek..."></textarea>
            </div>

            <div class="ilmuna-access-modal__footer" style="padding: 14px 0 0; display: flex; justify-content: flex-end; gap: 8px;">
                <button type="button" class="ilmuna-btn ilmuna-btn--light-outline" data-close="1">Batal</button>
                <button type="button" class="ilmuna-btn ilmuna-btn--gold" id="btnSaveCP">
                    <iconify-icon icon="solar:diskette-bold" width="16"></iconify-icon> Simpan Dokumen CP
                </button>
            </div>
        </form>
    </div>
</div>

<div class="ilmuna-access-modal" id="modalElemen">
    <div class="ilmuna-access-modal__overlay" data-close="1"></div>
    <div class="ilmuna-access-modal__box" style="max-width: 580px;">
        <div class="ilmuna-access-modal__head">
            <h3>
                <iconify-icon icon="solar:layers-bold" width="22" style="color: #0D7A87;"></iconify-icon>
                <span>Kelola Elemen Kurikulum Merdeka</span>
            </h3>
            <button type="button" class="ilmuna-access-modal__close" data-close="1">&times;</button>
        </div>
        <div class="ilmuna-access-modal__body">
            <div style="background: #F8FAFC; border: 1px solid #E2E8F0; border-radius: 10px; padding: 14px; margin-bottom: 16px;">
                <strong style="font-size: 13px; color: #0F172A; display: block; margin-bottom: 8px;">Tambah Elemen Baru:</strong>
                <div class="ilmuna-form-field">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 6px;">
                        <label style="margin: 0; font-weight: 700; color: #1E293B; font-size: 12.5px;">
                            Rombel &amp; Mata Pelajaran (Pilihan Berganda) <span style="color: #DC2626;">*</span>
                        </label>
                        <div style="display: flex; gap: 6px; align-items: center;">
                            <button type="button" id="btnSelectAllElemenKmp" class="ilmuna-btn ilmuna-btn--sm" style="height: 24px; padding: 0 8px; font-size: 11px; background: #E0F2FE; color: #0369A1; font-weight: 700; border: none; border-radius: 4px; cursor: pointer;">Pilih Semua</button>
                            <button type="button" id="btnDeselectAllElemenKmp" class="ilmuna-btn ilmuna-btn--sm" style="height: 24px; padding: 0 8px; font-size: 11px; background: #F1F5F9; color: #64748B; font-weight: 600; border: none; border-radius: 4px; cursor: pointer;">Batal Semua</button>
                            <span id="elemenKmpSelectedCount" style="background: #FEF3C7; color: #92400E; font-size: 11px; font-weight: 700; padding: 2px 8px; border-radius: 10px;">0 dipilih</span>
                        </div>
                    </div>

                    <div style="margin-bottom: 6px; position: relative;">
                        <input type="text" id="searchElemenKmp" placeholder="Cari rombel / mata pelajaran..." class="ilmuna-input" style="height: 32px; font-size: 12px; padding-left: 28px;">
                        <iconify-icon icon="solar:magnifer-linear" width="14" style="position: absolute; left: 8px; top: 9px; color: #94A3B8;"></iconify-icon>
                    </div>

                    <div id="elemenKmpMultiBox" style="max-height: 140px; overflow-y: auto; border: 1px solid #CBD5E1; border-radius: 8px; background: #FFFFFF; padding: 4px;">
                        <?php foreach ($kmpList as $kmp): ?>
                            <label class="elemen-kmp-item" style="display: flex; align-items: center; gap: 8px; padding: 6px 10px; border-radius: 6px; cursor: pointer; transition: background 0.15s; font-size: 12.5px; user-select: none;" onmouseover="this.style.background='#F1F5F9'" onmouseout="this.style.background='transparent'">
                                <input type="checkbox" name="elemen_kmp[]" class="elemen-kmp-checkbox" value="<?= $kmp['id'] ?>" data-kelas="<?= htmlspecialchars($kmp['nama_kelas']) ?>" data-mapel="<?= htmlspecialchars($kmp['nama_mata_pelajaran']) ?>" style="cursor: pointer; width: 16px; height: 16px; accent-color: #0D7A87;">
                                <span style="font-weight: 700; color: #0D7A87;"><?= htmlspecialchars($kmp['nama_kelas']) ?></span>
                                <span style="color: #64748B;">&bull;</span>
                                <span style="color: #1E293B;"><?= htmlspecialchars($kmp['nama_mata_pelajaran']) ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                    <div style="font-size: 11px; color: #64748B; margin-top: 4px;">
                        Centang satu atau beberapa rombel sekaligus untuk menerapkan elemen ke seluruh kelas terpilih.
                    </div>
                </div>
                <div class="ilmuna-form-row">
                    <div class="ilmuna-form-field" style="flex: 2;">
                        <label>Nama Elemen</label>
                        <input type="text" id="elemenNama" class="ilmuna-input" placeholder="Misal: Al-Qur'an Hadis, Fikih, Geometri">
                    </div>
                    <div class="ilmuna-form-field" style="flex: 1;">
                        <label>Fase</label>
                        <select id="elemenFase" class="ilmuna-input">
                            <option value="Fase E">Fase E (Kelas 10)</option>
                            <option value="Fase F">Fase F (Kelas 11-12)</option>
                            <option value="Fase D">Fase D (SMP/MTs)</option>
                            <option value="Fase A">Fase A (SD Kelas 1-2)</option>
                            <option value="Fase B">Fase B (SD Kelas 3-4)</option>
                            <option value="Fase C">Fase C (SD Kelas 5-6)</option>
                        </select>
                    </div>
                </div>
                <div class="ilmuna-form-field">
                    <label>Deskripsi Lingkup Materi</label>
                    <input type="text" id="elemenDeskripsi" class="ilmuna-input" placeholder="Uraian singkat fokus kajian elemen">
                </div>
                <button type="button" class="ilmuna-btn ilmuna-btn--teal ilmuna-btn--sm" id="btnSaveElemen" style="width: 100%; height: 34px;">
                    <iconify-icon icon="solar:add-circle-bold" width="14"></iconify-icon> Tambahkan Elemen Ini
                </button>
            </div>

            <div style="font-size: 13px; font-weight: 700; color: #0F172A; margin-bottom: 8px;">Daftar Elemen Terdaftar:</div>
            <div id="elemenListBox" style="max-height: 200px; overflow-y: auto; border: 1px solid #E2E8F0; border-radius: 8px;">
                <?php if (empty($elemenList)): ?>
                    <div style="padding: 16px; text-align: center; color: #94A3B8; font-size: 12px;">Belum ada elemen kurikulum.</div>
                <?php else: ?>
                    <?php foreach ($elemenList as $el): ?>
                        <div class="elemen-row" data-kmp="<?= $el['id_kelas_mata_pelajaran'] ?>" style="padding: 10px 14px; border-bottom: 1px solid #F1F5F9; display: flex; justify-content: space-between; align-items: center;">
                            <div>
                                <strong style="font-size: 13px; color: #0F172A;"><?= htmlspecialchars($el['nama_elemen']) ?></strong>
                                <span class="ilmuna-tab-counter" style="margin-left: 6px; font-size: 10px;"><?= htmlspecialchars($el['fase']) ?></span>
                                <div style="font-size: 11.5px; color: #64748B; margin-top: 2px;">
                                    <?= htmlspecialchars($el['nama_mata_pelajaran']) ?> &bull; <?= htmlspecialchars($el['nama_kelas']) ?>
                                </div>
                            </div>
                            <button type="button" class="ilmuna-btn-icon btn-delete-elemen" data-id="<?= $el['id'] ?>" data-nama="<?= htmlspecialchars($el['nama_elemen']) ?>" title="Hapus Elemen">
                                <iconify-icon icon="solar:trash-bin-trash-bold" width="16" style="color: #EF4444;"></iconify-icon>
                            </button>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<div class="ilmuna-toast-wrap" id="toastWrap"></div>

<script>
(function(){
    var AJAX_URL = '<?= BASE_URL ?>/dashboard/admin/curriculum.php';
    var activeYear = '<?= $selectedTahun ?>';
    var activeSemester = '<?= $selectedSem ?>';

    // Toast Notification (Integrasi Ilmuna Alert & Toast)
    function toast(type, msg) {
        var normType = (type === 'danger' || type === 'err') ? 'error' : (type || 'info');
        var titleMap = { success: 'Berhasil', error: 'Gagal', warning: 'Peringatan', info: 'Informasi' };
        if (window.Ilmuna && typeof window.Ilmuna.toast === 'function') {
            return Ilmuna.toast(normType, titleMap[normType] || 'Pemberitahuan', msg, 4000);
        }
        var wrap = document.getElementById('toastWrap');
        if (!wrap) return;
        var item = document.createElement('div');
        item.className = 'ilmuna-toast ilmuna-toast--' + type;
        var icon = (type === 'success') ? 'solar:check-circle-bold' : ((type === 'warning') ? 'solar:danger-triangle-bold' : 'solar:close-circle-bold');
        item.innerHTML = '<iconify-icon icon="' + icon + '" class="ilmuna-toast__icon"></iconify-icon>' +
                         '<div class="ilmuna-toast__msg">' + msg + '</div>' +
                         '<button type="button" class="ilmuna-toast__close">&times;</button>';
        wrap.appendChild(item);
        setTimeout(function(){ item.classList.add('show'); }, 10);
        item.querySelector('.ilmuna-toast__close').addEventListener('click', function(){ removeToast(item); });
        setTimeout(function(){ removeToast(item); }, 4000);
    }
    function removeToast(el) {
        el.classList.remove('show');
        setTimeout(function(){ if (el.parentNode) el.parentNode.removeChild(el); }, 250);
    }

    // Helper Dialog Konfirmasi Ilmuna
    function askConfirm(msg, title, type) {
        title = title || 'Konfirmasi Tindakan';
        type = type || 'warning';
        if (window.Ilmuna && typeof window.Ilmuna.confirm === 'function') {
            return Ilmuna.confirm(type, title, msg, 'Ya, Lanjutkan', 'Batal');
        }
        return Promise.resolve(confirm(msg));
    }

    // Modal Helpers
    function openModal(id) {
        var m = document.getElementById(id);
        if (m) m.classList.add('is-open');
    }
    function closeModal(id) {
        var m = document.getElementById(id);
        if (m) m.classList.remove('is-open');
    }
    document.addEventListener('click', function(e) {
        if (e.target.matches('[data-close]') || e.target.closest('[data-close]')) {
            var m = e.target.closest('.ilmuna-access-modal');
            if (m) m.classList.remove('is-open');
        }
    });

    // Tabs Strip Switcher (Segmented Control: Anti-Overlap, Tidak Timpang Tindih)
    var tabItems = document.querySelectorAll('.curriculum-main-tabs .curriculum-tab-btn');
    var tabPanes = {
        'kaldik': document.getElementById('tabPaneKaldik'),
        'rpe': document.getElementById('tabPaneRpe'),
        'cp': document.getElementById('tabPaneCP'),
        'struktur': document.getElementById('tabPaneStruktur')
    };

    tabItems.forEach(function(item){
        item.addEventListener('click', function(){
            tabItems.forEach(function(ti){ ti.classList.remove('is-active'); });
            item.classList.add('is-active');
            var target = item.dataset.tab;
            for (var k in tabPanes) {
                if (tabPanes[k]) tabPanes[k].classList.toggle('is-active', k === target);
            }
        });
    });

    function escapeHtml(text) {
        if (!text) return '';
        return String(text)
            .replace(/&/g, "&amp;")
            .replace(/</g, "&lt;")
            .replace(/>/g, "&gt;")
            .replace(/"/g, "&quot;")
            .replace(/'/g, "&#039;");
    }

    // =========================================================================
    // REACT-BIG-CALENDAR (RBC) CONTROLLER & LAZY STATE MANAGEMENT
    // =========================================================================
    var currentKaldikState = <?= json_encode(getKaldikStateData($pdo, $selectedTahun, $selectedSem)) ?>;
    var currentView = 'month';
    var calYear = <?= (int)$calYear ?>;
    var currentMonthNum = <?= (int)$defaultActiveMonth ?>;
    var monthKeys = <?= json_encode(array_values(array_keys($activeMonths))) ?>;

    var serverTodayYMD = '<?= date('Y-m-d') ?>';
    var sParts = serverTodayYMD.split('-');
    var todayObj = new Date(parseInt(sParts[0], 10), parseInt(sParts[1], 10) - 1, parseInt(sParts[2], 10));
    var currentDate = new Date(calYear, currentMonthNum - 1, 1);
    if (todayObj.getFullYear() === calYear && (todayObj.getMonth() + 1) === currentMonthNum) {
        currentDate = new Date(todayObj);
    }

    // DOM Elements
    var titleMonthEl = document.getElementById('displayActiveMonthTitle');
    var hijriMonthEl = document.getElementById('displayActiveHijriSpan');
    var btnRbcPrev   = document.getElementById('btnRbcPrev');
    var btnRbcNext   = document.getElementById('btnRbcNext');
    var btnRbcToday  = document.getElementById('btnRbcToday');
    var pillsBarEl   = document.getElementById('rbcMonthPillsBar');

    var monthPanes   = document.querySelectorAll('.kaldik-month-pane');
    var monthPills   = document.querySelectorAll('.kaldik-month-pill-btn');
    var viewBtns     = document.querySelectorAll('#rbcViewSwitchers .rbc-view-btn');

    var viewPanes = {
        'month':  document.getElementById('rbcViewMonth'),
        'week':   document.getElementById('rbcViewWeek'),
        'day':    document.getElementById('rbcViewDay'),
        'agenda': document.getElementById('rbcViewAgenda')
    };

    function pad2(n) { return String(n).padStart(2, '0'); }
    function toYMD(d) { return d.getFullYear() + '-' + pad2(d.getMonth() + 1) + '-' + pad2(d.getDate()); }

    var namaBulanIndo = ['', 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
    var namaHariIndo = ['Minggu', 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu'];

    function getEventChipClass(status, ket) {
        ket = (ket || '').toLowerCase();
        if (status === 'Libur Nasional') return 'rbc-chip--libur';
        if (status === 'Libur Semester') return 'rbc-chip--sem';
        if (status === 'Kegiatan Sekolah/Ujian') return 'rbc-chip--kegiatan';
        if (status === 'Libur Khusus Keagamaan') return 'rbc-chip--keagamaan';
        if (ket.indexOf('p5') !== -1 || ket.indexOf('projek') !== -1) return 'rbc-chip--p5';
        return 'rbc-chip--kbm';
    }

    // =========================================================================
    // 1. VIEW SWITCHER (BULAN, MINGGU, HARI, AGENDA)
    // =========================================================================
    function switchView(viewName) {
        currentView = viewName;
        document.querySelectorAll('#rbcViewSwitchers .rbc-view-btn').forEach(function(b){
            b.classList.toggle('is-active', b.getAttribute('data-view') === viewName);
        });

        for (var k in viewPanes) {
            if (viewPanes[k]) viewPanes[k].style.display = (k === viewName) ? 'block' : 'none';
        }

        var pBar = document.getElementById('rbcMonthPillsBar');
        if (pBar) {
            pBar.style.display = (viewName === 'month') ? 'flex' : 'none';
        }

        if (viewName === 'month') {
            switchActiveMonth(currentMonthNum);
        } else if (viewName === 'week') {
            renderWeekView();
        } else if (viewName === 'day') {
            renderDayView();
        } else if (viewName === 'agenda') {
            if (titleMonthEl) titleMonthEl.textContent = 'Daftar Agenda Kaldik Semester ' + activeSemester + ' (' + activeYear + ')';
            if (hijriMonthEl) hijriMonthEl.textContent = '📅 ' + (currentKaldikState.items ? currentKaldikState.items.length : 0) + ' Agenda Terdaftar';
        }
        updateNavState();
    }

    var rbcViewSwitchersBox = document.getElementById('rbcViewSwitchers');
    if (rbcViewSwitchersBox) {
        rbcViewSwitchersBox.addEventListener('click', function(e){
            var btn = e.target.closest('.rbc-view-btn');
            if (!btn) return;
            switchView(btn.getAttribute('data-view'));
        });
    }

    // =========================================================================
    // 2. MONTH SWITCHER (EVENT DELEGATION: ALWAYS WORKS & ULTRA SMOOTH)
    // =========================================================================
    function switchActiveMonth(mNum) {
        mNum = parseInt(mNum, 10);
        if (!mNum) return;
        currentMonthNum = mNum;
        currentDate = new Date(calYear, mNum - 1, 1);

        // Toggle active month panes dynamically
        document.querySelectorAll('.kaldik-month-pane').forEach(function(pane){
            var pM = parseInt(pane.getAttribute('data-month'), 10);
            if (pM === mNum) {
                pane.style.display = 'block';
                pane.classList.remove('is-active');
                void pane.offsetWidth; // force reflow for smooth animation
                pane.classList.add('is-active');
            } else {
                pane.classList.remove('is-active');
                pane.style.display = 'none';
            }
        });

        // Toggle active class on all month pills dynamically
        document.querySelectorAll('.kaldik-month-pill-btn').forEach(function(btn){
            btn.classList.toggle('is-active', parseInt(btn.getAttribute('data-month'), 10) === mNum);
        });

        var curPane = document.getElementById('monthPane-' + mNum);
        if (curPane && currentView === 'month') {
            if (titleMonthEl) titleMonthEl.textContent = curPane.getAttribute('data-title');
            if (hijriMonthEl) hijriMonthEl.textContent = curPane.getAttribute('data-hijri');
        }
        updateNavState();
    }

    // Event Delegation on month pills bar (never breaks on lazy loads)
    var monthPillsBarEl = document.getElementById('rbcMonthPillsBar');
    if (monthPillsBarEl) {
        monthPillsBarEl.addEventListener('click', function(e){
            var pill = e.target.closest('.kaldik-month-pill-btn');
            if (!pill) return;
            e.preventDefault();
            var m = parseInt(pill.getAttribute('data-month'), 10);
            if (m) {
                switchActiveMonth(m);
                if (currentView === 'week') renderWeekView();
                if (currentView === 'day') renderDayView();
            }
        });
    }

    // =========================================================================
    // 3. NAVIGATION (PREV, TODAY, NEXT)
    // =========================================================================
    function updateNavState() {
        if (!btnRbcPrev || !btnRbcNext) return;
        if (currentView === 'month') {
            var idx = monthKeys.indexOf(currentMonthNum);
            btnRbcPrev.disabled = (idx <= 0);
            btnRbcPrev.style.opacity = (idx <= 0) ? '0.4' : '1';
            btnRbcNext.disabled = (idx >= monthKeys.length - 1);
            btnRbcNext.style.opacity = (idx >= monthKeys.length - 1) ? '0.4' : '1';
        } else {
            btnRbcPrev.disabled = false;
            btnRbcPrev.style.opacity = '1';
            btnRbcNext.disabled = false;
            btnRbcNext.style.opacity = '1';
        }
    }

    if (btnRbcToday) {
        btnRbcToday.addEventListener('click', function(){
            var now = new Date();
            var nowM = now.getMonth() + 1;
            if (monthKeys.indexOf(nowM) !== -1) {
                currentDate = new Date(now);
                currentMonthNum = nowM;
            } else {
                currentMonthNum = monthKeys[0];
                currentDate = new Date(calYear, currentMonthNum - 1, 1);
            }
            if (currentView === 'month') {
                switchActiveMonth(currentMonthNum);
            } else if (currentView === 'week') {
                renderWeekView();
            } else if (currentView === 'day') {
                renderDayView();
            }
        });
    }

    if (btnRbcPrev) {
        btnRbcPrev.addEventListener('click', function(){
            if (currentView === 'month') {
                var idx = monthKeys.indexOf(currentMonthNum);
                if (idx > 0) switchActiveMonth(monthKeys[idx - 1]);
            } else if (currentView === 'week') {
                currentDate.setDate(currentDate.getDate() - 7);
                renderWeekView();
            } else if (currentView === 'day') {
                currentDate.setDate(currentDate.getDate() - 1);
                renderDayView();
            }
        });
    }

    if (btnRbcNext) {
        btnRbcNext.addEventListener('click', function(){
            if (currentView === 'month') {
                var idx = monthKeys.indexOf(currentMonthNum);
                if (idx < monthKeys.length - 1) switchActiveMonth(monthKeys[idx + 1]);
            } else if (currentView === 'week') {
                currentDate.setDate(currentDate.getDate() + 7);
                renderWeekView();
            } else if (currentView === 'day') {
                currentDate.setDate(currentDate.getDate() + 1);
                renderDayView();
            }
        });
    }

    // =========================================================================
    // 4. DYNAMIC WEEK VIEW (PERSIS SEPERTI REACT RBC Views.WEEK)
    // =========================================================================
    function renderWeekView() {
        var container = document.getElementById('rbcWeekGridContent');
        if (!container) return;

        var dayOfWeek = currentDate.getDay(); // 0 is Sunday, 1 is Monday...
        var distanceToMonday = (dayOfWeek + 6) % 7;
        var monday = new Date(currentDate);
        monday.setDate(currentDate.getDate() - distanceToMonday);

        var days = [];
        for (var i = 0; i < 7; i++) {
            var d = new Date(monday);
            d.setDate(monday.getDate() + i);
            days.push(d);
        }

        var startStr = days[0].getDate() + ' ' + namaBulanIndo[days[0].getMonth() + 1];
        var endStr   = days[6].getDate() + ' ' + namaBulanIndo[days[6].getMonth() + 1] + ' ' + days[6].getFullYear();
        if (titleMonthEl) titleMonthEl.textContent = 'Pekan ' + startStr + ' – ' + endStr;

        var itemsMap = currentKaldikState.items_by_date || {};
        var todayStr = toYMD(new Date());

        var html = '';
        days.forEach(function(d){
            var ymd = toYMD(d);
            var item = itemsMap[ymd];
            var isToday = (ymd === todayStr);

            var st = item ? item.status_hari : (d.getDay() === 0 ? 'Libur Semester' : 'Efektif KBM');
            var ket = item ? (item.keterangan || st) : (d.getDay() === 0 ? 'Libur Akhir Pekan' : 'KBM Reguler');
            var hijri = item ? item.hijri : '';
            var chipCls = getEventChipClass(st, ket);

            html += '<div class="rbc-week-col ' + (isToday ? 'is-today' : '') + '">' +
                '<div class="rbc-week-header">' +
                    '<div class="rbc-week-day-name" style="' + (d.getDay() === 0 ? 'color: #DC2626;' : '') + '">' + namaHariIndo[d.getDay()] + '</div>' +
                    '<div class="rbc-week-day-num" style="' + (isToday ? 'color: #0D7A87;' : '') + '">' + d.getDate() + '</div>' +
                    '<div class="rbc-week-day-hijri">' + (hijri ? '🌙 ' + escapeHtml(hijri) : '') + '</div>' +
                '</div>' +
                '<div class="rbc-week-body">' +
                    '<div class="rbc-event-chip ' + chipCls + '" data-date="' + ymd + '" style="margin-top: 4px;">' +
                        '<span class="rbc-chip-dot"></span>' +
                        '<span class="rbc-chip-title">' + escapeHtml(ket) + '</span>' +
                    '</div>' +
                    '<button type="button" class="rbc-week-add-btn" data-date="' + ymd + '">' +
                        '<iconify-icon icon="solar:add-circle-bold" width="13"></iconify-icon> Tambah / Ubah' +
                    '</button>' +
                '</div>' +
            '</div>';
        });

        container.innerHTML = html;

        // Click handlers on week view chips and add buttons
        container.querySelectorAll('.rbc-event-chip, .rbc-week-add-btn').forEach(function(el){
            el.addEventListener('click', function(e){
                e.stopPropagation();
                var dt = el.getAttribute('data-date');
                openEventModal(dt, itemsMap[dt]);
            });
        });
    }

    // =========================================================================
    // 5. DYNAMIC DAY HERO VIEW (PERSIS SEPERTI REACT RBC Views.DAY)
    // =========================================================================
    function renderDayView() {
        var container = document.getElementById('rbcDayHeroContent');
        if (!container) return;

        var ymd = toYMD(currentDate);
        var itemsMap = currentKaldikState.items_by_date || {};
        var item = itemsMap[ymd];

        var fullDateStr = namaHariIndo[currentDate.getDay()] + ', ' + currentDate.getDate() + ' ' + namaBulanIndo[currentDate.getMonth() + 1] + ' ' + currentDate.getFullYear();
        if (titleMonthEl) titleMonthEl.textContent = fullDateStr;

        var st = item ? item.status_hari : (currentDate.getDay() === 0 ? 'Libur Semester' : 'Efektif KBM');
        var ket = item ? (item.keterangan || st) : (currentDate.getDay() === 0 ? 'Libur Akhir Pekan (Minggu)' : 'KBM Reguler');
        var hijri = item ? item.hijri : '';
        if (hijriMonthEl) hijriMonthEl.textContent = hijri ? '🌙 ' + hijri : 'Kalender Pendidikan';

        var chipCls = getEventChipClass(st, ket);

        var html = '<div class="rbc-day-hero-card">' +
            '<div class="rbc-day-hero-top">' +
                '<div>' +
                    '<div class="rbc-day-hero-date">' + fullDateStr + '</div>' +
                    '<div class="rbc-day-hero-sub">' + (hijri ? '🌙 ' + escapeHtml(hijri) : 'Semester ' + activeSemester) + '</div>' +
                '</div>' +
                '<div>' +
                    '<span class="rbc-event-chip ' + chipCls + '" style="font-size: 13px; padding: 6px 14px; border-radius: 8px;">' +
                        '<span class="rbc-chip-dot" style="width: 8px; height: 8px;"></span>' +
                        '<strong>' + escapeHtml(st) + '</strong>' +
                    '</span>' +
                '</div>' +
            '</div>' +

            '<div style="background: #FFFFFF; border: 1px solid #E2E8F0; border-radius: 12px; padding: 16px 20px; margin-bottom: 20px;">' +
                '<div style="font-size: 12px; font-weight: 700; color: #64748B; text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 4px;">Uraian Agenda:</div>' +
                '<div style="font-size: 16px; font-weight: 800; color: #0F172A;">' + escapeHtml(ket) + '</div>' +
            '</div>' +

            '<div style="font-size: 13px; font-weight: 700; color: #334155; margin-bottom: 8px;">Ubah Cepat Status Tanggal Ini:</div>' +
            '<div class="rbc-day-quick-grid">' +
                '<button type="button" class="rbc-day-quick-btn" data-status="Efektif KBM" data-default="KBM Reguler">' +
                    '<span style="width: 10px; height: 10px; border-radius: 50%; background: #10B981;"></span>' +
                    '<span>Efektif KBM</span>' +
                '</button>' +
                '<button type="button" class="rbc-day-quick-btn" data-status="Libur Nasional" data-default="Libur Nasional">' +
                    '<span style="width: 10px; height: 10px; border-radius: 50%; background: #EF4444;"></span>' +
                    '<span>Libur Nasional</span>' +
                '</button>' +
                '<button type="button" class="rbc-day-quick-btn" data-status="Libur Semester" data-default="Libur Semester">' +
                    '<span style="width: 10px; height: 10px; border-radius: 50%; background: #64748B;"></span>' +
                    '<span>Libur Semester</span>' +
                '</button>' +
                '<button type="button" class="rbc-day-quick-btn" data-status="Kegiatan Sekolah/Ujian" data-default="Asesmen / Ujian Sekolah">' +
                    '<span style="width: 10px; height: 10px; border-radius: 50%; background: #F59E0B;"></span>' +
                    '<span>Ujian &amp; Kegiatan</span>' +
                '</button>' +
                '<button type="button" class="rbc-day-quick-btn" data-status="Libur Khusus Keagamaan" data-default="Hari Besar Islam / Ramadhan">' +
                    '<span style="width: 10px; height: 10px; border-radius: 50%; background: #8B5CF6;"></span>' +
                    '<span>Keagamaan</span>' +
                '</button>' +
                '<button type="button" class="rbc-day-quick-btn" data-status="Projek P5" data-default="Projek P5 / Class Meeting">' +
                    '<span style="width: 10px; height: 10px; border-radius: 50%; background: #0284C7;"></span>' +
                    '<span>Projek P5</span>' +
                '</button>' +
            '</div>' +

            '<div style="margin-top: 24px; text-align: right;">' +
                '<button type="button" class="ilmuna-btn ilmuna-btn--teal" id="btnEditDayHeroModal" style="border-radius: 10px;">' +
                    '<iconify-icon icon="solar:pen-bold" width="16"></iconify-icon> <span>Edit Agenda Lengkap</span>' +
                '</button>' +
            '</div>' +
        '</div>';

        container.innerHTML = html;

        // Quick status clicks in Day view
        container.querySelectorAll('.rbc-day-quick-btn').forEach(function(btn){
            btn.addEventListener('click', function(){
                var stNew = btn.getAttribute('data-status');
                var defKet = btn.getAttribute('data-default');
                applyBatchStatus([ymd], stNew, defKet);
            });
        });

        var btnHeroModal = document.getElementById('btnEditDayHeroModal');
        if (btnHeroModal) {
            btnHeroModal.addEventListener('click', function(){
                openEventModal(ymd, item);
            });
        }
    }

    // =========================================================================
    // 6. EVENT MODAL CONTROLLER (PERSIS SEPERTI REACT EventModal)
    // =========================================================================
    var modalEvent = document.getElementById('modalEventKaldik');
    var mEventTitle = document.getElementById('modalEventTitle');
    var mEventSubtitle = document.getElementById('modalEventSubtitle');
    var mEventId = document.getElementById('eventKaldikId');
    var mEventStatus = document.getElementById('eventKaldikStatus');
    var mEventStart = document.getElementById('eventStartDate');
    var mEventEnd = document.getElementById('eventEndDate');
    var mEventKet = document.getElementById('eventKeterangan');
    var mBtnDelete = document.getElementById('btnDeleteEventKaldik');
    var mBtnSave = document.getElementById('btnSaveEventKaldik');
    var typeCards = document.querySelectorAll('#modalEventTypePicker .rbc-type-card');

    function selectModalType(status) {
        if (!status) status = 'Efektif KBM';
        mEventStatus.value = status;
        typeCards.forEach(function(card){
            var cStatus = card.getAttribute('data-status');
            var isMatch = (cStatus === status);
            card.classList.toggle('is-active', isMatch);
        });
    }

    typeCards.forEach(function(card){
        card.addEventListener('click', function(){
            var st = card.getAttribute('data-status');
            var def = card.getAttribute('data-default') || 'KBM Reguler';
            selectModalType(st);
            if (!mEventKet.value || mEventKet.value === 'KBM Reguler' || mEventKet.value === 'Libur Nasional' || mEventKet.value === 'Libur Semester') {
                mEventKet.value = def;
            }
        });
    });

    function updateEventDurationBadge() {
        if (!mEventStart || !mEventEnd) return;
        var sDate = mEventStart.value;
        var eDate = mEventEnd.value;
        var bStart = document.getElementById('startDayNameBadge');
        var bDur = document.getElementById('rangeDurationBadge');
        if (!sDate) return;

        var d1 = parseYMD(sDate);
        if (bStart) {
            bStart.textContent = namaHariIndo[d1.getDay()];
        }

        if (!eDate || eDate < sDate) {
            eDate = sDate;
            mEventEnd.value = sDate;
        }

        var d2 = parseYMD(eDate);
        var diffTime = d2.getTime() - d1.getTime();
        var diffDays = Math.round(diffTime / (1000 * 3600 * 24)) + 1;

        if (bDur) {
            if (diffDays <= 1) {
                bDur.textContent = '1 Hari Saja';
                bDur.style.background = '#ECFDF5';
                bDur.style.color = '#047857';
            } else {
                bDur.textContent = diffDays + ' Hari (' + sDate.substring(8,10) + '/' + sDate.substring(5,7) + ' – ' + eDate.substring(8,10) + '/' + eDate.substring(5,7) + ')';
                bDur.style.background = '#EEF2FF';
                bDur.style.color = '#4338CA';
            }
        }
    }

    // Quick Duration Click Handlers in Modal
    document.querySelectorAll('.btn-quick-dur').forEach(function(btn){
        btn.addEventListener('click', function(e){
            e.preventDefault();
            var sDate = mEventStart.value;
            if (!sDate) return;
            var d1 = parseYMD(sDate);

            if (btn.dataset.days) {
                var days = parseInt(btn.dataset.days, 10);
                var targetD = new Date(d1);
                targetD.setDate(d1.getDate() + (days - 1));
                mEventEnd.value = formatYMD(targetD);
            } else if (btn.dataset.action === 'weekend') {
                var dayW = d1.getDay(); // 0 is Sunday, 6 is Saturday
                var daysToAdd = (dayW === 0) ? 6 : (6 - dayW);
                var targetD = new Date(d1);
                targetD.setDate(d1.getDate() + daysToAdd);
                mEventEnd.value = formatYMD(targetD);
            }
            updateEventDurationBadge();
        });
    });

    [mEventStart, mEventEnd].forEach(function(inp){
        if (!inp) return;
        inp.addEventListener('change', function(){
            if (inp === mEventStart) {
                mEventEnd.min = mEventStart.value;
                if (mEventEnd.value < mEventStart.value) {
                    mEventEnd.value = mEventStart.value;
                }
            }
            updateEventDurationBadge();
        });
        inp.addEventListener('click', function(){
            if (typeof this.showPicker === 'function') {
                try { this.showPicker(); } catch(e){}
            }
        });
    });

    function openEventModal(dateStr, eventObj) {
        if (!modalEvent) return;
        eventObj = eventObj || (currentKaldikState.items_by_date ? currentKaldikState.items_by_date[dateStr] : null);

        var startStr = dateStr || toYMD(new Date());
        var endStr = startStr;

        // Auto-detect multi-day contiguous range if event exists
        if (eventObj && eventObj.keterangan && currentKaldikState.items_by_date) {
            var itemsMap = currentKaldikState.items_by_date;
            // Scan backwards
            var curBack = parseYMD(startStr);
            while (true) {
                var prevD = new Date(curBack);
                prevD.setDate(curBack.getDate() - 1);
                var prevStr = formatYMD(prevD);
                var prevItem = itemsMap[prevStr];
                if (prevItem && prevItem.status_hari === eventObj.status_hari && prevItem.keterangan === eventObj.keterangan) {
                    startStr = prevStr;
                    curBack = prevD;
                } else {
                    break;
                }
            }
            // Scan forwards
            var curFwd = parseYMD(dateStr);
            while (true) {
                var nextD = new Date(curFwd);
                nextD.setDate(curFwd.getDate() + 1);
                var nextStr = formatYMD(nextD);
                var nextItem = itemsMap[nextStr];
                if (nextItem && nextItem.status_hari === eventObj.status_hari && nextItem.keterangan === eventObj.keterangan) {
                    endStr = nextStr;
                    curFwd = nextD;
                } else {
                    break;
                }
            }
        }

        mEventStart.value = startStr;
        mEventEnd.value   = endStr;
        mEventEnd.min     = startStr;
        updateEventDurationBadge();

        if (eventObj && eventObj.id) {
            mEventId.value = eventObj.id;
            mEventTitle.textContent = 'Edit Agenda Kaldik';
            mEventSubtitle.textContent = 'Perbarui detail agenda (' + startStr + (startStr !== endStr ? ' s/d ' + endStr : '') + ')';
            selectModalType(eventObj.status_hari || 'Efektif KBM');
            mEventKet.value = eventObj.keterangan || '';
            if (mBtnDelete) mBtnDelete.style.display = 'inline-flex';
        } else {
            mEventId.value = '0';
            mEventTitle.textContent = 'Tambah Agenda Kaldik';
            mEventSubtitle.textContent = 'Atur status dan agenda untuk tanggal ' + startStr;
            selectModalType(activeStatus || 'Efektif KBM');
            mEventKet.value = activeAgendaInput ? activeAgendaInput.value : 'KBM Reguler';
            if (mBtnDelete) mBtnDelete.style.display = 'none';
        }

        openModal('modalEventKaldik');
    }

    if (mBtnSave) {
        mBtnSave.addEventListener('click', function(){
            var sDate = mEventStart.value;
            var eDate = mEventEnd.value;
            var st = mEventStatus.value || 'Efektif KBM';
            var ket = mEventKet.value.trim() || 'KBM Reguler';

            if (!sDate || !eDate) {
                toast('warning', 'Tanggal mulai dan selesai wajib diisi.');
                return;
            }

            var fd = new FormData();
            fd.append('action', 'save_kaldik_batch');
            fd.append('tahun_pelajaran', activeYear);
            fd.append('semester', activeSemester);
            fd.append('start_date', sDate);
            fd.append('end_date', eDate);
            fd.append('status_hari', st);
            fd.append('keterangan', ket);

            mBtnSave.disabled = true;
            fetch(AJAX_URL, { method: 'POST', body: fd })
                .then(function(r){ return r.json(); })
                .then(function(res){
                    mBtnSave.disabled = false;
                    if (!res || !res.success) {
                        toast('error', (res && res.data && res.data.message) || 'Gagal menyimpan agenda.');
                        return;
                    }
                    toast('success', res.data.message);
                    closeModal('modalEventKaldik');
                    if (res.data && res.data.state) {
                        applyLazyState(res.data.state);
                    }
                })
                .catch(function(){
                    mBtnSave.disabled = false;
                    toast('error', 'Koneksi bermasalah.');
                });
        });
    }

    if (mBtnDelete) {
        mBtnDelete.addEventListener('click', function(){
            var idVal = parseInt(mEventId.value, 10);
            var sDate = mEventStart.value;

            askConfirm('Hapus agenda kaldik tanggal ini?', 'Hapus Agenda Kaldik', 'error').then(function(ok){
                if (!ok) return;

                var fd = new FormData();
                if (idVal > 0) {
                    fd.append('action', 'delete_kaldik_hari');
                    fd.append('id', idVal);
                } else {
                    fd.append('action', 'save_kaldik_batch');
                    fd.append('tahun_pelajaran', activeYear);
                    fd.append('semester', activeSemester);
                    fd.append('status_hari', 'DELETE');
                    fd.append('dates[]', sDate);
                }

                mBtnDelete.disabled = true;
                fetch(AJAX_URL, { method: 'POST', body: fd })
                    .then(function(r){ return r.json(); })
                    .then(function(res){
                        mBtnDelete.disabled = false;
                        if (!res || !res.success) {
                            toast('error', (res && res.data && res.data.message) || 'Gagal menghapus.');
                            return;
                        }
                        toast('success', res.data.message);
                        closeModal('modalEventKaldik');
                        if (res.data && res.data.state) {
                            applyLazyState(res.data.state);
                        }
                    })
                    .catch(function(){
                        mBtnDelete.disabled = false;
                        toast('error', 'Koneksi bermasalah.');
                    });
            });
        });
    }

    // =========================================================================
    // 7. REALTIME AGENDA SEARCH FILTER
    // =========================================================================
    var agendaSearch = document.getElementById('agendaSearchInput');
    if (agendaSearch) {
        agendaSearch.addEventListener('input', function(){
            var q = this.value.toLowerCase().trim();
            document.querySelectorAll('#tableKaldikAgenda tbody tr').forEach(function(row){
                var dt = row.getAttribute('data-date') || '';
                var ket = row.getAttribute('data-ket') || '';
                var match = (!q || dt.indexOf(q) !== -1 || ket.indexOf(q) !== -1);
                row.style.display = match ? '' : 'none';
            });
        });
    }

    // =========================================================================
    // 8. QUICK PAINTER & DRAG-TO-SELECT LOGIC
    // =========================================================================
    var activeStatus = 'Efektif KBM';
    var activeAgendaInput = document.getElementById('activeAgendaText');
    var swatches = document.querySelectorAll('#swatchGroup .kaldik-swatch-btn');
    var isKuasMode = true; // Kuas aktif secara default untuk kemudahan pewarnaan langsung
    var btnTogglePaint = document.getElementById('btnTogglePaintMode');
    var paintLabel = document.getElementById('paintModeLabel');

    function updateKuasUI(active) {
        isKuasMode = active;
        var wrap = document.getElementById('kaldikInteractiveView');
        if (wrap) wrap.classList.toggle('is-kuas-active', isKuasMode);
        if (btnTogglePaint) {
            if (isKuasMode) {
                btnTogglePaint.style.background = '#ECFDF5';
                btnTogglePaint.style.color = '#047857';
                btnTogglePaint.style.borderColor = '#10B981';
                if (paintLabel) paintLabel.textContent = 'Kuas Cepat: Aktif (' + activeStatus + ')';
            } else {
                btnTogglePaint.style.background = '#F1F5F9';
                btnTogglePaint.style.color = '#334155';
                btnTogglePaint.style.borderColor = '#CBD5E1';
                if (paintLabel) paintLabel.textContent = 'Kuas Cepat: Nonaktif (Klik = Modal)';
            }
        }
    }

    // Swatches selection listener
    swatches.forEach(function(sw){
        sw.addEventListener('click', function(){
            swatches.forEach(function(s){ s.classList.remove('is-selected'); });
            sw.classList.add('is-selected');
            activeStatus = sw.dataset.status;
            if (activeAgendaInput) activeAgendaInput.value = sw.dataset.default || 'KBM Reguler';
            updateKuasUI(true);

            // JIKA USER SUDAH MEMILIH/MENYERET TANGGAL SEBELUMNYA: LANGSUNG TERAPKAN KE TANGGAL TERSEBUT!
            if (selectedDateSet && selectedDateSet.length > 0) {
                var count = selectedDateSet.length;
                applyBatchStatus(selectedDateSet, activeStatus, activeAgendaInput ? activeAgendaInput.value : (sw.dataset.default || 'KBM Reguler'));
                toast('success', 'Status [' + activeStatus + '] diterapkan ke ' + count + ' tanggal terpilih!');
                clearDragSelection();
            } else {
                toast('info', 'Status [' + activeStatus + '] aktif. Klik tanggal mana saja untuk langsung mewarnai.');
            }
        });
    });

    if (btnTogglePaint) {
        btnTogglePaint.addEventListener('click', function(){
            updateKuasUI(!isKuasMode);
            if (isKuasMode) {
                toast('info', 'Mode Kuas Cepat Aktif: Klik tanggal untuk langsung mewarnai.');
            } else {
                toast('info', 'Mode Detail Aktif: Klik tanggal untuk membuka form modal agenda.');
            }
        });
    }

    // Inisialisasi Kuas Aktif sejak awal
    updateKuasUI(true);

    var isDragging = false;
    var dragStartDate = null;
    var selectedDateSet = [];
    var floatingBar = document.getElementById('dragFloatingBar');
    var floatingCountBadge = document.getElementById('dragFloatingCountBadge');
    var floatingMsg = document.getElementById('dragFloatingMsg');
    var dragFloatingKetInput = document.getElementById('dragFloatingKetInput');

    function parseYMD(s) {
        var parts = s.split('-');
        return new Date(parseInt(parts[0], 10), parseInt(parts[1], 10) - 1, parseInt(parts[2], 10));
    }
    function formatYMD(d) {
        return d.getFullYear() + '-' + pad2(d.getMonth() + 1) + '-' + pad2(d.getDate());
    }

    function highlightRange(startStr, endStr) {
        var d1 = parseYMD(startStr);
        var d2 = parseYMD(endStr);
        if (d1 > d2) { var tmp = d1; d1 = d2; d2 = tmp; }

        selectedDateSet = [];
        var cur = new Date(d1);
        while (cur <= d2) {
            selectedDateSet.push(formatYMD(cur));
            cur.setDate(cur.getDate() + 1);
        }

        document.querySelectorAll('.kaldik-cell[data-date]').forEach(function(cell){
            var cDate = cell.getAttribute('data-date');
            cell.classList.toggle('is-selecting', selectedDateSet.indexOf(cDate) !== -1);
        });
    }

    function clearDragSelection() {
        selectedDateSet = [];
        document.querySelectorAll('.kaldik-cell.is-selecting').forEach(function(c){
            c.classList.remove('is-selecting');
        });
        if (floatingBar) floatingBar.classList.remove('show');
    }

    function rebindCalendarEvents() {
        document.querySelectorAll('.kaldik-cell[data-date]').forEach(function(cell){
            cell.onmousedown = function(e){
                if (e.button !== 0) return;
                // Cegah seleksi teks default browser
                e.preventDefault();
                isDragging = true;
                dragStartDate = cell.getAttribute('data-date');
                highlightRange(dragStartDate, dragStartDate);
            };

            cell.onmouseenter = function(){
                if (!isDragging || !dragStartDate) return;
                var currentHoverDate = cell.getAttribute('data-date');
                highlightRange(dragStartDate, currentHoverDate);
            };

            // Klik tanggal pada kalender
            cell.onclick = function(e){
                var dt = cell.getAttribute('data-date');
                if (!dt) return;

                // Cek apakah hari Minggu
                var parts = dt.split('-');
                var cellDate = new Date(parseInt(parts[0], 10), parseInt(parts[1], 10) - 1, parseInt(parts[2], 10));
                var isSunday = (cellDate.getDay() === 0);

                // Jika Kuas Aktif: Langsung warnai tanggal tersebut secara instant!
                if (isKuasMode) {
                    var statusToApply = activeStatus;
                    var ketToApply = activeAgendaInput ? activeAgendaInput.value.trim() : 'KBM Reguler';

                    // Minggu WAJIB TETAP MERAH / LIBUR
                    if (isSunday && statusToApply === 'Efektif KBM') {
                        toast('warning', 'Hari Minggu adalah hari libur (tidak ada KBM di hari Minggu).');
                        statusToApply = 'Libur Semester';
                        ketToApply = 'Libur Akhir Pekan (Minggu)';
                    }

                    applyBatchStatus([dt], statusToApply, ketToApply || 'KBM Reguler');
                    clearDragSelection();
                } else {
                    // Jika Kuas Nonaktif: Buka modal detail
                    var itemsMap = currentKaldikState.items_by_date || {};
                    openEventModal(dt, itemsMap[dt]);
                }
            };

            // Double klik pada tanggal selalu membuka detail modal
            cell.ondblclick = function(e){
                e.stopPropagation();
                var dt = cell.getAttribute('data-date');
                var itemsMap = currentKaldikState.items_by_date || {};
                openEventModal(dt, itemsMap[dt]);
            };

            // Klik khusus pada event chip (pill agenda) membuka modal
            var chip = cell.querySelector('.rbc-event-chip');
            if (chip) {
                chip.onclick = function(e){
                    e.stopPropagation();
                    var dt = cell.getAttribute('data-date');
                    var itemsMap = currentKaldikState.items_by_date || {};
                    openEventModal(dt, itemsMap[dt]);
                };
            }
        });

        // Edit button on agenda table rows
        document.querySelectorAll('.btn-agenda-edit').forEach(function(btn){
            btn.onclick = function(e){
                e.stopPropagation();
                var dt = btn.getAttribute('data-date');
                var itemsMap = currentKaldikState.items_by_date || {};
                openEventModal(dt, itemsMap[dt]);
            };
        });
    }

    rebindCalendarEvents();

    document.addEventListener('mouseup', function(){
        if (!isDragging) return;
        isDragging = false;
        if (selectedDateSet.length === 0) return;

        // Jika user menyeret lebih dari 1 tanggal (rentang multi-hari):
        if (selectedDateSet.length > 1) {
            if (floatingCountBadge) floatingCountBadge.textContent = selectedDateSet.length + ' Hari';
            if (floatingMsg) floatingMsg.textContent = selectedDateSet.length + ' Hari Dipilih (' + selectedDateSet[0] + ' s/d ' + selectedDateSet[selectedDateSet.length - 1] + ')';
            if (dragFloatingKetInput && activeAgendaInput) dragFloatingKetInput.value = activeAgendaInput.value;
            if (floatingBar) floatingBar.classList.add('show');
        } else {
            // 1 hari dilepas: akan ditangani oleh cell.onclick
            if (!isKuasMode) {
                clearDragSelection();
            }
        }
    });

    // Floating bar quick status buttons
    document.querySelectorAll('.btn-drag-quick-status').forEach(function(btn){
        btn.addEventListener('click', function(){
            var st = btn.dataset.status;
            var ket = (dragFloatingKetInput && dragFloatingKetInput.value.trim()) ? dragFloatingKetInput.value.trim() : (btn.dataset.default || 'KBM Reguler');
            if (selectedDateSet.length > 0) {
                applyBatchStatus(selectedDateSet, st, ket);
            }
        });
    });

    var btnApplyDragged = document.getElementById('btnApplyDragged');
    if (btnApplyDragged) {
        btnApplyDragged.addEventListener('click', function(){
            if (selectedDateSet.length > 0) {
                var ket = (dragFloatingKetInput && dragFloatingKetInput.value.trim()) ? dragFloatingKetInput.value.trim() : (activeAgendaInput ? activeAgendaInput.value : 'KBM Reguler');
                applyBatchStatus(selectedDateSet, activeStatus, ket);
            }
        });
    }

    var btnCancelDragged = document.getElementById('btnCancelDragged');
    if (btnCancelDragged) {
        btnCancelDragged.addEventListener('click', function(){
            clearDragSelection();
        });
    }

    // =========================================================================
    // 9. IN-PLACE LAZY STATE APPLIER (ZERO RELOAD / FULL REACTIVE)
    // =========================================================================
    function renderTableAgenda(items) {
        var tbody = document.querySelector('#tableKaldikAgenda tbody');
        if (!tbody) return;
        if (!items || items.length === 0) {
            tbody.innerHTML = '<tr><td colspan="7" style="text-align: center; padding: 36px 20px; color: #64748B;">Belum ada data kalender pendidikan.</td></tr>';
            var counter = document.getElementById('agendaTotalCounter');
            if (counter) counter.textContent = '0 Tanggal';
            return;
        }

        var counterEl = document.getElementById('agendaTotalCounter');
        if (counterEl) counterEl.textContent = items.length + ' Tanggal';

        var html = '';
        items.forEach(function(kh, idx){
            var st = kh.status_hari;
            var badgeSt = 'badge-status--kbm';
            if (st === 'Libur Nasional') badgeSt = 'badge-status--libur';
            else if (st === 'Libur Semester') badgeSt = 'badge-status--libur';
            else if (st === 'Kegiatan Sekolah/Ujian') badgeSt = 'badge-status--kegiatan';
            else if (st === 'Libur Khusus Keagamaan') badgeSt = 'badge-status--keagamaan';

            var dtParts = kh.tanggal.split('-');
            var dtFmt = dtParts.length === 3 ? (dtParts[2] + '-' + dtParts[1] + '-' + dtParts[0]) : kh.tanggal;

            html += '<tr data-date="' + kh.tanggal + '" data-ket="' + escapeHtml((kh.keterangan || '').toLowerCase()) + '">' +
                '<td style="text-align: center; font-weight: 700; color: #64748B;">' + (idx + 1) + '</td>' +
                '<td><strong style="color: #1E293B; font-family: monospace; font-size: 13px;">' + escapeHtml(dtFmt) + '</strong></td>' +
                '<td><span class="kaldik-hijri-num" style="font-size: 11px;">' + escapeHtml(kh.hijri || '-') + '</span></td>' +
                '<td><span class="ilmuna-badge ilmuna-badge--teal" style="font-size: 11px;">' + escapeHtml(kh.hari || '') + '</span></td>' +
                '<td><span class="badge-status ' + badgeSt + '">' + escapeHtml(st) + '</span></td>' +
                '<td><strong style="color: #334155; font-size: 13px;">' + escapeHtml(kh.keterangan || '-') + '</strong></td>' +
                '<td style="text-align: center;">' +
                    '<div style="display: flex; align-items: center; justify-content: center; gap: 4px;">' +
                        '<button type="button" class="ilmuna-btn-icon btn-agenda-edit" data-date="' + kh.tanggal + '" data-id="' + kh.id + '" title="Edit Agenda">' +
                            '<iconify-icon icon="solar:pen-bold" width="15" style="color: #0D7A87;"></iconify-icon>' +
                        '</button>' +
                        '<button type="button" class="ilmuna-btn-icon btn-delete-single" data-id="' + kh.id + '" title="Hapus">' +
                            '<iconify-icon icon="solar:trash-bin-trash-bold" width="15" style="color: #EF4444;"></iconify-icon>' +
                        '</button>' +
                    '</div>' +
                '</td>' +
            '</tr>';
        });
        tbody.innerHTML = html;

        tbody.querySelectorAll('.btn-agenda-edit').forEach(function(btn){
            btn.onclick = function(e){
                e.stopPropagation();
                var dt = btn.getAttribute('data-date');
                var itemsMap = currentKaldikState.items_by_date || {};
                openEventModal(dt, itemsMap[dt]);
            };
        });
    }

    function applyLazyState(state) {
        if (!state) return;
        currentKaldikState = state;

        // 1. Update stats badges
        if (state.stats) {
            var s = state.stats;
            var setT = function(id, val){ var el = document.getElementById(id); if (el) el.textContent = val; };
            setT('statCountHE', s.count_he);
            setT('statCountLiburNas', s.count_libur_nas);
            setT('statCountLiburSem', s.count_libur_sem);
            setT('statCountKegiatan', s.count_kegiatan);
            setT('statCountKeagamaan', s.count_keagamaan);
            setT('badgeTabKaldik', s.total_dates);
            setT('badgeTabRpe', s.grand_pe + ' Pekan');
        }

        // 2. Map items by date
        var itemMap = state.items_by_date || {};
        if (!state.items_by_date && state.items) {
            itemMap = {};
            state.items.forEach(function(it){ itemMap[it.tanggal] = it; });
            currentKaldikState.items_by_date = itemMap;
        }

        // 3. Update all calendar cells in Month View in-place
        document.querySelectorAll('.kaldik-cell[data-date]').forEach(function(cell){
            var dt = cell.getAttribute('data-date');
            var item = itemMap[dt];

            var chip = cell.querySelector('.rbc-event-chip');
            if (!chip) {
                var cWrap = cell.querySelector('.rbc-chips-wrap');
                if (!cWrap) {
                    cWrap = document.createElement('div');
                    cWrap.className = 'rbc-chips-wrap';
                    cell.appendChild(cWrap);
                }
                chip = document.createElement('div');
                chip.className = 'rbc-event-chip';
                chip.setAttribute('data-date', dt);
                chip.innerHTML = '<span class="rbc-chip-dot"></span><span class="rbc-chip-title"></span>';
                cWrap.appendChild(chip);
            }

            var st = item ? item.status_hari : 'Efektif KBM';
            var ket = item ? (item.keterangan || st) : 'KBM Reguler';
            var chipCls = getEventChipClass(st, ket);

            chip.className = 'rbc-event-chip ' + chipCls;
            var titleSpan = chip.querySelector('.rbc-chip-title');
            if (titleSpan) titleSpan.textContent = ket;

            cell.setAttribute('data-status', st);
            cell.setAttribute('data-ket', ket);
            cell.setAttribute('data-id', item ? (item.id || '0') : '0');
            cell.setAttribute('title', dt + ' \nStatus: ' + st + '\nAgenda: ' + ket);
        });

        // 4. Update month pills HE indicators & RPE table rows
        if (state.rpe && Array.isArray(state.rpe)) {
            state.rpe.forEach(function(r){
                var pillSub = document.querySelector('.kaldik-month-pill-btn[data-month="' + r.mDigit + '"] .kaldik-pill-he');
                if (pillSub) pillSub.textContent = r.total_he + ' HE';

                var rRow = document.getElementById('rpeRow-' + r.urutan);
                if (rRow) {
                    var cSen = rRow.querySelector('.rpe-cell-senin'); if (cSen) cSen.textContent = r.senin;
                    var cSel = rRow.querySelector('.rpe-cell-selasa'); if (cSel) cSel.textContent = r.selasa;
                    var cRab = rRow.querySelector('.rpe-cell-rabu'); if (cRab) cRab.textContent = r.rabu;
                    var cKam = rRow.querySelector('.rpe-cell-kamis'); if (cKam) cKam.textContent = r.kamis;
                    var cJum = rRow.querySelector('.rpe-cell-jumat'); if (cJum) cJum.textContent = r.jumat;
                    var cSab = rRow.querySelector('.rpe-cell-sabtu'); if (cSab) cSab.textContent = r.sabtu;
                    var cTotHe = rRow.querySelector('.rpe-cell-total-he'); if (cTotHe) cTotHe.textContent = r.total_he;
                    var cPekTot = rRow.querySelector('.rpe-cell-pekan-total'); if (cPekTot) cPekTot.textContent = r.pekan_total;
                    var cPekEf = rRow.querySelector('.rpe-cell-pekan-efektif'); if (cPekEf) cPekEf.textContent = r.pekan_efektif;
                    var cPekNon = rRow.querySelector('.rpe-cell-pekan-non-efektif'); if (cPekNon) cPekNon.textContent = r.pekan_tidak_efektif;
                    var cKet = rRow.querySelector('.rpe-cell-keterangan'); if (cKet) cKet.textContent = r.keterangan || '-';
                }
            });
        }

        // 5. Update RPE footer summary totals
        if (state.rpe_totals) {
            var rt = state.rpe_totals;
            var setTxt = function(id, val){ var el = document.getElementById(id); if (el) el.textContent = val; };
            setTxt('rpeTotalSen', rt.senin);
            setTxt('rpeTotalSel', rt.selasa);
            setTxt('rpeTotalRab', rt.rabu);
            setTxt('rpeTotalKam', rt.kamis);
            setTxt('rpeTotalJum', rt.jumat);
            setTxt('rpeTotalSab', rt.sabtu);
            setTxt('rpeTotalHE', rt.total_he + ' Hari');
            setTxt('rpeTotalPekan', rt.pekan_total);
            setTxt('rpeTotalPE', rt.pekan_efektif + ' Pekan');
            setTxt('rpeTotalPTE', rt.pekan_tidak_efektif);
        }

        // 6. Refresh current active view
        if (currentView === 'week') renderWeekView();
        if (currentView === 'day') renderDayView();

        // 7. Refresh agenda table
        renderTableAgenda(state.items || []);
    }

    // Function to apply status in batch via AJAX with Optimistic UI
    function applyBatchStatus(datesArr, status, keterangan) {
        if (!datesArr || datesArr.length === 0) return;

        // 1. Optimistic UI Update: Langsung ubah visual kalender seketika (zero lag)
        var chipCls = getEventChipClass(status, keterangan);
        datesArr.forEach(function(dt){
            var cell = document.querySelector('.kaldik-cell[data-date="' + dt + '"]');
            if (cell) {
                cell.setAttribute('data-status', status);
                cell.setAttribute('data-ket', keterangan);
                var chip = cell.querySelector('.rbc-event-chip');
                if (chip) {
                    chip.className = 'rbc-event-chip ' + chipCls;
                    var t = chip.querySelector('.rbc-chip-title');
                    if (t) t.textContent = keterangan || status;
                }
            }
        });

        // 2. Kirim update ke server via AJAX
        var fd = new FormData();
        fd.append('action', 'save_kaldik_batch');
        fd.append('tahun_pelajaran', activeYear);
        fd.append('semester', activeSemester);
        fd.append('status_hari', status);
        fd.append('keterangan', keterangan);
        datesArr.forEach(function(d){ fd.append('dates[]', d); });

        fetch(AJAX_URL, { method: 'POST', body: fd })
            .then(function(r){ return r.json(); })
            .then(function(res){
                if (!res || !res.success) {
                    toast('error', (res && res.data && res.data.message) || 'Gagal menyimpan perubahan.');
                    return;
                }
                toast('success', res.data.message);
                if (res.data && res.data.state) {
                    applyLazyState(res.data.state);
                }
                clearDragSelection();
            })
            .catch(function(){ toast('error', 'Koneksi ke server bermasalah.'); });
    }

    // =========================================================================
    // 10. MODAL RENTANG TANGGAL (MANUAL DATEPICKER)
    // =========================================================================
    var btnOpenModalRange = document.getElementById('btnOpenModalRange');
    if (btnOpenModalRange) {
        btnOpenModalRange.addEventListener('click', function(){
            openModal('modalRangeKaldik');
        });
    }

    var btnSubmitRange = document.getElementById('btnSubmitRange');
    if (btnSubmitRange) {
        btnSubmitRange.addEventListener('click', function(){
            var btn = this;
            var sDate = document.getElementById('rangeStartDate').value;
            var eDate = document.getElementById('rangeEndDate').value;
            var status = document.getElementById('rangeStatus').value;
            var ket = document.getElementById('rangeKeterangan').value;

            if (!sDate || !eDate) {
                toast('warning', 'Tanggal mulai dan selesai wajib dipilih.');
                return;
            }

            var fd = new FormData();
            fd.append('action', 'save_kaldik_batch');
            fd.append('tahun_pelajaran', activeYear);
            fd.append('semester', activeSemester);
            fd.append('start_date', sDate);
            fd.append('end_date', eDate);
            fd.append('status_hari', status);
            fd.append('keterangan', ket);

            btn.disabled = true;
            fetch(AJAX_URL, { method: 'POST', body: fd })
                .then(function(r){ return r.json(); })
                .then(function(res){
                    btn.disabled = false;
                    if (!res || !res.success) {
                        toast('error', (res && res.data && res.data.message) || 'Gagal menerapkan rentang.');
                        return;
                    }
                    toast('success', res.data.message);
                    closeModal('modalRangeKaldik');
                    if (res.data && res.data.state) {
                        applyLazyState(res.data.state);
                    }
                })
                .catch(function(){
                    btn.disabled = false;
                    toast('error', 'Koneksi bermasalah.');
                });
        });
    }

    // =========================================================================
    // 11. OTOMATIS TARIK LIBUR NASIONAL & HARI BESAR ISLAM
    // =========================================================================
    var btnAutoLiburAll = document.getElementById('btnAutoLiburAll');
    if (btnAutoLiburAll) {
        btnAutoLiburAll.addEventListener('click', function(){
            var btn = this;
            var promptMsg = 'Tarik libur nasional resmi (API SKB 3 Menteri) dan tandai seluruh hari besar Islam & Ramadhan (Kalender Islam Falakiyah NU Online) untuk semester ' + activeSemester + ' (' + activeYear + ')?';

            askConfirm(promptMsg, 'Sinkronisasi Libur Nasional & Kalender Islam', 'warning').then(function(ok){
                if (!ok) return;

                btn.disabled = true;
                btn.textContent = 'Menarik Data API...';
                var fd = new FormData();
                fd.append('action', 'import_libur_nasional');
                fd.append('tahun_pelajaran', activeYear);
                fd.append('semester', activeSemester);

                fetch(AJAX_URL, { method: 'POST', body: fd })
                    .then(function(r){ return r.json(); })
                    .then(function(res){
                        btn.disabled = false;
                        btn.textContent = 'Tarik Libur Nasional & Kalender Islam NU Online (API)';
                        if (!res || !res.success) {
                            toast('error', (res && res.data && res.data.message) || 'Gagal impor libur.');
                            return;
                        }
                        toast('success', res.data.message);
                        if (res.data && res.data.state) {
                            applyLazyState(res.data.state);
                        }
                    })
                    .catch(function(){
                        btn.disabled = false;
                        btn.textContent = 'Tarik Libur Nasional & Kalender Islam NU Online (API)';
                        toast('error', 'Gagal memproses libur nasional.');
                    });
            });
        });
    }

    // =========================================================================
    // 12. GENERATE 1 SEMESTER PENUH
    // =========================================================================
    var btnOpenBulkGen = document.getElementById('btnOpenBulkGen');
    if (btnOpenBulkGen) {
        btnOpenBulkGen.addEventListener('click', function(){
            openModal('modalBulkGen');
        });
    }

    var btnSubmitBulkGen = document.getElementById('btnSubmitBulkGen');
    if (btnSubmitBulkGen) {
        btnSubmitBulkGen.addEventListener('click', function(){
            var btn = this;
            var fd = new FormData();
            fd.append('action', 'generate_kaldik_semester');
            fd.append('tahun_pelajaran', activeYear);
            fd.append('semester', activeSemester);
            fd.append('libur_sabtu', document.getElementById('genLiburSabtu').value);

            btn.disabled = true;
            btn.textContent = 'Memproses...';
            fetch(AJAX_URL, { method: 'POST', body: fd })
                .then(function(r){ return r.json(); })
                .then(function(res){
                    btn.disabled = false;
                    btn.textContent = 'Generate Sekarang';
                    if (!res || !res.success) {
                        toast('error', (res && res.data && res.data.message) || 'Gagal generate kalender.');
                        return;
                    }
                    toast('success', res.data.message);
                    closeModal('modalBulkGen');
                    if (res.data && res.data.state) {
                        applyLazyState(res.data.state);
                    }
                })
                .catch(function(){
                    btn.disabled = false;
                    btn.textContent = 'Generate Sekarang';
                    toast('error', 'Koneksi bermasalah.');
                });
        });
    }

    // =========================================================================
    // 12.1 EKSPOR KALENDER PENDIDIKAN & RPE (METODE BLOB: EXCEL & PDF)
    // =========================================================================
    var btnExportKaldikExcel = document.getElementById('btnExportKaldikExcel');
    if (btnExportKaldikExcel) {
        btnExportKaldikExcel.addEventListener('click', function(){
            var btn = this;
            var originalHtml = btn.innerHTML;
            btn.disabled = true;
            btn.innerHTML = '<iconify-icon icon="line-md:loading-loop" width="16"></iconify-icon><span>Mengunduh...</span>';

            var targetUrl = 'kaldik-excel.php?tahun=' + encodeURIComponent(activeYear);
            fetch(targetUrl)
                .then(function(res){
                    if (!res.ok) throw new Error('Gagal mengunduh berkas Excel (Kode ' + res.status + ')');
                    var disp = res.headers.get('Content-Disposition') || '';
                    var filename = 'kalender-pendidikan-rpe-' + activeYear.replace('/', '-') + '.xlsx';
                    var m = disp.match(/filename="?([^"]+)"?/);
                    if (m && m[1]) filename = m[1];
                    return res.blob().then(function(blob){ return { blob: blob, filename: filename }; });
                })
                .then(function(data){
                    var blobUrl = window.URL.createObjectURL(data.blob);
                    var a = document.createElement('a');
                    a.href = blobUrl;
                    a.download = data.filename;
                    document.body.appendChild(a);
                    a.click();
                    setTimeout(function(){
                        document.body.removeChild(a);
                        window.URL.revokeObjectURL(blobUrl);
                    }, 500);

                    toast('success', 'Kalender Pendidikan & RPE berhasil diekspor ke Excel!');
                })
                .catch(function(err){
                    toast('error', err.message || 'Gagal mengekspor Excel.');
                })
                .finally(function(){
                    btn.disabled = false;
                    btn.innerHTML = originalHtml;
                });
        });
    }

    var btnExportKaldikPdf = document.getElementById('btnExportKaldikPdf');
    if (btnExportKaldikPdf) {
        btnExportKaldikPdf.addEventListener('click', function(){
            var btn = this;
            var originalHtml = btn.innerHTML;
            btn.disabled = true;
            btn.innerHTML = '<iconify-icon icon="line-md:loading-loop" width="16"></iconify-icon><span>Mengunduh PDF...</span>';

            var targetUrl = 'kaldik-pdf.php?tahun=' + encodeURIComponent(activeYear);
            fetch(targetUrl)
                .then(function(res){
                    if (!res.ok) throw new Error('Gagal mengunduh berkas PDF (Kode ' + res.status + ')');
                    var disp = res.headers.get('Content-Disposition') || '';
                    var filename = 'kalender-pendidikan-rpe-' + activeYear.replace('/', '-') + '.pdf';
                    var m = disp.match(/filename="?([^"]+)"?/);
                    if (m && m[1]) filename = m[1];
                    return res.blob().then(function(blob){ return { blob: blob, filename: filename }; });
                })
                .then(function(data){
                    var blobUrl = window.URL.createObjectURL(data.blob);
                    var a = document.createElement('a');
                    a.href = blobUrl;
                    a.download = data.filename;
                    document.body.appendChild(a);
                    a.click();
                    setTimeout(function(){
                        document.body.removeChild(a);
                        window.URL.revokeObjectURL(blobUrl);
                    }, 500);

                    toast('success', 'Kalender Pendidikan & RPE berhasil diunduh ke PDF!');
                })
                .catch(function(err){
                    toast('error', err.message || 'Gagal mengunduh PDF.');
                })
                .finally(function(){
                    btn.disabled = false;
                    btn.innerHTML = originalHtml;
                });
        });
    }

    // =========================================================================
    // 13. SINKRONISASI MANUAL KE SELURUH KBM
    // =========================================================================
    var btnSyncKaldikTop = document.getElementById('btnSyncKaldikTop');
    if (btnSyncKaldikTop) {
        btnSyncKaldikTop.addEventListener('click', function(){
            var btn = this;
            var fd = new FormData();
            fd.append('action', 'sync_kaldik_ke_kbm');
            fd.append('tahun_pelajaran', activeYear);
            fd.append('semester', activeSemester);

            btn.disabled = true;
            fetch(AJAX_URL, { method: 'POST', body: fd })
                .then(function(r){ return r.json(); })
                .then(function(res){
                    btn.disabled = false;
                    if (!res || !res.success) {
                        toast('error', (res && res.data && res.data.message) || 'Gagal sinkronisasi.');
                        return;
                    }
                    toast('success', res.data.message);
                    if (res.data && res.data.state) {
                        applyLazyState(res.data.state);
                    }
                })
                .catch(function(){
                    btn.disabled = false;
                    toast('error', 'Koneksi bermasalah.');
                });
        });
    }

    // Delete single agenda from table view
    document.addEventListener('click', function(e){
        var delBtn = e.target.closest('.btn-delete-single');
        if (delBtn) {
            var idH = delBtn.dataset.id;
            askConfirm('Hapus tanggal ini dari Kalender Pendidikan?', 'Hapus Hari Kaldik', 'error').then(function(ok){
                if (!ok) return;

                var fd = new FormData();
                fd.append('action', 'delete_kaldik_hari');
                fd.append('id', idH);
                fetch(AJAX_URL, { method: 'POST', body: fd })
                    .then(function(r){ return r.json(); })
                    .then(function(res){
                        if (!res || !res.success) {
                            toast('error', (res && res.data && res.data.message) || 'Gagal menghapus.');
                            return;
                        }
                        toast('success', res.data.message);
                        if (res.data && res.data.state) {
                            applyLazyState(res.data.state);
                        }
                    })
                    .catch(function(){ toast('error', 'Koneksi bermasalah.'); });
            });
        }
    });

    // =========================================================================
    // 14. FILTER TAHUN PELAJARAN & SEMESTER (ULTRA-FAST IN-MEMORY CACHE, SAT-SET 0MS)
    // =========================================================================
    var semesterCache = {};
    var isSwitchingSem = false;

    function applySemesterData(d) {
        activeYear = d.tahun;
        activeSemester = d.semester;
        calYear = d.calYear;
        monthKeys = d.monthKeys || [];
        currentKaldikState = d.state || {};

        // 1. Update Month Pills HTML
        var pBar = document.getElementById('rbcMonthPillsBar');
        if (pBar && d.pillsHtml) {
            pBar.innerHTML = d.pillsHtml;
        }

        // 2. Update Month Panes HTML
        var vMonth = document.getElementById('rbcViewMonth');
        if (vMonth && d.panesHtml) {
            vMonth.innerHTML = d.panesHtml;
        }

        // 3. Update RPE Table Rows HTML
        var rpeBody = document.getElementById('rpeTableBody');
        if (rpeBody && d.rpeRowsHtml) {
            rpeBody.innerHTML = d.rpeRowsHtml;
        }

        // 4. Update RPE Totals Footer
        if (d.rpeTotals) {
            var rt = d.rpeTotals;
            var setTxt = function(id, val){ var el = document.getElementById(id); if (el) el.textContent = val; };
            setTxt('rpeTotalSen', rt.senin);
            setTxt('rpeTotalSel', rt.selasa);
            setTxt('rpeTotalRab', rt.rabu);
            setTxt('rpeTotalKam', rt.kamis);
            setTxt('rpeTotalJum', rt.jumat);
            setTxt('rpeTotalSab', rt.sabtu);
            setTxt('rpeTotalHE', rt.total_he + ' Hari');
            setTxt('rpeTotalPekan', rt.pekan_total);
            setTxt('rpeTotalPE', rt.pekan_efektif + ' Pekan');
            setTxt('rpeTotalPTE', rt.pekan_tidak_efektif);
        }

        // 5. Update Stats Badges
        if (d.state && d.state.stats) {
            var s = d.state.stats;
            var setS = function(id, val){ var el = document.getElementById(id); if (el) el.textContent = val; };
            setS('statCountHE', s.count_he);
            setS('statCountLiburNas', s.count_libur_nas);
            setS('statCountLiburSem', s.count_libur_sem);
            setS('statCountKegiatan', s.count_kegiatan);
            setS('statCountKeagamaan', s.count_keagamaan);
            setS('badgeTabKaldik', s.total_dates);
            setS('badgeTabRpe', s.grand_pe + ' Pekan');
        }

        // 6. Update titles and labels
        var semTitleEl = document.getElementById('agendaKaldikSemTitle');
        if (semTitleEl) semTitleEl.textContent = 'Rincian Agenda Kaldik Semester ' + activeSemester;
        var rpeBadgeEl = document.getElementById('badgeRpeSemesterTitle');
        if (rpeBadgeEl) rpeBadgeEl.textContent = 'Semester ' + activeSemester + ' ' + activeYear;
        var rpeFootEl = document.getElementById('rpeFooterSemTitle');
        if (rpeFootEl) rpeFootEl.textContent = 'Rekapitulasi Kaldik Semester ' + activeSemester;

        // 7. Set active month & update view
        currentMonthNum = d.defaultActiveMonth;
        currentDate = new Date(calYear, currentMonthNum - 1, 1);

        // Rebind click/drag/modal events to newly rendered cells
        rebindCalendarEvents();

        // Switch to default active month & refresh active view
        switchActiveMonth(currentMonthNum);
        if (currentView !== 'month') {
            switchView(currentView);
        }

        // Render agenda table
        renderTableAgenda(d.state.items || []);
    }

    function switchSemester(newTahun, newSem, pushUrl) {
        newTahun = newTahun || activeYear;
        newSem = newSem || activeSemester;

        if (isSwitchingSem && newTahun === activeYear && newSem === activeSemester) return;

        // 1. SAT-SET INSTANT VISUAL TOGGLE PADA TOMBOL SEMESTER (0ms)
        document.querySelectorAll('.ilmuna-sem-pill-btn').forEach(function(btn){
            var bSem = btn.getAttribute('data-semester') || (btn.href.indexOf('Genap') !== -1 ? 'Genap' : 'Ganjil');
            var isSel = (bSem === newSem);
            btn.classList.remove('is-active', 'is-ganjil', 'is-genap');
            if (isSel) {
                btn.classList.add('is-active');
                btn.classList.add(newSem === 'Genap' ? 'is-genap' : 'is-ganjil');
            }
            btn.href = '<?= BASE_URL ?>/dashboard/admin/curriculum.php?tahun=' + encodeURIComponent(newTahun) + '&semester=' + encodeURIComponent(bSem);
        });

        // Update dropdown tahun pelajaran
        var fTh = document.getElementById('filterTahun');
        if (fTh && fTh.value !== newTahun) fTh.value = newTahun;

        // Update URL di browser history tanpa page reload
        if (pushUrl !== false) {
            var newUrl = '<?= BASE_URL ?>/dashboard/admin/curriculum.php?tahun=' + encodeURIComponent(newTahun) + '&semester=' + encodeURIComponent(newSem);
            window.history.pushState({ tahun: newTahun, semester: newSem }, '', newUrl);
        }

        var cacheKey = newTahun + '_' + newSem;

        // 2. JIKA DATA SUDAH ADA DI CACHE MEMORI: LANGSUNG TERAPKAN SEKETIKA (0ms / SAT-SET MURNI)!
        if (semesterCache[cacheKey]) {
            applySemesterData(semesterCache[cacheKey]);
            toast('success', 'Semester ' + newSem + ' (' + newTahun + ') aktif.');
            return;
        }

        // 3. JIKA BELUM TER-CACHE: AMBIL DARI ENDPOINT AJAX DENGAN EFEK HALUS
        isSwitchingSem = true;
        var pBar = document.getElementById('rbcMonthPillsBar');
        var vMonth = document.getElementById('rbcViewMonth');
        if (pBar) pBar.style.opacity = '0.6';
        if (vMonth) vMonth.style.opacity = '0.6';

        var apiUrl = AJAX_URL + '?ajax=get_semester_full&tahun=' + encodeURIComponent(newTahun) + '&semester=' + encodeURIComponent(newSem);

        fetch(apiUrl)
            .then(function(r){ return r.json(); })
            .then(function(res){
                isSwitchingSem = false;
                if (pBar) pBar.style.opacity = '1';
                if (vMonth) vMonth.style.opacity = '1';
                if (!res || !res.success || !res.data) {
                    toast('error', 'Gagal memuat data semester.');
                    return;
                }

                var d = res.data;
                semesterCache[cacheKey] = d;
                applySemesterData(d);
                toast('success', 'Semester ' + activeSemester + ' (' + activeYear + ') aktif.');
            })
            .catch(function(err){
                isSwitchingSem = false;
                if (pBar) pBar.style.opacity = '1';
                if (vMonth) vMonth.style.opacity = '1';
                console.error(err);
                toast('error', 'Koneksi bermasalah saat memuat semester.');
            });
    }

    // Seed memori cache dengan data semester saat ini
    semesterCache[activeYear + '_' + activeSemester] = {
        tahun: activeYear,
        semester: activeSemester,
        calYear: calYear,
        defaultActiveMonth: currentMonthNum,
        monthKeys: monthKeys,
        state: currentKaldikState,
        pillsHtml: document.getElementById('rbcMonthPillsBar') ? document.getElementById('rbcMonthPillsBar').innerHTML : '',
        panesHtml: document.getElementById('rbcViewMonth') ? document.getElementById('rbcViewMonth').innerHTML : '',
        rpeRowsHtml: document.getElementById('rpeTableBody') ? document.getElementById('rpeTableBody').innerHTML : '',
        rpeTotals: currentKaldikState.rpe_totals || {}
    };

    // Pre-fetch semester seberang secara diam-diam (background ~15ms)
    // Sehingga ketika user klik tombol semester berikutnya, prosesnya 100% INSTAN 0ms!
    setTimeout(function(){
        var otherSem = (activeSemester === 'Ganjil') ? 'Genap' : 'Ganjil';
        var prefetchKey = activeYear + '_' + otherSem;
        if (!semesterCache[prefetchKey]) {
            var prefetchUrl = AJAX_URL + '?ajax=get_semester_full&tahun=' + encodeURIComponent(activeYear) + '&semester=' + encodeURIComponent(otherSem);
            fetch(prefetchUrl)
                .then(function(r){ return r.json(); })
                .then(function(res){
                    if (res && res.success && res.data) {
                        semesterCache[prefetchKey] = res.data;
                    }
                })
                .catch(function(){});
        }
    }, 150);

    // Delegated click for semester pills (always works, ultra smooth, no full reload)
    document.addEventListener('click', function(e){
        var semBtn = e.target.closest('.ilmuna-sem-pill-btn');
        if (semBtn) {
            e.preventDefault();
            var targetSem = semBtn.getAttribute('data-semester') || (semBtn.href.indexOf('Genap') !== -1 ? 'Genap' : 'Ganjil');
            var fTh = document.getElementById('filterTahun');
            var targetTahun = fTh ? fTh.value : activeYear;
            if (targetSem === activeSemester && targetTahun === activeYear) return;
            switchSemester(targetTahun, targetSem, true);
        }
    });

    var filterThEl = document.getElementById('filterTahun');
    if (filterThEl) {
        filterThEl.addEventListener('change', function(){
            switchSemester(this.value, activeSemester, true);
        });
    }

    // Browser back/forward navigation support
    window.addEventListener('popstate', function(e){
        var params = new URLSearchParams(window.location.search);
        var t = params.get('tahun') || activeYear;
        var s = params.get('semester') || activeSemester;
        if (t !== activeYear || s !== activeSemester) {
            switchSemester(t, s, false);
        }
    });

    // =========================================================================
    // CP & ELEMEN KURIKULUM (MULTIPLE CHOICE KMP, BULK ACTIONS, FILTER ELEMEN)
    // =========================================================================
    function updateCpKmpCount() {
        var checked = document.querySelectorAll('.cp-kmp-checkbox:checked');
        var badge = document.getElementById('cpKmpSelectedCount');
        if (badge) badge.textContent = checked.length + ' dipilih';
    }

    function updateElemenKmpCount() {
        var checked = document.querySelectorAll('.elemen-kmp-checkbox:checked');
        var badge = document.getElementById('elemenKmpSelectedCount');
        if (badge) badge.textContent = checked.length + ' dipilih';
    }

    function openModalCP(data) {
        data = data || {};
        document.getElementById('cpId').value = data.id || '0';
        document.getElementById('modalCPTitle').textContent = data.id ? 'Edit Capaian Pembelajaran (CP)' : 'Tambah Capaian Pembelajaran (CP)';

        // Reset search input
        var searchInput = document.getElementById('searchCpKmp');
        if (searchInput) {
            searchInput.value = '';
            document.querySelectorAll('.cp-kmp-item').forEach(function(el){ el.style.display = 'flex'; });
        }

        // Set checked states for multiple choice KMP
        var targetKmp = data.kmp ? String(data.kmp) : null;
        var checkboxes = document.querySelectorAll('.cp-kmp-checkbox');
        checkboxes.forEach(function(chk){
            if (targetKmp) {
                chk.checked = (chk.value === targetKmp);
            } else {
                chk.checked = false;
            }
        });
        updateCpKmpCount();

        document.getElementById('cpTeks').value = data.cp || '';
        filterElemenByKmp();
        if (data.elemen) {
            document.getElementById('cpElemen').value = data.elemen;
        } else {
            document.getElementById('cpElemen').value = '0';
        }
        openModal('modalCP');
    }

    function filterElemenByKmp() {
        var checkedKmps = Array.prototype.map.call(
            document.querySelectorAll('.cp-kmp-checkbox:checked'),
            function(c){ return c.value; }
        );
        var sel = document.getElementById('cpElemen');
        if (!sel) return;

        Array.prototype.forEach.call(sel.options, function(opt){
            if (opt.value === '0') {
                opt.hidden = false;
                return;
            }
            opt.hidden = false;
            var optKmp = opt.getAttribute('data-kmp');
            var rawNama = opt.getAttribute('data-nama') || opt.textContent.replace(/^★\s*/, '').replace(/\s*\(Sesuai Rombel Ini\)$/, '');
            var rawFase = opt.getAttribute('data-fase') || '';

            if (checkedKmps.length > 0 && checkedKmps.indexOf(optKmp) !== -1) {
                opt.textContent = '★ ' + rawNama + (rawFase ? ' [' + rawFase + ']' : '') + ' (Sesuai Rombel Ini)';
                opt.style.fontWeight = '700';
                opt.style.color = '#0D7A87';
            } else {
                opt.textContent = rawNama + (rawFase ? ' [' + rawFase + ']' : '');
                opt.style.fontWeight = 'normal';
                opt.style.color = '#1E293B';
            }
        });
    }

    // Multiple Choice Event Listeners for modalCP
    var searchCpKmp = document.getElementById('searchCpKmp');
    if (searchCpKmp) {
        searchCpKmp.addEventListener('input', function(){
            var q = this.value.toLowerCase().trim();
            document.querySelectorAll('.cp-kmp-item').forEach(function(item){
                var txt = item.textContent.toLowerCase();
                item.style.display = (!q || txt.indexOf(q) !== -1) ? 'flex' : 'none';
            });
        });
    }

    var btnSelectAllCpKmp = document.getElementById('btnSelectAllCpKmp');
    if (btnSelectAllCpKmp) {
        btnSelectAllCpKmp.addEventListener('click', function(){
            document.querySelectorAll('.cp-kmp-item').forEach(function(item){
                if (item.style.display !== 'none') {
                    var chk = item.querySelector('.cp-kmp-checkbox');
                    if (chk) chk.checked = true;
                }
            });
            updateCpKmpCount();
            filterElemenByKmp();
        });
    }

    var btnDeselectAllCpKmp = document.getElementById('btnDeselectAllCpKmp');
    if (btnDeselectAllCpKmp) {
        btnDeselectAllCpKmp.addEventListener('click', function(){
            document.querySelectorAll('.cp-kmp-checkbox').forEach(function(chk){
                chk.checked = false;
            });
            updateCpKmpCount();
            filterElemenByKmp();
        });
    }

    // Multiple Choice Event Listeners for modalElemen
    var searchElemenKmp = document.getElementById('searchElemenKmp');
    if (searchElemenKmp) {
        searchElemenKmp.addEventListener('input', function(){
            var q = this.value.toLowerCase().trim();
            document.querySelectorAll('.elemen-kmp-item').forEach(function(item){
                var txt = item.textContent.toLowerCase();
                item.style.display = (!q || txt.indexOf(q) !== -1) ? 'flex' : 'none';
            });
        });
    }

    var btnSelectAllElemenKmp = document.getElementById('btnSelectAllElemenKmp');
    if (btnSelectAllElemenKmp) {
        btnSelectAllElemenKmp.addEventListener('click', function(){
            document.querySelectorAll('.elemen-kmp-item').forEach(function(item){
                if (item.style.display !== 'none') {
                    var chk = item.querySelector('.elemen-kmp-checkbox');
                    if (chk) chk.checked = true;
                }
            });
            updateElemenKmpCount();
        });
    }

    var btnDeselectAllElemenKmp = document.getElementById('btnDeselectAllElemenKmp');
    if (btnDeselectAllElemenKmp) {
        btnDeselectAllElemenKmp.addEventListener('click', function(){
            document.querySelectorAll('.elemen-kmp-checkbox').forEach(function(chk){
                chk.checked = false;
            });
            updateElemenKmpCount();
        });
    }

    document.addEventListener('change', function(e){
        if (e.target && e.target.classList.contains('cp-kmp-checkbox')) {
            updateCpKmpCount();
            filterElemenByKmp();
        }
        if (e.target && e.target.classList.contains('elemen-kmp-checkbox')) {
            updateElemenKmpCount();
        }
    });

    document.getElementById('btnTambahCPInline').addEventListener('click', function(){ openModalCP(); });

    function syncCpKmpsToElemenModal() {
        var checkedCpKmps = Array.prototype.map.call(
            document.querySelectorAll('.cp-kmp-checkbox:checked'),
            function(c){ return c.value; }
        );
        document.querySelectorAll('.elemen-kmp-checkbox').forEach(function(chk){
            chk.checked = (checkedCpKmps.length > 0 && checkedCpKmps.indexOf(chk.value) !== -1);
        });
        updateElemenKmpCount();
    }

    var btnTambahElemenTop = document.getElementById('btnTambahElemenTop');
    if (btnTambahElemenTop) {
        btnTambahElemenTop.addEventListener('click', function(){
            syncCpKmpsToElemenModal();
            document.getElementById('elemenNama').value = '';
            document.getElementById('elemenDeskripsi').value = '';
            openModal('modalElemen');
        });
    }

    var btnQuickTambahElemen = document.getElementById('btnQuickTambahElemen');
    if (btnQuickTambahElemen) {
        btnQuickTambahElemen.addEventListener('click', function(){
            syncCpKmpsToElemenModal();
            document.getElementById('elemenNama').value = '';
            document.getElementById('elemenDeskripsi').value = '';
            openModal('modalElemen');
        });
    }

    document.getElementById('btnTambahElemenDariCP').addEventListener('click', function(){
        syncCpKmpsToElemenModal();
        document.getElementById('elemenNama').value = '';
        document.getElementById('elemenDeskripsi').value = '';
        openModal('modalElemen');
    });

    document.getElementById('btnKelolaElemen').addEventListener('click', function(){
        openModal('modalElemen');
    });

    document.getElementById('btnSaveElemen').addEventListener('click', function(){
        var checkedKmps = Array.prototype.map.call(
            document.querySelectorAll('.elemen-kmp-checkbox:checked'),
            function(c){ return c.value; }
        );
        if (checkedKmps.length === 0) {
            toast('warning', 'Pilih minimal satu Rombel & Mata Pelajaran.');
            return;
        }

        var namaElemen = document.getElementById('elemenNama').value.trim();
        if (!namaElemen) {
            toast('warning', 'Nama elemen wajib diisi.');
            return;
        }

        var fd = new FormData();
        fd.append('action', 'save_elemen');
        checkedKmps.forEach(function(kId){ fd.append('id_kelas_mata_pelajaran[]', kId); });
        fd.append('nama_elemen', namaElemen);
        fd.append('fase', document.getElementById('elemenFase').value);
        fd.append('deskripsi', document.getElementById('elemenDeskripsi').value);

        var btn = this;
        btn.disabled = true;
        fetch(AJAX_URL, { method: 'POST', body: fd })
            .then(function(r){ return r.json(); })
            .then(function(res){
                btn.disabled = false;
                if (!res || !res.success) { toast('error', (res && res.data && res.data.message) || 'Gagal simpan.'); return; }
                toast('success', res.data.message);
                closeModal('modalElemen');

                var list = (res.data && res.data.elemen_list) ? res.data.elemen_list : [(res.data && res.data.elemen) ? res.data.elemen : res.data];
                var sel = document.getElementById('cpElemen');
                var listBox = document.getElementById('elemenListBox');

                list.forEach(function(elData){
                    if (!elData || !elData.id) return;
                    if (sel) {
                        var opt = sel.querySelector('option[value="' + elData.id + '"]');
                        if (!opt) {
                            opt = document.createElement('option');
                            opt.value = elData.id;
                            sel.appendChild(opt);
                        }
                        opt.setAttribute('data-kmp', elData.id_kelas_mata_pelajaran || '');
                        opt.setAttribute('data-nama', elData.nama_elemen || namaElemen);
                        opt.setAttribute('data-fase', elData.fase || '');
                        opt.textContent = '★ ' + (elData.nama_elemen || namaElemen) + ' [' + (elData.fase || '') + '] (Baru Ditambahkan)';
                        opt.style.fontWeight = 'bold';
                        opt.style.color = '#0D7A87';
                    }

                    if (listBox) {
                        var emptyDiv = listBox.querySelector('div[style*="text-align: center"]');
                        if (emptyDiv) listBox.innerHTML = '';

                        var newDiv = document.createElement('div');
                        newDiv.className = 'elemen-row';
                        newDiv.setAttribute('data-kmp', elData.id_kelas_mata_pelajaran);
                        newDiv.style.cssText = 'padding: 10px 14px; border-bottom: 1px solid #F1F5F9; display: flex; justify-content: space-between; align-items: center;';
                        newDiv.innerHTML = '<div><strong style="font-size: 13px; color: #0F172A;">' + escapeHtml(elData.nama_elemen || namaElemen) + '</strong><span class="ilmuna-tab-counter" style="margin-left: 6px; font-size: 10px;">' + escapeHtml(elData.fase || '') + '</span><div style="font-size: 11.5px; color: #64748B; margin-top: 2px;">' + escapeHtml((elData.nama_mata_pelajaran || '') + ' • ' + (elData.nama_kelas || '')) + '</div></div><button type="button" class="ilmuna-btn-icon btn-delete-elemen" data-id="' + elData.id + '" data-nama="' + escapeHtml(elData.nama_elemen || namaElemen) + '" title="Hapus Elemen"><iconify-icon icon="solar:trash-bin-trash-bold" width="16" style="color: #EF4444;"></iconify-icon></button>';
                        listBox.prepend(newDiv);
                    }
                });

                if (sel && res.data.id) {
                    sel.value = res.data.id;
                }
            })
            .catch(function(){
                btn.disabled = false;
                toast('error', 'Koneksi bermasalah.');
            });
    });

    // =========================================================================
    // BULK ACTIONS LOGIC UNTUK TAB CP (CAPAIAN PEMBELAJARAN)
    // =========================================================================
    var checkAllCP = document.getElementById('checkAllCP');
    var cpBulkBar = document.getElementById('cpBulkBar');
    var cpBulkCountBadge = document.getElementById('cpBulkCountBadge');

    function updateCpBulkBar() {
        var checked = document.querySelectorAll('.check-cp-item:checked');
        var count = checked.length;
        if (cpBulkBar) {
            cpBulkBar.style.display = count > 0 ? 'flex' : 'none';
        }
        if (cpBulkCountBadge) {
            cpBulkCountBadge.textContent = count + ' CP Dipilih';
        }
        if (checkAllCP) {
            var total = document.querySelectorAll('.check-cp-item').length;
            checkAllCP.checked = (total > 0 && count === total);
            checkAllCP.indeterminate = (count > 0 && count < total);
        }
    }

    if (checkAllCP) {
        checkAllCP.addEventListener('change', function(){
            var isChecked = this.checked;
            document.querySelectorAll('.check-cp-item').forEach(function(chk){
                chk.checked = isChecked;
            });
            updateCpBulkBar();
        });
    }

    document.addEventListener('change', function(e){
        if (e.target && e.target.classList.contains('check-cp-item')) {
            updateCpBulkBar();
        }
    });

    var btnBulkDeselectCP = document.getElementById('btnBulkDeselectCP');
    if (btnBulkDeselectCP) {
        btnBulkDeselectCP.addEventListener('click', function(){
            document.querySelectorAll('.check-cp-item').forEach(function(chk){
                chk.checked = false;
            });
            if (checkAllCP) checkAllCP.checked = false;
            updateCpBulkBar();
        });
    }

    var btnBulkDeleteCP = document.getElementById('btnBulkDeleteCP');
    if (btnBulkDeleteCP) {
        btnBulkDeleteCP.addEventListener('click', function(){
            var checked = document.querySelectorAll('.check-cp-item:checked');
            if (checked.length === 0) return;

            var ids = Array.prototype.map.call(checked, function(c){ return c.value; });
            askConfirm('Hapus ' + ids.length + ' Capaian Pembelajaran (CP) yang dipilih secara permanen?', 'Hapus CP Terpilih', 'error').then(function(ok){
                if (!ok) return;

                var btn = btnBulkDeleteCP;
                var origHtml = btn.innerHTML;
                btn.disabled = true;
                btn.textContent = 'Menghapus...';

                var fd = new FormData();
                fd.append('action', 'bulk_delete_cp');
                ids.forEach(function(id){ fd.append('ids[]', id); });

                fetch(AJAX_URL, { method: 'POST', body: fd })
                    .then(function(r){ return r.json(); })
                    .then(function(res){
                        btn.disabled = false;
                        btn.innerHTML = origHtml;
                        if (!res || !res.success) {
                            toast('error', (res && res.data && res.data.message) || 'Gagal menghapus CP terpilih.');
                            return;
                        }
                        toast('success', res.data.message);

                        // Hapus baris tabel dari DOM
                        ids.forEach(function(id){
                            var chk = document.querySelector('.check-cp-item[value="' + id + '"]');
                            if (chk) {
                                var row = chk.closest('tr');
                                if (row) row.remove();
                            }
                        });

                        updateCpBulkBar();

                        // Re-index nomor urut baris
                        var tbody = document.getElementById('tbodyCP');
                        var rows = tbody ? tbody.querySelectorAll('tr.row-cp') : [];
                        rows.forEach(function(tr, idx){
                            var tdNo = tr.querySelector('td:nth-child(2)');
                            if (tdNo) tdNo.textContent = idx + 1;
                        });

                        var badgeCP = document.getElementById('badgeTabCP');
                        if (badgeCP) badgeCP.textContent = rows.length + ' Capaian';

                        if (rows.length === 0 && tbody) {
                            tbody.innerHTML = '<tr><td colspan="6" style="text-align: center; padding: 36px 20px; color: #64748B;">Belum ada Capaian Pembelajaran (CP). Tambahkan Elemen lalu input CP.</td></tr>';
                        }
                    })
                    .catch(function(){
                        btn.disabled = false;
                        btn.innerHTML = origHtml;
                        toast('error', 'Koneksi bermasalah.');
                    });
            });
        });
    }

    document.addEventListener('click', function(e){
        var editBtn = e.target.closest('.btn-edit-cp');
        if (editBtn) {
            openModalCP({
                id: editBtn.dataset.id,
                kmp: editBtn.dataset.kmp,
                elemen: editBtn.dataset.elemen,
                cp: editBtn.dataset.cp
            });
            return;
        }
        var delBtn = e.target.closest('.btn-delete-cp');
        if (delBtn) {
            var idCp = delBtn.dataset.id;
            askConfirm('Hapus Capaian Pembelajaran (CP) ini?', 'Hapus Capaian Pembelajaran', 'error').then(function(ok){
                if (!ok) return;

                var fd = new FormData();
                fd.append('action', 'delete_cp');
                fd.append('id', idCp);
                fetch(AJAX_URL, { method: 'POST', body: fd })
                    .then(function(r){ return r.json(); })
                    .then(function(res){
                        if (!res || !res.success) { toast('error', (res && res.data && res.data.message) || 'Gagal hapus.'); return; }
                        toast('success', res.data.message);
                        var row = delBtn.closest('tr');
                        if (row) {
                            row.remove();
                            updateCpBulkBar();
                            var tbody = document.getElementById('tbodyCP');
                            var rows = tbody ? tbody.querySelectorAll('tr.row-cp') : [];
                            rows.forEach(function(tr, idx){
                                var tdNo = tr.querySelector('td:nth-child(2)');
                                if (tdNo) tdNo.textContent = idx + 1;
                            });
                            var badgeCP = document.getElementById('badgeTabCP');
                            if (badgeCP) badgeCP.textContent = rows.length + ' Capaian';
                        }
                    })
                    .catch(function(){ toast('error', 'Koneksi bermasalah.'); });
            });
            return;
        }
        var delElBtn = e.target.closest('.btn-delete-elemen');
        if (delElBtn) {
            var idE = delElBtn.dataset.id;
            var nmE = delElBtn.dataset.nama;
            askConfirm('Hapus elemen "' + nmE + '"?', 'Hapus Elemen Kurikulum', 'error').then(function(ok){
                if (!ok) return;

                var fd = new FormData();
                fd.append('action', 'delete_elemen');
                fd.append('id', idE);
                fetch(AJAX_URL, { method: 'POST', body: fd })
                    .then(function(r){ return r.json(); })
                    .then(function(res){
                        if (!res || !res.success) { toast('error', (res && res.data && res.data.message) || 'Gagal hapus.'); return; }
                        toast('success', res.data.message);
                        var opt = document.querySelector('#cpElemen option[value="' + idE + '"]');
                        if (opt) opt.remove();
                        var elRow = delElBtn.closest('.elemen-row');
                        if (elRow) elRow.remove();
                    })
                    .catch(function(){ toast('error', 'Koneksi bermasalah.'); });
            });
        }
    });

    document.getElementById('btnSaveCP').addEventListener('click', function(){
        var checkedKmps = Array.prototype.map.call(
            document.querySelectorAll('.cp-kmp-checkbox:checked'),
            function(c){ return c.value; }
        );
        if (checkedKmps.length === 0) {
            toast('warning', 'Pilih minimal satu Rombel & Mata Pelajaran.');
            return;
        }

        var elVal = document.getElementById('cpElemen').value;
        if (!elVal || elVal === '0') {
            toast('warning', 'Elemen Kurikulum Merdeka wajib dipilih.');
            return;
        }

        var teksCp = document.getElementById('cpTeks').value.trim();
        if (!teksCp) {
            toast('warning', 'Naskah Capaian Pembelajaran (CP) wajib diisi.');
            return;
        }

        var fd = new FormData();
        fd.append('action', 'save_cp');
        var cpId = document.getElementById('cpId').value;
        fd.append('id', cpId);
        checkedKmps.forEach(function(kId){ fd.append('id_kelas_mata_pelajaran[]', kId); });
        fd.append('id_elemen', elVal);
        fd.append('cp', teksCp);

        var btn = this;
        btn.disabled = true;
        fetch(AJAX_URL, { method: 'POST', body: fd })
            .then(function(r){ return r.json(); })
            .then(function(res){
                btn.disabled = false;
                if (!res || !res.success) { toast('error', (res && res.data && res.data.message) || 'Gagal simpan.'); return; }
                toast('success', res.data.message);
                closeModal('modalCP');

                // In-place update CP table
                var tbody = document.getElementById('tbodyCP');
                if (!tbody) return;

                var isEdit = res.data && res.data.is_edit;
                var cpList = (res.data && res.data.cp_list) ? res.data.cp_list : [(res.data && res.data.cp) ? res.data.cp : null];

                if (isEdit && res.data && res.data.cp) {
                    var c = res.data.cp;
                    var existingRow = tbody.querySelector('.btn-edit-cp[data-id="' + c.id + '"]');
                    if (existingRow) {
                        var tr = existingRow.closest('tr');
                        tr.querySelector('td:nth-child(3) div:first-child').textContent = c.nama_elemen || 'Umum / Terpadu';
                        tr.querySelector('td:nth-child(3) .ilmuna-tab-counter').textContent = c.fase || 'Fase E/F';
                        tr.querySelector('td:nth-child(4) div').innerHTML = escapeHtml(c.cp).replace(/\n/g, '<br>');
                        tr.querySelector('td:nth-child(5) div:first-child').textContent = c.nama_mata_pelajaran || '';
                        tr.querySelector('td:nth-child(5) div:last-child').textContent = c.nama_kelas || '';
                        existingRow.setAttribute('data-kmp', c.id_kelas_mata_pelajaran);
                        existingRow.setAttribute('data-elemen', c.id_elemen);
                        existingRow.setAttribute('data-cp', c.cp);
                    }
                } else if (cpList && cpList.length > 0) {
                    var emptyTd = tbody.querySelector('td[colspan="6"], td[colspan="5"]');
                    if (emptyTd) tbody.innerHTML = '';

                    cpList.forEach(function(c){
                        if (!c) return;
                        var newIdx = tbody.querySelectorAll('tr.row-cp').length + 1;
                        var newTr = document.createElement('tr');
                        newTr.className = 'row-cp';
                        newTr.setAttribute('data-id', c.id);
                        newTr.innerHTML =
                            '<td style="text-align: center;"><input type="checkbox" class="check-cp-item" value="' + c.id + '" style="cursor: pointer; width: 16px; height: 16px; accent-color: #0D7A87;"></td>' +
                            '<td style="text-align: center; font-weight: 700; color: #64748B;">' + newIdx + '</td>' +
                            '<td><div style="font-weight: 700; color: #0D7A87; font-size: 13.5px;">' + escapeHtml(c.nama_elemen || 'Umum') + '</div><div style="margin-top: 4px;"><span class="ilmuna-tab-counter" style="background:#CCFBF1; color:#0F766E; font-size:10px;">' + escapeHtml(c.fase || 'Fase E/F') + '</span></div></td>' +
                            '<td><div style="color: #1E293B; font-size: 13px; line-height: 1.55;">' + escapeHtml(c.cp).replace(/\n/g, '<br>') + '</div></td>' +
                            '<td><div style="font-weight: 700; color: #334155; font-size: 12.5px;">' + escapeHtml(c.nama_mata_pelajaran || '') + '</div><div style="color: #94A3B8; font-size: 11.5px; margin-top: 2px;">' + escapeHtml(c.nama_kelas || '') + '</div></td>' +
                            '<td style="text-align: center;"><div style="display: inline-flex; gap: 4px;">' +
                            '<button type="button" class="ilmuna-btn-icon btn-edit-cp" data-id="' + c.id + '" data-kmp="' + c.id_kelas_mata_pelajaran + '" data-elemen="' + c.id_elemen + '" data-cp="' + escapeHtml(c.cp) + '" title="Edit CP"><iconify-icon icon="solar:pen-new-square-bold" width="16" style="color: #0D7A87;"></iconify-icon></button>' +
                            '<button type="button" class="ilmuna-btn-icon btn-delete-cp" data-id="' + c.id + '" title="Hapus CP"><iconify-icon icon="solar:trash-bin-trash-bold" width="16" style="color: #EF4444;"></iconify-icon></button>' +
                            '</div></td>';
                        tbody.appendChild(newTr);
                    });

                    var rows = tbody.querySelectorAll('tr.row-cp');
                    rows.forEach(function(tr, idx){
                        var tdNo = tr.querySelector('td:nth-child(2)');
                        if (tdNo) tdNo.textContent = idx + 1;
                    });

                    var badgeCP = document.getElementById('badgeTabCP');
                    if (badgeCP) badgeCP.textContent = rows.length + ' Capaian';
                }
            })
            .catch(function(){
                btn.disabled = false;
                toast('error', 'Koneksi bermasalah.');
            });
    });

})();
</script>

<?php require_once BASE_PATH . '/includes/footer.php'; ?>