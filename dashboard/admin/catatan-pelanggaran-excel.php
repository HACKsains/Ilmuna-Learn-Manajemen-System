<?php
/**
 * ==============================================================================
 * ILMUNA LEARN — BACKEND EXCEL HANDLER (CATATAN PELANGGARAN SISWA)
 * ==============================================================================
 * Menyamai 100% arsitektur & tema Lumina Gold dari teachers-excel.php:
 * - Standar Output: OpenXML Spreadsheet (.xlsx) 2 Worksheet:
 *   1. Sheet 1: "Catatan Pelanggaran Siswa" (Log detail Grup WA & Realitas)
 *   2. Sheet 2: "Rekap Poin Per Siswa" (Akumulasi kasus & poin per siswa)
 * - Engine: Hybrid IlmunaZipArchive (Native ZipArchive / Pure-PHP Deflate Zero-Failsafe)
 * - Tema: Lumina Gold (Navy #1D3B5E, Teal #0D7A87, Gold #F59E0B)
 * - Dukungan Download: JavaScript Blob Method (Expose Content-Disposition Header)
 * - Hak Akses:
 *   - Admin: Rekap penuh lintas seluruh kelas, mapel, dan guru (dengan filter opsional)
 *   - Guru : Terbatas ketat hanya pada kelas & mata pelajaran yang diampu
 * ==============================================================================
 */

date_default_timezone_set('Asia/Jakarta');

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../auth.php';
require_once __DIR__ . '/../../includes/academic-sync-engine.php';

$isCli = (php_sapi_name() === 'cli');
$currentUser = null;
$userRole = 'admin';

if (!$isCli) {
    requireRole(['admin', 'guru', 'guru_bk']);
    $currentUser = getCurrentUser();
    $activeRole = strtolower((string)($currentUser['active_role'] ?? ''));
    $isStrictGuru = ($activeRole === 'guru') || (!hasRole('admin') && !hasRole('guru_bk') && hasRole('guru'));
    $userRole = $isStrictGuru ? 'guru' : ($activeRole === 'guru_bk' ? 'guru_bk' : 'admin');
}

$pdo = getDBConnection();
$pdo->exec("SET time_zone = '+07:00'");

/* ==============================================================================
 * 1. OPENXML XLSX UTILITIES & THEME ENGINE (IDENTIK DENGAN teachers-excel.php)
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

        public function getStatusString() {
            if ($this->nativeZip && method_exists($this->nativeZip, 'getStatusString')) {
                return $this->nativeZip->getStatusString();
            }
            return 'OK';
        }

        private static function buildPureZipData($files) {
            $time = time();
            $dostime = ((date('Y', $time) - 1980) << 25)
                     | (date('m', $time) << 21)
                     | (date('d', $time) << 16)
                     | (date('H', $time) << 11)
                     | (date('i', $time) << 5)
                     | (date('s', $time) >> 1);

            $body = '';
            $cd = '';
            $count = 0;

            foreach ($files as $name => $data) {
                $name = str_replace('\\', '/', $name);
                $uncLen = strlen($data);
                $crc = crc32($data);

                if (function_exists('gzdeflate')) {
                    $cData = gzdeflate($data);
                    $cLen = strlen($cData);
                    $method = 8;
                } else {
                    $cData = $data;
                    $cLen = $uncLen;
                    $method = 0;
                }

                $offset = strlen($body);

                $body .= "\x50\x4b\x03\x04"
                    . pack('v', 20)
                    . pack('v', 0)
                    . pack('v', $method)
                    . pack('V', $dostime)
                    . pack('V', $crc)
                    . pack('V', $cLen)
                    . pack('V', $uncLen)
                    . pack('v', strlen($name))
                    . pack('v', 0)
                    . $name
                    . $cData;

                $cd .= "\x50\x4b\x01\x02"
                    . pack('v', 0x0314)
                    . pack('v', 20)
                    . pack('v', 0)
                    . pack('v', $method)
                    . pack('V', $dostime)
                    . pack('V', $crc)
                    . pack('V', $cLen)
                    . pack('V', $uncLen)
                    . pack('v', strlen($name))
                    . pack('v', 0)
                    . pack('v', 0)
                    . pack('v', 0)
                    . pack('v', 0)
                    . pack('V', 32)
                    . pack('V', $offset)
                    . $name;

                $count++;
            }

            $cdOffset = strlen($body);
            $cdLen = strlen($cd);

            $eocd = "\x50\x4b\x05\x06"
                . pack('v', 0)
                . pack('v', 0)
                . pack('v', $count)
                . pack('v', $count)
                . pack('V', $cdLen)
                . pack('V', $cdOffset)
                . pack('v', 0);

            return $body . $cd . $eocd;
        }
    }
}

if (!function_exists('ilmuna_create_temp_xlsx')) {
    function ilmuna_create_temp_xlsx($prefix = 'ilmuna_') {
        $randomSuffix = date('Ymd_His') . '_' . bin2hex(random_bytes(6)) . '.xlsx';

        $uploadsDir = __DIR__ . '/../../uploads';
        if (!is_dir($uploadsDir)) {
            @mkdir($uploadsDir, 0777, true);
        }
        if (is_dir($uploadsDir) && is_writable($uploadsDir)) {
            $path = $uploadsDir . '/' . $prefix . $randomSuffix;
            if (file_exists($path)) {
                @unlink($path);
            }
            return $path;
        }

        $laragonTmp = 'C:/laragon/tmp';
        if (is_dir($laragonTmp) && is_writable($laragonTmp)) {
            $path = $laragonTmp . '/' . $prefix . $randomSuffix;
            if (file_exists($path)) {
                @unlink($path);
            }
            return $path;
        }

        $sysTmp = sys_get_temp_dir();
        $path = $sysTmp . DIRECTORY_SEPARATOR . $prefix . $randomSuffix;
        if (file_exists($path)) {
            @unlink($path);
        }
        return $path;
    }
}

if (!function_exists('ilmuna_get_xlsx_theme_styles')) {
    function ilmuna_get_xlsx_theme_styles() {
        return '<?xml version="1.0" encoding="UTF-8"?>'
            .'<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            .'<fonts count="9">'
            .'<font><sz val="10"/><name val="Segoe UI"/></font>'
            .'<font><b/><sz val="15"/><color rgb="FF1D3B5E"/><name val="Segoe UI"/></font>'
            .'<font><b/><sz val="11"/><color rgb="FF0D7A87"/><name val="Segoe UI"/></font>'
            .'<font><i/><sz val="9.5"/><color rgb="FF64748B"/><name val="Segoe UI"/></font>'
            .'<font><b/><sz val="10"/><color rgb="FFFFFFFF"/><name val="Segoe UI"/></font>'
            .'<font><sz val="10"/><color rgb="FF1E293B"/><name val="Segoe UI"/></font>'
            .'<font><b/><sz val="10"/><color rgb="FF1D3B5E"/><name val="Segoe UI"/></font>'
            .'<font><sz val="9.5"/><color rgb="FF64748B"/><name val="Segoe UI"/></font>'
            .'<font><b/><sz val="10"/><color rgb="FF0F172A"/><name val="Segoe UI"/></font>'
            .'</fonts>'
            .'<fills count="5">'
            .'<fill><patternFill patternType="none"/></fill>'
            .'<fill><patternFill patternType="gray125"/></fill>'
            .'<fill><patternFill patternType="solid"><fgColor rgb="FF1D3B5E"/></patternFill></fill>'
            .'<fill><patternFill patternType="solid"><fgColor rgb="FFF8FAFC"/></patternFill></fill>'
            .'<fill><patternFill patternType="solid"><fgColor rgb="FFE2E8F0"/></patternFill></fill>'
            .'</fills>'
            .'<borders count="4">'
            .'<border><left/><right/><top/><bottom/></border>'
            .'<border>'
            .'<left style="thin"><color rgb="FFCBD5E1"/></left>'
            .'<right style="thin"><color rgb="FFCBD5E1"/></right>'
            .'<top style="thin"><color rgb="FFCBD5E1"/></top>'
            .'<bottom style="thin"><color rgb="FFCBD5E1"/></bottom>'
            .'</border>'
            .'<border>'
            .'<left style="thin"><color rgb="FF334155"/></left>'
            .'<right style="thin"><color rgb="FF334155"/></right>'
            .'<top style="thin"><color rgb="FF334155"/></top>'
            .'<bottom style="medium"><color rgb="FF0D7A87"/></bottom>'
            .'</border>'
            .'<border>'
            .'<left style="thin"><color rgb="FF94A3B8"/></left>'
            .'<right style="thin"><color rgb="FF94A3B8"/></right>'
            .'<top style="thin"><color rgb="FF94A3B8"/></top>'
            .'<bottom style="double"><color rgb="FF1D3B5E"/></bottom>'
            .'</border>'
            .'</borders>'
            .'<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            .'<cellXfs count="14">'
            .'<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
            .'<xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0"><alignment vertical="center"/></xf>'
            .'<xf numFmtId="0" fontId="2" fillId="0" borderId="0" xfId="0"><alignment vertical="center"/></xf>'
            .'<xf numFmtId="0" fontId="3" fillId="0" borderId="0" xfId="0"><alignment vertical="center"/></xf>'
            .'<xf numFmtId="0" fontId="4" fillId="2" borderId="2" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center" wrapText="1"/></xf>'
            .'<xf numFmtId="0" fontId="4" fillId="2" borderId="2" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="left" vertical="center" wrapText="1"/></xf>'
            .'<xf numFmtId="0" fontId="5" fillId="0" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="left" vertical="center"/></xf>'
            .'<xf numFmtId="0" fontId="5" fillId="0" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>'
            .'<xf numFmtId="0" fontId="6" fillId="0" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="left" vertical="center"/></xf>'
            .'<xf numFmtId="0" fontId="5" fillId="3" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="left" vertical="center"/></xf>'
            .'<xf numFmtId="0" fontId="5" fillId="3" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>'
            .'<xf numFmtId="0" fontId="6" fillId="3" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="left" vertical="center"/></xf>'
            .'<xf numFmtId="0" fontId="8" fillId="4" borderId="3" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="right" vertical="center"/></xf>'
            .'<xf numFmtId="0" fontId="8" fillId="4" borderId="3" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>'
            .'</cellXfs>'
            .'</styleSheet>';
    }
}

/* ==============================================================================
 * 2. SHEET 2 BUILDER: REKAP AKUMULASI POIN PELANGGARAN PER SISWA
 * ============================================================================== */
function ilmuna_build_pelanggaran_rekap_sheet2_xml(array $rekapSiswa, callable $addStr): string {
    $rowsXml = '';

    // Row 1: Title
    $rowsXml .= '<row r="1" ht="24" customHeight="1"><c r="A1" s="1" t="s"><v>'.$addStr('REKAP AKUMULASI POIN PELANGGARAN PER SISWA').'</v></c></row>';

    // Row 2: Subtitle
    $petunjuk = 'Ringkasan total kasus pelanggaran (Grup WA & Realitas) serta akumulasi poin per siswa, diurutkan dari akumulasi poin tertinggi.';
    $rowsXml .= '<row r="2" ht="20" customHeight="1"><c r="A2" s="2" t="s"><v>'.$addStr($petunjuk).'</v></c></row>';

    // Row 3: Blank separator
    $rowsXml .= '<row r="3" ht="10" customHeight="1"></row>';

    // Row 4: Table Headers (A to I)
    $headers = [
        ['col' => 'A', 'title' => 'NO', 'style' => 4],
        ['col' => 'B', 'title' => 'NIS', 'style' => 4],
        ['col' => 'C', 'title' => 'NAMA LENGKAP SISWA', 'style' => 5],
        ['col' => 'D', 'title' => 'KELAS', 'style' => 4],
        ['col' => 'E', 'title' => 'KASUS GRUP WA', 'style' => 4],
        ['col' => 'F', 'title' => 'KASUS REALITAS', 'style' => 4],
        ['col' => 'G', 'title' => 'TOTAL KASUS', 'style' => 4],
        ['col' => 'H', 'title' => 'AKUMULASI POIN', 'style' => 4],
        ['col' => 'I', 'title' => 'KEJADIAN TERAKHIR', 'style' => 4],
    ];
    $hCells = '';
    foreach ($headers as $h) {
        $hCells .= '<c r="'.$h['col'].'4" s="'.$h['style'].'" t="s"><v>'.$addStr($h['title']).'</v></c>';
    }
    $rowsXml .= '<row r="4" ht="28" customHeight="1">'.$hCells.'</row>';

    // Rows 5+: Data rows
    $rIdx = 5;
    $sumWa = 0;
    $sumReal = 0;
    $sumKasus = 0;
    $sumPoin = 0;

    foreach ($rekapSiswa as $s) {
        $isZebra = ($rIdx % 2 === 1);
        $sC = $isZebra ? 10 : 7;
        $sB = $isZebra ? 11 : 8;

        $kWa    = (int)($s['kasus_wa'] ?? 0);
        $kReal  = (int)($s['kasus_real'] ?? 0);
        $kTot   = (int)($s['total_kasus'] ?? 0);
        $kPoin  = (int)($s['total_poin'] ?? 0);

        $sumWa    += $kWa;
        $sumReal  += $kReal;
        $sumKasus += $kTot;
        $sumPoin  += $kPoin;

        $cRow = '';
        $cRow .= '<c r="A'.$rIdx.'" s="'.$sC.'"><v>'.($rIdx - 4).'</v></c>';
        $cRow .= '<c r="B'.$rIdx.'" s="'.$sC.'" t="s"><v>'.$addStr(($s['nis'] ?? '') !== '' ? $s['nis'] : '-').'</v></c>';
        $cRow .= '<c r="C'.$rIdx.'" s="'.$sB.'" t="s"><v>'.$addStr($s['nama_siswa'] ?? '-').'</v></c>';
        $cRow .= '<c r="D'.$rIdx.'" s="'.$sC.'" t="s"><v>'.$addStr($s['nama_kelas'] ?? '-').'</v></c>';
        $cRow .= '<c r="E'.$rIdx.'" s="'.$sC.'"><v>'.$kWa.'</v></c>';
        $cRow .= '<c r="F'.$rIdx.'" s="'.$sC.'"><v>'.$kReal.'</v></c>';
        $cRow .= '<c r="G'.$rIdx.'" s="'.$sC.'"><v>'.$kTot.'</v></c>';
        $cRow .= '<c r="H'.$rIdx.'" s="'.$sC.'"><v>'.$kPoin.'</v></c>';
        $cRow .= '<c r="I'.$rIdx.'" s="'.$sC.'" t="s"><v>'.$addStr($s['terakhir_tgl'] ?? '-').'</v></c>';

        $rowsXml .= '<row r="'.$rIdx.'" ht="23" customHeight="1">'.$cRow.'</row>';
        $rIdx++;
    }

    // Summary footer row
    $sumRow  = '<c r="A'.$rIdx.'" s="12" t="s"><v>'.$addStr('TOTAL:').'</v></c>';
    $sumRow .= '<c r="B'.$rIdx.'" s="13"><v>'.count($rekapSiswa).'</v></c>';
    $sumRow .= '<c r="C'.$rIdx.'" s="12" t="s"><v>'.$addStr('Total Siswa Tercatat').'</v></c>';
    $sumRow .= '<c r="D'.$rIdx.'" s="13" t="s"><v>'.$addStr('-').'</v></c>';
    $sumRow .= '<c r="E'.$rIdx.'" s="13"><v>'.$sumWa.'</v></c>';
    $sumRow .= '<c r="F'.$rIdx.'" s="13"><v>'.$sumReal.'</v></c>';
    $sumRow .= '<c r="G'.$rIdx.'" s="13"><v>'.$sumKasus.'</v></c>';
    $sumRow .= '<c r="H'.$rIdx.'" s="13"><v>'.$sumPoin.'</v></c>';
    $sumRow .= '<c r="I'.$rIdx.'" s="13" t="s"><v>'.$addStr('Lengkap').'</v></c>';
    $rowsXml .= '<row r="'.$rIdx.'" ht="25" customHeight="1">'.$sumRow.'</row>';

    // Column widths
    $colsXml = '<cols>'
        .'<col min="1" max="1" width="7" customWidth="1"/>'
        .'<col min="2" max="2" width="16" customWidth="1"/>'
        .'<col min="3" max="3" width="34" customWidth="1"/>'
        .'<col min="4" max="4" width="16" customWidth="1"/>'
        .'<col min="5" max="5" width="16" customWidth="1"/>'
        .'<col min="6" max="6" width="16" customWidth="1"/>'
        .'<col min="7" max="7" width="15" customWidth="1"/>'
        .'<col min="8" max="8" width="18" customWidth="1"/>'
        .'<col min="9" max="9" width="18" customWidth="1"/>'
        .'</cols>';

    return '<?xml version="1.0" encoding="UTF-8"?>'
        .'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        .'<sheetViews><sheetView workbookViewId="0"><pane ySplit="4" topLeftCell="A5" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>'
        .$colsXml
        .'<sheetData>'.$rowsXml.'</sheetData>'
        .'<autoFilter ref="A4:I4"/>'
        .'</worksheet>';
}

/* ==============================================================================
 * 3. GENERATOR EXCEL BERTEMA LUMINA GOLD (2 WORKSHEET)
 * ============================================================================== */
function ilmuna_generate_styled_pelanggaran_export(array $rows, array $metaInfo = []): string {
    $tmp = ilmuna_create_temp_xlsx('exp_pelanggaran_');
    if (!$tmp) {
        throw new Exception('Gagal membuat lokasi berkas sementara di folder uploads / sistem temp.');
    }

    $zip = new IlmunaZipArchive();
    $openRes = $zip->open($tmp, IlmunaZipArchive::CREATE);
    if ($openRes !== true) {
        throw new Exception('Gagal menginisialisasi arsip Excel: error kode ' . $openRes);
    }

    // 1. Content Types (2 Worksheets)
    $zip->addFromString('[Content_Types].xml',
        '<?xml version="1.0" encoding="UTF-8"?>'
        .'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
        .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
        .'<Default Extension="xml" ContentType="application/xml"/>'
        .'<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
        .'<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
        .'<Override PartName="/xl/worksheets/sheet2.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
        .'<Override PartName="/xl/sharedStrings.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sharedStrings+xml"/>'
        .'<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
        .'</Types>');

    // 2. Relationships Utama
    $zip->addFromString('_rels/.rels',
        '<?xml version="1.0" encoding="UTF-8"?>'
        .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
        .'</Relationships>');

    // 3. Workbook (2 Sheets)
    $zip->addFromString('xl/workbook.xml',
        '<?xml version="1.0" encoding="UTF-8"?>'
        .'<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
        .'<sheets>'
        .'<sheet name="Catatan Pelanggaran Siswa" sheetId="1" r:id="rId1"/>'
        .'<sheet name="Rekap Poin Per Siswa" sheetId="2" r:id="rId2"/>'
        .'</sheets></workbook>');

    // 4. Workbook Rel
    $zip->addFromString('xl/_rels/workbook.xml.rels',
        '<?xml version="1.0" encoding="UTF-8"?>'
        .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
        .'<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet2.xml"/>'
        .'<Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/sharedStrings" Target="sharedStrings.xml"/>'
        .'<Relationship Id="rId4" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
        .'</Relationships>');

    // 5. Stylesheet Tema Lumina Gold
    $zip->addFromString('xl/styles.xml', ilmuna_get_xlsx_theme_styles());

    // 6. Shared Strings Registry
    $strings = [];
    $map = [];
    $addStr = function ($s) use (&$strings, &$map) {
        $s = (string)$s;
        if (!isset($map[$s])) {
            $map[$s] = count($strings);
            $strings[] = $s;
        }
        return $map[$s];
    };

    // Hitung ringkasan & rekap per siswa
    $cWa = 0;
    $cReal = 0;
    $totalPoinSum = 0;
    $bySiswa = [];

    foreach ($rows as $r) {
        if (($r['lokasi_kejadian'] ?? '') === 'Grup WA') {
            $cWa++;
        } else {
            $cReal++;
        }
        $poinVal = (int)($r['poin'] ?? 0);
        $totalPoinSum += $poinVal;

        $sid = (int)($r['id_siswa'] ?? 0);
        if (!isset($bySiswa[$sid])) {
            $bySiswa[$sid] = [
                'id_siswa'     => $sid,
                'nis'          => $r['nis'] ?? '-',
                'nama_siswa'   => $r['nama_siswa'] ?? '-',
                'nama_kelas'   => $r['nama_kelas'] ?? '-',
                'kasus_wa'     => 0,
                'kasus_real'   => 0,
                'total_kasus'  => 0,
                'total_poin'   => 0,
                'terakhir_tgl' => $r['tanggal_kejadian'] ?? '-',
            ];
        }
        $bySiswa[$sid]['total_kasus']++;
        if (($r['lokasi_kejadian'] ?? '') === 'Grup WA') {
            $bySiswa[$sid]['kasus_wa']++;
        } else {
            $bySiswa[$sid]['kasus_real']++;
        }
        $bySiswa[$sid]['total_poin'] += $poinVal;
        if (($r['tanggal_kejadian'] ?? '') > $bySiswa[$sid]['terakhir_tgl']) {
            $bySiswa[$sid]['terakhir_tgl'] = $r['tanggal_kejadian'];
        }
    }

    $rekapSiswa = array_values($bySiswa);
    usort($rekapSiswa, function ($a, $b) {
        return ($b['total_poin'] <=> $a['total_poin']) ?: ($b['total_kasus'] <=> $a['total_kasus']);
    });

    $rowsXml = '';

    // Row 1: Brand Title
    $rowsXml .= '<row r="1" ht="26" customHeight="1"><c r="A1" s="1" t="s"><v>'.$addStr('SISTEM INFORMASI AKADEMIK ILMUNA LEARN').'</v></c></row>';

    // Row 2: Subtitle Laporan
    $subtitle = !empty($metaInfo['subtitle'])
        ? $metaInfo['subtitle']
        : 'Rekap Penuh Catatan Pelanggaran Siswa (Grup WA & Realitas)';
    $rowsXml .= '<row r="2" ht="20" customHeight="1"><c r="A2" s="2" t="s"><v>'.$addStr($subtitle).'</v></c></row>';

    // Row 3: Metadata Info
    $fLokasi  = !empty($metaInfo['filter_lokasi']) ? $metaInfo['filter_lokasi'] : 'Semua Lokasi (Grup WA & Realitas)';
    $fTingkat = !empty($metaInfo['filter_tingkat']) ? $metaInfo['filter_tingkat'] : 'Semua Tingkat';
    $fStatus  = !empty($metaInfo['filter_status']) ? $metaInfo['filter_status'] : 'Semua Status';
    $fScope   = !empty($metaInfo['filter_scope_label']) ? (' | ' . $metaInfo['filter_scope_label']) : '';
    $fSearch  = !empty($metaInfo['filter_search']) ? (' | Pencarian: "' . $metaInfo['filter_search'] . '"') : '';

    $metaText = 'Diekspor pada: ' . date('d F Y, H:i') . ' WIB | Lokasi: ' . $fLokasi
        . ' | Tingkat: ' . $fTingkat . ' | Status: ' . $fStatus . $fScope . $fSearch
        . ' | Total Data: ' . count($rows) . ' Kasus (Grup WA: ' . $cWa . ', Realitas: ' . $cReal . ')';
    $rowsXml .= '<row r="3" ht="18" customHeight="1"><c r="A3" s="3" t="s"><v>'.$addStr($metaText).'</v></c></row>';

    // Row 4: Empty Separator
    $rowsXml .= '<row r="4" ht="10" customHeight="1"></row>';

    // Row 5: Table Headers (A to P: 16 columns)
    $headers = [
        ['col' => 'A', 'title' => 'NO', 'style' => 4],
        ['col' => 'B', 'title' => 'TANGGAL', 'style' => 4],
        ['col' => 'C', 'title' => 'WAKTU (WIB)', 'style' => 4],
        ['col' => 'D', 'title' => 'NIS', 'style' => 4],
        ['col' => 'E', 'title' => 'NAMA LENGKAP SISWA', 'style' => 5],
        ['col' => 'F', 'title' => 'KELAS', 'style' => 4],
        ['col' => 'G', 'title' => 'MATA PELAJARAN', 'style' => 5],
        ['col' => 'H', 'title' => 'LOKASI KEJADIAN', 'style' => 4],
        ['col' => 'I', 'title' => 'KATEGORI PELANGGARAN', 'style' => 5],
        ['col' => 'J', 'title' => 'TINGKAT', 'style' => 4],
        ['col' => 'K', 'title' => 'POIN', 'style' => 4],
        ['col' => 'L', 'title' => 'KRONOLOGI KEJADIAN', 'style' => 5],
        ['col' => 'M', 'title' => 'BUKTI / REFERENSI', 'style' => 5],
        ['col' => 'N', 'title' => 'TINDAK LANJUT / PEMBINAAN', 'style' => 5],
        ['col' => 'O', 'title' => 'STATUS PENANGANAN', 'style' => 4],
        ['col' => 'P', 'title' => 'PENCATAT', 'style' => 5],
    ];

    $hCells = '';
    foreach ($headers as $h) {
        $hCells .= '<c r="'.$h['col'].'5" s="'.$h['style'].'" t="s"><v>'.$addStr($h['title']).'</v></c>';
    }
    $rowsXml .= '<row r="5" ht="28" customHeight="1">'.$hCells.'</row>';

    // Rows 6+: Data Rows
    $rowIdx = 6;
    foreach ($rows as $item) {
        $isZebra = ($rowIdx % 2 === 1);
        $sLeft   = $isZebra ? 9  : 6;
        $sCenter = $isZebra ? 10 : 7;
        $sBold   = $isZebra ? 11 : 8;

        $waktuStr = !empty($item['waktu_kejadian']) ? substr((string)$item['waktu_kejadian'], 0, 5) : '-';
        $poinVal  = (int)($item['poin'] ?? 0);

        $cRow  = '';
        $cRow .= '<c r="A'.$rowIdx.'" s="'.$sCenter.'"><v>'.($rowIdx - 5).'</v></c>';
        $cRow .= '<c r="B'.$rowIdx.'" s="'.$sCenter.'" t="s"><v>'.$addStr($item['tanggal_kejadian'] ?: '-').'</v></c>';
        $cRow .= '<c r="C'.$rowIdx.'" s="'.$sCenter.'" t="s"><v>'.$addStr($waktuStr).'</v></c>';
        $cRow .= '<c r="D'.$rowIdx.'" s="'.$sCenter.'" t="s"><v>'.$addStr(($item['nis'] ?? '') !== '' ? $item['nis'] : '-').'</v></c>';
        $cRow .= '<c r="E'.$rowIdx.'" s="'.$sBold.'" t="s"><v>'.$addStr($item['nama_siswa'] ?? '-').'</v></c>';
        $cRow .= '<c r="F'.$rowIdx.'" s="'.$sCenter.'" t="s"><v>'.$addStr($item['nama_kelas'] ?? '-').'</v></c>';
        $cRow .= '<c r="G'.$rowIdx.'" s="'.$sLeft.'" t="s"><v>'.$addStr($item['nama_mapel'] ?? 'Umum / Non-Mapel').'</v></c>';
        $cRow .= '<c r="H'.$rowIdx.'" s="'.$sCenter.'" t="s"><v>'.$addStr($item['lokasi_kejadian'] ?? 'Realitas').'</v></c>';
        $cRow .= '<c r="I'.$rowIdx.'" s="'.$sBold.'" t="s"><v>'.$addStr($item['kategori_pelanggaran'] ?? '-').'</v></c>';
        $cRow .= '<c r="J'.$rowIdx.'" s="'.$sCenter.'" t="s"><v>'.$addStr($item['tingkat_pelanggaran'] ?? 'Ringan').'</v></c>';
        $cRow .= '<c r="K'.$rowIdx.'" s="'.$sCenter.'"><v>'.$poinVal.'</v></c>';
        $cRow .= '<c r="L'.$rowIdx.'" s="'.$sLeft.'" t="s"><v>'.$addStr($item['kronologi'] ?? '-').'</v></c>';
        $cRow .= '<c r="M'.$rowIdx.'" s="'.$sLeft.'" t="s"><v>'.$addStr(($item['bukti_keterangan'] ?? '') !== '' ? $item['bukti_keterangan'] : '-').'</v></c>';
        $cRow .= '<c r="N'.$rowIdx.'" s="'.$sLeft.'" t="s"><v>'.$addStr(($item['tindak_lanjut'] ?? '') !== '' ? $item['tindak_lanjut'] : '-').'</v></c>';
        $cRow .= '<c r="O'.$rowIdx.'" s="'.$sCenter.'" t="s"><v>'.$addStr($item['status_penanganan'] ?? 'Baru').'</v></c>';
        $cRow .= '<c r="P'.$rowIdx.'" s="'.$sLeft.'" t="s"><v>'.$addStr($item['nama_pencatat'] ?? 'Administrator').'</v></c>';

        $rowsXml .= '<row r="'.$rowIdx.'" ht="23" customHeight="1">'.$cRow.'</row>';
        $rowIdx++;
    }

    // Summary Footer Row (16 columns A to P)
    $sumRow  = '<c r="A'.$rowIdx.'" s="12" t="s"><v>'.$addStr('TOTAL:').'</v></c>';
    $sumRow .= '<c r="B'.$rowIdx.'" s="13"><v>'.count($rows).'</v></c>';
    $sumRow .= '<c r="C'.$rowIdx.'" s="13" t="s"><v>'.$addStr('Kasus').'</v></c>';
    $sumRow .= '<c r="D'.$rowIdx.'" s="13" t="s"><v>'.$addStr('-').'</v></c>';
    $sumRow .= '<c r="E'.$rowIdx.'" s="12" t="s"><v>'.$addStr('Akumulasi Keseluruhan Catatan Pelanggaran').'</v></c>';
    $sumRow .= '<c r="F'.$rowIdx.'" s="13" t="s"><v>'.$addStr('-').'</v></c>';
    $sumRow .= '<c r="G'.$rowIdx.'" s="13" t="s"><v>'.$addStr('-').'</v></c>';
    $sumRow .= '<c r="H'.$rowIdx.'" s="13" t="s"><v>'.$addStr('WA: '.$cWa.' | Real: '.$cReal).'</v></c>';
    $sumRow .= '<c r="I'.$rowIdx.'" s="13" t="s"><v>'.$addStr('-').'</v></c>';
    $sumRow .= '<c r="J'.$rowIdx.'" s="12" t="s"><v>'.$addStr('TOTAL POIN:').'</v></c>';
    $sumRow .= '<c r="K'.$rowIdx.'" s="13"><v>'.$totalPoinSum.'</v></c>';
    $sumRow .= '<c r="L'.$rowIdx.'" s="13" t="s"><v>'.$addStr('-').'</v></c>';
    $sumRow .= '<c r="M'.$rowIdx.'" s="13" t="s"><v>'.$addStr('-').'</v></c>';
    $sumRow .= '<c r="N'.$rowIdx.'" s="13" t="s"><v>'.$addStr('-').'</v></c>';
    $sumRow .= '<c r="O'.$rowIdx.'" s="13" t="s"><v>'.$addStr('-').'</v></c>';
    $sumRow .= '<c r="P'.$rowIdx.'" s="13" t="s"><v>'.$addStr('Lengkap').'</v></c>';
    $rowsXml .= '<row r="'.$rowIdx.'" ht="25" customHeight="1">'.$sumRow.'</row>';

    // Column Widths (A to P)
    $colsXml = '<cols>'
        .'<col min="1" max="1" width="7" customWidth="1"/>'
        .'<col min="2" max="2" width="14" customWidth="1"/>'
        .'<col min="3" max="3" width="13" customWidth="1"/>'
        .'<col min="4" max="4" width="15" customWidth="1"/>'
        .'<col min="5" max="5" width="32" customWidth="1"/>'
        .'<col min="6" max="6" width="15" customWidth="1"/>'
        .'<col min="7" max="7" width="26" customWidth="1"/>'
        .'<col min="8" max="8" width="16" customWidth="1"/>'
        .'<col min="9" max="9" width="30" customWidth="1"/>'
        .'<col min="10" max="10" width="12" customWidth="1"/>'
        .'<col min="11" max="11" width="10" customWidth="1"/>'
        .'<col min="12" max="12" width="42" customWidth="1"/>'
        .'<col min="13" max="13" width="26" customWidth="1"/>'
        .'<col min="14" max="14" width="32" customWidth="1"/>'
        .'<col min="15" max="15" width="18" customWidth="1"/>'
        .'<col min="16" max="16" width="26" customWidth="1"/>'
        .'</cols>';

    // Worksheet 1 XML
    $sheet1Xml = '<?xml version="1.0" encoding="UTF-8"?>'
        .'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        .'<sheetViews><sheetView workbookViewId="0"><pane ySplit="5" topLeftCell="A6" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>'
        .$colsXml
        .'<sheetData>'.$rowsXml.'</sheetData>'
        .'<autoFilter ref="A5:P5"/>'
        .'</worksheet>';
    $zip->addFromString('xl/worksheets/sheet1.xml', $sheet1Xml);

    // Worksheet 2 XML: Rekap Poin Per Siswa
    $sheet2Xml = ilmuna_build_pelanggaran_rekap_sheet2_xml($rekapSiswa, $addStr);
    $zip->addFromString('xl/worksheets/sheet2.xml', $sheet2Xml);

    // Simpan Shared Strings
    $ssXml = '<?xml version="1.0" encoding="UTF-8"?>'
        .'<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" count="'.count($strings).'" uniqueCount="'.count($strings).'">';
    foreach ($strings as $s) {
        $ssXml .= '<si><t xml:space="preserve">'.htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8').'</t></si>';
    }
    $ssXml .= '</sst>';
    $zip->addFromString('xl/sharedStrings.xml', $ssXml);

    if (!$zip->close()) {
        $status = method_exists($zip, 'getStatusString') ? $zip->getStatusString() : 'unknown';
        @unlink($tmp);
        throw new Exception('Gagal menyimpan berkas Excel catatan pelanggaran: ' . $status);
    }

    if (!file_exists($tmp) || filesize($tmp) === 0) {
        @unlink($tmp);
        throw new Exception('Berkas Excel hasil export catatan pelanggaran kosong.');
    }

    return $tmp;
}

/* ==============================================================================
 * 4. ROUTER ACTION DISPATCHER (export_excel)
 * ============================================================================== */

$action = $_GET['action'] ?? $_POST['action'] ?? '';
if ($isCli && $action === '') {
    return;
}

if ($action === 'export_excel' || $action === '') {
    try {
        $search   = trim((string)($_GET['search'] ?? ''));
        $fLokasi  = trim((string)($_GET['lokasi'] ?? ''));
        $fTingkat = trim((string)($_GET['tingkat'] ?? ''));
        $fStatus  = trim((string)($_GET['status'] ?? ''));

        $where = ['1=1'];
        $params = [];
        $scopeLabels = [];
        $subtitle = 'Rekap Penuh Catatan Pelanggaran Siswa (Grup WA & Realitas)';
        $filePrefix = 'rekap-pelanggaran-siswa-ilmuna-';

        if ($userRole === 'guru') {
            $guru = ilmuna_resolve_logged_guru($pdo, $currentUser);
            $idGuru = (int)($guru['id_guru'] ?? $guru['id'] ?? 0);
            if ($idGuru <= 0) {
                throw new Exception('Profil guru tidak ditemukan untuk akun Anda.');
            }

            $stKmp = $pdo->prepare("
                SELECT kmp.id AS id_kmp, k.nama_kelas, mp.nama_mata_pelajaran
                FROM kelas_mata_pelajaran kmp
                JOIN guru_mata_pelajaran gmp ON gmp.id = kmp.id_guru_mata_pelajaran
                JOIN kelas k ON k.id = kmp.id_kelas
                JOIN mata_pelajaran mp ON mp.id = kmp.id_mata_pelajaran
                WHERE gmp.id_guru = ?
                ORDER BY k.nama_kelas ASC, mp.nama_mata_pelajaran ASC
            ");
            $stKmp->execute([$idGuru]);
            $kmpRows = $stKmp->fetchAll(PDO::FETCH_ASSOC);
            $kmpMap = [];
            foreach ($kmpRows as $kr) {
                $kmpMap[(int)$kr['id_kmp']] = $kr['nama_kelas'] . ' — ' . $kr['nama_mata_pelajaran'];
            }

            $allowedKmpIds = array_keys($kmpMap);
            if (empty($allowedKmpIds)) {
                $where[] = '1=0';
            } else {
                $fKmp = (int)($_GET['id_kmp'] ?? 0);
                if ($fKmp > 0 && isset($kmpMap[$fKmp])) {
                    $where[] = 'p.id_kelas_mata_pelajaran = ?';
                    $params[] = $fKmp;
                    $scopeLabels[] = 'Kelas & Mapel: ' . $kmpMap[$fKmp];
                } else {
                    $ph = implode(',', array_fill(0, count($allowedKmpIds), '?'));
                    $where[] = "p.id_kelas_mata_pelajaran IN ({$ph})";
                    $params = array_merge($params, $allowedKmpIds);
                    $scopeLabels[] = 'Semua Kelas & Mapel Ampu';
                }
            }

            $namaGuru = trim((string)($guru['nama_guru'] ?? $currentUser['full_name'] ?? 'Guru'));
            $subtitle = 'Catatan Pelanggaran Siswa Per Kelas & Mapel — ' . $namaGuru;
            $filePrefix = 'catatan-pelanggaran-guru-ilmuna-';
        } else {
            $fKelas = (int)($_GET['id_kelas'] ?? 0);
            $fMapel = (int)($_GET['id_mapel'] ?? 0);
            $fGuru  = (int)($_GET['id_guru'] ?? 0);
            $fKmp   = (int)($_GET['id_kmp'] ?? 0);

            if ($fKmp > 0) {
                $where[] = 'p.id_kelas_mata_pelajaran = ?';
                $params[] = $fKmp;
                $stLbl = $pdo->prepare("
                    SELECT CONCAT(k.nama_kelas, ' — ', mp.nama_mata_pelajaran)
                    FROM kelas_mata_pelajaran kmp
                    JOIN kelas k ON k.id = kmp.id_kelas
                    JOIN mata_pelajaran mp ON mp.id = kmp.id_mata_pelajaran
                    WHERE kmp.id = ? LIMIT 1
                ");
                $stLbl->execute([$fKmp]);
                $nmKmp = $stLbl->fetchColumn();
                if ($nmKmp) $scopeLabels[] = 'Kelas & Mapel: ' . $nmKmp;
            }
            if ($fKelas > 0) {
                $where[] = 'p.id_kelas = ?';
                $params[] = $fKelas;
                $stLbl = $pdo->prepare("SELECT nama_kelas FROM kelas WHERE id = ? LIMIT 1");
                $stLbl->execute([$fKelas]);
                $nmK = $stLbl->fetchColumn();
                if ($nmK) $scopeLabels[] = 'Kelas: ' . $nmK;
            }
            if ($fMapel > 0) {
                $where[] = 'kmp.id_mata_pelajaran = ?';
                $params[] = $fMapel;
                $stLbl = $pdo->prepare("SELECT nama_mata_pelajaran FROM mata_pelajaran WHERE id = ? LIMIT 1");
                $stLbl->execute([$fMapel]);
                $nmM = $stLbl->fetchColumn();
                if ($nmM) $scopeLabels[] = 'Mapel: ' . $nmM;
            }
            if ($fGuru > 0) {
                $where[] = 'p.id_guru = ?';
                $params[] = $fGuru;
                $stLbl = $pdo->prepare("SELECT nama_guru FROM guru WHERE id = ? LIMIT 1");
                $stLbl->execute([$fGuru]);
                $nmG = $stLbl->fetchColumn();
                if ($nmG) $scopeLabels[] = 'Guru: ' . $nmG;
            }
        }

        if (in_array($fLokasi, ['Grup WA', 'Realitas'], true)) {
            $where[] = 'p.lokasi_kejadian = ?';
            $params[] = $fLokasi;
        } else {
            $fLokasi = '';
        }

        if (in_array($fTingkat, ['Ringan', 'Sedang', 'Berat'], true)) {
            $where[] = 'p.tingkat_pelanggaran = ?';
            $params[] = $fTingkat;
        } else {
            $fTingkat = '';
        }

        if (in_array($fStatus, ['Baru', 'Pembinaan', 'Selesai'], true)) {
            $where[] = 'p.status_penanganan = ?';
            $params[] = $fStatus;
        } else {
            $fStatus = '';
        }

        if ($search !== '') {
            $where[] = '(s.nama_lengkap LIKE ? OR s.nis LIKE ? OR k.nama_kelas LIKE ? OR COALESCE(mp.nama_mata_pelajaran, "") LIKE ? OR p.kategori_pelanggaran LIKE ? OR p.kronologi LIKE ?)';
            $like = '%' . $search . '%';
            $params = array_merge($params, [$like, $like, $like, $like, $like, $like]);
        }

        $whereSql = implode(' AND ', $where);
        $sql = "
            SELECT p.*,
                   s.nis, s.nama_lengkap AS nama_siswa,
                   k.nama_kelas,
                   COALESCE(mp.nama_mata_pelajaran, 'Umum / Non-Mapel') AS nama_mapel,
                   COALESCE(g.nama_guru, u.full_name, 'Administrator') AS nama_pencatat
            FROM catatan_pelanggaran_siswa p
            JOIN siswa s ON s.id = p.id_siswa
            JOIN kelas k ON k.id = p.id_kelas
            LEFT JOIN kelas_mata_pelajaran kmp ON kmp.id = p.id_kelas_mata_pelajaran
            LEFT JOIN mata_pelajaran mp ON mp.id = kmp.id_mata_pelajaran
            LEFT JOIN guru g ON g.id = p.id_guru
            LEFT JOIN users u ON u.id = p.recorded_by_user_id
            WHERE {$whereSql}
            ORDER BY p.tanggal_kejadian DESC, p.id DESC
        ";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $metaInfo = [
            'subtitle'           => $subtitle,
            'filter_lokasi'      => $fLokasi,
            'filter_tingkat'     => $fTingkat,
            'filter_status'      => $fStatus,
            'filter_search'      => $search,
            'filter_scope_label' => implode(' | ', $scopeLabels),
        ];

        $tmpFile = ilmuna_generate_styled_pelanggaran_export($rows, $metaInfo);
        if (!$tmpFile || !file_exists($tmpFile)) {
            throw new Exception('Gagal membuat berkas Excel sementara catatan pelanggaran.');
        }

        $filename = $filePrefix . date('Ymd-His') . '.xlsx';

        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Access-Control-Expose-Headers: Content-Disposition');
        header('Cache-Control: max-age=0, no-cache, no-store, must-revalidate');
        header('Pragma: public');
        header('Content-Length: ' . filesize($tmpFile));

        readfile($tmpFile);
        @unlink($tmpFile);
        exit;
    } catch (Throwable $e) {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'success' => false,
            'data' => ['message' => 'Export error: ' . $e->getMessage()]
        ]);
        exit;
    }
}
