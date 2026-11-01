<?php
/**
 * ==============================================================================
 * ILMUNA LEARN — EXPORT EXCEL KALENDER PENDIDIKAN & RPE (SATUAN PENDIDIKAN)
 * ==============================================================================
 * Menghasilkan Berkas Spreadsheet OpenXML (.xlsx) 2-Sheet Sesuai Standar:
 *   - Sheet 1: Full Kalender 1 Tahun (Matriks 12 Bulan dengan Header Hijau,
 *              Pewarnaan Status Tanggal, Legenda Warna, dan Uraian Agenda di Bawah)
 *   - Sheet 2: Rincian Pekan Efektif (RPE) Tingkat Satuan Pendidikan
 *              (Tabel Semester Ganjil & Genap, Rincian Hari Senin-Sabtu,
 *              Total HE, Pekan Efektif, Pekan Non-Efektif, dan Rekapitulasi)
 *
 * Menggunakan Arsitektur Hybrid OpenXML Native ZipArchive & Pure-PHP Fallback
 * Serta Mendukung Pengunduhan via Metode JavaScript Blob.
 * ==============================================================================
 */

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../auth.php';
require_once __DIR__ . '/../../database.php';

if (php_sapi_name() !== 'cli') {
    requireRole('admin');
}

$pdo = getDBConnection();
$pdo->exec("SET time_zone = '+07:00'");

/* ==============================================================================
 * HELPER: OPENXML XLSX UTILITIES & THEME ENGINE
 * ============================================================================== */

if (!function_exists('ilmuna_xlsx_col_letter')) {
    function ilmuna_xlsx_col_letter($idx) {
        $letter = '';
        $idx = (int)$idx + 1;
        while ($idx > 0) {
            $mod = ($idx - 1) % 26;
            $letter = chr(65 + $mod) . $letter;
            $idx = intdiv($idx - $mod, 26);
        }
        return $letter;
    }
}

if (!class_exists('IlmunaZipArchive')) {
class IlmunaZipArchive {
    public const CREATE = 1;
    public const OVERWRITE = 8;

    private $nativeZip = null;
    private $filePath = '';
    private $files = [];
    private $zipData = null;

    public function __construct() {
        if (class_exists('ZipArchive')) {
            $this->nativeZip = new ZipArchive();
        }
    }

    public function open($filename, $flags = 0) {
        $this->filePath = $filename;
        if ($this->nativeZip) {
            $res = $this->nativeZip->open($filename, $flags);
            if ($res === true) {
                return true;
            }
        }

        if ($flags & (self::CREATE | self::OVERWRITE)) {
            $this->files = [];
            return true;
        }

        if (!file_exists($filename)) {
            return false;
        }
        $this->zipData = @file_get_contents($filename);
        return ($this->zipData !== false);
    }

    public function addFromString($localname, $contents) {
        if ($this->nativeZip) {
            return $this->nativeZip->addFromString($localname, $contents);
        }
        $this->files[$localname] = $contents;
        return true;
    }

    public function getFromName($localname) {
        if ($this->nativeZip) {
            $content = $this->nativeZip->getFromName($localname);
            if ($content !== false) {
                return $content;
            }
        }
        return false;
    }

    public function close() {
        if ($this->nativeZip) {
            $closed = $this->nativeZip->close();
            if ($closed) {
                return true;
            }
        }

        if (!empty($this->files)) {
            $binary = self::buildPureZipData($this->files);
            $bytes = @file_put_contents($this->filePath, $binary);
            return ($bytes !== false && $bytes > 0);
        }

        return true;
    }

    private static function buildPureZipData($files) {
        $time = time();
        $dostime = ((date('Y', $time) - 1980) << 25)
                 | (date('m', $time) << 21)
                 | (date('d', $time) << 16)
                 | (date('H', $time) << 11)
                 | (date('i', $time) << 5)
                 | (date('s', $time) >> 1);

        $entries = '';
        $cd = '';
        $offset = 0;

        foreach ($files as $name => $content) {
            $name = str_replace('\\', '/', $name);
            $nLen = strlen($name);
            $uSize = strlen($content);
            $crc = crc32($content);

            if (function_exists('gzdeflate')) {
                $cBody = gzdeflate($content);
                if (strlen($cBody) < $uSize) {
                    $method = 8;
                } else {
                    $cBody = $content;
                    $method = 0;
                }
            } else {
                $cBody = $content;
                $method = 0;
            }
            $cSize = strlen($cBody);

            $lh = pack('VvvvVVVVvv', 0x04034b50, 20, 0, $method, $dostime, $crc, $cSize, $uSize, $nLen, 0) . $name;
            $entries .= $lh . $cBody;

            $cdRecord = pack('VvvvvVVVVvvvvvVV', 0x02014b50, 20, 20, 0, $method, $dostime, $crc, $cSize, $uSize, $nLen, 0, 0, 0, 0, 0, $offset) . $name;
            $cd .= $cdRecord;

            $offset = strlen($entries);
        }

        $cdLen = strlen($cd);
        $eocd = pack('VvvvvVVv', 0x06054b50, 0, 0, count($files), count($files), $cdLen, $offset, 0);

        return $entries . $cd . $eocd;
    }
}
}

if (!function_exists('ilmuna_create_temp_xlsx')) {
    function ilmuna_create_temp_xlsx($prefix = 'kaldik_') {
        $randomSuffix = date('Ymd_His') . '_' . bin2hex(random_bytes(6)) . '.xlsx';
        $uploadsDir = __DIR__ . '/../../uploads';
        if (!is_dir($uploadsDir)) {
            @mkdir($uploadsDir, 0777, true);
        }
        if (is_dir($uploadsDir) && is_writable($uploadsDir)) {
            $path = $uploadsDir . '/' . $prefix . $randomSuffix;
            if (file_exists($path)) @unlink($path);
            return $path;
        }
        $laragonTmp = 'C:/laragon/tmp';
        if (is_dir($laragonTmp) && is_writable($laragonTmp)) {
            $path = $laragonTmp . '/' . $prefix . $randomSuffix;
            if (file_exists($path)) @unlink($path);
            return $path;
        }
        $sysTmp = sys_get_temp_dir();
        return $sysTmp . '/' . $prefix . $randomSuffix;
    }
}

if (!function_exists('ilmuna_build_shared_strings_xml')) {
    function ilmuna_build_shared_strings_xml($strings) {
        $count = count($strings);
        $xml = '<?xml version="1.0" encoding="UTF-8"?>';
        $xml .= '<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" count="' . $count . '" uniqueCount="' . $count . '">';
        foreach ($strings as $str) {
            $clean = htmlspecialchars((string)$str, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $clean = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $clean);
            $xml .= '<si><t xml:space="preserve">' . $clean . '</t></si>';
        }
        $xml .= '</sst>';
        return $xml;
    }
}

/**
 * Stylesheet Tema Emerald & Lumina Gold (Header Hijau Segar, Color Badges, Borders)
 */
function ilmuna_get_kaldik_xlsx_styles() {
    return '<?xml version="1.0" encoding="UTF-8"?>'
        .'<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        .'<fonts count="15">'
        .'<font><sz val="10"/><name val="Segoe UI"/></font>'                             // 0: Default
        .'<font><b/><sz val="15"/><color rgb="FF065F46"/><name val="Segoe UI"/></font>'  // 1: Brand Title (Dark Emerald)
        .'<font><b/><sz val="11"/><color rgb="FF0D7A87"/><name val="Segoe UI"/></font>'  // 2: Subtitle (Teal)
        .'<font><i/><sz val="9.5"/><color rgb="FF64748B"/><name val="Segoe UI"/></font>' // 3: Meta Info (Slate)
        .'<font><b/><sz val="10.5"/><color rgb="FFFFFFFF"/><name val="Segoe UI"/></font>' // 4: Table Header (White Bold)
        .'<font><sz val="9.5"/><color rgb="FF1E293B"/><name val="Segoe UI"/></font>'      // 5: Body Regular
        .'<font><b/><sz val="9.5"/><color rgb="FF065F46"/><name val="Segoe UI"/></font>'  // 6: Body Bold Green
        .'<font><b/><sz val="9.5"/><color rgb="FFB91C1C"/><name val="Segoe UI"/></font>'  // 7: Body Bold Red
        .'<font><b/><sz val="9.5"/><color rgb="FF475569"/><name val="Segoe UI"/></font>'  // 8: Body Bold Slate
        .'<font><b/><sz val="9.5"/><color rgb="FF92400E"/><name val="Segoe UI"/></font>'  // 9: Body Bold Amber/Gold
        .'<font><b/><sz val="9.5"/><color rgb="FF5B21B6"/><name val="Segoe UI"/></font>'  // 10: Body Bold Purple
        .'<font><b/><sz val="9.5"/><color rgb="FF0369A1"/><name val="Segoe UI"/></font>'  // 11: Body Bold Sky Blue
        .'<font><b/><sz val="9.5"/><color rgb="FF0F172A"/><name val="Segoe UI"/></font>'  // 12: Weekday Day Header
        .'<font><b/><sz val="10"/><color rgb="FF0F172A"/><name val="Segoe UI"/></font>'   // 13: Summary Bold
        .'<font><b/><sz val="9.5"/><color rgb="FFFFFFFF"/><name val="Segoe UI"/></font>'  // 14: Legend Pill Font
        .'</fonts>'
        .'<fills count="15">'
        .'<fill><patternFill patternType="none"/></fill>'                                  // 0: None
        .'<fill><patternFill patternType="gray125"/></fill>'                               // 1: Gray125
        .'<fill><patternFill patternType="solid"><fgColor rgb="FF059669"/></patternFill></fill>' // 2: Header Green Emerald (#059669)
        .'<fill><patternFill patternType="solid"><fgColor rgb="FF065F46"/></patternFill></fill>' // 3: Header Dark Green
        .'<fill><patternFill patternType="solid"><fgColor rgb="FFD1FAE5"/></patternFill></fill>' // 4: Fill Efektif KBM (Soft Green)
        .'<fill><patternFill patternType="solid"><fgColor rgb="FFFEE2E2"/></patternFill></fill>' // 5: Fill Libur / Minggu (Soft Red)
        .'<fill><patternFill patternType="solid"><fgColor rgb="FFF1F5F9"/></patternFill></fill>' // 6: Fill Libur Semester (Soft Slate)
        .'<fill><patternFill patternType="solid"><fgColor rgb="FFFEF3C7"/></patternFill></fill>' // 7: Fill Ujian / Kegiatan (Soft Gold)
        .'<fill><patternFill patternType="solid"><fgColor rgb="FFEDE9FE"/></patternFill></fill>' // 8: Fill Keagamaan (Soft Purple)
        .'<fill><patternFill patternType="solid"><fgColor rgb="FFE0F2FE"/></patternFill></fill>' // 9: Fill Class Meeting/P5 (Soft Blue)
        .'<fill><patternFill patternType="solid"><fgColor rgb="FFF8FAFC"/></patternFill></fill>' // 10: Zebra Slate
        .'<fill><patternFill patternType="solid"><fgColor rgb="FFE2E8F0"/></patternFill></fill>' // 11: Summary Row
        .'<fill><patternFill patternType="solid"><fgColor rgb="FFF1F5F9"/></patternFill></fill>' // 12: Weekday Bar
        .'<fill><patternFill patternType="solid"><fgColor rgb="FFFEE2E2"/></patternFill></fill>' // 13: Sunday Bar
        .'<fill><patternFill patternType="solid"><fgColor rgb="FF0D7A87"/></patternFill></fill>' // 14: Teal Accent
        .'</fills>'
        .'<borders count="5">'
        .'<border><left/><right/><top/><bottom/></border>'                                // 0: None
        .'<border>'                                                                       // 1: Data Thin Border
        .'<left style="thin"><color rgb="FFCBD5E1"/></left>'
        .'<right style="thin"><color rgb="FFCBD5E1"/></right>'
        .'<top style="thin"><color rgb="FFCBD5E1"/></top>'
        .'<bottom style="thin"><color rgb="FFCBD5E1"/></bottom>'
        .'</border>'
        .'<border>'                                                                       // 2: Header Border
        .'<left style="thin"><color rgb="FF047857"/></left>'
        .'<right style="thin"><color rgb="FF047857"/></right>'
        .'<top style="thin"><color rgb="FF047857"/></top>'
        .'<bottom style="medium"><color rgb="FF065F46"/></bottom>'
        .'</border>'
        .'<border>'                                                                       // 3: Summary Border
        .'<left style="thin"><color rgb="FF94A3B8"/></left>'
        .'<right style="thin"><color rgb="FF94A3B8"/></right>'
        .'<top style="thin"><color rgb="FF94A3B8"/></top>'
        .'<bottom style="double"><color rgb="FF0F172A"/></bottom>'
        .'</border>'
        .'<border>'                                                                       // 4: Calendar Day Border
        .'<left style="thin"><color rgb="FFE2E8F0"/></left>'
        .'<right style="thin"><color rgb="FFE2E8F0"/></right>'
        .'<top style="thin"><color rgb="FFE2E8F0"/></top>'
        .'<bottom style="thin"><color rgb="FFE2E8F0"/></bottom>'
        .'</border>'
        .'</borders>'
        .'<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
        .'<cellXfs count="24">'
        .'<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>' // 0: Default
        .'<xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0"><alignment vertical="center"/></xf>' // 1: Title
        .'<xf numFmtId="0" fontId="2" fillId="0" borderId="0" xfId="0"><alignment vertical="center"/></xf>' // 2: Subtitle
        .'<xf numFmtId="0" fontId="3" fillId="0" borderId="0" xfId="0"><alignment vertical="center"/></xf>' // 3: Meta
        .'<xf numFmtId="0" fontId="4" fillId="2" borderId="2" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center" wrapText="1"/></xf>' // 4: Header Green Center
        .'<xf numFmtId="0" fontId="12" fillId="12" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>'            // 5: Weekday Header Center
        .'<xf numFmtId="0" fontId="7" fillId="13" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>'              // 6: Weekday Sunday Header
        .'<xf numFmtId="0" fontId="0" fillId="0" borderId="4" xfId="0" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>'                                             // 7: Empty Cell
        .'<xf numFmtId="0" fontId="5" fillId="0" borderId="4" xfId="0" applyFont="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>'                            // 8: Cell Plain
        .'<xf numFmtId="0" fontId="6" fillId="4" borderId="4" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>'             // 9: Cell KBM Green
        .'<xf numFmtId="0" fontId="7" fillId="5" borderId="4" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>'             // 10: Cell Libur Red
        .'<xf numFmtId="0" fontId="8" fillId="6" borderId="4" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>'             // 11: Cell Semester Slate
        .'<xf numFmtId="0" fontId="9" fillId="7" borderId="4" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>'             // 12: Cell Kegiatan Gold
        .'<xf numFmtId="0" fontId="10" fillId="8" borderId="4" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>'            // 13: Cell Keagamaan Purple
        .'<xf numFmtId="0" fontId="11" fillId="9" borderId="4" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>'            // 14: Cell P5 Blue
        .'<xf numFmtId="0" fontId="4" fillId="2" borderId="2" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="left" vertical="center"/></xf>'               // 15: Table Header Left
        .'<xf numFmtId="0" fontId="5" fillId="0" borderId="1" xfId="0" applyFont="1" applyBorder="1" applyAlignment="1"><alignment horizontal="left" vertical="center" wrapText="1"/></xf>'                  // 16: Table Body Left
        .'<xf numFmtId="0" fontId="5" fillId="0" borderId="1" xfId="0" applyFont="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>'                            // 17: Table Body Center
        .'<xf numFmtId="0" fontId="5" fillId="10" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="left" vertical="center" wrapText="1"/></xf>' // 18: Table Zebra Left
        .'<xf numFmtId="0" fontId="5" fillId="10" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>'           // 19: Table Zebra Center
        .'<xf numFmtId="0" fontId="13" fillId="11" borderId="3" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="left" vertical="center"/></xf>'             // 20: Table Summary Left
        .'<xf numFmtId="0" fontId="13" fillId="11" borderId="3" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>'           // 21: Table Summary Center
        .'<xf numFmtId="0" fontId="4" fillId="3" borderId="2" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="left" vertical="center"/></xf>'               // 22: Dark Green Section
        .'<xf numFmtId="0" fontId="6" fillId="4" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>'             // 23: Legend Pill Bold
        .'</cellXfs>'
        .'</styleSheet>';
}

/**
 * ==============================================================================
 * GENERATE EXCEL KALDIK & RPE 2-SHEET WORKBOOK
 * ==============================================================================
 */
function ilmuna_generate_kaldik_excel_workbook($pdo, $tahun) {
    $parts = explode('/', $tahun);
    $yearGanjil = (int)($parts[0] ?? date('Y'));
    $yearGenap  = (int)($parts[1] ?? ($yearGanjil + 1));

    // 1. Ambil seluruh data kalender pendidikan 1 tahun
    $stmt = $pdo->prepare("SELECT * FROM kalender_pendidikan WHERE tahun_pelajaran = ? ORDER BY tanggal ASC");
    $stmt->execute([$tahun]);
    $kaldikRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $kaldikMap = [];
    $agendaList = [];
    foreach ($kaldikRows as $r) {
        $kaldikMap[$r['tanggal']] = $r;
        if (!empty($r['keterangan']) && $r['keterangan'] !== 'KBM Reguler') {
            $agendaList[] = $r;
        }
    }

    // 2. Kalkulasi RPE untuk seluruh 12 bulan (Ganjil: 7..12, Genap: 1..6)
    $bulanListGanjil = [
        1 => ['Juli', 7, $yearGanjil],
        2 => ['Agustus', 8, $yearGanjil],
        3 => ['September', 9, $yearGanjil],
        4 => ['Oktober', 10, $yearGanjil],
        5 => ['November', 11, $yearGanjil],
        6 => ['Desember', 12, $yearGanjil]
    ];

    $bulanListGenap = [
        7  => ['Januari', 1, $yearGenap],
        8  => ['Februari', 2, $yearGenap],
        9  => ['Maret', 3, $yearGenap],
        10 => ['April', 4, $yearGenap],
        11 => ['Mei', 5, $yearGenap],
        12 => ['Juni', 6, $yearGenap]
    ];

    $calcRpeForSemester = function($bulanDef, $semName) use ($pdo, $tahun) {
        $rows = [];
        $tSen = 0; $tSel = 0; $tRab = 0; $tKam = 0; $tJum = 0; $tSab = 0;
        $tHe = 0; $tPekan = 0; $tPe = 0; $tPte = 0;

        foreach ($bulanDef as $urutan => $bInfo) {
            $namaBulan = $bInfo[0];
            $mDigit    = $bInfo[1];
            $calYear   = $bInfo[2];

            $stmtCount = $pdo->prepare("
                SELECT hari, COUNT(*) as jml FROM kalender_pendidikan
                WHERE tahun_pelajaran = ? AND semester = ?
                  AND status_hari = 'Efektif KBM' AND MONTH(tanggal) = ?
                GROUP BY hari
            ");
            $stmtCount->execute([$tahun, $semName, $mDigit]);
            $counts = $stmtCount->fetchAll(PDO::FETCH_KEY_PAIR);

            $sen  = (int)($counts['Senin'] ?? 0);
            $sel  = (int)($counts['Selasa'] ?? 0);
            $rab  = (int)($counts['Rabu'] ?? 0);
            $kam  = (int)($counts['Kamis'] ?? 0);
            $jum  = (int)($counts['Jumat'] ?? 0);
            $sab  = (int)($counts['Sabtu'] ?? 0);

            $totalHe = $sen + $sel + $rab + $kam + $jum + $sab;
            $pekanTotal = in_array($mDigit, [7,8,10,12,1,3,5], true) ? 5 : 4;
            $pekanEfektif = $totalHe === 0 ? 0 : min($pekanTotal, max(1, (int)round($totalHe / 5)));
            $pekanTidakEfektif = max(0, $pekanTotal - $pekanEfektif);

            $stmtKet = $pdo->prepare("
                SELECT GROUP_CONCAT(DISTINCT keterangan SEPARATOR '; ')
                FROM kalender_pendidikan
                WHERE tahun_pelajaran = ? AND semester = ?
                  AND status_hari != 'Efektif KBM' AND MONTH(tanggal) = ?
            ");
            $stmtKet->execute([$tahun, $semName, $mDigit]);
            $ketAgenda = $stmtKet->fetchColumn() ?: ($pekanTidakEfektif > 0 ? 'Kegiatan Non-KBM / Libur Semester' : 'KBM Efektif Penuh');

            $rows[] = [
                'urutan' => $urutan,
                'bulan'  => $namaBulan,
                'tahun'  => $calYear,
                'mDigit' => $mDigit,
                'senin'  => $sen,
                'selasa' => $sel,
                'rabu'   => $rab,
                'kamis'  => $kam,
                'jumat'  => $jum,
                'sabtu'  => $sab,
                'total_he' => $totalHe,
                'pekan_total' => $pekanTotal,
                'pekan_efektif' => $pekanEfektif,
                'pekan_tidak_efektif' => $pekanTidakEfektif,
                'keterangan' => $ketAgenda
            ];

            $tSen += $sen; $tSel += $sel; $tRab += $rab;
            $tKam += $kam; $tJum += $jum; $tSab += $sab;
            $tHe += $totalHe; $tPekan += $pekanTotal;
            $tPe += $pekanEfektif; $tPte += $pekanTidakEfektif;
        }

        return [
            'rows'   => $rows,
            'totals' => [
                'senin' => $tSen, 'selasa' => $tSel, 'rabu' => $tRab,
                'kamis' => $tKam, 'jumat' => $tJum, 'sabtu' => $tSab,
                'total_he' => $tHe, 'pekan_total' => $tPekan,
                'pekan_efektif' => $tPe, 'pekan_tidak_efektif' => $tPte
            ]
        ];
    };

    $rpeGanjil = $calcRpeForSemester($bulanListGanjil, 'Ganjil');
    $rpeGenap  = $calcRpeForSemester($bulanListGenap, 'Genap');

    // Index bulan HE untuk tampilan header kalender
    $hePerMonth = [];
    foreach ($rpeGanjil['rows'] as $r) {
        $hePerMonth[$r['mDigit']] = $r['total_he'];
    }
    foreach ($rpeGenap['rows'] as $r) {
        $hePerMonth[$r['mDigit']] = $r['total_he'];
    }

    // Alokasi berkas sementara
    $tmpFile = ilmuna_create_temp_xlsx('kaldik_export_');
    $zip = new IlmunaZipArchive();
    if ($zip->open($tmpFile, IlmunaZipArchive::CREATE | IlmunaZipArchive::OVERWRITE) !== true) {
        throw new Exception('Gagal membuat arsip ZIP untuk berkas Excel.');
    }

    // Shared Strings Pool
    $strings = [];
    $strIndex = [];
    $addStr = function($text) use (&$strings, &$strIndex) {
        $text = (string)$text;
        if (isset($strIndex[$text])) {
            return $strIndex[$text];
        }
        $idx = count($strings);
        $strings[] = $text;
        $strIndex[$text] = $idx;
        return $idx;
    };

    /* ==============================================================================
     * CONTENT_TYPES.XML & WORKBOOK RELS
     * ============================================================================== */
    $contentTypes = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        .'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
        .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
        .'<Default Extension="xml" ContentType="application/xml"/>'
        .'<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
        .'<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
        .'<Override PartName="/xl/sharedStrings.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sharedStrings+xml"/>'
        .'<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
        .'<Override PartName="/xl/worksheets/sheet2.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
        .'</Types>';
    $zip->addFromString('[Content_Types].xml', $contentTypes);

    $relsRoot = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
        .'</Relationships>';
    $zip->addFromString('_rels/.rels', $relsRoot);

    $wbRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
        .'<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet2.xml"/>'
        .'<Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
        .'<Relationship Id="rId4" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/sharedStrings" Target="sharedStrings.xml"/>'
        .'</Relationships>';
    $zip->addFromString('xl/_rels/workbook.xml.rels', $wbRels);

    $workbookXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        .'<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
        .'<bookViews><workbookView xWindow="0" yWindow="0" windowWidth="24000" windowHeight="12000"/></bookViews>'
        .'<sheets>'
        .'<sheet name="Kalender Pendidikan" sheetId="1" r:id="rId1"/>'
        .'<sheet name="RPE Satuan Pendidikan" sheetId="2" r:id="rId2"/>'
        .'</sheets>'
        .'</workbook>';
    $zip->addFromString('xl/workbook.xml', $workbookXml);
    $zip->addFromString('xl/styles.xml', ilmuna_get_kaldik_xlsx_styles());

    /* ==============================================================================
     * SHEET 1: FULL KALENDER 1 TAHUN (MATRIKS 12 BULAN DENGAN HEADER HIJAU & LEGENDA)
     * ============================================================================== */
    $mergeCells1 = [];
    $rows1Xml = '';

    // Title Row
    $rows1Xml .= '<row r="1" ht="28" customHeight="1"><c r="A1" s="1" t="s"><v>'.$addStr('KALENDER PENDIDIKAN TAHUN PELAJARAN ' . $tahun).'</v></c></row>';
    $rows1Xml .= '<row r="2" ht="20" customHeight="1"><c r="A2" s="2" t="s"><v>'.$addStr('TINGKAT SATUAN PENDIDIKAN ILMUNA LEARN — SEMESTER GANJIL &amp; GENAP').'</v></c></row>';
    $rows1Xml .= '<row r="3" ht="18" customHeight="1"><c r="A3" s="3" t="s"><v>'.$addStr('Diterbitkan: '.date('d F Y, H:i').' WIB | Acuan: Libur Nasional SKB 3 Menteri &amp; Falakiyah NU Online').'</v></c></row>';
    $rows1Xml .= '<row r="4" ht="10" customHeight="1"></row>';
    $mergeCells1[] = 'A1:W1';
    $mergeCells1[] = 'A2:W2';
    $mergeCells1[] = 'A3:W3';

    // 12 Bulan dibagi dalam 4 Baris Blok (tiap baris memuat 3 bulan)
    // Blok 1: Juli, Agustus, September
    // Blok 2: Oktober, November, Desember
    // Blok 3: Januari, Februari, Maret
    // Blok 4: April, Mei, Juni
    $monthBlocks = [
        [['Juli', 7, $yearGanjil], ['Agustus', 8, $yearGanjil], ['September', 9, $yearGanjil]],
        [['Oktober', 10, $yearGanjil], ['November', 11, $yearGanjil], ['Desember', 12, $yearGanjil]],
        [['Januari', 1, $yearGenap], ['Februari', 2, $yearGenap], ['Maret', 3, $yearGenap]],
        [['April', 4, $yearGenap], ['Mei', 5, $yearGenap], ['Juni', 6, $yearGenap]],
    ];

    $blockColOffsets = [
        0 => 0,   // Kolom A s/d G (Idx 0..6)
        1 => 8,   // Kolom I s/d O (Idx 8..14)
        2 => 16,  // Kolom Q s/d W (Idx 16..22)
    ];

    $currentRow = 5;

    foreach ($monthBlocks as $bIdx => $threeMonths) {
        $headerRow = $currentRow;
        $dayHeaderRow = $currentRow + 1;
        $gridStartRow = $currentRow + 2;

        // Inisialisasi sel per baris
        $rowCells = [];
        for ($r = $headerRow; $r <= $gridStartRow + 5; $r++) {
            $rowCells[$r] = [];
        }

        foreach ($threeMonths as $colIdx => $mInfo) {
            $mName   = $mInfo[0];
            $mDigit  = $mInfo[1];
            $calYear = $mInfo[2];
            $baseCol = $blockColOffsets[$colIdx];
            $heCount = $hePerMonth[$mDigit] ?? 0;

            // 1. Month Header (Merge 7 Kolom)
            $startColLet = ilmuna_xlsx_col_letter($baseCol);
            $endColLet   = ilmuna_xlsx_col_letter($baseCol + 6);
            $mergeCells1[] = "{$startColLet}{$headerRow}:{$endColLet}{$headerRow}";

            $titleText = mb_strtoupper($mName) . " {$calYear} (" . $heCount . " HE)";
            $rowCells[$headerRow][$baseCol] = '<c r="'.$startColLet.$headerRow.'" s="4" t="s"><v>'.$addStr($titleText).'</v></c>';
            for ($c = 1; $c <= 6; $c++) {
                $cL = ilmuna_xlsx_col_letter($baseCol + $c);
                $rowCells[$headerRow][$baseCol + $c] = '<c r="'.$cL.$headerRow.'" s="4"/>';
            }

            // 2. Weekday Subheader: Sen, Sel, Rab, Kam, Jum, Sab, Min
            $dayLabels = ['Sen', 'Sel', 'Rab', 'Kam', 'Jum', 'Sab', 'Min'];
            for ($d = 0; $d < 7; $d++) {
                $cIndex = $baseCol + $d;
                $cL = ilmuna_xlsx_col_letter($cIndex);
                $isSun = ($d === 6);
                $st = $isSun ? 6 : 5;
                $rowCells[$dayHeaderRow][$cIndex] = '<c r="'.$cL.$dayHeaderRow.'" s="'.$st.'" t="s"><v>'.$addStr($dayLabels[$d]).'</v></c>';
            }

            // 3. Grid Tanggal Kalender (6 Baris)
            $daysInMonth = cal_days_in_month(CAL_GREGORIAN, $mDigit, $calYear);
            $firstDayOfWeek = (int)date('w', strtotime("$calYear-$mDigit-01"));
            $firstCol = ($firstDayOfWeek + 6) % 7; // 0=Senin ... 6=Minggu

            $dayNum = 1;
            for ($gRow = 0; $gRow < 6; $gRow++) {
                $rNum = $gridStartRow + $gRow;
                for ($gCol = 0; $gCol < 7; $gCol++) {
                    $cIndex = $baseCol + $gCol;
                    $cL = ilmuna_xlsx_col_letter($cIndex);

                    if ($gRow === 0 && $gCol < $firstCol) {
                        $rowCells[$rNum][$cIndex] = '<c r="'.$cL.$rNum.'" s="7"/>';
                    } elseif ($dayNum <= $daysInMonth) {
                        $dateStr = sprintf('%04d-%02d-%02d', $calYear, $mDigit, $dayNum);
                        $kItem = $kaldikMap[$dateStr] ?? null;
                        $isSun = ($gCol === 6);

                        // Pemilihan Style Warna Sesuai Status Kaldik:
                        // 9: KBM Hijau, 10: Libur Merah, 11: Libur Semester Abu-abu,
                        // 12: Kegiatan/Ujian Emas, 13: Keagamaan Ungu, 14: P5 Biru, 8: Plain
                        $styleId = 8;
                        if ($kItem) {
                            $stHari = $kItem['status_hari'] ?? '';
                            $ket = strtolower($kItem['keterangan'] ?? '');

                            if ($stHari === 'Efektif KBM') {
                                $styleId = 9;
                            } elseif ($stHari === 'Libur Nasional') {
                                $styleId = 10;
                            } elseif ($stHari === 'Libur Semester') {
                                $styleId = 11;
                            } elseif ($stHari === 'Kegiatan Sekolah/Ujian') {
                                if (strpos($ket, 'p5') !== false || strpos($ket, 'class meeting') !== false) {
                                    $styleId = 14;
                                } else {
                                    $styleId = 12;
                                }
                            } elseif ($stHari === 'Libur Khusus Keagamaan') {
                                $styleId = 13;
                            }
                        } else {
                            if ($isSun) {
                                $styleId = 10; // Minggu otomatis merah
                            }
                        }

                        $rowCells[$rNum][$cIndex] = '<c r="'.$cL.$rNum.'" s="'.$styleId.'"><v>'.$dayNum.'</v></c>';
                        $dayNum++;
                    } else {
                        $rowCells[$rNum][$cIndex] = '<c r="'.$cL.$rNum.'" s="7"/>';
                    }
                }
            }
        }

        // Tulis Baris Header Blok Bulan
        $hCells = '';
        ksort($rowCells[$headerRow]);
        foreach ($rowCells[$headerRow] as $cXml) $hCells .= $cXml;
        $rows1Xml .= '<row r="'.$headerRow.'" ht="26" customHeight="1">'.$hCells.'</row>';

        // Tulis Baris Hari (Sen s/d Min)
        $dhCells = '';
        ksort($rowCells[$dayHeaderRow]);
        foreach ($rowCells[$dayHeaderRow] as $cXml) $dhCells .= $cXml;
        $rows1Xml .= '<row r="'.$dayHeaderRow.'" ht="20" customHeight="1">'.$dhCells.'</row>';

        // Tulis 6 Baris Tanggal
        for ($gRow = 0; $gRow < 6; $gRow++) {
            $rNum = $gridStartRow + $gRow;
            $gCells = '';
            ksort($rowCells[$rNum]);
            foreach ($rowCells[$rNum] as $cXml) $gCells .= $cXml;
            $rows1Xml .= '<row r="'.$rNum.'" ht="22" customHeight="1">'.$gCells.'</row>';
        }

        // Baris Pemisah antar Blok Bulan
        $currentRow = $gridStartRow + 6;
        $rows1Xml .= '<row r="'.$currentRow.'" ht="10" customHeight="1"></row>';
        $currentRow++;
    }

    // ==============================================================================
    // LEGENDA WARNA KALDIK
    // ==============================================================================
    $rows1Xml .= '<row r="'.$currentRow.'" ht="24" customHeight="1"><c r="A'.$currentRow.'" s="22" t="s"><v>'.$addStr('LEGENDA WARNA &amp; KETERANGAN KALENDER PENDIDIKAN').'</v></c></row>';
    $mergeCells1[] = "A{$currentRow}:W{$currentRow}";
    $currentRow++;

    // Item Legenda Baris 1
    $legRow1 = $currentRow;
    $rows1Xml .= '<row r="'.$legRow1.'" ht="22" customHeight="1">'
        .'<c r="A'.$legRow1.'" s="9" t="s"><v>'.$addStr('EFEKTIF KBM').'</v></c>'
        .'<c r="B'.$legRow1.'" s="16" t="s"><v>'.$addStr('Hari Belajar Efektif / Tatap Muka Reguler (Hijau)').'</v></c>'
        .'<c r="I'.$legRow1.'" s="10" t="s"><v>'.$addStr('LIBUR NASIONAL').'</v></c>'
        .'<c r="J'.$legRow1.'" s="16" t="s"><v>'.$addStr('Hari Libur Nasional Keputusan SKB 3 Menteri (Merah)').'</v></c>'
        .'<c r="Q'.$legRow1.'" s="11" t="s"><v>'.$addStr('LIBUR SEMESTER').'</v></c>'
        .'<c r="R'.$legRow1.'" s="16" t="s"><v>'.$addStr('Libur Akhir Pekan (Minggu) &amp; Jeda Libur Semester (Abu-abu)').'</v></c>'
        .'</row>';
    $mergeCells1[] = "B{$legRow1}:G{$legRow1}";
    $mergeCells1[] = "J{$legRow1}:O{$legRow1}";
    $mergeCells1[] = "R{$legRow1}:W{$legRow1}";
    $currentRow++;

    // Item Legenda Baris 2
    $legRow2 = $currentRow;
    $rows1Xml .= '<row r="'.$legRow2.'" ht="22" customHeight="1">'
        .'<c r="A'.$legRow2.'" s="12" t="s"><v>'.$addStr('UJIAN / KEGIATAN').'</v></c>'
        .'<c r="B'.$legRow2.'" s="16" t="s"><v>'.$addStr('Asesmen Formatif, Sumatif, PTS, PAS, &amp; MPLS (Emas)').'</v></c>'
        .'<c r="I'.$legRow2.'" s="13" t="s"><v>'.$addStr('KEAGAMAAN').'</v></c>'
        .'<c r="J'.$legRow2.'" s="16" t="s"><v>'.$addStr('Libur Awal Puasa, Idul Fitri, &amp; Falakiyah NU Online (Ungu)').'</v></c>'
        .'<c r="Q'.$legRow2.'" s="14" t="s"><v>'.$addStr('P5 / CLASS MEETING').'</v></c>'
        .'<c r="R'.$legRow2.'" s="16" t="s"><v>'.$addStr('Projek Pelajar Pancasila &amp; Class Meeting (Biru Langit)').'</v></c>'
        .'</row>';
    $mergeCells1[] = "B{$legRow2}:G{$legRow2}";
    $mergeCells1[] = "J{$legRow2}:O{$legRow2}";
    $mergeCells1[] = "R{$legRow2}:W{$legRow2}";
    $currentRow++;

    // Spasi
    $rows1Xml .= '<row r="'.$currentRow.'" ht="14" customHeight="1"></row>';
    $currentRow++;

    // ==============================================================================
    // DAFTAR AGENDA & HARI LIBUR TAHUN PELAJARAN
    // ==============================================================================
    $rows1Xml .= '<row r="'.$currentRow.'" ht="24" customHeight="1"><c r="A'.$currentRow.'" s="22" t="s"><v>'.$addStr('RINCIAN AGENDA, KEGIATAN SEKOLAH, &amp; LIBUR RESMI TAHUN PELAJARAN ' . $tahun).'</v></c></row>';
    $mergeCells1[] = "A{$currentRow}:W{$currentRow}";
    $currentRow++;

    // Table Header Agenda
    $hAgendaRow = $currentRow;
    $rows1Xml .= '<row r="'.$hAgendaRow.'" ht="25" customHeight="1">'
        .'<c r="A'.$hAgendaRow.'" s="4" t="s"><v>'.$addStr('NO').'</v></c>'
        .'<c r="B'.$hAgendaRow.'" s="4" t="s"><v>'.$addStr('TANGGAL').'</v></c>'
        .'<c r="E'.$hAgendaRow.'" s="4" t="s"><v>'.$addStr('HARI').'</v></c>'
        .'<c r="G'.$hAgendaRow.'" s="4" t="s"><v>'.$addStr('STATUS HARI').'</v></c>'
        .'<c r="K'.$hAgendaRow.'" s="15" t="s"><v>'.$addStr('URAIAN AGENDA / KEGIATAN SEKOLAH').'</v></c>'
        .'</row>';
    $mergeCells1[] = "B{$hAgendaRow}:D{$hAgendaRow}";
    $mergeCells1[] = "E{$hAgendaRow}:F{$hAgendaRow}";
    $mergeCells1[] = "G{$hAgendaRow}:J{$hAgendaRow}";
    $mergeCells1[] = "K{$hAgendaRow}:W{$hAgendaRow}";
    $currentRow++;

    if (empty($agendaList)) {
        $emptyRow = $currentRow;
        $rows1Xml .= '<row r="'.$emptyRow.'" ht="22" customHeight="1"><c r="A'.$emptyRow.'" s="16" t="s"><v>'.$addStr('Tidak ada agenda khusus atau seluruh hari berstatus KBM Reguler.').'</v></c></row>';
        $mergeCells1[] = "A{$emptyRow}:W{$emptyRow}";
        $currentRow++;
    } else {
        $noAg = 1;
        foreach ($agendaList as $ag) {
            $isZebra = ($noAg % 2 === 0);
            $sL = $isZebra ? 18 : 16;
            $sC = $isZebra ? 19 : 17;

            $dFmt = date('d F Y', strtotime($ag['tanggal']));
            $hName = $ag['hari'] ?? getNamaHariIndo($ag['tanggal']);
            $stHari = $ag['status_hari'] ?? '';
            $ketAg = $ag['keterangan'] ?? '';

            $agRow = $currentRow;
            $rows1Xml .= '<row r="'.$agRow.'" ht="22" customHeight="1">'
                .'<c r="A'.$agRow.'" s="'.$sC.'"><v>'.$noAg.'</v></c>'
                .'<c r="B'.$agRow.'" s="'.$sC.'" t="s"><v>'.$addStr($dFmt).'</v></c>'
                .'<c r="E'.$agRow.'" s="'.$sC.'" t="s"><v>'.$addStr($hName).'</v></c>'
                .'<c r="G'.$agRow.'" s="'.$sC.'" t="s"><v>'.$addStr($stHari).'</v></c>'
                .'<c r="K'.$agRow.'" s="'.$sL.'" t="s"><v>'.$addStr($ketAg).'</v></c>'
                .'</row>';
            $mergeCells1[] = "B{$agRow}:D{$agRow}";
            $mergeCells1[] = "E{$agRow}:F{$agRow}";
            $mergeCells1[] = "G{$agRow}:J{$agRow}";
            $mergeCells1[] = "K{$agRow}:W{$agRow}";
            $currentRow++;
            $noAg++;
        }
    }

    // Definisi Kolom Lebar Sheet 1
    // A..G (K1): ~5.2, H (Gap): 2.5, I..O (K2): ~5.2, P (Gap): 2.5, Q..W (K3): ~5.2
    $cols1Xml = '<cols>'
        .'<col min="1" max="7" width="5.5" customWidth="1"/>'
        .'<col min="8" max="8" width="2.5" customWidth="1"/>'
        .'<col min="9" max="15" width="5.5" customWidth="1"/>'
        .'<col min="16" max="16" width="2.5" customWidth="1"/>'
        .'<col min="17" max="23" width="5.5" customWidth="1"/>'
        .'</cols>';

    $mergeXml1 = '';
    if (!empty($mergeCells1)) {
        $mergeXml1 = '<mergeCells count="'.count($mergeCells1).'">';
        foreach ($mergeCells1 as $mRef) {
            $mergeXml1 .= '<mergeCell ref="'.$mRef.'"/>';
        }
        $mergeXml1 .= '</mergeCells>';
    }

    $sheet1Xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        .'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        .'<sheetViews><sheetView workbookViewId="0"><pane ySplit="4" topLeftCell="A5" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>'
        .$cols1Xml
        .'<sheetData>'.$rows1Xml.'</sheetData>'
        .$mergeXml1
        .'</worksheet>';
    $zip->addFromString('xl/worksheets/sheet1.xml', $sheet1Xml);

    /* ==============================================================================
     * SHEET 2: RINCIAN PEKAN EFEKTIF (RPE) TINGKAT SATUAN PENDIDIKAN
     * ============================================================================== */
    $mergeCells2 = [];
    $rows2Xml = '';

    // Sheet 2 Title
    $rows2Xml .= '<row r="1" ht="28" customHeight="1"><c r="A1" s="1" t="s"><v>'.$addStr('RINCIAN PEKAN EFEKTIF (RPE) TINGKAT SATUAN PENDIDIKAN').'</v></c></row>';
    $rows2Xml .= '<row r="2" ht="20" customHeight="1"><c r="A2" s="2" t="s"><v>'.$addStr('TAHUN PELAJARAN ' . $tahun . ' — ILMUNA LEARN').'</v></c></row>';
    $rows2Xml .= '<row r="3" ht="10" customHeight="1"></row>';
    $mergeCells2[] = 'A1:M1';
    $mergeCells2[] = 'A2:M2';

    $curR2 = 4;

    // Helper Fungsi Render Tabel RPE Semester
    $renderRpeTable = function($semTitle, $rpeData, &$curR2, &$rows2Xml, &$mergeCells2) use ($addStr) {
        // Section Header
        $rows2Xml .= '<row r="'.$curR2.'" ht="25" customHeight="1"><c r="A'.$curR2.'" s="22" t="s"><v>'.$addStr($semTitle).'</v></c></row>';
        $mergeCells2[] = "A{$curR2}:M{$curR2}";
        $curR2++;

        // Table Header
        $hRow = $curR2;
        $headers = [
            'A' => ['NO', 4],
            'B' => ['BULAN', 15],
            'C' => ['SENIN', 4],
            'D' => ['SELASA', 4],
            'E' => ['RABU', 4],
            'F' => ['KAMIS', 4],
            'G' => ['JUMAT', 4],
            'H' => ['SABTU', 4],
            'I' => ['TOTAL HE', 4],
            'J' => ['JML PEKAN', 4],
            'K' => ['PEKAN EFEKTIF', 4],
            'L' => ['PEKAN TIDAK EFEKTIF', 4],
            'M' => ['KETERANGAN NON-EFEKTIF / AGENDA', 15],
        ];
        $hCells = '';
        foreach ($headers as $colL => $hInfo) {
            $hCells .= '<c r="'.$colL.$hRow.'" s="'.$hInfo[1].'" t="s"><v>'.$addStr($hInfo[0]).'</v></c>';
        }
        $rows2Xml .= '<row r="'.$hRow.'" ht="26" customHeight="1">'.$hCells.'</row>';
        $curR2++;

        // Data Rows
        $no = 1;
        foreach ($rpeData['rows'] as $r) {
            $isZebra = ($no % 2 === 0);
            $sL = $isZebra ? 18 : 16;
            $sC = $isZebra ? 19 : 17;

            $dRow = $curR2;
            $rows2Xml .= '<row r="'.$dRow.'" ht="22" customHeight="1">'
                .'<c r="A'.$dRow.'" s="'.$sC.'"><v>'.$no.'</v></c>'
                .'<c r="B'.$dRow.'" s="'.$sL.'" t="s"><v>'.$addStr($r['bulan'].' '.$r['tahun']).'</v></c>'
                .'<c r="C'.$dRow.'" s="'.$sC.'"><v>'.$r['senin'].'</v></c>'
                .'<c r="D'.$dRow.'" s="'.$sC.'"><v>'.$r['selasa'].'</v></c>'
                .'<c r="E'.$dRow.'" s="'.$sC.'"><v>'.$r['rabu'].'</v></c>'
                .'<c r="F'.$dRow.'" s="'.$sC.'"><v>'.$r['kamis'].'</v></c>'
                .'<c r="G'.$dRow.'" s="'.$sC.'"><v>'.$r['jumat'].'</v></c>'
                .'<c r="H'.$dRow.'" s="'.$sC.'"><v>'.$r['sabtu'].'</v></c>'
                .'<c r="I'.$dRow.'" s="'.$sC.'"><v>'.$r['total_he'].'</v></c>'
                .'<c r="J'.$dRow.'" s="'.$sC.'"><v>'.$r['pekan_total'].'</v></c>'
                .'<c r="K'.$dRow.'" s="'.$sC.'"><v>'.$r['pekan_efektif'].'</v></c>'
                .'<c r="L'.$dRow.'" s="'.$sC.'"><v>'.$r['pekan_tidak_efektif'].'</v></c>'
                .'<c r="M'.$dRow.'" s="'.$sL.'" t="s"><v>'.$addStr($r['keterangan']).'</v></c>'
                .'</row>';
            $curR2++;
            $no++;
        }

        // Summary Row
        $tot = $rpeData['totals'];
        $sumRow = $curR2;
        $rows2Xml .= '<row r="'.$sumRow.'" ht="25" customHeight="1">'
            .'<c r="A'.$sumRow.'" s="20" t="s"><v>'.$addStr('JUMLAH').'</v></c>'
            .'<c r="B'.$sumRow.'" s="20"/>'
            .'<c r="C'.$sumRow.'" s="21"><v>'.$tot['senin'].'</v></c>'
            .'<c r="D'.$sumRow.'" s="21"><v>'.$tot['selasa'].'</v></c>'
            .'<c r="E'.$sumRow.'" s="21"><v>'.$tot['rabu'].'</v></c>'
            .'<c r="F'.$sumRow.'" s="21"><v>'.$tot['kamis'].'</v></c>'
            .'<c r="G'.$sumRow.'" s="21"><v>'.$tot['jumat'].'</v></c>'
            .'<c r="H'.$sumRow.'" s="21"><v>'.$tot['sabtu'].'</v></c>'
            .'<c r="I'.$sumRow.'" s="21"><v>'.$tot['total_he'].'</v></c>'
            .'<c r="J'.$sumRow.'" s="21"><v>'.$tot['pekan_total'].'</v></c>'
            .'<c r="K'.$sumRow.'" s="21"><v>'.$tot['pekan_efektif'].'</v></c>'
            .'<c r="L'.$sumRow.'" s="21"><v>'.$tot['pekan_tidak_efektif'].'</v></c>'
            .'<c r="M'.$sumRow.'" s="20" t="s"><v>'.$addStr('-').'</v></c>'
            .'</row>';
        $mergeCells2[] = "A{$sumRow}:B{$sumRow}";
        $curR2++;

        // Spasi
        $rows2Xml .= '<row r="'.$curR2.'" ht="14" customHeight="1"></row>';
        $curR2++;
    };

    // 1. Render Tabel Semester Ganjil
    $renderRpeTable('I. SEMESTER GANJIL (JULI — DESEMBER)', $rpeGanjil, $curR2, $rows2Xml, $mergeCells2);

    // 2. Render Tabel Semester Genap
    $renderRpeTable('II. SEMESTER GENAP (JANUARI — JUNI)', $rpeGenap, $curR2, $rows2Xml, $mergeCells2);

    // 3. Rekapitulasi Tahunan (Ganjil + Genap)
    $grandHe = $rpeGanjil['totals']['total_he'] + $rpeGenap['totals']['total_he'];
    $grandPekan = $rpeGanjil['totals']['pekan_total'] + $rpeGenap['totals']['pekan_total'];
    $grandPe = $rpeGanjil['totals']['pekan_efektif'] + $rpeGenap['totals']['pekan_efektif'];
    $grandPte = $rpeGanjil['totals']['pekan_tidak_efektif'] + $rpeGenap['totals']['pekan_tidak_efektif'];

    $rows2Xml .= '<row r="'.$curR2.'" ht="25" customHeight="1"><c r="A'.$curR2.'" s="22" t="s"><v>'.$addStr('III. REKAPITULASI TAHUNAN (SEMESTER GANJIL + GENAP)').'</v></c></row>';
    $mergeCells2[] = "A{$curR2}:M{$curR2}";
    $curR2++;

    $rekapItems = [
        ['Total Hari Efektif (HE) KBM 1 Tahun', $grandHe . ' Hari'],
        ['Total Pekan Kalender Pendidikan 1 Tahun', $grandPekan . ' Pekan'],
        ['Total Pekan Efektif KBM (RPE) 1 Tahun', $grandPe . ' Pekan'],
        ['Total Pekan Tidak Efektif 1 Tahun', $grandPte . ' Pekan'],
    ];

    foreach ($rekapItems as $rk) {
        $rkRow = $curR2;
        $rows2Xml .= '<row r="'.$rkRow.'" ht="22" customHeight="1">'
            .'<c r="A'.$rkRow.'" s="16" t="s"><v>'.$addStr('• ' . $rk[0]).'</v></c>'
            .'<c r="F'.$rkRow.'" s="21" t="s"><v>'.$addStr($rk[1]).'</v></c>'
            .'</row>';
        $mergeCells2[] = "A{$rkRow}:E{$rkRow}";
        $mergeCells2[] = "F{$rkRow}:G{$rkRow}";
        $curR2++;
    }

    // Tanda Tangan
    $curR2 += 2;
    $signDateRow = $curR2;
    $rows2Xml .= '<row r="'.$signDateRow.'" ht="20" customHeight="1">'
        .'<c r="J'.$signDateRow.'" s="16" t="s"><v>'.$addStr('Ditetapkan di: Satuan Pendidikan, '.date('d F Y')).'</v></c>'
        .'</row>';
    $mergeCells2[] = "J{$signDateRow}:M{$signDateRow}";
    $curR2++;

    $signTitleRow = $curR2;
    $rows2Xml .= '<row r="'.$signTitleRow.'" ht="20" customHeight="1">'
        .'<c r="B'.$signTitleRow.'" s="17" t="s"><v>'.$addStr('Waka. Bidang Kurikulum').'</v></c>'
        .'<c r="J'.$signTitleRow.'" s="17" t="s"><v>'.$addStr('Kepala Satuan Pendidikan').'</v></c>'
        .'</row>';
    $mergeCells2[] = "B{$signTitleRow}:D{$signTitleRow}";
    $mergeCells2[] = "J{$signTitleRow}:M{$signTitleRow}";
    $curR2 += 4; // Space for signature

    $signNameRow = $curR2;
    $rows2Xml .= '<row r="'.$signNameRow.'" ht="20" customHeight="1">'
        .'<c r="B'.$signNameRow.'" s="21" t="s"><v>'.$addStr('( Tim Kurikulum / Waka )').'</v></c>'
        .'<c r="J'.$signNameRow.'" s="21" t="s"><v>'.$addStr('( Kepala Satuan Pendidikan )').'</v></c>'
        .'</row>';
    $mergeCells2[] = "B{$signNameRow}:D{$signNameRow}";
    $mergeCells2[] = "J{$signNameRow}:M{$signNameRow}";

    // Lebar Kolom Sheet 2
    $cols2Xml = '<cols>'
        .'<col min="1" max="1" width="6" customWidth="1"/>'
        .'<col min="2" max="2" width="20" customWidth="1"/>'
        .'<col min="3" max="8" width="9.5" customWidth="1"/>'
        .'<col min="9" max="9" width="13" customWidth="1"/>'
        .'<col min="10" max="10" width="13" customWidth="1"/>'
        .'<col min="11" max="11" width="16" customWidth="1"/>'
        .'<col min="12" max="12" width="19" customWidth="1"/>'
        .'<col min="13" max="13" width="45" customWidth="1"/>'
        .'</cols>';

    $mergeXml2 = '';
    if (!empty($mergeCells2)) {
        $mergeXml2 = '<mergeCells count="'.count($mergeCells2).'">';
        foreach ($mergeCells2 as $mRef) {
            $mergeXml2 .= '<mergeCell ref="'.$mRef.'"/>';
        }
        $mergeXml2 .= '</mergeCells>';
    }

    $sheet2Xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        .'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        .'<sheetViews><sheetView workbookViewId="0"><pane ySplit="3" topLeftCell="A4" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>'
        .$cols2Xml
        .'<sheetData>'.$rows2Xml.'</sheetData>'
        .$mergeXml2
        .'</worksheet>';
    $zip->addFromString('xl/worksheets/sheet2.xml', $sheet2Xml);

    // Shared Strings XML
    $zip->addFromString('xl/sharedStrings.xml', ilmuna_build_shared_strings_xml($strings));

    if (!$zip->close()) {
        @unlink($tmpFile);
        throw new Exception('Gagal menyimpan dan menutup arsip berkas Excel.');
    }

    if (!file_exists($tmpFile) || filesize($tmpFile) === 0) {
        @unlink($tmpFile);
        throw new Exception('Berkas Excel hasil pembuatan berukuran kosong.');
    }

    return $tmpFile;
}

/* ==============================================================================
 * ROUTER: HANDLE DIRECT DOWNLOAD OR CLI
 * ============================================================================== */

if (php_sapi_name() === 'cli' && basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    echo "CLI mode: generating test Excel...\n";
    $testYear = '2026/2027';
    $file = ilmuna_generate_kaldik_excel_workbook($pdo, $testYear);
    echo "Generated successfully: " . $file . " (size: " . filesize($file) . " bytes)\n";
    exit;
}

// Web Request:
$tahun = trim($_GET['tahun'] ?? '2026/2027');
if (!preg_match('/^\d{4}\/\d{4}$/', $tahun)) {
    $tahun = '2026/2027';
}

try {
    $excelPath = ilmuna_generate_kaldik_excel_workbook($pdo, $tahun);
    $filename = 'kalender-pendidikan-rpe-' . str_replace('/', '-', $tahun) . '-' . date('Ymd-His') . '.xlsx';

    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Access-Control-Expose-Headers: Content-Disposition');
    header('Cache-Control: max-age=0, no-cache, no-store, must-revalidate');
    header('Pragma: public');
    header('Content-Length: ' . filesize($excelPath));

    readfile($excelPath);
    @unlink($excelPath);
    exit;
} catch (Throwable $e) {
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'success' => false,
        'data' => [
            'message' => 'Gagal mengekspor Kalender Pendidikan ke Excel: ' . $e->getMessage()
        ]
    ]);
    exit;
}
