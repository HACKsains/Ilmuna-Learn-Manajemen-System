-- ==============================================================================
-- ILMUNA LEARN — SKEMA DATABASE (74 TABEL INTI KBM & SISTEM SEKOLAH CBT)
-- ==============================================================================
-- File ini menggabungkan seluruh tabel inti dalam 1 file database.
-- Disesuaikan dengan Standar Kurikulum Merdeka & Format PDP:
-- 1. "1. Hitung Hari Efektif.xlsx" (Hitung Hari & Pekan Efektif, Bagi HE Ganjil/Genap)
-- 2. "PROTA.docx" (Program Tahunan PAI & Capaian Pembelajaran Elemen)
-- 3. "PROSEM.xlsx" (Program Semester Matriks Alokasi Waktu Mingguan Ganjil & Genap)
--
-- PRINSIP ARSITEKTUR RELASIONAL:
-- - Seluruh tabel program (Pekan Efektif, Hari Efektif, Alokasi Mengajar, PROTA, PROSEM)
--   terikat langsung pada `id_kelas_mata_pelajaran`.
-- - Elemen kurikulum memiliki tabel tersendiri (`elemen`) yang menjadi induk dari CP & PROTA.
-- - Relasi beruntut hirarki pembelajaran: ELEMEN -> CP -> TP -> ATP -> ITP.
-- - ITP (Indikator Tujuan Pembelajaran / IKTP) berelasi langsung ke TP & ATP,
--   serta dirujuk di modul_ajar, distribusi_jtm_tp, prota_detail, dan prosem_detail.
-- - PROTA dan PROSEM tidak menduplikasi teks mentah nama guru/kepala sekolah atau teks TP,
--   melainkan langsung menghubungkan relasi `id_elemen`, `id_tp`, dan `id_itp`.
-- - Master Titik Koordinat Sekolah (`titik_koordinat_sekolah`):
--   Menyimpan titik koordinat latitude & longitude sekolah/kampus beserta radius toleransi meter.
-- - Jadwal Pelajaran (`jadwal_pelajaran`):
--   Jadwal pertemuan KBM diambil dari modul ajar, hari efektif, dan pekan efektif.
-- - Master Presensi Siswa (`presensi`):
--   Master kartu/ID presensi siswa per kelas per mapel berisi kode unik QR Code dan status.
-- - Alur Dual-Approval Perizinan (`perizinan`):
--   Siswa ajukan izin + bukti surat/foto + koordinat GPS (lat & long) -> Disetujui Ketua Kelas
--   -> Disetujui Guru Pengampu -> Otomatis Tersinkron ke Absensi Siswa (status I atau S).
-- - Transaksi Absensi Siswa (`absensi`):
--   Transaksi kehadiran per pertemuan KBM berbasis scan QR Code master presensi siswa
--   yang respon verifikasinya dicampur dengan titik GPS (latitude & longitude) siswa (tanpa selfie).
--
-- 1. TABEL KELAS:
--    - id                  : ID unik kelas (Primary Key, Auto Increment)
--    - nama_kelas          : Nama kelas / rombel (contoh: X PAI-1, XI PAI-1)
--    - kode_kelas          : Kode unik acak terproteksi kelas untuk Sheet 2 Excel (contoh: X26-ID1468)
--    - tahun_pelajaran     : Tahun pelajaran aktif (contoh: 2025/2026, 2026/2027)
--
-- 2. TABEL SISWA:
--    - id                  : ID unik siswa (Primary Key, Auto Increment)
--    - id_kelas            : Relasi ke tabel kelas (Foreign Key)
--    - nisn                : Nomor Induk Siswa Nasional
--    - nis                 : Nomor Induk Siswa (Sekolah)
--    - nama_lengkap        : Nama lengkap siswa
--    - alamat              : Alamat tempat tinggal
--    - no_hp               : Nomor WhatsApp / Telepon siswa
--    - gmail               : Alamat email Google / Gmail siswa
--    - foto                : File foto profil siswa
--
-- 3. TABEL USER:
--    - id                  : ID unik pengguna (Primary Key, Auto Increment)
--    - username            : Username unik untuk login
--    - password            : Kata sandi akun (hash bcrypt)
--    - email_google        : Alamat email Google / Gmail akun
--    - google_id           : ID autentikasi Single Sign-On (SSO) Google
--    - foto_profil         : Foto profil pengguna
--    - is_active           : Status akun aktif (1) atau nonaktif (0)
--    - last_login          : Waktu terakhir kali login ke sistem
--
-- 4. TABEL ROLES:
--    - id                  : ID unik role (Primary Key, Auto Increment)
--    - nama_roles          : Nama role hak akses (contoh: Administrator, Guru, Siswa)
--    - slug                : Kode/slug unik role (contoh: admin, guru, ketua_kelas, siswa)
--    - deskripsi           : Keterangan hak akses peran
--
-- 5. TABEL USER_ROLES (MULTI-ROLE: 1 User bisa memiliki lebih dari 1 role):
--    - id                  : ID relasi auto increment (Primary Key)
--    - id_user             : Relasi ke tabel users(id)
--    - id_roles            : Relasi ke tabel roles(id)
--
-- 6. TABEL GURU (MASTER DATA GURU PENGAMPU):
--    - id                  : ID unik guru (Primary Key, Auto Increment)
--    - id_user             : Relasi ke tabel users(id) jika guru memiliki akun login portal
--    - nip                 : Nomor Induk Pegawai (NIP)
--    - nuptk               : Nomor Unik Pendidik & Tenaga Kependidikan (NUPTK)
--    - nama_guru           : Nama lengkap guru beserta gelar akademik
--    - kode_guru           : Kode referensi unik guru terproteksi untuk ekspor & Sheet 2 Excel (contoh: GRU-132)
--    - jenis_kelamin       : Laki-laki (L) / Perempuan (P)
--    - no_hp               : Nomor WhatsApp / Telepon guru
--    - email               : Alamat email aktif guru
--    - alamat              : Alamat tempat tinggal
--    - foto                : Berkas foto profil guru
--    - status_kepegawaian  : Status kepegawaian (PNS, PPPK, GTT, Tetap Yayasan)
--
-- 7. TABEL MATA PELAJARAN:
--    - id                  : ID unik mata pelajaran (Primary Key, Auto Increment)
--    - nama_mata_pelajaran : Nama mata pelajaran (contoh: Pendidikan Agama Islam & Budi Pekerti)
--    - kode_mapel          : Kode referensi unik mata pelajaran terproteksi untuk Sheet 2 Excel (contoh: PAI-158)
--    - tahun_pelajaran     : Tahun pelajaran aktif (contoh: 2025/2026, 2026/2027)
--    - semester            : Semester pembelajaran (Ganjil / Genap)
--
-- 7b. TABEL GURU_MATA_PELAJARAN (RELASI MAPPING MASTER GURU KE MATA PELAJARAN YANG DIAMPU):
--    - id                  : ID unik relasi (Primary Key, Auto Increment)
--    - id_guru             : Relasi ke tabel guru(id)
--    - id_mata_pelajaran   : Relasi ke tabel mata_pelajaran(id)
--    - kode_relasi         : Kode unik acak relasi guru & mata pelajaran untuk Sheet 2 Excel (contoh: PAI-893)
--
-- 8. TABEL KELAS_MATA_PELAJARAN / KELAS_MAPEL (RELASI KELAS, MAPEL & PEMETAAN GURU PENGAMPU):
--    - id                      : ID unik relasi / id_kelas_mapel (Primary Key, Auto Increment)
--    - id_kelas                : Relasi ke tabel kelas(id)
--    - id_mata_pelajaran       : Relasi ke tabel mata_pelajaran(id)
--    - id_guru_mata_pelajaran  : Relasi ke tabel guru_mata_pelajaran(id) pengampu bidang studi
--    - id_guru                 : Relasi ke master data guru(id) - fallback compatibility
--
-- 8. TABEL ELEMEN (ELEMEN CAPAIAN PEMBELAJARAN KURIKULUM MERDEKA):
--    - id                  : ID unik elemen (Primary Key, Auto Increment)
--    - id_kelas_mata_pelajaran: Relasi ke kelas_mata_pelajaran(id)
--    - nama_elemen         : Nama elemen kurikulum (Al-Qur’an Hadis, Akidah, Akhlak, Fikih, Sejarah)
--    - fase                : Jenjang fase (Fase E/F)
--    - deskripsi           : Penjelasan lingkup materi elemen
--
-- 9. TABEL CP (CAPAIAN PEMBELAJARAN):
--    - id                      : ID unik CP (Primary Key, Auto Increment)
--    - id_kelas_mata_pelajaran : Relasi ke tabel kelas_mata_pelajaran(id)
--    - id_elemen               : Relasi ke tabel elemen(id)
--    - cp                      : Teks Capaian Pembelajaran (CP)
--
-- 10. TABEL TP (TUJUAN PEMBELAJARAN):
--    - id                  : ID unik TP (Primary Key, Auto Increment)
--    - id_cp               : Relasi ke tabel cp(id)
--    - kode_tp             : Kode/Nomor urut TP (contoh: 1.1, 2.1-2.3)
--    - tp                  : Teks Tujuan Pembelajaran (TP)
--    - materi_pokok        : Pokok bahasan terkait TP
--    - alokasi_jam         : Alokasi jam tatap muka default (JP)
--
-- 11. TABEL ATP (ALUR TUJUAN PEMBELAJARAN):
--    - id                  : ID unik ATP (Primary Key, Auto Increment)
--    - id_tp               : Relasi ke tabel tp(id)
--    - atp                 : Teks Alur Tujuan Pembelajaran (ATP)
--    - urutan              : Nomor urut alur pembelajaran (1, 2, 3...)
--
-- 12. TABEL ITP (INDIKATOR TUJUAN PEMBELAJARAN / IKTP BERELASI BERUNTUT KE TP & ATP):
--    - id                  : ID unik ITP (Primary Key, Auto Increment)
--    - id_tp               : Relasi beruntut ke tabel tp(id)
--    - id_atp              : Relasi opsional ke tabel atp(id)
--    - kode_itp            : Kode Indikator (misal: 1.1.1, 1.1.2)
--    - itp                 : Teks Indikator Tujuan Pembelajaran (ITP / IKTP)
--    - deskripsi_ketercapaian: Kriteria / rubrik ketercapaian kompetensi
--    - urutan              : Nomor urut indikator (1, 2, 3...)
--
-- 13. TABEL MODEL_PEMBELAJARAN (MASTER MODEL PEMBELAJARAN KURIKULUM MERDEKA):
--    - id                  : ID unik model pembelajaran (Primary Key, Auto Increment)
--    - nama_model          : Nama resmi model (PBL, PjBL, Discovery, Inquiry, dll.)
--    - slug                : Slug unik untuk routing / API
--    - kategori            : Kategori pendekatan pembelajaran (Kurikulum Merdeka)
--    - deskripsi           : Penjelasan konsep, filosofi & tujuan model
--    - sintaks             : Uraian sintaks lengkap tahap 1 s.d selesai
--
-- 14. TABEL SINTAKS_MODEL_PEMBELAJARAN (DETAIL FASE, AKTIVITAS GURU & SISWA):
--    - id                  : ID unik tahapan sintaks (Primary Key, Auto Increment)
--    - id_model_pembelajaran: Relasi ke tabel model_pembelajaran(id)
--    - tahap_ke            : Urutan langkah / fase sintaks (1, 2, 3, dst.)
--    - nama_fase           : Judul fase sintaks pembelajaran
--    - aktivitas_guru      : Peran dan tindakan pendidik secara rinci
--    - aktivitas_siswa     : Peran dan tindakan peserta didik secara aktif
--
-- 15. TABEL METODE_PEMBELAJARAN (MASTER METODE OPERASIONAL KBM):
--    - id                  : ID unik metode pembelajaran (Primary Key, Auto Increment)
--    - nama_metode         : Nama metode (Ceramah Interaktif, Diskusi, Talaqqi, dll.)
--    - slug                : Slug unik untuk routing / API
--    - deskripsi           : Penjelasan konsep dan filosofi metode
--    - langkah_langkah     : Uraian operasional langkah-langkah pelaksanaan di kelas
--    - kelebihan           : Keunggulan dan manfaat metode
--    - kekurangan          : Keterbatasan dan tantangan metode
--    - rekomendasi_penerapan: Materi dan situasi yang direkomendasikan
--
-- 16. TABEL MATERI (BANK MATERI PEMBELAJARAN INDEPENDEN):
--    - id                  : ID unik materi (Primary Key, Auto Increment)
--    - judul_materi        : Judul materi pembelajaran
--    - slug                : Slug URL ramah web
--    - deskripsi_singkat   : Ringkasan isi materi
--    - isi_materi          : Naskah lengkap materi artikel/teks KBM
--    - tipe_materi         : Format materi (Teks, Video, PDF, Slide, Link Eksternal)
--    - link_materi         : Tautan materi eksternal (YouTube / GDrive / URL)
--    - file_materi         : File unduhan materi (pdf/docx/pptx)
--    - durasi_menit        : Estimasi waktu belajar dalam menit
--
-- 17. TABEL MODUL_AJAR (PERENCANAAN PERTEMUAN KBM KURIKULUM MERDEKA):
--    - id                  : ID unik modul ajar (Primary Key, Auto Increment)
--    - id_kelas_mata_pelajaran: Relasi ke tabel kelas_mata_pelajaran(id)
--    - id_cp               : Relasi ke Capaian Pembelajaran cp(id)
--    - id_tp               : Relasi ke Tujuan Pembelajaran tp(id)
--    - id_atp              : Relasi ke Alur Tujuan Pembelajaran atp(id)
--    - id_itp              : Relasi ke Indikator Tujuan Pembelajaran itp(id)
--    - id_model_pembelajaran: Relasi ke model_pembelajaran(id)
--    - id_metode_pembelajaran: Relasi ke metode_pembelajaran(id)
--    - id_materi           : Relasi ke bank materi materi(id) yang dipilih untuk pertemuan ini
--    - id_guru             : Relasi ke users(id) guru penyusun
--    - pertemuan_ke        : Nomor urut pertemuan pembelajaran (1, 2, 3...)
--    - judul_modul         : Judul modul ajar / materi pokok
--    - fase                : Jenjang fase Kurikulum Merdeka (Fase E/F)
--    - alokasi_waktu       : Durasi jam pelajaran (contoh: 2 x 45 Menit)
--    - profil_pelajar_pancasila: Dimensi karakter P3 & Rahmatan Lil Alamin
--    - sarana_prasarana    : Media, perangkat, dan fasilitas pembelajaran
--    - target_peserta_didik: Sasaran profil kemampuan murid
--    - pemahaman_bermakna  : Nilai esensial dan kemanfaatan materi
--    - pertanyaan_pemantik : Pemantik rasa ingin tahu dan nalar kritis
--    - kegiatan_pendahuluan: Rangkaian kegiatan awal KBM
--    - kegiatan_inti       : Langkah inti sesuai sintaks model pembelajaran
--    - kegiatan_penutup    : Rangkuman, refleksi, dan evaluasi penutup
--    - asesmen_diagnostik  : Pemetaan kesiapan belajar awal
--    - asesmen_formatif    : Penilaian proses KBM & LKPD
--    - asesmen_sumatif     : Uji ketercapaian kompetensi akhir
--    - pengayaan_remedial  : Program penanganan murid cepat & berkesulitan
--    - refleksi_guru_siswa : Catatan evaluasi pendidik dan refleksi murid
--    - lkpd                : Lembar Kerja Peserta Didik
--    - bahan_bacaan        : Sumber bacaan literasi guru dan murid
--    - glosarium           : Daftar kata kunci / istilah penting
--    - daftar_pustaka      : Daftar rujukan buku dan literatur
--    - file_lampiran       : Dokumen lampiran (pdf/docx)
--    - status              : Status modul (Draft, Published, Archived)
--
-- 18. TABEL PEKAN_EFEKTIF (RINCIAN PEKAN & HARI EFEKTIF PER KELAS MAPEL - SHEET 1 EXCEL HE):
--    - id                  : ID unik pekan efektif (Primary Key, Auto Increment)
--    - id_kelas_mata_pelajaran: Relasi ke kelas_mata_pelajaran(id)
--    - tahun_pelajaran     : Tahun ajaran aktif (contoh: 2025/2026, 2026/2027)
--    - semester            : Semester pembelajaran (Ganjil / Genap)
--    - bulan               : Nama bulan kalender akademik (Juli s.d Juni)
--    - urutan_bulan        : Urutan bulan akademik (1: Juli s.d 12: Juni)
--    - senin               : Jumlah hari Senin efektif dalam bulan tersebut
--    - selasa              : Jumlah hari Selasa efektif dalam bulan tersebut
--    - rabu                : Jumlah hari Rabu efektif dalam bulan tersebut
--    - kamis               : Jumlah hari Kamis efektif dalam bulan tersebut
--    - jumat               : Jumlah hari Jumat efektif dalam bulan tersebut
--    - sabtu               : Jumlah hari Sabtu efektif dalam bulan tersebut
--    - minggu              : Jumlah hari Minggu efektif dalam bulan tersebut
--    - total_hari_efektif  : Total hari efektif bulan tersebut
--    - jumlah_pekan_total  : Total jumlah pekan dalam bulan (Jml Pekan di Excel)
--    - jumlah_pekan_efektif: Pekan aktif pembelajaran (Pekan Efektif di Excel)
--    - jumlah_pekan_tidak_efektif: Pekan non-KBM (libur, asesmen, MPLS)
--    - keterangan_tidak_efektif: Rincian agenda non-KBM
--
-- 19. TABEL HARI_EFEKTIF (KALENDER DETAIL HARI KBM PER KELAS MAPEL):
--    - id                  : ID unik hari efektif (Primary Key, Auto Increment)
--    - id_kelas_mata_pelajaran: Relasi ke kelas_mata_pelajaran(id)
--    - tahun_pelajaran     : Tahun ajaran aktif
--    - semester            : Semester pembelajaran (Ganjil / Genap)
--    - tanggal             : Tanggal spesifik kalender (YYYY-MM-DD)
--    - hari                : Nama hari (Senin s.d Minggu)
--    - status_hari         : Status (Efektif KBM, Libur Nasional, Libur Semester, dll.)
--    - keterangan          : Keterangan agenda harian sekolah
--
-- 20. TABEL ALOKASI_HARI_MENGAJAR (HITUNG JTM PER HARI MENGAJAR - SHEET 2 & 3 KIRI EXCEL):
--    - id                  : ID unik alokasi mengajar (Primary Key, Auto Increment)
--    - id_kelas_mata_pelajaran: Relasi ke kelas_mata_pelajaran(id)
--    - id_guru             : Relasi ke users(id) guru pengampu
--    - semester            : Semester pembelajaran (Ganjil / Genap)
--    - hari                : Hari mengajar di kelas (Senin s.d Sabtu)
--    - banyaknya_hari_efektif: Jumlah hari efektif hari tersebut dari pekan_efektif (misal: 18 hari)
--    - jp_per_minggu       : Jam Pelajaran per minggu (misal: 2 JP)
--    - total_jtm           : Jam Tatap Muka Total = Hari Efektif * JP/Minggu (misal: 18 * 2 = 36 JP)
--    - pola_pekan_mengajar : Rincian pola pekan aktif (contoh: Jul:00045, Agt:1234, Sept:1234, ...)
--
-- 21. TABEL DISTRIBUSI_JTM_TP (DISTRIBUSI JTM KE TP & ASESMEN - SHEET 2 & 3 KANAN EXCEL):
--    - id                  : ID unik distribusi TP (Primary Key, Auto Increment)
--    - id_alokasi_hari_mengajar: Relasi ke alokasi_hari_mengajar(id)
--    - id_kelas_mata_pelajaran: Relasi ke kelas_mata_pelajaran(id)
--    - id_tp               : Relasi ke tp(id) (NULL jika untuk Proyek/Ujian)
--    - id_itp              : Relasi ke itp(id) (Indikator Tujuan Pembelajaran / IKTP)
--    - jenis_kegiatan      : Kategori (TP KBM, Proyek, AS, STS, Remedial, ASAS)
--    - kode_kegiatan       : Kode singkat (misal: RI&SYD 1, TH 1, SF 1, Proyek, AS, STS, REMIDI, ASAS)
--    - nama_kegiatan       : Penjelasan materi atau aktivitas asesmen
--    - jtm                 : Alokasi Jam Tatap Muka (JP) (contoh: 2 JP, 1 JP, 5 JP)
--    - urutan              : Urutan pelaksanaan KBM (1, 2, 3...)
--    - keterangan          : Catatan tambahan
--
-- 22. TABEL PROTA (PROGRAM TAHUNAN KURIKULUM MERDEKA - PROTA.DOCX):
--    - id                  : ID unik prota (Primary Key, Auto Increment)
--    - id_kelas_mata_pelajaran: Relasi ke kelas_mata_pelajaran(id)
--    - id_guru             : Relasi ke users(id) guru pengampu
--    - tahun_pelajaran     : Tahun ajaran aktif (2025/2026)
--    - fase                : Fase jenjang (Fase F)
--    - total_jtm_ganjil    : Total jam tatap muka KBM semester ganjil (26 JP)
--    - total_jtm_genap     : Total jam tatap muka KBM semester genap (26 JP)
--    - total_jtm_tahunan   : Total JTM setahun (52 JP)
--    - as_ganjil/genap     : Alokasi Asesmen Sumatif (4 JP)
--    - sts_ganjil/genap    : Alokasi Sumatif Tengah Semester (2 JP)
--    - remidi_ganjil/genap : Alokasi Remedial (0 JP)
--    - proyek_ganjil/genap : Alokasi Proyek P5 PPRA (2 JP)
--    - status              : Status persetujuan (Draft, Approved, Archived)
--
-- 23. TABEL PROTA_DETAIL (RINCIAN BARIS PROGRAM TAHUNAN BERELASI KE ELEMEN, TP, & ITP):
--    - id                  : ID unik rincian prota (Primary Key, Auto Increment)
--    - id_prota            : Relasi ke prota(id)
--    - id_elemen           : Relasi ke elemen(id) (Elemen Kurikulum)
--    - id_tp               : Relasi ke tp(id) (Tujuan Pembelajaran)
--    - id_itp              : Relasi opsional ke itp(id) (Indikator Tujuan Pembelajaran)
--    - semester            : Semester pelaksanaan (Ganjil / Genap)
--    - nomor_urut          : Nomor urut baris (1 s.d 5)
--    - alokasi_waktu       : Jam tatap muka per materi pokok (4 JP, 6 JP)
--
-- 24. TABEL PROSEM (PROGRAM SEMESTER KURIKULUM MERDEKA - PROSEM.XLSX):
--    - id                  : ID unik prosem (Primary Key, Auto Increment)
--    - id_prota            : Relasi ke prota(id)
--    - id_kelas_mata_pelajaran: Relasi ke kelas_mata_pelajaran(id)
--    - id_guru             : Relasi ke users(id) guru pengampu
--    - tahun_pelajaran     : Tahun ajaran aktif (2025/2026)
--    - semester            : Semester pembelajaran (Ganjil / Genap)
--    - total_alokasi_jp    : Total alokasi waktu semester (36 JP Ganjil, 38 JP Genap)
--    - status              : Status persetujuan (Draft, Approved, Archived)
--
-- 25. TABEL PROSEM_DETAIL (RINCIAN POKOK BAHASAN PROSEM BERELASI KE TP & ITP):
--    - id                  : ID unik rincian prosem (Primary Key, Auto Increment)
--    - id_prosem           : Relasi ke prosem(id)
--    - id_tp               : Relasi ke tp(id) (NULL jika agenda non-TP seperti STS, ASAS, Proyek)
--    - id_itp              : Relasi opsional ke itp(id) (Indikator Tujuan Pembelajaran)
--    - nomor_urut          : Nomor urut agenda (1 s.d 10)
--    - kategori            : Kategori (Materi KBM, AS, STS, Proyek, ASAS, Remedial)
--    - nama_agenda         : Deskripsi jika id_tp NULL (misal: STS, ASAS, Proyek)
--    - alokasi_waktu       : Beban jam pelajaran (2 JP, 4 JP, 6 JP)
--    - id_modul_ajar       : Relasi ke modul_ajar(id)
--
-- 26. TABEL PROSEM_JADWAL_MINGGUAN (DISTRIBUSI MATRIKS JP PER PEKAN PER BULAN):
--    - id                  : ID unik matriks jadwal mingguan (Primary Key, Auto Increment)
--    - id_prosem_detail    : Relasi ke prosem_detail(id)
--    - bulan               : Nama bulan kalender (Juli s.d Juni)
--    - urutan_bulan        : Urutan bulan akademik (1 s.d 12)
--    - pekan_ke            : Nomor urut pekan dalam bulan (1 s.d 6)
--    - jam_tatap_muka      : Alokasi JP pada pekan tersebut (contoh: 2 JP)
--    - keterangan          : Keterangan aktivitas (KBM, Ujian, Proyek)
--
-- 27. TABEL TITIK_KOORDINAT_SEKOLAH (MASTER LOKASI GEOFENCING SEKOLAH/KAMPUS):
--    - id                  : ID unik titik lokasi (Primary Key, Auto Increment)
--    - nama_lokasi         : Nama titik / gedung sekolah
--    - latitude            : Titik koordinat latitude (lintang) desimal GPS
--    - longitude           : Titik koordinat longitude (bujur) desimal GPS
--    - radius_meter        : Radius toleransi geofencing dalam meter (contoh: 50m / 100m)
--    - alamat              : Alamat fisik sekolah
--    - is_active           : Status keaktifan titik lokasi
--
-- 28. TABEL JADWAL_PELAJARAN (JADWAL PERTEMUAN KBM - MODUL AJAR & PEKAN/HARI EFEKTIF):
--    - id                  : ID unik jadwal KBM (Primary Key, Auto Increment)
--    - id_kelas_mata_pelajaran: Relasi ke kelas_mata_pelajaran(id)
--    - id_guru             : Relasi ke users(id) guru pengampu
--    - id_modul_ajar       : Relasi ke modul_ajar(id) pertemuan ini
--    - id_hari_efektif     : Relasi ke kalender hari_efektif(id)
--    - id_pekan_efektif    : Relasi ke rincian pekan_efektif(id)
--    - id_titik_sekolah    : Relasi ke titik_koordinat_sekolah(id) acuan lokasi KBM
--    - pertemuan_ke        : Nomor pertemuan KBM (1, 2, 3...)
--    - hari                : Hari KBM (Senin s.d Minggu)
--    - tanggal             : Tanggal KBM (YYYY-MM-DD)
--    - jam_mulai/selesai   : Waktu pelaksanaan KBM (TIME)
--    - alokasi_jp          : Jumlah JP (misal: 2 JP)
--    - ruangan             : Lokasi kelas / ruang KBM
--    - status_jadwal       : Status jadwal (Terjadwal, Berlangsung, Selesai, Dibatalkan, Diganti)
--
-- 29. TABEL PRESENSI (MASTER KODE VALIDASI PRESENSI SISWA PER KELAS MAPEL):
--    - id                  : ID auto increment (Primary Key)
--    - id_presensi         : Kode acak/kombinasi NISN + angka random unik untuk validasi presensi (contoh: 492401010245073302)
--    - id_siswa            : Relasi ke siswa(id) / id_mahasiswa
--    - id_kelas_mata_pelajaran: Relasi ke kelas_mata_pelajaran(id) / id_kelas_matkul
--    - tanggal_created     : Tanggal & waktu pembuatan kode presensi
--    - expired_at          : Waktu kedaluwarsa masa berlaku kode presensi
--    - status              : Status validasi kepesertaan (aktif / nonaktif)
--
-- 30. TABEL PERIZINAN (PENGAJUAN IZIN DUAL APPROVAL: KETUA KELAS -> GURU -> SYNC ABSENSI):
--    - id                  : ID unik perizinan (Primary Key, Auto Increment)
--    - id_siswa            : Relasi ke siswa(id) pemohon izin
--    - id_kelas_mata_pelajaran: Relasi ke kelas_mata_pelajaran(id)
--    - id_jadwal           : Relasi ke jadwal_pelajaran(id)
--    - id_modul_ajar       : Relasi ke modul_ajar(id)
--    - pertemuan_ke        : Pertemuan ke berapa izin diajukan
--    - tanggal_izin        : Tanggal izin (YYYY-MM-DD)
--    - jenis_izin          : Kategori (Izin / Sakit)
--    - alasan              : Deskripsi alasan perizinan
--    - bukti_perizinan     : File lampiran dokumen bukti izin (pdf/jpg/png)
--    - latitude            : Latitude GPS saat izin diajukan
--    - longitude           : Longitude GPS saat izin diajukan
--    - approval_ketua_kelas: Status persetujuan ketua kelas (Pending, Approved, Rejected)
--    - id_ketua_kelas      : Relasi ke users(id) akun ketua kelas
--    - approval_guru       : Status persetujuan guru pengampu (Pending, Approved, Rejected)
--    - id_guru             : Relasi ke users(id) guru pengampu
--    - status_perizinan    : Status akumulatif (Menunggu Ketua Kelas, Menunggu Guru, Disetujui, Ditolak)
--    - is_sync_to_absensi  : Flag 1 jika otomatis tersinkron ke tabel absensi (status I/S)
--    - id_absensi          : Relasi ke absensi(id)
--
-- 31. TABEL ABSENSI (LOG TRANSAKSI KEHADIRAN SISWA PER PERTEMUAN: SISWA, KELAS MAPEL & GPS):
--    - id                  : ID unik absensi (Primary Key, Auto Increment)
--    - id_siswa            : Relasi ke siswa(id) / id_mahasiswa
--    - id_kelas_mata_pelajaran: Relasi ke kelas_mata_pelajaran(id) / id_kelas_matkul
--    - id_jadwal           : Relasi ke jadwal_pelajaran(id)
--    - id_modul_ajar       : Relasi ke modul_ajar(id)
--    - id_titik_sekolah    : Relasi ke titik_koordinat_sekolah acuan radius
--    - pertemuan_ke        : Pertemuan ke berapa KBM
--    - status              : Status kehadiran (H: Hadir, I: Izin, S: Sakit, A: Alpha, T: Terlambat)
--    - latitude            : Latitude GPS saat siswa melakukan absensi
--    - longitude           : Longitude GPS saat siswa melakukan absensi
--    - jarak_meter         : Jarak fisik siswa ke titik koordinat sekolah (geofence check)
--    - waktu_absen         : Waktu persis siswa melakukan absensi
--    - id_perizinan        : Relasi ke perizinan(id) jika status berasal dari izin I / S
--    - metode_absen        : Metode (Scan QR & GPS, Manual Guru, Manual Ketua Kelas, Sistem Perizinan)
--
-- 32. TABEL TUGAS (MASTER PENUGASAN GURU BERBASIS MODUL AJAR & ITP):
--    - id                  : ID unik penugasan (Primary Key, Auto Increment)
--    - id_kelas_mata_pelajaran: Relasi ke kelas_mata_pelajaran(id) / id_kelas_matkul
--    - id_guru             : Relasi ke users(id) guru pembuat tugas
--    - id_modul_ajar       : Relasi ke modul_ajar(id) pertemuan terkait
--    - id_tp               : Relasi ke tp(id)
--    - id_itp              : Relasi ke itp(id) (indikator ketercapaian tujuan pembelajaran)
--    - id_jadwal           : Relasi ke jadwal_pelajaran(id)
--    - pertemuan_ke        : Pertemuan ke berapa KBM
--    - judul_tugas         : Judul tugas KBM
--    - deskripsi_instruksi : Petunjuk teknis dan instruksi pengerjaan tugas
--    - jenis_penugasan     : Kategori tugas: Individu atau Kelompok
--    - tipe_pengumpulan    : Tipe pengumpulan (File Upload, Link URL, File atau Link, Teks / Essay)
--    - format_file_diizinkan: Format file ekstensi yang diperbolehkan (pdf, docx, zip)
--    - file_lampiran_tugas : Berkas materi / LKPD panduan tugas dari guru (pdf/docx)
--    - link_sumber_tugas   : Link referensi pendukung pengerjaan tugas (GDrive, Youtube, dll)
--    - nilai_maksimal      : Batas skor nilai maksimal (default 100.00)
--    - bobot_nilai         : Bobot persentase tugas terhadap nilai akhir
--    - tanggal_mulai       : Waktu tugas mulai dibuka untuk siswa
--    - tenggat_waktu       : Batas akhir waktu pengumpulan (deadline)
--    - status_tugas        : Status tugas (Draft, Dibuka, Ditutup)
--
-- 33. TABEL TUGAS_KELOMPOK (MASTER PEMBAGIAN KELOMPOK PENUGASAN):
--    - id                  : ID unik kelompok tugas (Primary Key, Auto Increment)
--    - id_tugas            : Relasi ke tugas(id)
--    - id_kelas_mata_pelajaran: Relasi ke kelas_mata_pelajaran(id)
--    - nomor_kelompok      : Nomor urut kelompok (1, 2, 3...)
--    - nama_kelompok       : Nama kelompok (contoh: Kelompok 1 - Abu Bakar)
--    - id_ketua_kelompok   : Relasi ke siswa(id) koordinator/ketua kelompok
--
-- 34. TABEL TUGAS_KELOMPOK_ANGGOTA (RINCIAN ANGGOTA KELOMPOK SISWA):
--    - id                  : ID unik anggota kelompok (Primary Key, Auto Increment)
--    - id_tugas_kelompok   : Relasi ke tugas_kelompok(id)
--    - id_siswa            : Relasi ke siswa(id) / id_mahasiswa anggota
--    - id_kelas_mata_pelajaran: Relasi ke kelas_mata_pelajaran(id)
--
-- 35. TABEL PENGUMPULAN_TUGAS (LOG PENGUMPULAN TUGAS SISWA - FILE / LINK / TEKS):
--    - id                  : ID unik pengumpulan tugas (Primary Key, Auto Increment)
--    - id_tugas            : Relasi ke tugas(id)
--    - id_siswa            : Relasi ke siswa(id) / id_mahasiswa pengumpul tugas
--    - id_kelas_mata_pelajaran: Relasi ke kelas_mata_pelajaran(id) / id_kelas_matkul
--    - id_tugas_kelompok   : Relasi ke tugas_kelompok(id) jika penugasan kelompok
--    - tipe_pengumpulan    : Metode pengumpulan yang digunakan (File Upload, Link URL, File dan Link, Teks)
--    - file_tugas          : Path file berkas upload (PDF, Docx, Zip)
--    - file_nama_asli      : Nama file asli saat diunggah
--    - file_ukuran_kb      : Ukuran berkas dalam KB
--    - link_tugas          : Tautan URL tugas (Google Drive, Docs, Figma, Video, dll)
--    - catatan_siswa       : Ringkasan teks jawaban essay atau catatan pengantar siswa
--    - waktu_pengumpulan   : Waktu persis saat tugas diserahkan
--    - status_pengumpulan  : Status (Diserahkan, Terlambat, Draft, Menunggu Revisi)
--    - is_terlambat        : Flag 1 jika diserahkan setelah tenggat_waktu
--    - revisi_ke           : Versi revisi pengumpulan tugas
--
-- 36. TABEL NILAI_TUGAS (PENILAIAN GURU BERDASARKAN PENGUMPULAN & EVALUASI ITP):
--    - id                  : ID unik penilaian tugas (Primary Key, Auto Increment)
--    - id_tugas            : Relasi ke tugas(id)
--    - id_pengumpulan_tugas: Relasi ke pengumpulan_tugas(id) yang dinilai
--    - id_siswa            : Relasi ke siswa(id) / id_mahasiswa yang dinilai
--    - id_kelas_mata_pelajaran: Relasi ke kelas_mata_pelajaran(id) / id_kelas_matkul
--    - id_guru             : Relasi ke users(id) guru penilai
--    - nilai               : Skor angka nilai tugas (contoh: 88.50 dari 100.00)
--    - predikat            : Huruf mutu predikat nilai (A, B, C, D, E)
--    - aspek_itp_ketercapaian: Evaluasi ketercapaian Indikator Tujuan Pembelajaran (ITP)
--    - catatan_guru        : Catatan evaluasi, umpan balik (feedback) dan saran perbaikan dari guru
--    - status_penilaian    : Status hasil penilaian (Dinilai, Perlu Revisi, Tuntas)
--    - waktu_penilaian     : Waktu guru memberikan penilaian
--
-- 37. TABEL ASESMEN_DIAGNOSTIK (MASTER PEMETAAN KESIAPAN BELAJAR KOGNITIF & NON-KOGNITIF):
--    - id                  : ID unik asesmen diagnostik (Primary Key, Auto Increment)
--    - id_kelas_mata_pelajaran: Relasi ke kelas_mata_pelajaran(id) / id_kelas_matkul
--    - id_guru             : Relasi ke users(id) guru penyusun
--    - id_modul_ajar       : Relasi ke modul_ajar(id)
--    - id_cp, id_tp, id_atp, id_itp: Relasi hirarki kurikulum merdeka
--    - pertemuan_ke        : Pertemuan ke berapa KBM
--    - jenis_diagnostik    : Kategori diagnostik (Kognitif / Non-Kognitif)
--
-- 38. TABEL ASESMEN_DIAGNOSTIK_SOAL (BUTIR SOAL ASESMEN DIAGNOSTIK PER PERTEMUAN KBM):
--    - id                  : ID unik butir soal diagnostik (Primary Key, Auto Increment)
--    - id_asesmen_diagnostik: Relasi ke master asesmen_diagnostik(id)
--    - id_kelas_mata_pelajaran, id_modul_ajar, pertemuan_ke, id_cp, id_tp, id_atp, id_itp: Relasi kurikulum
--    - nomor_soal          : Nomor urut butir soal (1, 2, 3...)
--    - tipe_soal           : Pilihan Ganda, PG Kompleks, Essay, Benar Salah, Menjodohkan
--    - pertanyaan          : Naskah pertanyaan / stimulus soal
--    - bobot_skor          : Bobot skor soal
--
-- 39. TABEL ASESMEN_DIAGNOSTIK_SOAL_OPSI (KUNCI JAWABAN & OPSI PG ASESMEN DIAGNOSTIK):
--    - id                  : ID unik opsi pilihan (Primary Key, Auto Increment)
--    - id_soal             : Relasi ke asesmen_diagnostik_soal(id)
--    - kode_opsi           : Pilihan huruf (A, B, C, D, E)
--    - teks_opsi           : Teks pilihan jawaban
--    - is_kunci_jawaban    : Flag 1 jika kunci jawaban benar
--    - penjelasan_kunci    : Pembahasan ilmiah kunci jawaban
--
-- 40. TABEL ASESMEN_DIAGNOSTIK_SOAL_RUBRIK (RUBRIK PENILAIAN 1 SOAL 1 RUBRIK DIAGNOSTIK):
--    - id                  : ID unik rubrik diagnostik (Primary Key, Auto Increment)
--    - id_soal             : Relasi unik 1 soal 1 rubrik ke asesmen_diagnostik_soal(id)
--    - id_itp              : Relasi ke itp(id) indikator yang diukur
--    - aspek_kompetensi    : Aspek kompetensi yang dinilai
--    - pedoman_penskoran   : Panduan penskoran guru
--    - kriteria_skor_maksimal / sedang / minimal / nol: Kriteria deskriptor penskoran
--
-- 41. TABEL NILAI_ASESMEN_DIAGNOSTIK (LOG NILAI DIAGNOSTIK PER MAHASISWA/SISWA PER PERTEMUAN):
--    - id                  : ID unik nilai diagnostik (Primary Key, Auto Increment)
--    - id_siswa            : Relasi ke siswa(id) / id_mahasiswa yang dinilai
--    - id_kelas_mata_pelajaran: Relasi ke kelas_mata_pelajaran(id) / id_kelas_matkul
--    - id_asesmen_diagnostik: Relasi ke master asesmen_diagnostik(id)
--    - pertemuan_ke        : Pertemuan ke berapa KBM
--    - nilai_angka         : Skor angka diagnostik (0.00 s.d 100.00)
--    - status_tuntas       : Status (Siap Belajar, Perlu Pendampingan Khusus, Perlu Penyesuaian Materi)
--
-- 42. TABEL ASESMEN_FORMATIF (MASTER EVALUASI PROSES KBM & UMPAN BALIK PEMBELAJARAN):
--    - id                  : ID unik asesmen formatif (Primary Key, Auto Increment)
--    - id_kelas_mata_pelajaran, id_modul_ajar, id_cp, id_tp, id_atp, id_itp: Relasi kurikulum
--    - pertemuan_ke        : Pertemuan ke berapa KBM
--    - teknik_asesmen      : Teknik (Tes Tertulis, Kuis Lisan, Observasi Kinerja, dll)
--
-- 43. TABEL ASESMEN_FORMATIF_SOAL (BUTIR SOAL ASESMEN FORMATIF PER PERTEMUAN KBM):
--    - id                  : ID unik butir soal formatif (Primary Key, Auto Increment)
--    - id_asesmen_formatif : Relasi ke master asesmen_formatif(id)
--    - id_kelas_mata_pelajaran, id_modul_ajar, pertemuan_ke, id_cp, id_tp, id_atp, id_itp: Relasi kurikulum
--    - nomor_soal, tipe_soal, pertanyaan, bobot_skor
--
-- 44. TABEL ASESMEN_FORMATIF_SOAL_OPSI (KUNCI JAWABAN & OPSI PG ASESMEN FORMATIF):
--    - id                  : ID unik opsi formatif (Primary Key, Auto Increment)
--    - id_soal             : Relasi ke asesmen_formatif_soal(id)
--    - kode_opsi, teks_opsi, is_kunci_jawaban, penjelasan_kunci, bobot_nilai_opsi
--
-- 45. TABEL ASESMEN_FORMATIF_SOAL_RUBRIK (RUBRIK PENILAIAN 1 SOAL 1 RUBRIK FORMATIF):
--    - id                  : ID unik rubrik formatif (Primary Key, Auto Increment)
--    - id_soal             : Relasi unik 1 soal 1 rubrik ke asesmen_formatif_soal(id)
--    - id_itp, aspek_kompetensi, pedoman_penskoran, kriteria_skor_maksimal/sedang/minimal/nol
--
-- 46. TABEL AKSES_ASESMEN_FORMATIF (MASTER ID UNIK & KREDENSIAL LOGIN SISWA DI APLIKASI UJIAN FORMATIF):
--    - id                  : ID unik akses ujian (Primary Key, Auto Increment)
--    - id_asesmen_formatif : Relasi ke master asesmen_formatif(id)
--    - id_siswa            : Relasi ke siswa(id) peserta ujian
--    - id_kelas_mata_pelajaran: Relasi ke kelas_mata_pelajaran(id)
--    - nomor_peserta       : Nomor kartu peserta ujian (contoh: FMT-2026-X1-001)
--    - username_ujian      : Username unik login siswa saat pengerjaan di aplikasi terpisah
--    - password_ujian      : Kata sandi / PIN unik akses pengerjaan soal
--    - token_sesi          : Token sesi pengerjaan ujian
--    - status_akses        : Status (Belum Mulai, Sedang Mengerjakan, Selesai, Terkunci)
--
-- 47. TABEL NILAI_ASESMEN_FORMATIF (LOG NILAI FORMATIF PER MAHASISWA/SISWA PER PERTEMUAN):
--    - id                  : ID unik nilai formatif (Primary Key, Auto Increment)
--    - id_siswa            : Relasi ke siswa(id) / id_mahasiswa
--    - id_kelas_mata_pelajaran, id_asesmen_formatif, pertemuan_ke
--    - nilai_angka, nilai_kktp, predikat, status_tuntas, catatan_evaluasi
--
-- 48. TABEL ASESMEN_SUMATIF (MASTER UJI KETERCAPAIAN TUJUAN PEMBELAJARAN: LM, STS, ASAS):
--    - id                  : ID unik asesmen sumatif (Primary Key, Auto Increment)
--    - jenis_sumatif       : Kategori sumatif (Sumatif Lingkup Materi, STS, ASAS)
--    - kktp                : Kriteria Ketercapaian Tujuan Pembelajaran (KKTP minimal)
--    - durasi_menit        : Alokasi waktu ujian dalam menit
--
-- 49. TABEL ASESMEN_SUMATIF_SOAL (BUTIR SOAL ASESMEN SUMATIF PER PERTEMUAN KBM):
--    - id                  : ID unik butir soal sumatif (Primary Key, Auto Increment)
--    - id_asesmen_sumatif  : Relasi ke master asesmen_sumatif(id)
--    - id_kelas_mata_pelajaran, id_modul_ajar, pertemuan_ke, id_cp, id_tp, id_atp, id_itp: Relasi kurikulum
--    - nomor_soal, tipe_soal, pertanyaan, bobot_skor
--
-- 50. TABEL ASESMEN_SUMATIF_SOAL_OPSI (KUNCI JAWABAN & OPSI PG ASESMEN SUMATIF):
--    - id                  : ID unik opsi sumatif (Primary Key, Auto Increment)
--    - id_soal             : Relasi ke asesmen_sumatif_soal(id)
--    - kode_opsi, teks_opsi, is_kunci_jawaban, penjelasan_kunci, bobot_nilai_opsi
--
-- 51. TABEL ASESMEN_SUMATIF_SOAL_RUBRIK (RUBRIK PENILAIAN 1 SOAL 1 RUBRIK SUMATIF):
--    - id                  : ID unik rubrik sumatif (Primary Key, Auto Increment)
--    - id_soal             : Relasi unik 1 soal 1 rubrik ke asesmen_sumatif_soal(id)
--    - id_itp, aspek_kompetensi, pedoman_penskoran, kriteria_skor_maksimal/sedang/minimal/nol
--
-- 52. TABEL AKSES_ASESMEN_SUMATIF (MASTER ID UNIK & KREDENSIAL LOGIN SISWA DI APLIKASI UJIAN SUMATIF):
--    - id                  : ID unik akses ujian (Primary Key, Auto Increment)
--    - id_asesmen_sumatif  : Relasi ke master asesmen_sumatif(id)
--    - id_siswa            : Relasi ke siswa(id) peserta ujian
--    - id_kelas_mata_pelajaran: Relasi ke kelas_mata_pelajaran(id)
--    - nomor_peserta       : Nomor kartu peserta ujian CBT (contoh: SMT-2026-X1-001)
--    - username_ujian      : Username unik login siswa saat pengerjaan di aplikasi CBT terpisah
--    - password_ujian      : Kata sandi / PIN unik akses pengerjaan soal CBT
--    - token_sesi          : Token sesi ujian dari pengawas/proktor
--    - status_akses        : Status (Belum Mulai, Sedang Mengerjakan, Selesai, Terkunci)
--
-- 52b. TABEL ABSENSI_ASESMEN_SUMATIF (PRESENSI WAJIB SEBELUM MENGERJAKAN ASESMEN SUMATIF):
--    - id                  : ID unik absensi sumatif (Primary Key, Auto Increment)
--    - id_asesmen_sumatif  : Relasi ke master asesmen_sumatif(id)
--    - nomor_peserta       : Nomor peserta ujian (diambil dari scan QR kartu atau input manual)
--    - id_siswa            : Relasi ke siswa(id) hasil pencarian dari nomor peserta
--    - id_kelas_mata_pelajaran: Relasi ke kelas_mata_pelajaran(id)
--    - status_kehadiran    : Status kehadiran (Hadir, Izin, Sakit, Alpha, Terlambat)
--    - metode_absen        : Metode absensi ('Scan QR', 'Manual')
--    - waktu_absen         : Waktu absensi dicatat
--    - diabsen_oleh        : Relasi ke users(id) guru atau admin yang memproses absensi
--    - keterangan          : Catatan tambahan / dispensasi
--
-- 53. TABEL NILAI_ASESMEN_SUMATIF (LOG NILAI SUMATIF PER MAHASISWA/SISWA PER PERTEMUAN):
--    - id                  : ID unik nilai sumatif (Primary Key, Auto Increment)
--    - id_siswa            : Relasi ke siswa(id) / id_mahasiswa
--    - id_kelas_mata_pelajaran, id_asesmen_sumatif, pertemuan_ke
--    - nilai_angka, nilai_kktp, predikat, status_tuntas, catatan_evaluasi
--
-- 54. TABEL LKPD (MASTER LEMBAR KERJA PESERTA DIDIK PER PERTEMUAN KBM):
--    - id                  : ID unik LKPD (Primary Key, Auto Increment)
--    - nomor_lkpd          : Kode unik lembar kerja (contoh: LKPD-01)
--    - judul_lkpd          : Judul lembar aktivitas belajar murid
--    - alokasi_waktu_menit : Waktu pengerjaan aktivitas lembar kerja
--
-- 55. TABEL LKPD_SOAL (BUTIR SOAL & LEMBAR AKTIVITAS LKPD PER PERTEMUAN KBM):
--    - id                  : ID unik butir soal LKPD (Primary Key, Auto Increment)
--    - id_lkpd             : Relasi ke master lkpd(id)
--    - id_kelas_mata_pelajaran, id_modul_ajar, pertemuan_ke, id_cp, id_tp, id_atp, id_itp: Relasi kurikulum
--    - nomor_soal, tipe_soal, pertanyaan, bobot_skor
--
-- 56. TABEL LKPD_SOAL_OPSI (KUNCI JAWABAN & OPSI PG LKPD):
--    - id                  : ID unik opsi LKPD (Primary Key, Auto Increment)
--    - id_soal             : Relasi ke lkpd_soal(id)
--    - kode_opsi, teks_opsi, is_kunci_jawaban, penjelasan_kunci, bobot_nilai_opsi
--
-- 57. TABEL LKPD_SOAL_RUBRIK (RUBRIK PENILAIAN 1 SOAL 1 RUBRIK LKPD):
--    - id                  : ID unik rubrik LKPD (Primary Key, Auto Increment)
--    - id_soal             : Relasi unik 1 soal 1 rubrik ke lkpd_soal(id)
--    - id_itp, aspek_kompetensi, pedoman_penskoran, kriteria_skor_maksimal/sedang/minimal/nol
--
-- 58. TABEL NILAI_LKPD (LOG NILAI LKPD PER MAHASISWA/SISWA PER PERTEMUAN):
--    - id                  : ID unik nilai LKPD (Primary Key, Auto Increment)
--    - id_siswa            : Relasi ke siswa(id) / id_mahasiswa
--    - id_kelas_mata_pelajaran, id_lkpd, pertemuan_ke
--    - nilai_angka, nilai_kktp, predikat, status_tuntas, catatan_evaluasi
--
-- 59. TABEL PENGAYAAN_REMEDIAL (MASTER PROGRAM TINDAK LANJUT KETUNTASAN ASESMEN):
--    - id                  : ID unik program tindak lanjut (Primary Key, Auto Increment)
--    - kategori            : Jenis program (Pengayaan / Remedial)
--    - kktp_minimal        : Batas standar kompetensi minimal
--    - bentuk_kegiatan     : Bimbingan Perorangan, Tutor Sebaya, Penugasan Khusus, Proyek Mini
--
-- 60. TABEL PENGAYAAN_REMEDIAL_SOAL (BUTIR SOAL / TUGAS PENGAYAAN & REMEDIAL PER PERTEMUAN):
--    - id                  : ID unik butir soal tindak lanjut (Primary Key, Auto Increment)
--    - id_pengayaan_remedial: Relasi ke master pengayaan_remedial(id)
--    - id_kelas_mata_pelajaran, id_modul_ajar, pertemuan_ke, id_cp, id_tp, id_atp, id_itp: Relasi kurikulum
--    - nomor_soal, tipe_soal, pertanyaan, bobot_skor
--
-- 61. TABEL PENGAYAAN_REMEDIAL_SOAL_OPSI (KUNCI JAWABAN & OPSI PG PENGAYAAN REMEDIAL):
--    - id                  : ID unik opsi tindak lanjut (Primary Key, Auto Increment)
--    - id_soal             : Relasi ke pengayaan_remedial_soal(id)
--    - kode_opsi, teks_opsi, is_kunci_jawaban, penjelasan_kunci, bobot_nilai_opsi
--
-- 62. TABEL PENGAYAAN_REMEDIAL_SOAL_RUBRIK (RUBRIK PENILAIAN 1 SOAL 1 RUBRIK PENGAYAAN REMEDIAL):
--    - id                  : ID unik rubrik tindak lanjut (Primary Key, Auto Increment)
--    - id_soal             : Relasi unik 1 soal 1 rubrik ke pengayaan_remedial_soal(id)
--    - id_itp, aspek_kompetensi, pedoman_penskoran, kriteria_skor_maksimal/sedang/minimal/nol
--
-- 63. TABEL NILAI_PENGAYAAN_REMEDIAL (LOG NILAI TINDAK LANJUT PER MAHASISWA/SISWA):
--    - id                  : ID unik nilai tindak lanjut (Primary Key, Auto Increment)
--    - id_siswa            : Relasi ke siswa(id) / id_mahasiswa
--    - id_kelas_mata_pelajaran, id_pengayaan_remedial, pertemuan_ke
--    - nilai_awal, nilai_akhir, nilai_kktp, predikat, status_tuntas, catatan_evaluasi
--
-- 64. TABEL REKAP_NILAI_SISWA_MAPEL (REKAPITULASI RAPOR 1 SISWA 1 MAPEL KELAS):
--    - id                  : ID unik rekapitulasi (Primary Key, Auto Increment)
--    - id_siswa            : Relasi ke siswa(id) / id_mahasiswa
--    - id_kelas_mata_pelajaran: Relasi ke kelas_mata_pelajaran(id) / id_kelas_matkul
--    - rata_diagnostik, rata_formatif, rata_lkpd, rata_sumatif_materi, nilai_sts, nilai_asas, nilai_proyek
--    - nilai_akhir_semester: Nilai rapor akhir semester
--    - predikat_akhir      : Predikat kelulusan (A, B, C, D)
--    - status_kelulusan_kktp: Tercapai / Belum Tercapai
--    - deskripsi_capaian_tertinggi & perlu_bimbingan: Deskripsi rapor narasi Kurikulum Merdeka
--
-- 65. TABEL PROYEK (MASTER PROYEK PEMBELAJARAN PjBL / P5: INDIVIDU & KELOMPOK):
--    - id                  : ID unik proyek (Primary Key, Auto Increment)
--    - id_kelas_mata_pelajaran: Relasi ke kelas_mata_pelajaran(id) / id_kelas_matkul
--    - id_guru             : Relasi ke users(id) guru pembimbing
--    - id_modul_ajar, id_tp, id_itp: Relasi kurikulum modul ajar
--    - nama_proyek         : Nama/judul proyek
--    - tema_proyek         : Tema P5 / proyek Kurikulum Merdeka
--    - jenis_proyek        : Kategori pengerjaan (Individu / Kelompok)
--    - dimensi_profil_pancasila: Karakter P3 yang dikembangkan
--    - target_luaran       : Wujud produk akhir (Film Pendek, Aplikasi, Maket, Laporan Ilmiah, Gelar Karya)
--    - jadwal_mulai / selesai: Linimasa pengerjaan proyek
--
-- 66. TABEL PROYEK_KELOMPOK (MASTER KELOMPOK PROYEK PEMBELAJARAN):
--    - id                  : ID unik kelompok proyek (Primary Key, Auto Increment)
--    - id_proyek           : Relasi ke proyek(id)
--    - id_kelas_mata_pelajaran: Relasi ke kelas_mata_pelajaran(id)
--    - nama_kelompok       : Nama tim proyek
--    - id_ketua_kelompok   : Relasi ke siswa(id) ketua tim
--
-- 67. TABEL PROYEK_KELOMPOK_ANGGOTA (RINCIAN MAHASISWA/SISWA ANGGOTA KELOMPOK PROYEK):
--    - id                  : ID unik relasi anggota tim (Primary Key, Auto Increment)
--    - id_proyek_kelompok  : Relasi ke proyek_kelompok(id)
--    - id_siswa            : Relasi ke siswa(id) / id_mahasiswa anggota
--    - peran_anggota       : Peran dalam tim (Sutradara, Editor, Presenter, Bendahara, Riset)
--
-- 68. TABEL PENGUMPULAN_PROYEK (LOG PENYERAHAN PRODUK, LAPORAN & VIDEO GELAR KARYA):
--    - id                  : ID unik penyerahan proyek (Primary Key, Auto Increment)
--    - id_proyek           : Relasi ke proyek(id)
--    - id_siswa            : Relasi ke siswa(id) / id_mahasiswa pengumpul
--    - id_kelas_mata_pelajaran: Relasi ke kelas_mata_pelajaran(id)
--    - id_proyek_kelompok  : Relasi ke proyek_kelompok(id) jika tim
--    - file_laporan        : Dokumen laporan akhir proyek PDF
--    - link_produk         : Tautan hasil karya (Youtube, Canva, Google Drive, GitHub)
--    - nilai_proyek        : Skor penilaian akhir proyek dari guru
--    - predikat_proyek     : Capaian P5 (Sangat Berkembang / Berkembang Sesuai Harapan / Mulai / Belum)
--
-- 69. TABEL BIAYA_PROYEK (RINCIAN ANGGARAN & BIAYA PENGELUARAN SELAMA MENGERJAKAN PROYEK):
--    - id                  : ID unik pengeluaran biaya (Primary Key, Auto Increment)
--    - id_proyek           : Relasi ke proyek(id)
--    - id_pengumpulan_proyek: Relasi ke pengumpulan_proyek(id)
--    - id_proyek_kelompok  : Relasi ke proyek_kelompok(id)
--    - id_siswa            : Relasi ke siswa(id) / id_mahasiswa pencatat / bendahara biaya
--    - id_kelas_mata_pelajaran: Relasi ke kelas_mata_pelajaran(id)
--    - tanggal_pengeluaran : Tanggal pengeluaran transaksi
--    - kategori_biaya      : Pos anggaran (Bahan Baku, Percetakan, Sewa Perangkat, Transportasi, Konsumsi)
--    - nama_item_biaya     : Nama barang / jasa yang dibeli
--    - kuantitas & satuan  : Jumlah unit dan satuan barang
--    - harga_satuan        : Harga per satuan (Rp)
--    - total_biaya         : Total pengeluaran (kuantitas x harga satuan)
--    - bukti_transaksi     : File foto struk / nota / kwitansi belanja
--    - sumber_dana         : Iuran Mandiri Siswa, BOS / Sekolah, Kas Kelas, Sponsor
--    - status_verifikasi   : Status verifikasi transparansi pengeluaran oleh guru (Diverifikasi Guru / Menunggu / Ditolak)
-- ==============================================================================

SET FOREIGN_KEY_CHECKS = 0;

-- ------------------------------------------------------------------------------
-- 1. STRUKTUR TABEL KELAS
-- ------------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `kelas` (
  `id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `nama_kelas` VARCHAR(100) NOT NULL,
  `kode_kelas` VARCHAR(50) DEFAULT NULL COMMENT 'Kode acak berformat (kelas)(angkatan)-id(random) untuk referensi Sheet 2 Excel',
  `tahun_pelajaran` VARCHAR(30) NOT NULL DEFAULT '2026/2027',
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_kelas_kode` (`kode_kelas`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------------------
-- 2. STRUKTUR TABEL SISWA
-- ------------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `siswa` (
  `id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `id_kelas` INT(11) UNSIGNED NOT NULL,
  `nisn` VARCHAR(30) NOT NULL,
  `nis` VARCHAR(30) NOT NULL,
  `nama_lengkap` VARCHAR(150) NOT NULL,
  `alamat` TEXT DEFAULT NULL,
  `no_hp` VARCHAR(25) DEFAULT NULL,
  `gmail` VARCHAR(150) DEFAULT NULL,
  `foto` VARCHAR(255) DEFAULT NULL,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_siswa_kelas` (`id_kelas`),
  CONSTRAINT `fk_siswa_kelas` FOREIGN KEY (`id_kelas`) REFERENCES `kelas` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------------------
-- 3. STRUKTUR TABEL USER
-- ------------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `users` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `username` VARCHAR(100) NOT NULL UNIQUE,
  `email` VARCHAR(150) NOT NULL UNIQUE,
  `password_hash` VARCHAR(255) NOT NULL,
  `full_name` VARCHAR(150) NOT NULL,
  `nisn_nip` VARCHAR(50) DEFAULT NULL,
  `gender` VARCHAR(20) DEFAULT 'L',
  `phone` VARCHAR(30) DEFAULT NULL,
  `avatar` VARCHAR(255) DEFAULT NULL,
  `status` VARCHAR(20) DEFAULT 'active',
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------------------
-- 4. STRUKTUR TABEL ROLES
-- ------------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `roles` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `code` VARCHAR(50) NOT NULL UNIQUE,
  `name` VARCHAR(100) NOT NULL,
  `description` TEXT DEFAULT NULL,
  `badge_color` VARCHAR(30) DEFAULT 'teal',
  `icon` VARCHAR(50) DEFAULT 'shield',
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------------------
-- 5. STRUKTUR TABEL USER_ROLES (MULTI-ROLE)
-- ------------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `user_roles` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `user_id` INT(11) NOT NULL,
  `role_id` INT(11) NOT NULL,
  `is_primary` TINYINT(1) DEFAULT 0,
  `assigned_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_ur_user` (`user_id`),
  KEY `idx_ur_roles` (`role_id`),
  CONSTRAINT `fk_ur_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_ur_roles` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------------------
-- 6. STRUKTUR TABEL GURU (MASTER DATA GURU PENGAMPU)
-- ------------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `guru` (
  `id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `id_user` INT(11) DEFAULT NULL COMMENT 'Relasi ke users(id) jika guru memiliki akun login portal',
  `nip` VARCHAR(50) DEFAULT NULL COMMENT 'Nomor Induk Pegawai (NIP)',
  `nuptk` VARCHAR(50) DEFAULT NULL COMMENT 'Nomor Unik Pendidik & Tenaga Kependidikan (NUPTK)',
  `nama_guru` VARCHAR(150) NOT NULL COMMENT 'Nama lengkap guru beserta gelar akademik',
  `kode_guru` VARCHAR(50) DEFAULT NULL COMMENT 'Kode referensi unik guru terproteksi untuk Sheet 2 Excel & ekspor',
  `jenis_kelamin` ENUM('L', 'P') NOT NULL DEFAULT 'L',
  `no_hp` VARCHAR(25) DEFAULT NULL COMMENT 'Nomor WhatsApp / Telepon',
  `email` VARCHAR(150) DEFAULT NULL COMMENT 'Alamat email aktif guru',
  `alamat` TEXT DEFAULT NULL COMMENT 'Alamat domisili guru',
  `foto` VARCHAR(255) DEFAULT NULL COMMENT 'File pas foto profil guru',
  `status_kepegawaian` VARCHAR(50) DEFAULT 'Tetap' COMMENT 'PNS / PPPK / GTT / Tetap Yayasan',
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_guru_user` (`id_user`),
  KEY `idx_guru_kode` (`kode_guru`),
  CONSTRAINT `fk_guru_user` FOREIGN KEY (`id_user`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------------------
-- 7. STRUKTUR TABEL MATA PELAJARAN
-- ------------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `mata_pelajaran` (
  `id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `nama_mata_pelajaran` VARCHAR(150) NOT NULL,
  `kode_mapel` VARCHAR(50) DEFAULT NULL COMMENT 'Kode referensi unik mata pelajaran terproteksi untuk Sheet 2 Excel',
  `tahun_pelajaran` VARCHAR(30) NOT NULL DEFAULT '2026/2027',
  `semester` ENUM('Ganjil', 'Genap') NOT NULL DEFAULT 'Ganjil',
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_mapel_kode` (`kode_mapel`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------------------
-- 7b. STRUKTUR TABEL GURU_MATA_PELAJARAN (RELASI PEMETAAN GURU PENGAMPU MATA PELAJARAN)
-- ------------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `guru_mata_pelajaran` (
  `id` BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `id_guru` INT(11) UNSIGNED NOT NULL COMMENT 'Relasi ke guru(id)',
  `id_mata_pelajaran` INT(11) UNSIGNED NOT NULL COMMENT 'Relasi ke mata_pelajaran(id)',
  `kode_relasi` VARCHAR(50) DEFAULT NULL COMMENT 'Kode unik acak relasi guru & mata pelajaran untuk Sheet 2 Excel',
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `guru_mapel_unique` (`id_guru`, `id_mata_pelajaran`),
  KEY `idx_guru` (`id_guru`),
  KEY `idx_mata_pelajaran` (`id_mata_pelajaran`),
  KEY `idx_gmp_kode_relasi` (`kode_relasi`),
  CONSTRAINT `fk_gmp_guru` FOREIGN KEY (`id_guru`) REFERENCES `guru` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_gmp_mapel` FOREIGN KEY (`id_mata_pelajaran`) REFERENCES `mata_pelajaran` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------------------
-- 8. STRUKTUR TABEL KELAS_MATA_PELAJARAN / KELAS_MAPEL (RELASI KELAS & MAPEL DENGAN GURU MAPEL)
-- ------------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `kelas_mata_pelajaran` (
  `id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `id_kelas` INT(11) UNSIGNED NOT NULL COMMENT 'Relasi ke kelas(id)',
  `id_mata_pelajaran` INT(11) UNSIGNED NOT NULL COMMENT 'Relasi ke mata_pelajaran(id)',
  `id_guru_mata_pelajaran` BIGINT(20) UNSIGNED DEFAULT NULL COMMENT 'Relasi ke guru_mata_pelajaran(id) pemetaan guru pengampu',
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_kelas_mapel` (`id_kelas`, `id_mata_pelajaran`),
  KEY `idx_kmp_kelas` (`id_kelas`),
  KEY `idx_kmp_mapel` (`id_mata_pelajaran`),
  KEY `idx_kmp_guru_mapel` (`id_guru_mata_pelajaran`),
  CONSTRAINT `fk_kmp_kelas` FOREIGN KEY (`id_kelas`) REFERENCES `kelas` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_kmp_mapel` FOREIGN KEY (`id_mata_pelajaran`) REFERENCES `mata_pelajaran` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_kmp_guru_mapel` FOREIGN KEY (`id_guru_mata_pelajaran`) REFERENCES `guru_mata_pelajaran` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------------------
-- VIEW KELAS_MAPEL (PENGHUBUNG TERPADU KELAS, MATA PELAJARAN, & GURU PENGAMPU)
-- ------------------------------------------------------------------------------
CREATE OR REPLACE VIEW `kelas_mapel` AS 
SELECT 
  kmp.id,
  kmp.id AS id_kelas_mata_pelajaran,
  kmp.id AS id_kelas_mapel,
  kmp.id_kelas,
  k.nama_kelas,
  k.tahun_pelajaran AS tahun_pelajaran_kelas,
  kmp.id_mata_pelajaran,
  mp.nama_mata_pelajaran,
  mp.semester,
  kmp.id_guru_mata_pelajaran,
  gmp.id_guru,
  g.nama_guru,
  g.nip AS nip_guru,
  kmp.created_at
FROM `kelas_mata_pelajaran` kmp
LEFT JOIN `kelas` k ON kmp.id_kelas = k.id
LEFT JOIN `mata_pelajaran` mp ON kmp.id_mata_pelajaran = mp.id
LEFT JOIN `guru_mata_pelajaran` gmp ON kmp.id_guru_mata_pelajaran = gmp.id
LEFT JOIN `guru` g ON gmp.id_guru = g.id;

-- ------------------------------------------------------------------------------
-- 8. STRUKTUR TABEL ELEMEN (ELEMEN CAPAIAN PEMBELAJARAN KURIKULUM MERDEKA)
-- ------------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `elemen` (
  `id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `id_kelas_mata_pelajaran` INT(11) UNSIGNED NOT NULL COMMENT 'Relasi ke kelas_mata_pelajaran',
  `nama_elemen` VARCHAR(100) NOT NULL COMMENT 'Al-Qur’an Hadis, Akidah, Akhlak, Fikih, Sejarah Peradaban Islam',
  `fase` VARCHAR(20) NOT NULL DEFAULT 'Fase F' COMMENT 'Jenjang fase (Fase E/F)',
  `deskripsi` TEXT DEFAULT NULL COMMENT 'Penjelasan lingkup elemen kurikulum',
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_elemen_kmp` (`id_kelas_mata_pelajaran`),
  CONSTRAINT `fk_elemen_kmp` FOREIGN KEY (`id_kelas_mata_pelajaran`) REFERENCES `kelas_mata_pelajaran` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------------------
-- 9. STRUKTUR TABEL CP (CAPAIAN PEMBELAJARAN)
-- ------------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `cp` (
  `id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `id_kelas_mata_pelajaran` INT(11) UNSIGNED NOT NULL,
  `id_elemen` INT(11) UNSIGNED DEFAULT NULL COMMENT 'Relasi ke elemen mata pelajaran',
  `cp` TEXT NOT NULL COMMENT 'Capaian Pembelajaran (CP)',
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_cp_kmp` (`id_kelas_mata_pelajaran`),
  KEY `idx_cp_elemen` (`id_elemen`),
  CONSTRAINT `fk_cp_kmp` FOREIGN KEY (`id_kelas_mata_pelajaran`) REFERENCES `kelas_mata_pelajaran` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_cp_elemen` FOREIGN KEY (`id_elemen`) REFERENCES `elemen` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------------------
-- 10. STRUKTUR TABEL TP (TUJUAN PEMBELAJARAN - BERELASI KE CP)
-- ------------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `tp` (
  `id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `id_cp` INT(11) UNSIGNED NOT NULL,
  `kode_tp` VARCHAR(50) DEFAULT NULL COMMENT 'Kode/Nomor TP (misal: 1.1, 2.1-2.3)',
  `tp` TEXT NOT NULL COMMENT 'Teks Tujuan Pembelajaran (TP)',
  `materi_pokok` VARCHAR(255) DEFAULT NULL COMMENT 'Materi pokok terkait TP (misal: Berpikir Kritis dalam Islam)',
  `alokasi_jam` INT(3) DEFAULT 4 COMMENT 'Alokasi default jam pelajaran (JP)',
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_tp_cp` (`id_cp`),
  CONSTRAINT `fk_tp_cp` FOREIGN KEY (`id_cp`) REFERENCES `cp` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------------------
-- 11. STRUKTUR TABEL ATP (ALUR TUJUAN PEMBELAJARAN - BERELASI KE TP)
-- ------------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `atp` (
  `id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `id_tp` INT(11) UNSIGNED NOT NULL,
  `atp` TEXT NOT NULL COMMENT 'Alur Tujuan Pembelajaran (ATP)',
  `urutan` INT(3) DEFAULT 1 COMMENT 'Nomor urut alur pembelajaran',
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_atp_tp` (`id_tp`),
  CONSTRAINT `fk_atp_tp` FOREIGN KEY (`id_tp`) REFERENCES `tp` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------------------
-- 12. STRUKTUR TABEL ITP (INDIKATOR TUJUAN PEMBELAJARAN / IKTP BERELASI KE TP & ATP)
-- ------------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `itp` (
  `id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `id_tp` INT(11) UNSIGNED NOT NULL COMMENT 'Relasi beruntut ke tujuan pembelajaran (tp)',
  `id_atp` INT(11) UNSIGNED DEFAULT NULL COMMENT 'Relasi opsional ke alur tujuan pembelajaran (atp)',
  `kode_itp` VARCHAR(50) DEFAULT NULL COMMENT 'Kode Indikator (misal: 1.1.1, 1.1.2)',
  `itp` TEXT NOT NULL COMMENT 'Teks Indikator Tujuan Pembelajaran (ITP / IKTP)',
  `deskripsi_ketercapaian` TEXT DEFAULT NULL COMMENT 'Kriteria / rubrik ketercapaian kompetensi',
  `urutan` INT(3) DEFAULT 1 COMMENT 'Nomor urut indikator',
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_itp_tp` (`id_tp`),
  KEY `idx_itp_atp` (`id_atp`),
  CONSTRAINT `fk_itp_tp` FOREIGN KEY (`id_tp`) REFERENCES `tp` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_itp_atp` FOREIGN KEY (`id_atp`) REFERENCES `atp` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------------------
-- 13. STRUKTUR TABEL MODEL_PEMBELAJARAN
-- ------------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `model_pembelajaran` (
  `id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `nama_model` VARCHAR(150) NOT NULL,
  `slug` VARCHAR(100) NOT NULL UNIQUE,
  `kategori` VARCHAR(100) NOT NULL DEFAULT 'Kurikulum Merdeka',
  `deskripsi` TEXT NOT NULL,
  `sintaks` LONGTEXT NOT NULL COMMENT 'Uraian sintaks langkah kerja operasional lengkap',
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------------------
-- 14. STRUKTUR TABEL SINTAKS_MODEL_PEMBELAJARAN (DETAIL AKTIVITAS GURU & SISWA)
-- ------------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `sintaks_model_pembelajaran` (
  `id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `id_model_pembelajaran` INT(11) UNSIGNED NOT NULL,
  `tahap_ke` INT(3) NOT NULL,
  `nama_fase` VARCHAR(150) NOT NULL,
  `aktivitas_guru` TEXT NOT NULL,
  `aktivitas_siswa` TEXT NOT NULL,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_smp_model` (`id_model_pembelajaran`),
  CONSTRAINT `fk_smp_model` FOREIGN KEY (`id_model_pembelajaran`) REFERENCES `model_pembelajaran` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------------------
-- 15. STRUKTUR TABEL METODE_PEMBELAJARAN
-- ------------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `metode_pembelajaran` (
  `id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `nama_metode` VARCHAR(150) NOT NULL,
  `slug` VARCHAR(100) NOT NULL UNIQUE,
  `deskripsi` TEXT NOT NULL COMMENT 'Pengertian dan karakteristik metode',
  `langkah_langkah` LONGTEXT NOT NULL COMMENT 'Langkah-langkah operasional pelaksanaan di kelas',
  `kelebihan` TEXT DEFAULT NULL COMMENT 'Keunggulan / kelebihan metode',
  `kekurangan` TEXT DEFAULT NULL COMMENT 'Keterbatasan / tantangan metode',
  `rekomendasi_penerapan` VARCHAR(255) DEFAULT NULL COMMENT 'Materi/topik yang direkomendasikan',
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------------------
-- 16. STRUKTUR TABEL MATERI (BANK MATERI PEMBELAJARAN)
-- ------------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `materi` (
  `id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `judul_materi` VARCHAR(255) NOT NULL COMMENT 'Judul materi pembelajaran',
  `slug` VARCHAR(150) NOT NULL UNIQUE COMMENT 'Slug URL materi untuk web',
  `deskripsi_singkat` TEXT DEFAULT NULL COMMENT 'Ringkasan isi materi',
  `isi_materi` LONGTEXT DEFAULT NULL COMMENT 'Naskah teks materi lengkap yang dibaca siswa',
  `tipe_materi` ENUM('Teks', 'Video', 'PDF', 'Slide', 'Link Eksternal') NOT NULL DEFAULT 'Teks' COMMENT 'Tipe media materi',
  `link_materi` VARCHAR(255) DEFAULT NULL COMMENT 'Tautan URL video YouTube / Google Drive / URL eksternal',
  `file_materi` VARCHAR(255) DEFAULT NULL COMMENT 'File unduhan materi (pdf, docx, pptx)',
  `durasi_menit` INT(4) DEFAULT 15 COMMENT 'Estimasi waktu membaca / menyimak materi dalam menit',
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_materi_tipe` (`tipe_materi`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------------------
-- 17. STRUKTUR TABEL MODUL_AJAR (PERENCANAAN KBM KURIKULUM MERDEKA)
-- ------------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `modul_ajar` (
  `id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `id_kelas_mata_pelajaran` INT(11) UNSIGNED NOT NULL COMMENT 'Relasi ke kelas_mata_pelajaran',
  `id_cp` INT(11) UNSIGNED DEFAULT NULL COMMENT 'Relasi ke Capaian Pembelajaran',
  `id_tp` INT(11) UNSIGNED DEFAULT NULL COMMENT 'Relasi ke Tujuan Pembelajaran',
  `id_atp` INT(11) UNSIGNED DEFAULT NULL COMMENT 'Relasi ke Alur Tujuan Pembelajaran',
  `id_itp` INT(11) UNSIGNED DEFAULT NULL COMMENT 'Relasi ke Indikator Tujuan Pembelajaran (ITP / IKTP)',
  `id_model_pembelajaran` INT(11) UNSIGNED DEFAULT NULL COMMENT 'Relasi ke Model Pembelajaran',
  `id_metode_pembelajaran` INT(11) UNSIGNED DEFAULT NULL COMMENT 'Relasi ke Metode Pembelajaran Utama',
  `id_materi` INT(11) UNSIGNED DEFAULT NULL COMMENT 'Relasi ke bank materi yang dipilih untuk pertemuan ini',
  `id_guru` INT(11) DEFAULT NULL COMMENT 'Relasi ke users(id) guru penyusun',
  `pertemuan_ke` INT(3) NOT NULL DEFAULT 1 COMMENT 'Pertemuan ke berapa (1, 2, 3...)',
  `judul_modul` VARCHAR(255) NOT NULL COMMENT 'Judul modul ajar / materi pokok',
  `fase` VARCHAR(10) NOT NULL DEFAULT 'Fase E' COMMENT 'Fase A/B/C/D/E/F Kurikulum Merdeka',
  `alokasi_waktu` VARCHAR(50) NOT NULL DEFAULT '2 x 45 Menit' COMMENT 'Alokasi jam pelajaran',
  `profil_pelajar_pancasila` TEXT DEFAULT NULL COMMENT 'Dimensi Profil Pelajar Pancasila & Rahmatan Lil Alamin',
  `sarana_prasarana` TEXT DEFAULT NULL COMMENT 'Media, alat peraga, dan lingkungan belajar',
  `target_peserta_didik` VARCHAR(150) DEFAULT 'Peserta Didik Reguler / Tipikal',
  `pemahaman_bermakna` TEXT DEFAULT NULL COMMENT 'Nilai esensial dan kemanfaatan materi di dunia nyata',
  `pertanyaan_pemantik` TEXT DEFAULT NULL COMMENT 'Pertanyaan pemantik nalar kritis',
  `kegiatan_pendahuluan` TEXT DEFAULT NULL COMMENT 'Apersepsi, salam, motivasi, dan penyampaian tujuan',
  `kegiatan_inti` LONGTEXT DEFAULT NULL COMMENT 'Langkah inti pembelajaran selaras sintaks model terpilih',
  `kegiatan_penutup` TEXT DEFAULT NULL COMMENT 'Rangkuman, refleksi, asesmen singkat, dan doa',
  `asesmen_diagnostik` TEXT DEFAULT NULL COMMENT 'Asesmen awal kesiapan belajar',
  `asesmen_formatif` TEXT DEFAULT NULL COMMENT 'Asesmen proses (rubrik observasi, LKPD, unjuk kerja)',
  `asesmen_sumatif` TEXT DEFAULT NULL COMMENT 'Asesmen ketercapaian kompetensi akhir',
  `pengayaan_remedial` TEXT DEFAULT NULL COMMENT 'Program tindak lanjut murid mahir dan murid yang tertinggal',
  `refleksi_guru_siswa` TEXT DEFAULT NULL COMMENT 'Evaluasi keterlaksanaan KBM pendidik dan refleksi siswa',
  `lkpd` LONGTEXT DEFAULT NULL COMMENT 'Panduan Lembar Kerja Peserta Didik',
  `bahan_bacaan` TEXT DEFAULT NULL COMMENT 'Ringkasan materi literasi guru dan murid',
  `glosarium` TEXT DEFAULT NULL COMMENT 'Daftar istilah penting',
  `daftar_pustaka` TEXT DEFAULT NULL COMMENT 'Sumber rujukan referensi buku / artikel',
  `file_lampiran` VARCHAR(255) DEFAULT NULL COMMENT 'File upload dokumen modul (pdf/docx)',
  `status` ENUM('Draft', 'Published', 'Archived') NOT NULL DEFAULT 'Published',
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_ma_kmp` (`id_kelas_mata_pelajaran`),
  KEY `idx_ma_cp` (`id_cp`),
  KEY `idx_ma_tp` (`id_tp`),
  KEY `idx_ma_atp` (`id_atp`),
  KEY `idx_ma_itp` (`id_itp`),
  KEY `idx_ma_model` (`id_model_pembelajaran`),
  KEY `idx_ma_metode` (`id_metode_pembelajaran`),
  KEY `idx_ma_materi` (`id_materi`),
  KEY `idx_ma_guru` (`id_guru`),
  CONSTRAINT `fk_ma_kmp` FOREIGN KEY (`id_kelas_mata_pelajaran`) REFERENCES `kelas_mata_pelajaran` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_ma_cp` FOREIGN KEY (`id_cp`) REFERENCES `cp` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_ma_tp` FOREIGN KEY (`id_tp`) REFERENCES `tp` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_ma_atp` FOREIGN KEY (`id_atp`) REFERENCES `atp` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_ma_itp` FOREIGN KEY (`id_itp`) REFERENCES `itp` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_ma_model` FOREIGN KEY (`id_model_pembelajaran`) REFERENCES `model_pembelajaran` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_ma_metode` FOREIGN KEY (`id_metode_pembelajaran`) REFERENCES `metode_pembelajaran` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_ma_materi` FOREIGN KEY (`id_materi`) REFERENCES `materi` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_ma_guru` FOREIGN KEY (`id_guru`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------------------
-- 18. STRUKTUR TABEL PEKAN_EFEKTIF (RINCIAN PEKAN & HARI EFEKTIF PER KELAS MAPEL - SHEET 1 EXCEL HE)
-- ------------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `pekan_efektif` (
  `id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `id_kelas_mata_pelajaran` INT(11) UNSIGNED NOT NULL COMMENT 'Relasi ke kelas_mata_pelajaran',
  `tahun_pelajaran` VARCHAR(30) NOT NULL DEFAULT '2026/2027' COMMENT 'Tahun ajaran aktif',
  `semester` ENUM('Ganjil', 'Genap') NOT NULL DEFAULT 'Ganjil' COMMENT 'Semester pembelajaran',
  `bulan` VARCHAR(30) NOT NULL COMMENT 'Nama bulan kalender akademik',
  `urutan_bulan` INT(2) NOT NULL COMMENT 'Urutan bulan akademik (1: Juli s.d 12: Juni)',
  `senin` INT(2) NOT NULL DEFAULT 0 COMMENT 'Jumlah hari Senin efektif di bulan ini',
  `selasa` INT(2) NOT NULL DEFAULT 0 COMMENT 'Jumlah hari Selasa efektif di bulan ini',
  `rabu` INT(2) NOT NULL DEFAULT 0 COMMENT 'Jumlah hari Rabu efektif di bulan ini',
  `kamis` INT(2) NOT NULL DEFAULT 0 COMMENT 'Jumlah hari Kamis efektif di bulan ini',
  `jumat` INT(2) NOT NULL DEFAULT 0 COMMENT 'Jumlah hari Jumat efektif di bulan ini',
  `sabtu` INT(2) NOT NULL DEFAULT 0 COMMENT 'Jumlah hari Sabtu efektif di bulan ini',
  `minggu` INT(2) NOT NULL DEFAULT 0 COMMENT 'Jumlah hari Minggu efektif di bulan ini',
  `total_hari_efektif` INT(3) NOT NULL DEFAULT 0 COMMENT 'Total hari efektif bulan ini (Sum Senin s.d Minggu)',
  `jumlah_pekan_total` INT(2) NOT NULL DEFAULT 4 COMMENT 'Total jumlah pekan dalam bulan (Jml Pekan di Excel)',
  `jumlah_pekan_efektif` INT(2) NOT NULL DEFAULT 4 COMMENT 'Jumlah pekan efektif KBM (Pekan Efektif di Excel)',
  `jumlah_pekan_tidak_efektif` INT(2) NOT NULL DEFAULT 0 COMMENT 'Jumlah pekan non-KBM',
  `keterangan_tidak_efektif` TEXT DEFAULT NULL COMMENT 'Rincian agenda non-efektif (MPLS, asesmen, libur)',
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_pekan_kmp_bulan` (`id_kelas_mata_pelajaran`, `tahun_pelajaran`, `semester`, `bulan`),
  KEY `idx_pekan_kmp` (`id_kelas_mata_pelajaran`),
  KEY `idx_pekan_sem` (`tahun_pelajaran`, `semester`),
  CONSTRAINT `fk_pekan_kmp` FOREIGN KEY (`id_kelas_mata_pelajaran`) REFERENCES `kelas_mata_pelajaran` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------------------
-- 19. STRUKTUR TABEL HARI_EFEKTIF (KALENDER DETAIL HARI KBM PER KELAS MAPEL)
-- ------------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `hari_efektif` (
  `id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `id_kelas_mata_pelajaran` INT(11) UNSIGNED NOT NULL COMMENT 'Relasi ke kelas_mata_pelajaran',
  `tahun_pelajaran` VARCHAR(30) NOT NULL DEFAULT '2026/2027' COMMENT 'Tahun ajaran aktif',
  `semester` ENUM('Ganjil', 'Genap') NOT NULL DEFAULT 'Ganjil' COMMENT 'Semester pembelajaran',
  `tanggal` DATE NOT NULL COMMENT 'Tanggal kalender spesifik (YYYY-MM-DD)',
  `hari` ENUM('Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu', 'Minggu') NOT NULL COMMENT 'Nama hari',
  `status_hari` ENUM('Efektif KBM', 'Libur Nasional', 'Libur Khusus Keagamaan', 'Libur Semester', 'Kegiatan Sekolah/Ujian') NOT NULL DEFAULT 'Efektif KBM' COMMENT 'Status operasional hari',
  `keterangan` VARCHAR(255) DEFAULT 'KBM Reguler' COMMENT 'Keterangan agenda hari sekolah',
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_he_kmp_tanggal` (`id_kelas_mata_pelajaran`, `tanggal`),
  KEY `idx_he_kmp` (`id_kelas_mata_pelajaran`),
  KEY `idx_he_tanggal` (`tanggal`),
  KEY `idx_he_status` (`status_hari`),
  KEY `idx_he_tp_sem` (`tahun_pelajaran`, `semester`),
  CONSTRAINT `fk_he_kmp` FOREIGN KEY (`id_kelas_mata_pelajaran`) REFERENCES `kelas_mata_pelajaran` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------------------
-- 20. STRUKTUR TABEL ALOKASI_HARI_MENGAJAR (HITUNG JTM PER HARI MENGAJAR - SHEET 2 & 3 KIRI EXCEL)
-- ------------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `alokasi_hari_mengajar` (
  `id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `id_kelas_mata_pelajaran` INT(11) UNSIGNED NOT NULL COMMENT 'Relasi ke kelas_mata_pelajaran',
  `id_guru` INT(11) DEFAULT NULL COMMENT 'Relasi ke users(id) guru pengampu',
  `semester` ENUM('Ganjil', 'Genap') NOT NULL DEFAULT 'Ganjil' COMMENT 'Semester pembelajaran',
  `hari` ENUM('Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu', 'Minggu') NOT NULL DEFAULT 'Senin' COMMENT 'Hari mengajar KBM di kelas',
  `banyaknya_hari_efektif` INT(3) NOT NULL DEFAULT 18 COMMENT 'Banyaknya Hari Efektif pada hari tersebut (misal: 18 hari)',
  `jp_per_minggu` INT(2) NOT NULL DEFAULT 2 COMMENT 'Alokasi Jam Pelajaran (JP) per minggu (contoh: 2 JP)',
  `total_jtm` INT(3) NOT NULL DEFAULT 36 COMMENT 'Total Jam Tatap Muka = Hari Efektif * JP/Minggu (18 * 2 = 36 JP)',
  `pola_pekan_mengajar` VARCHAR(255) DEFAULT NULL COMMENT 'Pola pekan KBM (contoh: Jul:00045, Agt:1234, Sept:1234, Okt:12345, Nop:1230, Des:000)',
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_ahm_kmp` (`id_kelas_mata_pelajaran`),
  KEY `idx_ahm_guru` (`id_guru`),
  KEY `idx_ahm_sem_hari` (`semester`, `hari`),
  CONSTRAINT `fk_ahm_kmp` FOREIGN KEY (`id_kelas_mata_pelajaran`) REFERENCES `kelas_mata_pelajaran` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_ahm_guru` FOREIGN KEY (`id_guru`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------------------
-- 21. STRUKTUR TABEL DISTRIBUSI_JTM_TP (DISTRIBUSI JTM KE TP & ASESMEN - SHEET 2 & 3 KANAN EXCEL)
-- ------------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `distribusi_jtm_tp` (
  `id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `id_alokasi_hari_mengajar` INT(11) UNSIGNED NOT NULL COMMENT 'Relasi ke header alokasi_hari_mengajar',
  `id_kelas_mata_pelajaran` INT(11) UNSIGNED NOT NULL COMMENT 'Relasi ke kelas_mata_pelajaran',
  `id_tp` INT(11) UNSIGNED DEFAULT NULL COMMENT 'Relasi ke tabel tp (NULL jika untuk Proyek/Ujian)',
  `id_itp` INT(11) UNSIGNED DEFAULT NULL COMMENT 'Relasi ke indikator tujuan pembelajaran (itp)',
  `jenis_kegiatan` ENUM('TP KBM', 'Proyek', 'Asesmen Sumatif (AS)', 'Sumatif Tengah Semester (STS)', 'Remedial', 'Asesmen Sumatif Akhir Semester (ASAS)') NOT NULL DEFAULT 'TP KBM' COMMENT 'Kategori kegiatan',
  `kode_kegiatan` VARCHAR(50) NOT NULL COMMENT 'Kode TP / label agenda: RI&SYD 1, TH 1, SF 1, Proyek, AS, STS, REMIDI, ASAS',
  `nama_kegiatan` VARCHAR(255) DEFAULT NULL COMMENT 'Uraian ringkas materi atau bentuk asesmen',
  `jtm` INT(2) NOT NULL DEFAULT 2 COMMENT 'Alokasi Jam Tatap Muka (JP)',
  `urutan` INT(3) NOT NULL DEFAULT 1 COMMENT 'Nomor urut pelaksanaan KBM',
  `keterangan` TEXT DEFAULT NULL COMMENT 'Catatan tambahan agenda',
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_djt_ahm` (`id_alokasi_hari_mengajar`),
  KEY `idx_djt_kmp` (`id_kelas_mata_pelajaran`),
  KEY `idx_djt_tp` (`id_tp`),
  KEY `idx_djt_itp` (`id_itp`),
  KEY `idx_djt_jenis` (`jenis_kegiatan`),
  CONSTRAINT `fk_djt_ahm` FOREIGN KEY (`id_alokasi_hari_mengajar`) REFERENCES `alokasi_hari_mengajar` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_djt_kmp` FOREIGN KEY (`id_kelas_mata_pelajaran`) REFERENCES `kelas_mata_pelajaran` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_djt_tp` FOREIGN KEY (`id_tp`) REFERENCES `tp` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_djt_itp` FOREIGN KEY (`id_itp`) REFERENCES `itp` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------------------
-- 22. STRUKTUR TABEL PROTA (PROGRAM TAHUNAN KURIKULUM MERDEKA - PROTA.DOCX)
-- ------------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `prota` (
  `id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `id_kelas_mata_pelajaran` INT(11) UNSIGNED NOT NULL COMMENT 'Relasi ke kelas_mata_pelajaran',
  `id_guru` INT(11) DEFAULT NULL COMMENT 'Relasi ke users(id) guru penyusun',
  `tahun_pelajaran` VARCHAR(30) NOT NULL DEFAULT '2025/2026' COMMENT 'Tahun ajaran aktif',
  `fase` VARCHAR(20) NOT NULL DEFAULT 'Fase F' COMMENT 'Fase Kurikulum Merdeka (Fase E/F)',
  `total_jtm_ganjil` INT(3) NOT NULL DEFAULT 26 COMMENT 'Total Jam Tatap Muka Semester Ganjil',
  `total_jtm_genap` INT(3) NOT NULL DEFAULT 26 COMMENT 'Total Jam Tatap Muka Semester Genap',
  `total_jtm_tahunan` INT(3) NOT NULL DEFAULT 52 COMMENT 'Total JTM Setahun (Ganjil + Genap)',
  `as_ganjil` INT(2) NOT NULL DEFAULT 4 COMMENT 'Alokasi Asesmen Sumatif Ganjil (JP)',
  `sts_ganjil` INT(2) NOT NULL DEFAULT 2 COMMENT 'Sumatif Tengah Semester Ganjil (JP)',
  `remidi_ganjil` INT(2) NOT NULL DEFAULT 0 COMMENT 'Alokasi Remedial Ganjil (JP)',
  `proyek_ganjil` INT(2) NOT NULL DEFAULT 2 COMMENT 'Alokasi Proyek P5 PPRA Ganjil (JP)',
  `as_genap` INT(2) NOT NULL DEFAULT 4 COMMENT 'Alokasi Asesmen Sumatif Genap (JP)',
  `sts_genap` INT(2) NOT NULL DEFAULT 2 COMMENT 'Sumatif Tengah Semester Genap (JP)',
  `remidi_genap` INT(2) NOT NULL DEFAULT 0 COMMENT 'Alokasi Remedial Genap (JP)',
  `proyek_genap` INT(2) NOT NULL DEFAULT 2 COMMENT 'Alokasi Proyek P5 PPRA Genap (JP)',
  `status` ENUM('Draft', 'Approved', 'Archived') NOT NULL DEFAULT 'Approved',
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_prota_kmp` (`id_kelas_mata_pelajaran`),
  KEY `idx_prota_guru` (`id_guru`),
  CONSTRAINT `fk_prota_kmp` FOREIGN KEY (`id_kelas_mata_pelajaran`) REFERENCES `kelas_mata_pelajaran` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_prota_guru` FOREIGN KEY (`id_guru`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------------------
-- 23. STRUKTUR TABEL PROTA_DETAIL (RINCIAN ELEMEN, TUJUAN PEMBELAJARAN & ITP PROTA)
-- ------------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `prota_detail` (
  `id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `id_prota` INT(11) UNSIGNED NOT NULL COMMENT 'Relasi ke prota(id)',
  `id_elemen` INT(11) UNSIGNED NOT NULL COMMENT 'Relasi ke elemen(id)',
  `id_tp` INT(11) UNSIGNED NOT NULL COMMENT 'Relasi ke tp(id)',
  `id_itp` INT(11) UNSIGNED DEFAULT NULL COMMENT 'Relasi opsional ke indikator tujuan pembelajaran itp(id)',
  `semester` ENUM('Ganjil', 'Genap') NOT NULL DEFAULT 'Ganjil' COMMENT 'Semester pembelajaran',
  `nomor_urut` INT(3) NOT NULL DEFAULT 1 COMMENT 'Nomor urut baris (1 s.d 5)',
  `alokasi_waktu` INT(3) NOT NULL DEFAULT 4 COMMENT 'Alokasi Jam Tatap Muka (JP)',
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_protad_prota` (`id_prota`),
  KEY `idx_protad_elemen` (`id_elemen`),
  KEY `idx_protad_tp` (`id_tp`),
  KEY `idx_protad_itp` (`id_itp`),
  KEY `idx_protad_sem` (`semester`),
  CONSTRAINT `fk_protad_prota` FOREIGN KEY (`id_prota`) REFERENCES `prota` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_protad_elemen` FOREIGN KEY (`id_elemen`) REFERENCES `elemen` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_protad_tp` FOREIGN KEY (`id_tp`) REFERENCES `tp` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_protad_itp` FOREIGN KEY (`id_itp`) REFERENCES `itp` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------------------
-- 24. STRUKTUR TABEL PROSEM (PROGRAM SEMESTER KURIKULUM MERDEKA - PROSEM.XLSX)
-- ------------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `prosem` (
  `id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `id_prota` INT(11) UNSIGNED DEFAULT NULL COMMENT 'Relasi ke prota(id)',
  `id_kelas_mata_pelajaran` INT(11) UNSIGNED NOT NULL COMMENT 'Relasi ke kelas_mata_pelajaran',
  `id_guru` INT(11) DEFAULT NULL COMMENT 'Relasi ke users(id) guru pengampu',
  `tahun_pelajaran` VARCHAR(30) NOT NULL DEFAULT '2025/2026',
  `semester` ENUM('Ganjil', 'Genap') NOT NULL DEFAULT 'Ganjil',
  `total_alokasi_jp` INT(3) NOT NULL DEFAULT 36 COMMENT 'Total Jam Pelajaran Semester (36 JP Ganjil, 38 JP Genap)',
  `status` ENUM('Draft', 'Approved', 'Archived') NOT NULL DEFAULT 'Approved',
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_prosem_prota` (`id_prota`),
  KEY `idx_prosem_kmp` (`id_kelas_mata_pelajaran`),
  KEY `idx_prosem_guru` (`id_guru`),
  CONSTRAINT `fk_prosem_prota` FOREIGN KEY (`id_prota`) REFERENCES `prota` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_prosem_kmp` FOREIGN KEY (`id_kelas_mata_pelajaran`) REFERENCES `kelas_mata_pelajaran` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_prosem_guru` FOREIGN KEY (`id_guru`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------------------
-- 25. STRUKTUR TABEL PROSEM_DETAIL (RINCIAN POKOK BAHASAN, TP & ITP PROSEM)
-- ------------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `prosem_detail` (
  `id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `id_prosem` INT(11) UNSIGNED NOT NULL COMMENT 'Relasi ke prosem(id)',
  `id_tp` INT(11) UNSIGNED DEFAULT NULL COMMENT 'Relasi ke tp(id) (NULL jika agenda non-TP seperti STS, ASAS, Proyek)',
  `id_itp` INT(11) UNSIGNED DEFAULT NULL COMMENT 'Relasi opsional ke indikator tujuan pembelajaran itp(id)',
  `nomor_urut` INT(3) NOT NULL DEFAULT 1 COMMENT 'Nomor urut baris (1 s.d 10)',
  `kategori` ENUM('Materi KBM', 'Asesmen Sumatif', 'Sumatif Tengah Semester', 'Proyek', 'Asesmen Sumatif Akhir Semester', 'Remedial') NOT NULL DEFAULT 'Materi KBM',
  `nama_agenda` VARCHAR(255) DEFAULT NULL COMMENT 'Nama/judul agenda jika id_tp NULL (misal: AS 1, STS, Proyek, ASAS)',
  `alokasi_waktu` INT(3) NOT NULL DEFAULT 2 COMMENT 'Alokasi Jam Pelajaran (JP)',
  `id_modul_ajar` INT(11) UNSIGNED DEFAULT NULL COMMENT 'Relasi ke modul_ajar(id)',
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_prosemd_prosem` (`id_prosem`),
  KEY `idx_prosemd_tp` (`id_tp`),
  KEY `idx_prosemd_itp` (`id_itp`),
  KEY `idx_prosemd_ma` (`id_modul_ajar`),
  CONSTRAINT `fk_prosemd_prosem` FOREIGN KEY (`id_prosem`) REFERENCES `prosem` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_prosemd_tp` FOREIGN KEY (`id_tp`) REFERENCES `tp` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_prosemd_itp` FOREIGN KEY (`id_itp`) REFERENCES `itp` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_prosemd_ma` FOREIGN KEY (`id_modul_ajar`) REFERENCES `modul_ajar` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------------------
-- 26. STRUKTUR TABEL PROSEM_JADWAL_MINGGUAN (DISTRIBUSI MATRIKS JP PER PEKAN PER BULAN)
-- ------------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `prosem_jadwal_mingguan` (
  `id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `id_prosem_detail` INT(11) UNSIGNED NOT NULL COMMENT 'Relasi ke prosem_detail(id)',
  `bulan` VARCHAR(30) NOT NULL COMMENT 'Nama bulan (Juli s.d Desember / Januari s.d Juni)',
  `urutan_bulan` INT(2) NOT NULL COMMENT 'Urutan bulan akademik (1 s.d 12)',
  `pekan_ke` INT(2) NOT NULL COMMENT 'Nomor pekan dalam bulan (1 s.d 6)',
  `jam_tatap_muka` INT(2) NOT NULL DEFAULT 2 COMMENT 'Alokasi JP pada pekan tersebut (contoh: 2 JP)',
  `keterangan` VARCHAR(100) DEFAULT NULL COMMENT 'Aktivitas: KBM, Ujian, Proyek',
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_pjm_detail` (`id_prosem_detail`),
  KEY `idx_pjm_bln_pekan` (`bulan`, `pekan_ke`),
  CONSTRAINT `fk_pjm_detail` FOREIGN KEY (`id_prosem_detail`) REFERENCES `prosem_detail` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------------------
-- 27. STRUKTUR TABEL TITIK_KOORDINAT_SEKOLAH (MASTER LOKASI GEOFENCING SEKOLAH/KAMPUS)
-- ------------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `titik_koordinat_sekolah` (
  `id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `nama_lokasi` VARCHAR(150) NOT NULL COMMENT 'Nama titik/gedung (contoh: Kampus Utama, Gedung KBM PAI Lantai 2)',
  `latitude` DECIMAL(10,8) NOT NULL COMMENT 'Titik koordinat latitude GPS',
  `longitude` DECIMAL(11,8) NOT NULL COMMENT 'Titik koordinat longitude GPS',
  `radius_meter` INT(5) NOT NULL DEFAULT 50 COMMENT 'Radius toleransi kehadiran dalam meter untuk geofencing',
  `alamat` TEXT DEFAULT NULL COMMENT 'Alamat fisik / deskripsi lokasi',
  `is_active` TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'Status aktif titik koordinat (1=Aktif, 0=Nonaktif)',
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_tks_status` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------------------
-- 28. STRUKTUR TABEL JADWAL_PELAJARAN (JADWAL PERTEMUAN KBM - MERUJUK MODUL AJAR & HARI/PEKAN EFEKTIF)
-- ------------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `jadwal_pelajaran` (
  `id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `id_kelas_mata_pelajaran` INT(11) UNSIGNED NOT NULL COMMENT 'Relasi ke kelas_mata_pelajaran',
  `id_guru` INT(11) DEFAULT NULL COMMENT 'Relasi ke users(id) guru pengampu',
  `id_modul_ajar` INT(11) UNSIGNED DEFAULT NULL COMMENT 'Relasi ke modul_ajar(id) pertemuan ini',
  `id_hari_efektif` INT(11) UNSIGNED DEFAULT NULL COMMENT 'Relasi ke kalender hari_efektif(id)',
  `id_pekan_efektif` INT(11) UNSIGNED DEFAULT NULL COMMENT 'Relasi ke rincian pekan_efektif(id)',
  `id_titik_sekolah` INT(11) UNSIGNED DEFAULT NULL COMMENT 'Relasi ke titik_koordinat_sekolah(id) acuan lokasi KBM',
  `pertemuan_ke` INT(3) NOT NULL DEFAULT 1 COMMENT 'Nomor urut pertemuan KBM (1, 2, 3...)',
  `tahun_pelajaran` VARCHAR(30) NOT NULL DEFAULT '2026/2027',
  `semester` ENUM('Ganjil', 'Genap') NOT NULL DEFAULT 'Ganjil',
  `hari` ENUM('Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu', 'Minggu') NOT NULL DEFAULT 'Senin',
  `tanggal` DATE NOT NULL COMMENT 'Tanggal KBM terlaksana (YYYY-MM-DD)',
  `jam_mulai` TIME NOT NULL DEFAULT '07:00:00' COMMENT 'Jam mulai KBM',
  `jam_selesai` TIME NOT NULL DEFAULT '08:30:00' COMMENT 'Jam berakhir KBM',
  `alokasi_jp` INT(2) NOT NULL DEFAULT 2 COMMENT 'Alokasi Jam Pelajaran (JP)',
  `materi_pembahasan` VARCHAR(255) DEFAULT NULL COMMENT 'Judul / pokok materi pembahasan pertemuan ini',
  `ruangan` VARCHAR(100) DEFAULT 'Ruang Kelas PAI' COMMENT 'Lokasi / ruangan KBM berlangsung',
  `status_jadwal` ENUM('Terjadwal', 'Berlangsung', 'Selesai', 'Dibatalkan', 'Diganti') NOT NULL DEFAULT 'Terjadwal',
  `keterangan` TEXT DEFAULT NULL COMMENT 'Catatan agenda / instruksi guru',
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_jp_kmp` (`id_kelas_mata_pelajaran`),
  KEY `idx_jp_guru` (`id_guru`),
  KEY `idx_jp_ma` (`id_modul_ajar`),
  KEY `idx_jp_he` (`id_hari_efektif`),
  KEY `idx_jp_pe` (`id_pekan_efektif`),
  KEY `idx_jp_titik` (`id_titik_sekolah`),
  KEY `idx_jp_tanggal` (`tanggal`),
  KEY `idx_jp_status` (`status_jadwal`),
  CONSTRAINT `fk_jp_kmp` FOREIGN KEY (`id_kelas_mata_pelajaran`) REFERENCES `kelas_mata_pelajaran` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_jp_guru` FOREIGN KEY (`id_guru`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_jp_ma` FOREIGN KEY (`id_modul_ajar`) REFERENCES `modul_ajar` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_jp_he` FOREIGN KEY (`id_hari_efektif`) REFERENCES `hari_efektif` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_jp_pe` FOREIGN KEY (`id_pekan_efektif`) REFERENCES `pekan_efektif` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_jp_titik` FOREIGN KEY (`id_titik_sekolah`) REFERENCES `titik_koordinat_sekolah` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------------------
-- 29. STRUKTUR TABEL PRESENSI (MASTER KODE VALIDASI PRESENSI SISWA PER KELAS MAPEL)
-- Sesuai Format wp3f_dosen_presensi: id, id_presensi, id_mahasiswa, id_kelas_matkul, tanggal_created, expired_at, status
-- Formula id_presensi: (3 digit acak) + NIS + (4 digit acak) + NISN + (5 digit acak), masa berlaku 1 semester (landasan QR Code presensi)
-- ------------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `presensi` (
  `id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `id_presensi` VARCHAR(50) NOT NULL COMMENT 'Kode validasi presensi unik per siswa per mapel: (3 digit acak)NIS(4 digit acak)NISN(5 digit acak)',
  `id_siswa` INT(11) UNSIGNED NOT NULL COMMENT 'Relasi ke siswa(id) / id_mahasiswa',
  `id_kelas_mata_pelajaran` INT(11) UNSIGNED NOT NULL COMMENT 'Relasi ke kelas_mata_pelajaran(id) / id_kelas_matkul',
  `tanggal_created` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'Waktu pembuatan kode presensi',
  `expired_at` DATETIME DEFAULT NULL COMMENT 'Waktu masa berlaku kode presensi berakhir (1 semester)',
  `status` ENUM('aktif', 'nonaktif') NOT NULL DEFAULT 'aktif' COMMENT 'Status validasi kartu presensi siswa',
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_presensi_id_presensi` (`id_presensi`),
  UNIQUE KEY `unique_presensi_kmp_siswa` (`id_kelas_mata_pelajaran`, `id_siswa`),
  KEY `idx_presensi_siswa` (`id_siswa`),
  KEY `idx_presensi_kmp` (`id_kelas_mata_pelajaran`),
  KEY `idx_presensi_status` (`status`),
  KEY `idx_presensi_expired` (`expired_at`),
  CONSTRAINT `fk_presensi_siswa` FOREIGN KEY (`id_siswa`) REFERENCES `siswa` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_presensi_kmp` FOREIGN KEY (`id_kelas_mata_pelajaran`) REFERENCES `kelas_mata_pelajaran` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------------------
-- 30. STRUKTUR TABEL PERIZINAN (PENGAJUAN IZIN DUAL APPROVAL: KETUA KELAS -> GURU -> SYNC ABSENSI)
-- ------------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `perizinan` (
  `id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `id_siswa` INT(11) UNSIGNED NOT NULL COMMENT 'Relasi ke siswa(id) pemohon izin',
  `id_kelas_mata_pelajaran` INT(11) UNSIGNED NOT NULL COMMENT 'Relasi ke kelas_mata_pelajaran',
  `id_jadwal` INT(11) UNSIGNED DEFAULT NULL COMMENT 'Relasi ke jadwal_pelajaran(id)',
  `id_modul_ajar` INT(11) UNSIGNED DEFAULT NULL COMMENT 'Relasi ke modul_ajar(id)',
  `pertemuan_ke` INT(3) NOT NULL DEFAULT 1 COMMENT 'Pertemuan ke berapa yang dimohonkan izin',
  `tanggal_izin` DATE NOT NULL COMMENT 'Tanggal izin berlaku (YYYY-MM-DD)',
  `jenis_izin` ENUM('Izin', 'Sakit') NOT NULL DEFAULT 'Izin' COMMENT 'Kategori perizinan: Izin (I) atau Sakit (S)',
  `alasan` TEXT NOT NULL COMMENT 'Uraian lengkap alasan perizinan',
  `bukti_perizinan` VARCHAR(255) NOT NULL COMMENT 'File upload dokumen / bukti izin (surat dokter/surat ortu: pdf/jpg/png)',
  `latitude` DECIMAL(10,8) DEFAULT NULL COMMENT 'Latitude posisi siswa saat mengajukan izin',
  `longitude` DECIMAL(11,8) DEFAULT NULL COMMENT 'Longitude posisi siswa saat mengajukan izin',
  `alamat_izin` TEXT DEFAULT NULL COMMENT 'Nama tempat / alamat fisik saat izin diajukan',
  `approval_ketua_kelas` ENUM('Pending', 'Approved', 'Rejected') NOT NULL DEFAULT 'Pending' COMMENT 'Tahap 1: Wajib di-approve ketua kelas terlebih dahulu',
  `id_ketua_kelas` INT(11) DEFAULT NULL COMMENT 'Relasi ke users(id) akun ketua kelas yang memverifikasi',
  `waktu_approval_ketua` DATETIME DEFAULT NULL COMMENT 'Waktu ketua kelas menyetujui / menolak',
  `catatan_ketua_kelas` TEXT DEFAULT NULL COMMENT 'Catatan / alasan reviu ketua kelas',
  `approval_guru` ENUM('Pending', 'Approved', 'Rejected') NOT NULL DEFAULT 'Pending' COMMENT 'Tahap 2: Di-approve guru pengampu setelah ketua kelas setuju',
  `id_guru` INT(11) DEFAULT NULL COMMENT 'Relasi ke users(id) guru pengampu yang memberi keputusan akhir',
  `waktu_approval_guru` DATETIME DEFAULT NULL COMMENT 'Waktu guru menyetujui / menolak',
  `catatan_guru` TEXT DEFAULT NULL COMMENT 'Catatan / pesan dari guru',
  `status_perizinan` ENUM('Menunggu Persetujuan Ketua Kelas', 'Menunggu Persetujuan Guru', 'Disetujui', 'Ditolak') NOT NULL DEFAULT 'Menunggu Persetujuan Ketua Kelas' COMMENT 'Status akumulatif alur persetujuan',
  `is_sync_to_absensi` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Flag 1 jika perizinan sah dan telah disinkronkan ke tabel absensi (status I/S)',
  `id_absensi` INT(11) UNSIGNED DEFAULT NULL COMMENT 'Relasi ke absensi(id) setelah otomatis tersinkron',
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_izin_siswa` (`id_siswa`),
  KEY `idx_izin_kmp` (`id_kelas_mata_pelajaran`),
  KEY `idx_izin_jadwal` (`id_jadwal`),
  KEY `idx_izin_ketua` (`id_ketua_kelas`),
  KEY `idx_izin_guru` (`id_guru`),
  KEY `idx_izin_status` (`status_perizinan`),
  KEY `idx_izin_absensi` (`id_absensi`),
  CONSTRAINT `fk_izin_siswa` FOREIGN KEY (`id_siswa`) REFERENCES `siswa` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_izin_kmp` FOREIGN KEY (`id_kelas_mata_pelajaran`) REFERENCES `kelas_mata_pelajaran` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_izin_jadwal` FOREIGN KEY (`id_jadwal`) REFERENCES `jadwal_pelajaran` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_izin_ketua` FOREIGN KEY (`id_ketua_kelas`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_izin_guru` FOREIGN KEY (`id_guru`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------------------
-- 31. STRUKTUR TABEL ABSENSI (LOG TRANSAKSI KEHADIRAN SISWA PER PERTEMUAN: SISWA, KELAS MAPEL & GPS)
-- Tanpa id_presensi karena sudah ada id_siswa dan id_kelas_mata_pelajaran (id_presensi hanya untuk pencocokan)
-- ------------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `absensi` (
  `id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `id_siswa` INT(11) UNSIGNED NOT NULL COMMENT 'Relasi ke siswa(id) (id mahasiswa/siswa)',
  `id_kelas_mata_pelajaran` INT(11) UNSIGNED NOT NULL COMMENT 'Relasi ke kelas_mata_pelajaran(id)',
  `id_jadwal` INT(11) UNSIGNED DEFAULT NULL COMMENT 'Relasi ke jadwal_pelajaran(id)',
  `id_modul_ajar` INT(11) UNSIGNED DEFAULT NULL COMMENT 'Relasi ke modul_ajar(id)',
  `id_titik_sekolah` INT(11) UNSIGNED DEFAULT NULL COMMENT 'Relasi ke titik_koordinat_sekolah acuan radius',
  `pertemuan_ke` INT(3) NOT NULL DEFAULT 1 COMMENT 'Pertemuan ke berapa KBM',
  `status` ENUM('H', 'I', 'S', 'A', 'T') NOT NULL DEFAULT 'H' COMMENT 'Status kehadiran: H=Hadir, I=Izin, S=Sakit, A=Alpha, T=Terlambat',
  `latitude` DECIMAL(10,8) DEFAULT NULL COMMENT 'Titik koordinat latitude GPS saat absensi',
  `longitude` DECIMAL(11,8) DEFAULT NULL COMMENT 'Titik koordinat longitude GPS saat absensi',
  `jarak_meter` DECIMAL(8,2) DEFAULT NULL COMMENT 'Jarak fisik siswa ke titik koordinat sekolah (meter)',
  `waktu_absen` DATETIME DEFAULT CURRENT_TIMESTAMP COMMENT 'Waktu presensi dicatat',
  `id_perizinan` INT(11) UNSIGNED DEFAULT NULL COMMENT 'Relasi ke perizinan(id) jika status I/S',
  `metode_absen` ENUM('Scan QR & GPS', 'Manual Guru', 'Manual Ketua Kelas', 'Sistem Perizinan') NOT NULL DEFAULT 'Scan QR & GPS',
  `keterangan` TEXT DEFAULT NULL COMMENT 'Catatan kehadiran / alasan terlambat',
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_absensi_siswa_pertemuan` (`id_kelas_mata_pelajaran`, `id_siswa`, `pertemuan_ke`),
  KEY `idx_absensi_siswa` (`id_siswa`),
  KEY `idx_absensi_kmp` (`id_kelas_mata_pelajaran`),
  KEY `idx_absensi_jadwal` (`id_jadwal`),
  KEY `idx_absensi_ma` (`id_modul_ajar`),
  KEY `idx_absensi_titik` (`id_titik_sekolah`),
  KEY `idx_absensi_status` (`status`),
  KEY `idx_absensi_izin` (`id_perizinan`),
  CONSTRAINT `fk_absensi_siswa` FOREIGN KEY (`id_siswa`) REFERENCES `siswa` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_absensi_kmp` FOREIGN KEY (`id_kelas_mata_pelajaran`) REFERENCES `kelas_mata_pelajaran` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_absensi_jadwal` FOREIGN KEY (`id_jadwal`) REFERENCES `jadwal_pelajaran` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_absensi_ma` FOREIGN KEY (`id_modul_ajar`) REFERENCES `modul_ajar` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_absensi_titik` FOREIGN KEY (`id_titik_sekolah`) REFERENCES `titik_koordinat_sekolah` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_absensi_izin` FOREIGN KEY (`id_perizinan`) REFERENCES `perizinan` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------------------
-- 32. STRUKTUR TABEL TUGAS (MASTER PENUGASAN GURU BERBASIS MODUL AJAR & ITP)
-- ------------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `tugas` (
  `id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `id_kelas_mata_pelajaran` INT(11) UNSIGNED NOT NULL COMMENT 'Relasi ke kelas_mata_pelajaran(id) / id_kelas_matkul',
  `id_guru` INT(11) DEFAULT NULL COMMENT 'Relasi ke users(id) guru pembuat tugas',
  `id_modul_ajar` INT(11) UNSIGNED DEFAULT NULL COMMENT 'Relasi ke modul_ajar(id) pertemuan terkait',
  `id_tp` INT(11) UNSIGNED DEFAULT NULL COMMENT 'Relasi ke tp(id) tujuan pembelajaran',
  `id_itp` INT(11) UNSIGNED DEFAULT NULL COMMENT 'Relasi ke itp(id) indikator tujuan pembelajaran',
  `id_jadwal` INT(11) UNSIGNED DEFAULT NULL COMMENT 'Relasi ke jadwal_pelajaran(id) pertemuan KBM',
  `pertemuan_ke` INT(3) NOT NULL DEFAULT 1 COMMENT 'Pertemuan ke berapa KBM',
  `judul_tugas` VARCHAR(255) NOT NULL COMMENT 'Judul / nama penugasan',
  `deskripsi_instruksi` TEXT NOT NULL COMMENT 'Instruksi detail teknis pengerjaan tugas & rubrik asesmen',
  `jenis_penugasan` ENUM('Individu', 'Kelompok') NOT NULL DEFAULT 'Individu' COMMENT 'Kategori penugasan: Individu atau Kelompok',
  `tipe_pengumpulan` ENUM('File Upload', 'Link URL', 'File atau Link', 'Teks / Essay') NOT NULL DEFAULT 'File atau Link' COMMENT 'Metode pengumpulan tugas yang diizinkan',
  `format_file_diizinkan` VARCHAR(100) DEFAULT 'pdf,docx,zip' COMMENT 'Ekstensi berkas yang diizinkan (misal: pdf, docx, zip)',
  `file_lampiran_tugas` VARCHAR(255) DEFAULT NULL COMMENT 'File lampiran LKPD / panduan soal dari guru (PDF/Docx)',
  `link_sumber_tugas` VARCHAR(255) DEFAULT NULL COMMENT 'Link referensi materi / video / literatur penunjang tugas',
  `nilai_maksimal` DECIMAL(5,2) NOT NULL DEFAULT 100.00 COMMENT 'Skor maksimal tugas (default 100.00)',
  `bobot_nilai` INT(3) NOT NULL DEFAULT 10 COMMENT 'Bobot persentase nilai terhadap nilai akhir',
  `tanggal_mulai` DATETIME DEFAULT CURRENT_TIMESTAMP COMMENT 'Waktu tugas mulai dapat diakses siswa',
  `tenggat_waktu` DATETIME NOT NULL COMMENT 'Batas akhir (deadline) waktu pengumpulan tugas',
  `status_tugas` ENUM('Draft', 'Dibuka', 'Ditutup') NOT NULL DEFAULT 'Dibuka' COMMENT 'Status ketersediaan tugas',
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_tugas_kmp` (`id_kelas_mata_pelajaran`),
  KEY `idx_tugas_guru` (`id_guru`),
  KEY `idx_tugas_ma` (`id_modul_ajar`),
  KEY `idx_tugas_tp` (`id_tp`),
  KEY `idx_tugas_itp` (`id_itp`),
  KEY `idx_tugas_jadwal` (`id_jadwal`),
  KEY `idx_tugas_status` (`status_tugas`),
  CONSTRAINT `fk_tugas_kmp` FOREIGN KEY (`id_kelas_mata_pelajaran`) REFERENCES `kelas_mata_pelajaran` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_tugas_guru` FOREIGN KEY (`id_guru`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_tugas_ma` FOREIGN KEY (`id_modul_ajar`) REFERENCES `modul_ajar` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_tugas_tp` FOREIGN KEY (`id_tp`) REFERENCES `tp` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_tugas_itp` FOREIGN KEY (`id_itp`) REFERENCES `itp` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_tugas_jadwal` FOREIGN KEY (`id_jadwal`) REFERENCES `jadwal_pelajaran` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------------------
-- 33. STRUKTUR TABEL TUGAS_KELOMPOK (MASTER PEMBAGIAN KELOMPOK PENUGASAN)
-- ------------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `tugas_kelompok` (
  `id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `id_tugas` INT(11) UNSIGNED NOT NULL COMMENT 'Relasi ke tugas(id)',
  `id_kelas_mata_pelajaran` INT(11) UNSIGNED NOT NULL COMMENT 'Relasi ke kelas_mata_pelajaran(id) / id_kelas_matkul',
  `nomor_kelompok` INT(3) NOT NULL DEFAULT 1 COMMENT 'Nomor urut kelompok (1, 2, 3...)',
  `nama_kelompok` VARCHAR(100) NOT NULL COMMENT 'Nama kelompok (contoh: Kelompok 1 - Abu Bakar Ash-Shiddiq)',
  `id_ketua_kelompok` INT(11) UNSIGNED DEFAULT NULL COMMENT 'Relasi ke siswa(id) koordinator/ketua kelompok',
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_tk_tugas` (`id_tugas`),
  KEY `idx_tk_kmp` (`id_kelas_mata_pelajaran`),
  KEY `idx_tk_ketua` (`id_ketua_kelompok`),
  CONSTRAINT `fk_tk_tugas` FOREIGN KEY (`id_tugas`) REFERENCES `tugas` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_tk_kmp` FOREIGN KEY (`id_kelas_mata_pelajaran`) REFERENCES `kelas_mata_pelajaran` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_tk_ketua` FOREIGN KEY (`id_ketua_kelompok`) REFERENCES `siswa` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------------------
-- 34. STRUKTUR TABEL TUGAS_KELOMPOK_ANGGOTA (RINCIAN ANGGOTA KELOMPOK SISWA)
-- ------------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `tugas_kelompok_anggota` (
  `id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `id_tugas_kelompok` INT(11) UNSIGNED NOT NULL COMMENT 'Relasi ke tugas_kelompok(id)',
  `id_siswa` INT(11) UNSIGNED NOT NULL COMMENT 'Relasi ke siswa(id) / id_mahasiswa anggota',
  `id_kelas_mata_pelajaran` INT(11) UNSIGNED NOT NULL COMMENT 'Relasi ke kelas_mata_pelajaran(id)',
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_kelompok_siswa` (`id_tugas_kelompok`, `id_siswa`),
  KEY `idx_tka_kelompok` (`id_tugas_kelompok`),
  KEY `idx_tka_siswa` (`id_siswa`),
  KEY `idx_tka_kmp` (`id_kelas_mata_pelajaran`),
  CONSTRAINT `fk_tka_kelompok` FOREIGN KEY (`id_tugas_kelompok`) REFERENCES `tugas_kelompok` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_tka_siswa` FOREIGN KEY (`id_siswa`) REFERENCES `siswa` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_tka_kmp` FOREIGN KEY (`id_kelas_mata_pelajaran`) REFERENCES `kelas_mata_pelajaran` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------------------
-- 35. STRUKTUR TABEL PENGUMPULAN_TUGAS (LOG PENGUMPULAN TUGAS SISWA - FILE / LINK / TEKS)
-- ------------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `pengumpulan_tugas` (
  `id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `id_tugas` INT(11) UNSIGNED NOT NULL COMMENT 'Relasi ke tugas(id)',
  `id_siswa` INT(11) UNSIGNED NOT NULL COMMENT 'Relasi ke siswa(id) / id_mahasiswa yang mengumpulkan',
  `id_kelas_mata_pelajaran` INT(11) UNSIGNED NOT NULL COMMENT 'Relasi ke kelas_mata_pelajaran(id) / id_kelas_matkul',
  `id_tugas_kelompok` INT(11) UNSIGNED DEFAULT NULL COMMENT 'Relasi ke tugas_kelompok(id) jika tugas kelompok',
  `tipe_pengumpulan` ENUM('File Upload', 'Link URL', 'File dan Link', 'Teks / Essay') NOT NULL DEFAULT 'File Upload' COMMENT 'Metode pengumpulan yang digunakan siswa',
  `file_tugas` VARCHAR(255) DEFAULT NULL COMMENT 'Path berkas tugas yang diunggah (PDF/Docx/Zip)',
  `file_nama_asli` VARCHAR(255) DEFAULT NULL COMMENT 'Nama berkas original saat diunggah',
  `file_ukuran_kb` INT(11) DEFAULT NULL COMMENT 'Ukuran file dalam kilobyte (KB)',
  `link_tugas` TEXT DEFAULT NULL COMMENT 'Tautan link URL pengumpulan (Google Drive, Docs, Figma, Video, dll)',
  `catatan_siswa` TEXT DEFAULT NULL COMMENT 'Catatan/keterangan pengerjaan atau jawaban teks essay siswa',
  `waktu_pengumpulan` DATETIME DEFAULT CURRENT_TIMESTAMP COMMENT 'Waktu siswa mengirimkan tugas',
  `status_pengumpulan` ENUM('Diserahkan', 'Terlambat', 'Draft', 'Menunggu Revisi') NOT NULL DEFAULT 'Diserahkan' COMMENT 'Status penyerahan tugas',
  `is_terlambat` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Flag 1 jika dikumpulkan setelah tenggat_waktu tugas',
  `revisi_ke` INT(2) NOT NULL DEFAULT 1 COMMENT 'Nomor versi pengumpulan jika ada revisi',
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_pengumpulan_siswa_tugas` (`id_tugas`, `id_siswa`),
  KEY `idx_pt_tugas` (`id_tugas`),
  KEY `idx_pt_siswa` (`id_siswa`),
  KEY `idx_pt_kmp` (`id_kelas_mata_pelajaran`),
  KEY `idx_pt_kelompok` (`id_tugas_kelompok`),
  KEY `idx_pt_status` (`status_pengumpulan`),
  CONSTRAINT `fk_pt_tugas` FOREIGN KEY (`id_tugas`) REFERENCES `tugas` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_pt_siswa` FOREIGN KEY (`id_siswa`) REFERENCES `siswa` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_pt_kmp` FOREIGN KEY (`id_kelas_mata_pelajaran`) REFERENCES `kelas_mata_pelajaran` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_pt_kelompok` FOREIGN KEY (`id_tugas_kelompok`) REFERENCES `tugas_kelompok` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------------------
-- 36. STRUKTUR TABEL NILAI_TUGAS (PENILAIAN GURU BERDASARKAN PENGUMPULAN & EVALUASI ITP)
-- ------------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `nilai_tugas` (
  `id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `id_tugas` INT(11) UNSIGNED NOT NULL COMMENT 'Relasi ke tugas(id)',
  `id_pengumpulan_tugas` INT(11) UNSIGNED NOT NULL COMMENT 'Relasi ke pengumpulan_tugas(id) yang dinilai',
  `id_siswa` INT(11) UNSIGNED NOT NULL COMMENT 'Relasi ke siswa(id) / id_mahasiswa yang dinilai',
  `id_kelas_mata_pelajaran` INT(11) UNSIGNED NOT NULL COMMENT 'Relasi ke kelas_mata_pelajaran(id) / id_kelas_matkul',
  `id_guru` INT(11) DEFAULT NULL COMMENT 'Relasi ke users(id) guru penilai',
  `nilai` DECIMAL(5,2) NOT NULL COMMENT 'Skor angka nilai tugas (contoh: 88.50 dari 100.00)',
  `predikat` ENUM('A', 'B', 'C', 'D', 'E') DEFAULT NULL COMMENT 'Konversi huruf mutu (A: Sangat Baik, B: Baik, C: Cukup, D: Kurang, E: Sangat Kurang)',
  `aspek_itp_ketercapaian` ENUM('Tercapai Sangat Baik', 'Tercapai', 'Cukup Tercapai', 'Perlu Bimbingan') NOT NULL DEFAULT 'Tercapai' COMMENT 'Evaluasi ketercapaian Indikator Tujuan Pembelajaran (ITP/IKTP)',
  `catatan_guru` TEXT DEFAULT NULL COMMENT 'Catatan evaluasi, umpan balik (feedback) dan saran perbaikan dari guru',
  `status_penilaian` ENUM('Dinilai', 'Perlu Revisi', 'Tuntas') NOT NULL DEFAULT 'Dinilai' COMMENT 'Status hasil penilaian tugas',
  `waktu_penilaian` DATETIME DEFAULT CURRENT_TIMESTAMP COMMENT 'Waktu guru memberikan nilai',
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_nilai_tugas_siswa` (`id_tugas`, `id_siswa`),
  KEY `idx_nt_tugas` (`id_tugas`),
  KEY `idx_nt_pengumpulan` (`id_pengumpulan_tugas`),
  KEY `idx_nt_siswa` (`id_siswa`),
  KEY `idx_nt_kmp` (`id_kelas_mata_pelajaran`),
  KEY `idx_nt_guru` (`id_guru`),
  CONSTRAINT `fk_nt_tugas` FOREIGN KEY (`id_tugas`) REFERENCES `tugas` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_nt_pengumpulan` FOREIGN KEY (`id_pengumpulan_tugas`) REFERENCES `pengumpulan_tugas` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_nt_siswa` FOREIGN KEY (`id_siswa`) REFERENCES `siswa` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_nt_kmp` FOREIGN KEY (`id_kelas_mata_pelajaran`) REFERENCES `kelas_mata_pelajaran` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_nt_guru` FOREIGN KEY (`id_guru`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------------------
-- 37. STRUKTUR TABEL ASESMEN_DIAGNOSTIK (MASTER PEMETAAN KESIAPAN BELAJAR KOGNITIF & NON-KOGNITIF)
-- ------------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `asesmen_diagnostik` (
  `id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `id_kelas_mata_pelajaran` INT(11) UNSIGNED NOT NULL COMMENT 'Relasi ke kelas_mata_pelajaran(id) / id_kelas_matkul',
  `id_guru` INT(11) DEFAULT NULL COMMENT 'Relasi ke users(id) guru penyusun',
  `id_modul_ajar` INT(11) UNSIGNED DEFAULT NULL COMMENT 'Relasi ke modul_ajar(id) pertemuan terkait',
  `id_cp` INT(11) UNSIGNED DEFAULT NULL COMMENT 'Relasi ke cp(id) capaian pembelajaran',
  `id_tp` INT(11) UNSIGNED DEFAULT NULL COMMENT 'Relasi ke tp(id) tujuan pembelajaran',
  `id_atp` INT(11) UNSIGNED DEFAULT NULL COMMENT 'Relasi ke atp(id) alur tujuan pembelajaran',
  `id_itp` INT(11) UNSIGNED DEFAULT NULL COMMENT 'Relasi ke itp(id) indikator tujuan pembelajaran',
  `pertemuan_ke` INT(3) NOT NULL DEFAULT 1 COMMENT 'Pertemuan ke berapa KBM',
  `judul_asesmen` VARCHAR(255) NOT NULL COMMENT 'Judul asesmen diagnostik (contoh: Tes Awal Pemetaan Kesiapan Belajar Ulil Albab)',
  `jenis_diagnostik` ENUM('Kognitif', 'Non-Kognitif') NOT NULL DEFAULT 'Kognitif' COMMENT 'Jenis diagnostik: Kognitif (prasyarat materi) atau Non-Kognitif (gaya belajar, psikologis)',
  `tujuan_asesmen` TEXT NOT NULL COMMENT 'Tujuan pemetaan kemampuan prasyarat peserta didik',
  `petunjuk_pengerjaan` TEXT NOT NULL COMMENT 'Petunjuk instruksi pengerjaan bagi siswa',
  `tanggal_pelaksanaan` DATE NOT NULL COMMENT 'Hari dan tanggal pelaksanaan asesmen diagnostik',
  `jam_mulai` TIME NOT NULL DEFAULT '07:30:00' COMMENT 'Jam mulai pelaksanaan ujian',
  `jam_selesai` TIME NOT NULL DEFAULT '09:00:00' COMMENT 'Jam batas akhir pelaksanaan ujian',
  `durasi_menit` INT(4) NOT NULL DEFAULT 60 COMMENT 'Durasi pengerjaan dalam menit',
  `status` ENUM('Draft', 'Aktif', 'Ditutup') NOT NULL DEFAULT 'Aktif',
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_ad_kmp` (`id_kelas_mata_pelajaran`),
  KEY `idx_ad_guru` (`id_guru`),
  KEY `idx_ad_ma` (`id_modul_ajar`),
  KEY `idx_ad_cp` (`id_cp`),
  KEY `idx_ad_tp` (`id_tp`),
  KEY `idx_ad_atp` (`id_atp`),
  KEY `idx_ad_itp` (`id_itp`),
  CONSTRAINT `fk_ad_kmp` FOREIGN KEY (`id_kelas_mata_pelajaran`) REFERENCES `kelas_mata_pelajaran` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_ad_guru` FOREIGN KEY (`id_guru`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_ad_ma` FOREIGN KEY (`id_modul_ajar`) REFERENCES `modul_ajar` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_ad_cp` FOREIGN KEY (`id_cp`) REFERENCES `cp` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_ad_tp` FOREIGN KEY (`id_tp`) REFERENCES `tp` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_ad_atp` FOREIGN KEY (`id_atp`) REFERENCES `atp` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_ad_itp` FOREIGN KEY (`id_itp`) REFERENCES `itp` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------------------
-- 38. STRUKTUR TABEL ASESMEN_DIAGNOSTIK_SOAL (BUTIR SOAL ASESMEN DIAGNOSTIK PER PERTEMUAN KBM)
-- ------------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `asesmen_diagnostik_soal` (
  `id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `id_asesmen_diagnostik` INT(11) UNSIGNED NOT NULL COMMENT 'Relasi ke asesmen_diagnostik(id)',
  `id_kelas_mata_pelajaran` INT(11) UNSIGNED NOT NULL COMMENT 'Relasi ke kelas_mata_pelajaran(id) / id_kelas_matkul',
  `id_modul_ajar` INT(11) UNSIGNED DEFAULT NULL COMMENT 'Relasi ke modul_ajar(id)',
  `pertemuan_ke` INT(3) NOT NULL DEFAULT 1 COMMENT 'Pertemuan ke berapa KBM',
  `id_cp` INT(11) UNSIGNED DEFAULT NULL COMMENT 'Relasi ke cp(id)',
  `id_tp` INT(11) UNSIGNED DEFAULT NULL COMMENT 'Relasi ke tp(id)',
  `id_atp` INT(11) UNSIGNED DEFAULT NULL COMMENT 'Relasi ke atp(id)',
  `id_itp` INT(11) UNSIGNED DEFAULT NULL COMMENT 'Relasi ke itp(id) indikator prasyarat yang diukur',
  `nomor_soal` INT(3) NOT NULL DEFAULT 1 COMMENT 'Nomor urut butir soal (1, 2, 3...)',
  `tipe_soal` ENUM('Pilihan Ganda', 'Pilihan Ganda Kompleks', 'Essay / Uraian', 'Benar Salah', 'Menjodohkan') NOT NULL DEFAULT 'Pilihan Ganda' COMMENT 'Tipe format soal',
  `pertanyaan` TEXT NOT NULL COMMENT 'Teks stimulus dan butir pertanyaan diagnostik',
  `gambar_stimulus` VARCHAR(255) DEFAULT NULL COMMENT 'Gambar ilustrasi/grafik jika ada',
  `tingkat_kesulitan` ENUM('Mudah', 'Sedang', 'Sukar') NOT NULL DEFAULT 'Sedang',
  `level_kognitif` ENUM('C1 - Mengingat', 'C2 - Memahami', 'C3 - Mengaplikasikan', 'C4 - Menganalisis', 'C5 - Mengevaluasi', 'C6 - Mencipta') NOT NULL DEFAULT 'C2 - Memahami',
  `bobot_skor` DECIMAL(5,2) NOT NULL DEFAULT 10.00 COMMENT 'Bobot skor maksimal butir soal ini',
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_ads_ad` (`id_asesmen_diagnostik`),
  KEY `idx_ads_kmp` (`id_kelas_mata_pelajaran`),
  KEY `idx_ads_ma` (`id_modul_ajar`),
  KEY `idx_ads_cp` (`id_cp`),
  KEY `idx_ads_tp` (`id_tp`),
  KEY `idx_ads_atp` (`id_atp`),
  KEY `idx_ads_itp` (`id_itp`),
  CONSTRAINT `fk_ads_ad` FOREIGN KEY (`id_asesmen_diagnostik`) REFERENCES `asesmen_diagnostik` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_ads_kmp` FOREIGN KEY (`id_kelas_mata_pelajaran`) REFERENCES `kelas_mata_pelajaran` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_ads_ma` FOREIGN KEY (`id_modul_ajar`) REFERENCES `modul_ajar` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_ads_cp` FOREIGN KEY (`id_cp`) REFERENCES `cp` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_ads_tp` FOREIGN KEY (`id_tp`) REFERENCES `tp` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_ads_atp` FOREIGN KEY (`id_atp`) REFERENCES `atp` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_ads_itp` FOREIGN KEY (`id_itp`) REFERENCES `itp` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------------------
-- 39. STRUKTUR TABEL ASESMEN_DIAGNOSTIK_SOAL_OPSI (KUNCI JAWABAN & OPSI PG ASESMEN DIAGNOSTIK)
-- ------------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `asesmen_diagnostik_soal_opsi` (
  `id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `id_soal` INT(11) UNSIGNED NOT NULL COMMENT 'Relasi ke asesmen_diagnostik_soal(id)',
  `kode_opsi` ENUM('A', 'B', 'C', 'D', 'E') NOT NULL COMMENT 'Pilihan opsi jawaban (A s.d E)',
  `teks_opsi` TEXT NOT NULL COMMENT 'Narasi teks pilihan jawaban',
  `gambar_opsi` VARCHAR(255) DEFAULT NULL COMMENT 'Gambar ilustrasi opsi jika ada',
  `is_kunci_jawaban` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Kunci jawaban benar (1 = Benar, 0 = Pengecoh)',
  `penjelasan_kunci` TEXT DEFAULT NULL COMMENT 'Pembahasan ilmiah mengapa opsi ini adalah kunci jawaban yang benar',
  `bobot_nilai_opsi` DECIMAL(5,2) DEFAULT NULL COMMENT 'Nilai skor jika opsi ini dipilih',
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_ad_soal_opsi` (`id_soal`, `kode_opsi`),
  KEY `idx_adso_soal` (`id_soal`),
  KEY `idx_adso_kunci` (`is_kunci_jawaban`),
  CONSTRAINT `fk_adso_soal` FOREIGN KEY (`id_soal`) REFERENCES `asesmen_diagnostik_soal` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------------------
-- 40. STRUKTUR TABEL ASESMEN_DIAGNOSTIK_SOAL_RUBRIK (1 SOAL 1 RUBRIK PENILAIAN DIAGNOSTIK)
-- ------------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `asesmen_diagnostik_soal_rubrik` (
  `id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `id_soal` INT(11) UNSIGNED NOT NULL COMMENT 'Relasi unik 1 soal 1 rubrik ke asesmen_diagnostik_soal(id)',
  `id_itp` INT(11) UNSIGNED DEFAULT NULL COMMENT 'Relasi ke itp(id) indikator prasyarat yang diukur',
  `aspek_kompetensi` VARCHAR(255) NOT NULL COMMENT 'Aspek kemampuan prasyarat yang dinilai',
  `pedoman_penskoran` TEXT NOT NULL COMMENT 'Panduan teknis bagi guru dalam memberikan skor',
  `skor_maksimal` DECIMAL(5,2) NOT NULL DEFAULT 10.00 COMMENT 'Skor batas tertinggi untuk butir soal ini',
  `kriteria_skor_maksimal` TEXT NOT NULL COMMENT 'Deskriptor capaian bila siswa memperoleh skor maksimal',
  `kriteria_skor_sedang` TEXT DEFAULT NULL COMMENT 'Deskriptor capaian bila siswa memperoleh skor sedang/sebagian',
  `kriteria_skor_minimal` TEXT DEFAULT NULL COMMENT 'Deskriptor capaian bila siswa memperoleh skor dasar/minimal',
  `kriteria_skor_nol` TEXT DEFAULT NULL COMMENT 'Deskriptor capaian bila siswa memperoleh skor 0 (salah total/kosong)',
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_ad_rubrik_soal` (`id_soal`),
  KEY `idx_adsr_itp` (`id_itp`),
  CONSTRAINT `fk_adsr_soal` FOREIGN KEY (`id_soal`) REFERENCES `asesmen_diagnostik_soal` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_adsr_itp` FOREIGN KEY (`id_itp`) REFERENCES `itp` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------------------
-- 41. STRUKTUR TABEL NILAI_ASESMEN_DIAGNOSTIK (LOG NILAI DIAGNOSTIK PER SISWA PER PERTEMUAN KBM)
-- ------------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `nilai_asesmen_diagnostik` (
  `id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `id_siswa` INT(11) UNSIGNED NOT NULL COMMENT 'Relasi ke siswa(id) / id_mahasiswa yang dinilai',
  `id_kelas_mata_pelajaran` INT(11) UNSIGNED NOT NULL COMMENT 'Relasi ke kelas_mata_pelajaran(id) / id_kelas_matkul',
  `id_asesmen_diagnostik` INT(11) UNSIGNED NOT NULL COMMENT 'Relasi ke asesmen_diagnostik(id)',
  `id_guru` INT(11) DEFAULT NULL COMMENT 'Relasi ke users(id) guru penilai',
  `id_modul_ajar` INT(11) UNSIGNED DEFAULT NULL COMMENT 'Relasi ke modul_ajar(id)',
  `id_tp` INT(11) UNSIGNED DEFAULT NULL COMMENT 'Relasi ke tp(id)',
  `id_itp` INT(11) UNSIGNED DEFAULT NULL COMMENT 'Relasi ke itp(id)',
  `pertemuan_ke` INT(3) NOT NULL DEFAULT 1 COMMENT 'Pertemuan ke berapa KBM',
  `nilai_angka` DECIMAL(5,2) NOT NULL COMMENT 'Skor nilai angka diagnostik (0.00 s.d 100.00)',
  `nilai_kktp` DECIMAL(5,2) NOT NULL DEFAULT 75.00 COMMENT 'Kriteria acuan kesiapan belajar',
  `predikat` ENUM('A', 'B', 'C', 'D', 'E') DEFAULT NULL COMMENT 'Konversi predikat huruf',
  `status_tuntas` ENUM('Siap Belajar', 'Perlu Pendampingan Khusus', 'Perlu Penyesuaian Materi') NOT NULL DEFAULT 'Siap Belajar' COMMENT 'Status kesiapan murid',
  `catatan_evaluasi` TEXT DEFAULT NULL COMMENT 'Catatan hasil pemetaan & rekomendasi diferensiasi pembelajaran',
  `waktu_penilaian` DATETIME DEFAULT CURRENT_TIMESTAMP COMMENT 'Waktu penilaian dicatat',
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_nad_siswa` (`id_kelas_mata_pelajaran`, `id_siswa`, `id_asesmen_diagnostik`),
  KEY `idx_nad_siswa` (`id_siswa`),
  KEY `idx_nad_kmp` (`id_kelas_mata_pelajaran`),
  KEY `idx_nad_ad` (`id_asesmen_diagnostik`),
  KEY `idx_nad_guru` (`id_guru`),
  KEY `idx_nad_ma` (`id_modul_ajar`),
  KEY `idx_nad_tp` (`id_tp`),
  KEY `idx_nad_itp` (`id_itp`),
  CONSTRAINT `fk_nad_siswa` FOREIGN KEY (`id_siswa`) REFERENCES `siswa` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_nad_kmp` FOREIGN KEY (`id_kelas_mata_pelajaran`) REFERENCES `kelas_mata_pelajaran` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_nad_ad` FOREIGN KEY (`id_asesmen_diagnostik`) REFERENCES `asesmen_diagnostik` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_nad_guru` FOREIGN KEY (`id_guru`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_nad_ma` FOREIGN KEY (`id_modul_ajar`) REFERENCES `modul_ajar` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_nad_tp` FOREIGN KEY (`id_tp`) REFERENCES `tp` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_nad_itp` FOREIGN KEY (`id_itp`) REFERENCES `itp` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------------------
-- 42. STRUKTUR TABEL ASESMEN_FORMATIF (MASTER EVALUASI PROSES KBM & UMPAN BALIK BELAJAR)
-- ------------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `asesmen_formatif` (
  `id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `id_kelas_mata_pelajaran` INT(11) UNSIGNED NOT NULL COMMENT 'Relasi ke kelas_mata_pelajaran(id) / id_kelas_matkul',
  `id_guru` INT(11) DEFAULT NULL COMMENT 'Relasi ke users(id) guru penyusun',
  `id_modul_ajar` INT(11) UNSIGNED DEFAULT NULL COMMENT 'Relasi ke modul_ajar(id) pertemuan terkait',
  `id_cp` INT(11) UNSIGNED DEFAULT NULL COMMENT 'Relasi ke cp(id)',
  `id_tp` INT(11) UNSIGNED DEFAULT NULL COMMENT 'Relasi ke tp(id)',
  `id_atp` INT(11) UNSIGNED DEFAULT NULL COMMENT 'Relasi ke atp(id)',
  `id_itp` INT(11) UNSIGNED DEFAULT NULL COMMENT 'Relasi ke itp(id)',
  `pertemuan_ke` INT(3) NOT NULL DEFAULT 1 COMMENT 'Pertemuan ke berapa KBM',
  `judul_asesmen` VARCHAR(255) NOT NULL COMMENT 'Judul asesmen formatif (contoh: Kuis Cek Pemahaman Tajwid & Berpikir Kritis)',
  `teknik_asesmen` ENUM('Tes Tertulis', 'Kuis Lisan', 'Observasi Kinerja', 'Penilaian Diri', 'Penilaian Antarteman') NOT NULL DEFAULT 'Tes Tertulis' COMMENT 'Teknik penilaian formatif selama proses KBM',
  `tujuan_asesmen` TEXT NOT NULL COMMENT 'Tujuan umpan balik perbaikan pembelajaran',
  `petunjuk_pengerjaan` TEXT NOT NULL COMMENT 'Instruksi pengerjaan bagi siswa',
  `tanggal_pelaksanaan` DATE NOT NULL COMMENT 'Hari dan tanggal pelaksanaan asesmen formatif',
  `jam_mulai` TIME NOT NULL DEFAULT '07:30:00' COMMENT 'Jam mulai pelaksanaan ujian',
  `jam_selesai` TIME NOT NULL DEFAULT '09:00:00' COMMENT 'Jam batas akhir pelaksanaan ujian',
  `durasi_menit` INT(4) NOT NULL DEFAULT 60 COMMENT 'Durasi pengerjaan dalam menit',
  `status` ENUM('Draft', 'Aktif', 'Ditutup') NOT NULL DEFAULT 'Aktif',
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_af_kmp` (`id_kelas_mata_pelajaran`),
  KEY `idx_af_guru` (`id_guru`),
  KEY `idx_af_ma` (`id_modul_ajar`),
  KEY `idx_af_cp` (`id_cp`),
  KEY `idx_af_tp` (`id_tp`),
  KEY `idx_af_atp` (`id_atp`),
  KEY `idx_af_itp` (`id_itp`),
  CONSTRAINT `fk_af_kmp` FOREIGN KEY (`id_kelas_mata_pelajaran`) REFERENCES `kelas_mata_pelajaran` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_af_guru` FOREIGN KEY (`id_guru`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_af_ma` FOREIGN KEY (`id_modul_ajar`) REFERENCES `modul_ajar` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_af_cp` FOREIGN KEY (`id_cp`) REFERENCES `cp` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_af_tp` FOREIGN KEY (`id_tp`) REFERENCES `tp` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_af_atp` FOREIGN KEY (`id_atp`) REFERENCES `atp` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_af_itp` FOREIGN KEY (`id_itp`) REFERENCES `itp` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------------------
-- 43. STRUKTUR TABEL ASESMEN_FORMATIF_SOAL (BUTIR SOAL ASESMEN FORMATIF PER PERTEMUAN KBM)
-- ------------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `asesmen_formatif_soal` (
  `id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `id_asesmen_formatif` INT(11) UNSIGNED NOT NULL COMMENT 'Relasi ke master asesmen_formatif(id)',
  `id_kelas_mata_pelajaran` INT(11) UNSIGNED NOT NULL COMMENT 'Relasi ke kelas_mata_pelajaran(id) / id_kelas_matkul',
  `id_modul_ajar` INT(11) UNSIGNED DEFAULT NULL COMMENT 'Relasi ke modul_ajar(id)',
  `pertemuan_ke` INT(3) NOT NULL DEFAULT 1 COMMENT 'Pertemuan ke berapa KBM',
  `id_cp` INT(11) UNSIGNED DEFAULT NULL COMMENT 'Relasi ke cp(id)',
  `id_tp` INT(11) UNSIGNED DEFAULT NULL COMMENT 'Relasi ke tp(id)',
  `id_atp` INT(11) UNSIGNED DEFAULT NULL COMMENT 'Relasi ke atp(id)',
  `id_itp` INT(11) UNSIGNED DEFAULT NULL COMMENT 'Relasi ke itp(id)',
  `nomor_soal` INT(3) NOT NULL DEFAULT 1 COMMENT 'Nomor urut butir soal (1, 2, 3...)',
  `tipe_soal` ENUM('Pilihan Ganda', 'Pilihan Ganda Kompleks', 'Essay / Uraian', 'Benar Salah', 'Menjodohkan') NOT NULL DEFAULT 'Pilihan Ganda' COMMENT 'Tipe format soal',
  `pertanyaan` TEXT NOT NULL COMMENT 'Teks stimulus dan butir pertanyaan formatif',
  `gambar_stimulus` VARCHAR(255) DEFAULT NULL COMMENT 'Gambar ilustrasi/grafik jika ada',
  `tingkat_kesulitan` ENUM('Mudah', 'Sedang', 'Sukar') NOT NULL DEFAULT 'Sedang',
  `level_kognitif` ENUM('C1 - Mengingat', 'C2 - Memahami', 'C3 - Mengaplikasikan', 'C4 - Menganalisis', 'C5 - Mengevaluasi', 'C6 - Mencipta') NOT NULL DEFAULT 'C3 - Mengaplikasikan',
  `bobot_skor` DECIMAL(5,2) NOT NULL DEFAULT 10.00 COMMENT 'Bobot skor maksimal butir soal ini',
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_afs_af` (`id_asesmen_formatif`),
  KEY `idx_afs_kmp` (`id_kelas_mata_pelajaran`),
  KEY `idx_afs_ma` (`id_modul_ajar`),
  KEY `idx_afs_cp` (`id_cp`),
  KEY `idx_afs_tp` (`id_tp`),
  KEY `idx_afs_atp` (`id_atp`),
  KEY `idx_afs_itp` (`id_itp`),
  CONSTRAINT `fk_afs_af` FOREIGN KEY (`id_asesmen_formatif`) REFERENCES `asesmen_formatif` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_afs_kmp` FOREIGN KEY (`id_kelas_mata_pelajaran`) REFERENCES `kelas_mata_pelajaran` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_afs_ma` FOREIGN KEY (`id_modul_ajar`) REFERENCES `modul_ajar` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_afs_cp` FOREIGN KEY (`id_cp`) REFERENCES `cp` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_afs_tp` FOREIGN KEY (`id_tp`) REFERENCES `tp` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_afs_atp` FOREIGN KEY (`id_atp`) REFERENCES `atp` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_afs_itp` FOREIGN KEY (`id_itp`) REFERENCES `itp` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------------------
-- 44. STRUKTUR TABEL ASESMEN_FORMATIF_SOAL_OPSI (KUNCI JAWABAN & OPSI PG ASESMEN FORMATIF)
-- ------------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `asesmen_formatif_soal_opsi` (
  `id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `id_soal` INT(11) UNSIGNED NOT NULL COMMENT 'Relasi ke asesmen_formatif_soal(id)',
  `kode_opsi` ENUM('A', 'B', 'C', 'D', 'E') NOT NULL COMMENT 'Pilihan opsi jawaban (A s.d E)',
  `teks_opsi` TEXT NOT NULL COMMENT 'Narasi teks pilihan jawaban',
  `gambar_opsi` VARCHAR(255) DEFAULT NULL COMMENT 'Gambar ilustrasi opsi jika ada',
  `is_kunci_jawaban` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Kunci jawaban benar (1 = Benar, 0 = Pengecoh)',
  `penjelasan_kunci` TEXT DEFAULT NULL COMMENT 'Pembahasan ilmiah mengapa opsi ini adalah kunci jawaban yang benar',
  `bobot_nilai_opsi` DECIMAL(5,2) DEFAULT NULL COMMENT 'Nilai skor jika opsi ini dipilih',
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_af_soal_opsi` (`id_soal`, `kode_opsi`),
  KEY `idx_afso_soal` (`id_soal`),
  KEY `idx_afso_kunci` (`is_kunci_jawaban`),
  CONSTRAINT `fk_afso_soal` FOREIGN KEY (`id_soal`) REFERENCES `asesmen_formatif_soal` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------------------
-- 45. STRUKTUR TABEL ASESMEN_FORMATIF_SOAL_RUBRIK (1 SOAL 1 RUBRIK PENILAIAN FORMATIF)
-- ------------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `asesmen_formatif_soal_rubrik` (
  `id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `id_soal` INT(11) UNSIGNED NOT NULL COMMENT 'Relasi unik 1 soal 1 rubrik ke asesmen_formatif_soal(id)',
  `id_itp` INT(11) UNSIGNED DEFAULT NULL COMMENT 'Relasi ke itp(id) indikator tujuan pembelajaran yang diukur',
  `aspek_kompetensi` VARCHAR(255) NOT NULL COMMENT 'Aspek proses yang dinilai',
  `pedoman_penskoran` TEXT NOT NULL COMMENT 'Panduan teknis bagi guru dalam memberikan skor',
  `skor_maksimal` DECIMAL(5,2) NOT NULL DEFAULT 10.00 COMMENT 'Skor batas tertinggi untuk butir soal ini',
  `kriteria_skor_maksimal` TEXT NOT NULL COMMENT 'Deskriptor capaian bila siswa memperoleh skor maksimal',
  `kriteria_skor_sedang` TEXT DEFAULT NULL COMMENT 'Deskriptor capaian bila siswa memperoleh skor sedang/sebagian',
  `kriteria_skor_minimal` TEXT DEFAULT NULL COMMENT 'Deskriptor capaian bila siswa memperoleh skor dasar/minimal',
  `kriteria_skor_nol` TEXT DEFAULT NULL COMMENT 'Deskriptor capaian bila siswa memperoleh skor 0 (salah total/kosong)',
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_af_rubrik_soal` (`id_soal`),
  KEY `idx_afsr_itp` (`id_itp`),
  CONSTRAINT `fk_afsr_soal` FOREIGN KEY (`id_soal`) REFERENCES `asesmen_formatif_soal` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_afsr_itp` FOREIGN KEY (`id_itp`) REFERENCES `itp` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------------------
-- 46. STRUKTUR TABEL AKSES_ASESMEN_FORMATIF (MASTER ID UNIK & KREDENSIAL LOGIN SISWA DI APLIKASI UJIAN)
-- ------------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `akses_asesmen_formatif` (
  `id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `id_asesmen_formatif` INT(11) UNSIGNED NOT NULL COMMENT 'Relasi ke master asesmen_formatif(id)',
  `id_siswa` INT(11) UNSIGNED NOT NULL COMMENT 'Relasi ke siswa(id) peserta ujian',
  `id_kelas_mata_pelajaran` INT(11) UNSIGNED NOT NULL COMMENT 'Relasi ke kelas_mata_pelajaran(id) / id_kelas_matkul',
  `nomor_peserta` VARCHAR(50) NOT NULL COMMENT 'Nomor peserta ujian formatif (contoh: FMT-2026-X1-001)',
  `username_ujian` VARCHAR(50) NOT NULL COMMENT 'Username unik login siswa di aplikasi CBT/asesmen terpisah',
  `password_ujian` VARCHAR(100) NOT NULL COMMENT 'Password / PIN unik akses pengerjaan soal',
  `token_sesi` VARCHAR(50) DEFAULT NULL COMMENT 'Token sesi ujian dinamis',
  `status_akses` ENUM('Belum Mulai', 'Sedang Mengerjakan', 'Selesai', 'Terkunci', 'Izin Keluar') NOT NULL DEFAULT 'Belum Mulai' COMMENT 'Status pengerjaan di aplikasi ujian',
  `waktu_mulai` DATETIME DEFAULT NULL COMMENT 'Waktu siswa mulai mengerjakan soal',
  `waktu_selesai` DATETIME DEFAULT NULL COMMENT 'Waktu siswa menyelesaikan soal',
  `sisa_waktu_detik` INT(11) DEFAULT NULL COMMENT 'Sisa durasi pengerjaan jika terputus/logout',
  `ip_address` VARCHAR(45) DEFAULT NULL COMMENT 'Alamat IP perangkat saat login ujian',
  `user_agent` VARCHAR(255) DEFAULT NULL COMMENT 'Browser/perangkat yang digunakan saat pengerjaan',
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_aaf_username` (`username_ujian`),
  UNIQUE KEY `unique_aaf_siswa` (`id_asesmen_formatif`, `id_siswa`),
  KEY `idx_aaf_af` (`id_asesmen_formatif`),
  KEY `idx_aaf_siswa` (`id_siswa`),
  KEY `idx_aaf_kmp` (`id_kelas_mata_pelajaran`),
  KEY `idx_aaf_status` (`status_akses`),
  CONSTRAINT `fk_aaf_af` FOREIGN KEY (`id_asesmen_formatif`) REFERENCES `asesmen_formatif` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_aaf_siswa` FOREIGN KEY (`id_siswa`) REFERENCES `siswa` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_aaf_kmp` FOREIGN KEY (`id_kelas_mata_pelajaran`) REFERENCES `kelas_mata_pelajaran` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------------------
-- 47. STRUKTUR TABEL NILAI_ASESMEN_FORMATIF (LOG NILAI FORMATIF PER SISWA PER PERTEMUAN KBM)
-- ------------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `nilai_asesmen_formatif` (
  `id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `id_siswa` INT(11) UNSIGNED NOT NULL COMMENT 'Relasi ke siswa(id) / id_mahasiswa yang dinilai',
  `id_kelas_mata_pelajaran` INT(11) UNSIGNED NOT NULL COMMENT 'Relasi ke kelas_mata_pelajaran(id) / id_kelas_matkul',
  `id_asesmen_formatif` INT(11) UNSIGNED NOT NULL COMMENT 'Relasi ke master asesmen_formatif(id)',
  `id_guru` INT(11) DEFAULT NULL COMMENT 'Relasi ke users(id) guru penilai',
  `id_modul_ajar` INT(11) UNSIGNED DEFAULT NULL COMMENT 'Relasi ke modul_ajar(id)',
  `id_tp` INT(11) UNSIGNED DEFAULT NULL COMMENT 'Relasi ke tp(id)',
  `id_itp` INT(11) UNSIGNED DEFAULT NULL COMMENT 'Relasi ke itp(id)',
  `pertemuan_ke` INT(3) NOT NULL DEFAULT 1 COMMENT 'Pertemuan ke berapa KBM',
  `nilai_angka` DECIMAL(5,2) NOT NULL COMMENT 'Skor nilai angka formatif (0.00 s.d 100.00)',
  `nilai_kktp` DECIMAL(5,2) NOT NULL DEFAULT 75.00 COMMENT 'Kriteria acuan ketercapaian proses',
  `predikat` ENUM('A', 'B', 'C', 'D', 'E') DEFAULT NULL COMMENT 'Konversi predikat huruf',
  `status_tuntas` ENUM('Tuntas', 'Belum Tuntas', 'Perlu Penguatan') NOT NULL DEFAULT 'Tuntas' COMMENT 'Status ketercapaian formatif',
  `catatan_evaluasi` TEXT DEFAULT NULL COMMENT 'Catatan umpan balik pembinaan dari guru',
  `waktu_penilaian` DATETIME DEFAULT CURRENT_TIMESTAMP COMMENT 'Waktu penilaian dicatat',
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_naf_siswa` (`id_kelas_mata_pelajaran`, `id_siswa`, `id_asesmen_formatif`),
  KEY `idx_naf_siswa` (`id_siswa`),
  KEY `idx_naf_kmp` (`id_kelas_mata_pelajaran`),
  KEY `idx_naf_af` (`id_asesmen_formatif`),
  KEY `idx_naf_guru` (`id_guru`),
  KEY `idx_naf_ma` (`id_modul_ajar`),
  KEY `idx_naf_tp` (`id_tp`),
  KEY `idx_naf_itp` (`id_itp`),
  CONSTRAINT `fk_naf_siswa` FOREIGN KEY (`id_siswa`) REFERENCES `siswa` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_naf_kmp` FOREIGN KEY (`id_kelas_mata_pelajaran`) REFERENCES `kelas_mata_pelajaran` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_naf_af` FOREIGN KEY (`id_asesmen_formatif`) REFERENCES `asesmen_formatif` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_naf_guru` FOREIGN KEY (`id_guru`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_naf_ma` FOREIGN KEY (`id_modul_ajar`) REFERENCES `modul_ajar` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_naf_tp` FOREIGN KEY (`id_tp`) REFERENCES `tp` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_naf_itp` FOREIGN KEY (`id_itp`) REFERENCES `itp` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------------------
-- 48. STRUKTUR TABEL ASESMEN_SUMATIF (MASTER UJI KETERCAPAIAN TUJUAN PEMBELAJARAN: LM, STS, ASAS)
-- ------------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `asesmen_sumatif` (
  `id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `id_kelas_mata_pelajaran` INT(11) UNSIGNED DEFAULT NULL COMMENT 'Relasi opsional kelas utama / backward compatibility (multi-kelas tersimpan di asesmen_sumatif_kelas)',
  `id_guru` INT(11) DEFAULT NULL COMMENT 'Relasi ke users(id) guru penyusun',
  `id_modul_ajar` INT(11) UNSIGNED DEFAULT NULL COMMENT 'Relasi ke modul_ajar(id) pertemuan terkait',
  `id_cp` INT(11) UNSIGNED DEFAULT NULL COMMENT 'Relasi ke cp(id)',
  `id_tp` INT(11) UNSIGNED DEFAULT NULL COMMENT 'Relasi ke tp(id)',
  `id_atp` INT(11) UNSIGNED DEFAULT NULL COMMENT 'Relasi ke atp(id)',
  `id_itp` INT(11) UNSIGNED DEFAULT NULL COMMENT 'Relasi ke itp(id)',
  `pertemuan_ke` INT(3) NOT NULL DEFAULT 1 COMMENT 'Pertemuan ke berapa KBM',
  `judul_asesmen` VARCHAR(255) NOT NULL COMMENT 'Judul asesmen sumatif (contoh: Asesmen Sumatif Bab 1 Berpikir Kritis)',
  `jenis_sumatif` ENUM('Sumatif Lingkup Materi', 'Sumatif Tengah Semester', 'Sumatif Akhir Semester') NOT NULL DEFAULT 'Sumatif Lingkup Materi' COMMENT 'Kategori sumatif (AS, STS, ASAS)',
  `kktp` DECIMAL(5,2) NOT NULL DEFAULT 75.00 COMMENT 'Kriteria Ketercapaian Tujuan Pembelajaran minimal',
  `durasi_menit` INT(4) NOT NULL DEFAULT 60 COMMENT 'Durasi pengerjaan dalam menit',
  `tanggal_pelaksanaan` DATE NOT NULL COMMENT 'Hari dan tanggal pelaksanaan asesmen sumatif',
  `jam_mulai` TIME NOT NULL DEFAULT '07:30:00' COMMENT 'Jam mulai pelaksanaan ujian',
  `jam_selesai` TIME NOT NULL DEFAULT '09:00:00' COMMENT 'Jam batas akhir pelaksanaan ujian',
  `petunjuk_pengerjaan` TEXT NOT NULL COMMENT 'Tata tertib dan instruksi pengerjaan ujian',
  `status` ENUM('Draft', 'Aktif', 'Ditutup') NOT NULL DEFAULT 'Aktif',
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_as_kmp` (`id_kelas_mata_pelajaran`),
  KEY `idx_as_guru` (`id_guru`),
  KEY `idx_as_ma` (`id_modul_ajar`),
  KEY `idx_as_cp` (`id_cp`),
  KEY `idx_as_tp` (`id_tp`),
  KEY `idx_as_atp` (`id_atp`),
  KEY `idx_as_itp` (`id_itp`),
  CONSTRAINT `fk_as_kmp` FOREIGN KEY (`id_kelas_mata_pelajaran`) REFERENCES `kelas_mata_pelajaran` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_as_guru` FOREIGN KEY (`id_guru`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_as_ma` FOREIGN KEY (`id_modul_ajar`) REFERENCES `modul_ajar` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_as_cp` FOREIGN KEY (`id_cp`) REFERENCES `cp` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_as_tp` FOREIGN KEY (`id_tp`) REFERENCES `tp` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_as_atp` FOREIGN KEY (`id_atp`) REFERENCES `atp` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_as_itp` FOREIGN KEY (`id_itp`) REFERENCES `itp` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------------------
-- 48b. STRUKTUR TABEL ASESMEN_SUMATIF_KELAS (RELASI MULTI-KELAS / MANY-TO-MANY ASESMEN SUMATIF)
-- ------------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `asesmen_sumatif_kelas` (
  `id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `id_asesmen_sumatif` INT(11) UNSIGNED NOT NULL COMMENT 'Relasi ke master asesmen_sumatif(id)',
  `id_kelas_mata_pelajaran` INT(11) UNSIGNED NOT NULL COMMENT 'Relasi ke kelas_mata_pelajaran(id) rombel peserta asesmen',
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_as_kelas` (`id_asesmen_sumatif`, `id_kelas_mata_pelajaran`),
  KEY `idx_ask_as` (`id_asesmen_sumatif`),
  KEY `idx_ask_kmp` (`id_kelas_mata_pelajaran`),
  CONSTRAINT `fk_ask_as` FOREIGN KEY (`id_asesmen_sumatif`) REFERENCES `asesmen_sumatif` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_ask_kmp` FOREIGN KEY (`id_kelas_mata_pelajaran`) REFERENCES `kelas_mata_pelajaran` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------------------
-- 49. STRUKTUR TABEL ASESMEN_SUMATIF_SOAL (BUTIR SOAL ASESMEN SUMATIF PER PERTEMUAN KBM)
-- ------------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `asesmen_sumatif_soal` (
  `id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `id_asesmen_sumatif` INT(11) UNSIGNED NOT NULL COMMENT 'Relasi ke master asesmen_sumatif(id)',
  `id_kelas_mata_pelajaran` INT(11) UNSIGNED DEFAULT NULL COMMENT 'Relasi opsional kelas mata pelajaran (bisa NULL untuk butir soal lintas rombel kelas)',
  `id_modul_ajar` INT(11) UNSIGNED DEFAULT NULL COMMENT 'Relasi ke modul_ajar(id)',
  `pertemuan_ke` INT(3) NOT NULL DEFAULT 1 COMMENT 'Pertemuan ke berapa KBM',
  `id_cp` INT(11) UNSIGNED DEFAULT NULL COMMENT 'Relasi ke cp(id)',
  `id_tp` INT(11) UNSIGNED DEFAULT NULL COMMENT 'Relasi ke tp(id)',
  `id_atp` INT(11) UNSIGNED DEFAULT NULL COMMENT 'Relasi ke atp(id)',
  `id_itp` INT(11) UNSIGNED DEFAULT NULL COMMENT 'Relasi ke itp(id)',
  `nomor_soal` INT(3) NOT NULL DEFAULT 1 COMMENT 'Nomor urut butir soal (1, 2, 3...)',
  `tipe_soal` ENUM('Pilihan Ganda', 'Pilihan Ganda Kompleks', 'Essay / Uraian', 'Benar Salah', 'Menjodohkan') NOT NULL DEFAULT 'Pilihan Ganda' COMMENT 'Tipe format soal',
  `pertanyaan` TEXT NOT NULL COMMENT 'Teks stimulus dan butir pertanyaan sumatif',
  `gambar_stimulus` VARCHAR(255) DEFAULT NULL COMMENT 'Gambar ilustrasi/grafik jika ada',
  `tingkat_kesulitan` ENUM('Mudah', 'Sedang', 'Sukar') NOT NULL DEFAULT 'Sedang',
  `level_kognitif` ENUM('C1 - Mengingat', 'C2 - Memahami', 'C3 - Mengaplikasikan', 'C4 - Menganalisis', 'C5 - Mengevaluasi', 'C6 - Mencipta') NOT NULL DEFAULT 'C3 - Mengaplikasikan',
  `bobot_skor` DECIMAL(5,2) NOT NULL DEFAULT 10.00 COMMENT 'Bobot skor maksimal butir soal ini',
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_ass_as` (`id_asesmen_sumatif`),
  KEY `idx_ass_kmp` (`id_kelas_mata_pelajaran`),
  KEY `idx_ass_ma` (`id_modul_ajar`),
  KEY `idx_ass_cp` (`id_cp`),
  KEY `idx_ass_tp` (`id_tp`),
  KEY `idx_ass_atp` (`id_atp`),
  KEY `idx_ass_itp` (`id_itp`),
  CONSTRAINT `fk_ass_as` FOREIGN KEY (`id_asesmen_sumatif`) REFERENCES `asesmen_sumatif` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_ass_kmp` FOREIGN KEY (`id_kelas_mata_pelajaran`) REFERENCES `kelas_mata_pelajaran` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_ass_ma` FOREIGN KEY (`id_modul_ajar`) REFERENCES `modul_ajar` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_ass_cp` FOREIGN KEY (`id_cp`) REFERENCES `cp` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_ass_tp` FOREIGN KEY (`id_tp`) REFERENCES `tp` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_ass_atp` FOREIGN KEY (`id_atp`) REFERENCES `atp` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_ass_itp` FOREIGN KEY (`id_itp`) REFERENCES `itp` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------------------
-- 50. STRUKTUR TABEL ASESMEN_SUMATIF_SOAL_OPSI (KUNCI JAWABAN & OPSI PG ASESMEN SUMATIF)
-- ------------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `asesmen_sumatif_soal_opsi` (
  `id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `id_soal` INT(11) UNSIGNED NOT NULL COMMENT 'Relasi ke asesmen_sumatif_soal(id)',
  `kode_opsi` ENUM('A', 'B', 'C', 'D', 'E') NOT NULL COMMENT 'Pilihan opsi jawaban (A s.d E)',
  `teks_opsi` TEXT NOT NULL COMMENT 'Narasi teks pilihan jawaban',
  `gambar_opsi` VARCHAR(255) DEFAULT NULL COMMENT 'Gambar ilustrasi opsi jika ada',
  `is_kunci_jawaban` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Kunci jawaban benar (1 = Benar, 0 = Pengecoh)',
  `penjelasan_kunci` TEXT DEFAULT NULL COMMENT 'Pembahasan ilmiah mengapa opsi ini adalah kunci jawaban yang benar',
  `bobot_nilai_opsi` DECIMAL(5,2) DEFAULT NULL COMMENT 'Nilai skor jika opsi ini dipilih',
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_as_soal_opsi` (`id_soal`, `kode_opsi`),
  KEY `idx_asso_soal` (`id_soal`),
  KEY `idx_asso_kunci` (`is_kunci_jawaban`),
  CONSTRAINT `fk_asso_soal` FOREIGN KEY (`id_soal`) REFERENCES `asesmen_sumatif_soal` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------------------
-- 51. STRUKTUR TABEL ASESMEN_SUMATIF_SOAL_RUBRIK (1 SOAL 1 RUBRIK PENILAIAN SUMATIF PG & ESSAY)
-- ------------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `asesmen_sumatif_soal_rubrik` (
  `id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `id_soal` INT(11) UNSIGNED NOT NULL COMMENT 'Relasi unik 1 soal 1 rubrik ke asesmen_sumatif_soal(id)',
  `id_itp` INT(11) UNSIGNED DEFAULT NULL COMMENT 'Relasi ke itp(id) indikator tujuan pembelajaran yang diukur',
  `aspek_kompetensi` VARCHAR(255) NOT NULL COMMENT 'Aspek kognitif/keterampilan yang dinilai',
  `pedoman_penskoran` TEXT NOT NULL COMMENT 'Panduan teknis bagi guru dalam memberikan skor',
  `skor_maksimal` DECIMAL(5,2) NOT NULL DEFAULT 10.00 COMMENT 'Skor batas tertinggi untuk soal ini',
  `kriteria_skor_maksimal` TEXT NOT NULL COMMENT 'Deskriptor capaian bila siswa memperoleh skor maksimal',
  `kriteria_skor_sedang` TEXT DEFAULT NULL COMMENT 'Deskriptor capaian bila siswa memperoleh skor sedang/sebagian',
  `kriteria_skor_minimal` TEXT DEFAULT NULL COMMENT 'Deskriptor capaian bila siswa memperoleh skor dasar/minimal',
  `kriteria_skor_nol` TEXT DEFAULT NULL COMMENT 'Deskriptor capaian bila siswa memperoleh skor 0 (salah total/kosong)',
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_as_rubrik_soal` (`id_soal`),
  KEY `idx_assr_itp` (`id_itp`),
  CONSTRAINT `fk_assr_soal` FOREIGN KEY (`id_soal`) REFERENCES `asesmen_sumatif_soal` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_assr_itp` FOREIGN KEY (`id_itp`) REFERENCES `itp` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------------------
-- 52. STRUKTUR TABEL AKSES_ASESMEN_SUMATIF (MASTER ID UNIK & KREDENSIAL LOGIN SISWA DI APLIKASI UJIAN)
-- ------------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `akses_asesmen_sumatif` (
  `id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `id_asesmen_sumatif` INT(11) UNSIGNED NOT NULL COMMENT 'Relasi ke master asesmen_sumatif(id)',
  `id_siswa` INT(11) UNSIGNED NOT NULL COMMENT 'Relasi ke siswa(id) peserta ujian',
  `id_kelas_mata_pelajaran` INT(11) UNSIGNED NOT NULL COMMENT 'Relasi ke kelas_mata_pelajaran(id) / id_kelas_matkul',
  `nomor_peserta` VARCHAR(50) NOT NULL COMMENT 'Nomor peserta ujian CBT (contoh: SMT-2026-X1-001)',
  `username_ujian` VARCHAR(50) NOT NULL COMMENT 'Username unik login siswa di aplikasi CBT/asesmen terpisah',
  `password_ujian` VARCHAR(100) NOT NULL COMMENT 'Password / PIN unik akses pengerjaan soal CBT',
  `token_sesi` VARCHAR(50) DEFAULT NULL COMMENT 'Token sesi ujian dinamis dari pengawas/proktor',
  `status_akses` ENUM('Belum Mulai', 'Sedang Mengerjakan', 'Selesai', 'Terkunci', 'Izin Keluar') NOT NULL DEFAULT 'Belum Mulai' COMMENT 'Status pengerjaan di aplikasi ujian',
  `waktu_mulai` DATETIME DEFAULT NULL COMMENT 'Waktu siswa mulai mengerjakan soal',
  `waktu_selesai` DATETIME DEFAULT NULL COMMENT 'Waktu siswa menyelesaikan soal',
  `sisa_waktu_detik` INT(11) DEFAULT NULL COMMENT 'Sisa durasi pengerjaan jika terputus/logout',
  `ip_address` VARCHAR(45) DEFAULT NULL COMMENT 'Alamat IP perangkat saat login ujian',
  `user_agent` VARCHAR(255) DEFAULT NULL COMMENT 'Browser/perangkat yang digunakan saat pengerjaan',
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_aas_username` (`username_ujian`),
  UNIQUE KEY `unique_aas_siswa` (`id_asesmen_sumatif`, `id_siswa`),
  KEY `idx_aas_as` (`id_asesmen_sumatif`),
  KEY `idx_aas_siswa` (`id_siswa`),
  KEY `idx_aas_kmp` (`id_kelas_mata_pelajaran`),
  KEY `idx_aas_nomor` (`nomor_peserta`),
  KEY `idx_aas_status` (`status_akses`),
  CONSTRAINT `fk_aas_as` FOREIGN KEY (`id_asesmen_sumatif`) REFERENCES `asesmen_sumatif` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_aas_siswa` FOREIGN KEY (`id_siswa`) REFERENCES `siswa` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_aas_kmp` FOREIGN KEY (`id_kelas_mata_pelajaran`) REFERENCES `kelas_mata_pelajaran` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------------------
-- 52b. STRUKTUR TABEL ABSENSI_ASESMEN_SUMATIF (PRESENSI WAJIB SEBELUM MENGERJAKAN ASESMEN SUMATIF)
-- Alur Presensi Halaman Guru & Admin:
-- 1. Siswa wajib hadir/absen sebelum diizinkan mengerjakan asesmen sumatif.
-- 2. Guru atau Admin membuka halaman absensi sumatif dan melakukan:
--    a. Scan QR Code: Memindai QR Code nomor peserta dari kartu ujian siswa.
--    b. Input Manual: Menginput nomor peserta atau memilih siswa dari daftar rombel kelas.
-- 3. Data siswa (nama_lengkap, NISN, NIS, rombel kelas, foto) diambil langsung dari tabel `siswa`.
-- ------------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `absensi_asesmen_sumatif` (
  `id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `id_asesmen_sumatif` INT(11) UNSIGNED NOT NULL COMMENT 'Relasi ke master asesmen_sumatif(id)',
  `nomor_peserta` VARCHAR(50) NOT NULL COMMENT 'Nomor peserta ujian sumatif',
  `id_siswa` INT(11) UNSIGNED NOT NULL COMMENT 'Relasi ke siswa(id) hasil pencarian via nomor peserta',
  `id_kelas_mata_pelajaran` INT(11) UNSIGNED NOT NULL COMMENT 'Relasi ke kelas_mata_pelajaran(id)',
  `status_kehadiran` ENUM('Hadir', 'Izin', 'Sakit', 'Alpha', 'Terlambat') NOT NULL DEFAULT 'Hadir' COMMENT 'Status kehadiran',
  `metode_absen` ENUM('Scan QR', 'Manual') NOT NULL DEFAULT 'Scan QR' COMMENT 'Metode absensi: Scan QR atau Input Manual',
  `waktu_absen` DATETIME DEFAULT CURRENT_TIMESTAMP COMMENT 'Waktu kehadiran dicatat',
  `diabsen_oleh` INT(11) DEFAULT NULL COMMENT 'Relasi ke users(id) guru atau admin yang melakukan absensi',
  `keterangan` TEXT DEFAULT NULL COMMENT 'Catatan atau keterangan tambahan',
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_aasum_ujian_siswa` (`id_asesmen_sumatif`, `id_siswa`),
  KEY `idx_aasum_as` (`id_asesmen_sumatif`),
  KEY `idx_aasum_nomor` (`nomor_peserta`),
  KEY `idx_aasum_siswa` (`id_siswa`),
  KEY `idx_aasum_kmp` (`id_kelas_mata_pelajaran`),
  KEY `idx_aasum_status` (`status_kehadiran`),
  KEY `idx_aasum_petugas` (`diabsen_oleh`),
  KEY `idx_aasum_waktu` (`waktu_absen`),
  CONSTRAINT `fk_aasum_as` FOREIGN KEY (`id_asesmen_sumatif`) REFERENCES `asesmen_sumatif` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_aasum_siswa` FOREIGN KEY (`id_siswa`) REFERENCES `siswa` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_aasum_kmp` FOREIGN KEY (`id_kelas_mata_pelajaran`) REFERENCES `kelas_mata_pelajaran` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_aasum_petugas` FOREIGN KEY (`diabsen_oleh`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------------------
-- VIEW V_ABSENSI_ASESMEN_SUMATIF (LOOKUP DETAIL ABSENSI & DATA SISWA VIA NOMOR PESERTA)
-- Digunakan untuk validasi instan saat Scan QR atau Input Manual Nomor Peserta
-- ------------------------------------------------------------------------------
CREATE OR REPLACE VIEW `v_absensi_asesmen_sumatif` AS
SELECT 
  aasum.id,
  aasum.id_asesmen_sumatif,
  asum.judul_asesmen,
  asum.jenis_sumatif,
  asum.durasi_menit,
  asum.tanggal_pelaksanaan,
  aasum.nomor_peserta,
  aasum.id_siswa,
  s.nisn,
  s.nis,
  s.nama_lengkap AS nama_siswa,
  s.foto AS foto_siswa,
  s.id_kelas,
  k.nama_kelas,
  aasum.id_kelas_mata_pelajaran,
  mp.nama_mata_pelajaran,
  aasum.status_kehadiran,
  aasum.metode_absen,
  aasum.waktu_absen,
  aasum.diabsen_oleh,
  u.full_name AS nama_petugas,
  aasum.keterangan,
  aasum.created_at
FROM `absensi_asesmen_sumatif` aasum
JOIN `asesmen_sumatif` asum ON aasum.id_asesmen_sumatif = asum.id
JOIN `siswa` s ON aasum.id_siswa = s.id
LEFT JOIN `kelas` k ON s.id_kelas = k.id
JOIN `kelas_mata_pelajaran` kmp ON aasum.id_kelas_mata_pelajaran = kmp.id
LEFT JOIN `mata_pelajaran` mp ON kmp.id_mata_pelajaran = mp.id
LEFT JOIN `users` u ON aasum.diabsen_oleh = u.id;

-- ------------------------------------------------------------------------------
-- VIEW V_MONITORING_ABSENSI_SUMATIF (TABEL MONITORING KELAS UNTUK HALAMAN GURU & ADMIN)
-- Menampilkan semua siswa di kelas ujian dengan status: Hadir vs Belum Hadir
-- ------------------------------------------------------------------------------
CREATE OR REPLACE VIEW `v_monitoring_absensi_sumatif` AS
SELECT 
  aas.id AS id_akses,
  aas.id_asesmen_sumatif,
  asum.judul_asesmen,
  asum.jenis_sumatif,
  asum.tanggal_pelaksanaan,
  asum.durasi_menit,
  aas.id_kelas_mata_pelajaran,
  k.id AS id_kelas,
  k.nama_kelas,
  mp.id AS id_mata_pelajaran,
  mp.nama_mata_pelajaran,
  aas.id_siswa,
  s.nisn,
  s.nis,
  s.nama_lengkap AS nama_siswa,
  s.foto AS foto_siswa,
  aas.nomor_peserta,
  aas.username_ujian,
  aas.token_sesi,
  aas.status_akses AS status_cbt,
  aasum.id AS id_absensi,
  COALESCE(aasum.status_kehadiran, 'Belum Hadir') AS status_kehadiran,
  aasum.metode_absen,
  aasum.waktu_absen,
  aasum.diabsen_oleh,
  u.full_name AS nama_petugas,
  aasum.keterangan,
  CASE WHEN aasum.id IS NOT NULL THEN 1 ELSE 0 END AS is_sudah_absen
FROM `akses_asesmen_sumatif` aas
JOIN `asesmen_sumatif` asum ON aas.id_asesmen_sumatif = asum.id
JOIN `siswa` s ON aas.id_siswa = s.id
LEFT JOIN `kelas` k ON s.id_kelas = k.id
JOIN `kelas_mata_pelajaran` kmp ON aas.id_kelas_mata_pelajaran = kmp.id
LEFT JOIN `mata_pelajaran` mp ON kmp.id_mata_pelajaran = mp.id
LEFT JOIN `absensi_asesmen_sumatif` aasum ON (aasum.id_asesmen_sumatif = aas.id_asesmen_sumatif AND aasum.id_siswa = aas.id_siswa)
LEFT JOIN `users` u ON aasum.diabsen_oleh = u.id;

-- ------------------------------------------------------------------------------
-- 53. STRUKTUR TABEL NILAI_ASESMEN_SUMATIF (LOG NILAI SUMATIF PER SISWA PER PERTEMUAN KBM)
-- ------------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `nilai_asesmen_sumatif` (
  `id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `id_siswa` INT(11) UNSIGNED NOT NULL COMMENT 'Relasi ke siswa(id) / id_mahasiswa yang dinilai',
  `id_kelas_mata_pelajaran` INT(11) UNSIGNED NOT NULL COMMENT 'Relasi ke kelas_mata_pelajaran(id) / id_kelas_matkul',
  `id_asesmen_sumatif` INT(11) UNSIGNED NOT NULL COMMENT 'Relasi ke master asesmen_sumatif(id)',
  `id_guru` INT(11) DEFAULT NULL COMMENT 'Relasi ke users(id) guru penilai',
  `id_modul_ajar` INT(11) UNSIGNED DEFAULT NULL COMMENT 'Relasi ke modul_ajar(id)',
  `id_tp` INT(11) UNSIGNED DEFAULT NULL COMMENT 'Relasi ke tp(id)',
  `id_itp` INT(11) UNSIGNED DEFAULT NULL COMMENT 'Relasi ke itp(id)',
  `pertemuan_ke` INT(3) NOT NULL DEFAULT 1 COMMENT 'Pertemuan ke berapa KBM',
  `nilai_angka` DECIMAL(5,2) NOT NULL COMMENT 'Skor nilai angka sumatif (0.00 s.d 100.00)',
  `nilai_kktp` DECIMAL(5,2) NOT NULL DEFAULT 75.00 COMMENT 'Kriteria Ketercapaian Tujuan Pembelajaran batas tuntas',
  `predikat` ENUM('A', 'B', 'C', 'D', 'E') DEFAULT NULL COMMENT 'Konversi predikat huruf mutu',
  `status_tuntas` ENUM('Tuntas', 'Belum Tuntas', 'Perlu Remedial', 'Diberi Pengayaan') NOT NULL DEFAULT 'Tuntas' COMMENT 'Status ketercapaian siswa',
  `catatan_evaluasi` TEXT DEFAULT NULL COMMENT 'Catatan evaluasi guru & arahan tindak lanjut',
  `waktu_penilaian` DATETIME DEFAULT CURRENT_TIMESTAMP COMMENT 'Waktu penilaian dicatat',
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_nasum_siswa` (`id_kelas_mata_pelajaran`, `id_siswa`, `id_asesmen_sumatif`),
  KEY `idx_nasum_siswa` (`id_siswa`),
  KEY `idx_nasum_kmp` (`id_kelas_mata_pelajaran`),
  KEY `idx_nasum_as` (`id_asesmen_sumatif`),
  KEY `idx_nasum_guru` (`id_guru`),
  KEY `idx_nasum_ma` (`id_modul_ajar`),
  KEY `idx_nasum_tp` (`id_tp`),
  KEY `idx_nasum_itp` (`id_itp`),
  CONSTRAINT `fk_nasum_siswa` FOREIGN KEY (`id_siswa`) REFERENCES `siswa` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_nasum_kmp` FOREIGN KEY (`id_kelas_mata_pelajaran`) REFERENCES `kelas_mata_pelajaran` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_nasum_as` FOREIGN KEY (`id_asesmen_sumatif`) REFERENCES `asesmen_sumatif` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_nasum_guru` FOREIGN KEY (`id_guru`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_nasum_ma` FOREIGN KEY (`id_modul_ajar`) REFERENCES `modul_ajar` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_nasum_tp` FOREIGN KEY (`id_tp`) REFERENCES `tp` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_nasum_itp` FOREIGN KEY (`id_itp`) REFERENCES `itp` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------------------
-- 54. STRUKTUR TABEL LKPD (MASTER LEMBAR KERJA PESERTA DIDIK PER PERTEMUAN KBM)
-- ------------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `lkpd` (
  `id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `id_kelas_mata_pelajaran` INT(11) UNSIGNED NOT NULL COMMENT 'Relasi ke kelas_mata_pelajaran(id) / id_kelas_matkul',
  `id_guru` INT(11) DEFAULT NULL COMMENT 'Relasi ke users(id) guru penyusun',
  `id_modul_ajar` INT(11) UNSIGNED DEFAULT NULL COMMENT 'Relasi ke modul_ajar(id) pertemuan terkait',
  `id_cp` INT(11) UNSIGNED DEFAULT NULL COMMENT 'Relasi ke cp(id)',
  `id_tp` INT(11) UNSIGNED DEFAULT NULL COMMENT 'Relasi ke tp(id)',
  `id_atp` INT(11) UNSIGNED DEFAULT NULL COMMENT 'Relasi ke atp(id)',
  `id_itp` INT(11) UNSIGNED DEFAULT NULL COMMENT 'Relasi ke itp(id)',
  `pertemuan_ke` INT(3) NOT NULL DEFAULT 1 COMMENT 'Pertemuan ke berapa KBM',
  `nomor_lkpd` VARCHAR(50) NOT NULL DEFAULT 'LKPD-01' COMMENT 'Nomor identifikasi LKPD',
  `judul_lkpd` VARCHAR(255) NOT NULL COMMENT 'Judul LKPD (contoh: LKPD 01: Eksplorasi Fenomena Alam dan Karakteristik Ulil Albab)',
  `tujuan_aktivitas` TEXT NOT NULL COMMENT 'Tujuan aktivitas belajar yang dicapai melalui LKPD',
  `petunjuk_kerja` TEXT NOT NULL COMMENT 'Petunjuk langkah-langkah kerja siswa (individu/kelompok)',
  `alokasi_waktu_menit` INT(4) NOT NULL DEFAULT 45 COMMENT 'Alokasi durasi waktu pengerjaan',
  `file_lampiran` VARCHAR(255) DEFAULT NULL COMMENT 'File panduan LKPD PDF/Docx jika ada',
  `status` ENUM('Draft', 'Aktif', 'Ditutup') NOT NULL DEFAULT 'Aktif',
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_lkpd_kmp` (`id_kelas_mata_pelajaran`),
  KEY `idx_lkpd_guru` (`id_guru`),
  KEY `idx_lkpd_ma` (`id_modul_ajar`),
  KEY `idx_lkpd_cp` (`id_cp`),
  KEY `idx_lkpd_tp` (`id_tp`),
  KEY `idx_lkpd_atp` (`id_atp`),
  KEY `idx_lkpd_itp` (`id_itp`),
  CONSTRAINT `fk_lkpd_kmp` FOREIGN KEY (`id_kelas_mata_pelajaran`) REFERENCES `kelas_mata_pelajaran` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_lkpd_guru` FOREIGN KEY (`id_guru`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_lkpd_ma` FOREIGN KEY (`id_modul_ajar`) REFERENCES `modul_ajar` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_lkpd_cp` FOREIGN KEY (`id_cp`) REFERENCES `cp` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_lkpd_tp` FOREIGN KEY (`id_tp`) REFERENCES `tp` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_lkpd_atp` FOREIGN KEY (`id_atp`) REFERENCES `atp` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_lkpd_itp` FOREIGN KEY (`id_itp`) REFERENCES `itp` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------------------
-- 55. STRUKTUR TABEL LKPD_SOAL (BUTIR SOAL & LEMBAR AKTIVITAS LKPD PER PERTEMUAN KBM)
-- ------------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `lkpd_soal` (
  `id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `id_lkpd` INT(11) UNSIGNED NOT NULL COMMENT 'Relasi ke master lkpd(id)',
  `id_kelas_mata_pelajaran` INT(11) UNSIGNED NOT NULL COMMENT 'Relasi ke kelas_mata_pelajaran(id) / id_kelas_matkul',
  `id_modul_ajar` INT(11) UNSIGNED DEFAULT NULL COMMENT 'Relasi ke modul_ajar(id)',
  `pertemuan_ke` INT(3) NOT NULL DEFAULT 1 COMMENT 'Pertemuan ke berapa KBM',
  `id_cp` INT(11) UNSIGNED DEFAULT NULL COMMENT 'Relasi ke cp(id)',
  `id_tp` INT(11) UNSIGNED DEFAULT NULL COMMENT 'Relasi ke tp(id)',
  `id_atp` INT(11) UNSIGNED DEFAULT NULL COMMENT 'Relasi ke atp(id)',
  `id_itp` INT(11) UNSIGNED DEFAULT NULL COMMENT 'Relasi ke itp(id)',
  `nomor_soal` INT(3) NOT NULL DEFAULT 1 COMMENT 'Nomor urut butir soal (1, 2, 3...)',
  `tipe_soal` ENUM('Pilihan Ganda', 'Pilihan Ganda Kompleks', 'Essay / Uraian', 'Lembar Aktivitas Siswa', 'Eksperimen / Prosedural') NOT NULL DEFAULT 'Lembar Aktivitas Siswa' COMMENT 'Tipe format lembar aktivitas',
  `pertanyaan` TEXT NOT NULL COMMENT 'Teks stimulus, instruksi lembar aktivitas, atau butir pertanyaan',
  `gambar_stimulus` VARCHAR(255) DEFAULT NULL COMMENT 'Gambar ilustrasi/grafik/tabel aktivitas',
  `tingkat_kesulitan` ENUM('Mudah', 'Sedang', 'Sukar') NOT NULL DEFAULT 'Sedang',
  `level_kognitif` ENUM('C1 - Mengingat', 'C2 - Memahami', 'C3 - Mengaplikasikan', 'C4 - Menganalisis', 'C5 - Mengevaluasi', 'C6 - Mencipta') NOT NULL DEFAULT 'C3 - Mengaplikasikan',
  `bobot_skor` DECIMAL(5,2) NOT NULL DEFAULT 10.00 COMMENT 'Bobot skor maksimal butir lembar kerja ini',
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_lkpds_lkpd` (`id_lkpd`),
  KEY `idx_lkpds_kmp` (`id_kelas_mata_pelajaran`),
  KEY `idx_lkpds_ma` (`id_modul_ajar`),
  KEY `idx_lkpds_cp` (`id_cp`),
  KEY `idx_lkpds_tp` (`id_tp`),
  KEY `idx_lkpds_atp` (`id_atp`),
  KEY `idx_lkpds_itp` (`id_itp`),
  CONSTRAINT `fk_lkpds_lkpd` FOREIGN KEY (`id_lkpd`) REFERENCES `lkpd` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_lkpds_kmp` FOREIGN KEY (`id_kelas_mata_pelajaran`) REFERENCES `kelas_mata_pelajaran` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_lkpds_ma` FOREIGN KEY (`id_modul_ajar`) REFERENCES `modul_ajar` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_lkpds_cp` FOREIGN KEY (`id_cp`) REFERENCES `cp` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_lkpds_tp` FOREIGN KEY (`id_tp`) REFERENCES `tp` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_lkpds_atp` FOREIGN KEY (`id_atp`) REFERENCES `atp` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_lkpds_itp` FOREIGN KEY (`id_itp`) REFERENCES `itp` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------------------
-- 56. STRUKTUR TABEL LKPD_SOAL_OPSI (KUNCI JAWABAN & OPSI PG LKPD)
-- ------------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `lkpd_soal_opsi` (
  `id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `id_soal` INT(11) UNSIGNED NOT NULL COMMENT 'Relasi ke lkpd_soal(id)',
  `kode_opsi` ENUM('A', 'B', 'C', 'D', 'E') NOT NULL COMMENT 'Pilihan opsi jawaban (A s.d E)',
  `teks_opsi` TEXT NOT NULL COMMENT 'Narasi teks pilihan jawaban',
  `gambar_opsi` VARCHAR(255) DEFAULT NULL COMMENT 'Gambar ilustrasi opsi jika ada',
  `is_kunci_jawaban` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Kunci jawaban benar (1 = Benar, 0 = Pengecoh)',
  `penjelasan_kunci` TEXT DEFAULT NULL COMMENT 'Pembahasan ilmiah kunci jawaban',
  `bobot_nilai_opsi` DECIMAL(5,2) DEFAULT NULL COMMENT 'Nilai skor jika opsi ini dipilih',
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_lkpd_soal_opsi` (`id_soal`, `kode_opsi`),
  KEY `idx_lkpdso_soal` (`id_soal`),
  KEY `idx_lkpdso_kunci` (`is_kunci_jawaban`),
  CONSTRAINT `fk_lkpdso_soal` FOREIGN KEY (`id_soal`) REFERENCES `lkpd_soal` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------------------
-- 57. STRUKTUR TABEL LKPD_SOAL_RUBRIK (1 SOAL 1 RUBRIK PENILAIAN LEMBAR KERJA LKPD)
-- ------------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `lkpd_soal_rubrik` (
  `id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `id_soal` INT(11) UNSIGNED NOT NULL COMMENT 'Relasi unik 1 soal 1 rubrik ke lkpd_soal(id)',
  `id_itp` INT(11) UNSIGNED DEFAULT NULL COMMENT 'Relasi ke itp(id) indikator tujuan pembelajaran yang diukur',
  `aspek_kompetensi` VARCHAR(255) NOT NULL COMMENT 'Aspek keterampilan/kerja ilmiah yang dinilai',
  `pedoman_penskoran` TEXT NOT NULL COMMENT 'Panduan teknis bagi guru dalam memberikan skor',
  `skor_maksimal` DECIMAL(5,2) NOT NULL DEFAULT 10.00 COMMENT 'Skor batas tertinggi untuk aktivitas ini',
  `kriteria_skor_maksimal` TEXT NOT NULL COMMENT 'Deskriptor capaian bila siswa memperoleh skor maksimal',
  `kriteria_skor_sedang` TEXT DEFAULT NULL COMMENT 'Deskriptor capaian bila siswa memperoleh skor sedang/sebagian',
  `kriteria_skor_minimal` TEXT DEFAULT NULL COMMENT 'Deskriptor capaian bila siswa memperoleh skor dasar/minimal',
  `kriteria_skor_nol` TEXT DEFAULT NULL COMMENT 'Deskriptor capaian bila siswa memperoleh skor 0 (salah total/kosong)',
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_lkpd_rubrik_soal` (`id_soal`),
  KEY `idx_lkpdsr_itp` (`id_itp`),
  CONSTRAINT `fk_lkpdsr_soal` FOREIGN KEY (`id_soal`) REFERENCES `lkpd_soal` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_lkpdsr_itp` FOREIGN KEY (`id_itp`) REFERENCES `itp` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------------------
-- 58. STRUKTUR TABEL NILAI_LKPD (LOG NILAI LKPD PER SISWA PER PERTEMUAN KBM)
-- ------------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `nilai_lkpd` (
  `id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `id_siswa` INT(11) UNSIGNED NOT NULL COMMENT 'Relasi ke siswa(id) / id_mahasiswa yang dinilai',
  `id_kelas_mata_pelajaran` INT(11) UNSIGNED NOT NULL COMMENT 'Relasi ke kelas_mata_pelajaran(id) / id_kelas_matkul',
  `id_lkpd` INT(11) UNSIGNED NOT NULL COMMENT 'Relasi ke master lkpd(id)',
  `id_guru` INT(11) DEFAULT NULL COMMENT 'Relasi ke users(id) guru penilai',
  `id_modul_ajar` INT(11) UNSIGNED DEFAULT NULL COMMENT 'Relasi ke modul_ajar(id)',
  `id_tp` INT(11) UNSIGNED DEFAULT NULL COMMENT 'Relasi ke tp(id)',
  `id_itp` INT(11) UNSIGNED DEFAULT NULL COMMENT 'Relasi ke itp(id)',
  `pertemuan_ke` INT(3) NOT NULL DEFAULT 1 COMMENT 'Pertemuan ke berapa KBM',
  `nilai_angka` DECIMAL(5,2) NOT NULL COMMENT 'Skor nilai angka LKPD (0.00 s.d 100.00)',
  `nilai_kktp` DECIMAL(5,2) NOT NULL DEFAULT 75.00 COMMENT 'Kriteria Ketercapaian batas tuntas LKPD',
  `predikat` ENUM('A', 'B', 'C', 'D', 'E') DEFAULT NULL COMMENT 'Konversi predikat huruf',
  `status_tuntas` ENUM('Tuntas', 'Belum Tuntas', 'Perlu Bimbingan') NOT NULL DEFAULT 'Tuntas' COMMENT 'Status ketercapaian LKPD',
  `catatan_evaluasi` TEXT DEFAULT NULL COMMENT 'Catatan evaluasi guru terhadap hasil lembar kerja siswa',
  `waktu_penilaian` DATETIME DEFAULT CURRENT_TIMESTAMP COMMENT 'Waktu penilaian dicatat',
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_nlkpd_siswa` (`id_kelas_mata_pelajaran`, `id_siswa`, `id_lkpd`),
  KEY `idx_nlkpd_siswa` (`id_siswa`),
  KEY `idx_nlkpd_kmp` (`id_kelas_mata_pelajaran`),
  KEY `idx_nlkpd_lkpd` (`id_lkpd`),
  KEY `idx_nlkpd_guru` (`id_guru`),
  KEY `idx_nlkpd_ma` (`id_modul_ajar`),
  KEY `idx_nlkpd_tp` (`id_tp`),
  KEY `idx_nlkpd_itp` (`id_itp`),
  CONSTRAINT `fk_nlkpd_siswa` FOREIGN KEY (`id_siswa`) REFERENCES `siswa` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_nlkpd_kmp` FOREIGN KEY (`id_kelas_mata_pelajaran`) REFERENCES `kelas_mata_pelajaran` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_nlkpd_lkpd` FOREIGN KEY (`id_lkpd`) REFERENCES `lkpd` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_nlkpd_guru` FOREIGN KEY (`id_guru`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_nlkpd_ma` FOREIGN KEY (`id_modul_ajar`) REFERENCES `modul_ajar` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_nlkpd_tp` FOREIGN KEY (`id_tp`) REFERENCES `tp` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_nlkpd_itp` FOREIGN KEY (`id_itp`) REFERENCES `itp` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------------------
-- 59. STRUKTUR TABEL PENGAYAAN_REMEDIAL (MASTER PROGRAM TINDAK LANJUT HASIL ASESMEN)
-- ------------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `pengayaan_remedial` (
  `id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `id_kelas_mata_pelajaran` INT(11) UNSIGNED NOT NULL COMMENT 'Relasi ke kelas_mata_pelajaran(id) / id_kelas_matkul',
  `id_guru` INT(11) DEFAULT NULL COMMENT 'Relasi ke users(id) guru pengampu',
  `id_modul_ajar` INT(11) UNSIGNED DEFAULT NULL COMMENT 'Relasi ke modul_ajar(id)',
  `id_cp` INT(11) UNSIGNED DEFAULT NULL COMMENT 'Relasi ke cp(id)',
  `id_tp` INT(11) UNSIGNED DEFAULT NULL COMMENT 'Relasi ke tp(id)',
  `id_atp` INT(11) UNSIGNED DEFAULT NULL COMMENT 'Relasi ke atp(id)',
  `id_itp` INT(11) UNSIGNED DEFAULT NULL COMMENT 'Relasi ke itp(id)',
  `pertemuan_ke` INT(3) NOT NULL DEFAULT 1 COMMENT 'Pertemuan ke berapa KBM',
  `kategori` ENUM('Pengayaan', 'Remedial') NOT NULL DEFAULT 'Remedial' COMMENT 'Program tindak lanjut hasil asesmen',
  `judul_program` VARCHAR(255) NOT NULL COMMENT 'Judul program pengayaan / remedial',
  `kktp_minimal` DECIMAL(5,2) NOT NULL DEFAULT 75.00 COMMENT 'Standar kriteria ketuntasan acuan',
  `bentuk_kegiatan` ENUM('Bimbingan Perorangan', 'Tutor Sebaya', 'Penugasan Khusus Terbimbing', 'Pembelajaran Ulang Materi Tertentu', 'Proyek Mini Pendalaman') NOT NULL DEFAULT 'Bimbingan Perorangan' COMMENT 'Metode pelaksanaan',
  `petunjuk_pelaksanaan` TEXT NOT NULL COMMENT 'Instruksi dan alur pelaksanaan program',
  `durasi_menit` INT(11) NOT NULL DEFAULT 60 COMMENT 'Durasi pengerjaan CBT (menit)',
  `tanggal_pelaksanaan` DATE DEFAULT NULL,
  `jam_mulai` TIME DEFAULT NULL,
  `jam_selesai` TIME DEFAULT NULL,
  `status` ENUM('Draft', 'Aktif', 'Selesai', 'Ditutup') NOT NULL DEFAULT 'Aktif',
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_pr_kmp` (`id_kelas_mata_pelajaran`),
  KEY `idx_pr_guru` (`id_guru`),
  KEY `idx_pr_ma` (`id_modul_ajar`),
  KEY `idx_pr_cp` (`id_cp`),
  KEY `idx_pr_tp` (`id_tp`),
  KEY `idx_pr_atp` (`id_atp`),
  KEY `idx_pr_itp` (`id_itp`),
  CONSTRAINT `fk_pr_kmp` FOREIGN KEY (`id_kelas_mata_pelajaran`) REFERENCES `kelas_mata_pelajaran` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_pr_guru` FOREIGN KEY (`id_guru`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_pr_ma` FOREIGN KEY (`id_modul_ajar`) REFERENCES `modul_ajar` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_pr_cp` FOREIGN KEY (`id_cp`) REFERENCES `cp` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_pr_tp` FOREIGN KEY (`id_tp`) REFERENCES `tp` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_pr_atp` FOREIGN KEY (`id_atp`) REFERENCES `atp` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_pr_itp` FOREIGN KEY (`id_itp`) REFERENCES `itp` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------------------
-- 60. STRUKTUR TABEL PENGAYAAN_REMEDIAL_SOAL (BUTIR SOAL / TUGAS TINDAK LANJUT PER PERTEMUAN KBM)
-- ------------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `pengayaan_remedial_soal` (
  `id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `id_pengayaan_remedial` INT(11) UNSIGNED NOT NULL COMMENT 'Relasi ke master pengayaan_remedial(id)',
  `id_kelas_mata_pelajaran` INT(11) UNSIGNED NOT NULL COMMENT 'Relasi ke kelas_mata_pelajaran(id) / id_kelas_matkul',
  `id_modul_ajar` INT(11) UNSIGNED DEFAULT NULL COMMENT 'Relasi ke modul_ajar(id)',
  `pertemuan_ke` INT(3) NOT NULL DEFAULT 1 COMMENT 'Pertemuan ke berapa KBM',
  `id_cp` INT(11) UNSIGNED DEFAULT NULL COMMENT 'Relasi ke cp(id)',
  `id_tp` INT(11) UNSIGNED DEFAULT NULL COMMENT 'Relasi ke tp(id)',
  `id_atp` INT(11) UNSIGNED DEFAULT NULL COMMENT 'Relasi ke atp(id)',
  `id_itp` INT(11) UNSIGNED DEFAULT NULL COMMENT 'Relasi ke itp(id)',
  `nomor_soal` INT(3) NOT NULL DEFAULT 1 COMMENT 'Nomor urut butir soal (1, 2, 3...)',
  `tipe_soal` ENUM('Pilihan Ganda', 'Pilihan Ganda Kompleks', 'Essay / Uraian', 'Tugas Terbimbing', 'Studi Kasus Khusus') NOT NULL DEFAULT 'Essay / Uraian' COMMENT 'Tipe format soal tindak lanjut',
  `pertanyaan` TEXT NOT NULL COMMENT 'Teks stimulus dan butir pertanyaan tindak lanjut',
  `gambar_stimulus` VARCHAR(255) DEFAULT NULL COMMENT 'Gambar ilustrasi/grafik jika ada',
  `tingkat_kesulitan` ENUM('Mudah', 'Sedang', 'Sukar') NOT NULL DEFAULT 'Sedang',
  `level_kognitif` ENUM('C1 - Mengingat', 'C2 - Memahami', 'C3 - Mengaplikasikan', 'C4 - Menganalisis', 'C5 - Mengevaluasi', 'C6 - Mencipta') NOT NULL DEFAULT 'C3 - Mengaplikasikan',
  `bobot_skor` DECIMAL(5,2) NOT NULL DEFAULT 10.00 COMMENT 'Bobot skor maksimal butir soal ini',
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_prs_pr` (`id_pengayaan_remedial`),
  KEY `idx_prs_kmp` (`id_kelas_mata_pelajaran`),
  KEY `idx_prs_ma` (`id_modul_ajar`),
  KEY `idx_prs_cp` (`id_cp`),
  KEY `idx_prs_tp` (`id_tp`),
  KEY `idx_prs_atp` (`id_atp`),
  KEY `idx_prs_itp` (`id_itp`),
  CONSTRAINT `fk_prs_pr` FOREIGN KEY (`id_pengayaan_remedial`) REFERENCES `pengayaan_remedial` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_prs_kmp` FOREIGN KEY (`id_kelas_mata_pelajaran`) REFERENCES `kelas_mata_pelajaran` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_prs_ma` FOREIGN KEY (`id_modul_ajar`) REFERENCES `modul_ajar` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_prs_cp` FOREIGN KEY (`id_cp`) REFERENCES `cp` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_prs_tp` FOREIGN KEY (`id_tp`) REFERENCES `tp` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_prs_atp` FOREIGN KEY (`id_atp`) REFERENCES `atp` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_prs_itp` FOREIGN KEY (`id_itp`) REFERENCES `itp` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------------------
-- 61. STRUKTUR TABEL PENGAYAAN_REMEDIAL_SOAL_OPSI (KUNCI JAWABAN & OPSI PG PENGAYAAN REMEDIAL)
-- ------------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `pengayaan_remedial_soal_opsi` (
  `id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `id_soal` INT(11) UNSIGNED NOT NULL COMMENT 'Relasi ke pengayaan_remedial_soal(id)',
  `kode_opsi` ENUM('A', 'B', 'C', 'D', 'E') NOT NULL COMMENT 'Pilihan opsi jawaban (A s.d E)',
  `teks_opsi` TEXT NOT NULL COMMENT 'Narasi teks pilihan jawaban',
  `gambar_opsi` VARCHAR(255) DEFAULT NULL COMMENT 'Gambar ilustrasi opsi jika ada',
  `is_kunci_jawaban` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Kunci jawaban benar (1 = Benar, 0 = Pengecoh)',
  `penjelasan_kunci` TEXT DEFAULT NULL COMMENT 'Pembahasan ilmiah kunci jawaban',
  `bobot_nilai_opsi` DECIMAL(5,2) DEFAULT NULL COMMENT 'Nilai skor jika opsi ini dipilih',
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_pr_soal_opsi` (`id_soal`, `kode_opsi`),
  KEY `idx_prso_soal` (`id_soal`),
  KEY `idx_prso_kunci` (`is_kunci_jawaban`),
  CONSTRAINT `fk_prso_soal` FOREIGN KEY (`id_soal`) REFERENCES `pengayaan_remedial_soal` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------------------
-- 62. STRUKTUR TABEL PENGAYAAN_REMEDIAL_SOAL_RUBRIK (1 SOAL 1 RUBRIK PENILAIAN PENGAYAAN REMEDIAL)
-- ------------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `pengayaan_remedial_soal_rubrik` (
  `id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `id_soal` INT(11) UNSIGNED NOT NULL COMMENT 'Relasi unik 1 soal 1 rubrik ke pengayaan_remedial_soal(id)',
  `id_itp` INT(11) UNSIGNED DEFAULT NULL COMMENT 'Relasi ke itp(id) indikator tujuan pembelajaran yang diukur',
  `aspek_kompetensi` VARCHAR(255) NOT NULL COMMENT 'Aspek penguasaan materi yang diperbaiki/diperdalam',
  `pedoman_penskoran` TEXT NOT NULL COMMENT 'Panduan teknis bagi guru dalam memberikan skor',
  `skor_maksimal` DECIMAL(5,2) NOT NULL DEFAULT 10.00 COMMENT 'Skor batas tertinggi untuk soal ini',
  `kriteria_skor_maksimal` TEXT NOT NULL COMMENT 'Deskriptor capaian bila siswa memperoleh skor maksimal',
  `kriteria_skor_sedang` TEXT DEFAULT NULL COMMENT 'Deskriptor capaian bila siswa memperoleh skor sedang/sebagian',
  `kriteria_skor_minimal` TEXT DEFAULT NULL COMMENT 'Deskriptor capaian bila siswa memperoleh skor dasar/minimal',
  `kriteria_skor_nol` TEXT DEFAULT NULL COMMENT 'Deskriptor capaian bila siswa memperoleh skor 0 (salah total/kosong)',
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_pr_rubrik_soal` (`id_soal`),
  KEY `idx_prsr_itp` (`id_itp`),
  CONSTRAINT `fk_prsr_soal` FOREIGN KEY (`id_soal`) REFERENCES `pengayaan_remedial_soal` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_prsr_itp` FOREIGN KEY (`id_itp`) REFERENCES `itp` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------------------
-- 63. STRUKTUR TABEL NILAI_PENGAYAAN_REMEDIAL (LOG NILAI TINDAK LANJUT PER SISWA PER PERTEMUAN KBM)
-- ------------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `nilai_pengayaan_remedial` (
  `id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `id_siswa` INT(11) UNSIGNED NOT NULL COMMENT 'Relasi ke siswa(id) / id_mahasiswa yang dinilai',
  `id_kelas_mata_pelajaran` INT(11) UNSIGNED NOT NULL COMMENT 'Relasi ke kelas_mata_pelajaran(id) / id_kelas_matkul',
  `id_pengayaan_remedial` INT(11) UNSIGNED NOT NULL COMMENT 'Relasi ke master pengayaan_remedial(id)',
  `id_guru` INT(11) DEFAULT NULL COMMENT 'Relasi ke users(id) guru penilai',
  `id_modul_ajar` INT(11) UNSIGNED DEFAULT NULL COMMENT 'Relasi ke modul_ajar(id)',
  `id_tp` INT(11) UNSIGNED DEFAULT NULL COMMENT 'Relasi ke tp(id)',
  `id_itp` INT(11) UNSIGNED DEFAULT NULL COMMENT 'Relasi ke itp(id)',
  `pertemuan_ke` INT(3) NOT NULL DEFAULT 1 COMMENT 'Pertemuan ke berapa KBM',
  `nilai_awal` DECIMAL(5,2) DEFAULT NULL COMMENT 'Nilai awal sebelum remedial/pengayaan',
  `nilai_akhir` DECIMAL(5,2) NOT NULL COMMENT 'Nilai akhir setelah program tindak lanjut',
  `nilai_kktp` DECIMAL(5,2) NOT NULL DEFAULT 75.00 COMMENT 'Kriteria Ketercapaian batas tuntas',
  `predikat` ENUM('A', 'B', 'C', 'D', 'E') DEFAULT NULL COMMENT 'Konversi predikat huruf',
  `status_tuntas` ENUM('Tuntas Remedial', 'Belum Tuntas', 'Pengayaan Berhasil') NOT NULL DEFAULT 'Tuntas Remedial' COMMENT 'Status ketercapaian tindak lanjut',
  `catatan_evaluasi` TEXT DEFAULT NULL COMMENT 'Catatan evaluasi guru & hasil perkembangan siswa',
  `waktu_penilaian` DATETIME DEFAULT CURRENT_TIMESTAMP COMMENT 'Waktu penilaian dicatat',
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_npr_siswa` (`id_kelas_mata_pelajaran`, `id_siswa`, `id_pengayaan_remedial`),
  KEY `idx_npr_siswa` (`id_siswa`),
  KEY `idx_npr_kmp` (`id_kelas_mata_pelajaran`),
  KEY `idx_npr_pr` (`id_pengayaan_remedial`),
  KEY `idx_npr_guru` (`id_guru`),
  KEY `idx_npr_ma` (`id_modul_ajar`),
  KEY `idx_npr_tp` (`id_tp`),
  KEY `idx_npr_itp` (`id_itp`),
  CONSTRAINT `fk_npr_siswa` FOREIGN KEY (`id_siswa`) REFERENCES `siswa` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_npr_kmp` FOREIGN KEY (`id_kelas_mata_pelajaran`) REFERENCES `kelas_mata_pelajaran` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_npr_pr` FOREIGN KEY (`id_pengayaan_remedial`) REFERENCES `pengayaan_remedial` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_npr_guru` FOREIGN KEY (`id_guru`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_npr_ma` FOREIGN KEY (`id_modul_ajar`) REFERENCES `modul_ajar` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_npr_tp` FOREIGN KEY (`id_tp`) REFERENCES `tp` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_npr_itp` FOREIGN KEY (`id_itp`) REFERENCES `itp` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------------------
-- 63B. STRUKTUR TABEL AKSES_PENGAYAAN_REMEDIAL (AKUN LOGIN CBT PROGRAM REMEDIAL / PENGAYAAN)
-- Akun HANYA dapat dibuat jika siswa TIDAK LULUS (nilai_angka < nilai_kktp) pada ketiga
-- asesmen (diagnostik, formatif, sumatif) di mapel kelas & pertemuan yang sama.
-- Syarat diverifikasi lewat view v_kelayakan_remedial; snapshot nilai disimpan di tiket
-- dan dijaga oleh CHECK memenuhi_syarat = 1.
-- ------------------------------------------------------------------------------
-- BEGIN_AKSES_PENGAYAAN_REMEDIAL
CREATE TABLE IF NOT EXISTS `akses_pengayaan_remedial` (
  `id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `id_pengayaan_remedial` INT(11) UNSIGNED NOT NULL COMMENT 'Relasi ke master pengayaan_remedial(id)',
  `id_siswa` INT(11) UNSIGNED NOT NULL COMMENT 'Relasi ke siswa(id) peserta program',
  `id_kelas_mata_pelajaran` INT(11) UNSIGNED NOT NULL COMMENT 'Relasi ke kelas_mata_pelajaran(id)',
  `pertemuan_ke` INT(3) NOT NULL DEFAULT 1 COMMENT 'Pertemuan KBM acuan 3 asesmen',
  `id_nilai_diagnostik` INT(11) UNSIGNED DEFAULT NULL COMMENT 'Relasi ke nilai_asesmen_diagnostik(id) sumber syarat',
  `id_nilai_formatif` INT(11) UNSIGNED DEFAULT NULL COMMENT 'Relasi ke nilai_asesmen_formatif(id) sumber syarat',
  `id_nilai_sumatif` INT(11) UNSIGNED DEFAULT NULL COMMENT 'Relasi ke nilai_asesmen_sumatif(id) sumber syarat',
  `snapshot_nilai_diagnostik` DECIMAL(5,2) NOT NULL COMMENT 'Salinan nilai diagnostik saat akun dibuat',
  `snapshot_nilai_formatif` DECIMAL(5,2) NOT NULL COMMENT 'Salinan nilai formatif saat akun dibuat',
  `snapshot_nilai_sumatif` DECIMAL(5,2) NOT NULL COMMENT 'Salinan nilai sumatif saat akun dibuat',
  `kktp_acuan` DECIMAL(5,2) NOT NULL DEFAULT 75.00 COMMENT 'KKTP acuan saat verifikasi',
  `memenuhi_syarat` TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'Wajib 1: tidak lulus di 3 asesmen',
  `nomor_peserta` VARCHAR(50) NOT NULL COMMENT 'Nomor peserta (contoh: RMD-2026-K2-001)',
  `username_ujian` VARCHAR(50) NOT NULL COMMENT 'Username unik login siswa',
  `password_ujian` VARCHAR(100) NOT NULL COMMENT 'Password / PIN akses',
  `token_sesi` VARCHAR(50) DEFAULT NULL COMMENT 'Token sesi dari pengawas/guru',
  `status_akses` ENUM('Belum Mulai', 'Sedang Mengerjakan', 'Selesai', 'Terkunci', 'Izin Keluar') NOT NULL DEFAULT 'Belum Mulai',
  `waktu_mulai` DATETIME DEFAULT NULL,
  `waktu_selesai` DATETIME DEFAULT NULL,
  `sisa_waktu_detik` INT(11) DEFAULT NULL,
  `ip_address` VARCHAR(45) DEFAULT NULL,
  `user_agent` VARCHAR(255) DEFAULT NULL,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_apr_username` (`username_ujian`),
  UNIQUE KEY `unique_apr_siswa` (`id_pengayaan_remedial`, `id_siswa`),
  KEY `idx_apr_pr` (`id_pengayaan_remedial`),
  KEY `idx_apr_siswa` (`id_siswa`),
  KEY `idx_apr_kmp` (`id_kelas_mata_pelajaran`),
  KEY `idx_apr_status` (`status_akses`),
  KEY `idx_apr_nd` (`id_nilai_diagnostik`),
  KEY `idx_apr_nf` (`id_nilai_formatif`),
  KEY `idx_apr_ns` (`id_nilai_sumatif`),
  CONSTRAINT `chk_apr_syarat` CHECK (`memenuhi_syarat` = 1),
  CONSTRAINT `fk_apr_pr` FOREIGN KEY (`id_pengayaan_remedial`) REFERENCES `pengayaan_remedial` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_apr_siswa` FOREIGN KEY (`id_siswa`) REFERENCES `siswa` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_apr_kmp` FOREIGN KEY (`id_kelas_mata_pelajaran`) REFERENCES `kelas_mata_pelajaran` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_apr_nd` FOREIGN KEY (`id_nilai_diagnostik`) REFERENCES `nilai_asesmen_diagnostik` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_apr_nf` FOREIGN KEY (`id_nilai_formatif`) REFERENCES `nilai_asesmen_formatif` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_apr_ns` FOREIGN KEY (`id_nilai_sumatif`) REFERENCES `nilai_asesmen_sumatif` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- VIEW KELAYAKAN: siswa yang belum tuntas (nilai < KKTP) pada diagnostik, formatif, sumatif, ATAU terdaftar di nilai_pengayaan_remedial
CREATE OR REPLACE VIEW `v_kelayakan_remedial` AS
SELECT
  s.id AS id_siswa,
  kmp.id AS id_kelas_mata_pelajaran,
  COALESCE(ns.pertemuan_ke, nf.pertemuan_ke, nd.pertemuan_ke, npr.pertemuan_ke, 1) AS pertemuan_ke,
  nd.id AS id_nilai_diagnostik,
  nf.id AS id_nilai_formatif,
  ns.id AS id_nilai_sumatif,
  COALESCE(nd.nilai_angka, 0.00) AS nilai_diagnostik,
  COALESCE(nf.nilai_angka, 0.00) AS nilai_formatif,
  COALESCE(ns.nilai_angka, npr.nilai_awal, 0.00) AS nilai_sumatif,
  COALESCE(ns.nilai_kktp, nf.nilai_kktp, nd.nilai_kktp, npr.nilai_kktp, 75.00) AS kktp_acuan
FROM `siswa` s
JOIN `kelas_mata_pelajaran` kmp
  ON (kmp.id_kelas = s.id_kelas
      OR EXISTS (SELECT 1 FROM `nilai_asesmen_sumatif` ns2 WHERE ns2.id_siswa = s.id AND ns2.id_kelas_mata_pelajaran = kmp.id)
      OR EXISTS (SELECT 1 FROM `nilai_asesmen_formatif` nf2 WHERE nf2.id_siswa = s.id AND nf2.id_kelas_mata_pelajaran = kmp.id)
      OR EXISTS (SELECT 1 FROM `nilai_asesmen_diagnostik` nd2 WHERE nd2.id_siswa = s.id AND nd2.id_kelas_mata_pelajaran = kmp.id)
      OR EXISTS (SELECT 1 FROM `nilai_pengayaan_remedial` npr2 WHERE npr2.id_siswa = s.id AND npr2.id_kelas_mata_pelajaran = kmp.id))
LEFT JOIN `nilai_asesmen_diagnostik` nd
  ON nd.id_siswa = s.id AND (nd.id_kelas_mata_pelajaran = kmp.id OR s.id_kelas = kmp.id_kelas)
LEFT JOIN `nilai_asesmen_formatif` nf
  ON nf.id_siswa = s.id AND (nf.id_kelas_mata_pelajaran = kmp.id OR s.id_kelas = kmp.id_kelas)
LEFT JOIN `nilai_asesmen_sumatif` ns
  ON ns.id_siswa = s.id AND (ns.id_kelas_mata_pelajaran = kmp.id OR s.id_kelas = kmp.id_kelas)
LEFT JOIN `nilai_pengayaan_remedial` npr
  ON npr.id_siswa = s.id AND (npr.id_kelas_mata_pelajaran = kmp.id OR s.id_kelas = kmp.id_kelas)
WHERE (nd.id IS NOT NULL AND (nd.nilai_angka < COALESCE(NULLIF(nd.nilai_kktp, 0), 75.00) OR nd.status_tuntas IN ('Belum Tuntas', 'Perlu Pendampingan Khusus', 'Perlu Penyesuaian Materi')))
   OR (nf.id IS NOT NULL AND (nf.nilai_angka < COALESCE(NULLIF(nf.nilai_kktp, 0), 75.00) OR nf.status_tuntas IN ('Belum Tuntas', 'Perlu Penguatan')))
   OR (ns.id IS NOT NULL AND (ns.nilai_angka < COALESCE(NULLIF(ns.nilai_kktp, 0), 75.00) OR ns.status_tuntas IN ('Belum Tuntas', 'Perlu Remedial')))
   OR (npr.id IS NOT NULL AND (npr.nilai_awal < COALESCE(NULLIF(npr.nilai_kktp, 0), 75.00) OR npr.status_tuntas IN ('Belum Tuntas', 'Tuntas Remedial')));
-- END_AKSES_PENGAYAAN_REMEDIAL

-- ------------------------------------------------------------------------------
-- 64. STRUKTUR TABEL REKAP_NILAI_SISWA_MAPEL (REKAPITULASI RAPOR 1 SISWA 1 MAPEL KELAS)
-- ------------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `rekap_nilai_siswa_mapel` (
  `id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `id_siswa` INT(11) UNSIGNED NOT NULL COMMENT 'Relasi ke siswa(id) / id_mahasiswa',
  `id_kelas_mata_pelajaran` INT(11) UNSIGNED NOT NULL COMMENT 'Relasi ke kelas_mata_pelajaran(id) / id_kelas_matkul',
  `semester` ENUM('Ganjil', 'Genap') NOT NULL DEFAULT 'Ganjil',
  `tahun_pelajaran` VARCHAR(30) NOT NULL DEFAULT '2026/2027',
  `rata_diagnostik` DECIMAL(5,2) DEFAULT NULL COMMENT 'Nilai rata-rata asesmen diagnostik',
  `rata_formatif` DECIMAL(5,2) DEFAULT NULL COMMENT 'Nilai rata-rata asesmen formatif',
  `rata_lkpd` DECIMAL(5,2) DEFAULT NULL COMMENT 'Nilai rata-rata lembar kerja peserta didik',
  `rata_sumatif_materi` DECIMAL(5,2) DEFAULT NULL COMMENT 'Nilai rata-rata asesmen sumatif lingkup materi',
  `nilai_sts` DECIMAL(5,2) DEFAULT NULL COMMENT 'Nilai Sumatif Tengah Semester (STS)',
  `nilai_asas` DECIMAL(5,2) DEFAULT NULL COMMENT 'Nilai Asesmen Sumatif Akhir Semester (ASAS)',
  `rata_tugas` DECIMAL(5,2) DEFAULT NULL COMMENT 'Nilai rata-rata tugas harian',
  `nilai_proyek` DECIMAL(5,2) DEFAULT NULL COMMENT 'Nilai proyek PjBL / P5',
  `nilai_akhir_semester` DECIMAL(5,2) DEFAULT NULL COMMENT 'Nilai rapor akhir kalkulasi pembobotan kurikulum merdeka',
  `predikat_akhir` ENUM('A', 'B', 'C', 'D') DEFAULT NULL COMMENT 'Predikat kelulusan akhir mata pelajaran',
  `status_kelulusan_kktp` ENUM('Tercapai', 'Belum Tercapai') NOT NULL DEFAULT 'Tercapai',
  `deskripsi_capaian_tertinggi` TEXT DEFAULT NULL COMMENT 'Narasi deskripsi rapor untuk capaian kompetensi terbaik',
  `deskripsi_capaian_perlu_bimbingan` TEXT DEFAULT NULL COMMENT 'Narasi deskripsi rapor untuk kompetensi yang perlu peningkatan',
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_rekap_siswa_mapel` (`id_kelas_mata_pelajaran`, `id_siswa`, `semester`, `tahun_pelajaran`),
  KEY `idx_rnsm_siswa` (`id_siswa`),
  KEY `idx_rnsm_kmp` (`id_kelas_mata_pelajaran`),
  CONSTRAINT `fk_rnsm_siswa` FOREIGN KEY (`id_siswa`) REFERENCES `siswa` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_rnsm_kmp` FOREIGN KEY (`id_kelas_mata_pelajaran`) REFERENCES `kelas_mata_pelajaran` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------------------
-- 65. STRUKTUR TABEL PROYEK (MASTER PROYEK PEMBELAJARAN PjBL / P5: INDIVIDU & KELOMPOK)
-- ------------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `proyek` (
  `id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `id_kelas_mata_pelajaran` INT(11) UNSIGNED NOT NULL COMMENT 'Relasi ke kelas_mata_pelajaran(id) / id_kelas_matkul',
  `id_guru` INT(11) DEFAULT NULL COMMENT 'Relasi ke users(id) guru pembimbing',
  `id_modul_ajar` INT(11) UNSIGNED DEFAULT NULL COMMENT 'Relasi ke modul_ajar(id)',
  `id_tp` INT(11) UNSIGNED DEFAULT NULL COMMENT 'Relasi ke tp(id)',
  `id_itp` INT(11) UNSIGNED DEFAULT NULL COMMENT 'Relasi ke itp(id)',
  `nama_proyek` VARCHAR(255) NOT NULL COMMENT 'Nama/judul proyek (contoh: Proyek Pembuatan Film Pendek Adab Digital Remaja Islami)',
  `tema_proyek` VARCHAR(150) NOT NULL COMMENT 'Tema tema P5 / kurikulum merdeka (contoh: Kearifan Lokal, Bhinneka Tunggal Ika, Rekayasa & Teknologi)',
  `deskripsi_tujuan` TEXT NOT NULL COMMENT 'Deskripsi detail latar belakang dan tujuan proyek',
  `jenis_proyek` ENUM('Individu', 'Kelompok') NOT NULL DEFAULT 'Kelompok' COMMENT 'Kategori pengerjaan: Individu atau Kelompok',
  `dimensi_profil_pancasila` VARCHAR(255) DEFAULT 'Beriman, Bertakwa kepada Tuhan YME, Bergotong Royong, Kreatif, Bernalar Kritis' COMMENT 'Dimensi profil pelajar pancasila yang dikembangkan',
  `target_luaran` VARCHAR(255) NOT NULL COMMENT 'Bentuk produk akhir (Video Dokumenter, Laporan Karya, Aplikasi Web, Maket/Produk Fisik)',
  `jadwal_mulai` DATE NOT NULL COMMENT 'Tanggal dimulainya pengerjaan proyek',
  `jadwal_selesai` DATE NOT NULL COMMENT 'Tenggat batas akhir proyek selesai',
  `pedoman_penilaian_rubrik` TEXT DEFAULT NULL COMMENT 'Rubrik penilaian proses dan hasil produk proyek',
  `nilai_maksimal` DECIMAL(5,2) NOT NULL DEFAULT 100.00 COMMENT 'Nilai maksimal proyek',
  `status_proyek` ENUM('Perencanaan', 'Sedang Berjalan', 'Tahap Pengujian', 'Selesai', 'Diarsipkan') NOT NULL DEFAULT 'Sedang Berjalan',
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_proyek_kmp` (`id_kelas_mata_pelajaran`),
  KEY `idx_proyek_guru` (`id_guru`),
  KEY `idx_proyek_ma` (`id_modul_ajar`),
  KEY `idx_proyek_tp` (`id_tp`),
  KEY `idx_proyek_itp` (`id_itp`),
  CONSTRAINT `fk_proyek_kmp` FOREIGN KEY (`id_kelas_mata_pelajaran`) REFERENCES `kelas_mata_pelajaran` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_proyek_guru` FOREIGN KEY (`id_guru`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_proyek_ma` FOREIGN KEY (`id_modul_ajar`) REFERENCES `modul_ajar` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_proyek_tp` FOREIGN KEY (`id_tp`) REFERENCES `tp` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_proyek_itp` FOREIGN KEY (`id_itp`) REFERENCES `itp` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------------------
-- 66. STRUKTUR TABEL PROYEK_KELOMPOK (MASTER KELOMPOK PROYEK PEMBELAJARAN)
-- ------------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `proyek_kelompok` (
  `id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `id_proyek` INT(11) UNSIGNED NOT NULL COMMENT 'Relasi ke proyek(id)',
  `id_kelas_mata_pelajaran` INT(11) UNSIGNED NOT NULL COMMENT 'Relasi ke kelas_mata_pelajaran(id) / id_kelas_matkul',
  `nomor_kelompok` INT(3) NOT NULL DEFAULT 1 COMMENT 'Nomor urut kelompok (1, 2, 3...)',
  `nama_kelompok` VARCHAR(100) NOT NULL COMMENT 'Nama tim/kelompok (contoh: Tim Sinematografi Al-Farabi)',
  `id_ketua_kelompok` INT(11) UNSIGNED DEFAULT NULL COMMENT 'Relasi ke siswa(id) koordinator/ketua tim',
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_pk_proyek` (`id_proyek`),
  KEY `idx_pk_kmp` (`id_kelas_mata_pelajaran`),
  KEY `idx_pk_ketua` (`id_ketua_kelompok`),
  CONSTRAINT `fk_pk_proyek` FOREIGN KEY (`id_proyek`) REFERENCES `proyek` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_pk_kmp` FOREIGN KEY (`id_kelas_mata_pelajaran`) REFERENCES `kelas_mata_pelajaran` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_pk_ketua` FOREIGN KEY (`id_ketua_kelompok`) REFERENCES `siswa` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------------------
-- 67. STRUKTUR TABEL PROYEK_KELOMPOK_ANGGOTA (RINCIAN MAHASISWA/SISWA ANGGOTA KELOMPOK)
-- ------------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `proyek_kelompok_anggota` (
  `id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `id_proyek_kelompok` INT(11) UNSIGNED NOT NULL COMMENT 'Relasi ke proyek_kelompok(id)',
  `id_siswa` INT(11) UNSIGNED NOT NULL COMMENT 'Relasi ke siswa(id) / id_mahasiswa anggota',
  `id_kelas_mata_pelajaran` INT(11) UNSIGNED NOT NULL COMMENT 'Relasi ke kelas_mata_pelajaran(id)',
  `peran_anggota` VARCHAR(100) DEFAULT 'Anggota' COMMENT 'Peran spesifik (Sutradara, Editor, Penulis Naskah, Presenter, Bendahara)',
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_proyek_kelompok_siswa` (`id_proyek_kelompok`, `id_siswa`),
  KEY `idx_pka_kelompok` (`id_proyek_kelompok`),
  KEY `idx_pka_siswa` (`id_siswa`),
  KEY `idx_pka_kmp` (`id_kelas_mata_pelajaran`),
  CONSTRAINT `fk_pka_kelompok` FOREIGN KEY (`id_proyek_kelompok`) REFERENCES `proyek_kelompok` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_pka_siswa` FOREIGN KEY (`id_siswa`) REFERENCES `siswa` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_pka_kmp` FOREIGN KEY (`id_kelas_mata_pelajaran`) REFERENCES `kelas_mata_pelajaran` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------------------
-- 68. STRUKTUR TABEL PENGUMPULAN_PROYEK (LOG PENYERAHAN PRODUK, LAPORAN & VIDEO GELAR KARYA)
-- ------------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `pengumpulan_proyek` (
  `id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `id_proyek` INT(11) UNSIGNED NOT NULL COMMENT 'Relasi ke proyek(id)',
  `id_siswa` INT(11) UNSIGNED NOT NULL COMMENT 'Relasi ke siswa(id) / id_mahasiswa pengumpul/ketua',
  `id_kelas_mata_pelajaran` INT(11) UNSIGNED NOT NULL COMMENT 'Relasi ke kelas_mata_pelajaran(id) / id_kelas_matkul',
  `id_proyek_kelompok` INT(11) UNSIGNED DEFAULT NULL COMMENT 'Relasi ke proyek_kelompok(id) jika pengerjaan kelompok',
  `judul_laporan_produk` VARCHAR(255) NOT NULL COMMENT 'Judul hasil karya / laporan akhir proyek',
  `deskripsi_produk` TEXT NOT NULL COMMENT 'Deskripsi inovasi produk, manfaat, dan hasil pengerjaan',
  `file_laporan` VARCHAR(255) DEFAULT NULL COMMENT 'Path berkas laporan ilmiah / booklet PDF',
  `file_dokumentasi` VARCHAR(255) DEFAULT NULL COMMENT 'Path arsip dokumentasi proses kegiatan (ZIP/PDF)',
  `link_produk` TEXT DEFAULT NULL COMMENT 'Tautan hasil karya digital (Google Drive, Youtube, GitHub, Canva, Figma, Web Hosting)',
  `link_video_presentasi` TEXT DEFAULT NULL COMMENT 'Tautan video presentasi / pameran gelar karya',
  `waktu_pengumpulan` DATETIME DEFAULT CURRENT_TIMESTAMP COMMENT 'Waktu penyerahan hasil karya',
  `status_pengumpulan` ENUM('Diserahkan', 'Revisi', 'Disetujui', 'Dipublikasikan') NOT NULL DEFAULT 'Diserahkan',
  `nilai_proyek` DECIMAL(5,2) DEFAULT NULL COMMENT 'Skor angka hasil penilaian guru',
  `predikat_proyek` ENUM('Sangat Berkembang (SB)', 'Berkembang Sesuai Harapan (BSH)', 'Mulai Berkembang (MB)', 'Belum Berkembang (BB)') DEFAULT NULL COMMENT 'Predikat P5 / Capaian Proyek',
  `catatan_evaluasi_guru` TEXT DEFAULT NULL COMMENT 'Umpan balik dan catatan apresiasi dari guru',
  `waktu_penilaian` DATETIME DEFAULT NULL,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_pp_proyek` (`id_proyek`),
  KEY `idx_pp_siswa` (`id_siswa`),
  KEY `idx_pp_kmp` (`id_kelas_mata_pelajaran`),
  KEY `idx_pp_kelompok` (`id_proyek_kelompok`),
  CONSTRAINT `fk_pp_proyek` FOREIGN KEY (`id_proyek`) REFERENCES `proyek` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_pp_siswa` FOREIGN KEY (`id_siswa`) REFERENCES `siswa` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_pp_kmp` FOREIGN KEY (`id_kelas_mata_pelajaran`) REFERENCES `kelas_mata_pelajaran` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_pp_kelompok` FOREIGN KEY (`id_proyek_kelompok`) REFERENCES `proyek_kelompok` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------------------
-- 69. STRUKTUR TABEL BIAYA_PROYEK (RINCIAN BIAYA PENGELUARAN SELAMA MENGERJAKAN PROYEK)
-- ------------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `biaya_proyek` (
  `id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `id_proyek` INT(11) UNSIGNED NOT NULL COMMENT 'Relasi ke proyek(id)',
  `id_pengumpulan_proyek` INT(11) UNSIGNED DEFAULT NULL COMMENT 'Relasi ke pengumpulan_proyek(id) jika sudah ada laporan akhir',
  `id_proyek_kelompok` INT(11) UNSIGNED DEFAULT NULL COMMENT 'Relasi ke proyek_kelompok(id) jika proyek kelompok',
  `id_siswa` INT(11) UNSIGNED NOT NULL COMMENT 'Relasi ke siswa(id) / id_mahasiswa pencatat / bendahara pengeluaran',
  `id_kelas_mata_pelajaran` INT(11) UNSIGNED NOT NULL COMMENT 'Relasi ke kelas_mata_pelajaran(id) / id_kelas_matkul',
  `tanggal_pengeluaran` DATE NOT NULL COMMENT 'Tanggal transaksi biaya dikeluarkan (YYYY-MM-DD)',
  `kategori_biaya` ENUM('Bahan Baku & Alat', 'Percetakan & Jilid', 'Transportasi', 'Konsumsi', 'Persewaan Perangkat', 'Digital / Cloud / Lisensi', 'Lain-lain') NOT NULL DEFAULT 'Bahan Baku & Alat' COMMENT 'Pos anggaran pengeluaran',
  `nama_item_biaya` VARCHAR(255) NOT NULL COMMENT 'Nama barang / jasa (contoh: Kertas Manila & Cat Poster, Sewa Kamera 1 Hari)',
  `kuantitas` DECIMAL(8,2) NOT NULL DEFAULT 1.00 COMMENT 'Jumlah kuantitas item',
  `satuan` VARCHAR(50) DEFAULT 'pcs' COMMENT 'Satuan kuantitas (pcs, paket, unit, rim, hari, lembar)',
  `harga_satuan` DECIMAL(12,2) NOT NULL DEFAULT 0.00 COMMENT 'Harga satuan dalam Rupiah',
  `total_biaya` DECIMAL(12,2) NOT NULL DEFAULT 0.00 COMMENT 'Total nominal biaya (kuantitas x harga_satuan)',
  `bukti_transaksi` VARCHAR(255) DEFAULT NULL COMMENT 'File foto struk / nota / kwitansi belanja (jpg/png/pdf)',
  `sumber_dana` ENUM('Iuran Mandiri Siswa', 'BOS / Sekolah', 'Sponsor / Donatur', 'Kas Kelas') NOT NULL DEFAULT 'Iuran Mandiri Siswa' COMMENT 'Asal sumber pendanaan biaya',
  `status_verifikasi` ENUM('Menunggu Verifikasi', 'Diverifikasi Guru', 'Ditolak') NOT NULL DEFAULT 'Diverifikasi Guru' COMMENT 'Status verifikasi transparansi pengeluaran oleh guru',
  `catatan` TEXT DEFAULT NULL COMMENT 'Catatan keterangan tambahan penggunaan anggaran',
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_bp_proyek` (`id_proyek`),
  KEY `idx_bp_pengumpulan` (`id_pengumpulan_proyek`),
  KEY `idx_bp_kelompok` (`id_proyek_kelompok`),
  KEY `idx_bp_siswa` (`id_siswa`),
  KEY `idx_bp_kmp` (`id_kelas_mata_pelajaran`),
  KEY `idx_bp_tanggal` (`tanggal_pengeluaran`),
  CONSTRAINT `fk_bp_proyek` FOREIGN KEY (`id_proyek`) REFERENCES `proyek` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_bp_pengumpulan` FOREIGN KEY (`id_pengumpulan_proyek`) REFERENCES `pengumpulan_proyek` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_bp_kelompok` FOREIGN KEY (`id_proyek_kelompok`) REFERENCES `proyek_kelompok` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_bp_siswa` FOREIGN KEY (`id_siswa`) REFERENCES `siswa` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_bp_kmp` FOREIGN KEY (`id_kelas_mata_pelajaran`) REFERENCES `kelas_mata_pelajaran` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;

-- ------------------------------------------------------------------------------
-- DATA AWAL / SEEDER CONTOH
-- ------------------------------------------------------------------------------
INSERT INTO `kelas` (`id`, `nama_kelas`, `tahun_pelajaran`) VALUES
(1, 'X PAI-1 (Imam Al-Bukhari)', '2026/2027'),
(2, 'X PAI-2 (Imam Muslim)', '2026/2027'),
(3, 'XI PAI-1 (Imam Asy-Syafi\'i)', '2026/2027'),
(4, 'XII PAI-1 (Imam Malik)', '2026/2027')
ON DUPLICATE KEY UPDATE `nama_kelas` = VALUES(`nama_kelas`);

INSERT INTO `siswa` (`id_kelas`, `nisn`, `nis`, `nama_lengkap`, `alamat`, `no_hp`, `gmail`, `foto`) VALUES
(1, '0081234567', '26271001', 'Muhammad Farhan Al-Fatih', 'Jl. Masjid No. 12, Jakarta', '081234567890', 'farhan.alfatih@gmail.com', 'farhan.jpg'),
(1, '0081234568', '26271002', 'Aisyah Putri Azzahra', 'Jl. Melati No. 05, Jakarta', '081234567891', 'aisyah.azzahra@gmail.com', 'aisyah.jpg'),
(2, '0081234569', '26271003', 'Ahmad Fauzan Pratama', 'Jl. Pesantren No. 08, Jakarta', '081234567892', 'fauzan.pratama@gmail.com', 'fauzan.jpg'),
(3, '0071234570', '25261001', 'Zahra Nurul Izzah', 'Jl. Cempaka Putih No. 19, Jakarta', '081234567893', 'zahra.izzah@gmail.com', 'zahra.jpg')
ON DUPLICATE KEY UPDATE `nama_lengkap` = VALUES(`nama_lengkap`);

INSERT INTO `users` (`id`, `username`, `email`, `password_hash`, `full_name`, `avatar`, `status`) VALUES
(1, 'admin', 'admin@ilmuna.my.id', '$2y$10$wE3/M5nQ6q0WfZQJ0B8CZe3qG1s8v9mK2L4P7R5T9V1X3Z5B7D9Fa', 'Administrator & Guru PAI', 'avatar-admin.png', 'active'),
(2, 'guru_pai', 'guru@ilmuna.my.id', '$2y$10$wE3/M5nQ6q0WfZQJ0B8CZe3qG1s8v9mK2L4P7R5T9V1X3Z5B7D9Fa', 'Ustadz Rahmat Hidayat, M.Pd.I', 'avatar-guru.png', 'active'),
(3, 'siswa_farhan', 'farhan@ilmuna.my.id', '$2y$10$wE3/M5nQ6q0WfZQJ0B8CZe3qG1s8v9mK2L4P7R5T9V1X3Z5B7D9Fa', 'Muhammad Farhan Al-Fatih', 'farhan.jpg', 'active'),
(7, 'Syahriel28', 'syahriel28@ilmuna.my.id', '$2y$10$wE3/M5nQ6q0WfZQJ0B8CZe3qG1s8v9mK2L4P7R5T9V1X3Z5B7D9Fa', 'Syahriel Andika (Super Admin)', 'avatar-admin.png', 'active')
ON DUPLICATE KEY UPDATE `full_name` = VALUES(`full_name`);

INSERT INTO `roles` (`id`, `code`, `name`, `description`, `badge_color`, `icon`) VALUES
(1, 'admin', 'Admin (Guru Mapel PAI)', 'Pengelola materi, tugas, penilaian, jurnal mengajar, dan manajemen akun.', 'navy', 'fas fa-chalkboard-teacher'),
(2, 'ketua_kelas', 'Ketua Kelas', 'Koordinator kelas, presensi harian siswa, dan pemantau kedisiplinan belajar.', 'teal', 'fas fa-user-tie'),
(3, 'siswa', 'Siswa', 'Pelajar aktif, akses materi pembelajaran PAI, kuis, tugas, dan mutabaah ibadah.', 'sky', 'fas fa-user-graduate'),
(4, 'guru', 'Guru', 'Guru pengampu mata pelajaran, penyusun modul ajar, materi KBM, tugas, absensi, dan asesmen.', 'emerald', 'fas fa-chalkboard-teacher')
ON DUPLICATE KEY UPDATE `name` = VALUES(`name`), `description` = VALUES(`description`), `badge_color` = VALUES(`badge_color`), `icon` = VALUES(`icon`);

-- Relasi Multi-Role: 1 User bisa memiliki > 1 role
INSERT INTO `user_roles` (`id`, `user_id`, `role_id`, `is_primary`) VALUES
(1, 1, 1, 1),
(2, 2, 4, 1),
(3, 3, 2, 1),
(4, 3, 3, 0),
(5, 6, 4, 1),
(6, 6, 1, 0),
(10, 7, 1, 1),
(11, 7, 4, 0),
(12, 7, 2, 0),
(13, 7, 3, 0)
ON DUPLICATE KEY UPDATE `is_primary` = VALUES(`is_primary`);

-- Seeder Mata Pelajaran
INSERT INTO `mata_pelajaran` (`id`, `nama_mata_pelajaran`, `tahun_pelajaran`, `semester`) VALUES
(1, 'Pendidikan Agama Islam & Budi Pekerti', '2026/2027', 'Ganjil'),
(2, 'Al-Qur\'an Hadits & Tajwid', '2026/2027', 'Ganjil'),
(3, 'Akidah Akhlak', '2026/2027', 'Ganjil'),
(4, 'Fiqih Ibadah', '2026/2027', 'Ganjil'),
(5, 'Sejarah Kebudayaan Islam (SKI)', '2026/2027', 'Ganjil'),
(6, 'Bahasa Arab', '2026/2027', 'Ganjil')
ON DUPLICATE KEY UPDATE `nama_mata_pelajaran` = VALUES(`nama_mata_pelajaran`);

-- Seeder Relasi Kelas & Mata Pelajaran
INSERT INTO `kelas_mata_pelajaran` (`id`, `id_kelas`, `id_mata_pelajaran`) VALUES
(1, 1, 1), -- X PAI-1 -> PAI & Budi Pekerti
(2, 1, 2), -- X PAI-1 -> Al-Qur'an Hadits
(3, 1, 3), -- X PAI-1 -> Akidah Akhlak
(4, 1, 4), -- X PAI-1 -> Fiqih Ibadah
(5, 2, 1), -- X PAI-2 -> PAI & Budi Pekerti
(6, 2, 2), -- X PAI-2 -> Al-Qur'an Hadits
(7, 3, 1), -- XI PAI-1 -> PAI & Budi Pekerti (Fase F)
(8, 4, 1)  -- XII PAI-1 -> PAI & Budi Pekerti
ON DUPLICATE KEY UPDATE `id_kelas` = VALUES(`id_kelas`), `id_mata_pelajaran` = VALUES(`id_mata_pelajaran`);

-- ------------------------------------------------------------------------------
-- SEEDER ELEMEN (ELEMEN CAPAIAN PEMBELAJARAN FASE F - PROTA.DOCX)
-- ------------------------------------------------------------------------------
INSERT INTO `elemen` (`id`, `id_kelas_mata_pelajaran`, `nama_elemen`, `fase`, `deskripsi`) VALUES
(1, 7, 'Al-Qur’an Hadis', 'Fase F', 'Elemen Al-Qur’an dan Hadis membimbing peserta didik memahami ayat suci dan hadis tentang pentingnya berpikir kritis, iptek, memelihara kehidupan, dan moderasi beragama.'),
(2, 7, 'Akidah', 'Fase F', 'Elemen Akidah membimbing peserta didik memahami cabang-cabang iman (syu‘ab al-īmān) serta keterkaitan harmonis antara iman, Islam, dan ihsan.'),
(3, 7, 'Akhlak', 'Fase F', 'Elemen Akhlak membimbing peserta didik menghindarkan diri dari berbagai penyakit sosial, membiasakan adab bermasyarakat, dan menerapkan etika digital Islami.'),
(4, 7, 'Fikih', 'Fase F', 'Elemen Fikih membimbing peserta didik memahami ketentuan khotbah, tabligh, dakwah, prinsip muamalah syariah modern, munakahat, dan mawaris.'),
(5, 7, 'Sejarah Peradaban Islam', 'Fase F', 'Elemen Sejarah Peradaban Islam membimbing peserta didik menelusuri keteladanan dan kontribusi ulama dunia dan ulama Nusantara serta peran organisasi Islam di Indonesia.')
ON DUPLICATE KEY UPDATE `nama_elemen` = VALUES(`nama_elemen`), `deskripsi` = VALUES(`deskripsi`);

-- ------------------------------------------------------------------------------
-- SEEDER CP (CAPAIAN PEMBELAJARAN BERELASI KE ELEMEN)
-- ------------------------------------------------------------------------------
INSERT INTO `cp` (`id`, `id_kelas_mata_pelajaran`, `id_elemen`, `cp`) VALUES
(1, 7, 1, 'Memahami ayat Al-Qur’an dan hadist tentang pentingnya berpikir kritis, ilmu pengetahuan dan teknologi, memelihara kehidupan manusia, dan moderasi beragama.'),
(2, 7, 2, 'Memahami beberapa cabang iman (syu‘ab al īmān), keterkaitan antara iman, Islam, dan ihsan.'),
(3, 7, 3, 'Memahami manfaat menghindari penyakit sosial; Memahami adab bermasyarakat dan etika digital dalam Islam.'),
(4, 7, 4, 'Memahami ketentuan khotbah, tabligh dan dakwah, muamalah, munakahat, dan mawaris.'),
(5, 7, 5, 'Memahami peran tokoh ulama dalam perkembangan peradaban Islam di dunia dan peran organisasi-organisasi Islam di Indonesia.')
ON DUPLICATE KEY UPDATE `cp` = VALUES(`cp`), `id_elemen` = VALUES(`id_elemen`);

-- ------------------------------------------------------------------------------
-- SEEDER TP (TUJUAN PEMBELAJARAN BERELASI KE CP - PROTA.DOCX & PROSEM.XLSX)
-- ------------------------------------------------------------------------------
INSERT INTO `tp` (`id`, `id_cp`, `kode_tp`, `materi_pokok`, `alokasi_jam`, `tp`) VALUES
-- Semester Ganjil (TP 1 s.d 5)
(1, 1, '1.1-1.2', 'Berpikir Kritis dalam Islam', 4, 
 'Menghafal ayat Al-Quran dan Hadis tentang pentingnya berpikir kritis. Menafsirkan ayat Al Qur’an dan Hadis tentang pentingnya berpikir kritis.'),
(2, 2, '2.1-2.3', 'Pengertian dan Dalil tentang Syu’ab al-iman.', 6, 
 '2.1 Mempelajari Pengertian dan Dalil tentang Syu’ab al-iman. 2.2 Mengklasifikasi Pengertian dan Dalil tentang Syu’ab al-iman. 2.3 Mencontohkan Pengertian dan Dalil tentang Syu’ab al-iman.'),
(3, 3, '3.1-3.3', 'Penyakit sosial dalam perspektif Islam', 6, 
 '3.1 Menunjukkan beberapa penyakit sosial dalam perspektif Islam. 3.2 Menguraikan beberapa penyakit sosial dalam Perspektif Islam. 3.3 Mencontohkan beberapa penyakit sosial dalam perspektif Islam.'),
(4, 4, '4.1-4.3', 'Khotbah, Tabligh, dan Dakwah', 6, 
 '4.1 Menelusuri ketentuan Khotbah, Tabligh, dan Dakwah. 4.2 Menggali ketentuan Khotbah, Tabligh, dan Dakwah. 4.3 Merangkum ketentuan Khotbah, Tabligh, dan Dakwah.'),
(5, 5, '5.1-5.2', 'Tokoh ulama dunia', 4, 
 '5.1 Menulis peran tokoh ulama dunia. 5.2 Menceritakan peran tokoh ulama dunia.'),

-- Semester Genap (TP 6 s.d 10)
(6, 1, '1.1-1.2', 'Ilmu Pengetahuan dan Teknologi (IPTEK)', 4, 
 'Menghafal ayat Al-Quran dan Hadis tentang Ilmu Pengetahuan dan Teknologi (IPTEK). Menafsirkan ayat Al Qur’an dan Hadis tentang Ilmu Pengetahuan dan Teknologi (IPTEK).'),
(7, 2, '2.1-2.3', 'Keterkaitan antara Iman, Islam, dan Ihsan', 6, 
 '2.1 Mempelajari Pengertian dan Dalil tentang Keterkaitan antara Iman, Islam, dan Ihsan. 2.2 Mengklasifikasi Pengertian dan Dalil. 2.3 Mencontohkan Keterkaitan antara Iman, Islam, dan Ihsan.'),
(8, 3, '3.1-3.3', 'Adab bermasyarakat', 6, 
 '3.1 Menunjukkan adab bermasyarakat. 3.2 Menguraikan adab bermasyarakat. 3.3 Mencontohkan adab bermasyarakat.'),
(9, 4, '4.1-4.3', 'fiqh muamalah (prinsip halal-haram, akad, riba, gharar, ekonomi syariah)', 6, 
 '4.1 Menelusuri fiqh muamalah (prinsip halal-haram, akad, riba, gharar, ekonomi syariah, fintech, koperasi syariah). 4.2 Menggali fiqh muamalah. 4.3 Merangkum fiqh muamalah modern.'),
(10, 5, '5.1-5.2', 'Tokoh ulama Nusantara', 4, 
 '5.1 Menulis peran tokoh ulama Nusantara. 5.2 Menceritakan peran tokoh ulama Nusantara.')
ON DUPLICATE KEY UPDATE `tp` = VALUES(`tp`), `kode_tp` = VALUES(`kode_tp`), `materi_pokok` = VALUES(`materi_pokok`), `alokasi_jam` = VALUES(`alokasi_jam`);

-- ------------------------------------------------------------------------------
-- SEEDER ATP (ALUR TUJUAN PEMBELAJARAN BERELASI KE TP)
-- ------------------------------------------------------------------------------
INSERT INTO `atp` (`id`, `id_tp`, `urutan`, `atp`) VALUES
(1, 1, 1, '11.1 Membaca QS. Ali Imran: 190-191 dan hadis terkait berpikir kritis dengan tartil.'),
(2, 1, 2, '11.2 Menganalisis pesan dan tafsir ayat berpikir kritis dalam konteks kemajuan teknologi.'),
(3, 2, 1, '11.3 Menelaah 77 cabang iman (syu\'ab al-iman) dan klasifikasi 3 ranah (lisan, hati, perbuatan).'),
(4, 3, 1, '11.4 Mengidentifikasi dampak destruktif khamr, judi, pertikaian, dan etika bermedia sosial.'),
(5, 4, 1, '11.5 Membedakan syarat dan rukun khotbah Jumat, tabligh, dan dakwah serta adab penceramah.'),
(6, 5, 1, '11.6 Mengkaji biografi dan sumbangsih Ibnu Sina, Al-Ghazali, dan ulama dunia bagi peradaban.'),
(7, 6, 1, '11.7 Mengkaji dalil Al-Qur\'an tentang etos pengembangan sains dan teknologi ramah lingkungan.'),
(8, 7, 1, '11.8 Mensintesis integrasi pilar Iman, Islam, dan Ihsan dalam membentuk karakter insan kamil.'),
(9, 8, 1, '11.9 Mempraktikkan adab bertetangga, musyawarah, dan interaksi toleran dalam kebhinekaan.'),
(10, 9, 1, '11.10 Menganalisis transaksi jual-beli syariah, larangan riba, bai al-mudharabah, dan fintech.'),
(11, 10, 1, '11.11 Meneladani perjuangan Wali Songo, KH Hasyim Asy\'ari, dan KH Ahmad Dahlan di Nusantara.')
ON DUPLICATE KEY UPDATE `atp` = VALUES(`atp`), `urutan` = VALUES(`urutan`);

-- ------------------------------------------------------------------------------
-- SEEDER ITP (INDIKATOR TUJUAN PEMBELAJARAN / IKTP BERELASI KE TP & ATP)
-- ------------------------------------------------------------------------------
INSERT INTO `itp` (`id`, `id_tp`, `id_atp`, `kode_itp`, `itp`, `deskripsi_ketercapaian`, `urutan`) VALUES
-- TP 1: Berpikir Kritis dalam Islam (ATP 1, 2)
(1,  1, 1, '1.1.1', 'Membaca QS. Ali Imran: 190-191 sesuai kaidah tajwid dan makhraj secara tartil.', 'Peserta didik mampu melafalkan ayat dengan tartil tanpa kesalahan harakat maupun makhraj.', 1),
(2,  1, 1, '1.1.2', 'Menghafal QS. Ali Imran: 190-191 dan hadis terkait berpikir kritis dengan lancar.', 'Peserta didik mampu menyetorkan hafalan tartil tanpa bantuan mushaf.', 2),
(3,  1, 2, '1.2.1', 'Menganalisis makna dan pesan tersirat QS. Ali Imran: 190-191 tentang karakteristik ulil albab.', 'Peserta didik mampu menguraikan ciri-ciri ulil albab dan merefleksikannya dalam kemajuan zaman.', 3),

-- TP 2: Syu'ab al-iman (ATP 3)
(4,  2, 3, '2.1.1', 'Mengidentifikasi 77 cabang iman (syu‘ab al-īmān) beserta dalil naqlinya secara tepat.', 'Peserta didik mampu menyebutkan cabang iman pokok dalam lisan, hati, dan perbuatan.', 1),
(5,  2, 3, '2.2.1', 'Mengklasifikasikan cabang iman ke dalam dimensi ma\'rifatun bil qalbi, iqrarun bil lisan, dan \'amalun bil arkan.', 'Peserta didik mampu memetakan contoh amalan ke dalam 3 ranah cabang iman.', 2),

-- TP 3: Penyakit sosial dalam perspektif Islam (ATP 4)
(6,  3, 4, '3.1.1', 'Mengidentifikasi ragam penyakit sosial (khamr, judi, pertikaian, dan hoaks) serta bahaya destruktifnya.', 'Peserta didik mampu menjelaskan dampak buruk penyakit sosial bagi individu dan masyarakat.', 1),
(7,  3, 4, '3.2.1', 'Menganalisis dalil larangan dan merumuskan langkah preventif etika bermedia sosial.', 'Peserta didik mampu menyusun panduan etika digital islami dalam pergaulan remaja.', 2),

-- TP 4: Khotbah, Tabligh, dan Dakwah (ATP 5)
(8,  4, 5, '4.1.1', 'Membedakan rukun, syarat, dan sunnah antara khotbah, tabligh, dan dakwah.', 'Peserta didik mampu menyusun matriks perbandingan rukun dan syarat secara komprehensif.', 1),
(9,  4, 5, '4.2.1', 'Menyusun naskah khotbah/dakwah singkat bertema moderasi beragama dan toleransi.', 'Peserta didik mampu menyusun dan memperagakan ceramah ringkas dengan bahasa santun.', 2),

-- TP 5: Tokoh ulama dunia (ATP 6)
(10, 5, 6, '5.1.1', 'Menelusuri biografi dan karya monumental ilmuwan muslim abad pertengahan (Ibnu Sina, Al-Ghazali).', 'Peserta didik mampu merangkum kontribusi keilmuan peradaban Islam bagi kemajuan dunia.', 1),

-- TP 6: IPTEK dalam Islam (ATP 7)
(11, 6, 7, '6.1.1', 'Menganalisis dalil Al-Qur\'an tentang etos riset dan inovasi sains teknologi ramah lingkungan.', 'Peserta didik mampu mengaitkan ayat kauniyah dengan pengembangan teknologi modern.', 1),

-- TP 7: Keterkaitan Iman, Islam, dan Ihsan (ATP 8)
(12, 7, 8, '7.1.1', 'Menjelaskan integrasi pilar Iman, Islam, dan Ihsan dalam membentuk karakter insan kamil.', 'Peserta didik mampu mengilustrasikan hubungan harmonis iman, syariat, dan ihsan.', 1),

-- TP 8: Adab bermasyarakat (ATP 9)
(13, 8, 9, '8.1.1', 'Menerapkan adab bertetangga, memuliakan tamu, dan interaksi toleran antar-umat beragama.', 'Peserta didik mampu mendemonstrasikan sikap empati dan adab sosial dalam kebhinekaan.', 1),

-- TP 9: Fiqh muamalah modern (ATP 10)
(14, 9, 10, '9.1.1', 'Menganalisis prinsip akad jual-beli syariah, larangan riba, bai al-mudharabah, dan fintech.', 'Peserta didik mampu menilai keabsahan akad transaksi e-commerce dan lembaga keuangan syariah.', 1),

-- TP 10: Tokoh ulama Nusantara (ATP 11)
(15, 10, 11, '10.1.1', 'Meneladani strategi dakwah santun Wali Songo, KH Hasyim Asy\'ari, dan KH Ahmad Dahlan.', 'Peserta didik mampu mempresentasikan nilai kearifan lokal dalam dakwah Islam di Nusantara.', 1)
ON DUPLICATE KEY UPDATE `itp` = VALUES(`itp`), `deskripsi_ketercapaian` = VALUES(`deskripsi_ketercapaian`), `urutan` = VALUES(`urutan`);

-- ------------------------------------------------------------------------------
-- SEEDER MODEL PEMBELAJARAN (LENGKAP BESERTA SINTAKS OPERASIONAL)
-- ------------------------------------------------------------------------------
INSERT INTO `model_pembelajaran` (`id`, `nama_model`, `slug`, `kategori`, `deskripsi`, `sintaks`) VALUES
(1, 'Problem Based Learning (PBL)', 'problem-based-learning', 'Kurikulum Merdeka', 
 'Model pembelajaran berbasis pemecahan masalah kontekstual autentik dunia nyata. Mengembangkan daya pikir kritis (HOTS), kolaborasi, serta kemandirian pemecahan masalah secara saintifik.',
 'Tahap 1: Orientasi peserta didik pada masalah kontekstual\nTahap 2: Mengorganisasikan peserta didik untuk belajar\nTahap 3: Membimbing penyelidikan individu maupun kelompok\nTahap 4: Mengembangkan dan menyajikan hasil karya pemecahan masalah\nTahap 5: Menganalisis dan mengevaluasi proses pemecahan masalah'),

(2, 'Project Based Learning (PjBL)', 'project-based-learning', 'Kurikulum Merdeka',
 'Model pembelajaran berbasis proyek yang mengorganisasikan pembelajaran di sekitar proyek nyata. Murid merancang, membuat jadwal, memonitor, menguji hasil, dan memamerkan produk karya autentik.',
 'Tahap 1: Penentuan pertanyaan mendasar (Start with the Essential Question)\nTahap 2: Mendesain perencanaan produk proyek (Design a Plan for the Project)\nTahap 3: Menyusun jadwal pembuatan proyek (Create a Schedule)\nTahap 4: Memonitor keaktifan dan perkembangan proyek (Monitor the Students and the Progress)\nTahap 5: Menguji hasil dan asesmen produk karya (Assess the Outcome)\nTahap 6: Mengevaluasi pengalaman belajar dan refleksi (Evaluate the Experience)'),

(3, 'Discovery Learning', 'discovery-learning', 'Kurikulum Merdeka',
 'Model pembelajaran penemuan induktif di mana peserta didik mengorganisasikan sendiri materi konsep melalui eksplorasi fenomena, merumuskan hipotesis, dan membuktikannya secara empiris.',
 'Tahap 1: Pemberian rangsangan / stimulus (Stimulation)\nTahap 2: Pernyataan / identifikasi masalah (Problem Statement)\nTahap 3: Pengumpulan data empiris (Data Collection)\nTahap 4: Pengolahan data dan klasifikasi informasi (Data Processing)\nTahap 5: Pembuktian kebenaran hipotesis (Verification)\nTahap 6: Menarik simpulan dan generalisasi konsep (Generalization)'),

(4, 'Inquiry Based Learning', 'inquiry-based-learning', 'Kurikulum Merdeka',
 'Model pembelajaran berbasis penyelidikan ilmiah terstruktur untuk menemukan kebenaran fakta dan konsep melalui rasa ingin tahu, perumusan masalah mendalam, dan eksperimen.',
 'Tahap 1: Orientasi dan pembentukan iklim penyelidikan\nTahap 2: Merumuskan masalah ilmiah secara terukur\nTahap 3: Mengajukan hipotesis sementara\nTahap 4: Mengumpulkan data melalui investigasi empiris\nTahap 5: Menguji hipotesis berbasis temuan data\nTahap 6: Merumuskan kesimpulan dan refleksi konsep'),

(5, 'Cooperative Learning (Jigsaw / STAD)', 'cooperative-learning', 'Kurikulum Merdeka',
 'Model pembelajaran kooperatif di mana peserta didik belajar bersama dalam kelompok kecil heterogen yang saling melengkapi peran untuk menguasai kompetensi secara gotong royong.',
 'Tahap 1: Menyampaikan tujuan pembelajaran dan memotivasi peserta didik\nTahap 2: Menyajikan informasi esensial materi pokok\nTahap 3: Mengorganisasikan peserta didik ke dalam kelompok belajar (kelompok asal & kelompok ahli)\nTahap 4: Membimbing kelompok belajar dan bekerja sama\nTahap 5: Evaluasi capaian kompetensi individu dan tim\nTahap 6: Memberikan penghargaan dan pengakuan keberhasilan kelompok'),

(6, 'Contextual Teaching and Learning (CTL)', 'contextual-teaching-and-learning', 'Kurikulum Merdeka',
 'Model pembelajaran yang mengaitkan materi ajar dengan konteks nyata kehidupan siswa, menghubungkan pengetahuan teoritis dengan aplikasi fungsional di masyarakat.',
 'Tahap 1: Konstruktivisme (Constructivism) - Mengaitkan skema awal\nTahap 2: Menemukan (Inquiry) - Eksplorasi konsep di lingkungan sekitar\nTahap 3: Bertanya (Questioning) - Menumbuhkan daya nalar kritis\nTahap 4: Masyarakat Belajar (Learning Community) - Diskusi kolaboratif\nTahap 5: Pemodelan (Modeling) - Demonstrasi tindakan atau keterampilan\nTahap 6: Refleksi (Reflection) - Menghayati kebermaknaan ilmu\nTahap 7: Penilaian Autentik (Authentic Assessment) - Asesmen performa komprehensif'),

(7, 'Pembelajaran Berdiferensiasi (Differentiated Instruction)', 'differentiated-instruction', 'Kurikulum Merdeka',
 'Pendekatan pembelajaran inti Kurikulum Merdeka yang memodifikasi konten, proses, dan produk agar selaras dengan kesiapan belajar, minat, dan profil gaya belajar murid.',
 'Tahap 1: Asesmen diagnostik awal (pemetaan minat, kesiapan belajar & profil murid)\nTahap 2: Diferensiasi Konten, Proses, dan Produk berdasarkan hasil diagnostik\nTahap 3: Pelaksanaan aktivitas berjenjang dengan pendampingan bertingkat (scaffolding)\nTahap 4: Asesmen formatif berkala dan pameran unjuk kerja portofolio'),

(8, 'Flipped Classroom (Blended Learning)', 'flipped-classroom', 'Kurikulum Merdeka',
 'Model pembelajaran terbalik di mana materi teoritis dipelajari mandiri sebelum kelas tatap muka, sehingga waktu di kelas difokuskan untuk diskusi mendalam, bedah kasus, dan proyek kolaboratif.',
 'Tahap 1: Eksplorasi mandiri pra-tatap muka (menyimak modul digital / video pembelajaran)\nTahap 2: Verifikasi dan pengecekan pemahaman konsep di awal kelas\nTahap 3: Diskusi interaktif, pemecahan studi kasus nyata, dan kolaborasi terbimbing\nTahap 4: Refleksi, asesmen pemahaman, dan proyek pengayaan lanjutan'),

(9, 'Teaching at the Right Level (TaRL)', 'teaching-at-the-right-level', 'Kurikulum Merdeka',
 'Pendekatan pembelajaran Kurikulum Merdeka yang berpusat pada tingkat ketercapaian kompetensi nyata murid (bukan semata usia/jenjang kelas), memastikan fondasi literasi dan numerasi kokoh.',
 'Tahap 1: Asesmen awal diagnostik kompetensi literasi dan numerasi murid\nTahap 2: Pengelompokan peserta didik berdasarkan fase level kemampuan riil\nTahap 3: Intervensi pembelajaran yang disesuaikan dengan kebutuhan spesifik tiap kelompok\nTahap 4: Evaluasi formatif berkelanjutan dan penyesuaian level kelompok belajar'),

(10, 'Creative Problem Solving (CPS)', 'creative-problem-solving', 'Kurikulum Merdeka',
 'Model pemecahan masalah kreatif yang melatih murid menggabungkan pemikiran divergen (eksplorasi ide baru) dan konvergen (seleksi solusi terbaik) untuk menyelesaikan tantangan sosial.',
 'Tahap 1: Klarifikasi tantangan dan identifikasi fakta masalah (Clarify)\nTahap 2: Perumusan pertanyaan inti masalah yang tajam (Problem-Finding)\nTahap 3: Penggalian ide kreatif alternatif tanpa batas (Idea-Finding / Brainstorming)\nTahap 4: Pengembangan dan penyempurnaan rancangan solusi (Solution-Finding)\nTahap 5: Perencanaan implementasi dan tindakan nyata (Acceptance-Finding)')
ON DUPLICATE KEY UPDATE `nama_model` = VALUES(`nama_model`), `deskripsi` = VALUES(`deskripsi`), `sintaks` = VALUES(`sintaks`);

-- ------------------------------------------------------------------------------
-- SEEDER DETAIL SINTAKS MODEL PEMBELAJARAN (PER TAHAP, GURU & SISWA)
-- ------------------------------------------------------------------------------
INSERT INTO `sintaks_model_pembelajaran` (`id_model_pembelajaran`, `tahap_ke`, `nama_fase`, `aktivitas_guru`, `aktivitas_siswa`) VALUES
-- 1. PBL
(1, 1, 'Orientasi Peserta Didik pada Masalah', 
 'Menjelaskan tujuan pembelajaran, menyajikan fenomena/kasus kontekstual melalui video/studi kasus nyata, dan memantik pertanyaan pemecahan masalah.', 
 'Menyimak paparan masalah, mencermati fenomena, mengidentifikasi akar permasalahan, dan merumuskan fokus persoalan yang akan dipecahkan.'),
(1, 2, 'Mengorganisasikan Peserta Didik untuk Belajar', 
 'Membagi siswa ke dalam kelompok heterogen, menetapkan batasan tugas, dan memastikan setiap anggota memahami peran belajarnya.', 
 'Membentuk kelompok, membagi peran dan tanggung jawab anggota tim, serta menyusun rencana investigasi bersama.'),
(1, 3, 'Membimbing Penyelidikan Individu dan Kelompok', 
 'Mendorong peserta didik mengumpulkan data empiris, membimbing pencarian literatur/kajian dalil, dan memantau jalannya diskusi tim.', 
 'Mengeksplorasi sumber belajar, mengumpulkan informasi, berdiskusi, dan menguji berbagai alternatif solusi pemecahan masalah.'),
(1, 4, 'Mengembangkan dan Menyajikan Hasil Karya', 
 'Membimbing pembuatan laporan, infografis, poster, atau slide presentasi pemecahan masalah, serta mengatur giliran presentasi.', 
 'Menyusun laporan atau artefak karya penyelesaian masalah secara terstruktur dan mempresentasikannya di depan kelas dengan percaya diri.'),
(1, 5, 'Menganalisis dan Mengevaluasi Proses Pemecahan Masalah', 
 'Memfasilitasi sesi tanya jawab, mengoreksi miskonsepsi, memberikan penguatan konsep ilmiah, dan merefleksi proses pembelajaran.', 
 'Menerima tanggapan dari kelompok lain, melakukan refleksi atas tahapan pemecahan masalah yang telah dilalui, dan menarik kesimpulan umum.'),

-- 2. PjBL
(2, 1, 'Penentuan Pertanyaan Mendasar (Start with Essential Question)', 
 'Mengajukan pertanyaan pemantik kontekstual yang menginspirasi penciptaan karya/produk yang menjawab permasalahan nyata.', 
 'Merespons pertanyaan pemantik, mengajukan ide solusi kreatif, dan menyepakati produk yang akan dikembangkan dalam proyek.'),
(2, 2, 'Mendesain Perencanaan Proyek (Design a Plan)', 
 'Memfasilitasi perancangan desain produk, pemilihan alat/bahan, penentuan kriteria kualitas produk, dan pembagian tugas tim.', 
 'Merancang skema desain produk, menentukan spesifikasi bahan, dan membagi pembagian peran kerja dalam kelompok.'),
(2, 3, 'Menyusun Jadwal Pembuatan (Create a Schedule)', 
 'Membimbing penetapan jadwal milestone pengerjaan, batas akhir tahapan, dan mekanisme pelaporan progres.', 
 'Menyepakati jadwal kerja proyek, timeline pembuatan langkah demi langkah, dan target penyelesaian tepat waktu.'),
(2, 4, 'Memonitor Perkembangan Proyek (Monitor the Progress)', 
 'Memantau perkembangan pembuatan karya, memberikan bimbingan teknis saat murid mengalami kendala, dan mencatat rubrik proses.', 
 'Mengerjakan proyek sesuai jadwal, mendokumentasikan progres dalam lembar kerja proyek, dan berkonsultasi mengenai kendala tim.'),
(2, 5, 'Menguji Hasil / Asesmen Produk (Assess the Outcome)', 
 'Melakukan asesmen ketercapaian standar kompetensi produk, menguji fungsionalitas dan kemanfaatan produk yang dihasilkan.', 
 'Mempamerkan produk proyek, mendemonstrasikan cara kerja atau manfaat produk, dan menjelaskan proses pembuatannya.'),
(2, 6, 'Mengevaluasi Pengalaman (Evaluate the Experience)', 
 'Mengarahkan refleksi kolektif mengenai hambatan, keberhasilan tim, dan potensi pengembangan produk lebih lanjut.', 
 'Mengungkapkan pengalaman berharga selama mengerjakan proyek, menerima masukan evaluatif, dan membuat simpulan perbaikan masa depan.'),

-- 3. Discovery Learning
(3, 1, 'Pemberian Rangsangan (Stimulation)', 
 'Menampilkan fenomena menarik, gambar, teks kontradiktif, atau studi kasus untuk memicu kebingungan positif dan rasa penasaran.', 
 'Mengamati fenomena yang disajikan, mencatat keanehan atau pertanyaan yang muncul di benaknya.'),
(3, 2, 'Pernyataan / Identifikasi Masalah (Problem Statement)', 
 'Memberi kesempatan kepada peserta didik untuk merumuskan hipotesis terkait fenomena yang baru diamati.', 
 'Memilih pertanyaan pokok yang relevan dan merumuskannya dalam bentuk pernyataan hipotesis yang dapat diuji.'),
(3, 3, 'Pengumpulan Data (Data Collection)', 
 'Menyediakan bahan ajar, modul rujukan, lembar observasi, dan memfasilitasi wawancara atau kajian literatur.', 
 'Membaca modul, menelaah referensi, melakukan eksperimen/pengamatan, dan mencatat data temuan sebanyak-banyaknya.'),
(3, 4, 'Pengolahan Data (Data Processing)', 
 'Membimbing penafsiran data, klasifikasi konsep, dan pengecekan apakah data yang diperoleh logis dan terstruktur.', 
 'Mengolah data temuan, mengaitkan antarvariabel, dan menyusun sintesis informasi hasil penemuan.'),
(3, 5, 'Pembuktian (Verification)', 
 'Membantu siswa memeriksa secara cermat kesesuaian antara temuan data dengan rumusan hipotesis awal.', 
 'Mencocokkan temuan dengan hipotesis awal untuk membuktikan apakah hipotesis diterima atau ditolak berdasarkan fakta.'),
(3, 6, 'Menarik Kesimpulan / Generalisasi (Generalization)', 
 'Memberikan penegasan konsep esensial, memvalidasi kesimpulan siswa, dan menghubungkannya dengan konteks yang lebih luas.', 
 'Merumuskan kaidah/prinsip umum dari hasil penemuan dan mempresentasikannya sebagai pengetahuan baru yang utuh.'),

-- 4. Inquiry Learning
(4, 1, 'Orientasi Masalah', 
 'Membina iklim penyelidikan yang kondusif, memancing rasa ingin tahu melalui analogi atau demonstrasi mini.', 
 'Menyimak orientasi guru, memfokuskan perhatian pada persoalan ilmiah yang hendak diselidiki.'),
(4, 2, 'Merumuskan Masalah', 
 'Mengarahkan ruang lingkup penyelidikan agar fokus dan dapat diuji secara ilmiah oleh peserta didik.', 
 'Menyusun kalimat rumusan masalah berbasis 5W1H yang terukur dan spesifik.'),
(4, 3, 'Mengajukan Hipotesis', 
 'Mendorong siswa membuat praduga beralasan berdasarkan pengetahuan awal yang mereka miliki.', 
 'Menuliskan dugaan awal (hipotesis) yang disertai argumen logis awal.'),
(4, 4, 'Mengumpulkan Data', 
 'Memandu teknis observasi, penyebaran angket, kajian ayat/hadits/literatur, atau eksperimen mandiri.', 
 'Melaksanakan pengumpulan data lapangan atau kajian pustaka secara cermat dan objektif.'),
(4, 5, 'Menguji Hipotesis', 
 'Mendampingi analisis uji silang data dan membantu siswa menginterpretasikan bukti-bukti yang didapat.', 
 'Menganalisis data, membandingkan fakta dengan dugaan awal untuk memastikan keabsahan hipotesis.'),
(4, 6, 'Merumuskan Kesimpulan', 
 'Mengonfirmasi temuan akhir dan mengarahkan siswa untuk menyimpulkan pembelajaran secara sistematis.', 
 'Menyusun laporan kesimpulan hasil penyelidikan ilmiah dan mendiseminasikannya kepada teman sekelas.'),

-- 5. Cooperative Learning (Jigsaw / STAD)
(5, 1, 'Menyampaikan Tujuan dan Memotivasi Siswa',
 'Menjelaskan indikator kompetensi yang hendak dicapai, memotivasi pentingnya kerja sama tim, dan membangkitkan komitmen kelompok.',
 'Menyimak tujuan pembelajaran, memahami kriteria evaluasi tim, dan menyatukan komitmen dengan anggota kelompok.'),
(5, 2, 'Menyajikan Informasi Pokok',
 'Memaparkan materi esensial secara ringkas melalui media grafis/audio atau teks ringkas sebagai pengantar.',
 'Mencatat pokok-pokok pikiran materi pengantar dan mendiskusikan pemahaman awal bersama teman sebangku.'),
(5, 3, 'Mengorganisasikan Siswa ke Kelompok Belajar',
 'Membentuk kelompok heterogen (kelompok asal & kelompok ahli) serta menjelaskan aturan pembagian tugas studi.',
 'Bergabung dengan kelompok asal, membagi subtopik materi, dan berpindah ke kelompok ahli untuk mendalami materi.'),
(5, 4, 'Membimbing Kelompok Bekerja dan Belajar',
 'Berkeliling mendampingi diskusi kelompok ahli, memastikan penguasaan konsep, lalu membimbing saat kembali ke kelompok asal.',
 'Berdiskusi mendalam di kelompok ahli hingga paham, lalu kembali ke kelompok asal untuk mengajarkan subtopik kepada rekan satu tim.'),
(5, 5, 'Evaluasi Capaian Belajar',
 'Memberikan kuis mandiri tanpa bantuan anggota lain untuk menguji daya serap individual atas seluruh subtopik.',
 'Mengerjakan kuis mandiri dengan jujur dan mengukur pemahaman pribadi atas materi yang telah dipelajari bersama.'),
(5, 6, 'Memberikan Penghargaan Kelompok',
 'Menghitung poin peningkatan skor kelompok (improvement points) dan memberikan sertifikat/rekognisi prestasi tim.',
 'Menerima penghargaan tim, merayakan keberhasilan kerja sama, dan merefleksikan kontribusi masing-masing anggota.'),

-- 6. Contextual Teaching and Learning (CTL)
(6, 1, 'Konstruktivisme (Constructivism)',
 'Menggali pemahaman awal murid, mengaitkan topik dengan tradisi, lingkungan, atau peristiwa nyata yang dialami siswa.',
 'Mengingat pengalaman keseharian, menghubungkan pengetahuan lama dengan topik yang dipelajari.'),
(6, 2, 'Menemukan (Inquiry)',
 'Mengarahkan pengamatan kontekstual di lingkungan sekolah/sosial untuk menemukan pola dan konsep.',
 'Melakukan pengamatan nyata, mencatat fenomena di sekitarnya, dan menemukan inti konsep secara mandiri.'),
(6, 3, 'Bertanya (Questioning)',
 'Memancing pertanyaan reflektif tingkat tinggi yang mengaitkan hukum/teori dengan tantangan etika dan moral masa kini.',
 'Mengajukan pertanyaan kritis, mendiskusikan dilema moral/sosial, dan mencari kejelasan konsep.'),
(6, 4, 'Masyarakat Belajar (Learning Community)',
 'Memfasilitasi kerja kelompok kolaboratif di mana murid saling belajar satu sama lain dan memanfaatkan narasumber.',
 'Berbagi perspektif dalam lingkaran diskusi kelompok, saling mengoreksi dan memperkaya pemahaman bersama.'),
(6, 5, 'Pemodelan (Modeling)',
 'Mendemonstrasikan perilaku teladan, praktik ibadah, pembacaan dalil yang fasih, atau simulasi tindakan nyata.',
 'Mengamati model percontohan dengan saksama dan mempraktikkan keterampilan yang dicontohkan.'),
(6, 6, 'Refleksi (Reflection)',
 'Menyediakan waktu hening untuk perenungan makna, menulis catatan jurnal belajar, dan mengevaluasi manfaat materi.',
 'Menuliskan refleksi pribadi dalam lembar mutaba\'ah/jurnal tentang hikmah dan bagaimana materi mengubah cara pandang hidupnya.'),
(6, 7, 'Penilaian Autentik (Authentic Assessment)',
 'Menilai sikap spiritual-sosial, keterampilan performa unjuk kerja, dan pemahaman konsep secara terpadu.',
 'Menunjukkan portofolio karya, unjuk praktik nyata dalam kehidupan, dan menyelesaikan asesmen autentik.'),

-- 7. Pembelajaran Berdiferensiasi
(7, 1, 'Asesmen Diagnostik Awal', 
 'Melakukan pemetaan gaya belajar (visual/auditori/kinestetik), kesiapan belajar awal, dan minat khusus murid.', 
 'Mengisi instrumen asesmen diagnostik atau menyelesaikan pre-test sesuai kondisi pemahaman dirinya.'),
(7, 2, 'Diferensiasi Konten, Proses & Produk', 
 'Menyediakan variasi bahan bacaan/audio/video (konten), aktivitas belajar beragam (proses), dan opsi karya (produk).', 
 'Memilih jalur penelaahan materi dan bentuk unjuk karya yang selaras dengan kekuatan dan gaya belajarnya.'),
(7, 3, 'Scaffolding & Pendampingan Berjenjang', 
 'Memberikan pendampingan intensif kepada kelompok yang butuh bimbingan dan memberi tantangan pengayaan bagi yang cepat paham.', 
 'Belajar aktif dalam kelompok tiering dengan tingkat kemandirian yang berkembang secara bertahap.'),
(7, 4, 'Asesmen Formatif & Unjuk Kerja', 
 'Melakukan asesmen unjuk kerja berbasis rubrik fleksibel dan memberikan umpan balik perbaikan personal.', 
 'Menampilkan produk belajar autentik (video, esai, rekaman tilawah, atau mind-map) dan saling mengapresiasi.'),

-- 8. Flipped Classroom (Blended Learning)
(8, 1, 'Eksplorasi Mandiri Pra-Kelas (Pre-Class)',
 'Mengunggah video pembelajaran interaktif, modul digital, dan lembar cek pemahaman singkat di platform LMS.',
 'Menyimak video materi sebelum jadwal kelas, mencatat poin sulit, dan mengerjakan kuis diagnostik kilat.'),
(8, 2, 'Klarifikasi Konsep & Pemanasan Kelas (In-Class Warm-up)',
 'Membuka kelas dengan reviu hasil kuis daring, mengklarifikasi miskonsepsi terbanyak, dan meluruskan konsep dasar.',
 'Menanyakan bagian yang belum dipahami saat belajar mandiri dan merespons kuis interaktif apersepsi.'),
(8, 3, 'Kolaborasi & Pemecahan Kasus (In-Class Active Learning)',
 'Memberikan skenario studi kasus kompleks atau lembar kerja kolaboratif tingkat tinggi (HOTS).',
 'Bekerja sama dalam tim menganalisis studi kasus nyata, berdebat secara ilmiah, dan merumuskan solusi.'),
(8, 4, 'Refleksi, Asesmen & Perluasan (Post-Class Extension)',
 'Memberikan feedback atas karya kelompok dan memberikan tugas pengayaan berbasis proyek lanjutan.',
 'Menyimpulkan hasil telaah kelas, menyelesaikan tugas tindak lanjut, dan mendokumentasikan pemahaman baru.'),

-- 9. Teaching at the Right Level (TaRL)
(9, 1, 'Asesmen Diagnostik Kemampuan Dasar',
 'Menjalankan asesmen diagnostik cepat untuk mendeteksi kemampuan membaca, pemahaman literasi, dan numerasi siswa.',
 'Mengerjakan asesmen dasar secara rileks tanpa tekanan peringkat untuk mengetahui posisi kemampuannya.'),
(9, 2, 'Pengelompokan Berbasis Tingkat Capaian Riil',
 'Mengelompokkan siswa ke dalam kelompok kompetensi selevel (fondasi dasar, berkembang, dan cakap/mahir).',
 'Bergabung dengan kelompok belajar sesuai tingkatan kemampuannya tanpa diskriminasi atau stigma negatif.'),
(9, 3, 'Intervensi Pembelajaran Terarah Sesuai Kesiapan',
 'Menyajikan materi dan aktivitas yang tepat sasaran (kelompok dasar: drill fonetik/huruf; kelompok mahir: telaah kritis).',
 'Berlatih fokus pada materi yang tepat levelnya sehingga mengalami keberhasilan bertahap yang meningkatkan kepercayaan diri.'),
(9, 4, 'Asesmen Formatif Berkala & Mobilitas Kelompok',
 'Memantau perkembangan mingguan; siswa yang sudah menguasai kompetensi langsung dinaikkan ke kelompok berikutnya.',
 'Menunjukkan kemajuan belajar dan bersiap berpindah kelompok tingkat lanjutan setelah tuntas menguasai indikator.'),

-- 10. Creative Problem Solving (CPS)
(10, 1, 'Klarifikasi Tantangan (Clarify)',
 'Menyajikan tantangan kehidupan nyata yang kabur dan menantang, serta membimbing pemetaan fakta penting.',
 'Mengidentifikasi fakta, perasaan, dan hambatan seputar tantangan yang dihadapi serta mencatat kondisi ideal yang diharapkan.'),
(10, 2, 'Perumusan Tantangan (Problem-Finding)',
 'Mengarahkan perumusan pertanyaan kunci "Bagaimana kita bisa..." (How Might We...) untuk membuka ruang kreasi.',
 'Merumuskan kalimat tantangan solutif yang berfokus pada solusi kreatif tanpa membatasi imajinasi.'),
(10, 3, 'Penggalian Ide Kreatif (Idea-Finding / Brainstorming)',
 'Menciptakan ruang bebas kritik (defer judgment) untuk merangsang sebanyak mungkin ide divergen yang radikal dan segar.',
 'Melakukan curah gagasan (brainstorming) secara antusias, memproduksi ide alternatif sebanyak-banyaknya tanpa rasa takut salah.'),
(10, 4, 'Pengembangan Solusi (Solution-Finding)',
 'Menyediakan matriks kriteria evaluasi (efektivitas, kelayakan, dampak, biaya) untuk menyaring ide terbaik.',
 'Menyeleksi ide unggulan menggunakan matriks kriteria, menyempurnakan detail ide, dan merancang prototipe aksi.'),
(10, 5, 'Perencanaan Tindakan Nyata (Acceptance-Finding)',
 'Membimbing penyusunan rencana aksi implementasi nyata (siapa melakukan apa, kapan, dan bagaimana mitigasi risikonya).',
 'Menyusun action plan terperinci, menyiapkan strategi menghadapi penolakan/kendala, dan siap mengeksekusi solusi kreatif.')
ON DUPLICATE KEY UPDATE `nama_fase` = VALUES(`nama_fase`), `aktivitas_guru` = VALUES(`aktivitas_guru`), `aktivitas_siswa` = VALUES(`aktivitas_siswa`);

-- ------------------------------------------------------------------------------
-- SEEDER METODE PEMBELAJARAN (LENGKAP BESERTA LANGKAH, KELEBIHAN & REKOMENDASI)
-- ------------------------------------------------------------------------------
INSERT INTO `metode_pembelajaran` (`id`, `nama_metode`, `slug`, `deskripsi`, `langkah_langkah`, `kelebihan`, `kekurangan`, `rekomendasi_penerapan`) VALUES
(1, 'Ceramah Interaktif (Interactive Lecturing)', 'ceramah-interaktif',
 'Penyampaian materi secara lisan oleh pendidik yang dipadukan dengan media visual, analogi kontekstual, pertanyaan berkala, dan respon dua arah siswa untuk mencegah kejenuhan.',
 '1. Apersepsi singkat dan stimulus fenomena pemantik (5 menit)\n2. Pemaparan konsep inti per segmen 10-15 menit dengan slide visual\n3. Jeda interaktif (tanya jawab kilat dua arah / polling kelas)\n4. Penguatan dengan visualisasi diagram atau klip video edukatif\n5. Rangkuman bersama siswa dan pengecekan pemahaman konsep',
 'Efisien menyampaikan materi konseptual dalam waktu singkat dan menjangkau seluruh siswa secara serentak.',
 'Dapat menimbulkan kejenuhan dan kepasifan jika guru mendominasi tanpa interaksi aktif.',
 'Materi konseptual baru, Sejarah Peradaban Islam (SKI), kaidah hukum syariat dasar.'),

(2, 'Diskusi Kelompok (Group Discussion)', 'diskusi-kelompok',
 'Metode bertukar pikiran, gagasan, dan argumen antar-peserta didik dalam kelompok kecil untuk membedah masalah atau topik tertentu secara mendalam dan demokratis.',
 '1. Guru menyajikan rumusan masalah atau studi kasus kontekstual\n2. Siswa dibagi ke dalam kelompok kecil 4-5 orang secara heterogen\n3. Penunjukan ketua diskusi dan notulis kelompok\n4. Diskusi dan eksplorasi solusi bersama dalam kelompok\n5. Presentasi hasil telaah kelompok dan tanggapan antartim\n6. Klarifikasi, penguatan konsep, dan simpulan bersama guru',
 'Melatih kemampuan berpikir kritis, toleransi menghargai pendapat orang lain, dan keterampilan komunikasi publik.',
 'Bisa didominasi oleh segelintir siswa yang vokal jika pembagian peran tidak diawasi ketat.',
 'Kajian isu sosial moral, perbandingan mazhab fiqih ibadah, etika pergaulan Islami.'),

(3, 'Tanya Jawab Kritis (Socratic Questioning / Q&A)', 'tanya-jawab-kritis',
 'Metode dialog dua arah melalui serangkaian pertanyaan bertingkat (HOTS) dari guru untuk merangsang rasa ingin tahu, melatih nalar analitis, dan mengungkap kebenaran konsep.',
 '1. Guru melontarkan pertanyaan pemantik tingkat tinggi (HOTS)\n2. Memberikan jeda waktu berpikir (wait time 3-5 detik)\n3. Menunjuk siswa secara acak untuk menyampaikan argumen\n4. Memantulkan respon siswa ke siswa lain untuk konfirmasi atau bantahan santun\n5. Guru mengarahkan dialog menuju kesimpulan ilmiah yang benar',
 'Mengaktifkan konsentrasi seluruh kelas, melatih keberanian berbicara, dan mendeteksi miskonsepsi seketika.',
 'Siswa yang pemalu dapat merasa tertekan jika iklim kelas tidak diciptakan ramah dan suportif.',
 'Sesi apersepsi, pendalaman makna Asmaul Husna, hikmah syariat dan akhlak terpuji.'),

(4, 'Demonstrasi & Pemodelan (Demonstration & Modeling)', 'demonstrasi-pemodelan',
 'Metode memperagakan secara nyata suatu proses, gerakan ibadah, tata cara ritual, atau keterampilan teknis yang disaksikan langsung oleh seluruh peserta didik.',
 '1. Guru menjelaskan tujuan, indikator, dan aspek keselamatan demonstrasi\n2. Guru/narasumber mempraktikkan gerakan/tata cara secara perlahan tahap demi tahap\n3. Penjelasan titik-titik kritis dan kesalahan yang sering terjadi\n4. Beberapa perwakilan siswa diminta mengulang peragaan (peer modeling)\n5. Seluruh siswa mempraktikkan gerakan secara serempak di bawah bimbingan guru',
 'Mengurangi kesalahpahaman verbal abstrak dan memberikan contoh visual nyata yang mudah ditiru dan diingat.',
 'Memerlukan sarana prasarana, alat peraga, dan ruang kelas/laboratorium yang memadai.',
 'Praktik wudhu & tayamum, tata cara sholat fardhu/jenazah, manasik haji, penyembelihan kurban.'),

(5, 'Simulasi & Bermain Peran (Role Playing)', 'simulasi-bermain-peran',
 'Metode penghayatan peran sosial di mana siswa memerankan tokoh, profesi, atau skenario kehidupan nyata untuk mengasah empati, pemahaman nilai, dan pemecahan konflik.',
 '1. Guru menentukan topik dan skenario peran kontekstual\n2. Pemilihan dan penetapan pemeran karakter serta penonton aktif\n3. Pelaksanaan simulasi bermain peran di hadapan kelas\n4. Penghentian adegan pada titik klimaks masalah untuk evaluasi bersama\n5. Diskusi refleksi perasaan pemeran dan penarikan nilai-nilai karakter moral',
 'Membangun kecerdasan emosional, daya empati, kemampuan negosiasi, dan pemahaman nilai aplikatif.',
 'Membutuhkan alokasi waktu lebih panjang dan siswa terkadang terbawa suasana bercanda.',
 'Praktik akad jual beli syariah, musyawarah Piagam Madinah, mediasi sengketa keluarga Islami.'),

(6, 'Talaqqi & Musyafahah (Sorogan / Bandongan)', 'talaqqi-musyafahah',
 'Metode tradisional khas pendidikan Al-Qur\'an dan pesantren di mana murid menyimak dan membaca langsung berhadapan dengan guru dengan koreksi makhraj dan harakat seketika.',
 '1. Guru melafalkan ayat/bacaan dengan makhraj dan kaidah tajwid yang fasih (Talaqqi)\n2. Murid mencermati gerakan bibir, lidah, dan suara guru secara saksama (Musyafahah)\n3. Murid menirukan pelafalan secara berulang hingga fasih dan benar\n4. Murid membaca secara bergantian di hadapan guru (Sorogan)\n5. Guru memberikan koreksi dan pengesahan kelancaran hafalan/bacaan',
 'Menjamin kemurnian, ketepatan makharijul huruf, dan kaidah tajwid secara presisi dan terverifikasi.',
 'Membutuhkan waktu intensif secara tatap muka individual per siswa.',
 'Tahsin Al-Qur\'an, hafalan surat-surat juz 30, hafalan hadits pilihan, pembacaan dalil naqli.'),

(7, 'Tutor Sebaya (Peer Teaching)', 'tutor-sebaya',
 'Metode pembelajaran di mana peserta didik yang telah mencapai kemahiran mendampingi dan membelajarkan rekannya yang masih membutuhkan bimbingan tambahan.',
 '1. Identifikasi siswa yang telah tuntas/mahir sebagai tutor sebaya\n2. Guru memberikan pengarahan singkat kepada para tutor tentang etika dan teknik membimbing\n3. Pembagian kelompok kecil (1 tutor mendampingi 2-3 rekan sekelas)\n4. Sesi belajar bersama dan latihan mandiri terbimbing oleh tutor\n5. Guru berkeliling memantau, memvalidasi pemahaman, dan memberi penguatan',
 'Komunikasi antar-teman sebaya lebih santai dan tidak canggung, meningkatkan kepercayaan diri tutor dan murid.',
 'Tutor sebaya berpotensi keliru menjelaskan jika pemahaman awalnya belum divalidasi oleh guru.',
 'Latihan hukum tajwid nun sukun/mim sukun, perhitungan zakat mal, latihan soal evaluasi.'),

(8, 'Resitasi & Penugasan Portofolio (Recitation & Assignment)', 'resitasi-portofolio',
 'Metode pemberian tugas mandiri atau kelompok di luar jam pelajaran tatap muka yang hasilnya wajib dilaporkan dan dipertanggungjawabkan dalam bentuk resume atau produk karya.',
 '1. Guru memberikan lembar penugasan dan kriteria rubrik penilaian yang transparan\n2. Siswa mengeksplorasi referensi dari perpustakaan, buku teks, atau sumber digital terpercaya\n3. Siswa menyusun laporan resume, esai reflektif, atau produk portofolio kreatif\n4. Pengumpulan dan presentasi/pertanggungjawaban isi tugas di depan kelas\n5. Umpan balik korektif dan apresiasi penguatan dari pendidik',
 'Memupuk kemandirian belajar, disiplin waktu, daya literasi membaca, dan tanggung jawab akademis.',
 'Risiko penyalinan/plagiarisme jika instrumen tugas tidak bersifat autentik personal.',
 'Pembuatan resume biografi tokoh ulama, rangkuman tema fiqih, jurnal mutaba\'ah ibadah harian.'),

(9, 'Karya Wisata / Outing Class (Field Trip)', 'karya-wisata',
 'Metode pembelajaran langsung di luar lingkungan kelas untuk mengamati dan mengkaji objek studi nyata di lapangan secara autentik dan kontekstual.',
 '1. Perencanaan lokasi, tujuan instruksional, dan prosedur keselamatan\n2. Pembekalan lembar observasi dan fokus pengkajian bagi siswa\n3. Kunjungan lapangan dan wawancara dengan narasumber/pengelola objek\n4. Pengumpulan data, dokumentasi visual, dan pencatatan fakta lapangan\n5. Penyusunan laporan perjalanan edukasi dan presentasi refleksi makna',
 'Menghilangkan kejenuhan belajar di kelas dan memberikan pengalaman sensorik autentik yang berkesan mendalam.',
 'Memerlukan biaya operasional transportasi, izin orang tua, dan manajemen keamanan ekstra.',
 'Kunjungan ke masjid bersejarah, museum peradaban Islam, kantor Badan Amil Zakat (BAZNAS), panti asuhan.'),

(10, 'Drill & Practice (Latihan & Pembiasaan)', 'drill-and-practice',
 'Metode latihan motorik dan mental secara berulang-ulang untuk menanamkan keterampilan hafalan, ketangkasan respon, dan kebiasaan akhlakul karimah yang melekat kuat.',
 '1. Penjelasan instruksi dan target kemahiran yang hendak dicapai\n2. Demonstrasi contoh baku mengenai standar kecepatan dan ketepatan dari guru\n3. Sesi latihan pengulangan berkala secara terstruktur (interval drill)\n4. Observasi guru dan koreksi langsung saat terjadi kesalahan berulang\n5. Pengujian kecepatan dan ketepatan unjuk kerja siswa',
 'Membentuk refleks hafalan otomatis dan keterampilan psikomotorik yang kokoh dalam memori jangka panjang.',
 'Cenderung mekanis dan monoton jika dilakukan berlebihan tanpa selingan aktivitas rekreatif.',
 'Hafalan doa harian, hafalan juz \'amma, kaidah kaligrafi Arab, pembiasaan sholat berjamaah.'),

(11, 'Mind Mapping & Pemetaan Konsep Visual', 'mind-mapping',
 'Metode pencatatan kreatif yang mengorganisasikan konsep materi secara visual menggunakan kata kunci, cabang pohon, simbol, dan warna untuk memetakan keterkaitan konsep.',
 '1. Menuliskan tema gagasan utama di tengah bidang kertas atau kanvas digital\n2. Menarik cabang-cabang utama untuk mengelompokkan subtopik penting\n3. Menambahkan ranting detail berisi kata kunci esensial dan simbol visual pendukung\n4. Menggunakan variasi warna berbeda untuk membedakan kategori konsep\n5. Mempresentasikan alur mind map kepada teman kelompok',
 'Mengoptimalkan potensi kedua belahan otak, merangsang daya visualisasi, dan mempermudah pengingatan materi padat.',
 'Membutuhkan waktu adaptasi bagi siswa yang belum terbiasa berpikir visual terstruktur.',
 'Peta konsep silsilah nabi dan rasul, cabang-cabang iman (Syu\'abul Iman), struktur pembagian waris Islam.'),

(12, 'Debat Aktif (Active Debate)', 'debat-aktif',
 'Metode adu argumentasi terstruktur antara tim pro dan kontra mengenai isu kontemporer berbasis dalil syar\'i, data empiris, dan penalaran logis yang beretika.',
 '1. Guru menetapkan mosi kontroversial yang relevan (misal: fintech syariah vs konvensional)\n2. Pembagian tim afirmasi (pro), oposisi (kontra), moderator, dan tim perumus\n3. Waktu perumusan argumen berbasis telaah dalil naqli dan data sosial (15 menit)\n4. Babak penyampaian argumen pokok, interupsi santun, dan sanggahan terstruktur\n5. Guru memfasilitasi rekonsiliasi, meluruskan prinsip syariah, dan menyimpulkan hikmah',
 'Melatih keberanian berbicara di forum publik, nalar kritis, riset dalil teruji, dan etika berikhtilaf.',
 'Dapat menimbulkan perselisihan emosional jika moderator tidak tegas menegakkan etika debat.',
 'Fiqih muamalah kontemporer (transaksi digital, kripto), etika bermedia sosial, toleransi beragama.'),

(13, 'Eksperimen / Praktikum Ilmiah (Scientific Experiment)', 'eksperimen-ilmiah',
 'Metode pembelajaran berbasis pengujian langsung di mana siswa memanipulasi variabel dan mengamati reaksi nyata untuk membuktikan kebenaran suatu prinsip ilmu.',
 '1. Penjelasan hipotesis kerja dan prosedur keselamatan kerja (K3)\n2. Pembagian peralatan, bahan uji, dan Lembar Kerja Praktikum (LKP)\n3. Pelaksanaan eksperimen secara cermat dan pencatatan data observasi riil\n4. Analisis komparasi antara data temuan eksperimen dengan teori referensi\n5. Penyusunan laporan praktikum dan penarikan kesimpulan ilmiah',
 'Menanamkan budaya ilmiah objektif, kejujuran mencatat data, dan pembuktian empiris yang tak terbantahkan.',
 'Memerlukan sarana laboratorium, ketersediaan bahan, dan waktu pelaksanaan yang memadai.',
 'Uji kehalalan bahan makanan (uji zat aditif/boraks), ilmu falak hisab & rukyatul hilal, sains embriologi Al-Qur\'an.'),

(14, 'Brainstorming (Curah Pendapat Bebas)', 'brainstorming',
 'Metode pembangkitan ide kreatif secara massal tanpa adanya kritik atau penghakiman di awal untuk menghasilkan sebanyak mungkin solusi orisinal atas sebuah persoalan.',
 '1. Guru memaparkan tantangan masalah yang memerlukan ragam alternatif ide kreatif\n2. Penetapan aturan emas: dilarang mengkritik ide rekan pada fase pengumpulan gagasan\n3. Siswa menuliskan/mengemukakan semua ide yang melintas di benak secara bebas\n4. Notulis mencatat seluruh gagasan di papan tulis atau digital board\n5. Pengelompokan, penyaringan, dan seleksi ide terbaik secara musyawarah mufakat',
 'Membebaskan daya kreativitas, mendorong partisipasi 100% siswa tanpa takut dihakimi salah.',
 'Bisa melebar ke luar batasan topik jika fasilitator guru tidak menjaga koridor tujuan.',
 'Perancangan program peringatan hari besar Islam (PHBI), ide dakwah kreatif digital, aksi peduli sosial.'),

(15, 'Studi Kasus (Case Study Analysis)', 'studi-kasus',
 'Metode penelaahan mendalam terhadap narasi peristiwa nyata atau simulasi masalah moral-sosial untuk dianalisis akar penyebab, dampak, dan solusi solutifnya.',
 '1. Guru membagikan teks berita atau video studi kasus nyata dari masyarakat\n2. Siswa membaca dan mencermati kronologi peristiwa serta aktor yang terlibat\n3. Siswa menganalisis dengan panduan 5W1H dan kacamata hukum serta etika Islam\n4. Perumusan rekomendasi jalan keluar dan langkah pencegahan preventif\n5. Presentasi hasil telaah kasus dan perumusan komitmen moral bersama',
 'Mengembangkan kemampuan analisis tingkat tinggi (HOTS), kepekaan nurani, dan penalaran kontekstual.',
 'Menuntut daya literasi membaca dan kemampuan analisis kritis yang memadai dari peserta didik.',
 'Analisis kasus bullying di sekolah, penanggulangan hoaks/ghibah digital, kasus penyalahgunaan amanah.')
ON DUPLICATE KEY UPDATE `nama_metode` = VALUES(`nama_metode`), `deskripsi` = VALUES(`deskripsi`), `langkah_langkah` = VALUES(`langkah_langkah`), `kelebihan` = VALUES(`kelebihan`), `kekurangan` = VALUES(`kekurangan`), `rekomendasi_penerapan` = VALUES(`rekomendasi_penerapan`);

-- ------------------------------------------------------------------------------
-- SEEDER BANK MATERI PEMBELAJARAN (PENYEDIA KONTEN PEMBELAJARAN)
-- ------------------------------------------------------------------------------
INSERT INTO `materi` (
  `id`, `judul_materi`, `slug`, `deskripsi_singkat`, `isi_materi`, 
  `tipe_materi`, `link_materi`, `file_materi`, `durasi_menit`
) VALUES
(
  1,
  'Memahami Makna Keluhuran 7 Asmaul Husna dalam Kehidupan Nyata',
  'memahami-makna-7-asmaul-husna',
  'Kajian mendalam tentang makna Asmaul Husna: Al-Karim, Al-Mu\'min, Al-Wakil, Al-Matin, Al-Jami\', Al-\'Adl, dan Al-Akhir serta dalil-dalil naqlinya.',
  'Asmaul Husna bukan sekadar nama-nama indah untuk dihafalkan, melainkan panduan nilai spiritual dan moral bagi seorang muslim dalam berkehidupan.\n\n1. Al-Karim (Yang Maha Mulia / Dermawan):\nMeneladani al-Karim berarti menumbuhkan sifat gemar berbagi, pemaaf, dan senantiasa menjaga kehormatan diri dari perbuatan tercela.\n\n2. Al-Mu\'min (Yang Maha Memberi Rasa Aman):\nMenjadi pribadi yang menjaga lisan dan perbuatan sehingga rekan dan lingkungan sekitar merasa aman dari gangguan kita.\n\n3. Al-Wakil (Yang Maha Memelihara):\nMenumbuhkan sikap tawakal yang kokoh setelah berikhtiar secara maksimal, tidak mudah putus asa saat menghadapi cobaan hidup.\n\n4. Al-Matin (Yang Maha Kokoh / Teguh):\nTeguh memegang prinsip keimanan dan kebenaran walau menghadapi berbagai godaan duniawi.\n\n5. Al-Jami\' (Yang Maha Mengumpulkan):\nSemangat persatuan dan merekatkan tali ukhuwah islamiyah di tengah kemajemukan bangsa.\n\n6. Al-\'Adl (Yang Maha Adil):\nBerperilaku adil kepada diri sendiri dan orang lain tanpa memandang latar belakang status sosial.\n\n7. Al-Akhir (Yang Maha Akhir):\nMengingat kehidupan abadi akhirat sehingga hidup di dunia terarah pada amal jariyah yang bermakna.',
  'Teks',
  'https://ilmuna.sch.id/materi/asmaul-husna-lengkap',
  'modul-asmaul-husna-fase-e.pdf',
  20
),
(
  2,
  'Video Pembelajaran: Kontekstualisasi Asmaul Husna dalam Era Digital',
  'video-kontekstualisasi-asmaul-husna',
  'Video penjelasan interaktif dan studi kasus penerapan nilai al-Mu\'min dan al-Wakil di tengah tantangan media sosial.',
  'Simak video penjelasan berdurasi 12 menit berikut untuk memahami bagaimana nilai al-Mu\'min mencegah penyebaran hoaks dan ujaran kebencian di media sosial, serta bagaimana sifat al-Wakil melatih ketenangan jiwa di era overthinking digital.',
  'Video',
  'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
  NULL,
  15
),
(
  3,
  'Kaidah Hukum Bacaan Nun Sukun dan Tanwin pada QS. Al-Hujurat',
  'kaidah-nun-sukun-dan-tanwin',
  'Penjelasan detail 4 hukum nun sukun: Idzhar Halqi, Idgham (Bighunnah & Bilaghunnah), Iqlab, dan Ikhfa Haqiqi beserta contoh ayatnya.',
  'Hukum nun sukun (نْ) atau tanwin (ـًـٍـٌ) ketika bertemu huruf hijaiyah terbagi menjadi 4 hukum utama:\n\n1. Idzhar Halqi (Jelas):\nTerjadi apabila nun sukun/tanwin bertemu 6 huruf tenggorokan (ء هـ ع ح غ خ). Cara membacanya jelas tanpa dengung.\n\n2. Idgham (Memasukkan):\n- Idgham Bighunnah (dengan dengung 2 harakat): bertemu huruf ي ن م و.\n- Idgham Bilaghunnah (tanpa dengung): bertemu huruf ل dan ر.\n\n3. Iqlab (Mengganti):\nTerjadi apabila bertemu huruf Ba (ب). Suara nun berubah menjadi mim samar disertai dengung.\n\n4. Ikhfa Haqiqi (Menyamarkan):\nTerjadi apabila bertemu 15 huruf hijaiyah lainnya. Suara nun disamarkan di antara idzhar dan idgham dengan dengung sempurna.',
  'Teks',
  'https://ilmuna.sch.id/materi/tajwid-nun-sukun',
  'panduan-tajwid-nun-sukun.pdf',
  25
),
(
  4,
  'Audio Murottal & Slide Tajwid QS. Al-Hujurat: 10-13',
  'audio-murottal-slide-tajwid',
  'Slide presentasi hukum tajwid berwarna dan audio murottal tartil per ayat sebagai panduan Talaqqi siswa.',
  'Dengarkan bacaan murottal berikut secara berulang-ulang, perhatikan panjang ghunnah 2 harakat pada setiap Idgham Bighunnah dan Ikhfa sebelum menyetorkan bacaan talaqqi kepada guru pengampu.',
  'Slide',
  'https://drive.google.com/file/d/example-slide-tajwid/view',
  'slide-hukum-tajwid-alhujurat.pptx',
  15
)
ON DUPLICATE KEY UPDATE `judul_materi` = VALUES(`judul_materi`), `isi_materi` = VALUES(`isi_materi`), `link_materi` = VALUES(`link_materi`);

-- ------------------------------------------------------------------------------
-- SEEDER MODUL AJAR (MEMILIH MATERI DARI BANK MATERI)
-- ------------------------------------------------------------------------------
INSERT INTO `modul_ajar` (
  `id`, `id_kelas_mata_pelajaran`, `id_cp`, `id_tp`, `id_atp`, `id_itp`,
  `id_model_pembelajaran`, `id_metode_pembelajaran`, `id_materi`, `id_guru`, 
  `pertemuan_ke`, `judul_modul`, `fase`, `alokasi_waktu`, 
  `profil_pelajar_pancasila`, `sarana_prasarana`, `target_peserta_didik`, 
  `pemahaman_bermakna`, `pertanyaan_pemantik`, 
  `kegiatan_pendahuluan`, `kegiatan_inti`, `kegiatan_penutup`, 
  `asesmen_diagnostik`, `asesmen_formatif`, `asesmen_sumatif`, 
  `pengayaan_remedial`, `refleksi_guru_siswa`, 
  `lkpd`, `bahan_bacaan`, `glosarium`, `daftar_pustaka`, `status`
) VALUES
(
  1, 1, 1, 1, 1, 1,
  1, 2, 1, 2, 
  1, 'Modul Ajar 01: Merengkuh Martabat Insan dengan 7 Asmaul Husna', 'Fase E', '2 x 45 Menit (Pertemuan 1)',
  'Beriman, Bertakwa kepada Tuhan YME, dan Berakhlak Mulia; Bernalar Kritis; Gotong Royong',
  'LCD Proyektor, Laptop, Musholla Sekolah, Mushaf Al-Qur\'an & Terjemah, LKPD Analisis Kasus, Koneksi Internet',
  'Peserta Didik Reguler / Tipikal (32 Siswa)',
  'Meneladani sifat al-Karim, al-Mu\'min, dan al-Wakil membentuk pribadi yang berintegritas, senantiasa memberi keamanan bagi lingkungan, dan tangguh bertawakal.',
  'Mengapa orang yang dermawan dan amanah lebih dihormati daripada orang yang sekadar memiliki kedudukan atau harta melimpah?',
  '1. Guru membuka kelas dengan salam, doa bersama, dan pembacaan ayat suci Al-Qur\'an.\n2. Guru memeriksa kehadiran siswa dan kesiapan ruang kelas.\n3. Apersepsi: Guru menampilkan video pendek tentang kejujuran dan kedermawanan seorang pemuda.\n4. Guru menyampaikan Tujuan Pembelajaran (TP) dan alur aktivitas Problem Based Learning.',
  'Fase 1 (Orientasi): Siswa mencermati artikel studi kasus tentang krisis keteladanan integritas moral di era digital.\nFase 2 (Organisasi): Siswa dibagi dalam 6 kelompok untuk membedah penerapan Asmaul Husna al-Karim, al-Mu\'min, al-Wakil, dll.\nFase 3 (Penyelidikan): Siswa mengkaji ayat-ayat Al-Qur\'an dan hadits terkait untuk merumuskan solusi krisis integritas.\nFase 4 (Penyajian Karya): Setiap kelompok menyusun lembar analisis solusi dan mempresentasikannya di hadapan kelompok lain.\nFase 5 (Evaluasi): Guru memfasilitasi tanya jawab, meluruskan pemahaman dalil, dan membimbing penarikan simpulan bersama.',
  '1. Siswa bersama guru menyimpulkan inti hikmah Asmaul Husna yang telah dipelajari.\n2. Guru memandu refleksi satu kata: "Apa sifat mulia yang ingin kamu praktikkan hari ini?"\n3. Guru menyampaikan agenda pertemuan ke-2 (presentasi karya poster).\n4. Kelas ditutup dengan doa kafaratul majelis dan salam penutup.',
  'Pre-test non-kognitif (survei gaya belajar) dan kuis kognitif singkat (5 soal pilihan ganda pemahaman Asmaul Husna dasar).',
  'Observasi rubrik keaktifan diskusi kelompok, penilaian unjuk kerja presentasi, dan penilaian lembar kerja siswa (LKPD).',
  'Tes tertulis analisis studi kasus pada akhir bab (HOTS) dan penilaian portofolio jurnal refleksi diri.',
  'Pengayaan: Murid yang telah melampaui capaian diminta membuat esai reflektif tentang Asmaul Husna dalam kepemimpinan.\nRemedial: Bimbingan intensif personal bagi murid yang belum menguasai arti dan dalil 7 Asmaul Husna.',
  'Refleksi Guru: Apakah pembagian waktu sintaks PBL berjalan efektif? Bagaimana respon murid saat mendiskusikan studi kasus?\nRefleksi Siswa: Bagian materi mana yang paling menyentuh hatiku? Bagaimana aku mengamalkannya di sekolah?',
  'Lembar Kerja Peserta Didik (LKPD) Analisis Skenario Kasus:\n1. Identifikasi problematika karakter yang ada dalam narasi kasus!\n2. Kaitkan solusi masalah tersebut dengan nilai salah satu dari 7 Asmaul Husna!\n3. Tuliskan dalil Al-Qur\'an yang mendukung argumen kelompokmu!',
  'Buku Teks Pendidikan Agama Islam dan Budi Pekerti SMA Kelas X (Kemendikbudristek 2024), Kitab Fathul Baari Syarah Shahih Al-Bukhari.',
  'Al-Karim (Maha Mulia/Maha Dermawan), Al-Mu\'min (Maha Memberi Keamanan), Al-Wakil (Maha Memelihara/Maha Menjaga), Al-Matin (Maha Kokoh).',
  'Kementerian Pendidikan, Kebudayaan, Riset, dan Teknologi. 2024. Buku Panduan Guru dan Siswa PAI & Budi Pekerti SMA Kelas X. Jakarta: Pusat Perbukuan.',
  'Published'
),
(
  2, 2, 2, 3, 5, 6,
  3, 6, 3, 2, 
  1, 'Modul Ajar 01: Keindahan Tilawah Al-Qur\'an dengan Kaidah Tajwid Nun Sukun', 'Fase E', '2 x 45 Menit (Pertemuan 1)',
  'Beriman, Bertakwa kepada Tuhan YME, dan Berakhlak Mulia; Mandiri; Bernalar Kritis',
  'Al-Qur\'an Mushaf Standar Kemenag, Speaker Murottal, Slide PPT Tajwid, Papan Tulis Kaligrafi, LKPD Identifikasi Tajwid',
  'Peserta Didik Reguler / Tipikal (32 Siswa)',
  'Membaca Al-Qur\'an dengan kaidah tajwid yang benar dan tartil menjaga kemurnian wahyu Allah serta mendatangkan ketenangan batin.',
  'Pernahkah kalian mendengar tilawah imam yang sangat merdu dan menyentuh hati? Apa yang membuat bacaan Al-Qur\'an terdengar begitu tertib dan harmonis?',
  '1. Pembukaan dengan tilawah bersama QS. Al-Fatihah.\n2. Presensi dan pembiasaan adab terhadap mushaf Al-Qur\'an.\n3. Apersepsi: Memutar audio murottal Syaikh Misyari Rasyid Al-Afasy pada QS. Al-Hujurat: 10-13.\n4. Penyampaian target capaian hukum nun sukun dan tanwin.',
  'Fase 1 (Stimulasi): Siswa menyimak tayangan ayat dengan highlight warna berbeda pada nun sukun/tanwin.\nFase 2 (Identifikasi): Siswa mengidentifikasi jenis hukum tajwid (Idzhar, Idgham, Iqlab, Ikhfa).\nFase 3 (Pengumpulan Data): Siswa mengecek tabel huruf hijaiyah dan kaidah cara membacanya.\nFase 4 (Pengolahan Data): Siswa mengelompokkan potongan ayat ke dalam kategori hukum nun sukun yang tepat.\nFase 5 (Pembuktian): Praktik Talaqqi & Musyafahah membaca ayat di hadapan guru dan teman sebangku.\nFase 6 (Generalisasi): Merumuskan kaidah praktis mengingat huruf dan mempraktikkan bacaan tartil.',
  '1. Refleksi bersama: Siswa melafalkan kembali contoh hukum Idgham Bighunnah dan Ikhfa Haqiqi.\n2. Guru memberikan apresiasi atas kelancaran makhraj siswa.\n3. Pemberian tugas mandiri menyimak tilawah di rumah.\n4. Penutupan dengan doa kafaratul majelis.',
  'Tes membaca 2 baris ayat Al-Qur\'an secara lisan untuk memetakan level kefasihan awal murid.',
  'Rubrik unjuk kerja kelancaran membaca (makhraj, sifat huruf, dan panjang-pendek mad).',
  'Tes praktik setoran hafalan dan bacaan tartil QS. Al-Hujurat ayat 10-13 pada akhir capaian materi.',
  'Pengayaan: Murid yang sudah sangat fasih diminta menjadi tutor sebaya mendampingi rekannya.\nRemedial: Latihan mandiri terbimbing Talaqqi hukum nun sukun dengan rekaman murottal berkecepatan lambat.',
  'Refleksi Guru: Siswa masih sering tertukar antara Ikhfa dan Idgham Bighunnah, perlu penekanan durasi ghunnah.\nRefleksi Siswa: Saya merasa lebih percaya diri melafalkan bacaan yang benar setelah menyimak contoh guru.',
  'LKPD Berburu Tajwid pada QS. Al-Hujurat ayat 10-13:\nTemukan 5 hukum nun sukun/tanwin, sebutkan sebabnya, dan tuliskan cara membacanya dengan tepat!',
  'Kitab Tuhfatul Athfal fi Tajwidil Qur\'an, Modul Tajwid Praktis Kemenag RI.',
  'Idzhar (Jelas/Terang), Idgham (Memasukkan), Iqlab (Mengganti), Ikhfa (Samar-samar), Ghunnah (Dengung).',
  'Kementerian Agama RI. 2023. Al-Qur\'an dan Terjemahannya. Jakarta: Lajnah Pentashihan Mushaf Al-Qur\'an.',
  'Published'
)
ON DUPLICATE KEY UPDATE `judul_modul` = VALUES(`judul_modul`), `id_itp` = VALUES(`id_itp`), `id_materi` = VALUES(`id_materi`), `pemahaman_bermakna` = VALUES(`pemahaman_bermakna`), `kegiatan_inti` = VALUES(`kegiatan_inti`);

-- ------------------------------------------------------------------------------
-- SEEDER PEKAN EFEKTIF (RINCIAN HARI & PEKAN EFEKTIF PER KELAS MAPEL - SHEET 1 EXCEL HE)
-- ------------------------------------------------------------------------------
INSERT INTO `pekan_efektif` (
  `id`, `id_kelas_mata_pelajaran`, `tahun_pelajaran`, `semester`, `bulan`, `urutan_bulan`, 
  `senin`, `selasa`, `rabu`, `kamis`, `jumat`, `sabtu`, `minggu`, `total_hari_efektif`,
  `jumlah_pekan_total`, `jumlah_pekan_efektif`, `jumlah_pekan_tidak_efektif`, `keterangan_tidak_efektif`
) VALUES
-- Semester Ganjil (Juli s.d Desember)
(1, 7, '2026/2027', 'Ganjil', 'Juli', 1, 2, 2, 2, 2, 1, 1, 0, 10, 5, 2, 3, 'Libur Akhir TP Lalu (2 Pekan) & Kegiatan MPLS (1 Pekan)'),
(2, 7, '2026/2027', 'Ganjil', 'Agustus', 2, 4, 4, 4, 4, 5, 5, 0, 26, 4, 4, 0, 'KBM Efektif Penuh (Peringatan HUT RI ke-81)'),
(3, 7, '2026/2027', 'Ganjil', 'September', 3, 5, 5, 4, 4, 3, 4, 0, 25, 4, 4, 0, 'KBM Efektif & Pelaksanaan Asesmen Sumatif Tengah Semester (ASTS)'),
(4, 7, '2026/2027', 'Ganjil', 'Oktober', 4, 4, 4, 5, 5, 5, 4, 0, 27, 5, 5, 0, 'KBM Efektif Penuh & Projek Penguatan Profil Pelajar Pancasila (P5)'),
(5, 7, '2026/2027', 'Ganjil', 'November', 5, 3, 3, 3, 3, 3, 4, 0, 19, 4, 3, 1, 'KBM Efektif Penuh & Pendalaman Materi Menjelang ASAS'),
(6, 7, '2026/2027', 'Ganjil', 'Desember', 6, 0, 0, 0, 0, 0, 0, 0, 0, 5, 0, 5, 'Asesmen Sumatif Akhir Semester (1 Pekan), Rapor (1 Pekan), Libur Semester Ganjil (3 Pekan)'),

-- Semester Genap (Januari s.d Juni)
(7, 7, '2026/2027', 'Genap', 'Januari', 7, 4, 4, 4, 4, 3, 3, 0, 22, 4, 4, 0, 'Awal Masuk KBM Efektif Semester Genap TP 2026/2027'),
(8, 7, '2026/2027', 'Genap', 'Februari', 8, 4, 3, 4, 4, 4, 4, 0, 23, 4, 4, 0, 'KBM Efektif Penuh & Kegiatan Pembiasaan Ibadah'),
(9, 7, '2026/2027', 'Genap', 'Maret', 9, 2, 2, 2, 2, 2, 2, 0, 12, 4, 1, 3, 'Libur Awal Ramadhan, Pondok Ramadhan & Asesmen Sumatif Tengah Semester (ASTS) Genap'),
(10, 7, '2026/2027', 'Genap', 'April', 10, 4, 4, 5, 5, 3, 4, 0, 25, 5, 4, 1, 'Libur Hari Raya Idul Fitri 1448 H & Halal Bihalal Sekolah'),
(11, 7, '2026/2027', 'Genap', 'Mei', 11, 3, 3, 3, 3, 4, 4, 0, 20, 4, 3, 1, 'KBM Efektif Penuh & Asesmen Akhir Jenjang Kelas Akhir'),
(12, 7, '2026/2027', 'Genap', 'Juni', 12, 0, 0, 0, 0, 0, 0, 0, 0, 4, 0, 4, 'Asesmen Sumatif Akhir Tahun (ASAT), Pengolahan Nilai, Rapor & Libur Kenaikan Kelas')
ON DUPLICATE KEY UPDATE 
  `id_kelas_mata_pelajaran` = VALUES(`id_kelas_mata_pelajaran`),
  `senin` = VALUES(`senin`), `selasa` = VALUES(`selasa`), `rabu` = VALUES(`rabu`),
  `kamis` = VALUES(`kamis`), `jumat` = VALUES(`jumat`), `sabtu` = VALUES(`sabtu`),
  `total_hari_efektif` = VALUES(`total_hari_efektif`), `jumlah_pekan_efektif` = VALUES(`jumlah_pekan_efektif`);

-- ------------------------------------------------------------------------------
-- SEEDER HARI EFEKTIF (CONTOH KALENDER HARI KBM PER KELAS MAPEL - TAHUN 2026)
-- ------------------------------------------------------------------------------
INSERT INTO `hari_efektif` (
  `id_kelas_mata_pelajaran`, `tahun_pelajaran`, `semester`, `tanggal`, `hari`, `status_hari`, `keterangan`
) VALUES
(7, '2026/2027', 'Ganjil', '2026-07-13', 'Senin', 'Kegiatan Sekolah/Ujian', 'Hari Pertama Masuk Sekolah & Pembukaan MPLS'),
(7, '2026/2027', 'Ganjil', '2026-07-14', 'Selasa', 'Kegiatan Sekolah/Ujian', 'Masa Pengenalan Lingkungan Sekolah (MPLS) Hari ke-2'),
(7, '2026/2027', 'Ganjil', '2026-07-15', 'Rabu', 'Kegiatan Sekolah/Ujian', 'Penutupan MPLS & Pengenalan Ekstrakurikuler'),
(7, '2026/2027', 'Ganjil', '2026-07-16', 'Kamis', 'Efektif KBM', 'Awal KBM Efektif Semester Ganjil TP 2026/2027'),
(7, '2026/2027', 'Ganjil', '2026-07-17', 'Jumat', 'Efektif KBM', 'KBM Reguler & Kegiatan Sholat Dhuha / Jumat Berjamaah'),
(7, '2026/2027', 'Ganjil', '2026-07-20', 'Senin', 'Efektif KBM', 'KBM Reguler Pekan ke-1'),
(7, '2026/2027', 'Ganjil', '2026-07-21', 'Selasa', 'Efektif KBM', 'KBM Reguler'),
(7, '2026/2027', 'Ganjil', '2026-07-22', 'Rabu', 'Efektif KBM', 'KBM Reguler'),
(7, '2026/2027', 'Ganjil', '2026-07-23', 'Kamis', 'Efektif KBM', 'KBM Reguler'),
(7, '2026/2027', 'Ganjil', '2026-07-24', 'Jumat', 'Efektif KBM', 'KBM Reguler & Keputrian/Rohis'),
(7, '2026/2027', 'Ganjil', '2026-08-17', 'Senin', 'Libur Nasional', 'Upacara & Peringatan HUT Proklamasi Kemerdekaan RI ke-81'),
(7, '2026/2027', 'Ganjil', '2026-08-25', 'Selasa', 'Libur Khusus Keagamaan', 'Peringatan Maulid Nabi Muhammad SAW 1448 H'),
(7, '2026/2027', 'Ganjil', '2026-09-21', 'Senin', 'Kegiatan Sekolah/Ujian', 'Asesmen Sumatif Tengah Semester (ASTS) Ganjil Hari ke-1'),
(7, '2026/2027', 'Ganjil', '2026-09-22', 'Selasa', 'Kegiatan Sekolah/Ujian', 'Asesmen Sumatif Tengah Semester (ASTS) Ganjil Hari ke-2'),
(7, '2026/2027', 'Ganjil', '2026-11-25', 'Rabu', 'Kegiatan Sekolah/Ujian', 'Peringatan Hari Guru Nasional & Apresiasi Pendidik'),
(7, '2026/2027', 'Ganjil', '2026-12-07', 'Senin', 'Kegiatan Sekolah/Ujian', 'Asesmen Sumatif Akhir Semester (ASAS) Ganjil Hari ke-1'),
(7, '2026/2027', 'Ganjil', '2026-12-18', 'Jumat', 'Kegiatan Sekolah/Ujian', 'Pembagian Rapor Capaian Belajar Semester Ganjil'),
(7, '2026/2027', 'Ganjil', '2026-12-21', 'Senin', 'Libur Semester', 'Libur Semester Ganjil TP 2026/2027')
ON DUPLICATE KEY UPDATE `status_hari` = VALUES(`status_hari`), `keterangan` = VALUES(`keterangan`);

-- ------------------------------------------------------------------------------
-- SEEDER ALOKASI HARI MENGAJAR (HITUNG JTM PER HARI MENGAJAR - SHEET 2 & 3 EXCEL)
-- ------------------------------------------------------------------------------
INSERT INTO `alokasi_hari_mengajar` (
  `id`, `id_kelas_mata_pelajaran`, `id_guru`, `semester`, `hari`, 
  `banyaknya_hari_efektif`, `jp_per_minggu`, `total_jtm`, `pola_pekan_mengajar`
) VALUES
(
  1, 7, 1, 'Ganjil', 'Senin', 
  18, 2, 36, 
  'Jul:00045, Agt:1234, Sept:1234, Okt:12345, Nop:1230, Des:000'
)
ON DUPLICATE KEY UPDATE 
  `id_kelas_mata_pelajaran` = VALUES(`id_kelas_mata_pelajaran`),
  `banyaknya_hari_efektif` = VALUES(`banyaknya_hari_efektif`), 
  `jp_per_minggu` = VALUES(`jp_per_minggu`), 
  `total_jtm` = VALUES(`total_jtm`);

-- ------------------------------------------------------------------------------
-- SEEDER DISTRIBUSI JTM KE TP & ASESMEN (SHEET 2 & 3 KANAN EXCEL)
-- TOTAL: 27 (TP KBM) + 5 (PROYEK) + 4 (AS) + 2 (STS) + 1 (REMIDI) + 2 (ASAS) = 36 JP
-- ------------------------------------------------------------------------------
INSERT INTO `distribusi_jtm_tp` (
  `id`, `id_alokasi_hari_mengajar`, `id_kelas_mata_pelajaran`, `id_tp`, `id_itp`,
  `jenis_kegiatan`, `kode_kegiatan`, `nama_kegiatan`, `jtm`, `urutan`, `keterangan`
) VALUES
(1,  1, 7, 1,    1,    'TP KBM', 'RI&SYD 1', 'Menganalisis konsep berpikir kritis dalam perspektif Islam', 2, 1,  'KBM Pertemuan 1'),
(2,  1, 7, 1,    3,    'TP KBM', 'RI&SYD 2', 'Tafsir ayat berpikir kritis QS. Ali Imran: 190-191', 2, 2,  'KBM Pertemuan 2'),
(3,  1, 7, 2,    4,    'TP KBM', 'RI&SYD 3', 'Pengertian dan dalil 77 cabang iman (Syu\'ab al-iman)', 2, 3,  'KBM Pertemuan 3'),
(4,  1, 7, 2,    5,    'TP KBM', 'TH 1',     'Klasifikasi cabang iman dalam lisan, hati, dan perbuatan', 2, 4,  'KBM Pertemuan 4'),
(5,  1, 7, 2,    5,    'TP KBM', 'TH 2',     'Mencontohkan amalan syu\'ab al-iman dalam pergaulan remaja', 2, 5,  'KBM Pertemuan 5'),
(6,  1, 7, NULL, NULL, 'Asesmen Sumatif (AS)', 'AS 1', 'Asesmen Sumatif Lingkup Materi 1 (Berpikir Kritis & Cabang Iman)', 2, 6, 'Ulangan Harian 1'),
(7,  1, 7, NULL, NULL, 'Sumatif Tengah Semester (STS)', 'STS', 'Asesmen Sumatif Tengah Semester (ASTS) Ganjil', 2, 7, 'Ujian Tengah Semester'),
(8,  1, 7, 3,    6,    'TP KBM', 'SF 1',     'Menganalisis dampak negatif penyakit sosial (khamr, judi, pertikaian)', 2, 8,  'KBM Pertemuan 6'),
(9,  1, 7, 3,    7,    'TP KBM', 'SF 2',     'Upaya preventif menghindari bahaya media sosial dan hoax', 2, 9,  'KBM Pertemuan 7'),
(10, 1, 7, 3,    7,    'TP KBM', 'SF 3',     'Studi kasus penyakit sosial di kalangan generasi muda', 2, 10, 'KBM Pertemuan 8'),
(11, 1, 7, 4,    8,    'TP KBM', 'SF 4',     'Ketentuan khotbah, syarat, rukun, dan sunnah khotbah Jumat', 2, 11, 'KBM Pertemuan 9'),
(12, 1, 7, 4,    9,    'TP KBM', 'SF 5',     'Teknik dan etika berdakwah dan bertabligh yang santun dan bijak', 2, 12, 'KBM Pertemuan 10'),
(13, 1, 7, 4,    9,    'TP KBM', 'SF 6',     'Praktik menyusun teks khotbah dan simulasi dakwah di kelas', 2, 13, 'KBM Pertemuan 11'),
(14, 1, 7, 5,    10,   'TP KBM', 'SF 7',     'Menelusuri sejarah keemasan tokoh ulama dunia di era keemasan Islam', 2, 14, 'KBM Pertemuan 12'),
(15, 1, 7, 5,    10,   'TP KBM', 'SF 8',     'Karya monumental Ibnu Sina, Al-Khawarizmi, dan Al-Ghazali', 2, 15, 'KBM Pertemuan 13'),
(16, 1, 7, NULL, NULL, 'Asesmen Sumatif (AS)', 'AS 2', 'Asesmen Sumatif Lingkup Materi 2 (Penyakit Sosial, Dakwah, Ulama)', 2, 16, 'Ulangan Harian 2'),
(17, 1, 7, NULL, NULL, 'Proyek', 'Proyek',   'Projek Penguatan Profil Pelajar Pancasila (P5) Berbasis Nilai Islam', 2, 17, 'Alokasi Proyek P5'),
(18, 1, 7, NULL, NULL, 'Asesmen Sumatif Akhir Semester (ASAS)', 'ASAS', 'Asesmen Sumatif Akhir Semester (ASAS) Ganjil', 2, 18, 'Ujian Akhir Semester')
ON DUPLICATE KEY UPDATE 
  `id_kelas_mata_pelajaran` = VALUES(`id_kelas_mata_pelajaran`),
  `id_tp` = VALUES(`id_tp`),
  `id_itp` = VALUES(`id_itp`),
  `kode_kegiatan` = VALUES(`kode_kegiatan`), 
  `nama_kegiatan` = VALUES(`nama_kegiatan`), 
  `jtm` = VALUES(`jtm`);

-- ------------------------------------------------------------------------------
-- SEEDER PROTA (PROGRAM TAHUNAN - VARIABEL DATA ESENSIAL SESUAI PROTA.DOCX)
-- ------------------------------------------------------------------------------
INSERT INTO `prota` (
  `id`, `id_kelas_mata_pelajaran`, `id_guru`, `tahun_pelajaran`, `fase`,
  `total_jtm_ganjil`, `total_jtm_genap`, `total_jtm_tahunan`,
  `as_ganjil`, `sts_ganjil`, `remidi_ganjil`, `proyek_ganjil`,
  `as_genap`, `sts_genap`, `remidi_genap`, `proyek_genap`,
  `status`
) VALUES
(
  1, 7, 1, '2025/2026', 'Fase F',
  26, 26, 52,
  4, 2, 0, 2,
  4, 2, 0, 2,
  'Approved'
)
ON DUPLICATE KEY UPDATE 
  `fase` = VALUES(`fase`), 
  `total_jtm_tahunan` = VALUES(`total_jtm_tahunan`);

-- ------------------------------------------------------------------------------
-- SEEDER PROTA DETAIL (RINCIAN MURNI BERELASI KE ELEMEN, TP, & ITP - SESUAI PROTA.DOCX)
-- ------------------------------------------------------------------------------
INSERT INTO `prota_detail` (
  `id`, `id_prota`, `id_elemen`, `id_tp`, `id_itp`, `semester`, `nomor_urut`, `alokasi_waktu`
) VALUES
-- Semester Ganjil (5 Elemen & 5 TP Pembelajaran)
(1, 1, 1, 1, 1,  'Ganjil', 1, 4), -- Al-Qur'an Hadis -> Berpikir Kritis dalam Islam (ITP 1)
(2, 1, 2, 2, 4,  'Ganjil', 2, 6), -- Akidah -> Syu'ab al-iman (ITP 4)
(3, 1, 3, 3, 6,  'Ganjil', 3, 6), -- Akhlak -> Penyakit sosial dalam perspektif Islam (ITP 6)
(4, 1, 4, 4, 8,  'Ganjil', 4, 6), -- Fikih -> Khotbah, Tabligh, dan Dakwah (ITP 8)
(5, 1, 5, 5, 10, 'Ganjil', 5, 4), -- Sejarah Peradaban Islam -> Tokoh ulama dunia (ITP 10)

-- Semester Genap (5 Elemen & 5 TP Pembelajaran)
(6,  1, 1, 6,  11, 'Genap', 1, 4), -- Al-Qur'an Hadis -> IPTEK dalam Islam (ITP 11)
(7,  1, 2, 7,  12, 'Genap', 2, 6), -- Akidah -> Iman, Islam, dan Ihsan (ITP 12)
(8,  1, 3, 8,  13, 'Genap', 3, 6), -- Akhlak -> Adab bermasyarakat (ITP 13)
(9,  1, 4, 9,  14, 'Genap', 4, 6), -- Fikih -> Fiqh muamalah modern & syariah (ITP 14)
(10, 1, 5, 10, 15, 'Genap', 5, 4)  -- Sejarah Peradaban Islam -> Tokoh ulama Nusantara (ITP 15)
ON DUPLICATE KEY UPDATE 
  `id_elemen` = VALUES(`id_elemen`),
  `id_tp` = VALUES(`id_tp`),
  `id_itp` = VALUES(`id_itp`),
  `alokasi_waktu` = VALUES(`alokasi_waktu`);

-- ------------------------------------------------------------------------------
-- SEEDER PROSEM (PROGRAM SEMESTER - VARIABEL DATA ESENSIAL SESUAI PROSEM.XLSX)
-- ------------------------------------------------------------------------------
INSERT INTO `prosem` (
  `id`, `id_prota`, `id_kelas_mata_pelajaran`, `id_guru`, 
  `tahun_pelajaran`, `semester`, `total_alokasi_jp`, `status`
) VALUES
(1, 1, 7, 1, '2025/2026', 'Ganjil', 36, 'Approved'),
(2, 1, 7, 1, '2025/2026', 'Genap',  38, 'Approved')
ON DUPLICATE KEY UPDATE 
  `total_alokasi_jp` = VALUES(`total_alokasi_jp`), 
  `status` = VALUES(`status`);

-- ------------------------------------------------------------------------------
-- SEEDER PROSEM DETAIL (RINCIAN AGENDA POKOK BAHASAN BERELASI KE TP & ITP - PROSEM.XLSX)
-- ------------------------------------------------------------------------------
INSERT INTO `prosem_detail` (
  `id`, `id_prosem`, `id_tp`, `id_itp`, `nomor_urut`, `kategori`, `nama_agenda`, `alokasi_waktu`, `id_modul_ajar`
) VALUES
-- Semester Ganjil (Sheet Ganjil PROSEM.xlsx - Total 36 JP)
(1,  1, 1,    1,    1,  'Materi KBM', NULL,                               4, 1),
(2,  1, 2,    4,    2,  'Materi KBM', NULL,                               6, NULL),
(3,  1, NULL, NULL, 3,  'Asesmen Sumatif', 'Asesmen Sumatif 1',           2, NULL),
(4,  1, NULL, NULL, 4,  'Sumatif Tengah Semester', 'Summatif Tengah Semester', 2, NULL),
(5,  1, 3,    6,    5,  'Materi KBM', NULL,                               6, NULL),
(6,  1, 4,    8,    6,  'Materi KBM', NULL,                               6, NULL),
(7,  1, 5,    10,   7,  'Materi KBM', NULL,                               4, NULL),
(8,  1, NULL, NULL, 8,  'Asesmen Sumatif', 'Asesmen Sumatif 2',           2, NULL),
(9,  1, NULL, NULL, 9,  'Proyek', 'Proyek P5 PPRA',                       2, NULL),
(10, 1, NULL, NULL, 10, 'Asesmen Sumatif Akhir Semester', 'Assesmen Summatif Akhir Semester', 2, NULL),

-- Semester Genap (Sheet Genap PROSEM.xlsx - Total 38 JP)
(11, 2, 6,    11,   1,  'Materi KBM', NULL,                               4, NULL),
(12, 2, 7,    12,   2,  'Materi KBM', NULL,                               6, NULL),
(13, 2, NULL, NULL, 3,  'Asesmen Sumatif', 'AS 1',                        2, NULL),
(14, 2, NULL, NULL, 4,  'Sumatif Tengah Semester', 'Summatif Tengah Semester', 2, NULL),
(15, 2, 8,    13,   5,  'Materi KBM', NULL,                               6, NULL),
(16, 2, 9,    14,   6,  'Materi KBM', NULL,                               6, NULL),
(17, 2, 10,   15,   7,  'Materi KBM', NULL,                               4, NULL),
(18, 2, NULL, NULL, 8,  'Asesmen Sumatif', 'AS 2',                        2, NULL),
(19, 2, NULL, NULL, 9,  'Proyek', 'Proyek P5 PPRA',                       2, NULL),
(20, 2, NULL, NULL, 10, 'Asesmen Sumatif Akhir Semester', 'Assesmen Summatif Akhir Semester', 2, NULL)
ON DUPLICATE KEY UPDATE 
  `id_tp` = VALUES(`id_tp`), 
  `id_itp` = VALUES(`id_itp`), 
  `nama_agenda` = VALUES(`nama_agenda`), 
  `alokasi_waktu` = VALUES(`alokasi_waktu`),
  `id_modul_ajar` = VALUES(`id_modul_ajar`);

-- ------------------------------------------------------------------------------
-- SEEDER PROSEM JADWAL MINGGUAN (DISTRIBUSI MATRIKS PER PEKAN - PROSEM.XLSX)
-- ------------------------------------------------------------------------------
INSERT INTO `prosem_jadwal_mingguan` (
  `id_prosem_detail`, `bulan`, `urutan_bulan`, `pekan_ke`, `jam_tatap_muka`, `keterangan`
) VALUES
-- Agenda 1: Berpikir Kritis (4 JP) -> Juli Pekan 3 & Pekan 4
(1, 'Juli', 1, 3, 2, 'KBM Pertemuan 1'),
(1, 'Juli', 1, 4, 2, 'KBM Pertemuan 2'),

-- Agenda 2: Syu'ab al-iman (6 JP) -> Juli Pekan 5, Agustus Pekan 2 & Pekan 3
(2, 'Juli', 1, 5, 2, 'KBM Pertemuan 3'),
(2, 'Agustus', 2, 2, 2, 'KBM Pertemuan 4'),
(2, 'Agustus', 2, 3, 2, 'KBM Pertemuan 5'),

-- Agenda 3: Asesmen Sumatif 1 (2 JP) -> Agustus Pekan 4
(3, 'Agustus', 2, 4, 2, 'Asesmen Sumatif Lingkup Materi 1'),

-- Agenda 4: Sumatif Tengah Semester (2 JP) -> September Pekan 1
(4, 'September', 3, 1, 2, 'Asesmen Sumatif Tengah Semester (ASTS)'),

-- Agenda 5: Penyakit Sosial (6 JP) -> September Pekan 2, 3, 4
(5, 'September', 3, 2, 2, 'KBM Pertemuan 6'),
(5, 'September', 3, 3, 2, 'KBM Pertemuan 7'),
(5, 'September', 3, 4, 2, 'KBM Pertemuan 8'),

-- Agenda 6: Khotbah, Tabligh, Dakwah (6 JP) -> September Pekan 5, Oktober Pekan 2 & 3
(6, 'September', 3, 5, 2, 'KBM Pertemuan 9'),
(6, 'Oktober', 4, 2, 2, 'KBM Pertemuan 10'),
(6, 'Oktober', 4, 3, 2, 'KBM Pertemuan 11'),

-- Agenda 7: Tokoh Ulama Dunia (4 JP) -> Oktober Pekan 4 & 5
(7, 'Oktober', 4, 4, 2, 'KBM Pertemuan 12'),
(7, 'Oktober', 4, 5, 2, 'KBM Pertemuan 13'),

-- Agenda 8: Asesmen Sumatif 2 (2 JP) -> November Pekan 2
(8, 'November', 5, 2, 2, 'Asesmen Sumatif Lingkup Materi 2'),

-- Agenda 9: Proyek P5 PPRA (2 JP) -> November Pekan 3
(9, 'November', 5, 3, 2, 'Pelaksanaan & Pameran Proyek P5 PPRA'),

-- Agenda 10: ASAS (2 JP) -> November Pekan 4
(10, 'November', 5, 4, 2, 'Asesmen Sumatif Akhir Semester (ASAS)')
ON DUPLICATE KEY UPDATE 
  `jam_tatap_muka` = VALUES(`jam_tatap_muka`), 
  `keterangan` = VALUES(`keterangan`);

-- ------------------------------------------------------------------------------
-- SEEDER TITIK KOORDINAT SEKOLAH (MASTER GEOFENCING SEKOLAH/KAMPUS)
-- ------------------------------------------------------------------------------
INSERT INTO `titik_koordinat_sekolah` (
  `id`, `nama_lokasi`, `latitude`, `longitude`, `radius_meter`, `alamat`, `is_active`
) VALUES
(1, 'Kampus Utama UNUGIRI Bojonegoro (Gedung Rektorat & KBM Terpadu)', -7.15664000, 111.88724000, 100, 'Jl. Ahmad Yani No. 10, Sukorejo, Bojonegoro, Jawa Timur', 1),
(2, 'Gedung Laboratorium PAI & Microteaching', -7.15685000, 111.88745000, 50, 'Sayap Timur Lantai 2, Kampus UNUGIRI Bojonegoro', 1)
ON DUPLICATE KEY UPDATE
  `nama_lokasi` = VALUES(`nama_lokasi`),
  `latitude` = VALUES(`latitude`),
  `longitude` = VALUES(`longitude`),
  `radius_meter` = VALUES(`radius_meter`);

-- ------------------------------------------------------------------------------
-- SEEDER JADWAL PELAJARAN (JADWAL KBM DIAMBIL DARI MODUL AJAR & PEKAN/HE)
-- ------------------------------------------------------------------------------
INSERT INTO `jadwal_pelajaran` (
  `id`, `id_kelas_mata_pelajaran`, `id_guru`, `id_modul_ajar`, `id_hari_efektif`, `id_pekan_efektif`, 
  `id_titik_sekolah`, `pertemuan_ke`, `tahun_pelajaran`, `semester`, `hari`, `tanggal`, 
  `jam_mulai`, `jam_selesai`, `alokasi_jp`, `materi_pembahasan`, `ruangan`, `status_jadwal`, `keterangan`
) VALUES
(1, 7, 2, 1, 1, 1, 1, 1, '2025/2026', 'Ganjil', 'Senin', '2025-07-21', '07:00:00', '08:30:00', 2, 
 'Berpikir Kritis dalam Islam: Membaca & Menghafal QS Ali Imran 190-191', 'Ruang XI PAI-1', 'Selesai', 'KBM pertemuan 1 telah selesai dilaksanakan dengan model PBL.'),

(2, 7, 2, 1, 1, 1, 1, 2, '2025/2026', 'Ganjil', 'Senin', '2025-07-28', '07:00:00', '08:30:00', 2, 
 'Berpikir Kritis dalam Islam: Analisis Makna & Karakter Ulil Albab', 'Ruang XI PAI-1', 'Berlangsung', 'KBM pertemuan 2 sedang berlangsung hari ini.'),

(3, 7, 2, 2, 2, 1, 1, 3, '2025/2026', 'Ganjil', 'Senin', '2025-08-04', '07:00:00', '08:30:00', 2, 
 'Syu\'ab al-Iman: Pengertian & 77 Cabang Iman', 'Ruang XI PAI-1', 'Terjadwal', 'KBM pertemuan 3 pekan mendatang.')
ON DUPLICATE KEY UPDATE
  `materi_pembahasan` = VALUES(`materi_pembahasan`),
  `status_jadwal` = VALUES(`status_jadwal`),
  `jam_mulai` = VALUES(`jam_mulai`),
  `jam_selesai` = VALUES(`jam_selesai`);

-- ------------------------------------------------------------------------------
-- SEEDER PRESENSI (MASTER KODE VALIDASI PRESENSI SISWA PER KELAS MAPEL)
-- Sesuai Data Real phpMyAdmin wp3f_dosen_presensi
-- ------------------------------------------------------------------------------
INSERT INTO `presensi` (
  `id`, `id_presensi`, `id_siswa`, `id_kelas_mata_pelajaran`, `tanggal_created`, `expired_at`, `status`
) VALUES
(1, '492401010245073302', 1, 7, '2026-10-02 10:59:56', '2027-01-19 22:29:00', 'aktif'),
(2, '542501012421744482', 2, 7, '2026-10-07 13:44:18', '2027-01-16 10:29:00', 'aktif'),
(3, '202501011405715122', 3, 7, '2026-10-07 13:45:00', '2027-01-16 10:29:00', 'aktif'),
(4, '612501019842103571', 4, 7, '2026-10-08 08:30:00', '2027-01-16 10:29:00', 'aktif')
ON DUPLICATE KEY UPDATE
  `id_presensi` = VALUES(`id_presensi`),
  `expired_at` = VALUES(`expired_at`),
  `status` = VALUES(`status`);

-- ------------------------------------------------------------------------------
-- SEEDER PERIZINAN (PENGAJUAN DUAL APPROVAL: KETUA KELAS -> GURU -> SYNC ABSENSI)
-- ------------------------------------------------------------------------------
INSERT INTO `perizinan` (
  `id`, `id_siswa`, `id_kelas_mata_pelajaran`, `id_jadwal`, `id_modul_ajar`, 
  `pertemuan_ke`, `tanggal_izin`, `jenis_izin`, `alasan`, `bukti_perizinan`, 
  `latitude`, `longitude`, `alamat_izin`, 
  `approval_ketua_kelas`, `id_ketua_kelas`, `waktu_approval_ketua`, `catatan_ketua_kelas`, 
  `approval_guru`, `id_guru`, `waktu_approval_guru`, `catatan_guru`, 
  `status_perizinan`, `is_sync_to_absensi`, `id_absensi`
) VALUES
-- Izin 1: Aisyah Sakit (S) -> Disetujui Ketua Kelas & Guru -> Sinkron ke Absensi
(1, 2, 7, 2, 1, 
 2, '2025-07-28', 'Sakit', 
 'Demam tinggi dan flu berat sejak malam hari, sedang periksa ke klinik dokter.', 
 'uploads/izin/surat_dokter_aisyah_20250728.pdf', 
 -7.15820000, 111.88530000, 'Klinik Medika Sehat Bojonegoro, Jl. Veteran No. 45', 
 'Approved', 3, '2025-07-28 06:50:15', 'Surat dokter valid dan sudah dikonfirmasi wali santri.', 
 'Approved', 2, '2025-07-28 07:05:00', 'Izin sakit disetujui guru pengampu. Semoga lekas sembuh.', 
 'Disetujui', 1, 2),

-- Izin 2: Fauzan Izin (I) -> Disetujui Ketua Kelas -> Masih Menunggu Guru (Pending)
(2, 3, 7, 2, 1, 
 2, '2025-07-28', 'Izin', 
 'Menghadiri resepsi pernikahan kakak kandung di luar kota.', 
 'uploads/izin/surat_wali_fauzan_20250728.jpg', 
 -7.16200000, 111.89000000, 'Kedungadem, Bojonegoro', 
 'Approved', 3, '2025-07-28 07:10:00', 'Surat izin orang tua telah diverifikasi ketua kelas.', 
 'Pending', NULL, NULL, NULL, 
 'Menunggu Persetujuan Guru', 0, NULL)
ON DUPLICATE KEY UPDATE
  `status_perizinan` = VALUES(`status_perizinan`),
  `approval_ketua_kelas` = VALUES(`approval_ketua_kelas`),
  `approval_guru` = VALUES(`approval_guru`),
  `is_sync_to_absensi` = VALUES(`is_sync_to_absensi`),
  `id_absensi` = VALUES(`id_absensi`);

-- ------------------------------------------------------------------------------
-- SEEDER ABSENSI (LOG TRANSAKSI SISWA PER PERTEMUAN: SISWA, KELAS MAPEL & GPS)
-- ------------------------------------------------------------------------------
INSERT INTO `absensi` (
  `id`, `id_siswa`, `id_kelas_mata_pelajaran`, `id_jadwal`, `id_modul_ajar`, 
  `id_titik_sekolah`, `pertemuan_ke`, `status`, `latitude`, `longitude`, `jarak_meter`, 
  `waktu_absen`, `id_perizinan`, `metode_absen`, `keterangan`
) VALUES
-- 1. Farhan (Ketua Kelas): Hadir tepat waktu di kelas via Scan QR & GPS
(1, 1, 7, 2, 1, 
 1, 2, 'H', -7.15663500, 111.88724500, 12.50, 
 '2025-07-28 06:55:12', NULL, 'Scan QR & GPS', 'Hadir tepat waktu di dalam radius kelas (12.5m)'),

-- 2. Aisyah: Sakit (S) - Sinkronisasi otomatis dari sistem perizinan yang disetujui Guru
(2, 2, 7, 2, 1, 
 1, 2, 'S', -7.15820000, 111.88530000, 280.00, 
 '2025-07-28 07:05:00', 1, 'Sistem Perizinan', 'Sakit (Disetujui Ketua Kelas & Guru Pengampu)'),

-- 3. Zahra: Terlambat (T) - Hadir jam 07:22 WIB via Scan QR & GPS
(3, 4, 7, 2, 1, 
 1, 2, 'T', -7.15665000, 111.88723000, 15.00, 
 '2025-07-28 07:22:45', NULL, 'Scan QR & GPS', 'Terlambat 22 menit karena kendala transportasi')
ON DUPLICATE KEY UPDATE
  `status` = VALUES(`status`),
  `latitude` = VALUES(`latitude`),
  `longitude` = VALUES(`longitude`),
  `jarak_meter` = VALUES(`jarak_meter`),
  `metode_absen` = VALUES(`metode_absen`),
  `keterangan` = VALUES(`keterangan`);

-- ------------------------------------------------------------------------------
-- SEEDER TUGAS (MASTER PENUGASAN GURU BERBASIS MODUL AJAR & ITP)
-- ------------------------------------------------------------------------------
INSERT INTO `tugas` (
  `id`, `id_kelas_mata_pelajaran`, `id_guru`, `id_modul_ajar`, `id_tp`, `id_itp`, 
  `id_jadwal`, `pertemuan_ke`, `judul_tugas`, `deskripsi_instruksi`, 
  `jenis_penugasan`, `tipe_pengumpulan`, `format_file_diizinkan`, 
  `file_lampiran_tugas`, `link_sumber_tugas`, `nilai_maksimal`, `bobot_nilai`, 
  `tanggal_mulai`, `tenggat_waktu`, `status_tugas`
) VALUES
-- Tugas 1: Penugasan Individu Pertemuan 2 (Modul Ajar 01, ITP 1.2.1)
(1, 7, 2, 1, 1, 2, 
 2, 2, 'Analisis Karakter Ulil Albab & Berpikir Kritis dalam Konteks Generasi Z', 
 'Susunlah esai analisis kritis (minimal 500 kata) mengenai penerapan nilai-nilai Ulil Albab (QS Ali Imran: 190-191) dalam menyikapi disrupsi digital dan fenomena hoaks. Tugas dapat diunggah dalam format berkas PDF atau menyertakan tautan Google Docs.', 
 'Individu', 'File atau Link', 'pdf,docx', 
 'uploads/tugas/panduan_analisis_ulil_albab.pdf', 'https://quran.kemenag.go.id/quran/per-ayat/surah/3?from=190&to=191', 100.00, 15, 
 '2025-07-28 08:30:00', '2025-08-03 23:59:00', 'Dibuka'),

-- Tugas 2: Penugasan Kelompok Pertemuan 3 (Modul Ajar 02, ITP 2.1.1)
(2, 7, 2, 2, 2, 3, 
 3, 3, 'Proyek Kolaboratif: Visualisasi Infografis Interaktif 77 Cabang Iman (Syu\'ab al-Iman)', 
 'Secara berkelompok (2-3 santri), buatlah media infografis digital yang mengklasifikasikan 77 cabang iman ke dalam 3 dimensi utama: ma\'rifatun bil qalbi, iqrarun bil lisan, dan \'amalun bil arkan. Pengumpulan berupa link Canva/Figma atau upload file poster PDF HD.', 
 'Kelompok', 'File atau Link', 'pdf,png,jpg', 
 'uploads/tugas/rubrik_penilaian_proyek_iman.pdf', 'https://sumberbelajar.belajar.kemdikbud.go.id', 100.00, 20, 
 '2025-08-04 08:30:00', '2025-08-11 23:59:00', 'Dibuka')
ON DUPLICATE KEY UPDATE
  `judul_tugas` = VALUES(`judul_tugas`),
  `deskripsi_instruksi` = VALUES(`deskripsi_instruksi`),
  `tenggat_waktu` = VALUES(`tenggat_waktu`),
  `status_tugas` = VALUES(`status_tugas`);

-- ------------------------------------------------------------------------------
-- SEEDER TUGAS_KELOMPOK & ANGGOTA (UNTUK PENUGASAN KELOMPOK)
-- ------------------------------------------------------------------------------
INSERT INTO `tugas_kelompok` (
  `id`, `id_tugas`, `id_kelas_mata_pelajaran`, `nomor_kelompok`, `nama_kelompok`, `id_ketua_kelompok`
) VALUES
(1, 2, 7, 1, 'Kelompok 1 - Abu Bakar Ash-Shiddiq', 1),
(2, 2, 7, 2, 'Kelompok 2 - Umar bin Khattab', 3)
ON DUPLICATE KEY UPDATE
  `nama_kelompok` = VALUES(`nama_kelompok`),
  `id_ketua_kelompok` = VALUES(`id_ketua_kelompok`);

INSERT INTO `tugas_kelompok_anggota` (
  `id`, `id_tugas_kelompok`, `id_siswa`, `id_kelas_mata_pelajaran`
) VALUES
-- Anggota Kelompok 1: Farhan (Ketua) & Aisyah
(1, 1, 1, 7),
(2, 1, 2, 7),
-- Anggota Kelompok 2: Fauzan (Ketua) & Zahra
(3, 2, 3, 7),
(4, 2, 4, 7)
ON DUPLICATE KEY UPDATE
  `id_siswa` = VALUES(`id_siswa`);

-- ------------------------------------------------------------------------------
-- SEEDER PENGUMPULAN_TUGAS (SISWA MENGUMPULKAN VIA PDF / LINK URL / TEKS)
-- ------------------------------------------------------------------------------
INSERT INTO `pengumpulan_tugas` (
  `id`, `id_tugas`, `id_siswa`, `id_kelas_mata_pelajaran`, `id_tugas_kelompok`, 
  `tipe_pengumpulan`, `file_tugas`, `file_nama_asli`, `file_ukuran_kb`, `link_tugas`, 
  `catatan_siswa`, `waktu_pengumpulan`, `status_pengumpulan`, `is_terlambat`, `revisi_ke`
) VALUES
-- 1. Farhan kumpulkan Tugas 1 (Individu) via Upload File PDF
(1, 1, 1, 7, NULL, 
 'File Upload', 'uploads/tugas/2025_t1_farhan_ulil_albab.pdf', 'Esai_Ulil_Albab_Farhan.pdf', 480, NULL, 
 'Berikut esai analisis kritis ulil albab saya Ustadz, mohon arahannya.', '2025-07-30 14:20:00', 'Diserahkan', 0, 1),

-- 2. Zahra kumpulkan Tugas 1 (Individu) via Link Google Docs
(2, 1, 4, 7, NULL, 
 'Link URL', NULL, NULL, NULL, 'https://docs.google.com/document/d/1BxiMVs0XRA5nFMdKvBdBZj_xY1/edit?usp=sharing', 
 'Tugas analisis sudah diselesaikan via Google Docs dengan referensi jurnal studi Islam kontemporer.', '2025-07-31 19:45:10', 'Diserahkan', 0, 1),

-- 3. Farhan kumpulkan Tugas 2 (Kelompok 1) via Upload File PDF + Link Canva
(3, 2, 1, 7, 1, 
 'File dan Link', 'uploads/tugas/2025_t2_kelompok1_infografis.pdf', 'Infografis_Cabang_Iman_Kelompok1.pdf', 2450, 'https://www.canva.com/design/DAFexample/view', 
 'Pengumpulan tugas proyek infografis Kelompok 1 (Farhan & Aisyah). Tersedia versi PDF cetak HD dan link Canva.', '2025-08-08 10:15:00', 'Diserahkan', 0, 1)
ON DUPLICATE KEY UPDATE
  `status_pengumpulan` = VALUES(`status_pengumpulan`),
  `file_tugas` = VALUES(`file_tugas`),
  `link_tugas` = VALUES(`link_tugas`),
  `catatan_siswa` = VALUES(`catatan_siswa`);

-- ------------------------------------------------------------------------------
-- SEEDER NILAI_TUGAS (PENILAIAN GURU: SKOR, PREDIKAT, KETERCAPAIAN ITP & FEEDBACK)
-- ------------------------------------------------------------------------------
INSERT INTO `nilai_tugas` (
  `id`, `id_tugas`, `id_pengumpulan_tugas`, `id_siswa`, `id_kelas_mata_pelajaran`, `id_guru`, 
  `nilai`, `predikat`, `aspek_itp_ketercapaian`, `catatan_guru`, `status_penilaian`, `waktu_penilaian`
) VALUES
-- Penilaian Tugas 1 Farhan: Nilai 94.50 (A) - Ketercapaian Sangat Baik
(1, 1, 1, 1, 7, 2, 
 94.50, 'A', 'Tercapai Sangat Baik', 
 'Analisis luar biasa tajam. Refleksi mengenai bahaya hoaks terhubung erat dengan kriteria ulil albab (QS Ali Imran: 190-191). Struktur penulisan sangat rapi.', 'Tuntas', '2025-08-01 09:30:00'),

-- Penilaian Tugas 1 Zahra: Nilai 88.00 (B) - Ketercapaian Baik
(2, 1, 2, 4, 7, 2, 
 88.00, 'B', 'Tercapai', 
 'Uraian dalil naqli dan tafsir sangat runtut. Sedikit masukan: elaborasikan lebih lanjut studi kasus implementasi adab digital.', 'Tuntas', '2025-08-01 10:00:00'),

-- Penilaian Tugas 2 Kelompok 1 (Farhan & Aisyah): Nilai 92.00 (A) - Ketercapaian Sangat Baik
(3, 2, 3, 1, 7, 2, 
 92.00, 'A', 'Tercapai Sangat Baik', 
 'Visualisasi infografis 77 cabang iman sangat komunikatif, pembagian 3 dimensi iman sangat akurat, estetika desain sangat menarik.', 'Tuntas', '2025-08-09 14:10:00')
ON DUPLICATE KEY UPDATE
  `nilai` = VALUES(`nilai`),
  `predikat` = VALUES(`predikat`),
  `aspek_itp_ketercapaian` = VALUES(`aspek_itp_ketercapaian`),
  `catatan_guru` = VALUES(`catatan_guru`),
  `status_penilaian` = VALUES(`status_penilaian`);

-- ------------------------------------------------------------------------------
-- SEEDER ASESMEN DIAGNOSTIK, FORMATIF, SUMATIF, LKPD & PENGAYAAN REMEDIAL
-- ------------------------------------------------------------------------------
-- 1. Master Asesmen Diagnostik & Butir Soal, Opsi, Rubrik, dan Nilai Siswa
INSERT INTO `asesmen_diagnostik` (
  `id`, `id_kelas_mata_pelajaran`, `id_guru`, `id_modul_ajar`, `id_cp`, `id_tp`, `id_atp`, `id_itp`, 
  `pertemuan_ke`, `judul_asesmen`, `jenis_diagnostik`, `tujuan_asesmen`, `petunjuk_pengerjaan`, `status`
) VALUES
(1, 7, 2, 1, 1, 1, 1, 1, 
 1, 'Tes Diagnostik Kognitif Awal: Kemampuan Prasyarat Tajwid & Pemahaman Makna QS Ali Imran', 
 'Kognitif', 'Memetakan kompetensi awal santri dalam membaca Al-Qur\'an tartil dan pemahaman nalar berpikir kritis.', 
 'Jawablah 5 butir pertanyaan awal berikut secara jujur dan mandiri sebelum KBM dimulai.', 'Aktif')
ON DUPLICATE KEY UPDATE `judul_asesmen` = VALUES(`judul_asesmen`);

INSERT INTO `asesmen_diagnostik_soal` (
  `id`, `id_asesmen_diagnostik`, `id_kelas_mata_pelajaran`, `id_modul_ajar`, `pertemuan_ke`, 
  `id_cp`, `id_tp`, `id_atp`, `id_itp`, `nomor_soal`, `tipe_soal`, `pertanyaan`, 
  `tingkat_kesulitan`, `level_kognitif`, `bobot_skor`
) VALUES
(1, 1, 7, 1, 1, 1, 1, 1, 1, 1, 'Pilihan Ganda',
 'Manakah di antara potongan ayat berikut yang menunjukkan anjuran untuk senantiasa memperhatikan tanda-tanda kebesaran Allah di langit dan bumi?',
 'Mudah', 'C2 - Memahami', 20.00)
ON DUPLICATE KEY UPDATE `pertanyaan` = VALUES(`pertanyaan`);

INSERT INTO `asesmen_diagnostik_soal_opsi` (
  `id`, `id_soal`, `kode_opsi`, `teks_opsi`, `is_kunci_jawaban`, `penjelasan_kunci`, `bobot_nilai_opsi`
) VALUES
(1, 1, 'A', 'إِنَّ فِي خَلْقِ السَّمَاوَاتِ وَالْأَرْضِ وَاخْتِلَافِ اللَّيْلِ وَالنَّهَارِ لَآيَاتٍ لِّأُولِي الْأَلْبَابِ', 1, 'Kunci Benar: QS Ali Imran 190 secara eksplisit menegaskan bahwa penciptaan langit, bumi, dan pergantian siang-malam adalah bukti nyata bagi ulil albab.', 20.00),
(2, 1, 'B', 'يَا أَيُّهَا الَّذِينَ آمَنُوا اتَّقُوا اللَّهَ حَقَّ تُقَاتِهِ', 0, 'Pengecoh: Ayat tentang perintah bertakwa secara umum (QS Ali Imran: 102).', 0.00),
(3, 1, 'C', 'وَاعْتَصِمُوا بِحَبْلِ اللَّهِ جَمِيعًا وَلَا تَفَرَّقُوا', 0, 'Pengecoh: Ayat tentang persatuan umat (QS Ali Imran: 103).', 0.00),
(4, 1, 'D', 'كُنتُمْ خَيْرَ أُمَّةٍ أُخْرِجَتْ لِلنَّاسِ', 0, 'Pengecoh: Ayat tentang khaira ummah (QS Ali Imran: 110).', 0.00)
ON DUPLICATE KEY UPDATE `is_kunci_jawaban` = VALUES(`is_kunci_jawaban`);

INSERT INTO `asesmen_diagnostik_soal_rubrik` (
  `id`, `id_soal`, `id_itp`, `aspek_kompetensi`, `pedoman_penskoran`, `skor_maksimal`, 
  `kriteria_skor_maksimal`, `kriteria_skor_nol`
) VALUES
(1, 1, 1, 'Kemampuan Awal Mengenali Teks Dalil Ulil Albab',
 'Koreksi otomatis pilihan ganda: Jawaban A skor 20, lainnya 0.', 20.00,
 'Memilih opsi A dengan tepat membuktikan kesiapan kognitif prasyarat yang memadai.',
 'Memilih opsi pengecoh atau tidak menjawab.')
ON DUPLICATE KEY UPDATE `pedoman_penskoran` = VALUES(`pedoman_penskoran`);

INSERT INTO `nilai_asesmen_diagnostik` (
  `id`, `id_siswa`, `id_kelas_mata_pelajaran`, `id_asesmen_diagnostik`, `id_guru`, 
  `id_modul_ajar`, `id_tp`, `id_itp`, `pertemuan_ke`, `nilai_angka`, `nilai_kktp`, 
  `predikat`, `status_tuntas`, `catatan_evaluasi`, `waktu_penilaian`
) VALUES
(1, 1, 7, 1, 2, 1, 1, 1, 1, 85.00, 75.00, 'B', 'Siap Belajar', 'Kesiapan belajar awal sangat baik, bacaan tartil lancar dan mengenali ayat dengan cermat.', '2025-07-21 08:30:00')
ON DUPLICATE KEY UPDATE `nilai_angka` = VALUES(`nilai_angka`);

-- 2. Master Asesmen Formatif & Butir Soal, Opsi, Rubrik, dan Nilai Siswa
INSERT INTO `asesmen_formatif` (
  `id`, `id_kelas_mata_pelajaran`, `id_guru`, `id_modul_ajar`, `id_cp`, `id_tp`, `id_atp`, `id_itp`, 
  `pertemuan_ke`, `judul_asesmen`, `teknik_asesmen`, `tujuan_asesmen`, `petunjuk_pengerjaan`, `status`
) VALUES
(1, 7, 2, 1, 1, 1, 1, 2, 
 2, 'Asesmen Formatif Proses KBM: Kuis Analisis Karakteristik Ulil Albab', 
 'Tes Tertulis', 'Mengukur pemahaman proses murid terkait kriteria ulil albab dan memberikan feedback perbaikan.', 
 'Kerjakan kuis pemahaman konsep berikut pada lembar kerja digital dalam waktu 20 menit.', 'Aktif')
ON DUPLICATE KEY UPDATE `judul_asesmen` = VALUES(`judul_asesmen`);

INSERT INTO `asesmen_formatif_soal` (
  `id`, `id_asesmen_formatif`, `id_kelas_mata_pelajaran`, `id_modul_ajar`, `pertemuan_ke`, 
  `id_cp`, `id_tp`, `id_atp`, `id_itp`, `nomor_soal`, `tipe_soal`, `pertanyaan`, 
  `tingkat_kesulitan`, `level_kognitif`, `bobot_skor`
) VALUES
(1, 1, 7, 1, 2, 1, 1, 1, 2, 1, 'Essay / Uraian', 
 'Sebutkan 3 dampak destruktif dari penyebaran berita hoaks bagi keharmonisan ukhuwah islamiyah dan bagaimana solusinya menurut perspektif Islam!', 
 'Sedang', 'C3 - Mengaplikasikan', 15.00)
ON DUPLICATE KEY UPDATE `pertanyaan` = VALUES(`pertanyaan`);

INSERT INTO `asesmen_formatif_soal_rubrik` (
  `id`, `id_soal`, `id_itp`, `aspek_kompetensi`, `pedoman_penskoran`, `skor_maksimal`, 
  `kriteria_skor_maksimal`, `kriteria_skor_sedang`, `kriteria_skor_minimal`, `kriteria_skor_nol`
) VALUES
(1, 1, 2, 'Aplikasi Nilai Preventif Hoaks dalam Kehidupan Remaja', 
 'Penilaian rubrik holistik: 3 dampak konkret (6 poin), solusi tabayyun & etika (9 poin).', 15.00, 
 'Menyebutkan 3 dampak hoaks secara tepat dan merumuskan solusi tabayyun islami yang terukur (Skor 13-15).', 
 'Menyebutkan dampak tetapi solusi kurang spesifik (Skor 8-12).', 
 'Hanya menyebutkan 1 dampak secara umum (Skor 3-7).', 
 'Tidak menjawab atau jawaban tidak relevan (Skor 0).')
ON DUPLICATE KEY UPDATE `pedoman_penskoran` = VALUES(`pedoman_penskoran`);

INSERT INTO `akses_asesmen_formatif` (
  `id`, `id_asesmen_formatif`, `id_siswa`, `id_kelas_mata_pelajaran`, 
  `nomor_peserta`, `username_ujian`, `password_ujian`, `token_sesi`, 
  `status_akses`, `waktu_mulai`, `waktu_selesai`
) VALUES
(1, 1, 1, 7, 'FMT-2026-X1-001', 'fmt_farhan01', 'fmt_pass2026', 'FORMATIF1', 'Selesai', '2025-07-28 08:00:00', '2025-07-28 08:35:00')
ON DUPLICATE KEY UPDATE `username_ujian` = VALUES(`username_ujian`);

INSERT INTO `nilai_asesmen_formatif` (
  `id`, `id_siswa`, `id_kelas_mata_pelajaran`, `id_asesmen_formatif`, `id_guru`, 
  `id_modul_ajar`, `id_tp`, `id_itp`, `pertemuan_ke`, `nilai_angka`, `nilai_kktp`, 
  `predikat`, `status_tuntas`, `catatan_evaluasi`, `waktu_penilaian`
) VALUES
(1, 1, 7, 1, 2, 1, 1, 2, 2, 92.00, 75.00, 'A', 'Tuntas', 'Pemahaman konsep ulil albab sangat mendalam dan aktif berdiskusi dalam proses KBM.', '2025-07-28 08:45:00')
ON DUPLICATE KEY UPDATE `nilai_angka` = VALUES(`nilai_angka`);

-- 3. Master Asesmen Sumatif & Butir Soal, Opsi, Rubrik, dan Nilai Siswa
INSERT INTO `asesmen_sumatif` (
  `id`, `id_kelas_mata_pelajaran`, `id_guru`, `id_modul_ajar`, `id_cp`, `id_tp`, `id_atp`, `id_itp`, 
  `pertemuan_ke`, `judul_asesmen`, `jenis_sumatif`, `kktp`, `durasi_menit`, `petunjuk_pengerjaan`, `status`
) VALUES
(1, 7, 2, 1, 1, 1, 1, 2, 
 2, 'Asesmen Sumatif Lingkup Materi 1: Berpikir Kritis & Ulil Albab dalam Islam', 
 'Sumatif Lingkup Materi', 75.00, 60, 
 'Pilihlah satu jawaban yang paling tepat pada soal pilihan ganda, dan uraikan argumentasi dalil secara komprehensif pada soal esai.', 'Aktif')
ON DUPLICATE KEY UPDATE `judul_asesmen` = VALUES(`judul_asesmen`);

INSERT INTO `asesmen_sumatif_kelas` (
  `id`, `id_asesmen_sumatif`, `id_kelas_mata_pelajaran`
) VALUES
(1, 1, 7), -- XI PAI-1
(2, 1, 8)  -- XII PAI-1 (Contoh asesmen sumatif dilaksanakan serentak oleh 2 atau lebih rombel kelas)
ON DUPLICATE KEY UPDATE `id_kelas_mata_pelajaran` = VALUES(`id_kelas_mata_pelajaran`);

INSERT INTO `asesmen_sumatif_soal` (
  `id`, `id_asesmen_sumatif`, `id_kelas_mata_pelajaran`, `id_modul_ajar`, `pertemuan_ke`, 
  `id_cp`, `id_tp`, `id_atp`, `id_itp`, `nomor_soal`, `tipe_soal`, `pertanyaan`, 
  `tingkat_kesulitan`, `level_kognitif`, `bobot_skor`
) VALUES
(1, 1, 7, 1, 2, 1, 1, 1, 2, 1, 'Pilihan Ganda', 
 'Perhatikan penggalan QS. Ali Imran ayat 191: الَّذِينَ يَذْكُرُونَ اللَّهَ قِيَامًا وَقُعُودًا وَعَلَىٰ جُنُوبِهِمْ. Berdasarkan ayat tersebut, salah satu ciri utama ulil albab adalah senantiasa berdzikir kepada Allah dalam keadaan...', 
 'Sedang', 'C2 - Memahami', 20.00),
(2, 1, 7, 1, 2, 1, 1, 1, 2, 2, 'Pilihan Ganda', 
 'Sikap berpikir kritis seorang muslim saat menerima sebuah informasi di era digital yang belum tentu kebenarannya sesuai prinsip Al-Qur\'an adalah melakukan...', 
 'Sedang', 'C4 - Menganalisis', 20.00),
(3, 1, 7, 1, 2, 1, 1, 1, 2, 3, 'Essay / Uraian', 
 'Jelaskan hubungan harmonis antara aktivitas tadabbur alam (berpikir) dan dzikrullah (mengingat Allah) dalam membentuk kepribadian ulil albab yang berakhlak mulia!', 
 'Sukar', 'C4 - Menganalisis', 30.00)
ON DUPLICATE KEY UPDATE `pertanyaan` = VALUES(`pertanyaan`);

INSERT INTO `asesmen_sumatif_soal_opsi` (
  `id`, `id_soal`, `kode_opsi`, `teks_opsi`, `is_kunci_jawaban`, `penjelasan_kunci`, `bobot_nilai_opsi`
) VALUES
-- Opsi Soal 1
(1, 1, 'A', 'Berdiri, duduk, dan berbaring', 1, 'Kunci Benar: Lafadz qiyaman wa qu\'udan wa \'ala junubihim mencakup tiga posisi fisik utama manusia (berdiri, duduk, dan berbaring).', 20.00),
(2, 1, 'B', 'Hanya ketika berada di dalam masjid', 0, 'Pengecoh: Dzikir tidak terbatas di masjid.', 0.00),
(3, 1, 'C', 'Saat tertimpa musibah dan kesulitan saja', 0, 'Pengecoh: Ulil albab berdzikir dalam segala kondisi.', 0.00),
(4, 1, 'D', 'Ketika membaca kitab suci Al-Qur\'an saja', 0, 'Pengecoh: Dzikir mencakup ucapan dan kesadaran batin.', 0.00),
-- Opsi Soal 2
(5, 2, 'A', 'Langsung membagikan ke media sosial tanpa verifikasi', 0, 'Pengecoh: Menyebarkan tanpa cek menimbulkan fitnah.', 0.00),
(6, 2, 'B', 'Tabayyun (klarifikasi dan verifikasi sumber data)', 1, 'Kunci Benar: Prinsip QS Al-Hujurat ayat 6 memerintahkan tabayyun terhadap setiap berita fasik.', 20.00),
(7, 2, 'C', 'Menghapus aplikasi percakapan', 0, 'Pengecoh: Tindakan reaktif tanpa solusi esensial.', 0.00),
(8, 2, 'D', 'Mengabaikan semua informasi ilmiah', 0, 'Pengecoh: Bertentangan dengan semangat literasi.', 0.00)
ON DUPLICATE KEY UPDATE `is_kunci_jawaban` = VALUES(`is_kunci_jawaban`);

INSERT INTO `asesmen_sumatif_soal_rubrik` (
  `id`, `id_soal`, `id_itp`, `aspek_kompetensi`, `pedoman_penskoran`, `skor_maksimal`, 
  `kriteria_skor_maksimal`, `kriteria_skor_sedang`, `kriteria_skor_minimal`, `kriteria_skor_nol`
) VALUES
(1, 1, 2, 'Pemahaman Tekstual Karakter Ulil Albab', 
 'Pemeriksaan otomatis kunci jawaban: Memilih opsi benar A = 20, salah = 0.', 20.00, 
 'Memilih opsi A dengan tepat sesuai makna qiyaman wa qu\'udan wa \'ala junubihim.', 
 NULL, NULL, 'Memilih opsi salah atau tidak menjawab.'),
(2, 2, 2, 'Penerapan Nalar Kritis & Tabayyun di Era Digital', 
 'Pemeriksaan otomatis kunci jawaban: Memilih opsi benar B = 20, salah = 0.', 20.00, 
 'Memilih opsi B (Tabayyun) sebagai wujud penalaran kritis etika digital Islam.', 
 NULL, NULL, 'Memilih opsi salah atau tidak menjawab.'),
(3, 3, 2, 'Analisis Komprehensif Hubungan Berpikir & Berdzikir', 
 'Penilaian rubrik analitik: Uraian dalil (10 poin), Integrasi logika & iman (10 poin), Konteks akhlak (10 poin).', 30.00, 
 'Menjelaskan hubungan berpikir dan berdzikir secara mendalam, menyertakan ayat dan hadis penguat, serta elaborasi akhlak kontekstual (Skor 25-30).', 
 'Menjelaskan korelasi namun ulasan dalil atau konteks akhlak belum lengkap (Skor 15-24).', 
 'Hanya mendefinisikan berpikir dan berdzikir secara terpisah tanpa menghubungkannya (Skor 5-14).', 
 'Jawaban keliru atau tidak relevan sama sekali (Skor 0).')
ON DUPLICATE KEY UPDATE `pedoman_penskoran` = VALUES(`pedoman_penskoran`);

INSERT INTO `akses_asesmen_sumatif` (
  `id`, `id_asesmen_sumatif`, `id_siswa`, `id_kelas_mata_pelajaran`, 
  `nomor_peserta`, `username_ujian`, `password_ujian`, `token_sesi`, 
  `status_akses`, `waktu_mulai`, `waktu_selesai`
) VALUES
(1, 1, 1, 7, 'SMT-2026-X1-001', 'smt_farhan01', 'smt_pass2026', 'ULILALBAB1', 'Selesai', '2025-07-28 08:30:00', '2025-07-28 09:45:00'),
(2, 1, 4, 7, 'SMT-2026-X1-002', 'smt_zahra02', 'smt_pass2026', 'ULILALBAB1', 'Selesai', '2025-07-28 08:30:00', '2025-07-28 09:40:00')
ON DUPLICATE KEY UPDATE `username_ujian` = VALUES(`username_ujian`);

INSERT INTO `absensi_asesmen_sumatif` (
  `id`, `id_asesmen_sumatif`, `nomor_peserta`, `id_siswa`, 
  `id_kelas_mata_pelajaran`, `status_kehadiran`, `metode_absen`, `waktu_absen`, 
  `diabsen_oleh`, `keterangan`
) VALUES
(1, 1, 'SMT-2026-X1-001', 1, 7, 'Hadir', 'Scan QR', '2025-07-28 08:15:00', 1, 'Presensi via scan QR code kartu peserta.'),
(2, 1, 'SMT-2026-X1-002', 4, 7, 'Hadir', 'Manual', '2025-07-28 08:20:00', 1, 'Presensi input manual oleh pengawas.')
ON DUPLICATE KEY UPDATE `status_kehadiran` = VALUES(`status_kehadiran`);

INSERT INTO `nilai_asesmen_sumatif` (
  `id`, `id_siswa`, `id_kelas_mata_pelajaran`, `id_asesmen_sumatif`, `id_guru`, 
  `id_modul_ajar`, `id_tp`, `id_itp`, `pertemuan_ke`, `nilai_angka`, `nilai_kktp`, 
  `predikat`, `status_tuntas`, `catatan_evaluasi`, `waktu_penilaian`
) VALUES
(1, 1, 7, 1, 2, 1, 1, 2, 2, 95.00, 75.00, 'A', 'Tuntas', 'Sangat menguasai butir soal PG dan esai analitis secara sempurna melampaui KKTP.', '2025-07-28 10:00:00'),
(2, 4, 7, 1, 2, 1, 1, 2, 2, 88.00, 75.00, 'B', 'Tuntas', 'Jawaban tepat dan runtut, capaian melampaui KKTP batas standar.', '2025-07-28 10:00:00')
ON DUPLICATE KEY UPDATE `nilai_angka` = VALUES(`nilai_angka`);

-- 4. Master LKPD & Butir Soal, Opsi, Rubrik, dan Nilai Siswa
INSERT INTO `lkpd` (
  `id`, `id_kelas_mata_pelajaran`, `id_guru`, `id_modul_ajar`, `id_cp`, `id_tp`, `id_atp`, `id_itp`, 
  `pertemuan_ke`, `nomor_lkpd`, `judul_lkpd`, `tujuan_aktivitas`, `petunjuk_kerja`, `alokasi_waktu_menit`, `file_lampiran`, `status`
) VALUES
(1, 7, 2, 1, 1, 1, 1, 2, 
 2, 'LKPD-01', 'LKPD 01: Eksplorasi Fenomena Alam dan Karakteristik Ulil Albab', 
 'Murid mampu menelaah fenomena alam ciptaan Allah dan mengidentifikasi 5 ciri ulil albab.', 
 'Diskusikan bersama rekan sebangku, cermati QS Ali Imran 190-191, lalu lengkapi tabel analisis fenomena.', 45, 
 'uploads/lkpd/lkpd_01_ulil_albab.pdf', 'Aktif')
ON DUPLICATE KEY UPDATE `judul_lkpd` = VALUES(`judul_lkpd`);

INSERT INTO `lkpd_soal` (
  `id`, `id_lkpd`, `id_kelas_mata_pelajaran`, `id_modul_ajar`, `pertemuan_ke`, 
  `id_cp`, `id_tp`, `id_atp`, `id_itp`, `nomor_soal`, `tipe_soal`, `pertanyaan`, 
  `tingkat_kesulitan`, `level_kognitif`, `bobot_skor`
) VALUES
(1, 1, 7, 1, 2, 1, 1, 1, 2, 1, 'Pilihan Ganda', 
 'Hukum tajwid lafadz إِنَّ فِي خَلْقِ السَّمَاوَاتِ وَالْأَرْضِ pada kata إِنَّ adalah...', 
 'Mudah', 'C1 - Mengingat', 15.00)
ON DUPLICATE KEY UPDATE `pertanyaan` = VALUES(`pertanyaan`);

INSERT INTO `lkpd_soal_opsi` (
  `id`, `id_soal`, `kode_opsi`, `teks_opsi`, `is_kunci_jawaban`, `penjelasan_kunci`, `bobot_nilai_opsi`
) VALUES
(1, 1, 'A', 'Ghunnah Musyaddadah', 1, 'Kunci Benar: Huruf Nun bertasydid dibaca dengung dengan tempo 2 harakat.', 15.00),
(2, 1, 'B', 'Idgham Bighunnah', 0, 'Pengecoh: Tidak ada nun sukun bertemu huruf idgham.', 0.00),
(3, 1, 'C', 'Iqlab', 0, 'Pengecoh: Tidak ada nun sukun bertemu ba.', 0.00),
(4, 1, 'D', 'Izhhar Halqi', 0, 'Pengecoh: Bukan huruf tenggorokan.', 0.00)
ON DUPLICATE KEY UPDATE `is_kunci_jawaban` = VALUES(`is_kunci_jawaban`);

INSERT INTO `lkpd_soal_rubrik` (
  `id`, `id_soal`, `id_itp`, `aspek_kompetensi`, `pedoman_penskoran`, `skor_maksimal`, 
  `kriteria_skor_maksimal`, `kriteria_skor_nol`
) VALUES
(1, 1, 1, 'Penguasaan Kaidah Tajwid Nun Tasydid', 
 'Pemeriksaan otomatis kunci jawaban: Memilih opsi A = 15, salah = 0.', 15.00, 
 'Memilih opsi A (Ghunnah Musyaddadah) dengan tepat.', 
 'Memilih opsi salah atau tidak menjawab.')
ON DUPLICATE KEY UPDATE `pedoman_penskoran` = VALUES(`pedoman_penskoran`);

INSERT INTO `nilai_lkpd` (
  `id`, `id_siswa`, `id_kelas_mata_pelajaran`, `id_lkpd`, `id_guru`, 
  `id_modul_ajar`, `id_tp`, `id_itp`, `pertemuan_ke`, `nilai_angka`, `nilai_kktp`, 
  `predikat`, `status_tuntas`, `catatan_evaluasi`, `waktu_penilaian`
) VALUES
(1, 1, 7, 1, 2, 1, 1, 2, 2, 90.00, 75.00, 'A', 'Tuntas', 'Pengerjaan lembar kerja sangat sistematis dan analisis fenomena alam sangat tajam.', '2025-07-28 08:30:00')
ON DUPLICATE KEY UPDATE `nilai_angka` = VALUES(`nilai_angka`);

-- 5. Master Pengayaan Remedial & Butir Soal, Opsi, Rubrik, dan Nilai Siswa
INSERT INTO `pengayaan_remedial` (
  `id`, `id_kelas_mata_pelajaran`, `id_guru`, `id_modul_ajar`, `id_cp`, `id_tp`, `id_atp`, `id_itp`, 
  `pertemuan_ke`, `kategori`, `judul_program`, `kktp_minimal`, `bentuk_kegiatan`, `petunjuk_pelaksanaan`, `status`
) VALUES
(1, 7, 2, 1, 1, 1, 1, 2, 
 2, 'Remedial', 'Program Bimbingan Remedial: Pemahaman Kaidah Tajwid dan Terjemah Ayat QS Ali Imran', 
 75.00, 'Bimbingan Perorangan', 'Santri yang belum mencapai KKTP 75 mengikuti sesi tilawah terbimbing dan pengulasan kosakata tafsir.', 'Aktif')
ON DUPLICATE KEY UPDATE `judul_program` = VALUES(`judul_program`);

INSERT INTO `pengayaan_remedial_soal` (
  `id`, `id_pengayaan_remedial`, `id_kelas_mata_pelajaran`, `id_modul_ajar`, `pertemuan_ke`, 
  `id_cp`, `id_tp`, `id_atp`, `id_itp`, `nomor_soal`, `tipe_soal`, `pertanyaan`, 
  `tingkat_kesulitan`, `level_kognitif`, `bobot_skor`
) VALUES
(1, 1, 7, 1, 2, 1, 1, 1, 2, 1, 'Tugas Terbimbing',
 'Tuliskan kembali lafadz QS Ali Imran: 190 beserta hukum tajwid pada kata yang digarisbawahi, lalu simpulkan intisarinya!',
 'Sedang', 'C2 - Memahami', 25.00)
ON DUPLICATE KEY UPDATE `pertanyaan` = VALUES(`pertanyaan`);

INSERT INTO `pengayaan_remedial_soal_rubrik` (
  `id`, `id_soal`, `id_itp`, `aspek_kompetensi`, `pedoman_penskoran`, `skor_maksimal`, 
  `kriteria_skor_maksimal`, `kriteria_skor_sedang`, `kriteria_skor_minimal`, `kriteria_skor_nol`
) VALUES
(1, 1, 2, 'Penguasaan Remedial Kaidah Tajwid & Intisari Ayat',
 'Penilaian rubrik tindak lanjut: Ketepatan kaidah tajwid (15 poin), keakuratan intisari ayat (10 poin).', 25.00,
 'Mampu menuliskan kaidah tajwid dengan benar dan menyimpulkan intisari secara tepat (Skor 20-25).',
 'Menjelaskan tajwid benar namun kesimpulan belum lengkap (Skor 10-19).',
 'Hanya menuliskan ulang ayat tanpa penjelasan hukum (Skor 5-9).',
 'Tidak mengerjakan sama sekali (Skor 0).')
ON DUPLICATE KEY UPDATE `pedoman_penskoran` = VALUES(`pedoman_penskoran`);

INSERT INTO `nilai_pengayaan_remedial` (
  `id`, `id_siswa`, `id_kelas_mata_pelajaran`, `id_pengayaan_remedial`, `id_guru`, 
  `id_modul_ajar`, `id_tp`, `id_itp`, `pertemuan_ke`, `nilai_awal`, `nilai_akhir`, 
  `nilai_kktp`, `predikat`, `status_tuntas`, `catatan_evaluasi`, `waktu_penilaian`
) VALUES
(1, 3, 7, 1, 2, 1, 1, 2, 2, 68.00, 78.00, 75.00, 'C', 'Tuntas Remedial', 'Tuntas mencapai standar KKTP 75 setelah mengikuti bimbingan perorangan telaah dalil naqli.', '2025-07-29 11:00:00')
ON DUPLICATE KEY UPDATE `nilai_akhir` = VALUES(`nilai_akhir`);

-- 6. Master Rekapitulasi Nilai Rapor Semester
INSERT INTO `rekap_nilai_siswa_mapel` (
  `id`, `id_siswa`, `id_kelas_mata_pelajaran`, `semester`, `tahun_pelajaran`, 
  `rata_diagnostik`, `rata_formatif`, `rata_lkpd`, `rata_sumatif_materi`, 
  `nilai_sts`, `nilai_asas`, `rata_tugas`, `nilai_proyek`, `nilai_akhir_semester`, 
  `predikat_akhir`, `status_kelulusan_kktp`, `deskripsi_capaian_tertinggi`, `deskripsi_capaian_perlu_bimbingan`
) VALUES
-- Rekap Siswa 1: Farhan (Nilai Akhir 93.20 - Predikat A)
(1, 1, 7, 'Ganjil', '2026/2027', 
 85.00, 92.00, 90.00, 95.00, 90.00, 94.00, 94.50, 92.00, 93.20, 
 'A', 'Tercapai', 
 'Menunjukkan penguasaan sangat istimewa dalam menganalisis dalil berpikir kritis dan karakteristik ulil albab.', 
 'Perlu terus mempertahankan konsistensi kolaborasi kepemimpinan dalam kegiatan proyek.')
ON DUPLICATE KEY UPDATE 
  `nilai_akhir_semester` = VALUES(`nilai_akhir_semester`),
  `predikat_akhir` = VALUES(`predikat_akhir`);

-- ------------------------------------------------------------------------------
-- SEEDER PROYEK, KELOMPOK, PENGUMPULAN & BIAYA PENGELUARAN PROYEK
-- ------------------------------------------------------------------------------
INSERT INTO `proyek` (
  `id`, `id_kelas_mata_pelajaran`, `id_guru`, `id_modul_ajar`, `id_tp`, `id_itp`, 
  `nama_proyek`, `tema_proyek`, `deskripsi_tujuan`, `jenis_proyek`, 
  `dimensi_profil_pancasila`, `target_luaran`, `jadwal_mulai`, `jadwal_selesai`, 
  `pedoman_penilaian_rubrik`, `nilai_maksimal`, `status_proyek`
) VALUES
(1, 7, 2, 1, 1, 2, 
 'Proyek Film Pendek Inspiratif: Menjadi Generasi Ulil Albab di Era Kecerdasan Buatan', 
 'Rekayasa & Teknologi Berkarakter Islam', 
 'Peserta didik merancang, memproduksi, dan mempublikasikan karya film pendek berdurasi 5-7 menit yang mengisahkan perjuangan remaja muslim dalam menyaring informasi palsu dan memanfaatkan teknologi AI secara beretika.', 
 'Kelompok', 
 'Beriman & Bertakwa, Gotong Royong, Bernalar Kritis, Kreatif', 
 'Video Film Pendek (MP4 HD di Youtube) & Laporan Karya Cetak Booklet', 
 '2025-08-01', '2025-08-25', 
 'Rubrik Holistik: Kesesuaian Pesan Syariat (25%), Kualitas Sinematografi (25%), Naskah & Akting (25%), Manfaat Edukatif Publik (25%).', 
 100.00, 'Sedang Berjalan')
ON DUPLICATE KEY UPDATE `nama_proyek` = VALUES(`nama_proyek`);

INSERT INTO `proyek_kelompok` (
  `id`, `id_proyek`, `id_kelas_mata_pelajaran`, `nomor_kelompok`, `nama_kelompok`, `id_ketua_kelompok`
) VALUES
(1, 1, 7, 1, 'Tim Sinematografi Ulil Albab (Kelompok 1)', 1),
(2, 1, 7, 2, 'Tim Kreatif Generasi Emas (Kelompok 2)', 3)
ON DUPLICATE KEY UPDATE `nama_kelompok` = VALUES(`nama_kelompok`);

INSERT INTO `proyek_kelompok_anggota` (
  `id`, `id_proyek_kelompok`, `id_siswa`, `id_kelas_mata_pelajaran`, `peran_anggota`
) VALUES
-- Kelompok 1: Farhan (Sutradara/Editor) & Aisyah (Penulis Naskah/Bendahara)
(1, 1, 1, 7, 'Sutradara & Editor Video'),
(2, 1, 2, 7, 'Penulis Naskah & Bendahara Proyek'),
-- Kelompok 2: Fauzan (Kameramen) & Zahra (Presenter/Talent)
(3, 2, 3, 7, 'Kameramen & Penata Artistik'),
(4, 2, 4, 7, 'Presenter & Talent Utama')
ON DUPLICATE KEY UPDATE `peran_anggota` = VALUES(`peran_anggota`);

INSERT INTO `pengumpulan_proyek` (
  `id`, `id_proyek`, `id_siswa`, `id_kelas_mata_pelajaran`, `id_proyek_kelompok`, 
  `judul_laporan_produk`, `deskripsi_produk`, `file_laporan`, `file_dokumentasi`, 
  `link_produk`, `link_video_presentasi`, `waktu_pengumpulan`, `status_pengumpulan`, 
  `nilai_proyek`, `predikat_proyek`, `catatan_evaluasi_guru`, `waktu_penilaian`
) VALUES
(1, 1, 1, 7, 1, 
 'Laporan Karya Film Pendek: Cahaya di Ujung Nalar - Refleksi Generasi Ulil Albab', 
 'Film pendek edukatif mengenai santri yang membuktikan kebenaran suatu berita viral melalui riset tabayyun mendalam sebelum menyebarkannya.', 
 'uploads/proyek/laporan_film_cahaya_nalar_kelompok1.pdf', 
 'uploads/proyek/dokumentasi_shooting_kelompok1.zip', 
 'https://youtu.be/example_film_ulil_albab', 
 'https://youtu.be/example_presentasi_pameran', 
 '2025-08-24 16:30:00', 'Disetujui', 
 95.00, 'Sangat Berkembang (SB)', 
 'Karya sangat menginspirasi, alur cerita menyentuh hati, pesan ayat QS Ali Imran 190-191 tersampaikan dengan sangat indah dan alami. Selamat!', 
 '2025-08-25 10:00:00')
ON DUPLICATE KEY UPDATE 
  `status_pengumpulan` = VALUES(`status_pengumpulan`),
  `nilai_proyek` = VALUES(`nilai_proyek`);

-- ------------------------------------------------------------------------------
-- SEEDER BIAYA_PROYEK (RINCIAN NOTA PENGELUARAN SELAMA MENGERJAKAN PROYEK)
-- ------------------------------------------------------------------------------
INSERT INTO `biaya_proyek` (
  `id`, `id_proyek`, `id_pengumpulan_proyek`, `id_proyek_kelompok`, `id_siswa`, 
  `id_kelas_mata_pelajaran`, `tanggal_pengeluaran`, `kategori_biaya`, `nama_item_biaya`, 
  `kuantitas`, `satuan`, `harga_satuan`, `total_biaya`, `bukti_transaksi`, `sumber_dana`, 
  `status_verifikasi`, `catatan`
) VALUES
-- Pengeluaran 1: Pembelian Properti Shooting & Make-up Karakter
(1, 1, 1, 1, 2, 7, '2025-08-05', 
 'Bahan Baku & Alat', 'Papan Clapper Board, Kertas Naskah & Properti Kostum Santri', 
 1.00, 'paket', 75000.00, 75000.00, 
 'uploads/nota/nota_properti_shooting_20250805.jpg', 'Iuran Mandiri Siswa', 'Diverifikasi Guru', 
 'Pembelian properti di Toko Buku & Alat Tulis Berkah.'),

-- Pengeluaran 2: Persewaan Mic Wireless & Lampu Softbox
(2, 1, 1, 1, 2, 7, '2025-08-10', 
 'Persewaan Perangkat', 'Sewa Wireless Clip-on Mic & Ring Light LED (2 Hari Shooting)', 
 2.00, 'hari', 60000.00, 120000.00, 
 'uploads/nota/kwitansi_sewa_audio_20250810.pdf', 'Iuran Mandiri Siswa', 'Diverifikasi Guru', 
 'Sewa perangkat audio studio di Rental Multimedia Bojonegoro.'),

-- Pengeluaran 3: Percetakan Booklet Laporan & Jilid Hardcover
(3, 1, 1, 1, 2, 7, '2025-08-23', 
 'Percetakan & Jilid', 'Cetak Full Color Booklet Laporan Akhir & Burning DVD Master Video', 
 3.00, 'eksemplar', 25000.00, 75000.00, 
 'uploads/nota/nota_percetakan_laporan_20250823.jpg', 'Kas Kelas', 'Diverifikasi Guru', 
 'Pencetakan laporan resmi untuk arsip guru dan perpustakaan sekolah.')
ON DUPLICATE KEY UPDATE 
  `total_biaya` = VALUES(`total_biaya`),
  `status_verifikasi` = VALUES(`status_verifikasi`);

-- ==============================================================================
-- KLASTER 16: EXAM BROWSER CBT ENGINE, JAWABAN SISWA & KEAMANAN KIOSK
-- ==============================================================================
-- Mendukung Ujian Diagnostik, Formatif, dan Sumatif dengan fitur:
-- 1. Anti Screenshot (FLAG_SECURE) & Anti Copy-Paste
-- 2. Freeze Sesi Ujian otomatis saat keluar aplikasi (Home, Recent Apps, Split Screen)
-- 3. Auto-Logout di sisi aplikasi namun jawaban dan soal tetap tersimpan utuh di database
-- 4. Buka Kunci Manual oleh Admin/Proktor di dashboard
-- ==============================================================================

-- ------------------------------------------------------------------------------
-- 70. STRUKTUR TABEL CBT_SESI_UJIAN (STATUS SESI, SISA WAKTU, FREEZE & KUNCI PROKTOR)
-- ------------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `cbt_sesi_ujian` (
  `id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `tipe_asesmen` ENUM('diagnostik', 'formatif', 'sumatif', 'remedial') NOT NULL DEFAULT 'sumatif' COMMENT 'Jenis asesmen yang dikerjakan',
  `id_asesmen` INT(11) UNSIGNED NOT NULL COMMENT 'Relasi dinamis ke asesmen_diagnostik, asesmen_formatif, atau asesmen_sumatif',
  `id_siswa` INT(11) UNSIGNED NOT NULL COMMENT 'Relasi ke siswa(id)',
  `id_kelas_mata_pelajaran` INT(11) UNSIGNED NOT NULL COMMENT 'Relasi ke kelas_mata_pelajaran(id)',
  `nomor_peserta` VARCHAR(50) DEFAULT NULL COMMENT 'Nomor peserta ujian jika menggunakan tiket khusus',
  `token_sesi` VARCHAR(50) DEFAULT NULL COMMENT 'Token ujian dinamis yang dimasukkan siswa',
  `status_sesi` ENUM('Belum Mulai', 'Sedang Mengerjakan', 'Terkunci / Freeze', 'Selesai', 'Izin Keluar') NOT NULL DEFAULT 'Sedang Mengerjakan' COMMENT 'Status pengerjaan saat ini',
  `sisa_waktu_detik` INT(11) NOT NULL DEFAULT 0 COMMENT 'Sisa durasi pengerjaan yang dibekukan',
  `waktu_mulai` DATETIME DEFAULT CURRENT_TIMESTAMP COMMENT 'Waktu siswa pertama kali memulai pengerjaan',
  `waktu_selesai` DATETIME DEFAULT NULL COMMENT 'Waktu siswa menyelesaikan ujian secara resmi',
  `is_frozen` TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1 = Sesi dibekukan karena keluar aplikasi/pelanggaran',
  `waktu_freeze` DATETIME DEFAULT NULL COMMENT 'Waktu terjadinya pembekuan sesi',
  `alasan_freeze` VARCHAR(255) DEFAULT NULL COMMENT 'Penyebab freeze: Keluar Aplikasi / Home, Recent Apps, Split Screen, dll',
  `total_pelanggaran` INT(5) NOT NULL DEFAULT 0 COMMENT 'Jumlah akumulasi pelanggaran siswa selama ujian',
  `is_locked_admin` TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1 = Memerlukan buka kunci manual oleh admin/proktor agar bisa login lagi',
  `dibuka_oleh` INT(11) DEFAULT NULL COMMENT 'Relasi ke users(id) admin/guru yang membuka kunci',
  `waktu_buka_kunci` DATETIME DEFAULT NULL COMMENT 'Waktu pembukaan kunci oleh admin',
  `catatan_proktor` TEXT DEFAULT NULL COMMENT 'Catatan admin saat verifikasi atau pembukaan kunci',
  `susunan_soal_json` LONGTEXT DEFAULT NULL COMMENT 'Daftar ID butir soal teracak per siswa agar susunan soal tetap sama saat dilanjutkan',
  `device_model` VARCHAR(150) DEFAULT NULL COMMENT 'Merk dan tipe perangkat siswa',
  `ip_address` VARCHAR(45) DEFAULT NULL COMMENT 'IP Address siswa saat mengerjakan',
  `user_agent` VARCHAR(255) DEFAULT NULL COMMENT 'User Agent khusus Exam Browser',
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_cbt_sesi_siswa` (`tipe_asesmen`, `id_asesmen`, `id_siswa`),
  KEY `idx_cs_siswa` (`id_siswa`),
  KEY `idx_cs_kmp` (`id_kelas_mata_pelajaran`),
  KEY `idx_cs_status` (`status_sesi`),
  KEY `idx_cs_frozen` (`is_frozen`),
  KEY `idx_cs_locked` (`is_locked_admin`),
  KEY `idx_cs_admin` (`dibuka_oleh`),
  CONSTRAINT `fk_cs_siswa` FOREIGN KEY (`id_siswa`) REFERENCES `siswa` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_cs_kmp` FOREIGN KEY (`id_kelas_mata_pelajaran`) REFERENCES `kelas_mata_pelajaran` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_cs_admin` FOREIGN KEY (`dibuka_oleh`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------------------
-- 71. STRUKTUR TABEL CBT_JAWABAN_SISWA (PENYIMPANAN JAWABAN POLIMORFIK PER BUTIR SOAL PER SISWA)
-- ------------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `cbt_jawaban_siswa` (
  `id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `id_sesi_ujian` INT(11) UNSIGNED DEFAULT NULL COMMENT 'Relasi ke cbt_sesi_ujian(id)',
  `tipe_asesmen` ENUM('diagnostik', 'formatif', 'sumatif', 'remedial') NOT NULL DEFAULT 'sumatif',
  `id_asesmen` INT(11) UNSIGNED NOT NULL COMMENT 'Relasi ke id asesmen terkait',
  `id_soal` INT(11) UNSIGNED NOT NULL COMMENT 'Relasi ke id butir soal terkait',
  `id_siswa` INT(11) UNSIGNED NOT NULL COMMENT 'Relasi ke siswa(id)',
  `nomor_urut_tampil` INT(4) NOT NULL DEFAULT 1 COMMENT 'Nomor urut soal yang tampil di layar siswa',
  `tipe_soal` ENUM('Pilihan Ganda', 'Pilihan Ganda Kompleks', 'Essay / Uraian', 'Benar Salah', 'Menjodohkan') NOT NULL DEFAULT 'Pilihan Ganda',
  `id_opsi_dipilih` INT(11) UNSIGNED DEFAULT NULL COMMENT 'ID opsi yang dipilih untuk PG tunggal',
  `kode_opsi_dipilih` VARCHAR(10) DEFAULT NULL COMMENT 'Huruf opsi yang dipilih (A, B, C, D, E)',
  `jawaban_teks` LONGTEXT DEFAULT NULL COMMENT 'Jawaban naratif teks untuk soal Essay / Uraian',
  `jawaban_kompleks` JSON DEFAULT NULL COMMENT 'Struktur JSON pilihan siswa untuk PG Kompleks, Menjodohkan, atau Benar Salah',
  `is_ragu` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Flag penanda ragu-ragu di navigasi soal (1 = Ragu-ragu, 0 = Yakin)',
  `is_benar` TINYINT(1) DEFAULT NULL COMMENT 'Status koreksi otomatis: 1 = Benar, 0 = Salah, NULL = Belum Dinilai/Essay',
  `skor_bobot_maks` DECIMAL(5,2) NOT NULL DEFAULT 10.00 COMMENT 'Bobot maksimal butir soal ini',
  `skor_diperoleh` DECIMAL(5,2) DEFAULT NULL COMMENT 'Nilai skor yang diperoleh siswa pada butir soal ini',
  `catatan_koreksi_guru` TEXT DEFAULT NULL COMMENT 'Catatan koreksi dari guru (khusus essay)',
  `waktu_simpan` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT 'Waktu autosave jawaban terakhir',
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_cbt_jawaban_item` (`tipe_asesmen`, `id_asesmen`, `id_soal`, `id_siswa`),
  KEY `idx_cj_sesi` (`id_sesi_ujian`),
  KEY `idx_cj_siswa` (`id_siswa`),
  KEY `idx_cj_soal` (`id_soal`),
  KEY `idx_cj_ragu` (`is_ragu`),
  KEY `idx_cj_benar` (`is_benar`),
  CONSTRAINT `fk_cj_sesi` FOREIGN KEY (`id_sesi_ujian`) REFERENCES `cbt_sesi_ujian` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_cj_siswa` FOREIGN KEY (`id_siswa`) REFERENCES `siswa` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------------------
-- 72. STRUKTUR TABEL CBT_LOG_PELANGGARAN_EXAMBRO (AUDIT TRAIL INSIDEN KEAMANAN & KIOSK)
-- ------------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `cbt_log_pelanggaran_exambro` (
  `id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `id_sesi_ujian` INT(11) UNSIGNED DEFAULT NULL COMMENT 'Relasi ke cbt_sesi_ujian(id)',
  `tipe_asesmen` ENUM('diagnostik', 'formatif', 'sumatif', 'remedial') NOT NULL DEFAULT 'sumatif',
  `id_asesmen` INT(11) UNSIGNED NOT NULL,
  `id_siswa` INT(11) UNSIGNED NOT NULL COMMENT 'Relasi ke siswa(id)',
  `jenis_pelanggaran` ENUM(
    'Keluar Aplikasi / Home',
    'Recent Apps / App Switcher',
    'Split Screen / Layar Ganda',
    'Floating Window / Aplikasi Mengambang',
    'Tangkapan Layar / Screenshot',
    'Perekaman Layar / Screen Recorder',
    'Salin / Tempel Teks (Clipboard)',
    'Kehilangan Fokus Layar',
    'Perangkat Terdeteksi Root',
    'Lainnya'
  ) NOT NULL DEFAULT 'Keluar Aplikasi / Home',
  `keterangan` TEXT DEFAULT NULL COMMENT 'Deskripsi detail teknis kejadian',
  `sisa_waktu_detik` INT(11) DEFAULT NULL COMMENT 'Sisa waktu siswa saat pelanggaran terjadi',
  `pelanggaran_ke` INT(4) NOT NULL DEFAULT 1 COMMENT 'Urutan ke berapa pelanggaran dilakukan siswa pada sesi ini',
  `status_tindakan` ENUM('Peringatan', 'Freeze & Logout Otomatis', 'Terkunci Permanen') NOT NULL DEFAULT 'Freeze & Logout Otomatis',
  `ip_address` VARCHAR(45) DEFAULT NULL,
  `user_agent` VARCHAR(255) DEFAULT NULL,
  `waktu_kejadian` DATETIME DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_clp_sesi` (`id_sesi_ujian`),
  KEY `idx_clp_siswa` (`id_siswa`),
  KEY `idx_clp_jenis` (`jenis_pelanggaran`),
  KEY `idx_clp_waktu` (`waktu_kejadian`),
  CONSTRAINT `fk_clp_sesi` FOREIGN KEY (`id_sesi_ujian`) REFERENCES `cbt_sesi_ujian` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_clp_siswa` FOREIGN KEY (`id_siswa`) REFERENCES `siswa` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------------------
-- 73. STRUKTUR TABEL CBT_PENGATURAN_EXAMBRO (KEBIJAKAN KEAMANAN EXAM BROWSER)
-- ------------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `cbt_pengaturan_exambro` (
  `id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `kode_pengaturan` VARCHAR(50) NOT NULL UNIQUE DEFAULT 'default_kiosk',
  `nama_pengaturan` VARCHAR(150) NOT NULL DEFAULT 'Standar Keamanan Ilmuna Exam Browser',
  `wajib_exambro` TINYINT(1) NOT NULL DEFAULT 1 COMMENT '1 = Siswa wajib mengerjakan via APK Exam Browser resmi, blokir browser biasa',
  `blokir_screenshot` TINYINT(1) NOT NULL DEFAULT 1 COMMENT '1 = Aktifkan FLAG_SECURE anti screenshot/record',
  `blokir_copy_paste` TINYINT(1) NOT NULL DEFAULT 1 COMMENT '1 = Nonaktifkan copy, cut, paste, dan text selection',
  `auto_freeze_on_leave` TINYINT(1) NOT NULL DEFAULT 1 COMMENT '1 = Otomatis freeze sisa waktu saat keluar aplikasi',
  `auto_logout_on_leave` TINYINT(1) NOT NULL DEFAULT 1 COMMENT '1 = Otomatis logout sesi aplikasi saat keluar aplikasi',
  `butuh_buka_manual_admin` TINYINT(1) NOT NULL DEFAULT 1 COMMENT '1 = Siswa yang ter-freeze tidak bisa login sendiri, harus dibuka manual oleh admin di dashboard',
  `maks_toleransi_pelanggaran` INT(3) NOT NULL DEFAULT 3 COMMENT 'Batas toleransi pelanggaran sebelum diskualifikasi/kunci permanen (0 = langsung kunci)',
  `pin_pengawas_kiosk` VARCHAR(20) NOT NULL DEFAULT '123456' COMMENT 'PIN darurat pengawas untuk keluar mode kiosk',
  `keterangan` TEXT DEFAULT NULL,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `cbt_pengaturan_exambro` (
  `kode_pengaturan`, `nama_pengaturan`, `wajib_exambro`, `blokir_screenshot`, `blokir_copy_paste`, 
  `auto_freeze_on_leave`, `auto_logout_on_leave`, `butuh_buka_manual_admin`, `maks_toleransi_pelanggaran`, 
  `pin_pengawas_kiosk`, `keterangan`
) VALUES (
  'default_kiosk', 'Standar Keamanan Ilmuna Exam Browser (High Security Kiosk)', 1, 1, 1, 1, 1, 1, 3, '123456', 
  'Konfigurasi standar Kiosk: anti-ss, anti-copy-paste, freeze saat keluar, auto logout, dan verifikasi buka kunci proktor.'
) ON DUPLICATE KEY UPDATE `updated_at` = CURRENT_TIMESTAMP;










