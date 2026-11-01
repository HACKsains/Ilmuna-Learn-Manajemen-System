<?php
/**
 * ==============================================================================
 * ILMUNA LEARN — INTELLIGENT ESSAY RUBRIC EVALUATOR ENGINE
 * ==============================================================================
 * Engine Penilaian Esai Semantik Multi-Tier Otomatis Sesuai Rubrik
 * Mampu mengevaluasi jawaban siswa dengan parafrase, sinonim, variasi kalimat,
 * serta menghitung capaian poin sesuai jenjang skor rubrik (Maksimal, Sedang, Minimal, Nol).
 * ==============================================================================
 */

class IlmunaEssayEvaluator {

    /**
     * Stopwords Bahasa Indonesia yang diabaikan saat ekstraksi konsep
     */
    private static $stopWords = [
        'yang', 'dan', 'di', 'ke', 'dari', 'pada', 'untuk', 'dengan', 'atau', 'ini', 'itu',
        'adalah', 'yaitu', 'yakni', 'seperti', 'sebagai', 'secara', 'mampu', 'dapat', 'bisa',
        'akan', 'telah', 'sudah', 'sedang', 'agar', 'supaya', 'dalam', 'oleh', 'karena', 'sebab',
        'sehingga', 'maka', 'namun', 'tetapi', 'melainkan', 'serta', 'juga', 'pun', 'hanya',
        'siswa', 'peserta', 'didik', 'antara', 'lain', 'tersebut', 'suatu', 'ada', 'bila',
        'jika', 'apabila', 'tentang', 'hal', 'terhadap', 'atas', 'bawah', 'bagi', 'kepadanya',
        'menurut', 'berdasarkan', 'kami', 'kita', 'saya', 'dia', 'mereka', 'kepada', 'rasa',
        'buat', 'membuat', 'menjadi', 'diri', 'dan/atau', 'dsb', 'dll', 'yaitu/yakni'
    ];

    /**
     * Klaster Tesaurus & Sinonim Semantik (Fokus Pendidikan, Agama Islam & Konsep Umum)
     */
    private static $thesaurus = [
        // Konsep Ketuhanan & Religi
        'allah' => ['tuhan', 'khaliq', 'pencipta', 'ilahi', 'rabb', 'gusti', 'dzat', 'mahakuasa'],
        'tuhan' => ['allah', 'khaliq', 'pencipta', 'ilahi', 'rabb', 'gusti'],
        'taqarrub' => ['dekat', 'mendekat', 'mendekatkan', 'intim', 'hubungan', 'menghampiri', 'ibadah', 'taqorrub'],
        'dekat' => ['taqarrub', 'mendekat', 'akrab', 'intim', 'rapat', 'menghampiri', 'erat'],
        'zikir' => ['ingat', 'mengingat', 'dzikir', 'wirid', 'menyebut', 'tasbih', 'tahmid', 'tahlil'],
        'ingat' => ['zikir', 'dzikir', 'mengingat', 'teringat', 'sadar'],
        'ibadah' => ['syariat', 'kebaktian', 'sembahyang', 'shalat', 'salat', 'amal', 'ketaatan'],
        'akidah' => ['iman', 'keyakinan', 'kepercayaan', 'tauhid', 'prinsip'],
        'iman' => ['akidah', 'keyakinan', 'kepercayaan', 'tauhid'],

        // Ketenangan & Emosi Kejiwaan
        'gelisah' => ['resah', 'cemas', 'khawatir', 'takut', 'waswas', 'risau', 'panik', 'stres', 'stress', 'bingung', 'gundah', 'galau'],
        'resah' => ['gelisah', 'cemas', 'khawatir', 'risau', 'stres', 'stress', 'gundah'],
        'cemas' => ['gelisah', 'resah', 'khawatir', 'takut', 'waswas', 'stres', 'stress', 'risau'],
        'stres' => ['gelisah', 'tertekan', 'cemas', 'resah', 'pusing', 'beban', 'depresi', 'stress'],
        'stress' => ['gelisah', 'tertekan', 'cemas', 'resah', 'stres'],
        'tenang' => ['damai', 'tenteram', 'tentram', 'ayem', 'adem', 'sejuk', 'hening', 'rileks', 'teduh', 'senang', 'nyaman', 'tenteramkan'],
        'tenteram' => ['tenang', 'damai', 'tentram', 'ayem', 'sejuk', 'teduh', 'aman', 'ketenteraman'],
        'tentram' => ['tenang', 'damai', 'tenteram', 'ayem', 'sejuk', 'ketentraman'],
        'damai' => ['tenang', 'tenteram', 'tentram', 'rukun', 'harmonis', 'aman', 'kedamaian'],
        'hati' => ['jiwa', 'kalbu', 'batin', 'dada', 'perasaan', 'nurani', 'rohani'],
        'jiwa' => ['hati', 'kalbu', 'batin', 'rohani', 'sukma'],
        'batin' => ['hati', 'jiwa', 'kalbu', 'rohani'],
        'prasangka' => ['duga', 'dugaan', 'sangka', 'suudzon', 'suudzan', 'buruk', 'curiga', 'suudzon'],

        // Lisan & Tutur Kata
        'lisan' => ['mulut', 'ucapan', 'perkataan', 'kata', 'tutur', 'omongan', 'bicara', 'bahasa'],
        'bicara' => ['lisan', 'mulut', 'ucapan', 'perkataan', 'kata', 'tutur', 'omongan', 'berkata'],
        'perkataan' => ['lisan', 'mulut', 'ucapan', 'kata', 'tutur', 'omongan', 'bicara', 'berkata'],
        'sia-sia' => ['kotor', 'kasar', 'gibah', 'ghibah', 'gosip', 'fitnah', 'bohong', 'dusta', 'makian', 'dosa', 'unfaedah', 'sia'],
        'kotor' => ['sia-sia', 'kasar', 'buruk', 'keji', 'jorok', 'makian', 'umpatan', 'cela'],

        // Akhlak & Karakter
        'akhlak' => ['moral', 'etika', 'adab', 'budi', 'pekerti', 'perilaku', 'karakter', 'perangai', 'sikap', 'tabiat'],
        'terpuji' => ['baik', 'mulia', 'luhur', 'mahmudah', 'bagus', 'positif', 'unggul', 'santun', 'sopan'],
        'budi' => ['akhlak', 'moral', 'pekerti', 'adab', 'etika', 'perilaku'],
        'pekerti' => ['akhlak', 'moral', 'budi', 'karakter', 'perilaku'],

        // Toleransi & Keberagaman
        'tasamuh' => ['toleran', 'toleransi', 'tenggang', 'rasa', 'menghargai', 'menghormati', 'rukun', 'harmonis'],
        'toleransi' => ['tasamuh', 'menghargai', 'menghormati', 'tenggang', 'rasa', 'terbuka', 'rukun'],
        'menghormati' => ['menghargai', 'toleran', 'tasamuh', 'tenggang', 'menjunjung', 'rukun', 'hormat'],
        'menghargai' => ['menghormati', 'toleran', 'tasamuh', 'mengakui', 'rukun', 'hargai'],
        'perbedaan' => ['beda', 'keberagaman', 'kemajemukan', 'majemuk', 'plural', 'variasi', 'ragam'],
        'muamalah' => ['sosial', 'masyarakat', 'interaksi', 'hubungan', 'kemanusiaan', 'gotong', 'royong', 'bantu'],
        'tolong-menolong' => ['bantu', 'membantu', 'gotong', 'royong', 'kerjasama', 'empati', 'peduli', 'tolong'],
        'memaksakan' => ['paksa', 'memaksa', 'intimidasi', 'tekanan', 'ancaman'],

        // Hikmah & Manfaat
        'hikmah' => ['manfaat', 'keutamaan', 'faedah', 'kegunaan', 'fungsi', 'keuntungan', 'fadhilah', 'kebaikan'],
        'keutamaan' => ['hikmah', 'manfaat', 'faedah', 'fadhilah', 'kelebihan', 'keistimewaan']
    ];

    /**
     * Stemming ringan Bahasa Indonesia
     */
    public static function stemWord($word) {
        $word = strtolower(trim($word));
        if (mb_strlen($word) <= 3) return $word;

        // Hilangkan akhiran kepemilikan/partikel: -kah, -lah, -tah, -pun, -ku, -mu, -nya
        $word = preg_replace('/(kah|lah|tah|pun)$/u', '', $word);
        $word = preg_replace('/(ku|mu|nya)$/u', '', $word);

        if (mb_strlen($word) <= 3) return $word;

        // Hilangkan akhiran: -kan, -an, -i
        $word = preg_replace('/(kan|an|i)$/u', '', $word);

        if (mb_strlen($word) <= 3) return $word;

        // Hilangkan awalan: meng-, meny-, men-, mem-, me-, peng-, peny-, pen-, pem-, pe-, di-, ter-, ke-, ber-, per-
        if (preg_match('/^(meng|meny|men|mem|me|peng|peny|pen|pem|pe|di|ter|ke|ber|per)(.+)$/u', $word, $m)) {
            $pref = $m[1];
            $rest = $m[2];

            if ($pref === 'meny' && mb_strlen($rest) >= 2) {
                $word = 's' . $rest;
            } elseif ($pref === 'meng' && mb_strlen($rest) >= 2 && in_array($rest[0], ['e', 'a', 'i', 'u', 'o', 'k'])) {
                $word = (in_array($rest[0], ['e', 'a', 'i', 'u', 'o'])) ? $rest : ('k' . substr($rest, 1));
            } elseif ($pref === 'mem' && mb_strlen($rest) >= 2 && in_array($rest[0], ['b', 'p', 'f'])) {
                $word = $rest;
            } elseif ($pref === 'men' && mb_strlen($rest) >= 2 && in_array($rest[0], ['d', 't', 'c', 'j'])) {
                $word = $rest;
            } else {
                $word = $rest;
            }
        }

        return trim($word);
    }

    /**
     * Parser Poin-Poin Indikator dari Teks Pedoman Penskoran
     */
    public static function extractRubricPoints($pedomanText) {
        $raw = trim($pedomanText ?? '');
        if ($raw === '') return [];

        // 1. Coba regex pola penomoran: 1) ... 2) ... atau 1. ... 2. ... atau a) ... b) ...
        if (preg_match_all('/(?:^|\s)(?:[0-9]{1,2}[\.\)]|[a-zA-Z][\.\)])\s*(.*?)(?=(?:\s[0-9]{1,2}[\.\)]|\s[a-zA-Z][\.\)]|$))/su', $raw, $matches)) {
            $points = [];
            foreach ($matches[1] as $item) {
                $item = trim(preg_replace('/^[\s\:\-]+/u', '', $item));
                if (mb_strlen($item) > 3) {
                    $points[] = $item;
                }
            }
            if (count($points) >= 2) {
                return $points;
            }
        }

        // 2. Coba pemisah baris baru (\n atau \r\n) atau bullet (- atau *)
        $lines = preg_split('/[\r\n]+/', $raw);
        $cleanLines = [];
        foreach ($lines as $line) {
            $line = trim(preg_replace('/^[\s\*\-\•\d\.\)]+/u', '', $line));
            if (mb_strlen($line) > 5) {
                $cleanLines[] = $line;
            }
        }
        if (count($cleanLines) >= 2) {
            return $cleanLines;
        }

        // 3. Coba pemisah titik koma (;)
        if (strpos($raw, ';') !== false) {
            $parts = explode(';', $raw);
            $points = [];
            foreach ($parts as $p) {
                $p = trim(preg_replace('/^[\s\*\-\•\d\.\)]+/u', '', $p));
                if (mb_strlen($p) > 5) {
                    $points[] = $p;
                }
            }
            if (count($points) >= 2) {
                return $points;
            }
        }

        // 4. Coba pemisah koma jika diawali kata "mencakup:" atau "meliputi:"
        if (preg_match('/(?:mencakup|meliputi|antara lain)\s*:\s*(.+)$/iu', $raw, $subm)) {
            $parts = explode(',', $subm[1]);
            $points = [];
            foreach ($parts as $p) {
                $p = trim(preg_replace('/^[\s\*\-\•\d\.\)]+/u', '', $p));
                if (mb_strlen($p) > 4) {
                    $points[] = $p;
                }
            }
            if (count($points) >= 2) {
                return $points;
            }
        }

        // Fallback: satu butir poin tunggal
        return [$raw];
    }

    /**
     * Ekstraksi Nilai Skor Nominal dari Teks Kriteria
     */
    public static function extractScoreFromText($criteriaText, $defaultScore = 0.0) {
        if (empty($criteriaText)) return (float)$defaultScore;

        if (preg_match('/(?:skor|nilai)\s*:?\s*(\d+(?:\.\d+)?)/iu', $criteriaText, $m)) {
            return (float)$m[1];
        }

        if (preg_match('/\((\d+(?:\.\d+)?)\)/u', $criteriaText, $m)) {
            return (float)$m[1];
        }

        return (float)$defaultScore;
    }

    /**
     * Dapatkan daftar kata kunci bermakna dan klaster sinonim dari sebuah teks indikator
     */
    public static function getKeywordsAndThesaurus($text) {
        $clean = preg_replace('/[^\p{L}\p{N}\s\-]/u', ' ', strtolower($text));
        $words = preg_split('/\s+/u', $clean, -1, PREG_SPLIT_NO_EMPTY);

        $stemmedList = [];
        $extendedSynonyms = [];

        foreach ($words as $w) {
            if (mb_strlen($w) < 4 || in_array($w, self::$stopWords, true)) {
                continue;
            }
            $stem = self::stemWord($w);
            if (mb_strlen($stem) >= 3 && !in_array($stem, self::$stopWords, true)) {
                $stemmedList[] = $stem;
            }

            // Perluasan sinonim
            if (isset(self::$thesaurus[$w])) {
                foreach (self::$thesaurus[$w] as $syn) {
                    $sStem = self::stemWord($syn);
                    if (!in_array($sStem, self::$stopWords, true) && mb_strlen($sStem) >= 3) {
                        $extendedSynonyms[] = $sStem;
                    }
                }
            }
            if (isset(self::$thesaurus[$stem])) {
                foreach (self::$thesaurus[$stem] as $syn) {
                    $sStem = self::stemWord($syn);
                    if (!in_array($sStem, self::$stopWords, true) && mb_strlen($sStem) >= 3) {
                        $extendedSynonyms[] = $sStem;
                    }
                }
            }
        }

        $allUnique = array_unique(array_merge($stemmedList, $extendedSynonyms));
        return [
            'core_stems' => array_values(array_unique($stemmedList)),
            'all_synonyms' => array_values($allUnique)
        ];
    }

    /**
     * Memeriksa apakah suatu butir poin indikator terpenuhi oleh jawaban siswa
     */
    public static function checkPointAchieved($pointText, $studentAnswerRaw, $studentStems, $studentAnswerNormalized) {
        $kwData = self::getKeywordsAndThesaurus($pointText);
        $coreStems = $kwData['core_stems'];
        $allSynonyms = $kwData['all_synonyms'];

        if (empty($coreStems)) {
            return ['achieved' => false, 'matched_words' => []];
        }

        // Cek juga deteksi frasa khusus dalil (misal "ar-ra'd", "al-baqarah", "al-kafirun")
        $specialPhrases = [];
        if (preg_match_all('/(?:qs|surat)?\s*([a-z\-]+(?:\s*:\s*\d+)?)/iu', $pointText, $pm)) {
            foreach ($pm[0] as $phrase) {
                $pClean = trim($phrase);
                if (mb_strlen($pClean) >= 5 && !in_array(strtolower($pClean), ['jawaban', 'poin', 'utama'])) {
                    $specialPhrases[] = strtolower($pClean);
                }
            }
        }

        $matchedWords = [];

        // 1. Cek kecocokan frasa khusus
        foreach ($specialPhrases as $sp) {
            $spClean = preg_replace('/[^\p{L}\p{N}]/u', '', $sp);
            $ansClean = preg_replace('/[^\p{L}\p{N}]/u', '', $studentAnswerNormalized);
            if (mb_strlen($spClean) >= 4 && strpos($ansClean, $spClean) !== false) {
                $matchedWords[] = $sp;
            }
        }

        // 2. Cek kecocokan kata dasar / sinonim pada jawaban siswa
        foreach ($allSynonyms as $targetWord) {
            // Hindari stopword
            if (in_array($targetWord, self::$stopWords, true) || mb_strlen($targetWord) < 3) {
                continue;
            }

            // Cek exact pada stem siswa
            if (in_array($targetWord, $studentStems, true)) {
                $matchedWords[] = $targetWord;
                continue;
            }

            // Cek substring pada kalimat siswa jika panjang kata >= 4
            if (mb_strlen($targetWord) >= 4 && strpos($studentAnswerNormalized, $targetWord) !== false) {
                $matchedWords[] = $targetWord;
                continue;
            }

            // Strict Fuzzy match (hanya untuk kata panjang >= 5 huruf, toleransi 1 karakter / similarity >= 85%)
            if (mb_strlen($targetWord) >= 5) {
                foreach ($studentStems as $sStem) {
                    if (mb_strlen($sStem) >= 5 && abs(mb_strlen($targetWord) - mb_strlen($sStem)) <= 1) {
                        similar_text($targetWord, $sStem, $perc);
                        if ($perc >= 85.0) {
                            $matchedWords[] = "$sStem";
                            break;
                        }
                    }
                }
            }
        }

        $matchedWords = array_unique($matchedWords);

        // Ambang batas terpenuhi:
        // Jika core stems >= 4: minimal 2 konsep cocok
        // Jika core stems 1-3: minimal 1-2 konsep cocok
        $minRequired = (count($coreStems) >= 4) ? 2 : 1;
        $achieved = (count($matchedWords) >= $minRequired);

        return [
            'achieved' => $achieved,
            'matched_words' => array_values($matchedWords),
            'matched_count' => count($matchedWords),
            'required_count' => $minRequired
        ];
    }

    /**
     * EVALUASI UTAMA SOAL ESAI BERDASARKAN DATA RUBRIK & BOBOT
     */
    public static function evaluate($studentAnswer, $rubricData, $overrideBobotMaks = null) {
        $jawabanTrim = trim($studentAnswer ?? '');

        // Default rubrik fallbacks
        $skorMaksimalRubrik = (float)($rubricData['skor_maksimal'] ?? 20.00);
        if ($skorMaksimalRubrik <= 0) $skorMaksimalRubrik = 20.00;

        $bobotMaksSoal = ($overrideBobotMaks !== null && (float)$overrideBobotMaks > 0) 
            ? (float)$overrideBobotMaks 
            : $skorMaksimalRubrik;

        // Jika jawaban kosong sama sekali
        if ($jawabanTrim === '') {
            return [
                'skor_diperoleh'    => 0.00,
                'skor_bobot_maks'   => $bobotMaksSoal,
                'is_benar'          => 0,
                'tier_label'        => 'Skor 0 (Nol)',
                'poin_terpenuhi'    => 0,
                'total_poin'        => 0,
                'persentase_skor'   => 0.0,
                'catatan_koreksi'   => "[Evaluasi Rubrik AI] Jawaban kosong / tidak dijawab sama sekali. (Skor 0.00)",
                'detail_poin'       => []
            ];
        }

        // Parse skor nominal dari masing-masing tingkat rubrik
        $skorTierMaksimal = self::extractScoreFromText($rubricData['kriteria_skor_maksimal'] ?? '', $skorMaksimalRubrik);
        $skorTierSedang   = self::extractScoreFromText($rubricData['kriteria_skor_sedang'] ?? '', round($skorMaksimalRubrik * 0.70, 2));
        $skorTierMinimal  = self::extractScoreFromText($rubricData['kriteria_skor_minimal'] ?? '', round($skorMaksimalRubrik * 0.40, 2));
        $skorTierNol      = self::extractScoreFromText($rubricData['kriteria_skor_nol'] ?? '', 0.00);

        // Ekstrak butir-butir indikator pedoman penskoran
        $points = self::extractRubricPoints($rubricData['pedoman_penskoran'] ?? '');
        $totalPoints = count($points);

        // Tokenize dan stem jawaban siswa
        $ansClean = preg_replace('/[^\p{L}\p{N}\s\-]/u', ' ', strtolower($jawabanTrim));
        $ansWords = preg_split('/\s+/u', $ansClean, -1, PREG_SPLIT_NO_EMPTY);

        $studentStems = [];
        foreach ($ansWords as $w) {
            if (in_array($w, self::$stopWords, true)) continue;
            $s = self::stemWord($w);
            if (mb_strlen($s) >= 3 && !in_array($s, self::$stopWords, true)) {
                $studentStems[] = $s;
            }
        }
        $studentStems = array_unique($studentStems);
        $studentNorm = strtolower($jawabanTrim);

        // Evaluasi capaian setiap poin indikator
        $achievedCount = 0;
        $detailPoin = [];

        foreach ($points as $idx => $pText) {
            $check = self::checkPointAchieved($pText, $jawabanTrim, $studentStems, $studentNorm);
            $isAchieved = $check['achieved'];
            if ($isAchieved) {
                $achievedCount++;
            }

            $detailPoin[] = [
                'nomor'         => $idx + 1,
                'indikator'     => $pText,
                'status'        => $isAchieved ? 'TERPENUHI' : 'BELUM TERPENUHI',
                'kata_terdeteksi' => $check['matched_words']
            ];
        }

        // Tentukan Tingkat / Tier Capaian berdasarkan jumlah poin terpenuhi
        $tierLabel = 'Skor 0 (Nol)';
        $nominalRubrik = $skorTierNol;

        if ($totalPoints >= 3) {
            if ($achievedCount >= 3) {
                $tierLabel = 'Maksimal';
                $nominalRubrik = $skorTierMaksimal;
            } elseif ($achievedCount == 2) {
                $tierLabel = 'Sedang / Sebagian';
                $nominalRubrik = $skorTierSedang;
            } elseif ($achievedCount == 1) {
                $tierLabel = 'Minimal / Dasar';
                $nominalRubrik = $skorTierMinimal;
            } else {
                $tierLabel = 'Skor 0 (Nol)';
                $nominalRubrik = $skorTierNol;
            }
        } elseif ($totalPoints == 2) {
            if ($achievedCount >= 2) {
                $tierLabel = 'Maksimal';
                $nominalRubrik = $skorTierMaksimal;
            } elseif ($achievedCount == 1) {
                $tierLabel = 'Sedang / Sebagian';
                $nominalRubrik = $skorTierSedang;
            } else {
                $tierLabel = 'Skor 0 (Nol)';
                $nominalRubrik = $skorTierNol;
            }
        } elseif ($totalPoints == 1) {
            if ($achievedCount >= 1) {
                $tierLabel = 'Maksimal';
                $nominalRubrik = $skorTierMaksimal;
            } else {
                $tierLabel = 'Skor 0 (Nol)';
                $nominalRubrik = $skorTierNol;
            }
        } else {
            // Jika tidak ada poin spesifik, evaluasi proporsi kata kunci umum
            $kwGeneral = self::getKeywordsAndThesaurus(($rubricData['kriteria_skor_maksimal'] ?? '') . ' ' . ($rubricData['pedoman_penskoran'] ?? ''));
            $matchedGeneral = array_intersect($studentStems, $kwGeneral['all_synonyms']);
            $matchCount = count($matchedGeneral);

            if ($matchCount >= 4) {
                $tierLabel = 'Maksimal';
                $nominalRubrik = $skorTierMaksimal;
            } elseif ($matchCount >= 2) {
                $tierLabel = 'Sedang / Sebagian';
                $nominalRubrik = $skorTierSedang;
            } elseif ($matchCount >= 1) {
                $tierLabel = 'Minimal / Dasar';
                $nominalRubrik = $skorTierMinimal;
            } else {
                $tierLabel = 'Skor 0 (Nol)';
                $nominalRubrik = $skorTierNol;
            }
        }

        // Proporsikan skor nominal rubrik ke bobot maks butir soal jika berbeda
        if ($skorMaksimalRubrik > 0 && abs($skorMaksimalRubrik - $bobotMaksSoal) > 0.01) {
            $skorFinal = round(($nominalRubrik / $skorMaksimalRubrik) * $bobotMaksSoal, 2);
        } else {
            $skorFinal = round($nominalRubrik, 2);
        }

        if ($skorFinal > $bobotMaksSoal) {
            $skorFinal = $bobotMaksSoal;
        }

        $persentase = ($bobotMaksSoal > 0) ? round(($skorFinal / $bobotMaksSoal) * 100, 1) : 0;
        $isBenar = ($skorFinal > 0) ? 1 : 0;

        // Susun Catatan Evaluasi Transparan untuk Guru
        $catatanLines = [];
        $catatanLines[] = "[Evaluasi Rubrik AI] Tingkat Capaian: $tierLabel | Skor: $skorFinal / $bobotMaksSoal ($persentase%)";
        $catatanLines[] = "Poin Indikator Terpenuhi: $achievedCount dari $totalPoints poin.";
        foreach ($detailPoin as $dp) {
            $statusIco = ($dp['status'] === 'TERPENUHI') ? '[OK]' : '[BELUM]';
            $kwStr = !empty($dp['kata_terdeteksi']) ? ' (Kecocokan: ' . implode(', ', array_slice($dp['kata_terdeteksi'], 0, 4)) . ')' : '';
            $catatanLines[] = "  $statusIco Poin " . $dp['nomor'] . ": " . mb_substr($dp['indikator'], 0, 60) . '...' . $kwStr;
        }

        $catatanKoreksi = implode("\n", $catatanLines);

        return [
            'skor_diperoleh'    => $skorFinal,
            'skor_bobot_maks'   => $bobotMaksSoal,
            'is_benar'          => $isBenar,
            'tier_label'        => $tierLabel,
            'poin_terpenuhi'    => $achievedCount,
            'total_poin'        => $totalPoints,
            'persentase_skor'   => $persentase,
            'catatan_koreksi'   => $catatanKoreksi,
            'detail_poin'       => $detailPoin
        ];
    }
}
