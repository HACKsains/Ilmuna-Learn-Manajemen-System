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
import android.widget.EditText
import android.widget.Toast
import androidx.appcompat.app.AppCompatActivity
import com.google.android.material.button.MaterialButton
import com.google.android.material.dialog.MaterialAlertDialogBuilder
import id.ilmuna.exambrowser.databinding.ActivitySplashBinding

/**
 * ==============================================================================
 * ILMUNA EXAM BROWSER — SPLASH & PRE-FLIGHT GATEWAY
 * ==============================================================================
 * Menampilkan ringkasan kesiapan ujian, konfigurasi IP/URL server sekolah,
 * dan inisiasi keamanan sebelum siswa memasuki sesi Kiosk CBT.
 * ==============================================================================
 */
class SplashActivity : AppCompatActivity() {

    private lateinit var binding: ActivitySplashBinding
    private lateinit var prefs: SharedPreferences

    companion object {
        const val PREF_NAME = "ilmuna_exam_prefs"
        const val KEY_SERVER_URL = "server_url"
        const val DEFAULT_SERVER_URL = "http://10.221.231.106/learn/exam"
        const val SUPERVISOR_PIN = "123456"
    }

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        binding = ActivitySplashBinding.inflate(layoutInflater)
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

        // Cek Keamanan Layar
        SecurityHelper.enableScreenshotProtection(this)

        // Terapkan Animasi Masuk UI
        startUIAnimations()

        // 1. Klik Masuk Ujian CBT Murni Native
        binding.btnStartExam.setOnClickListener {
            val intent = Intent(this, ExamLoginActivity::class.java)
            startActivity(intent)
            @Suppress("DEPRECATION")
            overridePendingTransition(R.anim.anim_fade_in, R.anim.anim_fade_out)
            finish()
        }

        // 2. Klik Pengaturan Server
        binding.btnConfigServer.setOnClickListener {
            showServerConfigDialog()
        }
    }

    private fun startUIAnimations() {
        val animScale = AnimationUtils.loadAnimation(this, R.anim.anim_scale_up)
        val animFade = AnimationUtils.loadAnimation(this, R.anim.anim_fade_in)
        val animSlide = AnimationUtils.loadAnimation(this, R.anim.anim_slide_up)

        binding.frameSplashLogo.startAnimation(animScale)
        binding.tvSplashTitle.startAnimation(animFade)
        binding.tvSplashSubtitle.startAnimation(animFade)
        binding.layoutSplashActions.startAnimation(animSlide)
    }

    private fun showServerConfigDialog() {
        val dialogView = LayoutInflater.from(this).inflate(R.layout.dialog_server_settings, null)
        val etUrl = dialogView.findViewById<EditText>(R.id.etServerUrl)
        val btnCancel = dialogView.findViewById<MaterialButton>(R.id.btnCancelServerSettings)
        val btnSave = dialogView.findViewById<MaterialButton>(R.id.btnSaveServerSettings)

        etUrl.setText(prefs.getString(KEY_SERVER_URL, DEFAULT_SERVER_URL))

        val dialog = MaterialAlertDialogBuilder(this)
            .setView(dialogView)
            .setCancelable(true)
            .create()

        btnCancel.setOnClickListener {
            dialog.dismiss()
        }

        btnSave.setOnClickListener {
            var inputUrl = etUrl.text.toString().trim()
            if (inputUrl.endsWith(".php", ignoreCase = true)) {
                inputUrl = inputUrl.substring(0, inputUrl.length - 4)
            }

            if (inputUrl.isNotEmpty() && (inputUrl.startsWith("http://") || inputUrl.startsWith("https://"))) {
                prefs.edit().putString(KEY_SERVER_URL, inputUrl).apply()
                Toast.makeText(this, "URL Server Berhasil Diperbarui!", Toast.LENGTH_SHORT).show()
                dialog.dismiss()
            } else {
                Toast.makeText(this, "Format URL harus diawali http:// atau https://", Toast.LENGTH_SHORT).show()
            }
        }

        dialog.window?.setBackgroundDrawable(ColorDrawable(Color.TRANSPARENT))
        dialog.show()
    }
}
