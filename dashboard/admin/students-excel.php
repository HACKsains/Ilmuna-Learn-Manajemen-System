<?php
/**
 * ==============================================================================
 * ILMUNA LEARN — BACKEND EXCEL HANDLER (STUDENTS / SISWA & PESERTA DIDIK)
 * ==============================================================================
 * File Terpisah untuk Maintenance Backend Data Siswa:
 * 1. Download Template Import Excel (.xlsx) bertema Lumina Gold (2 Sheets)
 *    - Sheet 1: Template Format Import Siswa
 *    - Sheet 2: Daftar Referensi Rombel / Kelas Aktif
 * 2. Export Master Data Siswa (.xlsx) bertema Lumina Gold (Navy, Teal, Gold)
 *    - Mendukung filter rombel kelas spesifik atau seluruh rombel
 * 3. Import Data Siswa dari Excel (.xlsx) dengan validasi & anti-duplikasi
 *    - Opsi otomatis generate kode presensi unik per kelas mapel
 * 
 * Standar Output: OpenXML Spreadsheet (.xlsx)
 * Engine: Hybrid IlmunaZipArchive (Native ZipArchive / Pure-PHP Deflate Zero-Failsafe)
 * Dukungan Download: JavaScript Blob Method (Expose Content-Disposition Header)
 * ==============================================================================
 */

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../auth.php';

// Pastikan hanya admin yang dapat mengakses backend excel ini
if (php_sapi_name() !== 'cli') {
    requireRole('admin');
}

$pdo = getDBConnection();

/* ==============================================================================
 * 1. OPENXML XLSX UTILITIES & THEME ENGINE
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
 * 1. Menggunakan Native ZipArchive jika ekstensi PHP zip aktif.
 * 2. Menggunakan Pure-PHP OpenXML ZIP Engine jika ekstensi zip belum aktif / belum reload di Apache.
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

            // Build Pure ZIP jika native tidak aktif atau gagal write
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

                // Local File Header (PK\x03\x04)
                $body .= "\x50\x4b\x03\x04"
                    . pack('v', 20)           // version needed
                    . pack('v', 0)            // general purpose bit flag
                    . pack('v', $method)      // compression method
                    . pack('V', $dostime)     // dos time
                    . pack('V', $crc)         // crc-32
                    . pack('V', $cLen)        // compressed size
                    . pack('V', $uncLen)      // uncompressed size
                    . pack('v', strlen($name))// filename length
                    . pack('v', 0)            // extra field length
                    . $name
                    . $cData;

                // Central Directory Record (PK\x01\x02)
                $cd .= "\x50\x4b\x01\x02"
                    . pack('v', 0x0314)       // version made by (UNIX/MS-DOS)
                    . pack('v', 20)           // version needed
                    . pack('v', 0)            // flags
                    . pack('v', $method)      // compression method
                    . pack('V', $dostime)     // dos time
                    . pack('V', $crc)         // crc-32
                    . pack('V', $cLen)        // compressed size
                    . pack('V', $uncLen)      // uncompressed size
                    . pack('v', strlen($name))// filename length
                    . pack('v', 0)            // extra field length
                    . pack('v', 0)            // file comment length
                    . pack('v', 0)            // disk number start
                    . pack('v', 0)            // internal file attributes
                    . pack('V', 32)           // external file attributes (archive)
                    . pack('V', $offset)      // relative offset of local header
                    . $name;

                $count++;
            }

            $cdOffset = strlen($body);
            $cdLen = strlen($cd);

            // End of Central Directory Record (PK\x05\x06)
            $eocd = "\x50\x4b\x05\x06"
                . pack('v', 0)                // disk number
                . pack('v', 0)                // disk with start of cd
                . pack('v', $count)           // entries on this disk
                . pack('v', $count)           // total entries
                . pack('V', $cdLen)           // cd size
                . pack('V', $cdOffset)        // offset of cd
                . pack('v', 0);               // comment length

            return $body . $cd . $eocd;
        }

        private static function extractEntryFromPureZip($zipData, $targetName) {
            $targetName = str_replace('\\', '/', $targetName);
            $eocdPos = strrpos($zipData, "\x50\x4b\x05\x06");
            if ($eocdPos === false) return false;

            $eocd = substr($zipData, $eocdPos);
            if (strlen($eocd) < 22) return false;

            $count = unpack('v', substr($eocd, 10, 2))[1];
            $cdOffset = unpack('V', substr($eocd, 16, 4))[1];

            $pos = $cdOffset;
            for ($i = 0; $i < $count; $i++) {
                if (substr($zipData, $pos, 4) !== "\x50\x4b\x01\x02") break;
                $method = unpack('v', substr($zipData, $pos + 10, 2))[1];
                $cSize = unpack('V', substr($zipData, $pos + 20, 4))[1];
                $uSize = unpack('V', substr($zipData, $pos + 24, 4))[1];
                $fnLen = unpack('v', substr($zipData, $pos + 28, 2))[1];
                $extraLen = unpack('v', substr($zipData, $pos + 30, 2))[1];
                $commLen = unpack('v', substr($zipData, $pos + 32, 2))[1];
                $localOffset = unpack('V', substr($zipData, $pos + 42, 4))[1];
                $fn = substr($zipData, $pos + 46, $fnLen);

                if (str_replace('\\', '/', $fn) === $targetName) {
                    $lh = substr($zipData, $localOffset, 30);
                    $lhFnLen = unpack('v', substr($lh, 26, 2))[1];
                    $lhExtraLen = unpack('v', substr($lh, 28, 2))[1];
                    $raw = substr($zipData, $localOffset + 30 + $lhFnLen + $lhExtraLen, $cSize);

                    if ($method === 8 && function_exists('gzinflate')) {
                        return @gzinflate($raw);
                    } elseif ($method === 0) {
                        return $raw;
                    }
                    return false;
                }

                $pos += 46 + $fnLen + $extraLen + $commLen;
            }

            return false;
        }
    }
}

/**
 * Parser Excel (.xlsx) OpenXML yang Andal & Komprehensif
 */
if (!function_exists('ilmuna_read_excel_rows')) {
    function ilmuna_read_excel_rows($filePath) {
        if (!file_exists($filePath)) {
            return ['error' => 'Berkas Excel tidak ditemukan di sistem.'];
        }

        $zip = new IlmunaZipArchive();
        if ($zip->open($filePath) !== true) {
            return ['error' => 'Gagal membuka berkas Excel (.xlsx). Pastikan format berkas benar.'];
        }

        // 1. Baca sharedStrings.xml
        $strings = [];
        $ssXml = $zip->getFromName('xl/sharedStrings.xml');
        if ($ssXml) {
            $sDom = @simplexml_load_string($ssXml);
            if ($sDom && isset($sDom->si)) {
                foreach ($sDom->si as $si) {
                    if (isset($si->t)) {
                        $strings[] = (string) $si->t;
                    } elseif (isset($si->r)) {
                        $fullText = '';
                        foreach ($si->r as $r) {
                            $fullText .= (string) ($r->t ?? '');
                        }
                        $strings[] = $fullText;
                    } else {
                        $strings[] = '';
                    }
                }
            }
        }

        // 2. Baca sheet1.xml
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
 * Alokasi File Excel Sementara yang Aman & Terjamin Izin Tulisnya (Zero Pre-lock)
 */
if (!function_exists('ilmuna_create_temp_xlsx')) {
    function ilmuna_create_temp_xlsx($prefix = 'ilmuna_') {
        $randomSuffix = date('Ymd_His') . '_' . bin2hex(random_bytes(6)) . '.xlsx';

        // 1. Prioritaskan folder uploads proyek
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

        // 2. Coba di C:/laragon/tmp
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
 * Stylesheet Tema Lumina Gold Standar OpenXML (Navy #1D3B5E, Teal #0D7A87, Gold #F59E0B)
 * Dilengkapi dengan cellStyleXfs wajib agar kompatibel 100% tanpa pesan korup pada Excel & LibreOffice.
 */
if (!function_exists('ilmuna_get_xlsx_theme_styles')) {
    function ilmuna_get_xlsx_theme_styles() {
        return '<?xml version="1.0" encoding="UTF-8"?>'
            .'<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            .'<fonts count="9">'
            .'<font><sz val="10"/><name val="Segoe UI"/></font>'                             // 0: Default
            .'<font><b/><sz val="15"/><color rgb="FF1D3B5E"/><name val="Segoe UI"/></font>'  // 1: Brand Title (Navy)
            .'<font><b/><sz val="11"/><color rgb="FF0D7A87"/><name val="Segoe UI"/></font>'  // 2: Subtitle (Teal)
            .'<font><i/><sz val="9.5"/><color rgb="FF64748B"/><name val="Segoe UI"/></font>' // 3: Meta Info (Slate)
            .'<font><b/><sz val="10"/><color rgb="FFFFFFFF"/><name val="Segoe UI"/></font>'  // 4: Table Header (White Bold)
            .'<font><sz val="10"/><color rgb="FF1E293B"/><name val="Segoe UI"/></font>'      // 5: Body Regular
            .'<font><b/><sz val="10"/><color rgb="FF1D3B5E"/><name val="Segoe UI"/></font>'  // 6: Body Bold Navy
            .'<font><sz val="9.5"/><color rgb="FF64748B"/><name val="Segoe UI"/></font>'     // 7: Body Muted
            .'<font><b/><sz val="10"/><color rgb="FF0F172A"/><name val="Segoe UI"/></font>'  // 8: Footer Summary
            .'</fonts>'
            .'<fills count="5">'
            .'<fill><patternFill patternType="none"/></fill>'                                  // 0: None
            .'<fill><patternFill patternType="gray125"/></fill>'                               // 1: Gray125
            .'<fill><patternFill patternType="solid"><fgColor rgb="FF1D3B5E"/></patternFill></fill>' // 2: Header Navy
            .'<fill><patternFill patternType="solid"><fgColor rgb="FFF8FAFC"/></patternFill></fill>' // 3: Zebra Soft Slate
            .'<fill><patternFill patternType="solid"><fgColor rgb="FFE2E8F0"/></patternFill></fill>' // 4: Summary Row Slate
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
            .'<cellXfs count="14">'
            .'<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>' // 0: Default
            .'<xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0"><alignment vertical="center"/></xf>' // 1: Title
            .'<xf numFmtId="0" fontId="2" fillId="0" borderId="0" xfId="0"><alignment vertical="center"/></xf>' // 2: Subtitle
            .'<xf numFmtId="0" fontId="3" fillId="0" borderId="0" xfId="0"><alignment vertical="center"/></xf>' // 3: Meta
            .'<xf numFmtId="0" fontId="4" fillId="2" borderId="2" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center" wrapText="1"/></xf>' // 4: Header Center
            .'<xf numFmtId="0" fontId="4" fillId="2" borderId="2" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="left" vertical="center" wrapText="1"/></xf>'   // 5: Header Left
            .'<xf numFmtId="0" fontId="5" fillId="0" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="left" vertical="center"/></xf>'                 // 6: Body Left
            .'<xf numFmtId="0" fontId="5" fillId="0" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>'               // 7: Body Center
            .'<xf numFmtId="0" fontId="6" fillId="0" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="left" vertical="center"/></xf>'                 // 8: Body Bold Left
            .'<xf numFmtId="0" fontId="5" fillId="3" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="left" vertical="center"/></xf>'                 // 9: Zebra Left
            .'<xf numFmtId="0" fontId="5" fillId="3" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>'               // 10: Zebra Center
            .'<xf numFmtId="0" fontId="6" fillId="3" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="left" vertical="center"/></xf>'                 // 11: Zebra Bold Left
            .'<xf numFmtId="0" fontId="8" fillId="4" borderId="3" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="right" vertical="center"/></xf>'                // 12: Summary Label
            .'<xf numFmtId="0" fontId="8" fillId="4" borderId="3" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>'               // 13: Summary Value Center
            .'</cellXfs>'
            .'</styleSheet>';
    }
}

/**
 * ==============================================================================
 * HELPER PROTEKSI KODE ROMBEL / KELAS
 * Format: (kelas)(angkatan)-ID(id)(3 angka acak)
 * Contoh: Kelas X PAI-1, Tahun 2026/2027, ID 1 -> X26-ID1742
 * Melindungi ID database auto-increment agar tidak terpublikasi gamblang.
 * ==============================================================================
 */
if (!function_exists('ilmuna_get_kelas_level')) {
    function ilmuna_get_kelas_level($namaKelas) {
        $clean = trim((string)$namaKelas);
        if (preg_match('/\b(XII)\b/i', $clean)) return 'XII';
        if (preg_match('/\b(XI)\b/i', $clean)) return 'XI';
        if (preg_match('/\b(X)\b/i', $clean)) return 'X';
        if (preg_match('/\b(12)\b/', $clean)) return 'XII';
        if (preg_match('/\b(11)\b/', $clean)) return 'XI';
        if (preg_match('/\b(10)\b/', $clean)) return 'X';
        
        // Ambil alfabet/angka awal jika tidak ada romawi
        if (preg_match('/^([A-Za-z0-9]+)/', $clean, $m)) {
            return strtoupper(substr($m[1], 0, 4));
        }
        return 'KLS';
    }
}

if (!function_exists('ilmuna_get_kelas_angkatan')) {
    function ilmuna_get_kelas_angkatan($tahunPelajaran) {
        $tp = trim((string)$tahunPelajaran);
        if (preg_match('/\b(20\d{2})\b/', $tp, $m)) {
            return substr($m[1], 2); // misal 2026 -> 26
        }
        if (preg_match('/^(\d{2})/', $tp, $m)) {
            return $m[1];
        }
        return date('y');
    }
}

if (!function_exists('ilmuna_generate_masked_kelas_code')) {
    function ilmuna_generate_masked_kelas_code($idKelas, $namaKelas, $tahunPelajaran) {
        $level = ilmuna_get_kelas_level($namaKelas);
        $angkatan = ilmuna_get_kelas_angkatan($tahunPelajaran);
        $rand3 = mt_rand(100, 999);
        return strtoupper($level . $angkatan) . '-ID' . (int)$idKelas . $rand3;
    }
}

if (!function_exists('ilmuna_parse_kelas_code')) {
    function ilmuna_parse_kelas_code($token, $validKelasIds = null) {
        $token = trim((string)$token);
        if ($token === '') return null;

        // 1. Format: (kelas)(angkatan)-ID(id)(3 angka acak) -> misal X26-ID1582
        if (preg_match('/^[A-Za-z0-9]+-ID(\d+?)(\d{3})$/i', $token, $m)) {
            $extractedId = (int)$m[1];
            if ($extractedId > 0 && ($validKelasIds === null || in_array($extractedId, $validKelasIds, true))) {
                return $extractedId;
            }
        }

        // 2. Format: (kelas)(angkatan)-(id)(3 angka acak) -> misal X26-1582
        if (preg_match('/^[A-Za-z0-9]+-(\d{4,})$/i', $token, $m)) {
            $numStr = $m[1];
            $extractedId = (int)substr($numStr, 0, -3);
            if ($extractedId > 0 && ($validKelasIds === null || in_array($extractedId, $validKelasIds, true))) {
                return $extractedId;
            }
        }

        // 3. Format: Direct integer ID jika valid
        if (ctype_digit($token)) {
            $dirId = (int)$token;
            if ($dirId > 0 && $validKelasIds !== null && in_array($dirId, $validKelasIds, true)) {
                return $dirId;
            }
        }

        return null;
    }
}

/**
 * Generator Sheet 2: Daftar Referensi Rombel & Kelas Aktif
 */
function ilmuna_build_students_ref_sheet2_xml(&$strings, &$map, $addStr) {
    $pdo = getDBConnection();
    $sql = "
        SELECT 
            k.id,
            k.nama_kelas,
            k.kode_kelas,
            k.tahun_pelajaran,
            (SELECT COUNT(*) FROM siswa s WHERE s.id_kelas = k.id) AS total_siswa
        FROM kelas k
        ORDER BY k.nama_kelas ASC
    ";
    $stmt = $pdo->query($sql);
    $kelasList = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];

    $rowsXml = '';

    // Row 1: Brand Title
    $rowsXml .= '<row r="1" ht="26" customHeight="1"><c r="A1" s="1" t="s"><v>'.$addStr('DAFTAR REFERENSI ROMBEL & KELAS — ILMUNA LEARN').'</v></c></row>';

    // Row 2: Subtitle / Petunjuk
    $petunjuk = 'Petunjuk: Gunakan nilai pada kolom "KODE ROMBEL" (contoh: X26-ID1482) atau "NAMA ROMBEL / KELAS" untuk dimasukkan ke Sheet 1 kolom "nama_kelas". ID kelas telah diproteksi menggunakan format (kelas)(angkatan)-ID(id)(3 angka acak) dan tersimpan permanen di database.';
    $rowsXml .= '<row r="2" ht="20" customHeight="1"><c r="A2" s="2" t="s"><v>'.$addStr($petunjuk).'</v></c></row>';

    // Row 3: Blank separator
    $rowsXml .= '<row r="3" ht="10" customHeight="1"></row>';

    // Row 4: Table Headers (A to D)
    $headers = [
        ['col' => 'A', 'title' => 'KODE ROMBEL', 'style' => 4],
        ['col' => 'B', 'title' => 'NAMA ROMBEL / KELAS', 'style' => 5],
        ['col' => 'C', 'title' => 'TAHUN PELAJARAN', 'style' => 4],
        ['col' => 'D', 'title' => 'TOTAL SISWA AKTIF', 'style' => 4]
    ];
    $hCells = '';
    foreach ($headers as $h) {
        $hCells .= '<c r="'.$h['col'].'4" s="'.$h['style'].'" t="s"><v>'.$addStr($h['title']).'</v></c>';
    }
    $rowsXml .= '<row r="4" ht="28" customHeight="1">'.$hCells.'</row>';

    // Rows 5+: Data rows
    $rIdx = 5;
    $totalSiswaSum = 0;
    foreach ($kelasList as $k) {
        $isZebra = ($rIdx % 2 === 1);
        $sCenter = $isZebra ? 10 : 7;
        $sLeft   = $isZebra ? 9  : 6;
        $sBold   = $isZebra ? 11 : 8;

        $tSiswa = (int)($k['total_siswa'] ?? 0);
        $totalSiswaSum += $tSiswa;

        // Ambil kode permanen dari database, jika belum ada buat sekali dan simpan permanen
        $kodeKelas = trim((string)($k['kode_kelas'] ?? ''));
        if ($kodeKelas === '') {
            $kodeKelas = ilmuna_generate_masked_kelas_code($k['id'], $k['nama_kelas'], $k['tahun_pelajaran']);
            $updK = $pdo->prepare("UPDATE kelas SET kode_kelas = ? WHERE id = ?");
            $updK->execute([$kodeKelas, $k['id']]);
        }

        $cRow = '';
        $cRow .= '<c r="A'.$rIdx.'" s="'.$sCenter.'" t="s"><v>'.$addStr($kodeKelas).'</v></c>';
        $cRow .= '<c r="B'.$rIdx.'" s="'.$sBold.'" t="s"><v>'.$addStr((string)$k['nama_kelas']).'</v></c>';
        $cRow .= '<c r="C'.$rIdx.'" s="'.$sCenter.'" t="s"><v>'.$addStr((string)$k['tahun_pelajaran']).'</v></c>';
        $cRow .= '<c r="D'.$rIdx.'" s="'.$sCenter.'"><v>'.$tSiswa.'</v></c>';

        $rowsXml .= '<row r="'.$rIdx.'" ht="22" customHeight="1">'.$cRow.'</row>';
        $rIdx++;
    }

    // Summary Footer Row
    $sumRow = '<c r="A'.$rIdx.'" s="12" t="s"><v>'.$addStr('TOTAL:').'</v></c>';
    $sumRow .= '<c r="B'.$rIdx.'" s="12" t="s"><v>'.$addStr(count($kelasList).' Rombel Kelas Terdaftar').'</v></c>';
    $sumRow .= '<c r="C'.$rIdx.'" s="13" t="s"><v>'.$addStr('-').'</v></c>';
    $sumRow .= '<c r="D'.$rIdx.'" s="13"><v>'.$totalSiswaSum.'</v></c>';
    $rowsXml .= '<row r="'.$rIdx.'" ht="25" customHeight="1">'.$sumRow.'</row>';

    $colsXml = '<cols>'
        .'<col min="1" max="1" width="20" customWidth="1"/>'
        .'<col min="2" max="2" width="40" customWidth="1"/>'
        .'<col min="3" max="3" width="22" customWidth="1"/>'
        .'<col min="4" max="4" width="22" customWidth="1"/>'
        .'</cols>';

    return '<?xml version="1.0" encoding="UTF-8"?>'
        .'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        .'<sheetViews><sheetView workbookViewId="0"><pane ySplit="4" topLeftCell="A5" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>'
        .$colsXml
        .'<sheetData>'.$rowsXml.'</sheetData>'
        .'<autoFilter ref="A4:D4"/>'
        .'</worksheet>';
}

/**
 * ==============================================================================
 * GENERATOR TEMPLATE IMPORT EXCEL DATA SISWA (2 SHEETS)
 * ==============================================================================
 */
function ilmuna_generate_styled_students_template() {
    $tmp = ilmuna_create_temp_xlsx('tpl_siswa_');
    if (!$tmp) {
        throw new Exception('Gagal mengalokasikan lokasi berkas sementara untuk template Excel.');
    }

    $zip = new IlmunaZipArchive();
    $openRes = $zip->open($tmp, IlmunaZipArchive::CREATE);
    if ($openRes !== true) {
        throw new Exception('Gagal menginisialisasi berkas template Excel: error kode ' . $openRes);
    }

    // 1. Content Types (2 Worksheets: sheet1.xml & sheet2.xml)
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
        .'<sheet name="Format Import Siswa" sheetId="1" r:id="rId1"/>'
        .'<sheet name="Referensi Rombel &amp; Kelas" sheetId="2" r:id="rId2"/>'
        .'</sheets></workbook>');

    // 4. Workbook Rel (sheet1, sheet2, sharedStrings, styles)
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

    // 6. Data & Shared Strings
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

    $rowsXml = '';

    // Row 1: Brand Title
    $rowsXml .= '<row r="1" ht="26" customHeight="1"><c r="A1" s="1" t="s"><v>'.$addStr('TEMPLATE IMPORT DATA SISWA & PESERTA DIDIK — ILMUNA LEARN').'</v></c></row>';

    // Row 2: Panduan & Subtitle
    $petunjuk = 'Petunjuk: Isi data peserta didik sesuai kolom di bawah ini. Nilai kolom "nama_kelas" harus sesuai dengan nama rombel yang terdaftar pada Sheet 2 "Referensi Rombel & Kelas". Kolom nisn/nis, nama_lengkap wajib diisi.';
    $rowsXml .= '<row r="2" ht="20" customHeight="1"><c r="A2" s="2" t="s"><v>'.$addStr($petunjuk).'</v></c></row>';

    // Row 3: Spasi
    $rowsXml .= '<row r="3" ht="10" customHeight="1"></row>';

    // Row 4: Header Kolom (A sampai G)
    $headers = [
        ['col' => 'A', 'title' => 'nama_kelas',   'style' => 5],
        ['col' => 'B', 'title' => 'nisn',         'style' => 4],
        ['col' => 'C', 'title' => 'nis',          'style' => 4],
        ['col' => 'D', 'title' => 'nama_lengkap', 'style' => 5],
        ['col' => 'E', 'title' => 'no_hp',        'style' => 4],
        ['col' => 'F', 'title' => 'gmail',        'style' => 5],
        ['col' => 'G', 'title' => 'alamat',       'style' => 5]
    ];
    $hCells = '';
    foreach ($headers as $h) {
        $hCells .= '<c r="'.$h['col'].'4" s="'.$h['style'].'" t="s"><v>'.$addStr($h['title']).'</v></c>';
    }
    $rowsXml .= '<row r="4" ht="28" customHeight="1">'.$hCells.'</row>';

    // Cari contoh kelas pertama dari DB jika ada
    $pdo = getDBConnection();
    $sampleKelas = $pdo->query("SELECT nama_kelas FROM kelas ORDER BY id ASC LIMIT 1")->fetchColumn();
    if (!$sampleKelas) $sampleKelas = 'X PAI-1 (Imam Al-Bukhari)';

    // Contoh Data Baris 5-8
    $examples = [
        [$sampleKelas, '0081234590', '26271025', 'Zaidan Ahmad Al-Farisi', '081234567890', 'zaidan.farisi@gmail.com', 'Jl. Pesantren Luhur No. 12, Semarang'],
        [$sampleKelas, '0081234591', '26271026', 'Fatimah Zahra Azzahra',  '081234567891', 'fatimah.zahra@gmail.com',  'Jl. Melati Indah No. 5, Demak'],
        [$sampleKelas, '0081234592', '26271027', 'Muhammad Hanif Rabbani', '081234567892', 'hanif.rabbani@gmail.com', 'Jl. Pahlawan Setia No. 8, Kudus'],
        [$sampleKelas, '0081234593', '26271028', 'Aisyah Nurul Hikmah',    '081234567893', 'aisyah.nurul@gmail.com',   'Jl. Cendrawasih No. 17, Kendal']
    ];

    $rNum = 5;
    foreach ($examples as $idx => $ex) {
        $isZebra = ($idx % 2 === 1);
        $sLeft   = $isZebra ? 9  : 6;
        $sCenter = $isZebra ? 10 : 7;
        $sBold   = $isZebra ? 11 : 8;

        $rCells = '';
        $rCells .= '<c r="A'.$rNum.'" s="'.$sBold.'" t="s"><v>'.$addStr($ex[0]).'</v></c>';
        $rCells .= '<c r="B'.$rNum.'" s="'.$sCenter.'" t="s"><v>'.$addStr($ex[1]).'</v></c>';
        $rCells .= '<c r="C'.$rNum.'" s="'.$sCenter.'" t="s"><v>'.$addStr($ex[2]).'</v></c>';
        $rCells .= '<c r="D'.$rNum.'" s="'.$sBold.'" t="s"><v>'.$addStr($ex[3]).'</v></c>';
        $rCells .= '<c r="E'.$rNum.'" s="'.$sCenter.'" t="s"><v>'.$addStr($ex[4]).'</v></c>';
        $rCells .= '<c r="F'.$rNum.'" s="'.$sLeft.'" t="s"><v>'.$addStr($ex[5]).'</v></c>';
        $rCells .= '<c r="G'.$rNum.'" s="'.$sLeft.'" t="s"><v>'.$addStr($ex[6]).'</v></c>';

        $rowsXml .= '<row r="'.$rNum.'" ht="23" customHeight="1">'.$rCells.'</row>';
        $rNum++;
    }

    // Column Widths
    $colsXml = '<cols>'
        .'<col min="1" max="1" width="34" customWidth="1"/>'
        .'<col min="2" max="2" width="18" customWidth="1"/>'
        .'<col min="3" max="3" width="18" customWidth="1"/>'
        .'<col min="4" max="4" width="36" customWidth="1"/>'
        .'<col min="5" max="5" width="20" customWidth="1"/>'
        .'<col min="6" max="6" width="32" customWidth="1"/>'
        .'<col min="7" max="7" width="42" customWidth="1"/>'
        .'</cols>';

    // Sheet 1 XML
    $sheet1Xml = '<?xml version="1.0" encoding="UTF-8"?>'
        .'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        .'<sheetViews><sheetView workbookViewId="0"><pane ySplit="4" topLeftCell="A5" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>'
        .$colsXml
        .'<sheetData>'.$rowsXml.'</sheetData>'
        .'<autoFilter ref="A4:G4"/>'
        .'</worksheet>';
    $zip->addFromString('xl/worksheets/sheet1.xml', $sheet1Xml);

    // Sheet 2 XML: Referensi Rombel & Kelas
    $sheet2Xml = ilmuna_build_students_ref_sheet2_xml($strings, $map, $addStr);
    $zip->addFromString('xl/worksheets/sheet2.xml', $sheet2Xml);

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

/**
 * ==============================================================================
 * GENERATOR EXPORT MASTER DATA SISWA (.XLSX) BERTEMA LUMINA GOLD
 * ==============================================================================
 */
function ilmuna_export_students_to_xlsx($students, $metaInfo = []) {
    $tmp = ilmuna_create_temp_xlsx('exp_siswa_');
    if (!$tmp) {
        throw new Exception('Gagal mengalokasikan lokasi berkas sementara di folder uploads / sistem temp.');
    }

    $zip = new IlmunaZipArchive();
    $openRes = $zip->open($tmp, IlmunaZipArchive::CREATE);
    if ($openRes !== true) {
        throw new Exception('Gagal menginisialisasi berkas Excel: error kode ' . $openRes);
    }

    // 1. Content Types (2 Worksheets: sheet1.xml & sheet2.xml)
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
        .'<sheet name="Data Siswa" sheetId="1" r:id="rId1"/>'
        .'<sheet name="Referensi Rombel &amp; Kelas" sheetId="2" r:id="rId2"/>'
        .'</sheets></workbook>');

    // 4. Workbook Rel (sheet1, sheet2, sharedStrings, styles)
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

    // 6. Data & Shared Strings
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

    $rowsXml = '';

    // Row 1: Brand Title
    $rowsXml .= '<row r="1" ht="26" customHeight="1"><c r="A1" s="1" t="s"><v>'.$addStr('SISTEM INFORMASI AKADEMIK ILMUNA LEARN').'</v></c></row>';

    // Row 2: Subtitle Laporan
    $kelasTitle = $metaInfo['kelas_title'] ?? 'Semua Rombel Kelas';
    $rowsXml .= '<row r="2" ht="20" customHeight="1"><c r="A2" s="2" t="s"><v>'.$addStr('Laporan Master Data Siswa & Peserta Didik — Rombel: ' . $kelasTitle).'</v></c></row>';

    // Row 3: Metadata Info
    $filterSearch = !empty($metaInfo['search']) ? ' | Pencarian: "' . $metaInfo['search'] . '"' : '';
    $metaText = 'Diekspor pada: ' . date('d F Y, H:i') . ' WIB' . $filterSearch . ' | Total Data: ' . count($students) . ' Siswa';
    $rowsXml .= '<row r="3" ht="18" customHeight="1"><c r="A3" s="3" t="s"><v>'.$addStr($metaText).'</v></c></row>';

    // Row 4: Empty Separator
    $rowsXml .= '<row r="4" ht="10" customHeight="1"></row>';

    // Row 5: Table Headers (A to J)
    $headers = [
        ['col' => 'A', 'title' => 'NO',                    'style' => 4],
        ['col' => 'B', 'title' => 'NISN',                  'style' => 4],
        ['col' => 'C', 'title' => 'NIS',                   'style' => 4],
        ['col' => 'D', 'title' => 'NAMA LENGKAP SISWA',    'style' => 5],
        ['col' => 'E', 'title' => 'ROMBEL / KELAS',        'style' => 5],
        ['col' => 'F', 'title' => 'TAHUN PELAJARAN',       'style' => 4],
        ['col' => 'G', 'title' => 'NO WHATSAPP / TELEPON', 'style' => 4],
        ['col' => 'H', 'title' => 'GMAIL / EMAIL',         'style' => 5],
        ['col' => 'I', 'title' => 'STATUS KODE PRESENSI',  'style' => 4],
        ['col' => 'J', 'title' => 'ALAMAT TINGGAL',        'style' => 5]
    ];

    $hCells = '';
    foreach ($headers as $h) {
        $cRef = $h['col'] . '5';
        $hCells .= '<c r="'.$cRef.'" s="'.$h['style'].'" t="s"><v>'.$addStr($h['title']).'</v></c>';
    }
    $rowsXml .= '<row r="5" ht="28" customHeight="1">'.$hCells.'</row>';

    // Rows 6+: Data Rows
    $rowIdx = 6;
    $totalPresensiAktifSum = 0;

    foreach ($students as $item) {
        $isZebra = ($rowIdx % 2 === 1);
        $sCenter = $isZebra ? 10 : 7;
        $sLeft   = $isZebra ? 9  : 6;
        $sBold   = $isZebra ? 11 : 8;

        $tPresensi = (int)($item['total_presensi_aktif'] ?? 0);
        $tMapel    = (int)($item['total_mapel_kelas'] ?? 0);
        $totalPresensiAktifSum += $tPresensi;

        $presensiStatusText = ($tPresensi > 0)
            ? ($tPresensi . ' / ' . $tMapel . ' Mapel Aktif')
            : 'Belum Digenerate';

        $cRow = '';
        // A: No
        $cRow .= '<c r="A'.$rowIdx.'" s="'.$sCenter.'"><v>'.($rowIdx - 5).'</v></c>';
        // B: NISN (Text type 's' agar leading zero 008... tidak hilang)
        $cRow .= '<c r="B'.$rowIdx.'" s="'.$sCenter.'" t="s"><v>'.$addStr($item['nisn'] ?: '-').'</v></c>';
        // C: NIS (Text type 's' agar leading zero tidak hilang)
        $cRow .= '<c r="C'.$rowIdx.'" s="'.$sCenter.'" t="s"><v>'.$addStr($item['nis'] ?: '-').'</v></c>';
        // D: Nama Lengkap
        $cRow .= '<c r="D'.$rowIdx.'" s="'.$sBold.'" t="s"><v>'.$addStr($item['nama_lengkap'] ?: '-').'</v></c>';
        // E: Rombel / Kelas
        $cRow .= '<c r="E'.$rowIdx.'" s="'.$sLeft.'" t="s"><v>'.$addStr($item['nama_kelas'] ?: '-').'</v></c>';
        // F: Tahun Pelajaran
        $cRow .= '<c r="F'.$rowIdx.'" s="'.$sCenter.'" t="s"><v>'.$addStr($item['tahun_pelajaran'] ?: '-').'</v></c>';
        // G: No WhatsApp
        $cRow .= '<c r="G'.$rowIdx.'" s="'.$sCenter.'" t="s"><v>'.$addStr($item['no_hp'] ?: '-').'</v></c>';
        // H: Gmail / Email
        $cRow .= '<c r="H'.$rowIdx.'" s="'.$sLeft.'" t="s"><v>'.$addStr($item['gmail'] ?: '-').'</v></c>';
        // I: Status Kode Presensi
        $cRow .= '<c r="I'.$rowIdx.'" s="'.$sCenter.'" t="s"><v>'.$addStr($presensiStatusText).'</v></c>';
        // J: Alamat
        $cRow .= '<c r="J'.$rowIdx.'" s="'.$sLeft.'" t="s"><v>'.$addStr($item['alamat'] ?: '-').'</v></c>';

        $rowsXml .= '<row r="'.$rowIdx.'" ht="23" customHeight="1">'.$cRow.'</row>';
        $rowIdx++;
    }

    // Summary Footer Row (10 Kolom A to J)
    $sumRow = '<c r="A'.$rowIdx.'" s="12" t="s"><v>'.$addStr('TOTAL:').'</v></c>';
    $sumRow .= '<c r="B'.$rowIdx.'" s="13"><v>'.count($students).'</v></c>';
    $sumRow .= '<c r="C'.$rowIdx.'" s="12" t="s"><v>'.$addStr('Akumulasi Keseluruhan Data Siswa').'</v></c>';
    $sumRow .= '<c r="D'.$rowIdx.'" s="13" t="s"><v>'.$addStr('-').'</v></c>';
    $sumRow .= '<c r="E'.$rowIdx.'" s="13" t="s"><v>'.$addStr('-').'</v></c>';
    $sumRow .= '<c r="F'.$rowIdx.'" s="13" t="s"><v>'.$addStr('-').'</v></c>';
    $sumRow .= '<c r="G'.$rowIdx.'" s="13" t="s"><v>'.$addStr('-').'</v></c>';
    $sumRow .= '<c r="H'.$rowIdx.'" s="13" t="s"><v>'.$addStr('-').'</v></c>';
    $sumRow .= '<c r="I'.$rowIdx.'" s="13"><v>'.$totalPresensiAktifSum.'</v></c>';
    $sumRow .= '<c r="J'.$rowIdx.'" s="13" t="s"><v>'.$addStr('-').'</v></c>';
    $rowsXml .= '<row r="'.$rowIdx.'" ht="25" customHeight="1">'.$sumRow.'</row>';

    // Column Widths (A to J)
    $colsXml = '<cols>'
        .'<col min="1" max="1" width="7" customWidth="1"/>'
        .'<col min="2" max="2" width="18" customWidth="1"/>'
        .'<col min="3" max="3" width="18" customWidth="1"/>'
        .'<col min="4" max="4" width="36" customWidth="1"/>'
        .'<col min="5" max="5" width="30" customWidth="1"/>'
        .'<col min="6" max="6" width="20" customWidth="1"/>'
        .'<col min="7" max="7" width="20" customWidth="1"/>'
        .'<col min="8" max="8" width="30" customWidth="1"/>'
        .'<col min="9" max="9" width="26" customWidth="1"/>'
        .'<col min="10" max="10" width="40" customWidth="1"/>'
        .'</cols>';

    // Sheet 1 XML
    $sheet1Xml = '<?xml version="1.0" encoding="UTF-8"?>'
        .'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        .'<sheetViews><sheetView workbookViewId="0"><pane ySplit="5" topLeftCell="A6" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>'
        .$colsXml
        .'<sheetData>'.$rowsXml.'</sheetData>'
        .'<autoFilter ref="A5:J5"/>'
        .'</worksheet>';
    $zip->addFromString('xl/worksheets/sheet1.xml', $sheet1Xml);

    // Sheet 2 XML: Referensi Rombel & Kelas
    $sheet2Xml = ilmuna_build_students_ref_sheet2_xml($strings, $map, $addStr);
    $zip->addFromString('xl/worksheets/sheet2.xml', $sheet2Xml);

    // Shared Strings XML
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
        throw new Exception('Gagal menyimpan berkas Excel export: ' . $status);
    }

    if (!file_exists($tmp) || filesize($tmp) === 0) {
        @unlink($tmp);
        throw new Exception('Berkas Excel hasil ekspor siswa kosong.');
    }

    return $tmp;
}

/* ==============================================================================
 * 2. ROUTER & ACTION DISPATCHER
 * ============================================================================== */

$action = $_GET['action'] ?? $_POST['action'] ?? '';

// 2.1 DOWNLOAD TEMPLATE IMPORT EXCEL (.XLSX)
if ($action === 'download_template') {
    try {
        $tmpFile = ilmuna_generate_styled_students_template();
        if (!$tmpFile || !file_exists($tmpFile)) {
            throw new Exception('Gagal membuat berkas template Excel.');
        }

        $filename = 'template-import-siswa-ilmuna.xlsx';

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
        echo json_encode(['success' => false, 'data' => ['message' => 'Gagal membuat template Excel: ' . $e->getMessage()]]);
        exit;
    }
}

// 2.2 EXPORT DATA SISWA KE EXCEL (.XLSX)
if ($action === 'export_excel') {
    try {
        $id_kelas = (int)($_GET['id_kelas'] ?? $_POST['id_kelas'] ?? 0);
        $search   = trim((string)($_GET['search'] ?? $_POST['search'] ?? ''));

        $where = '1=1';
        $params = [];

        if ($id_kelas > 0) {
            $where .= ' AND s.id_kelas = ?';
            $params[] = $id_kelas;
        }

        if ($search !== '') {
            $like = '%' . $search . '%';
            $where .= ' AND (s.nama_lengkap LIKE ? OR s.nisn LIKE ? OR s.nis LIKE ? OR s.gmail LIKE ? OR s.no_hp LIKE ?)';
            $params = array_merge($params, [$like, $like, $like, $like, $like]);
        }

        $sql = "SELECT s.id, s.id_kelas, s.nisn, s.nis, s.nama_lengkap, s.no_hp, s.gmail, s.alamat, s.created_at,
                       k.nama_kelas, k.tahun_pelajaran,
                       (SELECT COUNT(*) FROM presensi p WHERE p.id_siswa = s.id AND p.status = 'aktif') AS total_presensi_aktif,
                       (SELECT COUNT(*) FROM kelas_mata_pelajaran kmp WHERE kmp.id_kelas = s.id_kelas) AS total_mapel_kelas
                FROM siswa s
                JOIN kelas k ON s.id_kelas = k.id
                WHERE {$where}
                ORDER BY k.nama_kelas ASC, s.nama_lengkap ASC";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $students = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Ambil info nama kelas jika diexport spesifik
        $kelasTitle = 'Semua Rombel Kelas';
        if ($id_kelas > 0) {
            $stmtK = $pdo->prepare("SELECT nama_kelas FROM kelas WHERE id = ?");
            $stmtK->execute([$id_kelas]);
            $kName = $stmtK->fetchColumn();
            if ($kName) $kelasTitle = $kName;
        }

        $tmpFile = ilmuna_export_students_to_xlsx($students, [
            'kelas_title' => $kelasTitle,
            'search'      => $search
        ]);

        if (!$tmpFile || !file_exists($tmpFile)) {
            throw new Exception('Gagal membuat berkas Excel hasil ekspor.');
        }

        $cleanTitle = preg_replace('/[^A-Za-z0-9_-]/', '_', $kelasTitle);
        $downloadName = 'Data_Siswa_' . $cleanTitle . '_' . date('Ymd_His') . '.xlsx';

        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $downloadName . '"');
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
        echo json_encode(['success' => false, 'data' => ['message' => 'Gagal export data siswa ke Excel: ' . $e->getMessage()]]);
        exit;
    }
}

// 2.3 IMPORT DATA SISWA DARI EXCEL (.XLSX)
if ($action === 'import_excel') {
    header('Content-Type: application/json; charset=utf-8');

    if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        echo json_encode(['success' => false, 'data' => ['message' => 'Berkas Excel (.xlsx) tidak terunggah atau rusak.']]);
        exit;
    }

    $defaultClassId = (int)($_POST['id_kelas'] ?? 0);
    $autoPresensi   = !empty($_POST['auto_presensi']) && $_POST['auto_presensi'] == '1';

    $tmpUpload = $_FILES['file']['tmp_name'];
    $parsed = ilmuna_read_excel_rows($tmpUpload);

    if (isset($parsed['error'])) {
        echo json_encode(['success' => false, 'data' => ['message' => $parsed['error']]]);
        exit;
    }

    $rows = $parsed['rows'] ?? [];
    if (empty($rows)) {
        echo json_encode(['success' => false, 'data' => ['message' => 'Berkas Excel kosong atau tidak memiliki baris data.']]);
        exit;
    }

    // Cari header row
    $headerRowIdx = -1;
    $headers = [];
    foreach ($rows as $idx => $r) {
        $clean = array_map(function($v){ return strtolower(trim((string)$v)); }, $r);
        if (in_array('nama_lengkap', $clean, true) || in_array('nisn', $clean, true) || in_array('nama siswa', $clean, true)) {
            $headerRowIdx = $idx;
            $headers = $clean;
            break;
        }
    }

    if ($headerRowIdx === -1) {
        echo json_encode([
            'success' => false,
            'data' => ['message' => 'Format template tidak sesuai. Kolom "nama_lengkap" atau "nisn" tidak ditemukan. Gunakan template resmi dari sistem.']
        ]);
        exit;
    }

    // Deteksi index kolom
    $colNama = array_search('nama_lengkap', $headers, true);
    if ($colNama === false) $colNama = array_search('nama siswa', $headers, true);
    if ($colNama === false) $colNama = array_search('nama', $headers, true);

    $colNisn = array_search('nisn', $headers, true);
    $colNis  = array_search('nis', $headers, true);
    if ($colNis === false) $colNis = array_search('nim', $headers, true);

    $colKelas = array_search('nama_kelas', $headers, true);
    if ($colKelas === false) $colKelas = array_search('kelas', $headers, true);
    if ($colKelas === false) $colKelas = array_search('rombel', $headers, true);

    $colHp = array_search('no_hp', $headers, true);
    if ($colHp === false) $colHp = array_search('no hp', $headers, true);
    if ($colHp === false) $colHp = array_search('no whatsapp', $headers, true);
    if ($colHp === false) $colHp = array_search('telepon', $headers, true);

    $colGmail = array_search('gmail', $headers, true);
    if ($colGmail === false) $colGmail = array_search('email', $headers, true);

    $colAlamat = array_search('alamat', $headers, true);

    // Map nama_kelas dan kode_kelas ke id_kelas dari database
    $allKelas = $pdo->query("SELECT id, LOWER(TRIM(nama_kelas)) AS clean_nama, UPPER(TRIM(COALESCE(kode_kelas, ''))) AS clean_kode FROM kelas")->fetchAll(PDO::FETCH_ASSOC);
    $kelasNameToId = [];
    $kelasCodeToId = [];
    foreach ($allKelas as $k) {
        $kelasNameToId[$k['clean_nama']] = (int)$k['id'];
        if ($k['clean_kode'] !== '') {
            $kelasCodeToId[$k['clean_kode']] = (int)$k['id'];
        }
    }

    $stat = ['ok' => 0, 'skip' => 0, 'error' => 0, 'presensi_generated' => 0];
    $log = [];

    $dataRows = array_slice($rows, $headerRowIdx + 1);

    // Siapkan helper generator kode presensi per kelas
    $stmtMapelKelas = $pdo->prepare("SELECT id FROM kelas_mata_pelajaran WHERE id_kelas = ?");
    $stmtInsertPresensi = $pdo->prepare("
        INSERT INTO presensi (id_presensi, id_siswa, id_kelas_mata_pelajaran, tanggal_created, expired_at, status)
        VALUES (?, ?, ?, NOW(), ?, 'aktif')
        ON DUPLICATE KEY UPDATE
            id_presensi = VALUES(id_presensi),
            tanggal_created = NOW(),
            expired_at = VALUES(expired_at),
            status = 'aktif'
    ");

    $expiredAt = date('Y-m-d 23:59:59', strtotime('+6 months')); // 1 Semester

    foreach ($dataRows as $i => $row) {
        $rowNum = $headerRowIdx + 1 + $i + 1;

        $nama = ($colNama !== false) ? trim((string)($row[$colNama] ?? '')) : '';
        $nisn = ($colNisn !== false) ? trim((string)($row[$colNisn] ?? '')) : '';
        $nis  = ($colNis !== false)  ? trim((string)($row[$colNis] ?? ''))  : '';

        if ($nama === '' && $nisn === '' && $nis === '') {
            continue; // Skip baris kosong
        }

        if ($nama === '') {
            $stat['error']++;
            $log[] = [$rowNum, 'error', 'Nama lengkap siswa kosong.'];
            continue;
        }

        if ($nisn === '' && $nis === '') {
            $stat['error']++;
            $log[] = [$rowNum, 'error', "Siswa '{$nama}' dilewati: NISN dan NIS tidak boleh kosong keduanya."];
            continue;
        }

        // Jika NISN kosong, samakan dengan NIS dan sebaliknya
        if ($nisn === '') $nisn = $nis;
        if ($nis === '')  $nis = $nisn;

        // Tentukan id_kelas (Mendukung Kode Kelas Permanen maupun Nama Rombel)
        $targetClassId = $defaultClassId;
        if ($colKelas !== false && !empty($row[$colKelas])) {
            $rawKelasInput = trim((string)$row[$colKelas]);
            $upperInput = strtoupper($rawKelasInput);
            $lowerInput = strtolower($rawKelasInput);

            // 1. Cek langsung ke database kode_kelas
            if (isset($kelasCodeToId[$upperInput])) {
                $targetClassId = $kelasCodeToId[$upperInput];
            } else {
                // 2. Coba parse pola kode terproteksi (misal: X26-ID1482 atau X26-1482)
                $parsedId = ilmuna_parse_kelas_code($rawKelasInput, array_values($kelasNameToId));
                if ($parsedId !== null && $parsedId > 0) {
                    $targetClassId = $parsedId;
                } elseif (isset($kelasNameToId[$lowerInput])) {
                    // 3. Cocokkan dengan nama_kelas exact
                    $targetClassId = $kelasNameToId[$lowerInput];
                } else {
                    // 4. Fuzzy search nama_kelas
                    foreach ($kelasNameToId as $kName => $kId) {
                        if (strpos($kName, $lowerInput) !== false || strpos($lowerInput, $kName) !== false) {
                            $targetClassId = $kId;
                            break;
                        }
                    }
                }
            }
        }

        if ($targetClassId <= 0) {
            $stat['error']++;
            $log[] = [$rowNum, 'error', "Siswa '{$nama}' ({$nisn}): Rombel kelas tidak ditemukan di sistem. Harap pilih kelas tujuan."];
            continue;
        }

        $noHp   = ($colHp !== false) ? trim((string)($row[$colHp] ?? '')) : null;
        $gmail  = ($colGmail !== false) ? trim((string)($row[$colGmail] ?? '')) : null;
        $alamat = ($colAlamat !== false) ? trim((string)($row[$colAlamat] ?? '')) : null;

        // Cek duplikasi NISN / NIS
        $checkStmt = $pdo->prepare("SELECT id FROM siswa WHERE nisn = ? OR nis = ? LIMIT 1");
        $checkStmt->execute([$nisn, $nis]);
        $existingId = $checkStmt->fetchColumn();

        if ($existingId) {
            // Update rombel / info
            $updStmt = $pdo->prepare("
                UPDATE siswa 
                SET id_kelas = ?, nama_lengkap = ?, no_hp = COALESCE(?, no_hp), gmail = COALESCE(?, gmail), alamat = COALESCE(?, alamat)
                WHERE id = ?
            ");
            $updStmt->execute([$targetClassId, $nama, $noHp, $gmail, $alamat, $existingId]);

            $studentId = (int)$existingId;
            $msg = "Siswa '{$nama}' ({$nisn}) sudah ada: Data diperbarui ke rombel ID #{$targetClassId}.";
        } else {
            // Insert baru
            $insStmt = $pdo->prepare("
                INSERT INTO siswa (id_kelas, nisn, nis, nama_lengkap, no_hp, gmail, alamat)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ");
            $insStmt->execute([$targetClassId, $nisn, $nis, $nama, $noHp, $gmail, $alamat]);
            $studentId = (int)$pdo->lastInsertId();
            $msg = "Ditambahkan — {$nama} (NISN: {$nisn})";
        }

        // Auto Generate Kode Presensi untuk setiap mapel kelas jika diaktifkan
        if ($autoPresensi && $studentId > 0) {
            $stmtMapelKelas->execute([$targetClassId]);
            $mapelIds = $stmtMapelKelas->fetchAll(PDO::FETCH_COLUMN);

            $nisClean  = preg_replace('/[^a-zA-Z0-9]/', '', $nis);
            $nisnClean = preg_replace('/[^a-zA-Z0-9]/', '', $nisn);

            foreach ($mapelIds as $kmpId) {
                $rand3 = mt_rand(100, 999);
                $rand4 = mt_rand(1000, 9999);
                $rand5 = mt_rand(10000, 99999);
                $kodePresensi = sprintf("%03d%s%04d%s%05d", $rand3, $nisClean, $rand4, $nisnClean, $rand5);

                $stmtInsertPresensi->execute([$kodePresensi, $studentId, (int)$kmpId, $expiredAt]);
                $stat['presensi_generated']++;
            }
        }

        $stat['ok']++;
        $log[] = [$rowNum, 'ok', $msg];
    }

    echo json_encode([
        'success' => true,
        'data' => [
            'stat'    => $stat,
            'log'     => $log,
            'message' => "Import selesai: {$stat['ok']} berhasil diproses, {$stat['skip']} dilewati, {$stat['error']} gagal." . ($stat['presensi_generated'] > 0 ? " ({$stat['presensi_generated']} kode presensi dibuat otomatis)." : "")
        ]
    ]);
    exit;
}

