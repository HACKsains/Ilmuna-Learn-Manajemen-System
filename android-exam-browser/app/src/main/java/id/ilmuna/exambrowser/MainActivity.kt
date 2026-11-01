package id.ilmuna.exambrowser

import android.annotation.SuppressLint
import android.content.BroadcastReceiver
import android.content.Context
import android.content.Intent
import android.content.IntentFilter
import android.content.SharedPreferences
import android.graphics.Bitmap
import android.net.Uri
import android.os.BatteryManager
import android.os.Bundle
import android.os.Handler
import android.os.Looper
import android.view.ActionMode
import android.view.LayoutInflater
import android.view.Menu
import android.view.MenuItem
import android.view.View
import android.webkit.CookieManager
import android.webkit.WebChromeClient
import android.webkit.WebResourceError
import android.webkit.WebResourceRequest
import android.webkit.WebSettings
import android.webkit.WebView
import android.webkit.WebViewClient
import android.widget.EditText
import android.widget.TextView
import android.widget.Toast
import androidx.appcompat.app.AlertDialog
import androidx.appcompat.app.AppCompatActivity
import com.google.android.material.dialog.MaterialAlertDialogBuilder
import id.ilmuna.exambrowser.databinding.ActivityMainBinding
import java.io.OutputStreamWriter
import java.net.HttpURLConnection
import java.net.URL
import java.net.URLEncoder
import java.text.SimpleDateFormat
import java.util.Date
import java.util.Locale
import kotlin.concurrent.thread

/**
 * ==============================================================================
 * ILMUNA EXAM BROWSER — MAIN SECURE KIOSK ACTIVITY (KOTLIN)
 * ==============================================================================
 * Fitur Keamanan:
 * 1. Anti Screenshot & Anti Screen Recording (FLAG_SECURE).
 * 2. Anti Copy-Paste, Anti Seleksi Teks & Matikan Context Menu (startActionMode).
 * 3. Logika Keluar Aplikasi (Freeze & Auto-Logout Sisi Aplikasi):
 *    - Ketika siswa keluar aplikasi (Home, Recent Apps, Split Screen, Floating Apps),
 *      aplikasi OTOMATIS ME-LOGOUT akun di sisi aplikasi (bersihkan cookies & sesi).
 *    - Di database, status pengerjaan TETAP "Sedang Mengerjakan" dalam kondisi Freeze
 *      (sisa waktu dibekukan).
 *    - Butir soal & jawaban yang sudah dikerjakan TETAP AMAN & TIDAK DIRESET / TIDAK DIBUAT ULANG.
 * 4. Immersive Sticky Fullscreen Kiosk Mode.
 * ==============================================================================
 */
class MainActivity : AppCompatActivity() {

    private lateinit var binding: ActivityMainBinding
    private var examUrl: String = SplashActivity.DEFAULT_SERVER_URL
    private var violationCount = 0
    private var isPageError: Boolean = false

    // State Pelacakan Sesi Ujian Aktif
    private var isExamActive: Boolean = false
    private var activeStudentId: String = ""
    private var activeAssessmentId: String = ""
    private var activeAssessmentType: String = "sumatif"
    private var lastRemainingSeconds: Int = 0
    private var isSessionFrozen: Boolean = false
    private var isSupervisorExiting: Boolean = false
    private var hasInitialFocusGained: Boolean = false

    // Realtime Clock Handler
    private val clockHandler = Handler(Looper.getMainLooper())
    private val clockRunnable = object : Runnable {
        override fun run() {
            val timeFormat = SimpleDateFormat("HH:mm:ss", Locale.getDefault())
            binding.tvRealtimeClock.text = timeFormat.format(Date())
            clockHandler.postDelayed(this, 1000)
        }
    }

    // Battery Monitor Receiver
    private val batteryReceiver = object : BroadcastReceiver() {
        override fun onReceive(context: Context?, intent: Intent?) {
            val level = intent?.getIntExtra(BatteryManager.EXTRA_LEVEL, -1) ?: -1
            val scale = intent?.getIntExtra(BatteryManager.EXTRA_SCALE, -1) ?: -1
            if (level != -1 && scale != -1) {
                val batteryPct = (level * 100 / scale.toFloat()).toInt()
                binding.tvBatteryStatus.text = "$batteryPct%"
            }
        }
    }

    // Screen Off Monitor Receiver
    private val screenOffReceiver = object : BroadcastReceiver() {
        override fun onReceive(context: Context?, intent: Intent?) {
            if (intent?.action == Intent.ACTION_SCREEN_OFF) {
                if (hasInitialFocusGained && !isSupervisorExiting && isExamActive && !isSessionFrozen && !isFinishing) {
                    handleLeaveAppViolation("Layar Mati / HP Terkunci (Screen Off)")
                }
            }
        }
    }

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        binding = ActivityMainBinding.inflate(layoutInflater)
        setContentView(binding.root)

        // 1. Ambil URL Server Ujian
        examUrl = intent.getStringExtra("EXAM_URL") ?: examUrl

        // 2. Proteksi Anti-Screenshot & Screen Recording (FLAG_SECURE)
        SecurityHelper.enableScreenshotProtection(this)

        // 3. Immersive Fullscreen Kiosk Mode
        SecurityHelper.enableKioskMode(this)

        // Register Screen Off Protection
        registerReceiver(screenOffReceiver, IntentFilter(Intent.ACTION_SCREEN_OFF))

        // 4. Inisialisasi WebView & Kontrol Kiosk
        setupWebView()
        setupKioskControls()

        // 5. Mulai Jam & Pantauan Baterai
        clockHandler.post(clockRunnable)
        registerReceiver(batteryReceiver, IntentFilter(Intent.ACTION_BATTERY_CHANGED))

        // 6. Muat Halaman Ujian
        binding.examWebView.loadUrl(examUrl)
    }

    @SuppressLint("SetJavaScriptEnabled")
    private fun setupWebView() {
        val webView = binding.examWebView
        val settings = webView.settings

        settings.apply {
            javaScriptEnabled = true
            domStorageEnabled = true
            useWideViewPort = true
            loadWithOverviewMode = true
            cacheMode = WebSettings.LOAD_DEFAULT
            allowFileAccess = false
            allowContentAccess = false

            // Tambahkan Identitas Khusus Exam Browser ke User Agent
            val defaultAgent = userAgentString
            userAgentString = "$defaultAgent IlmunaExamBrowser/1.0 (Android; SecureKiosk; Lumina)"
        }

        // Bridge JavaScript Web CBT dengan Kotlin Android
        webView.addJavascriptInterface(WebAppInterface(this), "IlmunaKiosk")

        // 2. ANTI-COPY-PASTE PADA TINGKAT WEBVIEW
        SecurityHelper.setupWebViewAntiCopy(webView, this)

        webView.webViewClient = object : WebViewClient() {
            override fun onPageStarted(view: WebView?, url: String?, favicon: Bitmap?) {
                binding.examProgressBar.visibility = View.VISIBLE
            }

            override fun onPageFinished(view: WebView?, url: String?) {
                binding.examProgressBar.visibility = View.GONE
                binding.swipeRefresh.isRefreshing = false

                // Pastikan Kiosk Mode tetap aktif
                SecurityHelper.enableKioskMode(this@MainActivity)

                // Suntikkan Anti-Copy-Paste CSS & JS ke dalam DOM Web
                view?.let {
                    SecurityHelper.injectAntiCopyScripts(it)
                }

                // Deteksi Otomatis Jika Halaman adalah Lembar Pengerjaan Soal CBT
                url?.let {
                    if (it.contains("/cbt") || it.contains("ujian") || it.contains("asesmen") || it.contains("exam") || it.contains("remedial") || it.contains("pengayaan")) {
                        isExamActive = true
                    }
                }
            }

            override fun onReceivedError(view: WebView?, request: WebResourceRequest?, error: WebResourceError?) {
                if (request?.isForMainFrame == true) {
                    Toast.makeText(this@MainActivity, "Gagal terhubung ke server ujian. Periksa koneksi WiFi / IP Server.", Toast.LENGTH_LONG).show()
                }
            }
        }

        webView.webChromeClient = object : WebChromeClient() {
            override fun onProgressChanged(view: WebView?, newProgress: Int) {
                binding.examProgressBar.progress = newProgress
            }
        }

        binding.swipeRefresh.setOnRefreshListener {
            webView.reload()
        }
    }

    /**
     * ==============================================================================
     * PROTEKSI ANTI-COPY-PASTE: BLOKIR FLOATING TEXT SELECTION ACTION MODE
     * ==============================================================================
     * Mengembalikan null agar menu floating (Salin, Tempel, Bagikan, Web Search)
     * TIDAK BISA muncul di layar Android.
     * ==============================================================================
     */
    override fun onActionModeStarted(mode: ActionMode?) {
        mode?.finish()
        super.onActionModeStarted(mode)
    }

    override fun startActionMode(callback: ActionMode.Callback?): ActionMode? {
        return null
    }

    override fun startActionMode(callback: ActionMode.Callback?, type: Int): ActionMode? {
        return null
    }

    private fun setupKioskControls() {
        // Tombol Reload Darurat
        binding.btnReloadWeb.setOnClickListener {
            binding.examWebView.reload()
            Toast.makeText(this, "Memuat ulang halaman...", Toast.LENGTH_SHORT).show()
        }

        // Tombol Keluar Ujian (Harus dengan PIN Pengawas)
        binding.btnExitExam.setOnClickListener {
            showExitPinDialog()
        }
    }

    private fun showExitPinDialog() {
        val etPin = EditText(this).apply {
            hint = getString(R.string.pin_supervisor_hint)
            inputType = android.text.InputType.TYPE_CLASS_NUMBER or android.text.InputType.TYPE_NUMBER_VARIATION_PASSWORD
        }

        AlertDialog.Builder(this)
            .setTitle("Konfirmasi Keluar Kiosk")
            .setMessage("Masukkan PIN Pengawas untuk membuka kunci dan keluar dari aplikasi:")
            .setView(etPin)
            .setPositiveButton("Buka Kunci") { _, _ ->
                val pin = etPin.text.toString().trim()
                if (pin == SplashActivity.SUPERVISOR_PIN) {
                    isSupervisorExiting = true
                    Toast.makeText(this, "Sesi Kiosk Dibuka oleh Pengawas.", Toast.LENGTH_SHORT).show()
                    startActivity(Intent(this, SplashActivity::class.java))
                    finish()
                } else {
                    Toast.makeText(this, "PIN Pengawas Salah!", Toast.LENGTH_SHORT).show()
                }
            }
            .setNegativeButton("Batal", null)
            .show()
    }

    /**
     * ==============================================================================
     * LOGIKA KELUAR APLIKASI (FREEZE & AUTO-LOGOUT SISI APLIKASI)
     * ==============================================================================
     * Dipanggil saat siswa menekan tombol Home atau Recent Apps (onUserLeaveHint)
     * atau saat aplikasi berpindah ke background (onPause).
     * ==============================================================================
     */
    override fun onUserLeaveHint() {
        super.onUserLeaveHint()
        if (hasInitialFocusGained && !isSupervisorExiting && isExamActive && !isSessionFrozen && !isFinishing) {
            handleLeaveAppViolation("Menekan Tombol Home / Navigasi Keluar")
        }
    }

    override fun onPause() {
        super.onPause()
        if (hasInitialFocusGained && !isSupervisorExiting && isExamActive && !isSessionFrozen && !isFinishing) {
            handleLeaveAppViolation("Aplikasi Berpindah ke Background / Keluar Layar")
        }
    }

    override fun onWindowFocusChanged(hasFocus: Boolean) {
        super.onWindowFocusChanged(hasFocus)
        if (hasFocus) {
            hasInitialFocusGained = true
            SecurityHelper.enableKioskMode(this)
            if (isSessionFrozen) {
                showSessionFrozenDialog("Membuka Bar Notifikasi / Pengaturan Cepat / Navigasi")
            }
        } else {
            if (hasInitialFocusGained && !isSupervisorExiting && isExamActive && !isSessionFrozen && !isFinishing) {
                handleLeaveAppViolation("Membuka Bar Notifikasi / Pengaturan Cepat / Navigasi")
            }
        }
    }

    override fun onMultiWindowModeChanged(isInMultiWindowMode: Boolean) {
        super.onMultiWindowModeChanged(isInMultiWindowMode)
        if (isInMultiWindowMode && hasInitialFocusGained && !isSupervisorExiting && isExamActive && !isSessionFrozen && !isFinishing) {
            handleLeaveAppViolation("Membuka Mini Apps / Mode Layar Belah (Split Screen)")
        }
    }

    override fun onPictureInPictureModeChanged(isInPictureInPictureMode: Boolean) {
        super.onPictureInPictureModeChanged(isInPictureInPictureMode)
        if (isInPictureInPictureMode && !isSupervisorExiting && isExamActive && !isSessionFrozen) {
            handleLeaveAppViolation("Membuka Jendela Mini / Picture-in-Picture")
        }
    }

    /**
     * EKSEKUSI MEKANISME FREEZE & AUTO-LOGOUT APLIKASI
     */
    private fun handleLeaveAppViolation(reason: String = "Peralihan Layar / Keluar Aplikasi") {
        if (isSessionFrozen) return
        isSessionFrozen = true
        violationCount++

        // 1. Langsung sembunyikan WebView dari layar
        binding.examWebView.visibility = View.INVISIBLE

        // 2. Minta Web melakukan autosave jawaban aktif saat ini
        binding.examWebView.evaluateJavascript(
            "if(typeof window.autoSaveExam === 'function'){ window.autoSaveExam(); } else if(typeof window.saveCurrentAnswer === 'function'){ window.saveCurrentAnswer(); }",
            null
        )

        // 3. Kirim Sinyal Freeze ke Server Backend secara Asynchronous
        freezeSessionOnBackend(reason)

        // 4. Otomatis Logout di Sisi Aplikasi Android (Hapus Cookies & Sesi WebView)
        logoutAppSession()

        // 5. Tampilkan Dialog Freeze
        showSessionFrozenDialog(reason)
    }

    /**
     * Mengirimkan permintaan HTTP ke server untuk membekukan sisa waktu ujian.
     */
    private fun freezeSessionOnBackend(reason: String = "Peralihan Layar / Keluar Aplikasi") {
        val targetUrl = CbtApiClient.getFreezeEndpoint(examUrl)

        thread {
            try {
                val url = URL(targetUrl)
                val conn = url.openConnection() as HttpURLConnection
                conn.requestMethod = "POST"
                conn.connectTimeout = 4000
                conn.readTimeout = 4000
                conn.doOutput = true
                conn.setRequestProperty("Content-Type", "application/x-www-form-urlencoded")

                val postData = "action=freeze" +
                        "&tipe=" + URLEncoder.encode(activeAssessmentType, "UTF-8") +
                        "&tipe_asesmen=" + URLEncoder.encode(activeAssessmentType, "UTF-8") +
                        "&id_siswa=" + URLEncoder.encode(activeStudentId, "UTF-8") +
                        "&id_asesmen=" + URLEncoder.encode(activeAssessmentId, "UTF-8") +
                        "&sisa_waktu_detik=$lastRemainingSeconds" +
                        "&alasan=" + URLEncoder.encode(reason, "UTF-8")

                val writer = OutputStreamWriter(conn.outputStream)
                writer.write(postData)
                writer.flush()
                writer.close()

                val responseCode = conn.responseCode
                conn.disconnect()
            } catch (_: Exception) {
            }
        }
    }

    /**
     * Membersihkan sesi login lokal di WebView (Auto-Logout di Aplikasi)
     */
    private fun logoutAppSession() {
        runOnUiThread {
            try {
                val cookieManager = CookieManager.getInstance()
                cookieManager.removeAllCookies(null)
                cookieManager.flush()

                binding.examWebView.clearFormData()
                binding.examWebView.clearHistory()
                binding.examWebView.loadUrl(examUrl)
            } catch (_: Exception) {}
        }
    }

    private var freezeDialog: AlertDialog? = null

    /**
     * Menampilkan Dialog Sesi Ujian Dibekukan kepada Siswa
     */
    private fun showSessionFrozenDialog(reason: String = "Peralihan Layar") {
        if (isFinishing || isDestroyed) return
        if (freezeDialog?.isShowing == true) return

        binding.examWebView.visibility = View.INVISIBLE

        val dialogView = LayoutInflater.from(this).inflate(R.layout.dialog_session_frozen, null)
        val tvReason = dialogView.findViewById<TextView>(R.id.tvFreezeReason)
        val btnExit = dialogView.findViewById<View>(R.id.btnFreezeExit)

        tvReason.text = reason

        val dialog = MaterialAlertDialogBuilder(this)
            .setView(dialogView)
            .setCancelable(false)
            .create()

        btnExit.setOnClickListener {
            isSessionFrozen = false
            dialog.dismiss()
            binding.examWebView.visibility = View.VISIBLE
            SecurityHelper.enableKioskMode(this)
            binding.examWebView.loadUrl(examUrl)
        }

        dialog.window?.setBackgroundDrawable(android.graphics.drawable.ColorDrawable(android.graphics.Color.TRANSPARENT))
        freezeDialog = dialog
        dialog.show()
    }

    /**
     * Dihubungkan via JavaScript Interface saat ujian aktif
     */
    fun setExamActiveState(active: Boolean, studentId: String = "", assessmentId: String = "", remainingSeconds: Int = 0, assessmentType: String = "sumatif") {
        this.isExamActive = active
        this.activeStudentId = studentId
        this.activeAssessmentId = assessmentId
        this.activeAssessmentType = assessmentType.ifBlank { "sumatif" }
        this.lastRemainingSeconds = remainingSeconds
        if (active) {
            this.isSessionFrozen = false
        }
    }

    fun updateCurrentRemainingSeconds(seconds: Int) {
        this.lastRemainingSeconds = seconds
    }

    /**
     * Mencegah tombol Back menutup aplikasi saat ujian
     */
    @Deprecated("Deprecated in Java")
    override fun onBackPressed() {
        if (binding.examWebView.canGoBack()) {
            binding.examWebView.goBack()
        } else {
            Toast.makeText(this, "Aplikasi dalam mode Kiosk Ujian. Selesaikan ujian melalui sistem CBT.", Toast.LENGTH_SHORT).show()
        }
    }

    override fun onDestroy() {
        super.onDestroy()
        clockHandler.removeCallbacks(clockRunnable)
        try {
            unregisterReceiver(screenOffReceiver)
        } catch (_: Exception) {}
        try {
            unregisterReceiver(batteryReceiver)
        } catch (_: Exception) {}
    }
}
