package id.ilmuna.exambrowser

import android.os.Handler
import android.os.Looper
import org.json.JSONArray
import org.json.JSONObject
import java.io.BufferedReader
import java.io.InputStreamReader
import java.io.OutputStreamWriter
import java.net.HttpURLConnection
import java.net.URL
import java.net.URLEncoder
import kotlin.concurrent.thread

object CbtApiClient {

    private val mainHandler = Handler(Looper.getMainLooper())

    fun getApiEndpoint(baseUrl: String): String {
        var trimmed = baseUrl.trim().removeSuffix("/")
        if (trimmed.endsWith(".php", ignoreCase = true)) {
            trimmed = trimmed.substring(0, trimmed.length - 4)
        }

        return when {
            trimmed.endsWith("cbt-exam", ignoreCase = true) -> {
                "$trimmed.php"
            }
            trimmed.endsWith("/exam", ignoreCase = true) || trimmed.endsWith("/login", ignoreCase = true) -> {
                val root = trimmed.substringBeforeLast("/")
                "$root/api/cbt-exam.php"
            }
            trimmed.equals("exam", ignoreCase = true) || trimmed.equals("login", ignoreCase = true) -> {
                "api/cbt-exam.php"
            }
            else -> {
                "$trimmed/api/cbt-exam.php"
            }
        }
    }

    fun getFreezeEndpoint(baseUrl: String): String {
        val examUrl = getApiEndpoint(baseUrl)
        return examUrl.replace("cbt-exam.php", "cbt-freeze.php")
    }

    private fun postRequest(urlStr: String, params: Map<String, String>): JSONObject {
        val url = URL(urlStr)
        val conn = url.openConnection() as HttpURLConnection
        conn.requestMethod = "POST"
        conn.connectTimeout = 8000
        conn.readTimeout = 10000
        conn.doOutput = true
        conn.doInput = true
        conn.setRequestProperty("Content-Type", "application/x-www-form-urlencoded")
        conn.setRequestProperty("User-Agent", "IlmunaExamBrowser/1.0 (Android Native; SecureKiosk)")

        val postData = StringBuilder()
        var first = true
        for ((k, v) in params) {
            if (!first) postData.append("&")
            postData.append(URLEncoder.encode(k, "UTF-8"))
            postData.append("=")
            postData.append(URLEncoder.encode(v, "UTF-8"))
            first = false
        }

        val writer = OutputStreamWriter(conn.outputStream)
        writer.write(postData.toString())
        writer.flush()
        writer.close()

        val responseCode = conn.responseCode
        val stream = if (responseCode in 200..299) conn.inputStream else conn.errorStream
        val reader = BufferedReader(InputStreamReader(stream))
        val response = StringBuilder()
        var line: String?
        while (reader.readLine().also { line = it } != null) {
            response.append(line)
        }
        reader.close()
        conn.disconnect()

        val raw = response.toString().trim()
        val jsonStart = raw.indexOf('{')
        val jsonEnd = raw.lastIndexOf('}')
        if (jsonStart != -1 && jsonEnd != -1 && jsonEnd >= jsonStart) {
            val cleanJson = raw.substring(jsonStart, jsonEnd + 1)
            try {
                return JSONObject(cleanJson)
            } catch (_: Exception) {
                // Bukan JSON yang valid (misal stack trace PHP)
            }
        }
        if (responseCode !in 200..299) {
            throw Exception("HTTP Error $responseCode dari server")
        }
        throw Exception("Server mengalami kendala internal: ${if (raw.length > 80) raw.substring(0, 80) + "..." else raw}")
    }

    fun login(
        baseUrl: String,
        identifier: String,
        password: String,
        token: String,
        onSuccess: (StudentSession) -> Unit,
        onError: (message: String, isFrozen: Boolean, isCompleted: Boolean, isAttendanceRequired: Boolean) -> Unit
    ) {
        val targetUrl = getApiEndpoint(baseUrl)
        thread {
            try {
                val params = mapOf(
                    "action" to "login",
                    "identifier" to identifier,
                    "password" to password,
                    "token" to token
                )
                val json = postRequest(targetUrl, params)
                val success = json.optBoolean("success", false)

                if (success) {
                    val sessObj = json.optJSONObject("session") ?: json
                    val session = StudentSession(
                        idCbtSesi = sessObj.optInt("id_cbt_sesi", sessObj.optInt("id_sesi", sessObj.optInt("id", 0))),
                        idAkses = sessObj.optInt("id_akses", sessObj.optInt("akses_id", 0)),
                        tipeAsesmen = sessObj.optString("tipe_asesmen", sessObj.optString("tipe", "CBT")),
                        idAsesmen = sessObj.optInt("id_asesmen", sessObj.optInt("asesmen_id", 0)),
                        idSiswa = sessObj.optInt("id_siswa", sessObj.optInt("siswa_id", 0)),
                        idKelasMataPelajaran = sessObj.optInt("id_kelas_mata_pelajaran", sessObj.optInt("id_kelas_mapel", 0)),
                        nomorPeserta = sessObj.optString("nomor_peserta", identifier),
                        usernameUjian = sessObj.optString("username_ujian", identifier),
                        namaSiswa = sessObj.optString("nama_siswa", sessObj.optString("nama", "Siswa")),
                        nis = if (sessObj.isNull("nis")) null else sessObj.optString("nis"),
                        nisn = if (sessObj.isNull("nisn")) null else sessObj.optString("nisn"),
                        foto = if (sessObj.isNull("foto")) null else sessObj.optString("foto"),
                        namaKelas = sessObj.optString("nama_kelas", sessObj.optString("kelas", "-")),
                        namaPelajaran = sessObj.optString("nama_pelajaran", sessObj.optString("pelajaran", "-")),
                        judulAsesmen = sessObj.optString("judul_asesmen", sessObj.optString("judul", "Asesmen CBT")),
                        subtipe = if (sessObj.isNull("subtipe")) null else sessObj.optString("subtipe"),
                        durasiMenit = sessObj.optInt("durasi_menit", sessObj.optInt("durasi", 60)),
                        kktp = sessObj.optDouble("kktp", 75.0),
                        sisaWaktuDetik = sessObj.optInt("sisa_waktu_detik", sessObj.optInt("sisa_waktu", 3600)),
                        petunjukPengerjaan = if (sessObj.isNull("petunjuk_pengerjaan")) null else sessObj.optString("petunjuk_pengerjaan")
                    )
                    mainHandler.post { onSuccess(session) }
                } else {
                    val msg = json.optString("message", json.optString("error", "Login gagal diperiksa."))
                    val isFrozen = json.optBoolean("is_frozen", false)
                    val isCompleted = json.optBoolean("is_completed", false)
                    val isAttendanceRequired = json.optBoolean("is_attendance_required", false)
                    mainHandler.post { onError(msg, isFrozen, isCompleted, isAttendanceRequired) }
                }
            } catch (e: Exception) {
                val isNetworkError = e is java.net.ConnectException || 
                                    e is java.net.SocketTimeoutException || 
                                    e is java.net.UnknownHostException

                val errorDetails = if (isNetworkError) {
                    "Tidak dapat terhubung ke $targetUrl.\n\n" +
                    "• Pastikan HP dan Laptop Server berada di Wi-Fi/jaringan yang sama.\n" +
                    "• Cek IP laptop server (ketik 'ipconfig' di CMD) lalu perbarui alamat IP via tombol 'Pengaturan Server'."
                } else {
                    "Gagal masuk ($targetUrl):\n${e.localizedMessage ?: "Respon tidak valid"}"
                }
                mainHandler.post { onError(errorDetails, false, false, false) }
            }
        }
    }

    fun getQuestions(
        baseUrl: String,
        session: StudentSession,
        onSuccess: (List<QuestionItem>) -> Unit,
        onError: (String) -> Unit
    ) {
        val targetUrl = getApiEndpoint(baseUrl)
        thread {
            try {
                val params = mapOf(
                    "action" to "get_questions",
                    "id_cbt_sesi" to session.idCbtSesi.toString(),
                    "tipe_asesmen" to session.tipeAsesmen,
                    "id_asesmen" to session.idAsesmen.toString(),
                    "id_siswa" to session.idSiswa.toString()
                )
                val json = postRequest(targetUrl, params)
                if (json.optBoolean("success", false)) {
                    val soalArray = json.optJSONArray("soal") ?: json.optJSONArray("questions") ?: JSONArray()
                    val list = mutableListOf<QuestionItem>()

                    for (i in 0 until soalArray.length()) {
                        val sObj = soalArray.getJSONObject(i)
                        val sId = sObj.optInt("id", i + 1)
                        val opsiArr = sObj.optJSONArray("opsi") ?: sObj.optJSONArray("options") ?: JSONArray()
                        val opsiList = mutableListOf<OptionItem>()

                        for (j in 0 until opsiArr.length()) {
                            val oObj = opsiArr.getJSONObject(j)
                            opsiList.add(
                                OptionItem(
                                    id = oObj.optInt("id", j + 1),
                                    idSoal = oObj.optInt("id_soal", sId),
                                    kodeOpsi = oObj.optString("kode_opsi", oObj.optString("kode", "${('A' + j)}")),
                                    teksOpsi = oObj.optString("teks_opsi", oObj.optString("teks", "")),
                                    gambarOpsi = if (oObj.isNull("gambar_opsi")) null else oObj.optString("gambar_opsi")
                                )
                            )
                        }

                        val jwbObj = sObj.optJSONObject("jawaban_tersimpan")
                        var saved: SavedAnswer? = null
                        if (jwbObj != null) {
                            val optId = if (!jwbObj.isNull("id_opsi_dipilih")) jwbObj.optInt("id_opsi_dipilih") else null
                            val optCode = if (!jwbObj.isNull("kode_opsi_dipilih")) jwbObj.optString("kode_opsi_dipilih") else null
                            val teks = if (!jwbObj.isNull("jawaban_teks")) jwbObj.optString("jawaban_teks") else null
                            val ragu = jwbObj.optInt("is_ragu", 0)
                            saved = SavedAnswer(optId, optCode, teks, ragu)
                        }

                        val imgStimulus = if (sObj.isNull("gambar_stimulus")) null else sObj.optString("gambar_stimulus")
                        var imgStimulusUrl = if (sObj.isNull("gambar_stimulus_url")) null else sObj.optString("gambar_stimulus_url")
                        if (imgStimulusUrl.isNullOrBlank() && !imgStimulus.isNullOrBlank()) {
                            imgStimulusUrl = if (imgStimulus.startsWith("http://", ignoreCase = true) || imgStimulus.startsWith("https://", ignoreCase = true)) {
                                imgStimulus
                            } else {
                                "${baseUrl.trimEnd('/')}/${imgStimulus.trimStart('/')}"
                            }
                        }
                        val linkVideo = if (sObj.isNull("link_video")) null else sObj.optString("link_video")

                        list.add(
                            QuestionItem(
                                id = sId,
                                nomorUrut = sObj.optInt("nomor_urut", i + 1),
                                nomorSoal = sObj.optInt("nomor_soal", i + 1),
                                tipeSoal = sObj.optString("tipe_soal", "Pilihan Ganda"),
                                pertanyaan = sObj.optString("pertanyaan", sObj.optString("soal", "")),
                                gambarStimulus = imgStimulus,
                                gambarStimulusUrl = imgStimulusUrl,
                                linkVideo = linkVideo,
                                bobotSkor = sObj.optDouble("bobot_skor", 10.0),
                                opsi = opsiList,
                                jawabanTersimpan = saved
                            )
                        )
                    }

                    mainHandler.post { onSuccess(list) }
                } else {
                    val msg = json.optString("message", json.optString("error", "Gagal mengambil soal dari server."))
                    mainHandler.post { onError(msg) }
                }
            } catch (e: Exception) {
                val isNetworkError = e is java.net.ConnectException || 
                                    e is java.net.SocketTimeoutException || 
                                    e is java.net.UnknownHostException

                val errorDetails = if (isNetworkError) {
                    "Tidak dapat terhubung ke $targetUrl.\n\n" +
                    "• Pastikan HP dan Laptop Server berada di Wi-Fi/jaringan yang sama.\n" +
                    "• Cek IP laptop server (ketik 'ipconfig' di CMD) lalu perbarui alamat IP via tombol 'Pengaturan Server'."
                } else {
                    "Gagal memuat butir soal ($targetUrl):\n${e.localizedMessage ?: "Respon tidak valid"}"
                }
                mainHandler.post { onError(errorDetails) }
            }
        }
    }

    fun saveAnswer(
        baseUrl: String,
        session: StudentSession,
        soalId: Int,
        nomorUrut: Int,
        tipeSoal: String,
        optionId: Int?,
        optionCode: String?,
        jawabanTeks: String?,
        isRagu: Int,
        sisaDetik: Int,
        onDone: ((Boolean) -> Unit)? = null
    ) {
        val targetUrl = getApiEndpoint(baseUrl)
        thread {
            try {
                val params = mutableMapOf(
                    "action" to "save_answer",
                    "id_cbt_sesi" to session.idCbtSesi.toString(),
                    "tipe_asesmen" to session.tipeAsesmen,
                    "id_asesmen" to session.idAsesmen.toString(),
                    "id_siswa" to session.idSiswa.toString(),
                    "id_soal" to soalId.toString(),
                    "nomor_urut" to nomorUrut.toString(),
                    "tipe_soal" to tipeSoal,
                    "is_ragu" to isRagu.toString(),
                    "sisa_waktu_detik" to sisaDetik.toString()
                )
                if (optionId != null) params["id_opsi"] = optionId.toString()
                if (optionCode != null) params["kode_opsi"] = optionCode
                if (jawabanTeks != null) params["jawaban_teks"] = jawabanTeks

                val res = postRequest(targetUrl, params)
                val ok = res.optBoolean("success", false)
                if (onDone != null) mainHandler.post { onDone(ok) }
            } catch (_: Exception) {
                if (onDone != null) mainHandler.post { onDone(false) }
            }
        }
    }

    fun syncTimer(
        baseUrl: String,
        idCbtSesi: Int,
        remainingSeconds: Int,
        onUpdate: ((serverSisaWaktu: Int, isFrozen: Boolean, statusSesi: String?) -> Unit)? = null
    ) {
        val targetUrl = getApiEndpoint(baseUrl)
        thread {
            try {
                val params = mapOf(
                    "action" to "sync_timer",
                    "id_cbt_sesi" to idCbtSesi.toString(),
                    "sisa_waktu_detik" to remainingSeconds.toString()
                )
                val json = postRequest(targetUrl, params)
                if (json.optBoolean("success", false)) {
                    val serverSisa = json.optInt("sisa_waktu_detik", json.optInt("sisa_waktu", remainingSeconds))
                    val isFrozen = json.optBoolean("is_frozen", false)
                    val statusSesi = if (json.has("status_sesi")) json.optString("status_sesi") else null
                    if (onUpdate != null) {
                        mainHandler.post {
                            onUpdate(serverSisa, isFrozen, statusSesi)
                        }
                    }
                }
            } catch (_: Exception) {}
        }
    }

    fun finishExam(
        baseUrl: String,
        session: StudentSession,
        questions: List<QuestionItem> = emptyList(),
        onSuccess: () -> Unit,
        onError: (String) -> Unit
    ) {
        val targetUrl = getApiEndpoint(baseUrl)
        thread {
            try {
                val answersArr = JSONArray()
                for (q in questions) {
                    val jwb = q.jawabanTersimpan ?: continue
                    val hasOption = jwb.idOpsiDipilih != null || !jwb.kodeOpsiDipilih.isNullOrBlank()
                    val hasText = !jwb.jawabanTeks.isNullOrBlank()
                    if (!hasOption && !hasText) continue

                    val obj = JSONObject()
                    obj.put("id_soal", q.id)
                    obj.put("nomor_urut", q.nomorUrut)
                    obj.put("tipe_soal", q.tipeSoal)
                    if (jwb.idOpsiDipilih != null) obj.put("id_opsi", jwb.idOpsiDipilih)
                    if (!jwb.kodeOpsiDipilih.isNullOrBlank()) obj.put("kode_opsi", jwb.kodeOpsiDipilih)
                    if (!jwb.jawabanTeks.isNullOrBlank()) obj.put("jawaban_teks", jwb.jawabanTeks)
                    obj.put("is_ragu", jwb.isRagu)
                    answersArr.put(obj)
                }

                val params = mutableMapOf(
                    "action" to "finish_exam",
                    "id_cbt_sesi" to session.idCbtSesi.toString(),
                    "tipe_asesmen" to session.tipeAsesmen,
                    "id_asesmen" to session.idAsesmen.toString(),
                    "id_siswa" to session.idSiswa.toString(),
                    "id_kelas_mata_pelajaran" to session.idKelasMataPelajaran.toString(),
                    "save_answers_only" to "1"
                )
                if (answersArr.length() > 0) {
                    params["answers_json"] = answersArr.toString()
                }
                val json = postRequest(targetUrl, params)
                if (json.optBoolean("success", false)) {
                    mainHandler.post { onSuccess() }
                } else {
                    val msg = json.optString("message", json.optString("error", "Gagal mengumpulkan ujian."))
                    mainHandler.post { onError(msg) }
                }
            } catch (e: Exception) {
                mainHandler.post { onError("Gagal mengumpulkan: ${e.localizedMessage}") }
            }
        }
    }

    fun freezeSession(
        baseUrl: String,
        session: StudentSession?,
        reason: String = "Keluar Aplikasi / Home",
        deviceModel: String = android.os.Build.MODEL ?: "Android Device"
    ) {
        if (session == null) return
        val targetUrl = getApiEndpoint(baseUrl)
        thread {
            try {
                val params = mapOf(
                    "action" to "freeze",
                    "id_cbt_sesi" to session.idCbtSesi.toString(),
                    "tipe_asesmen" to session.tipeAsesmen,
                    "id_asesmen" to session.idAsesmen.toString(),
                    "id_siswa" to session.idSiswa.toString(),
                    "id_kelas_mata_pelajaran" to session.idKelasMataPelajaran.toString(),
                    "sisa_waktu_detik" to session.sisaWaktuDetik.toString(),
                    "alasan" to reason,
                    "device_model" to deviceModel
                )
                postRequest(targetUrl, params)
            } catch (_: Exception) {}
        }
    }
}
