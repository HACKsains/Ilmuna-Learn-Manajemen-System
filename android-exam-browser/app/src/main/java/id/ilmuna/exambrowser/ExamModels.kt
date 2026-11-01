package id.ilmuna.exambrowser

import java.io.Serializable

data class StudentSession(
    val idCbtSesi: Int,
    val idAkses: Int,
    val tipeAsesmen: String,
    val idAsesmen: Int,
    val idSiswa: Int,
    val idKelasMataPelajaran: Int,
    val nomorPeserta: String,
    val usernameUjian: String,
    val namaSiswa: String,
    val nis: String?,
    val nisn: String?,
    val foto: String?,
    val namaKelas: String,
    val namaPelajaran: String,
    val judulAsesmen: String,
    val subtipe: String?,
    val durasiMenit: Int,
    val kktp: Double,
    var sisaWaktuDetik: Int,
    val petunjukPengerjaan: String? = null
) : Serializable

data class OptionItem(
    val id: Int,
    val idSoal: Int,
    val kodeOpsi: String,
    val teksOpsi: String,
    val gambarOpsi: String?
) : Serializable

data class SavedAnswer(
    var idOpsiDipilih: Int? = null,
    var kodeOpsiDipilih: String? = null,
    var jawabanTeks: String? = null,
    var isRagu: Int = 0
) : Serializable

data class QuestionItem(
    val id: Int,
    val nomorUrut: Int,
    val nomorSoal: Int,
    val tipeSoal: String,
    val pertanyaan: String,
    val gambarStimulus: String?,
    val bobotSkor: Double,
    val opsi: List<OptionItem>,
    val gambarStimulusUrl: String? = null,
    val linkVideo: String? = null,
    var jawabanTersimpan: SavedAnswer? = null
) : Serializable
