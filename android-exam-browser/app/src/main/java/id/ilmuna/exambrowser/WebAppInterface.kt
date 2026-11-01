package id.ilmuna.exambrowser

import android.webkit.JavascriptInterface
import android.widget.Toast

/**
 * ==============================================================================
 * ILMUNA EXAM BROWSER — JAVASCRIPT BRIDGE INTERFACE
 * ==============================================================================
 * Komunikasi dua arah antara WebView Web LMS/CBT dengan Aplikasi Android Kotlin:
 * 1. Web memberitahu Android bahwa siswa SEDANG DALAM SESI MENGERJAKAN SOAL
 *    (sehingga jika keluar aplikasi, logika Freeze & Auto-Logout aktif).
 * 2. Web dapat meminta status aplikasi dan versi Kiosk.
 * 3. Android dapat memicu autosave sebelum sesi dibekukan.
 * ==============================================================================
 */
class WebAppInterface(private val activity: MainActivity) {

    @JavascriptInterface
    fun isExamBrowser(): Boolean {
        return true
    }

    @JavascriptInterface
    fun getAppVersion(): String {
        return "1.0.0"
    }

    /**
     * Dipanggil oleh halaman web saat siswa mulai membuka lembar soal ujian.
     * Mengaktifkan flag penjagaan ketat Kiosk (Freeze on Leave).
     */
    @JavascriptInterface
    fun setExamActive(active: Boolean, studentId: String = "", assessmentId: String = "", remainingSeconds: Int = 0, assessmentType: String = "sumatif") {
        activity.runOnUiThread {
            activity.setExamActiveState(active, studentId, assessmentId, remainingSeconds, assessmentType)
        }
    }

    /**
     * Memperbarui sisa waktu terkini dari timer ujian di web.
     */
    @JavascriptInterface
    fun updateRemainingTime(seconds: Int) {
        activity.runOnUiThread {
            activity.updateCurrentRemainingSeconds(seconds)
        }
    }

    /**
     * Menampilkan pesan toast dari web
     */
    @JavascriptInterface
    fun showToast(message: String) {
        activity.runOnUiThread {
            Toast.makeText(activity, message, Toast.LENGTH_SHORT).show()
        }
    }

    /**
     * Dipanggil saat siswa telah menekan tombol "Selesai Ujian" di web.
     * Mematikan mode ujian aktif secara resmi agar tidak dianggap pelanggaran keluar.
     */
    @JavascriptInterface
    fun finishExam() {
        activity.runOnUiThread {
            activity.setExamActiveState(false)
            Toast.makeText(activity, "Ujian selesai dikerjakan.", Toast.LENGTH_LONG).show()
        }
    }
}
