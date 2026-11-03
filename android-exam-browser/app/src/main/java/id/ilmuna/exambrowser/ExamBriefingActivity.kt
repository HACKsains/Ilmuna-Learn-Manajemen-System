package id.ilmuna.exambrowser

import android.content.Intent
import android.os.Bundle
import android.view.LayoutInflater
import android.view.animation.AnimationUtils
import android.widget.TextView
import android.widget.Toast
import androidx.appcompat.app.AppCompatActivity
import com.google.android.material.button.MaterialButton
import com.google.android.material.dialog.MaterialAlertDialogBuilder
import id.ilmuna.exambrowser.databinding.ActivityExamBriefingBinding

class ExamBriefingActivity : AppCompatActivity() {

    private lateinit var binding: ActivityExamBriefingBinding
    private lateinit var session: StudentSession
    private var serverUrl: String = ExamLoginActivity.DEFAULT_SERVER_URL

    private var questionsCount: Int? = null
    private var isCheckingQuestions = false

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        binding = ActivityExamBriefingBinding.inflate(layoutInflater)
        setContentView(binding.root)

        val sess = if (android.os.Build.VERSION.SDK_INT >= android.os.Build.VERSION_CODES.TIRAMISU) {
            intent.getSerializableExtra("STUDENT_SESSION", StudentSession::class.java)
        } else {
            @Suppress("DEPRECATION")
            intent.getSerializableExtra("STUDENT_SESSION") as? StudentSession
        }
        if (sess == null) {
            Toast.makeText(this, "Sesi ujian tidak valid.", Toast.LENGTH_SHORT).show()
            finish()
            return
        }
        session = sess
        serverUrl = intent.getStringExtra("SERVER_URL") ?: ExamLoginActivity.DEFAULT_SERVER_URL

        // Proteksi Layar Kiosk
        SecurityHelper.enableScreenshotProtection(this)
        SecurityHelper.enableKioskMode(this)

        // Bind Detail Pribadi Siswa (Kartu 1 - 2 Kolom)
        binding.tvStudentName.text = "Nama: ${session.namaSiswa}"
        val nisText = session.nis ?: session.nisn ?: "-"
        binding.tvStudentNis.text = "NIS / NISN: $nisText"
        binding.tvStudentNumber.text = "Nomor Peserta: ${session.nomorPeserta}"
        binding.tvStudentClass.text = "Kelas: ${session.namaKelas}"

        // Memuat Foto Siswa Asinkron
        loadStudentPhotoAsync(session.foto, binding.imgStudentPhoto)

        // Bind Detail Paket Ujian (Kartu 2)
        binding.tvAssessmentTitle.text = "Nama Asesmen: ${session.judulAsesmen}"
        binding.tvExamDuration.text = "Durasi Pengerjaan: ${session.durasiMenit} Menit"
        binding.tvAssessmentType.text = "Tipe / Acuan Nilai: ${formatAssessmentTypeLabel()} (Memeriksa Soal...)"

        // Animasi UI
        startUIAnimations()

        // Cek ketersediaan butir soal dari server
        checkQuestionAvailability()

        // Tombol Mulai Ujian Sekarang -> Cek ketersediaan soal & izin DND terlebih dahulu
        binding.btnStartExamNow.setOnClickListener {
            if (isCheckingQuestions) {
                Toast.makeText(this, "Sedang memeriksa ketersediaan soal...", Toast.LENGTH_SHORT).show()
                return@setOnClickListener
            }

            if (questionsCount == null || questionsCount == 0) {
                showNoQuestionsDialog()
                return@setOnClickListener
            }

            if (!SecurityHelper.hasDndPermission(this)) {
                showDndPromptDialog {
                    startExamActivity()
                }
            } else {
                startExamActivity()
            }
        }

        // Tombol Kembali ke Halaman Login
        binding.btnBackToLogin.setOnClickListener {
            navigateBackToLogin()
        }
    }

    private fun formatAssessmentTypeLabel(): String {
        val rawTipe = session.tipeAsesmen.trim()
        val rawSub = session.subtipe?.trim()
        return when {
            rawTipe.equals("remedial", ignoreCase = true) -> {
                if (!rawSub.isNullOrBlank()) {
                    "TINDAK LANJUT (${rawSub.uppercase()})"
                } else {
                    "REMEDIAL / PENGAYAAN"
                }
            }
            !rawSub.isNullOrBlank() -> "${rawTipe.uppercase()} (${rawSub.uppercase()})"
            else -> rawTipe.uppercase()
        }
    }

    private fun checkQuestionAvailability() {
        isCheckingQuestions = true
        val typeLabel = formatAssessmentTypeLabel()
        val isPan = session.tipeAsesmen.equals("sumatif", ignoreCase = true) && 
            (session.subtipe?.contains("Tengah Semester", ignoreCase = true) == true || 
             session.subtipe?.contains("Akhir Semester", ignoreCase = true) == true ||
             session.subtipe?.contains("STS", ignoreCase = true) == true ||
             session.subtipe?.contains("SAS", ignoreCase = true) == true ||
             session.subtipe?.contains("ASAS", ignoreCase = true) == true)
        val acuanText = if (isPan) "PAN (Norma Rata-rata Kelas)" else "PAP (KKTP: ${session.kktp})"

        CbtApiClient.getQuestions(
            baseUrl = serverUrl,
            session = session,
            onSuccess = { list ->
                isCheckingQuestions = false
                questionsCount = list.size
                if (list.isNotEmpty()) {
                    binding.tvAssessmentType.text = "Tipe / Acuan Nilai: $typeLabel ($acuanText) • ${list.size} Soal"
                } else {
                    binding.tvAssessmentType.text = "Tipe / Acuan Nilai: $typeLabel ($acuanText • 0 Soal • Belum Aktif)"
                }
            },
            onError = { _ ->
                isCheckingQuestions = false
                questionsCount = 0
                binding.tvAssessmentType.text = "Tipe / Acuan Nilai: $typeLabel ($acuanText • 0 Soal • Belum Aktif)"
            }
        )
    }

    private fun loadStudentPhotoAsync(photoPath: String?, target: android.widget.ImageView) {
        if (photoPath.isNullOrBlank()) {
            target.setImageResource(android.R.drawable.ic_menu_myplaces)
            return
        }

        val fullPhotoUrl = if (photoPath.startsWith("http://", ignoreCase = true) || photoPath.startsWith("https://", ignoreCase = true)) {
            photoPath
        } else {
            var cleanBase = serverUrl.trimEnd('/')
            cleanBase = cleanBase.replace(Regex("/api/cbt-exam\\.php$"), "")
            cleanBase = cleanBase.replace(Regex("/cbt-exam\\.php$"), "")
            cleanBase = cleanBase.replace(Regex("/api/?$"), "")

            var cleanPath = photoPath.trimStart('/')
            if (!cleanPath.startsWith("uploads/") && !cleanPath.startsWith("assets/") && !cleanPath.startsWith("img/")) {
                cleanPath = "uploads/siswa/$cleanPath"
            }
            "$cleanBase/$cleanPath"
        }

        kotlin.concurrent.thread {
            try {
                val url = java.net.URL(fullPhotoUrl)
                val conn = url.openConnection() as java.net.HttpURLConnection
                conn.connectTimeout = 8000
                conn.readTimeout = 12000
                conn.instanceFollowRedirects = true
                conn.connect()
                if (conn.responseCode in 200..299) {
                    val stream = conn.inputStream
                    val bitmap = android.graphics.BitmapFactory.decodeStream(stream)
                    stream.close()
                    conn.disconnect()
                    if (bitmap != null) {
                        runOnUiThread {
                            target.setImageBitmap(bitmap)
                        }
                    }
                } else {
                    conn.disconnect()
                    var fallbackBase = serverUrl.trimEnd('/')
                    fallbackBase = fallbackBase.replace(Regex("/api/cbt-exam\\.php$"), "")
                    fallbackBase = fallbackBase.replace(Regex("/cbt-exam\\.php$"), "")
                    fallbackBase = fallbackBase.replace(Regex("/api/?$"), "")
                    val fallbackUrl = "$fallbackBase/${photoPath.trimStart('/')}"

                    val conn2 = java.net.URL(fallbackUrl).openConnection() as java.net.HttpURLConnection
                    conn2.connectTimeout = 6000
                    conn2.readTimeout = 8000
                    conn2.instanceFollowRedirects = true
                    conn2.connect()
                    if (conn2.responseCode in 200..299) {
                        val stream2 = conn2.inputStream
                        val bitmap2 = android.graphics.BitmapFactory.decodeStream(stream2)
                        stream2.close()
                        conn2.disconnect()
                        if (bitmap2 != null) {
                            runOnUiThread {
                                target.setImageBitmap(bitmap2)
                            }
                        }
                    }
                }
            } catch (_: Exception) {}
        }
    }

    private fun showNoQuestionsDialog() {
        val dialogView = LayoutInflater.from(this).inflate(R.layout.dialog_lumina_alert, null)
        val tvTitle = dialogView.findViewById<TextView>(R.id.tvAlertTitle)
        val tvSubtitle = dialogView.findViewById<TextView>(R.id.tvAlertSubtitle)
        val tvMessage = dialogView.findViewById<TextView>(R.id.tvAlertMessage)
        val btnOk = dialogView.findViewById<MaterialButton>(R.id.btnAlertOk)

        tvTitle.text = "⚠️ Belum Ada Butir Soal"
        tvSubtitle.text = "Sesi Ujian Belum Siap"
        tvMessage.text = "Asesmen ini belum memiliki butir soal yang diaktifkan oleh Proktor/Guru. Anda tidak dapat memulai pengerjaan saat ini.\n\nSilakan hubungi Pengawas Ujian."

        val dialog = MaterialAlertDialogBuilder(this)
            .setView(dialogView)
            .setCancelable(true)
            .create()

        btnOk.setOnClickListener {
            dialog.dismiss()
        }

        dialog.window?.setBackgroundDrawable(android.graphics.drawable.ColorDrawable(android.graphics.Color.TRANSPARENT))
        dialog.show()
    }

    private fun startExamActivity() {
        val intent = Intent(this, ExamActivity::class.java).apply {
            putExtra("STUDENT_SESSION", session)
            putExtra("SERVER_URL", serverUrl)
        }
        startActivity(intent)
        @Suppress("DEPRECATION")
        overridePendingTransition(R.anim.anim_fade_in, R.anim.anim_fade_out)
        finish()
    }

    private fun showDndPromptDialog(onContinue: () -> Unit) {
        val dialogView = LayoutInflater.from(this).inflate(R.layout.dialog_lumina_alert, null)
        val tvTitle = dialogView.findViewById<TextView>(R.id.tvAlertTitle)
        val tvMessage = dialogView.findViewById<TextView>(R.id.tvAlertMessage)
        val btnOk = dialogView.findViewById<MaterialButton>(R.id.btnAlertOk)

        tvTitle.text = "🔕 Akses Jangan Ganggu"
        tvMessage.text = "Silakan izinkan Akses Jangan Ganggu untuk melanjutkan pengerjaan ujian."
        btnOk.text = "Izinkan Jangan Ganggu"

        val dialog = MaterialAlertDialogBuilder(this)
            .setView(dialogView)
            .setCancelable(false)
            .create()

        btnOk.setOnClickListener {
            dialog.dismiss()
            SecurityHelper.requestDndPermissionIfNeeded(this)
            onContinue()
        }

        dialog.window?.setBackgroundDrawable(android.graphics.drawable.ColorDrawable(android.graphics.Color.TRANSPARENT))
        dialog.show()
    }

    private fun navigateBackToLogin() {
        val intent = Intent(this, ExamLoginActivity::class.java)
        startActivity(intent)
        @Suppress("DEPRECATION")
        overridePendingTransition(R.anim.anim_fade_in, R.anim.anim_fade_out)
        finish()
    }

    @Deprecated("Deprecated in Java")
    override fun onBackPressed() {
        navigateBackToLogin()
    }

    private fun startUIAnimations() {
        val animBehind = AnimationUtils.loadAnimation(this, R.anim.anim_slide_in_behind)
        val animFadeSlow = AnimationUtils.loadAnimation(this, R.anim.anim_fade_in_slow)

        binding.cardStudentDetails.startAnimation(animBehind)
        binding.cardExamDetails.startAnimation(animFadeSlow)
        binding.cardInstructions.startAnimation(animFadeSlow)
        binding.btnStartExamNow.startAnimation(animFadeSlow)
    }
}
