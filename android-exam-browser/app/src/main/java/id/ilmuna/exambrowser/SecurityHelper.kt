package id.ilmuna.exambrowser

import android.app.Activity
import android.content.ClipData
import android.content.ClipboardManager
import android.content.Context
import android.content.Intent
import android.os.Build
import android.view.View
import android.view.WindowInsets
import android.view.WindowInsetsController
import android.view.WindowManager
import android.webkit.WebView
import java.io.File

/**
 * ==============================================================================
 * ILMUNA EXAM BROWSER — SECURITY & KIOSK HELPER (KOTLIN)
 * ==============================================================================
 * Fitur Keamanan Tingkat Tinggi (High-Grade Kiosk):
 * 1. Anti Screenshot & Screen Recording (FLAG_SECURE)
 * 2. Anti Copy-Paste, Anti Seleksi Teks & Blokir Context Menu
 * 3. Pembersih Clipboard OS Otomatis
 * 4. Immersive Sticky Fullscreen (Sembunyikan Status & Nav Bar)
 * 5. Deteksi Integritas & Root Perangkat
 * ==============================================================================
 */
object SecurityHelper {

    /**
     * 1. PROTEKSI ANTI-SCREENSHOT & SCREEN RECORDING (FLAG_SECURE)
     * - Mencegah screenshot fisik tombol (Power + Vol) maupun gesture 3 jari.
     * - Mencegah aplikasi perekam layar (Screen Recorder) - hasil video hitam/blank.
     * - Menyembunyikan preview tampilan aplikasi pada menu Recent Apps / App Switcher.
     */
    fun enableScreenshotProtection(activity: Activity) {
        activity.window.setFlags(
            WindowManager.LayoutParams.FLAG_SECURE,
            WindowManager.LayoutParams.FLAG_SECURE
        )
    }

    /**
     * 2. PROTEKSI ANTI COPY-PASTE & ANTI SELEKSI TEKS PADA WEBVIEW
     * - Mematikan event klik lama (Long Click / Context Menu).
     * - Mematikan haptic feedback saat ditekan lama.
     */
    fun setupWebViewAntiCopy(webView: WebView, context: Context) {
        webView.isLongClickable = false
        webView.isHapticFeedbackEnabled = false
        webView.setOnLongClickListener {
            clearSystemClipboard(context)
            true // Mengonsumsi event agar context menu seleksi tidak muncul
        }
    }

    /**
     * 3. INJEKSI SCRIPT & CSS ANTI-COPY-PASTE SECARA DINAMIS KE WEB CBT
     * - Menolak seleksi teks via CSS (user-select: none).
     * - Membatalkan event: copy, cut, paste, contextmenu, selectstart, dragstart.
     */
    fun injectAntiCopyScripts(webView: WebView) {
        val antiCopyJs = """
            (function() {
                // Injeksi CSS Anti-Seleksi Teks
                var style = document.getElementById('ilmuna-anti-copy-style');
                if (!style) {
                    style = document.createElement('style');
                    style.id = 'ilmuna-anti-copy-style';
                    style.type = 'text/css';
                    style.innerHTML = `
                        * {
                            -webkit-touch-callout: none !important;
                            -webkit-user-select: none !important;
                            -khtml-user-select: none !important;
                            -moz-user-select: none !important;
                            -ms-user-select: none !important;
                            user-select: none !important;
                        }
                        input[type="text"], input[type="password"], input[type="number"], textarea {
                            -webkit-user-select: text !important;
                            user-select: text !important;
                        }
                    `;
                    document.head.appendChild(style);
                }

                // Blokir Event Salin, Potong, Tempel, Menu Konteks, dan Drag
                var blockedEvents = ['copy', 'cut', 'paste', 'contextmenu', 'selectstart', 'dragstart'];
                blockedEvents.forEach(function(evtName) {
                    document.addEventListener(evtName, function(e) {
                        e.preventDefault();
                        e.stopPropagation();
                        return false;
                    }, true);
                });

                // Tambahkan identitas browser aman ke window global
                window.__IS_ILMUNA_SECURE_EXAM_BROWSER__ = true;
            })();
        """.trimIndent()

        webView.evaluateJavascript(antiCopyJs, null)
    }

    /**
     * 4. PEMBERSIH CLIPBOARD SISTEM OS ANDROID
     * Menghapus isi clipboard sehingga teks yang disalin sebelumnya hilang.
     */
    fun clearSystemClipboard(context: Context) {
        try {
            val clipboard = context.getSystemService(Context.CLIPBOARD_SERVICE) as? ClipboardManager
            clipboard?.let {
                val clipData = ClipData.newPlainText("", "")
                it.setPrimaryClip(clipData)
            }
        } catch (_: Exception) {}
    }

    /**
     * 5. IMMERSIVE STICKY FULLSCREEN (Kiosk Mode)
     * Mengunci navigasi Android dan Status Bar ke mode tersembunyi penuh.
     */
    fun enableKioskMode(activity: Activity) {
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.R) {
            @Suppress("DEPRECATION")
            activity.window.setDecorFitsSystemWindows(false)
            activity.window.insetsController?.let { controller ->
                controller.hide(WindowInsets.Type.statusBars() or WindowInsets.Type.navigationBars())
                controller.systemBarsBehavior =
                    WindowInsetsController.BEHAVIOR_SHOW_TRANSIENT_BARS_BY_SWIPE
            }
        } else {
            @Suppress("DEPRECATION")
            activity.window.decorView.systemUiVisibility = (
                View.SYSTEM_UI_FLAG_IMMERSIVE_STICKY
                    or View.SYSTEM_UI_FLAG_LAYOUT_STABLE
                    or View.SYSTEM_UI_FLAG_LAYOUT_HIDE_NAVIGATION
                    or View.SYSTEM_UI_FLAG_LAYOUT_FULLSCREEN
                    or View.SYSTEM_UI_FLAG_HIDE_NAVIGATION
                    or View.SYSTEM_UI_FLAG_FULLSCREEN
            )
        }
    }

    /**
     * 6. DETEKSI PERANGKAT ROOT
     */
    fun isDeviceRooted(): Boolean {
        val paths = arrayOf(
            "/system/app/Superuser.apk",
            "/sbin/su",
            "/system/bin/su",
            "/system/xbin/su",
            "/data/local/xbin/su",
            "/data/local/bin/su",
            "/system/sd/xbin/su",
            "/system/bin/failsafe/su",
            "/data/local/su"
        )
        for (path in paths) {
            if (File(path).exists()) return true
        }
        return false
    }

    /**
     * 7. PROTEKSI ANTI COPY-PASTE PADA EDITTEXT (NATIVE ESSAY INPUT)
     * Mematikan paste, seleksi teks, dan context menu pada kolom jawaban esai.
     */
    fun disableCopyPaste(editText: android.widget.EditText) {
        editText.isLongClickable = false
        editText.setTextIsSelectable(false)
        val noActionMode = object : android.view.ActionMode.Callback {
            override fun onCreateActionMode(mode: android.view.ActionMode?, menu: android.view.Menu?): Boolean = false
            override fun onPrepareActionMode(mode: android.view.ActionMode?, menu: android.view.Menu?): Boolean = false
            override fun onActionItemClicked(mode: android.view.ActionMode?, item: android.view.MenuItem?): Boolean = false
            override fun onDestroyActionMode(mode: android.view.ActionMode?) {}
        }
        editText.customSelectionActionModeCallback = noActionMode
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.M) {
            editText.customInsertionActionModeCallback = noActionMode
        }
    }

    /**
     * 8. DETEKSI MODE PENGEMBANG / USB DEBUGGING (DEVELOPER OPTIONS)
     */
    fun isDeveloperOptionsEnabled(context: Context): Boolean {
        return try {
            val devOptions = android.provider.Settings.Global.getInt(
                context.contentResolver,
                android.provider.Settings.Global.DEVELOPMENT_SETTINGS_ENABLED, 0
            ) != 0
            val adbEnabled = android.provider.Settings.Global.getInt(
                context.contentResolver,
                android.provider.Settings.Global.ADB_ENABLED, 0
            ) != 0
            devOptions || adbEnabled
        } catch (_: Exception) {
            false
        }
    }

    /**
     * 9. MODE JANGAN GANGGU (DO NOT DISTURB / DND) & STERILISASI NOTIFIKASI SEMUA APLIKASI
     * Memblokir 100% seluruh notifikasi melayang (heads-up banners) dari SELURUH aplikasi tanpa terkecuali.
     */
    @Suppress("DEPRECATION")
    fun enableDoNotDisturbMode(context: Context) {
        try {
            val audioManager = context.getSystemService(Context.AUDIO_SERVICE) as? android.media.AudioManager
            audioManager?.ringerMode = android.media.AudioManager.RINGER_MODE_SILENT

            val nm = context.getSystemService(Context.NOTIFICATION_SERVICE) as? android.app.NotificationManager
            if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.M && nm?.isNotificationPolicyAccessGranted == true) {
                try {
                    val allSuppressedEffects = if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.P) {
                        android.app.NotificationManager.Policy.SUPPRESSED_EFFECT_SCREEN_OFF or
                                android.app.NotificationManager.Policy.SUPPRESSED_EFFECT_SCREEN_ON or
                                android.app.NotificationManager.Policy.SUPPRESSED_EFFECT_FULL_SCREEN_INTENT or
                                android.app.NotificationManager.Policy.SUPPRESSED_EFFECT_PEEK or
                                android.app.NotificationManager.Policy.SUPPRESSED_EFFECT_STATUS_BAR or
                                android.app.NotificationManager.Policy.SUPPRESSED_EFFECT_BADGE or
                                android.app.NotificationManager.Policy.SUPPRESSED_EFFECT_NOTIFICATION_LIST
                    } else if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) {
                        android.app.NotificationManager.Policy.SUPPRESSED_EFFECT_SCREEN_OFF or
                                android.app.NotificationManager.Policy.SUPPRESSED_EFFECT_SCREEN_ON or
                                android.app.NotificationManager.Policy.SUPPRESSED_EFFECT_PEEK or
                                android.app.NotificationManager.Policy.SUPPRESSED_EFFECT_STATUS_BAR
                    } else {
                        0
                    }

                    val totalBlockPolicy = android.app.NotificationManager.Policy(
                        0, // 0 Priority Categories (Tidak ada pengecualian kategori)
                        0, // 0 Call Senders (Tidak ada panggilan masuk yang diizinkan)
                        0, // 0 Message Senders (Tidak ada pesan/chat yang diizinkan)
                        allSuppressedEffects // Blokir seluruh efek visual banner melayang & status bar untuk semua aplikasi
                    )
                    nm.notificationPolicy = totalBlockPolicy
                } catch (_: Exception) {}

                // Kunci Interruption Filter ke NONE (Penutupan Total Seluruh Notifikasi OS)
                nm.setInterruptionFilter(android.app.NotificationManager.INTERRUPTION_FILTER_NONE)
            }
        } catch (_: Exception) {}
    }

    fun disableDoNotDisturbMode(context: Context) {
        try {
            val audioManager = context.getSystemService(Context.AUDIO_SERVICE) as? android.media.AudioManager
            audioManager?.ringerMode = android.media.AudioManager.RINGER_MODE_NORMAL

            val nm = context.getSystemService(Context.NOTIFICATION_SERVICE) as? android.app.NotificationManager
            if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.M && nm?.isNotificationPolicyAccessGranted == true) {
                nm.setInterruptionFilter(android.app.NotificationManager.INTERRUPTION_FILTER_ALL)
            }
        } catch (_: Exception) {}
    }

    fun hasDndPermission(context: Context): Boolean {
        return if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.M) {
            val nm = context.getSystemService(Context.NOTIFICATION_SERVICE) as? android.app.NotificationManager
            nm?.isNotificationPolicyAccessGranted == true
        } else {
            true
        }
    }

    fun requestDndPermissionIfNeeded(activity: Activity) {
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.M) {
            val nm = activity.getSystemService(Context.NOTIFICATION_SERVICE) as? android.app.NotificationManager
            if (nm != null && !nm.isNotificationPolicyAccessGranted) {
                try {
                    val intent = Intent(android.provider.Settings.ACTION_NOTIFICATION_POLICY_ACCESS_SETTINGS)
                    activity.startActivity(intent)
                } catch (_: Exception) {}
            }
        }
    }
}
