<?php
/**
 * ==============================================================================
 * ILMUNA LEARN — BACKEND EXCEL HANDLER (ASSESSMENTS / ASESMEN PEMBELAJARAN)
 * ==============================================================================
 * Mengelola Operasi Spreadsheet Master Asesmen Kurikulum Merdeka:
 * 1. Download Template Import Excel (.xlsx) 4-Sheet dengan styling Lumina Gold:
 *    - Sheet 1: Template Data Asesmen (Durasi, Jadwal Tanggal, Jam Mulai, Jam Selesai Akumulatif)
 *    - Sheet 2: Referensi Modul Ajar (MOD-{id}, Judul, Pertemuan, Mapel, Guru, TP dari DB)
 *    - Sheet 3: Referensi Guru & Mapel Rombel (KMP-{id}, Kelas, Mapel, Guru, NIP, TP dari DB)
 *    - Sheet 4: Panduan & Nilai Valid (Kategori, Tipe Khusus, Status, Format Jam & Tanggal)
 * 2. Import Data Asesmen dari Excel (.xlsx) dengan validasi lengkap:
 *    - Mendukung format kode aman: KMP-X, MOD-X, atau angka ID langsung
 *    - Multi-Rombel Kelas dalam 1 baris (contoh: KMP-1, KMP-2)
 *    - Sinkronisasi otomatis CP, TP, ATP, ITP dari Modul Ajar
 *    - Perhitungan otomatis Jam Selesai = Jam Mulai + Durasi Menit
 *    - Anti-duplikasi dan laporan log eksekusi transaksional
 *
 * Standar Output: OpenXML Spreadsheet (.xlsx) Native ZipArchive / Pure PHP Fallback
 * ==============================================================================
 */

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../auth.php';

if (php_sapi_name() !== 'cli') {
    requireRole('admin');
}

$pdo = getDBConnection();

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

if (!function_exists('ilmuna_xlsx_col_index')) {
    function ilmuna_xlsx_col_index($letter) {
        $letter = strtoupper($letter);
        $num = 0;
        for ($i = 0; $i < strlen($letter); $i++) {
            $num = $num * 26 + (ord($letter[$i]) - 64);
        }
        return $num - 1;
    }
}

/**
 * ==============================================================================
 * ENGINE: HYBRID ZIPARCHIVE & PURE-PHP ZIP ADAPTER (ZERO-FAILSAFE)
 * ==============================================================================
 */
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

        // Mode Pure PHP Engine:
        if ($flags & (self::CREATE | self::OVERWRITE)) {
            $this->files = [];
            return true;
        }

        // Mode baca (Extract)
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

        if ($this->zipData === null && file_exists($this->filePath)) {
            $this->zipData = @file_get_contents($this->filePath);
        }

        if ($this->zipData) {
            return self::extractEntryFromPureZip($this->zipData, $localname);
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

        $entries = '';
        $cdir = '';
        $offset = 0;

        foreach ($files as $name => $data) {
            $name = str_replace('\\', '/', $name);
            $nameLen = strlen($name);
            $uncompLen = strlen($data);
            $crc = crc32($data);

            if (function_exists('gzdeflate')) {
                $compData = gzdeflate($data);
                $compLen = strlen($compData);
                $method = 8;
            } else {
                $compData = $data;
                $compLen = $uncompLen;
                $method = 0;
            }

            // Local file header (30 bytes + name)
            $lh = pack('VvvvVVVvv',
                0x04034b50, 20, 0, $method, $dostime,
                $crc, $compLen, $uncompLen, $nameLen, 0
            ) . $name;

            $entries .= $lh . $compData;

            // Central directory record (46 bytes + name)
            $cd = pack('VvvvvVVVVvvvvvVV',
                0x02014b50, 20, 20, 0, $method, $dostime,
                $crc, $compLen, $uncompLen, $nameLen, 0, 0, 0, 0, 32, $offset
            ) . $name;

            $cdir .= $cd;
            $offset += strlen($lh) + $compLen;
        }

        $cdirLen = strlen($cdir);
        $count = count($files);

        // End of central directory record (22 bytes)
        $eocd = pack('VvvvvVVv',
            0x06054b50, 0, 0, $count, $count,
            $cdirLen, $offset, 0
        );

        return $entries . $cdir . $eocd;
    }

    private static function extractEntryFromPureZip($zipData, $targetName) {
        $targetName = str_replace('\\', '/', $targetName);
        $len = strlen($zipData);
        if ($len < 22) return false;

        $eocdPos = strrpos($zipData, "\x50\x4b\x05\x06");
        if ($eocdPos === false) return false;

        $cdOffset = unpack('V', substr($zipData, $eocdPos + 16, 4))[1];
        $pos = $cdOffset;

        while ($pos < $eocdPos) {
            if (substr($zipData, $pos, 4) !== "\x50\x4b\x01\x02") {
                break;
            }

            $method = unpack('v', substr($zipData, $pos + 10, 2))[1];
            $cSize = unpack('V', substr($zipData, $pos + 20, 4))[1];
            $fnLen = unpack('v', substr($zipData, $pos + 28, 2))[1];
            $extraLen = unpack('v', substr($zipData, $pos + 30, 2))[1];
            $commLen = unpack('v', substr($zipData, $pos + 32, 2))[1];
            $localOffset = unpack('V', substr($zipData, $pos + 42, 4))[1];
            $fn = substr($zipData, $pos + 46, $fnLen);

            if (str_replace('\\', '/', $fn) === $targetName) {
                $lh = substr($zipData, $localOffset, 30);
                $lhFnLen = unpack('v', substr($lh, 26, 2))[1];
                $lhExtraLen = unpack('v', substr($lh, 28, 2))[1];
                $dataStart = $localOffset + 30 + $lhFnLen + $lhExtraLen;
                $raw = substr($zipData, $dataStart, $cSize);
                if ($method === 8 && function_exists('gzinflate')) {
                    return @gzinflate($raw);
                }
                return $raw;
            }

            $pos += 46 + $fnLen + $extraLen + $commLen;
        }

        return false;
    }
}
}

/**
 * Membaca baris dari file .xlsx (Hybrid Engine: Native ZipArchive / Pure PHP SimpleXML)
 */
if (!function_exists('ilmuna_read_excel_rows')) {
    function ilmuna_read_excel_rows($filePath) {
        $zip = new IlmunaZipArchive();
        if ($zip->open($filePath) !== true) {
            return ['error' => 'Berkas Excel tidak valid atau rusak (gagal membaca arsip berkas).'];
        }

        // 1. Shared Strings
        $strings = [];
        $ssXml = $zip->getFromName('xl/sharedStrings.xml');
        if ($ssXml) {
            $xml = @simplexml_load_string($ssXml);
            if ($xml) {
                foreach ($xml->si as $si) {
                    if (isset($si->t)) {
                        $strings[] = (string) $si->t;
                    } elseif (isset($si->r)) {
                        $txt = '';
                        foreach ($si->r as $r) {
                            $txt .= (string) $r->t;
                        }
                        $strings[] = $txt;
                    } else {
                        $strings[] = '';
                    }
                }
            }
        }

        // 2. Baca sheet1.xml (Template Import Sheet)
        $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();

        if (!$sheetXml) {
            return ['error' => 'Worksheet Sheet1 tidak ditemukan dalam berkas Excel.'];
        }

        $xml = @simplexml_load_string($sheetXml);
        if (!$xml || !isset($xml->sheetData)) {
            return ['error' => 'Struktur data worksheet tidak dapat dibaca.'];
        }

        $rows = [];
        foreach ($xml->sheetData->row as $row) {
            $rowData = [];
            foreach ($row->c as $c) {
                $ref = (string) $c['r'];
                $colL = preg_replace('/[0-9]/', '', $ref);
                $colI = ilmuna_xlsx_col_index($colL);
                $type = (string) $c['t'];
                $v = (string) $c->v;

                if ($type === 's') {
                    $v = $strings[(int)$v] ?? '';
                } elseif ($type === 'inlineStr' && isset($c->is->t)) {
                    $v = (string) $c->is->t;
                }
                $rowData[$colI] = trim($v);
            }

            if (!empty($rowData)) {
                $maxIdx = max(array_keys($rowData));
                $full = [];
                for ($i = 0; $i <= $maxIdx; $i++) {
                    $full[] = $rowData[$i] ?? '';
                }
                $rows[] = $full;
            }
        }

        return ['rows' => $rows];
    }
}

/**
 * Alokasi File Excel Sementara yang Aman & Terjamin Izin Tulisnya
 */
if (!function_exists('ilmuna_create_temp_xlsx')) {
    function ilmuna_create_temp_xlsx($prefix = 'ilmuna_') {
        $randomSuffix = date('Ymd_His') . '_' . bin2hex(random_bytes(6)) . '.xlsx';

        // 1. Folder uploads proyek
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

        // 2. Laragon tmp
        $laragonTmp = 'C:/laragon/tmp';
        if (is_dir($laragonTmp) && is_writable($laragonTmp)) {
            $path = $laragonTmp . '/' . $prefix . $randomSuffix;
            if (file_exists($path)) {
                @unlink($path);
            }
            return $path;
        }

        // 3. Fallback sistem temp
        $sysTmp = sys_get_temp_dir();
        $path = $sysTmp . DIRECTORY_SEPARATOR . $prefix . $randomSuffix;
        if (file_exists($path)) {
            @unlink($path);
        }
        return $path;
    }
}

/**
 * Stylesheet Tema Lumina Gold (Navy #1D3B5E, Teal #0D7A87, Gold #F59E0B)
 */
if (!function_exists('ilmuna_get_xlsx_theme_styles')) {
    function ilmuna_get_xlsx_theme_styles() {
        return '<?xml version="1.0" encoding="UTF-8"?>'
            .'<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            .'<fonts count="10">'
            .'<font><sz val="10"/><name val="Segoe UI"/></font>'                             // 0: Default
            .'<font><b/><sz val="15"/><color rgb="FF1D3B5E"/><name val="Segoe UI"/></font>'  // 1: Brand Title (Navy)
            .'<font><b/><sz val="11"/><color rgb="FF0D7A87"/><name val="Segoe UI"/></font>'  // 2: Subtitle (Teal)
            .'<font><i/><sz val="9.5"/><color rgb="FF64748B"/><name val="Segoe UI"/></font>' // 3: Meta Info (Slate)
            .'<font><b/><sz val="10"/><color rgb="FFFFFFFF"/><name val="Segoe UI"/></font>'  // 4: Table Header (White Bold)
            .'<font><sz val="10"/><color rgb="FF1E293B"/><name val="Segoe UI"/></font>'      // 5: Body Regular
            .'<font><b/><sz val="10"/><color rgb="FF1D3B5E"/><name val="Segoe UI"/></font>'  // 6: Body Bold Navy
            .'<font><sz val="9.5"/><color rgb="FF64748B"/><name val="Segoe UI"/></font>'     // 7: Body Muted
            .'<font><b/><sz val="10"/><color rgb="FF0F172A"/><name val="Segoe UI"/></font>'  // 8: Footer Summary
            .'<font><b/><sz val="10"/><color rgb="FFD97706"/><name val="Segoe UI"/></font>'  // 9: Warning/Notice (Amber)
            .'</fonts>'
            .'<fills count="6">'
            .'<fill><patternFill patternType="none"/></fill>'                                  // 0: None
            .'<fill><patternFill patternType="gray125"/></fill>'                               // 1: Gray125
            .'<fill><patternFill patternType="solid"><fgColor rgb="FF1D3B5E"/></patternFill></fill>' // 2: Header Navy
            .'<fill><patternFill patternType="solid"><fgColor rgb="FFF8FAFC"/></patternFill></fill>' // 3: Zebra Soft Slate
            .'<fill><patternFill patternType="solid"><fgColor rgb="FFE2E8F0"/></patternFill></fill>' // 4: Summary Row Slate
            .'<fill><patternFill patternType="solid"><fgColor rgb="FFFEF3C7"/></patternFill></fill>' // 5: Warning Soft Amber
            .'</fills>'
            .'<borders count="4">'
            .'<border><left/><right/><top/><bottom/></border>'                                // 0: None
            .'<border>'                                                                       // 1: Data Thin Border
            .'<left style="thin"><color rgb="FFCBD5E1"/></left>'
            .'<right style="thin"><color rgb="FFCBD5E1"/></right>'
            .'<top style="thin"><color rgb="FFCBD5E1"/></top>'
            .'<bottom style="thin"><color rgb="FFCBD5E1"/></bottom>'
            .'</border>'
            .'<border>'                                                                       // 2: Header Border
            .'<left style="thin"><color rgb="FF334155"/></left>'
            .'<right style="thin"><color rgb="FF334155"/></right>'
            .'<top style="thin"><color rgb="FF334155"/></top>'
            .'<bottom style="medium"><color rgb="FF0D7A87"/></bottom>'
            .'</border>'
            .'<border>'                                                                       // 3: Summary Border
            .'<left style="thin"><color rgb="FF94A3B8"/></left>'
            .'<right style="thin"><color rgb="FF94A3B8"/></right>'
            .'<top style="thin"><color rgb="FF94A3B8"/></top>'
            .'<bottom style="double"><color rgb="FF1D3B5E"/></bottom>'
            .'</border>'
            .'</borders>'
            .'<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            .'<cellXfs count="15">'
            .'<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>' // 0: Default
            .'<xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0"><alignment vertical="center"/></xf>' // 1: Title
            .'<xf numFmtId="0" fontId="2" fillId="0" borderId="0" xfId="0"><alignment vertical="center"/></xf>' // 2: Subtitle
            .'<xf numFmtId="0" fontId="3" fillId="0" borderId="0" xfId="0"><alignment vertical="center"/></xf>' // 3: Meta
            .'<xf numFmtId="0" fontId="4" fillId="2" borderId="2" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center" wrapText="1"/></xf>' // 4: Header Center
            .'<xf numFmtId="0" fontId="4" fillId="2" borderId="2" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="left" vertical="center" wrapText="1"/></xf>'   // 5: Header Left
            .'<xf numFmtId="0" fontId="5" fillId="0" borderId="1" xfId="0" applyFont="1" applyBorder="1" applyAlignment="1"><alignment horizontal="left" vertical="center" wrapText="1"/></xf>'                  // 6: Body Left
            .'<xf numFmtId="0" fontId="5" fillId="0" borderId="1" xfId="0" applyFont="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>'                            // 7: Body Center
            .'<xf numFmtId="0" fontId="6" fillId="0" borderId="1" xfId="0" applyFont="1" applyBorder="1" applyAlignment="1"><alignment horizontal="left" vertical="center"/></xf>'                             // 8: Body Bold Left
            .'<xf numFmtId="0" fontId="5" fillId="3" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="left" vertical="center" wrapText="1"/></xf>'   // 9: Zebra Left
            .'<xf numFmtId="0" fontId="5" fillId="3" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>'             // 10: Zebra Center
            .'<xf numFmtId="0" fontId="6" fillId="3" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="left" vertical="center"/></xf>'              // 11: Zebra Bold Left
            .'<xf numFmtId="0" fontId="8" fillId="4" borderId="3" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="left" vertical="center"/></xf>'              // 12: Summary Left
            .'<xf numFmtId="0" fontId="8" fillId="4" borderId="3" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>'            // 13: Summary Center
            .'<xf numFmtId="0" fontId="9" fillId="5" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>'            // 14: Warning Pill
            .'</cellXfs>'
            .'</styleSheet>';
    }
}

/**
 * Parser Tanggal Fleksibel (String YYYY-MM-DD, DD/MM/YYYY, atau Serial Excel Timestamp)
 */
if (!function_exists('ilmuna_parse_excel_date')) {
    function ilmuna_parse_excel_date($val) {
        $val = trim((string)$val);
        if ($val === '') return date('Y-m-d');

        // Jika angka serial Excel (misal 45678)
        if (is_numeric($val) && (float)$val > 1000) {
            $unix = ((float)$val - 25569) * 86400;
            return gmdate('Y-m-d', (int)$unix);
        }

        // Format DD/MM/YYYY
        if (preg_match('/^(\d{1,2})[\/\-\.](\d{1,2})[\/\-\.](\d{4})$/', $val, $m)) {
            return sprintf('%04d-%02d-%02d', (int)$m[3], (int)$m[2], (int)$m[1]);
        }

        // Format YYYY-MM-DD
        if (preg_match('/^(\d{4})[\/\-\.](\d{1,2})[\/\-\.](\d{1,2})$/', $val, $m)) {
            return sprintf('%04d-%02d-%02d', (int)$m[1], (int)$m[2], (int)$m[3]);
        }

        $timestamp = strtotime($val);
        if ($timestamp !== false && $timestamp > 0) {
            return date('Y-m-d', $timestamp);
        }

        return date('Y-m-d');
    }
}

/**
 * Parser Waktu Fleksibel (String HH:MM, HH:MM:SS, atau Desimal Pecahan Hari Excel)
 */
if (!function_exists('ilmuna_parse_excel_time')) {
    function ilmuna_parse_excel_time($val) {
        $val = trim((string)$val);
        if ($val === '') return '07:30:00';

        // Jika pecahan desimal Excel (misal 0.3125 = 07:30:00)
        if (is_numeric($val) && (float)$val >= 0 && (float)$val < 1) {
            $totalSecs = (int)round((float)$val * 86400);
            $h = intdiv($totalSecs, 3600);
            $m = intdiv($totalSecs % 3600, 60);
            $s = $totalSecs % 60;
            return sprintf('%02d:%02d:%02d', $h % 24, $m, $s);
        }

        if (preg_match('/^(\d{1,2}):(\d{2})(?::(\d{2}))?$/', $val, $m)) {
            return sprintf('%02d:%02d:%02d', (int)$m[1], (int)$m[2], isset($m[3]) ? (int)$m[3] : 0);
        }

        $timestamp = strtotime($val);
        if ($timestamp !== false) {
            return date('H:i:s', $timestamp);
        }

        return '07:30:00';
    }
}

/**
 * Helper: Ekstrak ID Relasi Rombel Mapel (Mendukung KMA-1angkarandomID2angkarandom, KMP-1, kmp-1, atau angka langsung)
 */
if (!function_exists('ilmuna_parse_kmp_id')) {
    function ilmuna_parse_kmp_id($token) {
        $token = trim((string)$token);
        if ($token === '') return null;

        // 1. Format resmi database: KMA-1angkarandomID2angkarandom (contoh: KMA-9114 -> ID 1, KMA-31023 -> ID 10)
        if (preg_match('/^KMA-[0-9](\d+)[0-9]{2}$/i', $token, $m)) {
            $id = (int)$m[1];
            return $id > 0 ? $id : null;
        }

        // 2. Format legacy: KMP-X atau ID angka langsung
        if (preg_match('/^(?:KMP-)?(\d+)$/i', $token, $m)) {
            $id = (int)$m[1];
            return $id > 0 ? $id : null;
        }

        // 3. Fallback jika terdapat angka di dalam string
        if (preg_match('/(\d+)/', $token, $m)) {
            $id = (int)$m[1];
            return $id > 0 ? $id : null;
        }

        return null;
    }
}

/**
 * Helper: Ekstrak ID Modul Ajar (Mendukung MOD-1, mod-1, atau angka langsung)
 */
if (!function_exists('ilmuna_parse_modul_id')) {
    function ilmuna_parse_modul_id($token) {
        $token = trim((string)$token);
        if ($token === '') return null;
        if (preg_match('/(\d+)/', $token, $m)) {
            $id = (int)$m[1];
            return $id > 0 ? $id : null;
        }
        return null;
    }
}


/* ==============================================================================
 * BUILDER WORKBOOK SHEETS (.XML)
 * ============================================================================== */

if (!function_exists('ilmuna_build_modul_ajar_sheet2_xml')) {
function ilmuna_build_modul_ajar_sheet2_xml(&$strings, &$map, $addStr) {
    $pdo = getDBConnection();
    $sql = "
        SELECT 
            ma.id, 
            ma.judul_modul, 
            ma.pertemuan_ke,
            k.nama_kelas, 
            mp.nama_mata_pelajaran,
            COALESCE(g.nama_guru, 'Guru Pengampu PAI') AS nama_guru,
            COALESCE(tp.kode_tp, '-') AS kode_tp,
            COALESCE(tp.tp, '-') AS deskripsi_tp
        FROM modul_ajar ma
        LEFT JOIN kelas_mata_pelajaran kmp ON ma.id_kelas_mata_pelajaran = kmp.id
        LEFT JOIN kelas k ON kmp.id_kelas = k.id
        LEFT JOIN mata_pelajaran mp ON kmp.id_mata_pelajaran = mp.id
        LEFT JOIN guru g ON ma.id_guru = g.id
        LEFT JOIN tp ON ma.id_tp = tp.id
        ORDER BY ma.id ASC
    ";
    $stmt = $pdo->query($sql);
    $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];

    $rowsXml = '';

    // Row 1: Title
    $rowsXml .= '<row r="1" ht="26" customHeight="1"><c r="A1" s="1" t="s"><v>'.$addStr('DAFTAR REFERENSI MODUL AJAR (DATABASE AKTIF)').'</v></c></row>';

    // Row 2: Subtitle
    $sub = 'Petunjuk: Masukkan nilai kolom "KODE MODUL" (contoh: MOD-1 atau 1) ke kolom kode_modul_ajar pada Sheet 1.';
    $rowsXml .= '<row r="2" ht="20" customHeight="1"><c r="A2" s="2" t="s"><v>'.$addStr($sub).'</v></c></row>';

    // Row 3: Meta Info
    $meta = 'Total: ' . count($rows) . ' Modul Ajar Terdaftar | Otomatis mengisi CP, TP, ATP, ITP, Pertemuan, dan Guru Pengampu.';
    $rowsXml .= '<row r="3" ht="18" customHeight="1"><c r="A3" s="3" t="s"><v>'.$addStr($meta).'</v></c></row>';

    // Row 4: Spacer
    $rowsXml .= '<row r="4" ht="10" customHeight="1"></row>';

    // Row 5: Headers
    $headers = [
        ['col' => 'A', 'title' => 'NO', 'style' => 4],
        ['col' => 'B', 'title' => 'KODE MODUL', 'style' => 4],
        ['col' => 'C', 'title' => 'ID MODUL', 'style' => 4],
        ['col' => 'D', 'title' => 'JUDUL MODUL AJAR', 'style' => 5],
        ['col' => 'E', 'title' => 'PERTEMUAN KE-', 'style' => 4],
        ['col' => 'F', 'title' => 'ROMBEL KELAS', 'style' => 5],
        ['col' => 'G', 'title' => 'MATA PELAJARAN', 'style' => 5],
        ['col' => 'H', 'title' => 'GURU PENGAMPU', 'style' => 5],
        ['col' => 'I', 'title' => 'KODE TP', 'style' => 4],
        ['col' => 'J', 'title' => 'TUJUAN PEMBELAJARAN (TP)', 'style' => 5]
    ];
    $hCells = '';
    foreach ($headers as $h) {
        $hCells .= '<c r="'.$h['col'].'5" s="'.$h['style'].'" t="s"><v>'.$addStr($h['title']).'</v></c>';
    }
    $rowsXml .= '<row r="5" ht="28" customHeight="1">'.$hCells.'</row>';

    // Data Rows
    $rNum = 6;
    foreach ($rows as $idx => $r) {
        $isZebra = ($rNum % 2 === 1);
        $sCenter = $isZebra ? 10 : 7;
        $sLeft   = $isZebra ? 9  : 6;
        $sBold   = $isZebra ? 11 : 8;

        $cRow = '';
        $cRow .= '<c r="A'.$rNum.'" s="'.$sCenter.'"><v>'.($idx + 1).'</v></c>';
        $cRow .= '<c r="B'.$rNum.'" s="'.$sCenter.'" t="s"><v>'.$addStr('MOD-' . $r['id']).'</v></c>';
        $cRow .= '<c r="C'.$rNum.'" s="'.$sCenter.'"><v>'.$r['id'].'</v></c>';
        $cRow .= '<c r="D'.$rNum.'" s="'.$sBold.'" t="s"><v>'.$addStr($r['judul_modul'] ?? '-').'</v></c>';
        $cRow .= '<c r="E'.$rNum.'" s="'.$sCenter.'"><v>'.(int)($r['pertemuan_ke'] ?? 1).'</v></c>';
        $cRow .= '<c r="F'.$rNum.'" s="'.$sLeft.'" t="s"><v>'.$addStr($r['nama_kelas'] ?? '-').'</v></c>';
        $cRow .= '<c r="G'.$rNum.'" s="'.$sLeft.'" t="s"><v>'.$addStr($r['nama_mata_pelajaran'] ?? '-').'</v></c>';
        $cRow .= '<c r="H'.$rNum.'" s="'.$sLeft.'" t="s"><v>'.$addStr($r['nama_guru'] ?? '-').'</v></c>';
        $cRow .= '<c r="I'.$rNum.'" s="'.$sCenter.'" t="s"><v>'.$addStr($r['kode_tp'] ?? '-').'</v></c>';
        $cRow .= '<c r="J'.$rNum.'" s="'.$sLeft.'" t="s"><v>'.$addStr($r['deskripsi_tp'] ?? '-').'</v></c>';

        $rowsXml .= '<row r="'.$rNum.'" ht="24" customHeight="1">'.$cRow.'</row>';
        $rNum++;
    }

    // Cols Width
    $colsXml = '<cols>'
        .'<col min="1" max="1" width="7" customWidth="1"/>'
        .'<col min="2" max="2" width="16" customWidth="1"/>'
        .'<col min="3" max="3" width="12" customWidth="1"/>'
        .'<col min="4" max="4" width="45" customWidth="1"/>'
        .'<col min="5" max="5" width="16" customWidth="1"/>'
        .'<col min="6" max="6" width="26" customWidth="1"/>'
        .'<col min="7" max="7" width="32" customWidth="1"/>'
        .'<col min="8" max="8" width="28" customWidth="1"/>'
        .'<col min="9" max="9" width="16" customWidth="1"/>'
        .'<col min="10" max="10" width="55" customWidth="1"/>'
        .'</cols>';

    return '<?xml version="1.0" encoding="UTF-8"?>'
        .'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        .'<sheetViews><sheetView workbookViewId="0"><pane ySplit="5" topLeftCell="A6" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>'
        .$colsXml
        .'<sheetData>'.$rowsXml.'</sheetData>'
        .'<autoFilter ref="A5:J5"/>'
        .'</worksheet>';
}
}

/**
 * SHEET 3: Referensi Guru & Mapel Rombel Kelas dari Database
 */
if (!function_exists('ilmuna_build_guru_mapel_sheet3_xml')) {
function ilmuna_build_guru_mapel_sheet3_xml(&$strings, &$map, $addStr) {
    $pdo = getDBConnection();
    $sql = "
        SELECT 
            kmp.id, 
            kmp.kode_kelas_mapel,
            k.nama_kelas, 
            mp.nama_mata_pelajaran,
            COALESCE(g.nama_guru, 'Guru Pengampu PAI') AS nama_guru,
            COALESCE(g.nip, '-') AS nip,
            k.tahun_pelajaran
        FROM kelas_mata_pelajaran kmp
        LEFT JOIN kelas k ON kmp.id_kelas = k.id
        LEFT JOIN mata_pelajaran mp ON kmp.id_mata_pelajaran = mp.id
        LEFT JOIN guru_mata_pelajaran gmp ON kmp.id_guru_mata_pelajaran = gmp.id
        LEFT JOIN guru g ON gmp.id_guru = g.id
        ORDER BY kmp.id ASC
    ";
    $stmt = $pdo->query($sql);
    $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];

    $rowsXml = '';

    // Row 1: Title
    $rowsXml .= '<row r="1" ht="26" customHeight="1"><c r="A1" s="1" t="s"><v>'.$addStr('DAFTAR REFERENSI GURU, MAPEL &amp; ROMBEL KELAS (DATABASE AKTIF)').'</v></c></row>';

    // Row 2: Subtitle
    $sub = 'Petunjuk: Masukkan nilai kolom "KODE ROMBEL MAPEL" (contoh: KMA-9114 atau KMP-1) ke kolom kode_rombel_mapel pada Sheet 1. Mendukung multi-kode dipisah koma (contoh: KMA-9114, KMA-2239).';
    $rowsXml .= '<row r="2" ht="20" customHeight="1"><c r="A2" s="2" t="s"><v>'.$addStr($sub).'</v></c></row>';

    // Row 3: Meta
    $meta = 'Total: ' . count($rows) . ' Rombel Mapel Terdaftar di Database Sekolah.';
    $rowsXml .= '<row r="3" ht="18" customHeight="1"><c r="A3" s="3" t="s"><v>'.$addStr($meta).'</v></c></row>';

    // Row 4: Spacer
    $rowsXml .= '<row r="4" ht="10" customHeight="1"></row>';

    // Row 5: Headers
    $headers = [
        ['col' => 'A', 'title' => 'NO', 'style' => 4],
        ['col' => 'B', 'title' => 'KODE ROMBEL MAPEL', 'style' => 4],
        ['col' => 'C', 'title' => 'ID KMP', 'style' => 4],
        ['col' => 'D', 'title' => 'NAMA KELAS / ROMBEL', 'style' => 5],
        ['col' => 'E', 'title' => 'MATA PELAJARAN', 'style' => 5],
        ['col' => 'F', 'title' => 'GURU PENGAMPU', 'style' => 5],
        ['col' => 'G', 'title' => 'NIP GURU', 'style' => 4],
        ['col' => 'H', 'title' => 'TAHUN PELAJARAN', 'style' => 4]
    ];
    $hCells = '';
    foreach ($headers as $h) {
        $hCells .= '<c r="'.$h['col'].'5" s="'.$h['style'].'" t="s"><v>'.$addStr($h['title']).'</v></c>';
    }
    $rowsXml .= '<row r="5" ht="28" customHeight="1">'.$hCells.'</row>';

    // Data Rows
    $rNum = 6;
    foreach ($rows as $idx => $r) {
        $isZebra = ($rNum % 2 === 1);
        $sCenter = $isZebra ? 10 : 7;
        $sLeft   = $isZebra ? 9  : 6;
        $sBold   = $isZebra ? 11 : 8;

        $kodeRombel = !empty($r['kode_kelas_mapel']) ? $r['kode_kelas_mapel'] : ('KMP-' . $r['id']);

        $cRow = '';
        $cRow .= '<c r="A'.$rNum.'" s="'.$sCenter.'"><v>'.($idx + 1).'</v></c>';
        $cRow .= '<c r="B'.$rNum.'" s="'.$sCenter.'" t="s"><v>'.$addStr($kodeRombel).'</v></c>';
        $cRow .= '<c r="C'.$rNum.'" s="'.$sCenter.'"><v>'.$r['id'].'</v></c>';
        $cRow .= '<c r="D'.$rNum.'" s="'.$sBold.'" t="s"><v>'.$addStr($r['nama_kelas'] ?? '-').'</v></c>';
        $cRow .= '<c r="E'.$rNum.'" s="'.$sLeft.'" t="s"><v>'.$addStr($r['nama_mata_pelajaran'] ?? '-').'</v></c>';
        $cRow .= '<c r="F'.$rNum.'" s="'.$sLeft.'" t="s"><v>'.$addStr($r['nama_guru'] ?? '-').'</v></c>';
        $cRow .= '<c r="G'.$rNum.'" s="'.$sCenter.'" t="s"><v>'.$addStr($r['nip'] ?? '-').'</v></c>';
        $cRow .= '<c r="H'.$rNum.'" s="'.$sCenter.'" t="s"><v>'.$addStr($r['tahun_pelajaran'] ?? '-').'</v></c>';

        $rowsXml .= '<row r="'.$rNum.'" ht="23" customHeight="1">'.$cRow.'</row>';
        $rNum++;
    }

    // Cols Width
    $colsXml = '<cols>'
        .'<col min="1" max="1" width="7" customWidth="1"/>'
        .'<col min="2" max="2" width="22" customWidth="1"/>'
        .'<col min="3" max="3" width="12" customWidth="1"/>'
        .'<col min="4" max="4" width="28" customWidth="1"/>'
        .'<col min="5" max="5" width="34" customWidth="1"/>'
        .'<col min="6" max="6" width="28" customWidth="1"/>'
        .'<col min="7" max="7" width="22" customWidth="1"/>'
        .'<col min="8" max="8" width="18" customWidth="1"/>'
        .'</cols>';

    return '<?xml version="1.0" encoding="UTF-8"?>'
        .'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        .'<sheetViews><sheetView workbookViewId="0"><pane ySplit="5" topLeftCell="A6" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>'
        .$colsXml
        .'<sheetData>'.$rowsXml.'</sheetData>'
        .'<autoFilter ref="A5:H5"/>'
        .'</worksheet>';
}
}

/**
 * SHEET 4: Panduan Kolom & Nilai Valid Enum
 */
if (!function_exists('ilmuna_build_guidelines_sheet4_xml')) {
function ilmuna_build_guidelines_sheet4_xml(&$strings, &$map, $addStr) {
    $rowsXml = '';

    // Title
    $rowsXml .= '<row r="1" ht="26" customHeight="1"><c r="A1" s="1" t="s"><v>'.$addStr('PANDUAN LENGKAP PENGISIAN &amp; NILAI VALID TEMPLATE IMPORT ASESMEN').'</v></c></row>';
    $rowsXml .= '<row r="2" ht="20" customHeight="1"><c r="A2" s="2" t="s"><v>'.$addStr('Standar Kurikulum Merdeka Ilmuna Learn — Bacalah sebelum melakukan pengisian data.').'</v></c></row>';
    $rowsXml .= '<row r="3" ht="10" customHeight="1"></row>';

    // Header
    $rowsXml .= '<row r="4" ht="28" customHeight="1">'
        .'<c r="A4" s="4" t="s"><v>'.$addStr('NO').'</v></c>'
        .'<c r="B4" s="5" t="s"><v>'.$addStr('NAMA KOLOM').'</v></c>'
        .'<c r="C4" s="4" t="s"><v>'.$addStr('STATUS WAJIB').'</v></c>'
        .'<c r="D4" s="5" t="s"><v>'.$addStr('FORMAT &amp; NILAI VALID').'</v></c>'
        .'<c r="E4" s="5" t="s"><v>'.$addStr('KETERANGAN &amp; CONTOH').'</v></c>'
        .'</row>';

    $guidelines = [
        ['kategori', 'WAJIB', 'sumatif, formatif, atau diagnostik', 'Menentukan jenis asesmen yang dibuat. Huruf kecil atau besar didukung otomatis.'],
        ['judul_asesmen', 'WAJIB', 'Teks bebas (Maks. 255 karakter)', 'Judul paket asesmen. Contoh: "Asesmen Sumatif Bab 1: Merengkuh Martabat Insan"'],
        ['kode_rombel_mapel', 'WAJIB', 'KMP-X atau ID angka (Sheet 3)', 'Dapat diisi lebih dari satu kelas dipisah koma jika ingin dibuat serentak (Contoh: "KMP-1, KMP-2").'],
        ['kode_modul_ajar', 'OPSIONAL', 'MOD-X atau ID angka (Sheet 2)', 'Sangat disarankan diisi. Sistem otomatis menyinkronkan CP, TP, ATP, ITP, dan Guru Pengampu.'],
        ['durasi_menit', 'WAJIB', 'Angka integer (Minimal 5 menit)', 'Durasi pengerjaan ujian. Contoh: 60, 90, 120. Default: 60 menit.'],
        ['tanggal_pelaksanaan', 'WAJIB', 'YYYY-MM-DD atau DD/MM/YYYY', 'Tanggal ujian dilaksanakan. Contoh: "2026-10-25" atau "25/10/2026". Format serial Excel didukung.'],
        ['jam_mulai', 'WAJIB', 'HH:MM (24 Jam)', 'Jam dimulainya ujian. Contoh: "07:30", "08:00", "13:00". Format desimal Excel didukung.'],
        ['jam_selesai', 'OPSIONAL (READ-ONLY)', 'HH:MM (24 Jam)', 'Jika dikosongkan, sistem OTOMATIS menghitung Jam Selesai = Jam Mulai + Durasi Menit secara akumulatif.'],
        ['tipe_khusus', 'OPSIONAL', 'Lihat pilihan per kategori di bawah', 'Sumatif: "Sumatif Lingkup Materi", "Sumatif Tengah Semester", "Sumatif Akhir Semester". Formatif: "Tes Tertulis", "Kuis Lisan", "Observasi Kinerja". Diagnostik: "Kognitif", "Non-Kognitif".'],
        ['kktp', 'OPSIONAL', 'Angka desimal (0 - 100)', 'Otomatis mengikuti KKTP paten resmi dari mata pelajaran terkait. Default: KKTP Mapel.'],
        ['pertemuan_ke', 'OPSIONAL', 'Angka integer (>= 1)', 'Pertemuan KBM ke berapa asesmen ini berlangsung. Default: 1 (atau mengikuti Modul Ajar).'],
        ['status', 'OPSIONAL', 'Aktif, Draft, Ditutup', 'Status asesmen. Default: "Aktif" (langsung dapat diakses sesuai jadwal).'],
        ['petunjuk_pengerjaan', 'OPSIONAL', 'Teks bebas', 'Instruksi pengerjaan soal bagi peserta didik di lembar ujian browser.'],
        ['tujuan_asesmen', 'OPSIONAL', 'Teks bebas', 'Tujuan evaluasi / pemetaan awal (khusus untuk Formatif dan Diagnostik).']
    ];

    $rNum = 5;
    foreach ($guidelines as $idx => $g) {
        $isZebra = ($rNum % 2 === 1);
        $sCenter = $isZebra ? 10 : 7;
        $sLeft   = $isZebra ? 9  : 6;
        $sBold   = $isZebra ? 11 : 8;

        $cRow = '';
        $cRow .= '<c r="A'.$rNum.'" s="'.$sCenter.'"><v>'.($idx + 1).'</v></c>';
        $cRow .= '<c r="B'.$rNum.'" s="'.$sBold.'" t="s"><v>'.$addStr($g[0]).'</v></c>';
        $cRow .= '<c r="C'.$rNum.'" s="'.($g[1] === 'WAJIB' ? 14 : $sCenter).'" t="s"><v>'.$addStr($g[1]).'</v></c>';
        $cRow .= '<c r="D'.$rNum.'" s="'.$sLeft.'" t="s"><v>'.$addStr($g[2]).'</v></c>';
        $cRow .= '<c r="E'.$rNum.'" s="'.$sLeft.'" t="s"><v>'.$addStr($g[3]).'</v></c>';

        $rowsXml .= '<row r="'.$rNum.'" ht="24" customHeight="1">'.$cRow.'</row>';
        $rNum++;
    }

    $colsXml = '<cols>'
        .'<col min="1" max="1" width="7" customWidth="1"/>'
        .'<col min="2" max="2" width="24" customWidth="1"/>'
        .'<col min="3" max="3" width="18" customWidth="1"/>'
        .'<col min="4" max="4" width="38" customWidth="1"/>'
        .'<col min="5" max="5" width="60" customWidth="1"/>'
        .'</cols>';

    return '<?xml version="1.0" encoding="UTF-8"?>'
        .'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        .'<sheetViews><sheetView workbookViewId="0"><pane ySplit="4" topLeftCell="A5" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>'
        .$colsXml
        .'<sheetData>'.$rowsXml.'</sheetData>'
        .'<autoFilter ref="A4:E4"/>'
        .'</worksheet>';
}
}

/**
 * Generator File Template Import Excel Data Asesmen Bertema Lumina Gold
 * 4 Sheet:
 * - Sheet 1: "Template Import Asesmen"
 * - Sheet 2: "Referensi Modul Ajar" (Live DB)
 * - Sheet 3: "Referensi Guru & Mapel" (Live DB)
 * - Sheet 4: "Panduan & Nilai Valid"
 */
if (!function_exists('ilmuna_generate_styled_assessments_template')) {
function ilmuna_generate_styled_assessments_template() {
    $tmp = ilmuna_create_temp_xlsx('tpl_asesmen_');
    if (!$tmp) {
        throw new Exception('Gagal mengalokasikan lokasi berkas sementara untuk template Excel.');
    }

    $zip = new IlmunaZipArchive();
    $openRes = $zip->open($tmp, IlmunaZipArchive::CREATE);
    if ($openRes !== true) {
        throw new Exception('Gagal menginisialisasi arsip template Excel: error kode ' . $openRes);
    }

    // 1. Content Types (4 Worksheets)
    $zip->addFromString('[Content_Types].xml',
        '<?xml version="1.0" encoding="UTF-8"?>'
        .'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
        .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
        .'<Default Extension="xml" ContentType="application/xml"/>'
        .'<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
        .'<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
        .'<Override PartName="/xl/worksheets/sheet2.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
        .'<Override PartName="/xl/worksheets/sheet3.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
        .'<Override PartName="/xl/worksheets/sheet4.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
        .'<Override PartName="/xl/sharedStrings.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sharedStrings+xml"/>'
        .'<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
        .'</Types>');

    // 2. Relationships
    $zip->addFromString('_rels/.rels',
        '<?xml version="1.0" encoding="UTF-8"?>'
        .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
        .'</Relationships>');

    // 3. Workbook (4 Sheets)
    $zip->addFromString('xl/workbook.xml',
        '<?xml version="1.0" encoding="UTF-8"?>'
        .'<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
        .'<sheets>'
        .'<sheet name="Template Import Asesmen" sheetId="1" r:id="rId1"/>'
        .'<sheet name="Referensi Modul Ajar" sheetId="2" r:id="rId2"/>'
        .'<sheet name="Referensi Guru &amp; Mapel" sheetId="3" r:id="rId3"/>'
        .'<sheet name="Panduan &amp; Nilai Valid" sheetId="4" r:id="rId4"/>'
        .'</sheets></workbook>');

    // 4. Workbook Rel (sheet1, sheet2, sheet3, sheet4, sharedStrings, styles)
    $zip->addFromString('xl/_rels/workbook.xml.rels',
        '<?xml version="1.0" encoding="UTF-8"?>'
        .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
        .'<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet2.xml"/>'
        .'<Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet3.xml"/>'
        .'<Relationship Id="rId4" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet4.xml"/>'
        .'<Relationship Id="rId5" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/sharedStrings" Target="sharedStrings.xml"/>'
        .'<Relationship Id="rId6" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
        .'</Relationships>');

    // 5. Stylesheet Tema
    $zip->addFromString('xl/styles.xml', ilmuna_get_xlsx_theme_styles());

    $strings = [];
    $map = [];
    $addStr = function($s) use (&$strings, &$map) {
        $s = (string)$s;
        if (!isset($map[$s])) {
            $map[$s] = count($strings);
            $strings[] = $s;
        }
        return $map[$s];
    };

    // Sheet 1 XML Rows
    $rowsXml = '';

    // Row 1: Judul Template
    $rowsXml .= '<row r="1" ht="26" customHeight="1"><c r="A1" s="1" t="s"><v>'.$addStr('TEMPLATE IMPORT MASTER ASESMEN PEMBELAJARAN — ILMUNA LEARN').'</v></c></row>';

    // Row 2: Panduan
    $petunjuk = 'Petunjuk: Kolom kategori (sumatif/formatif/diagnostik), judul_asesmen, kode_rombel_mapel, durasi_menit, tanggal_pelaksanaan, dan jam_mulai wajib diisi. Jam selesai terhitung otomatis akumulatif. Gunakan kode referensi dari Sheet 2 & 3.';
    $rowsXml .= '<row r="2" ht="20" customHeight="1"><c r="A2" s="2" t="s"><v>'.$addStr($petunjuk).'</v></c></row>';

    // Row 3: Spasi
    $rowsXml .= '<row r="3" ht="8" customHeight="1"></row>';

    // Row 4: Header Kolom (A4:N4)
    $headers = [
        ['col' => 'A', 'title' => 'kategori', 'style' => 4],
        ['col' => 'B', 'title' => 'judul_asesmen', 'style' => 5],
        ['col' => 'C', 'title' => 'kode_rombel_mapel', 'style' => 4],
        ['col' => 'D', 'title' => 'kode_modul_ajar', 'style' => 4],
        ['col' => 'E', 'title' => 'durasi_menit', 'style' => 4],
        ['col' => 'F', 'title' => 'tanggal_pelaksanaan', 'style' => 4],
        ['col' => 'G', 'title' => 'jam_mulai', 'style' => 4],
        ['col' => 'H', 'title' => 'jam_selesai', 'style' => 4],
        ['col' => 'I', 'title' => 'tipe_khusus', 'style' => 4],
        ['col' => 'J', 'title' => 'kktp', 'style' => 4],
        ['col' => 'K', 'title' => 'pertemuan_ke', 'style' => 4],
        ['col' => 'L', 'title' => 'status', 'style' => 4],
        ['col' => 'M', 'title' => 'petunjuk_pengerjaan', 'style' => 5],
        ['col' => 'N', 'title' => 'tujuan_asesmen', 'style' => 5]
    ];
    $hCells = '';
    foreach ($headers as $h) {
        $hCells .= '<c r="'.$h['col'].'4" s="'.$h['style'].'" t="s"><v>'.$addStr($h['title']).'</v></c>';
    }
    $rowsXml .= '<row r="4" ht="28" customHeight="1">'.$hCells.'</row>';

    // Contoh Data Baris 5-7 (Sumatif, Formatif, Diagnostik)
    $examples = [
        [
            'kategori' => 'sumatif',
            'judul' => 'Asesmen Sumatif Bab 1: Merengkuh Martabat Insan',
            'kmp' => 'KMP-1',
            'modul' => 'MOD-1',
            'durasi' => 60,
            'tanggal' => date('Y-m-d', strtotime('+3 days')),
            'mulai' => '07:30',
            'selesai' => '08:30',
            'tipe' => 'Sumatif Lingkup Materi',
            'kktp' => '75.00',
            'pertemuan' => 1,
            'status' => 'Aktif',
            'petunjuk' => 'Kerjakan secara jujur, mandiri, dan teliti melalui aplikasi browser ujian.',
            'tujuan' => ''
        ],
        [
            'kategori' => 'formatif',
            'judul' => 'Kuis Formatif: Kaidah Tajwid Nun Sukun & Tanwin',
            'kmp' => 'KMP-2',
            'modul' => 'MOD-2',
            'durasi' => 45,
            'tanggal' => date('Y-m-d', strtotime('+4 days')),
            'mulai' => '08:00',
            'selesai' => '08:45',
            'tipe' => 'Tes Tertulis',
            'kktp' => '75.00',
            'pertemuan' => 2,
            'status' => 'Aktif',
            'petunjuk' => 'Pilihlah salah satu jawaban yang paling tepat dari pilihan yang tersedia.',
            'tujuan' => 'Mengukur pemahaman kaidah hukum tajwid nun sukun dan tanwin dalam tilawah.'
        ],
        [
            'kategori' => 'diagnostik',
            'judul' => 'Pemetaan Awal Kesiapan Belajar PAI & Budi Pekerti',
            'kmp' => 'KMP-1',
            'modul' => '',
            'durasi' => 30,
            'tanggal' => date('Y-m-d', strtotime('+5 days')),
            'mulai' => '07:30',
            'selesai' => '08:00',
            'tipe' => 'Kognitif',
            'kktp' => '70.00',
            'pertemuan' => 1,
            'status' => 'Aktif',
            'petunjuk' => 'Jawablah sesuai dengan pemahaman dan kondisi Anda saat ini.',
            'tujuan' => 'Mengetahui pemetaan awal kompetensi peserta didik sebelum KBM dimulai.'
        ]
    ];

    $rNum = 5;
    foreach ($examples as $idx => $ex) {
        $isZebra = ($idx % 2 === 1);
        $sCenter = $isZebra ? 10 : 7;
        $sLeft   = $isZebra ? 9  : 6;
        $sBold   = $isZebra ? 11 : 8;

        $cRow = '';
        $cRow .= '<c r="A'.$rNum.'" s="'.$sCenter.'" t="s"><v>'.$addStr($ex['kategori']).'</v></c>';
        $cRow .= '<c r="B'.$rNum.'" s="'.$sBold.'" t="s"><v>'.$addStr($ex['judul']).'</v></c>';
        $cRow .= '<c r="C'.$rNum.'" s="'.$sCenter.'" t="s"><v>'.$addStr($ex['kmp']).'</v></c>';
        $cRow .= '<c r="D'.$rNum.'" s="'.$sCenter.'" t="s"><v>'.$addStr($ex['modul']).'</v></c>';
        $cRow .= '<c r="E'.$rNum.'" s="'.$sCenter.'"><v>'.$ex['durasi'].'</v></c>';
        $cRow .= '<c r="F'.$rNum.'" s="'.$sCenter.'" t="s"><v>'.$addStr($ex['tanggal']).'</v></c>';
        $cRow .= '<c r="G'.$rNum.'" s="'.$sCenter.'" t="s"><v>'.$addStr($ex['mulai']).'</v></c>';
        $cRow .= '<c r="H'.$rNum.'" s="'.$sCenter.'" t="s"><v>'.$addStr($ex['selesai']).'</v></c>';
        $cRow .= '<c r="I'.$rNum.'" s="'.$sCenter.'" t="s"><v>'.$addStr($ex['tipe']).'</v></c>';
        $cRow .= '<c r="J'.$rNum.'" s="'.$sCenter.'"><v>'.$ex['kktp'].'</v></c>';
        $cRow .= '<c r="K'.$rNum.'" s="'.$sCenter.'"><v>'.$ex['pertemuan'].'</v></c>';
        $cRow .= '<c r="L'.$rNum.'" s="'.$sCenter.'" t="s"><v>'.$addStr($ex['status']).'</v></c>';
        $cRow .= '<c r="M'.$rNum.'" s="'.$sLeft.'" t="s"><v>'.$addStr($ex['petunjuk']).'</v></c>';
        $cRow .= '<c r="N'.$rNum.'" s="'.$sLeft.'" t="s"><v>'.$addStr($ex['tujuan']).'</v></c>';

        $rowsXml .= '<row r="'.$rNum.'" ht="23" customHeight="1">'.$cRow.'</row>';
        $rNum++;
    }

    // Column Widths Sheet 1
    $colsXml = '<cols>'
        .'<col min="1" max="1" width="16" customWidth="1"/>'
        .'<col min="2" max="2" width="42" customWidth="1"/>'
        .'<col min="3" max="3" width="22" customWidth="1"/>'
        .'<col min="4" max="4" width="20" customWidth="1"/>'
        .'<col min="5" max="5" width="16" customWidth="1"/>'
        .'<col min="6" max="6" width="22" customWidth="1"/>'
        .'<col min="7" max="7" width="16" customWidth="1"/>'
        .'<col min="8" max="8" width="24" customWidth="1"/>'
        .'<col min="9" max="9" width="26" customWidth="1"/>'
        .'<col min="10" max="10" width="14" customWidth="1"/>'
        .'<col min="11" max="11" width="16" customWidth="1"/>'
        .'<col min="12" max="12" width="16" customWidth="1"/>'
        .'<col min="13" max="13" width="45" customWidth="1"/>'
        .'<col min="14" max="14" width="40" customWidth="1"/>'
        .'</cols>';

    // Sheet 1 XML
    $sheet1Xml = '<?xml version="1.0" encoding="UTF-8"?>'
        .'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        .'<sheetViews><sheetView workbookViewId="0"><pane ySplit="4" topLeftCell="A5" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>'
        .$colsXml
        .'<sheetData>'.$rowsXml.'</sheetData>'
        .'<autoFilter ref="A4:N4"/>'
        .'</worksheet>';
    $zip->addFromString('xl/worksheets/sheet1.xml', $sheet1Xml);

    // Sheet 2: Referensi Modul Ajar
    $sheet2Xml = ilmuna_build_modul_ajar_sheet2_xml($strings, $map, $addStr);
    $zip->addFromString('xl/worksheets/sheet2.xml', $sheet2Xml);

    // Sheet 3: Referensi Guru & Mapel
    $sheet3Xml = ilmuna_build_guru_mapel_sheet3_xml($strings, $map, $addStr);
    $zip->addFromString('xl/worksheets/sheet3.xml', $sheet3Xml);

    // Sheet 4: Panduan & Nilai Valid
    $sheet4Xml = ilmuna_build_guidelines_sheet4_xml($strings, $map, $addStr);
    $zip->addFromString('xl/worksheets/sheet4.xml', $sheet4Xml);

    // Shared Strings
    $ssXml = '<?xml version="1.0" encoding="UTF-8"?>'
        .'<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" count="'.count($strings).'" uniqueCount="'.count($strings).'">';
    foreach ($strings as $s) {
        $ssXml .= '<si><t xml:space="preserve">'.htmlspecialchars($s, ENT_XML1|ENT_QUOTES, 'UTF-8').'</t></si>';
    }
    $ssXml .= '</sst>';
    $zip->addFromString('xl/sharedStrings.xml', $ssXml);

    if (!$zip->close()) {
        $status = method_exists($zip, 'getStatusString') ? $zip->getStatusString() : 'status ' . $zip->status;
        @unlink($tmp);
        throw new Exception('Gagal menyimpan berkas template Excel: ' . $status);
    }

    if (!file_exists($tmp) || filesize($tmp) === 0) {
        @unlink($tmp);
        throw new Exception('Berkas template Excel kosong.');
    }

    return $tmp;
}
}



/* ==============================================================================
 * ROUTER ACTION DISPATCHER
 * ============================================================================== */

$action = $_GET['action'] ?? $_POST['action'] ?? '';

// 1. DOWNLOAD TEMPLATE IMPORT EXCEL (.xlsx)
if ($action === 'download_template') {
    try {
        $tmpFile = ilmuna_generate_styled_assessments_template();
        if (!$tmpFile || !file_exists($tmpFile)) {
            throw new Exception('Gagal membuat berkas template Excel.');
        }

        $filename = 'template-import-asesmen-ilmuna.xlsx';

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
            'data' => ['message' => 'Template error: ' . $e->getMessage()]
        ]);
        exit;
    }
}

// 2. IMPORT DATA ASESMEN DARI EXCEL (.xlsx)
if ($action === 'import_excel') {
    header('Content-Type: application/json; charset=utf-8');

    if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        echo json_encode([
            'success' => false,
            'data' => ['message' => 'Berkas Excel tidak terunggah atau terjadi kesalahan upload.']
        ]);
        exit;
    }

    $file = $_FILES['file'];
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if ($ext !== 'xlsx') {
        echo json_encode([
            'success' => false,
            'data' => ['message' => 'Format berkas harus .xlsx (Excel OpenXML Spreadsheet).']
        ]);
        exit;
    }

    $readRes = ilmuna_read_excel_rows($file['tmp_name']);
    if (isset($readRes['error'])) {
        echo json_encode([
            'success' => false,
            'data' => ['message' => $readRes['error']]
        ]);
        exit;
    }

    $allRows = $readRes['rows'];
    if (empty($allRows)) {
        echo json_encode([
            'success' => false,
            'data' => ['message' => 'Berkas Excel kosong atau tidak memiliki baris data.']
        ]);
        exit;
    }

    // Temukan baris header yang memuat judul_asesmen atau kategori
    $headerRowIdx = null;
    $colMap = [
        'kategori'             => null,
        'judul_asesmen'        => null,
        'kode_rombel_mapel'    => null,
        'kode_modul_ajar'      => null,
        'durasi_menit'         => null,
        'tanggal_pelaksanaan'  => null,
        'jam_mulai'            => null,
        'jam_selesai'          => null,
        'tipe_khusus'          => null,
        'kktp'                 => null,
        'pertemuan_ke'         => null,
        'status'               => null,
        'petunjuk_pengerjaan'  => null,
        'tujuan_asesmen'       => null
    ];

    foreach ($allRows as $rIdx => $row) {
        foreach ($row as $cIdx => $val) {
            $valClean = strtolower(trim(str_replace([' ', '_', '-', '(', ')', '.'], '', (string)$val)));
            if (in_array($valClean, ['kategori', 'jeniskategori', 'kat'], true)) {
                $colMap['kategori'] = $cIdx;
            }
            if (in_array($valClean, ['judulasesmen', 'judul', 'namaasesmen', 'nama'], true)) {
                $colMap['judul_asesmen'] = $cIdx;
            }
            if (in_array($valClean, ['koderombelmapel', 'koderombel', 'idrombel', 'idkelasmata_pelajaran', 'koderelasi', 'idkmp', 'kodekmp', 'rombel'], true)) {
                $colMap['kode_rombel_mapel'] = $cIdx;
            }
            if (in_array($valClean, ['kodemodulajar', 'kodemodul', 'idmodulajar', 'idmodul', 'modul'], true)) {
                $colMap['kode_modul_ajar'] = $cIdx;
            }
            if (in_array($valClean, ['durasimenit', 'durasi', 'waktu', 'menit'], true)) {
                $colMap['durasi_menit'] = $cIdx;
            }
            if (in_array($valClean, ['tanggalpelaksanaan', 'tanggal', 'tgl', 'tglpelaksanaan', 'jadwal'], true)) {
                $colMap['tanggal_pelaksanaan'] = $cIdx;
            }
            if (in_array($valClean, ['jammulai', 'mulai', 'waktumulai', 'jam'], true)) {
                $colMap['jam_mulai'] = $cIdx;
            }
            if (in_array($valClean, ['jamselesai', 'selesai', 'waktuselesai'], true)) {
                $colMap['jam_selesai'] = $cIdx;
            }
            if (in_array($valClean, ['tipekhusus', 'tipe', 'jenissumatif', 'teknikasesmen', 'jenisdiagnostik', 'jenis'], true)) {
                $colMap['tipe_khusus'] = $cIdx;
            }
            if (in_array($valClean, ['kktp', 'kkm', 'skorkktp'], true)) {
                $colMap['kktp'] = $cIdx;
            }
            if (in_array($valClean, ['pertemuanke', 'pertemuan'], true)) {
                $colMap['pertemuan_ke'] = $cIdx;
            }
            if (in_array($valClean, ['status', 'statusasesmen'], true)) {
                $colMap['status'] = $cIdx;
            }
            if (in_array($valClean, ['petunjukpengerjaan', 'petunjuk', 'instruksi'], true)) {
                $colMap['petunjuk_pengerjaan'] = $cIdx;
            }
            if (in_array($valClean, ['tujuanasesmen', 'tujuan', 'tujuanpembelajaran'], true)) {
                $colMap['tujuan_asesmen'] = $cIdx;
            }
        }
        if ($colMap['judul_asesmen'] !== null) {
            $headerRowIdx = $rIdx;
            break;
        }
    }

    if ($headerRowIdx === null || $colMap['judul_asesmen'] === null) {
        echo json_encode([
            'success' => false,
            'data' => ['message' => 'Header kolom "judul_asesmen" tidak ditemukan dalam berkas Excel. Silakan gunakan format template resmi.']
        ]);
        exit;
    }

    // Lookup seluruh relasi KMP aktif di database
    $validKmpMap = [];
    $kmpByKode = [];
    $stmtAllKmp = $pdo->query("
        SELECT kmp.id, kmp.kode_kelas_mapel, kmp.id_kelas, kmp.id_mata_pelajaran, gmp.id_guru, k.nama_kelas, mp.nama_mata_pelajaran
        FROM kelas_mata_pelajaran kmp
        LEFT JOIN kelas k ON kmp.id_kelas = k.id
        LEFT JOIN mata_pelajaran mp ON kmp.id_mata_pelajaran = mp.id
        LEFT JOIN guru_mata_pelajaran gmp ON kmp.id_guru_mata_pelajaran = gmp.id
    ");
    while ($kRow = $stmtAllKmp->fetch(PDO::FETCH_ASSOC)) {
        $validKmpMap[(int)$kRow['id']] = $kRow;
        if (!empty($kRow['kode_kelas_mapel'])) {
            $kmpByKode[strtoupper(trim($kRow['kode_kelas_mapel']))] = (int)$kRow['id'];
        }
    }

    // Lookup seluruh modul ajar di database
    $validModulMap = [];
    $stmtAllModul = $pdo->query("
        SELECT ma.id, ma.judul_modul, ma.pertemuan_ke, ma.id_kelas_mata_pelajaran, ma.id_guru,
               ma.id_cp, ma.id_tp, ma.id_atp, ma.id_itp
        FROM modul_ajar ma
    ");
    while ($mRow = $stmtAllModul->fetch(PDO::FETCH_ASSOC)) {
        $validModulMap[(int)$mRow['id']] = $mRow;
    }

    // Prepared statements untuk asesmen
    $stmtInsertSumatif = $pdo->prepare("
        INSERT INTO asesmen_sumatif (
            id_kelas_mata_pelajaran, id_guru, id_modul_ajar, id_cp, id_tp, id_atp, id_itp,
            pertemuan_ke, judul_asesmen, jenis_sumatif, kktp, durasi_menit, tanggal_pelaksanaan,
            jam_mulai, jam_selesai, petunjuk_pengerjaan, status
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");

    $stmtInsertFormatif = $pdo->prepare("
        INSERT INTO asesmen_formatif (
            id_kelas_mata_pelajaran, id_guru, id_modul_ajar, id_cp, id_tp, id_atp, id_itp,
            pertemuan_ke, judul_asesmen, teknik_asesmen, tujuan_asesmen, durasi_menit, tanggal_pelaksanaan,
            jam_mulai, jam_selesai, petunjuk_pengerjaan, status
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");

    $stmtInsertDiagnostik = $pdo->prepare("
        INSERT INTO asesmen_diagnostik (
            id_kelas_mata_pelajaran, id_guru, id_modul_ajar, id_cp, id_tp, id_atp, id_itp,
            pertemuan_ke, judul_asesmen, jenis_diagnostik, tujuan_asesmen, durasi_menit, tanggal_pelaksanaan,
            jam_mulai, jam_selesai, petunjuk_pengerjaan, status
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");

    // Check duplicate statements
    $stmtCheckSumatif = $pdo->prepare("
        SELECT id FROM asesmen_sumatif 
        WHERE id_kelas_mata_pelajaran = ? AND LOWER(TRIM(judul_asesmen)) = ? AND tanggal_pelaksanaan = ? LIMIT 1
    ");
    $stmtCheckFormatif = $pdo->prepare("
        SELECT id FROM asesmen_formatif 
        WHERE id_kelas_mata_pelajaran = ? AND LOWER(TRIM(judul_asesmen)) = ? AND tanggal_pelaksanaan = ? LIMIT 1
    ");
    $stmtCheckDiag = $pdo->prepare("
        SELECT id FROM asesmen_diagnostik 
        WHERE id_kelas_mata_pelajaran = ? AND LOWER(TRIM(judul_asesmen)) = ? AND tanggal_pelaksanaan = ? LIMIT 1
    ");

    $stat = ['ok' => 0, 'skip' => 0, 'error' => 0];
    $log = [];

    $pdo->beginTransaction();
    try {
        $totalRows = count($allRows);
        for ($i = $headerRowIdx + 1; $i < $totalRows; $i++) {
            $row = $allRows[$i];
            $displayRow = $i + 1;

            $judul = trim((string)($row[$colMap['judul_asesmen']] ?? ''));
            if ($judul === '') {
                // Abaikan jika baris kosong total
                $hasAny = false;
                foreach ($row as $cell) {
                    if (trim((string)$cell) !== '') {
                        $hasAny = true;
                        break;
                    }
                }
                if ($hasAny) {
                    $log[] = [$displayRow, 'error', 'Kolom judul_asesmen tidak boleh kosong.'];
                    $stat['error']++;
                }
                continue;
            }

            // Normalisasi Kategori
            $rawKat = strtolower(trim((string)($row[$colMap['kategori']] ?? 'sumatif')));
            if (strpos($rawKat, 'formatif') !== false || strpos($rawKat, 'kuis') !== false) {
                $kategori = 'formatif';
            } elseif (strpos($rawKat, 'diag') !== false || strpos($rawKat, 'awal') !== false) {
                $kategori = 'diagnostik';
            } else {
                $kategori = 'sumatif';
            }

            // Ekstrak Rombel Mapel (mendukung kode resmi KMA-..., legacy KMP-..., atau angka ID)
            $rawKmp = trim((string)($row[$colMap['kode_rombel_mapel']] ?? ''));
            $kmpTokens = preg_split('/[,;\s]+/', $rawKmp);
            $targetKmpIds = [];
            foreach ($kmpTokens as $tok) {
                $uTok = strtoupper(trim($tok));
                if (isset($kmpByKode[$uTok])) {
                    $targetKmpIds[] = $kmpByKode[$uTok];
                    continue;
                }
                $kid = ilmuna_parse_kmp_id($tok);
                if ($kid !== null && isset($validKmpMap[$kid])) {
                    $targetKmpIds[] = $kid;
                }
            }
            $targetKmpIds = array_values(array_unique(array_filter($targetKmpIds)));

            // Ekstrak Modul Ajar (jika diisi)
            $rawModul = trim((string)($row[$colMap['kode_modul_ajar']] ?? ''));
            $modulId = ilmuna_parse_modul_id($rawModul);
            $modulData = ($modulId && isset($validModulMap[$modulId])) ? $validModulMap[$modulId] : null;

            // Fallback KMP dari Modul Ajar jika kolom KMP kosong
            if (empty($targetKmpIds) && $modulData && !empty($modulData['id_kelas_mata_pelajaran'])) {
                $fallbackKmp = (int)$modulData['id_kelas_mata_pelajaran'];
                if (isset($validKmpMap[$fallbackKmp])) {
                    $targetKmpIds[] = $fallbackKmp;
                }
            }

            if (empty($targetKmpIds)) {
                $log[] = [$displayRow, 'error', 'Kode rombel mapel "' . htmlspecialchars($rawKmp) . '" tidak valid atau tidak ditemukan di database (lihat Sheet 3).'];
                $stat['error']++;
                continue;
            }

            // Durasi Menit
            $rawDurasi = $colMap['durasi_menit'] !== null ? trim((string)($row[$colMap['durasi_menit']] ?? '')) : '';
            $durasiFinal = (is_numeric($rawDurasi) && (int)$rawDurasi >= 5) ? (int)$rawDurasi : 60;

            // Tanggal Pelaksanaan
            $rawTgl = $colMap['tanggal_pelaksanaan'] !== null ? trim((string)($row[$colMap['tanggal_pelaksanaan']] ?? '')) : '';
            $tglPelaksanaan = ilmuna_parse_excel_date($rawTgl);

            // Jam Mulai
            $rawJamMulai = $colMap['jam_mulai'] !== null ? trim((string)($row[$colMap['jam_mulai']] ?? '')) : '';
            $jamMulai = ilmuna_parse_excel_time($rawJamMulai);

            // Jam Selesai: Akumulatif Jam Mulai + Durasi Menit
            $rawJamSelesai = $colMap['jam_selesai'] !== null ? trim((string)($row[$colMap['jam_selesai']] ?? '')) : '';
            $jamSelesai = '';
            if ($rawJamSelesai !== '') {
                $jamSelesai = ilmuna_parse_excel_time($rawJamSelesai);
            }
            if ($jamSelesai === '' || $jamSelesai <= $jamMulai) {
                $jamSelesai = date('H:i:s', strtotime($jamMulai) + ($durasiFinal * 60));
            }

            // KKTP: Otomatis mengikuti KKTP paten dari mata pelajaran terpilih
            $mapelKktpStmt = $pdo->prepare("
                SELECT COALESCE(mp.kktp, 75.00)
                FROM kelas_mata_pelajaran kmp
                JOIN mata_pelajaran mp ON kmp.id_mata_pelajaran = mp.id
                WHERE kmp.id = ?
            ");
            $mapelKktpStmt->execute([$primaryKmp]);
            $resolvedMapelKktp = $mapelKktpStmt->fetchColumn();
            $rawKktp = $colMap['kktp'] !== null ? trim((string)($row[$colMap['kktp']] ?? '')) : '';
            $kktp = ($resolvedMapelKktp !== false && $resolvedMapelKktp !== null) 
                ? (float)$resolvedMapelKktp 
                : ((is_numeric($rawKktp) && (float)$rawKktp >= 0 && (float)$rawKktp <= 100) ? (float)$rawKktp : 75.00);

            // Pertemuan Ke-
            $rawPertemuan = $colMap['pertemuan_ke'] !== null ? trim((string)($row[$colMap['pertemuan_ke']] ?? '')) : '';
            $pertemuan = (is_numeric($rawPertemuan) && (int)$rawPertemuan >= 1) 
                ? (int)$rawPertemuan 
                : (($modulData && !empty($modulData['pertemuan_ke'])) ? (int)$modulData['pertemuan_ke'] : 1);

            // Status
            $rawStatus = $colMap['status'] !== null ? ucfirst(strtolower(trim((string)($row[$colMap['status']] ?? '')))) : '';
            $status = in_array($rawStatus, ['Aktif', 'Draft', 'Ditutup'], true) ? $rawStatus : 'Aktif';

            // Petunjuk Pengerjaan & Tujuan
            $petunjuk = $colMap['petunjuk_pengerjaan'] !== null ? trim((string)($row[$colMap['petunjuk_pengerjaan']] ?? '')) : '';
            if ($petunjuk === '') {
                $petunjuk = 'Kerjakan asesmen dengan jujur, mandiri, dan teliti.';
            }

            $tujuan = $colMap['tujuan_asesmen'] !== null ? trim((string)($row[$colMap['tujuan_asesmen']] ?? '')) : '';
            if ($tujuan === '') {
                $tujuan = ($kategori === 'diagnostik') 
                    ? 'Pemetaan kesiapan awal peserta didik.' 
                    : 'Mengevaluasi proses pemahaman materi pembelajaran.';
            }

            // Tipe Khusus
            $rawTipe = $colMap['tipe_khusus'] !== null ? trim((string)($row[$colMap['tipe_khusus']] ?? '')) : '';

            // Sinkronisasi data CP, TP, ATP, ITP
            $idCp = $modulData ? ($modulData['id_cp'] ? (int)$modulData['id_cp'] : null) : null;
            $idTp = $modulData ? ($modulData['id_tp'] ? (int)$modulData['id_tp'] : null) : null;
            $idAtp = $modulData ? ($modulData['id_atp'] ? (int)$modulData['id_atp'] : null) : null;
            $idItp = $modulData ? ($modulData['id_itp'] ? (int)$modulData['id_itp'] : null) : null;
            $actualModulId = $modulData ? (int)$modulData['id'] : null;

            // Simpan setiap kelas
            $rowInserted = 0;
            $rowSkipped = 0;

            if ($kategori === 'sumatif') {
                $primaryKmp = $targetKmpIds[0];
                $kmpInfo = $validKmpMap[$primaryKmp];
                $currGuruId = !empty($kmpInfo['id_guru']) ? (int)$kmpInfo['id_guru'] : ($modulData['id_guru'] ?? null);

                // Check duplicate pada KMP primer
                $stmtCheckSumatif->execute([$primaryKmp, strtolower($judul), $tglPelaksanaan]);
                $existingId = $stmtCheckSumatif->fetchColumn();

                if (!$existingId) {
                    $chkMulti = $pdo->prepare("
                        SELECT ask.id_asesmen_sumatif 
                        FROM asesmen_sumatif_kelas ask
                        JOIN asesmen_sumatif a ON ask.id_asesmen_sumatif = a.id
                        WHERE ask.id_kelas_mata_pelajaran = ? AND LOWER(TRIM(a.judul_asesmen)) = ? AND a.tanggal_pelaksanaan = ?
                        LIMIT 1
                    ");
                    $chkMulti->execute([$primaryKmp, strtolower($judul), $tglPelaksanaan]);
                    $existingId = $chkMulti->fetchColumn();
                }

                if ($existingId) {
                    $rowSkipped++;
                } else {
                    $validJenis = ['Sumatif Lingkup Materi', 'Sumatif Tengah Semester', 'Sumatif Akhir Semester'];
                    $jenisSumatif = in_array($rawTipe, $validJenis, true) ? $rawTipe : 'Sumatif Lingkup Materi';

                    // Buat 1 master asesmen sumatif
                    $stmtInsertSumatif->execute([
                        $primaryKmp, $currGuruId, $actualModulId, $idCp, $idTp, $idAtp, $idItp,
                        $pertemuan, $judul, $jenisSumatif, $kktp, $durasiFinal, $tglPelaksanaan,
                        $jamMulai, $jamSelesai, $petunjuk, $status
                    ]);
                    $newSumatifId = (int)$pdo->lastInsertId();

                    // Hubungkan seluruh rombel kelas ke tabel pivot asesmen_sumatif_kelas
                    $stmtPivot = $pdo->prepare("
                        INSERT IGNORE INTO asesmen_sumatif_kelas (id_asesmen_sumatif, id_kelas_mata_pelajaran) 
                        VALUES (?, ?)
                    ");
                    foreach ($targetKmpIds as $currKmpId) {
                        $stmtPivot->execute([$newSumatifId, $currKmpId]);
                    }
                    $rowInserted++;
                }

            } else {
                // Formatif & Diagnostik tetap single-class per KMP
                foreach ($targetKmpIds as $currKmpId) {
                    $kmpInfo = $validKmpMap[$currKmpId];
                    $currGuruId = !empty($kmpInfo['id_guru']) ? (int)$kmpInfo['id_guru'] : ($modulData['id_guru'] ?? null);

                    if ($kategori === 'formatif') {
                        $stmtCheckFormatif->execute([$currKmpId, strtolower($judul), $tglPelaksanaan]);
                        if ($stmtCheckFormatif->fetchColumn()) {
                            $rowSkipped++;
                            continue;
                        }

                        $validTeknik = ['Tes Tertulis', 'Kuis Lisan', 'Observasi Kinerja', 'Penilaian Diri', 'Penilaian Antarteman'];
                        $teknik = in_array($rawTipe, $validTeknik, true) ? $rawTipe : 'Tes Tertulis';

                        $stmtInsertFormatif->execute([
                            $currKmpId, $currGuruId, $actualModulId, $idCp, $idTp, $idAtp, $idItp,
                            $pertemuan, $judul, $teknik, $tujuan, $durasiFinal, $tglPelaksanaan,
                            $jamMulai, $jamSelesai, $petunjuk, $status
                        ]);
                        $rowInserted++;

                    } elseif ($kategori === 'diagnostik') {
                        $stmtCheckDiag->execute([$currKmpId, strtolower($judul), $tglPelaksanaan]);
                        if ($stmtCheckDiag->fetchColumn()) {
                            $rowSkipped++;
                            continue;
                        }

                        $jenisDiag = in_array($rawTipe, ['Kognitif', 'Non-Kognitif'], true) ? $rawTipe : 'Kognitif';

                        $stmtInsertDiagnostik->execute([
                            $currKmpId, $currGuruId, $actualModulId, $idCp, $idTp, $idAtp, $idItp,
                            $pertemuan, $judul, $jenisDiag, $tujuan, $durasiFinal, $tglPelaksanaan,
                            $jamMulai, $jamSelesai, $petunjuk, $status
                        ]);
                        $rowInserted++;
                    }
                }
            }

            if ($rowInserted > 0) {
                $stat['ok'] += $rowInserted;
                $msg = ($kategori === 'sumatif' && count($targetKmpIds) > 1)
                    ? "[{$kategori}] \"{$judul}\" berhasil ditambahkan untuk " . count($targetKmpIds) . " rombel kelas (Durasi: {$durasiFinal}m, {$jamMulai}-{$jamSelesai})."
                    : "[{$kategori}] \"{$judul}\" berhasil ditambahkan ({$rowInserted} rombel kelas, Durasi: {$durasiFinal}m, {$jamMulai}-{$jamSelesai}).";
                if ($rowSkipped > 0) {
                    $msg .= " ({$rowSkipped} rombel dilewati karena sudah ada).";
                }
                $log[] = [$displayRow, 'ok', $msg];
            } else {
                $stat['skip']++;
                $log[] = [$displayRow, 'skip', "[{$kategori}] \"{$judul}\" ({$tglPelaksanaan}) sudah ada di seluruh rombel kelas terkait."];
            }
        }

        $pdo->commit();

        echo json_encode([
            'success' => true,
            'data' => [
                'stat'    => $stat,
                'log'     => $log,
                'message' => "Import Asesmen Selesai: {$stat['ok']} paket berhasil dibuat, {$stat['skip']} dilewati, {$stat['error']} gagal."
            ]
        ]);
        exit;

    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        echo json_encode([
            'success' => false,
            'data' => ['message' => 'Gagal memproses data import asesmen: ' . $e->getMessage()]
        ]);
        exit;
    }
}

// Fallback jika ada action yang tidak cocok
if (!empty($action)) {
    echo json_encode(['success' => false, 'data' => ['message' => 'Aksi Excel Asesmen tidak valid.']]);
    exit;
}

