package id.ilmuna.exambrowser

import android.content.Context
import android.content.Intent
import android.content.SharedPreferences
import android.graphics.Color
import android.graphics.drawable.ColorDrawable
import android.os.Bundle
import android.view.LayoutInflater
import android.view.View
import android.view.animation.AnimationUtils
import android.widget.TextView
import android.widget.Toast
import androidx.appcompat.app.AppCompatActivity
import com.google.android.material.dialog.MaterialAlertDialogBuilder
import id.ilmuna.exambrowser.databinding.ActivityExamLoginBinding

class ExamLoginActivity : AppCompatActivity() {

    private lateinit var binding: ActivityExamLoginBinding
    private lateinit var prefs: SharedPreferences

    companion object {
        const val PREF_NAME = "ilmuna_exam_prefs"
        const val KEY_SERVER_URL = "server_url"
        const val DEFAULT_SERVER_URL = "http://10.221.231.106/learn/exam"
        const val SUPERVISOR_PIN = "123456"
    }

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        binding = ActivityExamLoginBinding.inflate(layoutInflater)
        setContentView(binding.root)

        prefs = getSharedPreferences(PREF_NAME, Context.MODE_PRIVATE)

        // Inisialisasi default URL jika belum pernah tersimpan
        val saved = prefs.getString(KEY_SERVER_URL, null)
        if (saved == null) {
            prefs.edit().putString(KEY_SERVER_URL, DEFAULT_SERVER_URL).apply()
        } else if (saved.endsWith(".php", ignoreCase = true)) {
            val clean = saved.substring(0, saved.length - 4)
            prefs.edit().putString(KEY_SERVER_URL, clean).apply()
        }

        // Keamanan Layar Kiosk & Sterilkan dari Prompt Autofill
        SecurityHelper.enableScreenshotProtection(this)
        SecurityHelper.enableKioskMode(this)
        if (android.os.Build.VERSION.SDK_INT >= android.os.Build.VERSION_CODES.O) {
            window.decorView.importantForAutofill = View.IMPORTANT_FOR_AUTOFILL_NO_EXCLUDE_DESCENDANTS
        }

        // Bind Footer Versi Aplikasi Otomatis
        val appVersion = try {
            @Suppress("DEPRECATION")
            packageManager.getPackageInfo(packageName, 0).versionName ?: "1.0.0"
        } catch (_: Exception) {
            "1.0.0"
        }
        binding.tvAppVersionFooter.text = "ILMUNA LEARN CBT APPS v$appVersion"

        startUIAnimations()

        // 1. Tombol Masuk Ujian CBT
        binding.btnSubmitExamLogin.setOnClickListener {
            handleLogin()
        }

        // 2. Tombol Kembali ke Halaman Utama (di bawah tombol Masuk)
        binding.btnBackToSplash.setOnClickListener {
            navigateBackToSplash()
        }
    }

    private fun navigateBackToSplash() {
        val intent = Intent(this, SplashActivity::class.java)
        startActivity(intent)
        @Suppress("DEPRECATION")
        overridePendingTransition(R.anim.anim_fade_in, R.anim.anim_fade_out)
        finish()
    }

    @Deprecated("Deprecated in Java")
    override fun onBackPressed() {
        navigateBackToSplash()
    }

    private fun startUIAnimations() {
        val animBehind = AnimationUtils.loadAnimation(this, R.anim.anim_slide_in_behind)
        val animFadeSlow = AnimationUtils.loadAnimation(this, R.anim.anim_fade_in_slow)

        binding.layoutBrandHeader.startAnimation(animBehind)
        binding.cardLoginContainer.startAnimation(animFadeSlow)
    }

    private fun getServerUrl(): String {
        return prefs.getString(KEY_SERVER_URL, DEFAULT_SERVER_URL) ?: DEFAULT_SERVER_URL
    }

    private fun handleLogin() {
        val identifier = binding.etIdentifier.text.toString().trim()
        val password   = binding.etPassword.text.toString().trim()
        val token      = binding.etToken.text.toString().trim()

        if (identifier.isEmpty() || password.isEmpty()) {
            showLuminaDialog("Perhatian", "Silakan masukkan Nomor Peserta / Username Ujian dan Password Anda.")
            return
        }

        setLoadingState(true)

        val serverUrl = getServerUrl()
        CbtApiClient.login(
            baseUrl = serverUrl,
            identifier = identifier,
            password = password,
            token = token,
            onSuccess = { session ->
                setLoadingState(false)
                Toast.makeText(this, "Login berhasil! Selamat datang, ${session.namaSiswa}", Toast.LENGTH_SHORT).show()
                val intent = Intent(this, ExamBriefingActivity::class.java).apply {
                    putExtra("STUDENT_SESSION", session)
                    putExtra("SERVER_URL", serverUrl)
                }
                startActivity(intent)
                @Suppress("DEPRECATION")
                overridePendingTransition(R.anim.anim_fade_in, R.anim.anim_fade_out)
                finish()
            },
            onError = { message, isFrozen, isCompleted, isAttendanceRequired ->
                setLoadingState(false)
                if (isFrozen) {
                    showFreezeDialog(message)
                } else if (isCompleted) {
                    showLuminaDialog("Sesi Ujian Selesai", message)
                } else if (isAttendanceRequired) {
                    showLuminaDialog("Wajib Absensi Terlebih Dahulu", message)
                } else {
                    showLuminaDialog("Gagal Masuk Ujian", message)
                }
            }
        )
    }

    private fun showFreezeDialog(reasonMessage: String) {
        val dialogView = LayoutInflater.from(this).inflate(R.layout.dialog_session_frozen, null)
        val tvReason = dialogView.findViewById<TextView>(R.id.tvFreezeReason)
        val btnExit = dialogView.findViewById<View>(R.id.btnFreezeExit)

        tvReason.text = reasonMessage

        val dialog = MaterialAlertDialogBuilder(this)
            .setView(dialogView)
            .setCancelable(false)
            .create()

        btnExit.setOnClickListener {
            dialog.dismiss()
        }

        dialog.window?.setBackgroundDrawable(ColorDrawable(Color.TRANSPARENT))
        dialog.show()
    }

    private fun setLoadingState(isLoading: Boolean) {
        binding.pbLoginLoading.visibility = if (isLoading) View.VISIBLE else View.INVISIBLE
        binding.btnSubmitExamLogin.isEnabled = !isLoading
        binding.btnSubmitExamLogin.text = if (isLoading) "Memverifikasi Kredensial..." else "Masuk & Mulai Ujian"
    }

    private fun showLuminaDialog(title: String, message: String) {
        val dialogView = LayoutInflater.from(this).inflate(R.layout.dialog_lumina_alert, null)
        val tvTitle = dialogView.findViewById<TextView>(R.id.tvAlertTitle)
        val tvMessage = dialogView.findViewById<TextView>(R.id.tvAlertMessage)
        val btnOk = dialogView.findViewById<com.google.android.material.button.MaterialButton>(R.id.btnAlertOk)

        tvTitle.text = title
        tvMessage.text = message

        val dialog = MaterialAlertDialogBuilder(this)
            .setView(dialogView)
            .setCancelable(true)
            .create()

        btnOk.setOnClickListener {
            dialog.dismiss()
        }

        dialog.window?.setBackgroundDrawable(ColorDrawable(Color.TRANSPARENT))
        dialog.show()
    }
}
