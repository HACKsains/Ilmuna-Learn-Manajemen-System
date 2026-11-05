<?php
/**
 * ==============================================================================
 * ILMUNA LEARN — BACKEND EXCEL HANDLER (TEACHERS / GURU & TENAGA PENDIDIK)
 * ==============================================================================
 * File Terpisah untuk Maintenance Backend Data Guru:
 * 1. Download Template Import Excel (.xlsx) bertema Lumina Gold
 * 2. Export Master Data Guru (.xlsx) bertema Lumina Gold (Navy, Teal, Gold)
 * 3. Import Data Guru dari Excel (.xlsx) dengan validasi & anti-duplikasi
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

if (!function_exists('ilmuna_abbreviate_teacher')) {
    function ilmuna_abbreviate_teacher(string $nama): string {
        $namaClean = trim(preg_replace('/\(.*?\)/', '', $nama));
        $namaClean = preg_replace('/,.*$/', '', $namaClean);
        $prefixes = [
            '/\b(ustadzah|ustadz|ustz|ust|k\.h\.|kh\.|kh|kyai|habib|drs\.|drs|dra\.|dra|prof\.|prof|dr\.|dr|ir\.|ir|h\.|hj\.)\b/i',
            '/\b(s\.pd|s\.pd\.i|m\.pd|m\.pd\.i|s\.ag|m\.a|s\.kom|m\.kom|s\.t|m\.t|s\.e|m\.m|s\.sos|s\.si|m\.si|s\.h|lc)\b/i'
        ];
        $namaClean = preg_replace($prefixes, '', $namaClean);
        $namaClean = trim(preg_replace('/[^a-zA-Z0-9\s]/', '', $namaClean));

        $words = preg_split('/\s+/', $namaClean, -1, PREG_SPLIT_NO_EMPTY);
        $stopwords = ['dan', 'bin', 'binti', '&', 'al', 'ibn'];
        $filtered = [];
        foreach ($words as $w) {
            if (!in_array(strtolower($w), $stopwords, true)) {
                $filtered[] = $w;
            }
        }

        if (count($filtered) >= 3) {
            return strtoupper(substr($filtered[0], 0, 1) . substr($filtered[1], 0, 1) . substr($filtered[2], 0, 1));
        } elseif (count($filtered) === 2) {
            $w1 = strtoupper($filtered[0]);
            $w2 = strtoupper($filtered[1]);
            $c2 = preg_replace('/[AEIOU]/', '', substr($w2, 1));
            if (strlen($c2) >= 1) {
                return substr($w1, 0, 1) . substr($w2, 0, 1) . substr($c2, 0, 1);
            }
            return substr($w1, 0, 1) . substr($w2, 0, 2);
        } elseif (count($filtered) === 1) {
            $w = strtoupper($filtered[0]);
            if (strlen($w) <= 3) return $w;
            $vowelsRemoved = $w[0] . preg_replace('/[AEIOU]/', '', substr($w, 1));
            if (strlen($vowelsRemoved) >= 3) {
                return substr($vowelsRemoved, 0, 3);
            }
            return substr($w, 0, 3);
        }

        return 'GRU';
    }
}

if (!function_exists('ilmuna_generate_teacher_code')) {
    function ilmuna_generate_teacher_code(PDO $pdo, string $namaGuru, int $idGuru = 0): string {
        $singkatan = ilmuna_abbreviate_teacher($namaGuru);

        if ($idGuru <= 0) {
            try {
                $st = $pdo->query("SELECT AUTO_INCREMENT FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'guru'");
                $nextId = (int)$st->fetchColumn();
                if ($nextId <= 0) {
                    $st = $pdo->query("SELECT COALESCE(MAX(id), 0) + 1 FROM guru");
                    $nextId = (int)$st->fetchColumn();
                }
                $idGuru = $nextId > 0 ? $nextId : 1;
            } catch (Throwable $e) {
                $idGuru = 1;
            }
        }

        $chk = $pdo->prepare("SELECT id FROM guru WHERE kode_guru = ? AND id != ? LIMIT 1");

        for ($i = 0; $i < 10; $i++) {
            $rand2 = str_pad((string)random_int(10, 99), 2, '0', STR_PAD_LEFT);
            $rand3 = str_pad((string)random_int(100, 999), 3, '0', STR_PAD_LEFT);
            $kode = "{$singkatan}-{$rand2}{$idGuru}{$rand3}";

            $chk->execute([$kode, $idGuru]);
            if (!$chk->fetchColumn()) {
                return $kode;
            }
        }

        return "{$singkatan}-" . random_int(10, 99) . $idGuru . random_int(100, 999);
    }
}

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

        // 1. Baca shared strings
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

        // 2. Baca sheet1.xml (atau sheet pertama)
        $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');
        if (!$sheetXml) {
            $relsXml = $zip->getFromName('xl/_rels/workbook.xml.rels');
            if ($relsXml && preg_match('/Target="([^"]*worksheets\/[^"]+\.xml)"/i', $relsXml, $mRel)) {
                $targetPath = 'xl/' . ltrim($mRel[1], '/');
                $sheetXml = $zip->getFromName($targetPath);
            }
        }
        $zip->close();

        if (!$sheetXml) {
            return ['error' => 'Worksheet data guru tidak ditemukan dalam berkas Excel.'];
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
 * Stylesheet Tema Lumina Gold (Navy #1D3B5E, Teal #0D7A87, Gold #F59E0B)
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
 * HELPER KODE MATA PELAJARAN OTOMATIS: (singkatan)-(2acak)(idmapel)(3acak)
 * ==============================================================================
 */
if (!function_exists('ilmuna_abbreviate_subject')) {
    function ilmuna_abbreviate_subject(string $nama): string {
        $namaClean = trim(preg_replace('/\(.*?\)/', '', $nama));
        $lower = strtolower($namaClean);

        $dict = [
            'pendidikan agama islam' => 'PAI',
            'agama islam'            => 'PAI',
            'pancasila'              => 'PPKN',
            'pendidikan pancasila'   => 'PPKN',
            'ppkn'                   => 'PPKN',
            'pkn'                    => 'PKN',
            'bahasa indonesia'       => 'BIND',
            'b. indonesia'           => 'BIND',
            'bahasa inggris'         => 'BING',
            'b. inggris'             => 'BING',
            'bahasa arab'            => 'BAR',
            'b. arab'                => 'BAR',
            'bahasa jepang'          => 'BJEP',
            'bahasa mandarin'        => 'BMAN',
            'bahasa jerman'          => 'BJER',
            'bahasa perancis'        => 'BPER',
            'bahasa sunda'           => 'BSUN',
            'bahasa jawa'            => 'BJAW',
            'matematika peminatan'   => 'MTKP',
            'matematika'             => 'MTK',
            'al-qur\'an hadits'      => 'QHD',
            'al-qur\'an hadis'       => 'QHD',
            'al-quran hadis'         => 'QHD',
            'qur\'an hadits'         => 'QHD',
            'al-qur\'an'             => 'QUR',
            'tajwid'                 => 'TJW',
            'akidah akhlak'          => 'AAK',
            'akidah'                 => 'AKD',
            'akhlak'                 => 'AKH',
            'fiqih'                  => 'FQH',
            'fikih'                  => 'FQH',
            'ushul fiqih'            => 'UFQ',
            'sejarah kebudayaan islam'=> 'SKI',
            'ski'                    => 'SKI',
            'tarikh'                 => 'TRK',
            'ilmu pengetahuan alam'  => 'IPA',
            'ilmu pengetahuan sosial' => 'IPS',
            'fisika'                 => 'FIS',
            'kimia'                  => 'KIM',
            'biologi'                => 'BIO',
            'ekonomi'                => 'EKO',
            'geografi'               => 'GEO',
            'sosiologi'              => 'SOS',
            'sejarah indonesia'      => 'SJRI',
            'sejarah peminatan'      => 'SJRP',
            'sejarah'                => 'SJR',
            'antropologi'            => 'ANT',
            'informatika'            => 'INF',
            'teknologi informasi'    => 'TIK',
            'seni budaya'            => 'SBD',
            'seni rupa'              => 'SRUP',
            'seni musik'             => 'SMUS',
            'seni tari'              => 'STAR',
            'seni teater'            => 'STEA',
            'pendidikan jasmani'     => 'PJOK',
            'pjok'                   => 'PJOK',
            'penjas'                 => 'PJOK',
            'bimbingan konseling'    => 'BK',
            'bimbingan dan konseling'=> 'BK',
            'prakarya dan kewirausahaan' => 'PKWU',
            'prakarya'               => 'PKR',
            'kewirausahaan'          => 'KWU',
            'muatan lokal'           => 'MULOK',
            'tasawuf'                => 'TSW',
            'ilmu kalam'             => 'KLM',
            'nahwu'                  => 'NHW',
            'shorof'                 => 'SHR',
            'balaghah'               => 'BLG',
        ];

        foreach ($dict as $key => $abbr) {
            if (strpos($lower, $key) !== false) {
                return $abbr;
            }
        }

        $words = preg_split('/[\s\-_,\.\/&]+/', $namaClean, -1, PREG_SPLIT_NO_EMPTY);
        $stopwords = ['dan', 'atau', 'pada', 'di', 'ke', 'dari', 'untuk', 'yang', 'dengan', '&'];
        $filteredWords = [];
        foreach ($words as $w) {
            $wClean = preg_replace('/[^a-zA-Z0-9]/', '', $w);
            if ($wClean !== '' && !in_array(strtolower($wClean), $stopwords, true)) {
                $filteredWords[] = $wClean;
            }
        }

        if (count($filteredWords) >= 2) {
            $abbr = '';
            foreach ($filteredWords as $w) {
                $abbr .= strtoupper(substr($w, 0, 1));
            }
            if (strlen($abbr) === 2 && strlen($filteredWords[0]) >= 2) {
                $abbr = strtoupper(substr($filteredWords[0], 0, 2) . substr($filteredWords[1], 0, 1));
            }
            return substr($abbr, 0, 4);
        } elseif (count($filteredWords) === 1) {
            $word = strtoupper($filteredWords[0]);
            if (strlen($word) <= 4) {
                return $word;
            }
            $vowelsRemoved = $word[0] . preg_replace('/[AEIOU]/', '', substr($word, 1));
            if (strlen($vowelsRemoved) >= 3) {
                return substr($vowelsRemoved, 0, 3);
            }
            return substr($word, 0, 3);
        }

        return 'MPL';
    }
}

if (!function_exists('ilmuna_generate_subject_code')) {
    function ilmuna_generate_subject_code(PDO $pdo, string $namaMapel, int $idMapel = 0): string {
        $singkatan = ilmuna_abbreviate_subject($namaMapel);

        if ($idMapel <= 0) {
            try {
                $st = $pdo->query("SELECT AUTO_INCREMENT FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'mata_pelajaran'");
                $nextId = (int)$st->fetchColumn();
                if ($nextId <= 0) {
                    $st = $pdo->query("SELECT COALESCE(MAX(id), 0) + 1 FROM mata_pelajaran");
                    $nextId = (int)$st->fetchColumn();
                }
                $idMapel = $nextId > 0 ? $nextId : 1;
            } catch (Throwable $e) {
                $idMapel = 1;
            }
        }

        $chk = $pdo->prepare("SELECT id FROM mata_pelajaran WHERE kode_mapel = ? AND id != ? LIMIT 1");

        for ($i = 0; $i < 10; $i++) {
            $rand2 = str_pad((string)random_int(10, 99), 2, '0', STR_PAD_LEFT);
            $rand3 = str_pad((string)random_int(100, 999), 3, '0', STR_PAD_LEFT);
            $kode = "{$singkatan}-{$rand2}{$idMapel}{$rand3}";

            $chk->execute([$kode, $idMapel]);
            if (!$chk->fetchColumn()) {
                return $kode;
            }
        }

        return "{$singkatan}-" . random_int(10, 99) . $idMapel . random_int(100, 999);
    }
}

if (!function_exists('ilmuna_get_mapel_prefix')) {
    function ilmuna_get_mapel_prefix($namaMapel) {
        return ilmuna_abbreviate_subject($namaMapel);
    }
}

if (!function_exists('ilmuna_generate_masked_relasi_code')) {
    function ilmuna_generate_masked_relasi_code($idRelasi, $namaMapel) {
        global $pdo;
        if (!($pdo instanceof PDO)) {
            $pdo = getDBConnection();
        }
        return ilmuna_generate_subject_code($pdo, $namaMapel, (int)$idRelasi);
    }
}

if (!function_exists('ilmuna_parse_guru_mapel_code')) {
    function ilmuna_parse_guru_mapel_code($token, $validGmpMap = null) {
        $token = trim((string)$token);
        if ($token === '') return null;

        global $pdo;
        if (!($pdo instanceof PDO)) {
            $pdo = getDBConnection();
        }

        // 1. Direct database match against mata_pelajaran.kode_mapel
        try {
            $st = $pdo->prepare("SELECT id FROM mata_pelajaran WHERE kode_mapel = ? LIMIT 1");
            $st->execute([$token]);
            $directId = $st->fetchColumn();
            if ($directId && ($validGmpMap === null || isset($validGmpMap[(int)$directId]))) {
                return (int)$directId;
            }
        } catch (Throwable $e) {}

        // 2. Parse format resmi: (singkatan)-(2random)(idmapel)(3random)
        if (preg_match('/^[A-Za-z0-9]+-(\d+)$/', $token, $m)) {
            $numStr = $m[1];
            // Format resmi memiliki minimal 6 digit (2 acak awal + id + 3 acak akhir)
            if (strlen($numStr) >= 6) {
                $extractedId = (int)substr($numStr, 2, -3);
                if ($extractedId > 0 && ($validGmpMap === null || isset($validGmpMap[$extractedId]))) {
                    return $extractedId;
                }
            }
            // Kompatibilitas format lama: (id) . (2 acak akhir)
            if (strlen($numStr) >= 3) {
                $oldExtractedId = (int)substr($numStr, 0, -2);
                if ($oldExtractedId > 0 && ($validGmpMap === null || isset($validGmpMap[$oldExtractedId]))) {
                    return $oldExtractedId;
                }
            }
            // ID langsung numerik setelah strip
            $directNum = (int)$numStr;
            if ($directNum > 0 && ($validGmpMap === null || isset($validGmpMap[$directNum]))) {
                return $directNum;
            }
        }

        // 3. Raw number tanpa prefix strip
        if (preg_match('/^\d+$/', $token)) {
            if (strlen($token) >= 6) {
                $extractedId = (int)substr($token, 2, -3);
                if ($extractedId > 0 && $validGmpMap !== null && isset($validGmpMap[$extractedId])) {
                    return $extractedId;
                }
            }
            $directId = (int)$token;
            if ($validGmpMap === null || isset($validGmpMap[$directId])) {
                return $directId;
            }
        }

        return null;
    }
}

/**
 * Generator Sheet 2: Daftar Referensi Kode & Nama Mata Pelajaran (Table: mata_pelajaran)
 * Digunakan sebagai sheet referensi baik pada Template Import maupun Export Data Guru.
 */
function ilmuna_build_mapel_sheet2_xml(&$strings, &$map, $addStr) {
    $pdo = getDBConnection();
    $stmt = $pdo->query("SELECT id, nama_mata_pelajaran, kode_mapel, semester, tahun_pelajaran FROM mata_pelajaran ORDER BY id ASC");
    $mapels = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];

    $rowsXml = '';

    // Row 1: Brand Title
    $rowsXml .= '<row r="1" ht="26" customHeight="1"><c r="A1" s="1" t="s"><v>'.$addStr('DAFTAR REFERENSI KODE MATA PELAJARAN — ILMUNA LEARN').'</v></c></row>';

    // Row 2: Subtitle / Petunjuk
    $petunjuk = 'Petunjuk: Gunakan kode pada kolom "KODE" (contoh: PAI-481931, QHD-722415) untuk diisikan ke Sheet 1 kolom "kode_mapel". Format resmi: (singkatan)-(2acak)(idmapel)(3acak). Guru dapat mengajar lebih dari 1 mapel (pisahkan dengan koma, contoh: PAI-481931, QHD-722415).';
    $rowsXml .= '<row r="2" ht="20" customHeight="1"><c r="A2" s="2" t="s"><v>'.$addStr($petunjuk).'</v></c></row>';

    // Row 3: Blank separator
    $rowsXml .= '<row r="3" ht="10" customHeight="1"></row>';

    // Row 4: Table Headers (A to D)
    $headers = [
        ['col' => 'A', 'title' => 'KODE', 'style' => 4],
        ['col' => 'B', 'title' => 'NAMA MATA PELAJARAN', 'style' => 5],
        ['col' => 'C', 'title' => 'SEMESTER', 'style' => 4],
        ['col' => 'D', 'title' => 'TAHUN PELAJARAN', 'style' => 4]
    ];
    $hCells = '';
    foreach ($headers as $h) {
        $hCells .= '<c r="'.$h['col'].'4" s="'.$h['style'].'" t="s"><v>'.$addStr($h['title']).'</v></c>';
    }
    $rowsXml .= '<row r="4" ht="28" customHeight="1">'.$hCells.'</row>';

    // Rows 5+: Data rows
    $rIdx = 5;
    foreach ($mapels as $m) {
        $isZebra = ($rIdx % 2 === 1);
        $sL = $isZebra ? 9  : 6;
        $sC = $isZebra ? 10 : 7;
        $sB = $isZebra ? 11 : 8;

        $idMapel    = (int)$m['id'];
        $namaMapel  = (string)($m['nama_mata_pelajaran'] ?? '-');
        $semester   = (string)($m['semester'] ?? '-');
        $tahun      = (string)($m['tahun_pelajaran'] ?? '-');

        // Gunakan kode mapel resmi format (singkatan)-(2acak)(idmapel)(3acak)
        $maskedCode = trim((string)($m['kode_mapel'] ?? ''));
        if ($maskedCode === '' || !preg_match('/^[A-Z0-9]+-[0-9]{2}' . $idMapel . '[0-9]{3}$/', $maskedCode)) {
            $maskedCode = ilmuna_generate_subject_code($pdo, $namaMapel, $idMapel);
            $pdo->prepare("UPDATE mata_pelajaran SET kode_mapel = ? WHERE id = ?")->execute([$maskedCode, $idMapel]);
        }

        $cRow = '';
        $cRow .= '<c r="A'.$rIdx.'" s="'.$sC.'" t="s"><v>'.$addStr($maskedCode).'</v></c>';
        $cRow .= '<c r="B'.$rIdx.'" s="'.$sB.'" t="s"><v>'.$addStr($namaMapel).'</v></c>';
        $cRow .= '<c r="C'.$rIdx.'" s="'.$sC.'" t="s"><v>'.$addStr($semester).'</v></c>';
        $cRow .= '<c r="D'.$rIdx.'" s="'.$sC.'" t="s"><v>'.$addStr($tahun).'</v></c>';

        $rowsXml .= '<row r="'.$rIdx.'" ht="23" customHeight="1">'.$cRow.'</row>';
        $rIdx++;
    }

    // Summary footer row
    $sumRow = '<c r="A'.$rIdx.'" s="12" t="s"><v>'.$addStr('TOTAL:').'</v></c>';
    $sumRow .= '<c r="B'.$rIdx.'" s="13"><v>'.count($mapels).'</v></c>';
    $sumRow .= '<c r="C'.$rIdx.'" s="12" t="s"><v>'.$addStr('Mata Pelajaran Terdaftar Aktif').'</v></c>';
    $sumRow .= '<c r="D'.$rIdx.'" s="13" t="s"><v>'.$addStr('-').'</v></c>';
    $rowsXml .= '<row r="'.$rIdx.'" ht="25" customHeight="1">'.$sumRow.'</row>';

    // Column widths
    $colsXml = '<cols>'
        .'<col min="1" max="1" width="18" customWidth="1"/>'
        .'<col min="2" max="2" width="40" customWidth="1"/>'
        .'<col min="3" max="3" width="18" customWidth="1"/>'
        .'<col min="4" max="4" width="22" customWidth="1"/>'
        .'</cols>';

    $sheetXml = '<?xml version="1.0" encoding="UTF-8"?>'
        .'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        .'<sheetViews><sheetView workbookViewId="0"><pane ySplit="4" topLeftCell="A5" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>'
        .$colsXml
        .'<sheetData>'.$rowsXml.'</sheetData>'
        .'<autoFilter ref="A4:D4"/>'
        .'</worksheet>';

    return $sheetXml;
}

/**
 * Generator File Excel Bertema Lumina Gold (Export Data Guru)
 * 2 Sheet:
 * - Sheet 1: "Data Guru & Tenaga Pendidik" (Termasuk kolom Kode Mapel ID & Nama Mapel)
 * - Sheet 2: "Referensi Mapel" (Daftar seluruh mata pelajaran aktif dari database)
 */
function ilmuna_generate_styled_teachers_export($rows, $metaInfo = []) {
    $tmp = ilmuna_create_temp_xlsx('exp_guru_');
    if (!$tmp) {
        throw new Exception('Gagal membuat lokasi berkas sementara di folder uploads / sistem temp.');
    }

    $zip = new IlmunaZipArchive();
    $openRes = $zip->open($tmp, IlmunaZipArchive::CREATE);
    if ($openRes !== true) {
        throw new Exception('Gagal menginisialisasi arsip Excel: error kode ' . $openRes);
    }

    // 1. Content Types (Mendukung 2 Worksheet: sheet1.xml & sheet2.xml)
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
        .'<sheet name="Data Guru &amp; Tenaga Pendidik" sheetId="1" r:id="rId1"/>'
        .'<sheet name="Referensi Mapel" sheetId="2" r:id="rId2"/>'
        .'</sheets></workbook>');

    // 4. Workbook Rel (Mendefinisikan sheet1 rId1 dan sheet2 rId2)
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
    $rowsXml .= '<row r="2" ht="20" customHeight="1"><c r="A2" s="2" t="s"><v>'.$addStr('Pangkalan Data Master Guru & Tenaga Pendidik').'</v></c></row>';

    // Row 3: Metadata Info
    $filterStatus = !empty($metaInfo['filter_status']) ? $metaInfo['filter_status'] : 'Semua Status';
    $filterGender = !empty($metaInfo['filter_gender']) ? ($metaInfo['filter_gender'] === 'L' ? 'Laki-laki (L)' : 'Perempuan (P)') : 'Semua Gender';
    $filterSearch = !empty($metaInfo['filter_search']) ? ' | Pencarian: "' . $metaInfo['filter_search'] . '"' : '';
    $metaText = 'Diekspor pada: ' . date('d F Y, H:i') . ' WIB | Status: ' . $filterStatus . ' | Gender: ' . $filterGender . $filterSearch . ' | Total Data: ' . count($rows) . ' Guru';
    $rowsXml .= '<row r="3" ht="18" customHeight="1"><c r="A3" s="3" t="s"><v>'.$addStr($metaText).'</v></c></row>';

    // Row 4: Empty Separator
    $rowsXml .= '<row r="4" ht="10" customHeight="1"></row>';

    // Row 5: Table Headers (A to N: 14 columns)
    $headers = [
        ['col' => 'A', 'title' => 'NO', 'style' => 4],
        ['col' => 'B', 'title' => 'KODE GURU', 'style' => 4],
        ['col' => 'C', 'title' => 'NAMA LENGKAP & GELAR', 'style' => 5],
        ['col' => 'D', 'title' => 'NIP', 'style' => 4],
        ['col' => 'E', 'title' => 'NUPTK', 'style' => 4],
        ['col' => 'F', 'title' => 'L/P', 'style' => 4],
        ['col' => 'G', 'title' => 'STATUS KEPEGAWAIAN', 'style' => 4],
        ['col' => 'H', 'title' => 'NO WHATSAPP / HP', 'style' => 4],
        ['col' => 'I', 'title' => 'EMAIL', 'style' => 5],
        ['col' => 'J', 'title' => 'KODE MAPEL', 'style' => 4],
        ['col' => 'K', 'title' => 'MATA PELAJARAN YANG DIAMPU', 'style' => 5],
        ['col' => 'L', 'title' => 'TOTAL ROMBEL', 'style' => 4],
        ['col' => 'M', 'title' => 'AKUN PORTAL', 'style' => 4],
        ['col' => 'N', 'title' => 'ALAMAT TINGGAL', 'style' => 5]
    ];

    $hCells = '';
    foreach ($headers as $h) {
        $cRef = $h['col'] . '5';
        $hCells .= '<c r="'.$cRef.'" s="'.$h['style'].'" t="s"><v>'.$addStr($h['title']).'</v></c>';
    }
    $rowsXml .= '<row r="5" ht="28" customHeight="1">'.$hCells.'</row>';

    // Preload relasi mapel kode permanen dari database
    $pdo = getDBConnection();
    $mapelCodeLookup = [];
    $stmtMpLookup = $pdo->query("SELECT id, nama_mata_pelajaran, kode_mapel FROM mata_pelajaran");
    if ($stmtMpLookup) {
        while ($mpRow = $stmtMpLookup->fetch(PDO::FETCH_ASSOC)) {
            $mid = (int)$mpRow['id'];
            $code = trim((string)($mpRow['kode_mapel'] ?? ''));
            if ($code === '' || !preg_match('/^[A-Z0-9]+-[0-9]{2}' . $mid . '[0-9]{3}$/', $code)) {
                $code = ilmuna_generate_subject_code($pdo, $mpRow['nama_mata_pelajaran'], $mid);
                $pdo->prepare("UPDATE mata_pelajaran SET kode_mapel = ? WHERE id = ?")->execute([$code, $mid]);
            }
            $mapelCodeLookup[$mid] = $code;
        }
    }

    // Rows 6+: Data Rows
    $rowIdx = 6;
    $totalRombelSum = 0;

    foreach ($rows as $item) {
        $isZebra = ($rowIdx % 2 === 1);
        $sLeft   = $isZebra ? 9  : 6;
        $sCenter = $isZebra ? 10 : 7;
        $sBold   = $isZebra ? 11 : 8;

        $totalRombel = (int)($item['total_mengajar'] ?? 0);
        $totalRombelSum += $totalRombel;

        $jkText = ($item['jenis_kelamin'] === 'P') ? 'P' : 'L';
        $statusText = $item['status_kepegawaian'] ?: 'Tetap';

        // Format kode mapel terlindungi dari database
        $kodeMapelArr = [];
        if (!empty($item['raw_guru_mapel'])) {
            $pairs = explode('||', $item['raw_guru_mapel']);
            foreach ($pairs as $pair) {
                $parts = explode('::', $pair, 2);
                $mid = (int)($parts[0] ?? 0);
                if ($mid > 0) {
                    $kodeMapelArr[] = $mapelCodeLookup[$mid] ?? ('MPL-' . $mid);
                }
            }
        } elseif (!empty($item['kode_mapel_diampu'])) {
            $kodeMapelArr = array_map('trim', explode(',', $item['kode_mapel_diampu']));
        }
        $kodeMapelText = !empty($kodeMapelArr) ? implode(', ', $kodeMapelArr) : '-';

        $mapelText  = !empty($item['mapel_diampu']) ? $item['mapel_diampu'] : '-';
        $akunText   = !empty($item['akun_portal']) ? $item['akun_portal'] : 'Belum Ada';

        $cRow = '';
        // A: No
        $cRow .= '<c r="A'.$rowIdx.'" s="'.$sCenter.'"><v>'.($rowIdx - 5).'</v></c>';
        // B: Kode Guru
        $kodeGuruVal = !empty($item['kode_guru']) ? $item['kode_guru'] : ('GRU-' . ($item['id'] ?? '-'));
        $cRow .= '<c r="B'.$rowIdx.'" s="'.$sCenter.'" t="s"><v>'.$addStr($kodeGuruVal).'</v></c>';
        // C: Nama Guru
        $cRow .= '<c r="C'.$rowIdx.'" s="'.$sBold.'" t="s"><v>'.$addStr($item['nama_guru'] ?? '-').'</v></c>';
        // D: NIP
        $cRow .= '<c r="D'.$rowIdx.'" s="'.$sCenter.'" t="s"><v>'.$addStr($item['nip'] ?: '-').'</v></c>';
        // E: NUPTK
        $cRow .= '<c r="E'.$rowIdx.'" s="'.$sCenter.'" t="s"><v>'.$addStr($item['nuptk'] ?: '-').'</v></c>';
        // F: Jenis Kelamin
        $cRow .= '<c r="F'.$rowIdx.'" s="'.$sCenter.'" t="s"><v>'.$addStr($jkText).'</v></c>';
        // G: Status Kepegawaian
        $cRow .= '<c r="G'.$rowIdx.'" s="'.$sCenter.'" t="s"><v>'.$addStr($statusText).'</v></c>';
        // H: No HP
        $cRow .= '<c r="H'.$rowIdx.'" s="'.$sCenter.'" t="s"><v>'.$addStr($item['no_hp'] ?: '-').'</v></c>';
        // I: Email
        $cRow .= '<c r="I'.$rowIdx.'" s="'.$sLeft.'" t="s"><v>'.$addStr($item['email'] ?: '-').'</v></c>';
        // J: Kode Mapel (ID)
        $cRow .= '<c r="J'.$rowIdx.'" s="'.$sCenter.'" t="s"><v>'.$addStr($kodeMapelText).'</v></c>';
        // K: Mapel Diampu
        $cRow .= '<c r="K'.$rowIdx.'" s="'.$sLeft.'" t="s"><v>'.$addStr($mapelText).'</v></c>';
        // L: Total Rombel Mengajar
        $cRow .= '<c r="L'.$rowIdx.'" s="'.$sCenter.'"><v>'.$totalRombel.'</v></c>';
        // M: Akun Portal
        $cRow .= '<c r="M'.$rowIdx.'" s="'.$sCenter.'" t="s"><v>'.$addStr($akunText).'</v></c>';
        // N: Alamat
        $cRow .= '<c r="N'.$rowIdx.'" s="'.$sLeft.'" t="s"><v>'.$addStr($item['alamat'] ?: '-').'</v></c>';

        $rowsXml .= '<row r="'.$rowIdx.'" ht="23" customHeight="1">'.$cRow.'</row>';
        $rowIdx++;
    }

    // Summary Footer Row (14 columns A to N)
    $sumRow = '<c r="A'.$rowIdx.'" s="12" t="s"><v>'.$addStr('TOTAL:').'</v></c>';
    $sumRow .= '<c r="B'.$rowIdx.'" s="13"><v>'.count($rows).'</v></c>';
    $sumRow .= '<c r="C'.$rowIdx.'" s="12" t="s"><v>'.$addStr('Akumulasi Keseluruhan Data Guru').'</v></c>';
    $sumRow .= '<c r="D'.$rowIdx.'" s="13" t="s"><v>'.$addStr('-').'</v></c>';
    $sumRow .= '<c r="E'.$rowIdx.'" s="13" t="s"><v>'.$addStr('-').'</v></c>';
    $sumRow .= '<c r="F'.$rowIdx.'" s="13" t="s"><v>'.$addStr('-').'</v></c>';
    $sumRow .= '<c r="G'.$rowIdx.'" s="13" t="s"><v>'.$addStr('-').'</v></c>';
    $sumRow .= '<c r="H'.$rowIdx.'" s="13" t="s"><v>'.$addStr('-').'</v></c>';
    $sumRow .= '<c r="I'.$rowIdx.'" s="13" t="s"><v>'.$addStr('-').'</v></c>';
    $sumRow .= '<c r="J'.$rowIdx.'" s="13" t="s"><v>'.$addStr('-').'</v></c>';
    $sumRow .= '<c r="K'.$rowIdx.'" s="13" t="s"><v>'.$addStr('-').'</v></c>';
    $sumRow .= '<c r="L'.$rowIdx.'" s="13"><v>'.$totalRombelSum.'</v></c>';
    $sumRow .= '<c r="M'.$rowIdx.'" s="13" t="s"><v>'.$addStr('-').'</v></c>';
    $sumRow .= '<c r="N'.$rowIdx.'" s="13" t="s"><v>'.$addStr('Lengkap').'</v></c>';
    $rowsXml .= '<row r="'.$rowIdx.'" ht="25" customHeight="1">'.$sumRow.'</row>';

    // Column Widths (A to N)
    $colsXml = '<cols>'
        .'<col min="1" max="1" width="7" customWidth="1"/>'
        .'<col min="2" max="2" width="12" customWidth="1"/>'
        .'<col min="3" max="3" width="36" customWidth="1"/>'
        .'<col min="4" max="4" width="22" customWidth="1"/>'
        .'<col min="5" max="5" width="20" customWidth="1"/>'
        .'<col min="6" max="6" width="10" customWidth="1"/>'
        .'<col min="7" max="7" width="22" customWidth="1"/>'
        .'<col min="8" max="8" width="20" customWidth="1"/>'
        .'<col min="9" max="9" width="28" customWidth="1"/>'
        .'<col min="10" max="10" width="18" customWidth="1"/>'
        .'<col min="11" max="11" width="36" customWidth="1"/>'
        .'<col min="12" max="12" width="16" customWidth="1"/>'
        .'<col min="13" max="13" width="20" customWidth="1"/>'
        .'<col min="14" max="14" width="32" customWidth="1"/>'
        .'</cols>';

    // Worksheet 1 XML
    $sheet1Xml = '<?xml version="1.0" encoding="UTF-8"?>'
        .'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        .'<sheetViews><sheetView workbookViewId="0"><pane ySplit="5" topLeftCell="A6" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>'
        .$colsXml
        .'<sheetData>'.$rowsXml.'</sheetData>'
        .'<autoFilter ref="A5:N5"/>'
        .'</worksheet>';
    $zip->addFromString('xl/worksheets/sheet1.xml', $sheet1Xml);

    // Worksheet 2 XML: Referensi Kode Mata Pelajaran
    $sheet2Xml = ilmuna_build_mapel_sheet2_xml($strings, $map, $addStr);
    $zip->addFromString('xl/worksheets/sheet2.xml', $sheet2Xml);

    // Simpan Shared Strings (setelah sheet1 & sheet2 selesai agar seluruh string terdaftar)
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
        throw new Exception('Gagal menyimpan berkas Excel export guru: ' . $status);
    }

    if (!file_exists($tmp) || filesize($tmp) === 0) {
        @unlink($tmp);
        throw new Exception('Berkas Excel hasil export guru kosong.');
    }

    return $tmp;
}

/**
 * Generator File Template Import Excel Data Guru Bertema Lumina Gold
 * 2 Sheet:
 * - Sheet 1: "Template Import Guru" (Header kode_mapel mendukung multi ID, misal: 1, 2)
 * - Sheet 2: "Referensi Mapel" (Daftar seluruh mata pelajaran aktif dari database)
 */
function ilmuna_generate_styled_teachers_template() {
    $tmp = ilmuna_create_temp_xlsx('tpl_guru_');
    if (!$tmp) {
        throw new Exception('Gagal mengalokasikan lokasi berkas sementara untuk template Excel.');
    }

    $zip = new IlmunaZipArchive();
    $openRes = $zip->open($tmp, IlmunaZipArchive::CREATE);
    if ($openRes !== true) {
        throw new Exception('Gagal menginisialisasi arsip template Excel: error kode ' . $openRes);
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

    // 2. Relationships
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
        .'<sheet name="Template Import Guru" sheetId="1" r:id="rId1"/>'
        .'<sheet name="Referensi Mapel" sheetId="2" r:id="rId2"/>'
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

    // 6. Data Strings
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

    // Row 1: Title
    $rowsXml .= '<row r="1" ht="24" customHeight="1"><c r="A1" s="1" t="s"><v>'.$addStr('TEMPLATE IMPORT DATA GURU &amp; TENAGA PENDIDIK — ILMUNA LEARN').'</v></c></row>';

    // Row 2: Petunjuk
    $petunjuk = 'Petunjuk: Kolom nama_guru wajib diisi. NIP bersifat unik. Gender: L / P. Kolom kode_mapel diisi kode dari Sheet 2 "Referensi Mapel" sesuai format resmi (singkatan)-(2acak)(idmapel)(3acak) (bisa lebih dari 1 kode dipisah koma, contoh: PAI-481931, QHD-722415).';
    $rowsXml .= '<row r="2" ht="18" customHeight="1"><c r="A2" s="2" t="s"><v>'.$addStr($petunjuk).'</v></c></row>';

    // Row 3: Blank separator
    $rowsXml .= '<row r="3" ht="10" customHeight="1"></row>';

    // Row 4: Header Columns (A to I: 9 Columns)
    $headers = [
        ['col' => 'A', 'title' => 'nama_guru', 'style' => 5],
        ['col' => 'B', 'title' => 'nip', 'style' => 4],
        ['col' => 'C', 'title' => 'nuptk', 'style' => 4],
        ['col' => 'D', 'title' => 'jenis_kelamin', 'style' => 4],
        ['col' => 'E', 'title' => 'status_kepegawaian', 'style' => 4],
        ['col' => 'F', 'title' => 'no_hp', 'style' => 4],
        ['col' => 'G', 'title' => 'email', 'style' => 5],
        ['col' => 'H', 'title' => 'kode_mapel', 'style' => 4],
        ['col' => 'I', 'title' => 'alamat', 'style' => 5]
    ];
    $hCells = '';
    foreach ($headers as $h) {
        $hCells .= '<c r="'.$h['col'].'4" s="'.$h['style'].'" t="s"><v>'.$addStr($h['title']).'</v></c>';
    }
    $rowsXml .= '<row r="4" ht="28" customHeight="1">'.$hCells.'</row>';

    // Sample Data Rows (Rows 5, 6, 7)
    $examples = [
        ['nama_guru' => 'Ustadz Rahmat Hidayat, M.Pd.I', 'nip' => '198804202014021003', 'nuptk' => '92837492817263', 'jk' => 'L', 'status' => 'PNS', 'nohp' => '081234567890', 'email' => 'rahmat@ilmuna.my.id', 'kode_mapel' => 'PAI-481931, QHD-722415', 'alamat' => 'Jl. Pesantren No. 12, Sleman'],
        ['nama_guru' => 'Ustadzah Siti Fatimah, S.Ag', 'nip' => '199003152016032001', 'nuptk' => '81927364510293', 'jk' => 'P', 'status' => 'PPPK', 'nohp' => '081298765432', 'email' => 'fatimah@ilmuna.my.id', 'kode_mapel' => 'AAK-353819', 'alamat' => 'Jl. Melati No. 45, Bantul'],
        ['nama_guru' => 'Drs. KH. Ahmad Zaki Mubarak, M.A', 'nip' => '197508121999031002', 'nuptk' => '71625340918273', 'jk' => 'L', 'status' => 'Tetap Yayasan', 'nohp' => '081345678901', 'email' => 'zaki@ilmuna.my.id', 'kode_mapel' => 'FQH-614290, SKI-195822', 'alamat' => 'Kompleks Islamic Center']
    ];

    $rIdx = 5;
    foreach ($examples as $ex) {
        $isZebra = ($rIdx % 2 === 1);
        $sL = $isZebra ? 9  : 6;
        $sC = $isZebra ? 10 : 7;
        $sB = $isZebra ? 11 : 8;

        $cRow = '';
        $cRow .= '<c r="A'.$rIdx.'" s="'.$sB.'" t="s"><v>'.$addStr($ex['nama_guru']).'</v></c>';
        $cRow .= '<c r="B'.$rIdx.'" s="'.$sC.'" t="s"><v>'.$addStr($ex['nip']).'</v></c>';
        $cRow .= '<c r="C'.$rIdx.'" s="'.$sC.'" t="s"><v>'.$addStr($ex['nuptk']).'</v></c>';
        $cRow .= '<c r="D'.$rIdx.'" s="'.$sC.'" t="s"><v>'.$addStr($ex['jk']).'</v></c>';
        $cRow .= '<c r="E'.$rIdx.'" s="'.$sC.'" t="s"><v>'.$addStr($ex['status']).'</v></c>';
        $cRow .= '<c r="F'.$rIdx.'" s="'.$sC.'" t="s"><v>'.$addStr($ex['nohp']).'</v></c>';
        $cRow .= '<c r="G'.$rIdx.'" s="'.$sL.'" t="s"><v>'.$addStr($ex['email']).'</v></c>';
        $cRow .= '<c r="H'.$rIdx.'" s="'.$sC.'" t="s"><v>'.$addStr($ex['kode_mapel']).'</v></c>';
        $cRow .= '<c r="I'.$rIdx.'" s="'.$sL.'" t="s"><v>'.$addStr($ex['alamat']).'</v></c>';

        $rowsXml .= '<row r="'.$rIdx.'" ht="23" customHeight="1">'.$cRow.'</row>';
        $rIdx++;
    }

    // Column Widths
    $colsXml = '<cols>'
        .'<col min="1" max="1" width="36" customWidth="1"/>'
        .'<col min="2" max="2" width="22" customWidth="1"/>'
        .'<col min="3" max="3" width="20" customWidth="1"/>'
        .'<col min="4" max="4" width="14" customWidth="1"/>'
        .'<col min="5" max="5" width="22" customWidth="1"/>'
        .'<col min="6" max="6" width="20" customWidth="1"/>'
        .'<col min="7" max="7" width="28" customWidth="1"/>'
        .'<col min="8" max="8" width="22" customWidth="1"/>'
        .'<col min="9" max="9" width="34" customWidth="1"/>'
        .'</cols>';

    // Worksheet 1 XML
    $sheet1Xml = '<?xml version="1.0" encoding="UTF-8"?>'
        .'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        .'<sheetViews><sheetView workbookViewId="0"><pane ySplit="4" topLeftCell="A5" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>'
        .$colsXml
        .'<sheetData>'.$rowsXml.'</sheetData>'
        .'<autoFilter ref="A4:I4"/>'
        .'</worksheet>';
    $zip->addFromString('xl/worksheets/sheet1.xml', $sheet1Xml);

    // Worksheet 2 XML: Referensi Kode Mata Pelajaran
    $sheet2Xml = ilmuna_build_mapel_sheet2_xml($strings, $map, $addStr);
    $zip->addFromString('xl/worksheets/sheet2.xml', $sheet2Xml);

    // Simpan Shared Strings (setelah sheet1 & sheet2 selesai agar seluruh string terdaftar)
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


/* ==============================================================================
 * ROUTER ACTION DISPATCHER
 * ============================================================================== */

$action = $_GET['action'] ?? $_POST['action'] ?? '';

// 1. DOWNLOAD TEMPLATE IMPORT EXCEL (.xlsx)
if ($action === 'download_template') {
    try {
        $tmpFile = ilmuna_generate_styled_teachers_template();
        if (!$tmpFile || !file_exists($tmpFile)) {
            throw new Exception('Gagal membuat berkas template Excel data guru.');
        }

        $filename = 'template-data-guru-ilmuna.xlsx';

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

// 2. EXPORT MASTER DATA GURU (.xlsx)
if ($action === 'export_excel') {
    try {
        $search   = trim($_GET['search'] ?? '');
        $f_status = trim($_GET['f_status'] ?? '');
        $f_gender = trim($_GET['f_gender'] ?? '');
        $idsParam = trim($_GET['ids'] ?? '');

        $where = '1=1';
        $params = [];

        if ($idsParam !== '') {
            $idList = array_filter(array_map('intval', explode(',', $idsParam)));
            if (!empty($idList)) {
                $inClause = implode(',', $idList);
                $where .= " AND g.id IN ($inClause)";
            }
        } else {
            if ($search !== '') {
                $where .= ' AND (g.nama_guru LIKE ? OR g.nip LIKE ? OR g.nuptk LIKE ? OR g.email LIKE ?)';
                $like = '%' . $search . '%';
                $params = array_merge($params, [$like, $like, $like, $like]);
            }
            if ($f_status !== '') {
                $where .= ' AND g.status_kepegawaian = ?';
                $params[] = $f_status;
            }
            if ($f_gender !== '') {
                $where .= ' AND g.jenis_kelamin = ?';
                $params[] = $f_gender;
            }
        }

        $sql = "SELECT g.id, g.kode_guru, g.nama_guru, g.nip, g.nuptk, g.jenis_kelamin, g.no_hp, g.email, g.status_kepegawaian, g.alamat,
                    (SELECT COUNT(*) FROM kelas_mata_pelajaran kmp JOIN guru_mata_pelajaran gmp ON kmp.id_guru_mata_pelajaran = gmp.id WHERE gmp.id_guru = g.id) AS total_mengajar,
                    (SELECT GROUP_CONCAT(CONCAT(gmp.id_mata_pelajaran, '::', mp.nama_mata_pelajaran) ORDER BY gmp.id_mata_pelajaran ASC SEPARATOR '||')
                     FROM guru_mata_pelajaran gmp 
                     JOIN mata_pelajaran mp ON gmp.id_mata_pelajaran = mp.id
                     WHERE gmp.id_guru = g.id) AS raw_guru_mapel,
                    (SELECT GROUP_CONCAT(gmp.id_mata_pelajaran ORDER BY gmp.id_mata_pelajaran ASC SEPARATOR ', ')
                     FROM guru_mata_pelajaran gmp 
                     WHERE gmp.id_guru = g.id) AS kode_mapel_diampu,
                    (SELECT GROUP_CONCAT(mp.nama_mata_pelajaran SEPARATOR ', ')
                     FROM guru_mata_pelajaran gmp 
                     JOIN mata_pelajaran mp ON gmp.id_mata_pelajaran = mp.id 
                     WHERE gmp.id_guru = g.id) AS mapel_diampu,
                    u.username AS akun_portal
                FROM guru g
                LEFT JOIN users u ON g.id_user = u.id
                WHERE {$where}
                ORDER BY g.nama_guru ASC";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $metaInfo = [
            'filter_status' => $f_status,
            'filter_gender' => $f_gender,
            'filter_search' => $search
        ];

        $tmpFile = ilmuna_generate_styled_teachers_export($rows, $metaInfo);
        if (!$tmpFile || !file_exists($tmpFile)) {
            throw new Exception('Gagal membuat berkas Excel sementara data guru.');
        }

        $filename = 'data-guru-ilmuna-' . date('Ymd-His') . '.xlsx';

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

// 3. IMPORT DATA GURU DARI EXCEL (.xlsx)
if ($action === 'import_excel') {
    header('Content-Type: application/json; charset=utf-8');

    if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        echo json_encode(['success' => false, 'data' => ['message' => 'File Excel (.xlsx) tidak terunggah atau rusak.']]);
        exit;
    }

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

    // Cari baris header
    $headerRowIdx = -1;
    $headers = [];
    foreach ($rows as $idx => $r) {
        $clean = array_map(function($v){ return strtolower(trim((string)$v)); }, $r);
        if (in_array('nama_guru', $clean, true) || in_array('nama lengkap & gelar', $clean, true) || in_array('nama', $clean, true)) {
            $headerRowIdx = $idx;
            $headers = $clean;
            break;
        }
    }

    if ($headerRowIdx === -1) {
        echo json_encode([
            'success' => false,
            'data' => ['message' => 'Format template tidak sesuai. Kolom "nama_guru" tidak ditemukan pada berkas Excel.']
        ]);
        exit;
    }

    // Deteksi index kolom
    $colNama = array_search('nama_guru', $headers, true);
    if ($colNama === false) $colNama = array_search('nama lengkap & gelar', $headers, true);
    if ($colNama === false) $colNama = array_search('nama', $headers, true);

    $colNip = array_search('nip', $headers, true);
    $colNuptk = array_search('nuptk', $headers, true);
    $colJk = array_search('jenis_kelamin', $headers, true);
    if ($colJk === false) $colJk = array_search('l/p', $headers, true);
    if ($colJk === false) $colJk = array_search('gender', $headers, true);

    $colStatus = array_search('status_kepegawaian', $headers, true);
    if ($colStatus === false) $colStatus = array_search('status', $headers, true);

    $colNoHp = array_search('no_hp', $headers, true);
    if ($colNoHp === false) $colNoHp = array_search('no whatsapp / hp', $headers, true);
    if ($colNoHp === false) $colNoHp = array_search('telepon', $headers, true);

    $colEmail = array_search('email', $headers, true);
    $colAlamat = array_search('alamat', $headers, true);
    if ($colAlamat === false) $colAlamat = array_search('alamat tinggal', $headers, true);

    // Deteksi kolom kode_mapel / id_mapel
    $colKodeMapel = array_search('kode_mapel', $headers, true);
    if ($colKodeMapel === false) $colKodeMapel = array_search('id_mapel', $headers, true);
    if ($colKodeMapel === false) $colKodeMapel = array_search('kode mapel', $headers, true);
    if ($colKodeMapel === false) $colKodeMapel = array_search('id mapel', $headers, true);
    if ($colKodeMapel === false) $colKodeMapel = array_search('kode mapel (id)', $headers, true);
    if ($colKodeMapel === false) $colKodeMapel = array_search('mata pelajaran yang diampu', $headers, true);
    if ($colKodeMapel === false) $colKodeMapel = array_search('mata pelajaran', $headers, true);
    if ($colKodeMapel === false) $colKodeMapel = array_search('mapel', $headers, true);

    // Pre-fetch seluruh mata pelajaran aktif dari DB untuk validasi cepat O(1)
    $allMapels = $pdo->query("SELECT id, LOWER(TRIM(nama_mata_pelajaran)) AS clean_nama FROM mata_pelajaran")->fetchAll(PDO::FETCH_ASSOC);
    $validMapelIds = [];
    $mapelNameToId = [];
    foreach ($allMapels as $m) {
        $mId = (int)$m['id'];
        $validMapelIds[$mId] = true;
        if (!empty($m['clean_nama'])) {
            $mapelNameToId[$m['clean_nama']] = $mId;
        }
    }

    $stat = ['ok' => 0, 'skip' => 0, 'error' => 0];
    $log = [];

    $dataRows = array_slice($rows, $headerRowIdx + 1);
    foreach ($dataRows as $i => $row) {
        $rowNum = $headerRowIdx + 1 + $i + 1;

        $nama = trim((string)($row[$colNama] ?? ''));
        if ($nama === '' || stripos($nama, 'total:') !== false) {
            continue;
        }

        $nip    = ($colNip !== false) ? trim((string)($row[$colNip] ?? '')) : '';
        $nuptk  = ($colNuptk !== false) ? trim((string)($row[$colNuptk] ?? '')) : '';
        
        $jkRaw  = ($colJk !== false) ? strtoupper(trim((string)($row[$colJk] ?? 'L'))) : 'L';
        $jk     = in_array($jkRaw, ['P', 'PEREMPUAN', 'WANITA']) ? 'P' : 'L';

        $status = ($colStatus !== false) ? trim((string)($row[$colStatus] ?? 'Tetap')) : 'Tetap';
        if ($status === '' || $status === '-') $status = 'Tetap';

        $noHp   = ($colNoHp !== false) ? trim((string)($row[$colNoHp] ?? '')) : '';
        $email  = ($colEmail !== false) ? trim((string)($row[$colEmail] ?? '')) : '';
        $alamat = ($colAlamat !== false) ? trim((string)($row[$colAlamat] ?? '')) : '';

        // Ekstraksi kode mapel (mendukung format terlindungi PREFIX-(ID)(2-digit) seperti SKI-583, multi-kode pisah koma, atau ID numerik)
        $rawMapel = ($colKodeMapel !== false) ? trim((string)($row[$colKodeMapel] ?? '')) : '';
        $assignedMapelIds = [];
        if ($rawMapel !== '' && $rawMapel !== '-') {
            $tokens = preg_split('/[,;\r\n|\/]+/', $rawMapel);
            foreach ($tokens as $token) {
                $token = trim($token);
                if ($token === '') continue;

                $parsedId = ilmuna_parse_guru_mapel_code($token, $validMapelIds);
                if ($parsedId !== null && isset($validMapelIds[$parsedId])) {
                    $assignedMapelIds[$parsedId] = $parsedId;
                    continue;
                }

                $cleanToken = strtolower($token);
                if (isset($mapelNameToId[$cleanToken])) {
                    $tid = $mapelNameToId[$cleanToken];
                    $assignedMapelIds[$tid] = $tid;
                }
            }
        }

        // Validasi Duplikasi NIP
        if ($nip !== '' && $nip !== '-') {
            $checkStmt = $pdo->prepare("SELECT id FROM guru WHERE nip = ? LIMIT 1");
            $checkStmt->execute([$nip]);
            $existing = $checkStmt->fetch(PDO::FETCH_ASSOC);
            if ($existing) {
                $existingId = (int)$existing['id'];

                // Jika ada kode mapel pada import, hubungkan ke guru yang sudah ada tanpa error
                if (!empty($assignedMapelIds)) {
                    $insMapelStmt = $pdo->prepare("INSERT IGNORE INTO guru_mata_pelajaran (id_guru, id_mata_pelajaran) VALUES (?, ?)");
                    $newAssigned = 0;
                    foreach ($assignedMapelIds as $mid) {
                        $insMapelStmt->execute([$existingId, $mid]);
                        if ($insMapelStmt->rowCount() > 0) {
                            $newAssigned++;
                        }
                    }
                    $stat['ok']++;
                    $log[] = [$rowNum, 'ok', "Guru '{$nama}' (ID: {$existingId}) sudah terdaftar: ditambahkan +{$newAssigned} mapel diampu."];
                    continue;
                }

                $stat['skip']++;
                $log[] = [$rowNum, 'skip', "Guru '{$nama}' dilewati: NIP {$nip} sudah terdaftar."];
                continue;
            }
        }

        try {
            $ins = $pdo->prepare("
                INSERT INTO guru (nama_guru, nip, nuptk, jenis_kelamin, email, no_hp, status_kepegawaian, alamat)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $ins->execute([
                $nama,
                ($nip !== '' && $nip !== '-') ? $nip : null,
                ($nuptk !== '' && $nuptk !== '-') ? $nuptk : null,
                $jk,
                ($email !== '' && $email !== '-') ? $email : null,
                ($noHp !== '' && $noHp !== '-') ? $noHp : null,
                $status,
                ($alamat !== '' && $alamat !== '-') ? $alamat : null
            ]);
            $newId = (int)$pdo->lastInsertId();

            // Generate dan simpan kode_guru permanen format: (singkatan)-(2acak)(id)(3acak)
            try {
                $newKodeGuru = ilmuna_generate_teacher_code($pdo, $nama, $newId);
                $pdo->prepare("UPDATE guru SET kode_guru = ? WHERE id = ?")->execute([$newKodeGuru, $newId]);
            } catch (Exception $eKg) {}

            // Hubungkan guru baru ke mata pelajaran di guru_mata_pelajaran
            if (!empty($assignedMapelIds)) {
                $insMapelStmt = $pdo->prepare("INSERT IGNORE INTO guru_mata_pelajaran (id_guru, id_mata_pelajaran) VALUES (?, ?)");
                foreach ($assignedMapelIds as $mid) {
                    $insMapelStmt->execute([$newId, $mid]);
                }
            }

            $mapelCount = count($assignedMapelIds);
            $mapelNote = ($mapelCount > 0) ? " + {$mapelCount} Mapel diampu" : "";

            $stat['ok']++;
            $log[] = [$rowNum, 'ok', "Berhasil: {$nama} [{$status}]{$mapelNote}"];
        } catch (Throwable $e) {
            $stat['error']++;
            $log[] = [$rowNum, 'error', "Baris {$rowNum} gagal: " . $e->getMessage()];
        }
    }

    echo json_encode([
        'success' => true,
        'data' => [
            'stat' => $stat,
            'log' => $log,
            'message' => "Import selesai: {$stat['ok']} berhasil diimport, {$stat['skip']} dilewati, {$stat['error']} gagal."
        ]
    ]);
    exit;
}

if (!empty($action)) {
    // Fallback jika tidak ada action yang cocok
    http_response_code(400);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'success' => false,
        'data' => ['message' => 'Aksi Excel tidak dikenali atau parameter tidak lengkap.']
    ]);
    exit;
}
