<?php
/**
 * ==============================================================================
 * ILMUNA LEARN — BACKEND EXCEL HANDLER (REKAP ABSENSI & MONITORING IZIN)
 * ==============================================================================
 * Export Rekap Absensi (.xlsx) — Tema Lumina Gold — 3 SHEET:
 *
 *   1. "Rekap Absensi"  → Ringkasan per siswa (tanpa grid pertemuan):
 *                          NO | NISN | NIS | NAMA SISWA | H | I | S | A | T | %
 *   2. "Rekap Detail"   → Grid per pertemuan: P# di header, tanggal di bawah,
 *                          baris siswa berisi status H/I/S/A/T per pertemuan.
 *   3. "Info Pertemuan" → Daftar pertemuan + rekap H/I/S/A/T per pertemuan.
 *
 * Standar Output: OpenXML Spreadsheet (.xlsx)
 * Engine: Hybrid ZipArchive Native / Pure-PHP ZIP Adapter (Zero-Failsafe)
 * ==============================================================================
 */

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../auth.php';
require_once __DIR__ . '/../../database.php';

if (php_sapi_name() !== 'cli') {
    requireRole('admin');
}

$pdo = getDBConnection();

/* ==============================================================================
 * HELPER: OPENXML XLSX UTILITIES
 * ============================================================================== */

if (!function_exists('ilmuna_xlsx_col_letter')) {
    function ilmuna_xlsx_col_letter($idx) {
        $letter = ''; $idx = (int)$idx + 1;
        while ($idx > 0) {
            $mod = ($idx - 1) % 26;
            $letter = chr(65 + $mod) . $letter;
            $idx = intdiv($idx - $mod, 26);
        }
        return $letter;
    }
}

/* ==============================================================================
 * ENGINE: HYBRID ZIPARCHIVE & PURE-PHP ZIP ADAPTER
 * ============================================================================== */
if (!class_exists('IlmunaZipArchive')) {
class IlmunaZipArchive {
    public const CREATE = 1;
    public const OVERWRITE = 8;
    private $nativeZip = null;
    private $filePath = '';
    private $files = [];
    private $zipData = null;

    public function __construct() {
        if (class_exists('ZipArchive')) $this->nativeZip = new ZipArchive();
    }
    public function open($filename, $flags = 0) {
        $this->filePath = $filename;
        if ($this->nativeZip) {
            $res = $this->nativeZip->open($filename, $flags);
            if ($res === true) return true;
        }
        if ($flags & (self::CREATE | self::OVERWRITE)) { $this->files = []; return true; }
        if (!file_exists($filename)) return false;
        $this->zipData = @file_get_contents($filename);
        return ($this->zipData !== false);
    }
    public function addFromString($localname, $contents) {
        if ($this->nativeZip) return $this->nativeZip->addFromString($localname, $contents);
        $this->files[$localname] = $contents;
        return true;
    }
    public function close() {
        if ($this->nativeZip) {
            $closed = $this->nativeZip->close();
            if ($closed) return true;
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
                 | (date('m', $time) << 21) | (date('d', $time) << 16)
                 | (date('H', $time) << 11) | (date('i', $time) << 5) | (date('s', $time) >> 1);
        $body = ''; $cd = ''; $count = 0;
        foreach ($files as $name => $data) {
            $name = str_replace('\\', '/', $name);
            $uncLen = strlen($data); $crc = crc32($data);
            if (function_exists('gzdeflate')) {
                $cData = gzdeflate($data); $cLen = strlen($cData); $method = 8;
            } else { $cData = $data; $cLen = $uncLen; $method = 0; }
            $offset = strlen($body);
            $body .= "\x50\x4b\x03\x04" . pack('v',20) . pack('v',0) . pack('v',$method)
                . pack('V',$dostime) . pack('V',$crc) . pack('V',$cLen) . pack('V',$uncLen)
                . pack('v',strlen($name)) . pack('v',0) . $name . $cData;
            $cd .= "\x50\x4b\x01\x02" . pack('v',0x0314) . pack('v',20) . pack('v',0)
                . pack('v',$method) . pack('V',$dostime) . pack('V',$crc) . pack('V',$cLen)
                . pack('V',$uncLen) . pack('v',strlen($name)) . pack('v',0) . pack('v',0)
                . pack('v',0) . pack('v',0) . pack('V',32) . pack('V',$offset) . $name;
            $count++;
        }
        $cdOffset = strlen($body); $cdLen = strlen($cd);
        $eocd = "\x50\x4b\x05\x06" . pack('v',0) . pack('v',0) . pack('v',$count)
            . pack('v',$count) . pack('V',$cdLen) . pack('V',$cdOffset) . pack('v',0);
        return $body . $cd . $eocd;
    }
}
}

if (!function_exists('ilmuna_create_temp_xlsx')) {
    function ilmuna_create_temp_xlsx($prefix = 'ilmuna_') {
        $randomSuffix = date('Ymd_His') . '_' . bin2hex(random_bytes(6)) . '.xlsx';
        $uploadsDir = __DIR__ . '/../../uploads';
        if (!is_dir($uploadsDir)) @mkdir($uploadsDir, 0777, true);
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
        $path = $sysTmp . DIRECTORY_SEPARATOR . $prefix . $randomSuffix;
        if (file_exists($path)) @unlink($path);
        return $path;
    }
}

/**
 * StyleSheet Tema Lumina Gold + Status Colors (H/I/S/A/T)
 * Style 0-19: standard + status
 * Style 20   : sub-header (tanggal di bawah P#) — dark slate pada light slate
 */
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
            .'<fills count="11">'
            .'<fill><patternFill patternType="none"/></fill>'
            .'<fill><patternFill patternType="gray125"/></fill>'
            .'<fill><patternFill patternType="solid"><fgColor rgb="FF1D3B5E"/></patternFill></fill>'
            .'<fill><patternFill patternType="solid"><fgColor rgb="FFF8FAFC"/></patternFill></fill>'
            .'<fill><patternFill patternType="solid"><fgColor rgb="FFE2E8F0"/></patternFill></fill>'
            .'<fill><patternFill patternType="solid"><fgColor rgb="FF10B981"/></patternFill></fill>'
            .'<fill><patternFill patternType="solid"><fgColor rgb="FFF59E0B"/></patternFill></fill>'
            .'<fill><patternFill patternType="solid"><fgColor rgb="FF3B82F6"/></patternFill></fill>'
            .'<fill><patternFill patternType="solid"><fgColor rgb="FFEF4444"/></patternFill></fill>'
            .'<fill><patternFill patternType="solid"><fgColor rgb="FF8B5CF6"/></patternFill></fill>'
            .'<fill><patternFill patternType="solid"><fgColor rgb="FFF1F5F9"/></patternFill></fill>'
            .'</fills>'
            .'<borders count="4">'
            .'<border><left/><right/><top/><bottom/></border>'
            .'<border><left style="thin"><color rgb="FFCBD5E1"/></left><right style="thin"><color rgb="FFCBD5E1"/></right><top style="thin"><color rgb="FFCBD5E1"/></top><bottom style="thin"><color rgb="FFCBD5E1"/></bottom></border>'
            .'<border><left style="thin"><color rgb="FF334155"/></left><right style="thin"><color rgb="FF334155"/></right><top style="thin"><color rgb="FF334155"/></top><bottom style="medium"><color rgb="FF0D7A87"/></bottom></border>'
            .'<border><left style="thin"><color rgb="FF94A3B8"/></left><right style="thin"><color rgb="FF94A3B8"/></right><top style="thin"><color rgb="FF94A3B8"/></top><bottom style="double"><color rgb="FF1D3B5E"/></bottom></border>'
            .'</borders>'
            .'<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            .'<cellXfs count="21">'
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
            .'<xf numFmtId="0" fontId="4" fillId="5" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>'
            .'<xf numFmtId="0" fontId="4" fillId="6" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>'
            .'<xf numFmtId="0" fontId="4" fillId="7" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>'
            .'<xf numFmtId="0" fontId="4" fillId="8" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>'
            .'<xf numFmtId="0" fontId="4" fillId="9" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>'
            .'<xf numFmtId="0" fontId="7" fillId="10" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>'
            .'<xf numFmtId="0" fontId="5" fillId="10" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>'
            .'</cellXfs>'
            .'</styleSheet>';
    }
}

/* ==============================================================================
 * QUERY: AMBIL DATA REKAP ABSENSI
 * ============================================================================== */
function ilmuna_query_rekap($pdo, $idKmp) {
    $stmtInfo = $pdo->prepare("
        SELECT kmp.id, kmp.id_kelas, k.nama_kelas, k.tahun_pelajaran,
               mp.nama_mata_pelajaran, mp.semester,
               COALESCE(g.nama_guru, 'Guru Pengampu') AS nama_guru
        FROM kelas_mata_pelajaran kmp
        JOIN kelas k ON kmp.id_kelas = k.id
        JOIN mata_pelajaran mp ON kmp.id_mata_pelajaran = mp.id
        LEFT JOIN guru_mata_pelajaran gmp ON kmp.id_guru_mata_pelajaran = gmp.id
        LEFT JOIN guru g ON gmp.id_guru = g.id
        WHERE kmp.id = ?
    ");
    $stmtInfo->execute([$idKmp]);
    $info = $stmtInfo->fetch(PDO::FETCH_ASSOC);
    if (!$info) return null;

    $stmtSiswa = $pdo->prepare("
        SELECT id, nis, nisn, nama_lengkap
        FROM siswa WHERE id_kelas = ?
        ORDER BY nama_lengkap ASC
    ");
    $stmtSiswa->execute([$info['id_kelas']]);
    $siswaList = $stmtSiswa->fetchAll(PDO::FETCH_ASSOC);

    $pertemuan = [];
    try {
        $stmt = $pdo->prepare("
            SELECT jp.pertemuan_ke,
                   MIN(jp.tanggal)           AS tanggal,
                   MIN(jp.jam_mulai)         AS jam_mulai,
                   MIN(jp.jam_selesai)       AS jam_selesai,
                   MAX(jp.materi_pembahasan) AS materi,
                   MAX(jp.status_jadwal)     AS status_jadwal
            FROM jadwal_pelajaran jp
            WHERE jp.id_kelas_mata_pelajaran = ?
            GROUP BY jp.pertemuan_ke
            ORDER BY jp.pertemuan_ke ASC
        ");
        $stmt->execute([$idKmp]);
        $pertemuan = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) { $pertemuan = []; }

    if (empty($pertemuan)) {
        $stmt = $pdo->prepare("
            SELECT pertemuan_ke, MIN(DATE(waktu_absen)) AS tanggal
            FROM absensi WHERE id_kelas_mata_pelajaran = ?
            GROUP BY pertemuan_ke ORDER BY pertemuan_ke ASC
        ");
        $stmt->execute([$idKmp]);
        $pertemuan = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($pertemuan as &$p) {
            $p['jam_mulai'] = null;
            $p['jam_selesai'] = null;
            $p['materi'] = null;
            $p['status_jadwal'] = null;
        }
        unset($p);
    }

    $stmtAbs = $pdo->prepare("
        SELECT id_siswa, pertemuan_ke, status
        FROM absensi WHERE id_kelas_mata_pelajaran = ?
    ");
    $stmtAbs->execute([$idKmp]);
    $absRows = $stmtAbs->fetchAll(PDO::FETCH_ASSOC);

    $absIndexed = [];
    foreach ($absRows as $a) {
        $absIndexed[(int)$a['id_siswa']][(int)$a['pertemuan_ke']] = $a;
    }

    return [
        'info' => $info,
        'siswa' => $siswaList,
        'pertemuan' => $pertemuan,
        'absensi' => $absIndexed,
    ];
}

/* ==============================================================================
 * BUILDER: SHARED XLSX PARTS
 * ============================================================================== */
function ilmuna_build_common_parts($sheet1Name, $sheet2Name, $sheet3Name) {
    return [
        '[Content_Types].xml' =>
            '<?xml version="1.0" encoding="UTF-8"?>'
            .'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            .'<Default Extension="xml" ContentType="application/xml"/>'
            .'<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            .'<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            .'<Override PartName="/xl/worksheets/sheet2.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            .'<Override PartName="/xl/worksheets/sheet3.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            .'<Override PartName="/xl/sharedStrings.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sharedStrings+xml"/>'
            .'<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            .'</Types>',

        '_rels/.rels' =>
            '<?xml version="1.0" encoding="UTF-8"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            .'</Relationships>',

        'xl/workbook.xml' =>
            '<?xml version="1.0" encoding="UTF-8"?>'
            .'<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            .'<sheets>'
            .'<sheet name="'.htmlspecialchars($sheet1Name, ENT_XML1|ENT_QUOTES, 'UTF-8').'" sheetId="1" r:id="rId1"/>'
            .'<sheet name="'.htmlspecialchars($sheet2Name, ENT_XML1|ENT_QUOTES, 'UTF-8').'" sheetId="2" r:id="rId2"/>'
            .'<sheet name="'.htmlspecialchars($sheet3Name, ENT_XML1|ENT_QUOTES, 'UTF-8').'" sheetId="3" r:id="rId3"/>'
            .'</sheets></workbook>',

        'xl/_rels/workbook.xml.rels' =>
            '<?xml version="1.0" encoding="UTF-8"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
            .'<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet2.xml"/>'
            .'<Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet3.xml"/>'
            .'<Relationship Id="rId4" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/sharedStrings" Target="sharedStrings.xml"/>'
            .'<Relationship Id="rId5" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
            .'</Relationships>',
    ];
}

function ilmuna_build_shared_strings_xml($strings) {
    $ssXml = '<?xml version="1.0" encoding="UTF-8"?>'
        .'<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" count="'.count($strings).'" uniqueCount="'.count($strings).'">';
    foreach ($strings as $s) {
        $ssXml .= '<si><t xml:space="preserve">'.htmlspecialchars($s, ENT_XML1|ENT_QUOTES, 'UTF-8').'</t></si>';
    }
    $ssXml .= '</sst>';
    return $ssXml;
}

/* ==============================================================================
 * GENERATOR: REKAP ABSENSI EXCEL (3 SHEET)
 * ============================================================================== */
function ilmuna_generate_rekap_export($data) {
    $tmp = ilmuna_create_temp_xlsx('exp_rekap_');
    if (!$tmp) throw new Exception('Gagal membuat lokasi berkas sementara.');

    $zip = new IlmunaZipArchive();
    if ($zip->open($tmp, IlmunaZipArchive::CREATE) !== true) {
        throw new Exception('Gagal menginisialisasi arsip Excel.');
    }

    $parts = ilmuna_build_common_parts('Rekap Absensi', 'Rekap Detail', 'Info Pertemuan');
    foreach ($parts as $name => $xml) $zip->addFromString($name, $xml);
    $zip->addFromString('xl/styles.xml', ilmuna_get_xlsx_theme_styles());

    $strings = []; $map = [];
    $addStr = function($s) use (&$strings, &$map) {
        $s = (string)$s;
        if (!isset($map[$s])) { $map[$s] = count($strings); $strings[] = $s; }
        return $map[$s];
    };

    $info = $data['info'];
    $siswaList = $data['siswa'];
    $pertemuan = $data['pertemuan'];
    $absIndexed = $data['absensi'];
    $N = count($pertemuan);

    $statusStyleMap = ['H' => 14, 'I' => 15, 'S' => 16, 'A' => 17, 'T' => 18];
    $emptyStyle = 19;

    /* ==========================================================
     * SHEET 1: REKAP ABSENSI (ringkasan H/I/S/A/T/% — tanpa grid)
     * ==========================================================
     * Layout:
     *   Row 5 (header) : NO | NISN | NIS | NAMA SISWA | H | I | S | A | T | %
     *   Row 6+ (data)  : nomor | nisn | nis | nama       | h | i | s | a | t | pct%
     *   Footer         : TOTAL | jumlah siswa | - | total pertemuan | sumH..sumT | avg%
     * ========================================================== */
    $rowsXml = '';
    $rowsXml .= '<row r="1" ht="26" customHeight="1"><c r="A1" s="1" t="s"><v>'.$addStr('SISTEM INFORMASI AKADEMIK ILMUNA LEARN').'</v></c></row>';
    $rowsXml .= '<row r="2" ht="20" customHeight="1"><c r="A2" s="2" t="s"><v>'.$addStr('Laporan Rekap Absensi & Monitoring Perizinan').'</v></c></row>';

    $metaText = 'Kelas: ' . $info['nama_kelas']
              . ' | Mapel: ' . $info['nama_mata_pelajaran']
              . ' | Guru: ' . $info['nama_guru']
              . ' | TP: ' . $info['tahun_pelajaran']
              . ' | Semester: ' . $info['semester']
              . ' | Diekspor: ' . date('d F Y, H:i') . ' WIB';
    $rowsXml .= '<row r="3" ht="18" customHeight="1"><c r="A3" s="3" t="s"><v>'.$addStr($metaText).'</v></c></row>';
    $rowsXml .= '<row r="4" ht="10" customHeight="1"></row>';

    // Header row 5
    $hCells = '';
    $hCells .= '<c r="A5" s="4" t="s"><v>'.$addStr('NO').'</v></c>';
    $hCells .= '<c r="B5" s="4" t="s"><v>'.$addStr('NISN').'</v></c>';
    $hCells .= '<c r="C5" s="4" t="s"><v>'.$addStr('NIS').'</v></c>';
    $hCells .= '<c r="D5" s="5" t="s"><v>'.$addStr('NAMA SISWA').'</v></c>';
    $hCells .= '<c r="E5" s="4" t="s"><v>'.$addStr('H').'</v></c>';
    $hCells .= '<c r="F5" s="4" t="s"><v>'.$addStr('I').'</v></c>';
    $hCells .= '<c r="G5" s="4" t="s"><v>'.$addStr('S').'</v></c>';
    $hCells .= '<c r="H5" s="4" t="s"><v>'.$addStr('A').'</v></c>';
    $hCells .= '<c r="I5" s="4" t="s"><v>'.$addStr('T').'</v></c>';
    $hCells .= '<c r="J5" s="4" t="s"><v>'.$addStr('%').'</v></c>';
    $rowsXml .= '<row r="5" ht="28" customHeight="1">'.$hCells.'</row>';

    // Data rows
    $rowIdx = 6;
    $sumH = 0; $sumI = 0; $sumS = 0; $sumA = 0; $sumT = 0;

    foreach ($siswaList as $s) {
        $isZebra = ($rowIdx % 2 === 1);
        $sC = $isZebra ? 10 : 7;
        $sB = $isZebra ? 11 : 8;
        $sid = (int)$s['id'];

        $h = 0; $i = 0; $sk = 0; $a = 0; $t = 0;
        foreach ($pertemuan as $p) {
            $pk = (int)$p['pertemuan_ke'];
            $ab = $absIndexed[$sid][$pk] ?? null;
            if (!$ab) continue;
            switch ($ab['status']) {
                case 'H': $h++; break;
                case 'I': $i++; break;
                case 'S': $sk++; break;
                case 'A': $a++; break;
                case 'T': $t++; break;
            }
        }

        $sumH += $h; $sumI += $i; $sumS += $sk; $sumA += $a; $sumT += $t;

        $total = $N;
        $pct = $total > 0 ? round((($h + $t) / $total) * 100, 1) : 0;

        $cRow = '';
        $cRow .= '<c r="A'.$rowIdx.'" s="'.$sC.'"><v>'.($rowIdx - 5).'</v></c>';
        $cRow .= '<c r="B'.$rowIdx.'" s="'.$sC.'" t="s"><v>'.$addStr($s['nisn'] ?: '-').'</v></c>';
        $cRow .= '<c r="C'.$rowIdx.'" s="'.$sC.'" t="s"><v>'.$addStr($s['nis'] ?: '-').'</v></c>';
        $cRow .= '<c r="D'.$rowIdx.'" s="'.$sB.'" t="s"><v>'.$addStr($s['nama_lengkap']).'</v></c>';
        $cRow .= '<c r="E'.$rowIdx.'" s="'.$sC.'"><v>'.$h.'</v></c>';
        $cRow .= '<c r="F'.$rowIdx.'" s="'.$sC.'"><v>'.$i.'</v></c>';
        $cRow .= '<c r="G'.$rowIdx.'" s="'.$sC.'"><v>'.$sk.'</v></c>';
        $cRow .= '<c r="H'.$rowIdx.'" s="'.$sC.'"><v>'.$a.'</v></c>';
        $cRow .= '<c r="I'.$rowIdx.'" s="'.$sC.'"><v>'.$t.'</v></c>';
        $cRow .= '<c r="J'.$rowIdx.'" s="'.$sC.'" t="s"><v>'.$addStr($pct.'%').'</v></c>';

        $rowsXml .= '<row r="'.$rowIdx.'" ht="22" customHeight="1">'.$cRow.'</row>';
        $rowIdx++;
    }

    // Summary footer
    $sumRow = $rowIdx;
    $sCells = '';
    $sCells .= '<c r="A'.$sumRow.'" s="12" t="s"><v>'.$addStr('TOTAL').'</v></c>';
    $sCells .= '<c r="B'.$sumRow.'" s="13"><v>'.count($siswaList).'</v></c>';
    $sCells .= '<c r="C'.$sumRow.'" s="12" t="s"><v>'.$addStr('Siswa').'</v></c>';
    $sCells .= '<c r="D'.$sumRow.'" s="12" t="s"><v>'.$addStr('Total Pertemuan: '.$N).'</v></c>';
    $sCells .= '<c r="E'.$sumRow.'" s="13"><v>'.$sumH.'</v></c>';
    $sCells .= '<c r="F'.$sumRow.'" s="13"><v>'.$sumI.'</v></c>';
    $sCells .= '<c r="G'.$sumRow.'" s="13"><v>'.$sumS.'</v></c>';
    $sCells .= '<c r="H'.$sumRow.'" s="13"><v>'.$sumA.'</v></c>';
    $sCells .= '<c r="I'.$sumRow.'" s="13"><v>'.$sumT.'</v></c>';
    $totalRecords = $sumH + $sumI + $sumS + $sumA + $sumT;
    $avgPct = $totalRecords > 0 ? round((($sumH + $sumT) / $totalRecords) * 100, 1) : 0;
    $sCells .= '<c r="J'.$sumRow.'" s="13" t="s"><v>'.$addStr($avgPct.'%').'</v></c>';

    $rowsXml .= '<row r="'.$sumRow.'" ht="25" customHeight="1">'.$sCells.'</row>';

    // Column widths
    $colsXml = '<cols>'
        .'<col min="1" max="1" width="6" customWidth="1"/>'
        .'<col min="2" max="2" width="16" customWidth="1"/>'
        .'<col min="3" max="3" width="14" customWidth="1"/>'
        .'<col min="4" max="4" width="32" customWidth="1"/>'
        .'<col min="5" max="9" width="8" customWidth="1"/>'
        .'<col min="10" max="10" width="10" customWidth="1"/>'
        .'</cols>';

    $sheet1Xml = '<?xml version="1.0" encoding="UTF-8"?>'
        .'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        .'<sheetViews><sheetView workbookViewId="0"><pane ySplit="5" topLeftCell="A6" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>'
        .$colsXml
        .'<sheetData>'.$rowsXml.'</sheetData>'
        .'<autoFilter ref="A5:J5"/>'
        .'</worksheet>';
    $zip->addFromString('xl/worksheets/sheet1.xml', $sheet1Xml);

    /* ==========================================================
     * SHEET 2: REKAP DETAIL (Grid per pertemuan: P# / tanggal)
     * ==========================================================
     * Layout:
     *   Row 5 (header)  : NO | NIS | NISN | NAMA SISWA | P1 | P2 | P3 | ... | PN
     *   Row 6 (sub-hdr) : (merged)                      | tgl1 | tgl2 | tgl3 | ... | tglN
     *   Row 7+ (data)   : nomor | nis | nisn | nama     | H  | I  | S  | ... | T
     * ========================================================== */
    $rows2Xml = '';
    $rows2Xml .= '<row r="1" ht="26" customHeight="1"><c r="A1" s="1" t="s"><v>'.$addStr('REKAP ABSENSI PER PERTEMUAN — '.$info['nama_kelas'].' / '.$info['nama_mata_pelajaran']).'</v></c></row>';
    $rows2Xml .= '<row r="2" ht="20" customHeight="1"><c r="A2" s="2" t="s"><v>'.$addStr('Guru: '.$info['nama_guru'].' | TP: '.$info['tahun_pelajaran'].' | Semester: '.$info['semester']).'</v></c></row>';

    $meta2 = 'Diekspor: '.date('d F Y, H:i').' WIB | Total Siswa: '.count($siswaList).' | Total Pertemuan: '.$N;
    $rows2Xml .= '<row r="3" ht="18" customHeight="1"><c r="A3" s="3" t="s"><v>'.$addStr($meta2).'</v></c></row>';
    $rows2Xml .= '<row r="4" ht="10" customHeight="1"></row>';

    // Row 5: Header (NO | NIS | NISN | NAMA SISWA | P1 | P2 | ... | PN)
    $h5Cells = '';
    $h5Cells .= '<c r="A5" s="4" t="s"><v>'.$addStr('NO').'</v></c>';
    $h5Cells .= '<c r="B5" s="4" t="s"><v>'.$addStr('NIS').'</v></c>';
    $h5Cells .= '<c r="C5" s="4" t="s"><v>'.$addStr('NISN').'</v></c>';
    $h5Cells .= '<c r="D5" s="5" t="s"><v>'.$addStr('NAMA SISWA').'</v></c>';

    $colIdx2 = 4;
    foreach ($pertemuan as $p) {
        $col = ilmuna_xlsx_col_letter($colIdx2++);
        $h5Cells .= '<c r="'.$col.'5" s="4" t="s"><v>'.$addStr('P'.(int)$p['pertemuan_ke']).'</v></c>';
    }
    $rows2Xml .= '<row r="5" ht="24" customHeight="1">'.$h5Cells.'</row>';

    // Row 6: Sub-header tanggal (A6-D6 kosong karena di-merge dengan row 5)
    $h6Cells = '';
    $colIdx2 = 4;
    foreach ($pertemuan as $p) {
        $col = ilmuna_xlsx_col_letter($colIdx2++);
        $tgl = !empty($p['tanggal']) ? date('d/m', strtotime($p['tanggal'])) : '—';
        $h6Cells .= '<c r="'.$col.'6" s="20" t="s"><v>'.$addStr($tgl).'</v></c>';
    }
    $rows2Xml .= '<row r="6" ht="20" customHeight="1">'.$h6Cells.'</row>';

    // Row 7+: Data rows
    $rIdx2 = 7;
    foreach ($siswaList as $s) {
        $isZebra = ($rIdx2 % 2 === 1);
        $sC = $isZebra ? 10 : 7;
        $sB = $isZebra ? 11 : 8;
        $sid = (int)$s['id'];

        $cRow = '';
        $cRow .= '<c r="A'.$rIdx2.'" s="'.$sC.'"><v>'.($rIdx2 - 6).'</v></c>';
        $cRow .= '<c r="B'.$rIdx2.'" s="'.$sC.'" t="s"><v>'.$addStr($s['nis'] ?: '-').'</v></c>';
        $cRow .= '<c r="C'.$rIdx2.'" s="'.$sC.'" t="s"><v>'.$addStr($s['nisn'] ?: '-').'</v></c>';
        $cRow .= '<c r="D'.$rIdx2.'" s="'.$sB.'" t="s"><v>'.$addStr($s['nama_lengkap']).'</v></c>';

        $colPtr = 4;
        foreach ($pertemuan as $p) {
            $pk = (int)$p['pertemuan_ke'];
            $ab = $absIndexed[$sid][$pk] ?? null;
            $st = $ab['status'] ?? '';
            $col = ilmuna_xlsx_col_letter($colPtr++);

            if ($st === '') {
                $cRow .= '<c r="'.$col.$rIdx2.'" s="'.$emptyStyle.'" t="s"><v>'.$addStr('·').'</v></c>';
            } else {
                $style = $statusStyleMap[$st] ?? $emptyStyle;
                $cRow .= '<c r="'.$col.$rIdx2.'" s="'.$style.'" t="s"><v>'.$addStr($st).'</v></c>';
            }
        }

        $rows2Xml .= '<row r="'.$rIdx2.'" ht="22" customHeight="1">'.$cRow.'</row>';
        $rIdx2++;
    }

    $cols2Xml = '<cols>'
        .'<col min="1" max="1" width="6" customWidth="1"/>'
        .'<col min="2" max="2" width="16" customWidth="1"/>'
        .'<col min="3" max="3" width="16" customWidth="1"/>'
        .'<col min="4" max="4" width="32" customWidth="1"/>';
    if ($N > 0) {
        $cols2Xml .= '<col min="5" max="'.(4+$N).'" width="9" customWidth="1"/>';
    }
    $cols2Xml .= '</cols>';

    $mergeCells = '<mergeCells count="4">'
        .'<mergeCell ref="A5:A6"/>'
        .'<mergeCell ref="B5:B6"/>'
        .'<mergeCell ref="C5:C6"/>'
        .'<mergeCell ref="D5:D6"/>'
        .'</mergeCells>';

    $sheet2Xml = '<?xml version="1.0" encoding="UTF-8"?>'
        .'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        .'<sheetViews><sheetView workbookViewId="0"><pane ySplit="6" topLeftCell="A7" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>'
        .$cols2Xml
        .'<sheetData>'.$rows2Xml.'</sheetData>'
        .$mergeCells
        .'</worksheet>';
    $zip->addFromString('xl/worksheets/sheet2.xml', $sheet2Xml);

    /* ==========================================================
     * SHEET 3: INFO PERTEMUAN
     * ========================================================== */
    $rows3Xml = '';
    $rows3Xml .= '<row r="1" ht="26" customHeight="1"><c r="A1" s="1" t="s"><v>'.$addStr('INFO PERTEMUAN — '.$info['nama_kelas'].' / '.$info['nama_mata_pelajaran']).'</v></c></row>';
    $rows3Xml .= '<row r="2" ht="20" customHeight="1"><c r="A2" s="2" t="s"><v>'.$addStr('Guru: '.$info['nama_guru'].' | TP: '.$info['tahun_pelajaran'].' | Semester: '.$info['semester']).'</v></c></row>';
    $rows3Xml .= '<row r="3" ht="10" customHeight="1"></row>';

    $h3Headers = ['PERTEMUAN','TANGGAL','JAM MULAI','JAM SELESAI','MATERI','STATUS','HADIR','IZIN','SAKIT','ALPA','TERLAMBAT'];
    $h3Cells = '';
    $colPtr = 0;
    foreach ($h3Headers as $title) {
        $col = ilmuna_xlsx_col_letter($colPtr++);
        $style = in_array($title, ['MATERI','STATUS']) ? 5 : 4;
        $h3Cells .= '<c r="'.$col.'4" s="'.$style.'" t="s"><v>'.$addStr($title).'</v></c>';
    }
    $rows3Xml .= '<row r="4" ht="28" customHeight="1">'.$h3Cells.'</row>';

    if (empty($pertemuan)) {
        $rows3Xml .= '<row r="5" ht="24" customHeight="1"><c r="A5" s="3" t="s"><v>'.$addStr('Belum ada pertemuan KBM untuk mata pelajaran ini.').'</v></c></row>';
    } else {
        $rIdx3 = 5;
        foreach ($pertemuan as $p) {
            $isZebra = ($rIdx3 % 2 === 1);
            $sL = $isZebra ? 9  : 6;
            $sC = $isZebra ? 10 : 7;

            $pk = (int)$p['pertemuan_ke'];
            $tanggal = !empty($p['tanggal']) ? date('d M Y', strtotime($p['tanggal'])) : '-';
            $jamM = !empty($p['jam_mulai']) ? substr($p['jam_mulai'],0,5) : '-';
            $jamS = !empty($p['jam_selesai']) ? substr($p['jam_selesai'],0,5) : '-';
            $materi = !empty($p['materi']) ? (string)$p['materi'] : '-';
            $statusJ = !empty($p['status_jadwal']) ? (string)$p['status_jadwal'] : '-';

            $cH = $cI = $cS = $cA = $cT = 0;
            foreach ($siswaList as $s) {
                $ab = $absIndexed[(int)$s['id']][$pk] ?? null;
                if (!$ab) continue;
                switch ($ab['status']) {
                    case 'H': $cH++; break;
                    case 'I': $cI++; break;
                    case 'S': $cS++; break;
                    case 'A': $cA++; break;
                    case 'T': $cT++; break;
                }
            }

            $cRow = '';
            $cRow .= '<c r="A'.$rIdx3.'" s="'.$sC.'" t="s"><v>'.$addStr('P'.$pk).'</v></c>';
            $cRow .= '<c r="B'.$rIdx3.'" s="'.$sC.'" t="s"><v>'.$addStr($tanggal).'</v></c>';
            $cRow .= '<c r="C'.$rIdx3.'" s="'.$sC.'" t="s"><v>'.$addStr($jamM).'</v></c>';
            $cRow .= '<c r="D'.$rIdx3.'" s="'.$sC.'" t="s"><v>'.$addStr($jamS).'</v></c>';
            $cRow .= '<c r="E'.$rIdx3.'" s="'.$sL.'" t="s"><v>'.$addStr($materi).'</v></c>';
            $cRow .= '<c r="F'.$rIdx3.'" s="'.$sC.'" t="s"><v>'.$addStr($statusJ).'</v></c>';
            $cRow .= '<c r="G'.$rIdx3.'" s="'.$sC.'"><v>'.$cH.'</v></c>';
            $cRow .= '<c r="H'.$rIdx3.'" s="'.$sC.'"><v>'.$cI.'</v></c>';
            $cRow .= '<c r="I'.$rIdx3.'" s="'.$sC.'"><v>'.$cS.'</v></c>';
            $cRow .= '<c r="J'.$rIdx3.'" s="'.$sC.'"><v>'.$cA.'</v></c>';
            $cRow .= '<c r="K'.$rIdx3.'" s="'.$sC.'"><v>'.$cT.'</v></c>';

            $rows3Xml .= '<row r="'.$rIdx3.'" ht="22" customHeight="1">'.$cRow.'</row>';
            $rIdx3++;
        }

        $sumRow3 = $rIdx3;
        $sCells3 = '<c r="A'.$sumRow3.'" s="12" t="s"><v>'.$addStr('TOTAL:').'</v></c>';
        $sCells3 .= '<c r="B'.$sumRow3.'" s="13"><v>'.$N.'</v></c>';
        $sCells3 .= '<c r="C'.$sumRow3.'" s="12" t="s"><v>'.$addStr('Pertemuan').'</v></c>';
        for ($col2 = 3; $col2 < 11; $col2++) {
            $colL = ilmuna_xlsx_col_letter($col2);
            $sCells3 .= '<c r="'.$colL.$sumRow3.'" s="13" t="s"><v>'.$addStr('-').'</v></c>';
        }
        $rows3Xml .= '<row r="'.$sumRow3.'" ht="25" customHeight="1">'.$sCells3.'</row>';
    }

    $cols3Xml = '<cols>'
        .'<col min="1" max="1" width="14" customWidth="1"/>'
        .'<col min="2" max="2" width="18" customWidth="1"/>'
        .'<col min="3" max="3" width="14" customWidth="1"/>'
        .'<col min="4" max="4" width="14" customWidth="1"/>'
        .'<col min="5" max="5" width="55" customWidth="1"/>'
        .'<col min="6" max="6" width="18" customWidth="1"/>'
        .'<col min="7" max="11" width="10" customWidth="1"/>'
        .'</cols>';

    $sheet3Xml = '<?xml version="1.0" encoding="UTF-8"?>'
        .'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        .'<sheetViews><sheetView workbookViewId="0"><pane ySplit="4" topLeftCell="A5" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>'
        .$cols3Xml
        .'<sheetData>'.$rows3Xml.'</sheetData>'
        .'<autoFilter ref="A4:K4"/>'
        .'</worksheet>';
    $zip->addFromString('xl/worksheets/sheet3.xml', $sheet3Xml);

    // Shared Strings
    $zip->addFromString('xl/sharedStrings.xml', ilmuna_build_shared_strings_xml($strings));

    if (!$zip->close()) {
        @unlink($tmp);
        throw new Exception('Gagal menyimpan arsip Excel.');
    }
    if (!file_exists($tmp) || filesize($tmp) === 0) {
        @unlink($tmp);
        throw new Exception('Berkas Excel hasil ekspor kosong.');
    }
    return $tmp;
}

/* ==============================================================================
 * ROUTER
 * ============================================================================== */
$action = $_GET['action'] ?? $_POST['action'] ?? '';

if ($action === 'export_excel') {
    try {
        $idKmp = (int)($_GET['kmp_id'] ?? 0);
        if (!$idKmp) throw new Exception('Parameter kmp_id tidak valid.');

        $data = ilmuna_query_rekap($pdo, $idKmp);
        if (!$data) throw new Exception('Data kelas-mapel tidak ditemukan.');

        $tmpFile = ilmuna_generate_rekap_export($data);
        if (!$tmpFile || !file_exists($tmpFile)) throw new Exception('Gagal membuat Excel sementara.');

        $slug = preg_replace('/[^A-Za-z0-9]+/', '-',
            $data['info']['nama_kelas'].'-'.$data['info']['nama_mata_pelajaran']);
        $slug = trim($slug, '-');
        $filename = 'rekap-absensi-'.$slug.'-'.date('Ymd-His').'.xlsx';

        while (ob_get_level() > 0) ob_end_clean();
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
        while (ob_get_level() > 0) ob_end_clean();
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success'=>false, 'data'=>['message'=>'Export error: '.$e->getMessage()]]);
        exit;
    }
}

http_response_code(400);
header('Content-Type: application/json; charset=utf-8');
echo json_encode(['success'=>false, 'data'=>['message'=>'Aksi tidak dikenali: '.$action]]);