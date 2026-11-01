package id.ilmuna.exambrowser

import android.content.BroadcastReceiver
import android.content.Context
import android.content.Intent
import android.content.IntentFilter
import android.graphics.Bitmap
import android.graphics.BitmapFactory
import android.graphics.Color
import android.os.Build
import android.os.Bundle
import android.os.Handler
import android.os.Looper
import android.text.Editable
import android.text.Html
import android.text.TextWatcher
import android.view.LayoutInflater
import android.view.View
import android.widget.EditText
import android.widget.ImageView
import android.widget.TextView
import android.widget.Toast
import androidx.appcompat.app.AlertDialog
import androidx.appcompat.app.AppCompatActivity
import androidx.collection.LruCache
import androidx.core.content.ContextCompat
import androidx.recyclerview.widget.GridLayoutManager
import androidx.recyclerview.widget.RecyclerView
import com.google.android.material.button.MaterialButton
import com.google.android.material.card.MaterialCardView
import com.google.android.material.dialog.MaterialAlertDialogBuilder
import id.ilmuna.exambrowser.databinding.ActivityExamBinding
import kotlin.concurrent.thread

class ExamActivity : AppCompatActivity() {

    private lateinit var binding: ActivityExamBinding
    private val imageCache = object : LruCache<String, Bitmap>(20 * 1024 * 1024) {
        override fun sizeOf(key: String, value: Bitmap): Int {
            return value.byteCount
        }
    }
    private lateinit var session: StudentSession
    private var serverUrl: String = ExamLoginActivity.DEFAULT_SERVER_URL

    private val questions = mutableListOf<QuestionItem>()
    private var currentIndex = 0
    private var isSessionFrozen = false
    private var isSupervisorExiting = false
    private var isSubmittingExam = false
    private var hasInitialFocusGained = false
    private var isInternalDialogOpen = false

    // Realtime Countdown Timer
    private val timerHandler = Handler(Looper.getMainLooper())
    private var timerRunnable: Runnable? = null

    // Autosave Debounce
    private val debounceHandler = Handler(Looper.getMainLooper())
    private var debounceRunnable: Runnable? = null
    private var activeEssayWatcher: TextWatcher? = null

    // Monitor Layar Mati (Screen Off Freeze Protection)
    private val screenOffReceiver = object : BroadcastReceiver() {
        override fun onReceive(context: Context?, intent: Intent?) {
            if (intent?.action == Intent.ACTION_SCREEN_OFF) {
                if (hasInitialFocusGained && !isSupervisorExiting && !isSessionFrozen && !isInternalDialogOpen && !isFinishing) {
                    handleLeaveAppViolation("Layar Mati / HP Terkunci (Screen Off)")
                }
            }
        }
    }

    // Monitor Panggilan Telepon Masuk (Kembalikan Ujian ke Depan)
    private val phoneCallReceiver = object : BroadcastReceiver() {
        override fun onReceive(context: Context?, intent: Intent?) {
            if (intent?.action == "android.intent.action.PHONE_STATE") {
                val state = intent.getStringExtra(android.telephony.TelephonyManager.EXTRA_STATE)
                if (state == android.telephony.TelephonyManager.EXTRA_STATE_RINGING) {
                    val reorderIntent = Intent(context, ExamActivity::class.java).apply {
                        addFlags(Intent.FLAG_ACTIVITY_NEW_TASK or Intent.FLAG_ACTIVITY_SINGLE_TOP or Intent.FLAG_ACTIVITY_REORDER_TO_FRONT)
                    }
                    context?.startActivity(reorderIntent)
                }
            }
        }
    }

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        binding = ActivityExamBinding.inflate(layoutInflater)
        setContentView(binding.root)

        val sess = if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.TIRAMISU) {
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

        // 1. Keamanan Kiosk & Anti-Screenshot
        SecurityHelper.enableScreenshotProtection(this)
        SecurityHelper.enableKioskMode(this)
        SecurityHelper.disableCopyPaste(binding.etEssayAnswer)

        // Floating Keyboard Animation untuk Input Esai (Teks Soal & Header Tetap 100% Fixed Murni)
        androidx.core.view.ViewCompat.setOnApplyWindowInsetsListener(binding.root) { _, insets ->
            val imeInsets = insets.getInsets(androidx.core.view.WindowInsetsCompat.Type.ime())
            val imeHeight = imeInsets.bottom
            val sysInsets = insets.getInsets(androidx.core.view.WindowInsetsCompat.Type.systemBars())

            if (imeHeight > 0) {
                val shiftY = -(imeHeight - sysInsets.bottom).toFloat()
                binding.cardFixedOptions.animate()
                    .translationY(shiftY)
                    .setDuration(120)
                    .start()
            } else {
                binding.cardFixedOptions.animate()
                    .translationY(0f)
                    .setDuration(120)
                    .start()
            }
            insets
        }

        // Register Screen Off & Phone Call Protection
        SecurityHelper.enableDoNotDisturbMode(this)
        try {
            registerReceiver(screenOffReceiver, IntentFilter(Intent.ACTION_SCREEN_OFF))
            val phoneFilter = IntentFilter("android.intent.action.PHONE_STATE")
            registerReceiver(phoneCallReceiver, phoneFilter)
        } catch (_: Exception) {}

        // 2. Tampilkan Info Header Ujian
        binding.tvHeaderAssessmentTitle.text = session.judulAsesmen
        val badgeTipe = if (session.tipeAsesmen.equals("remedial", ignoreCase = true)) {
            " • ${(session.subtipe ?: "Remedial")}"
        } else {
            ""
        }
        binding.tvHeaderStudentInfo.text = "${session.namaSiswa} • ${session.namaKelas}$badgeTipe"

        // 3. Tombol Aksi Kiosk
        setupControls()

        // 4. Ambil Daftar Soal dari Server
        loadExamQuestions()

        // 5. Mulai Timer Hitung Mundur
        startTimer()
    }

    private fun setupControls() {
        // Tombol Sebelumnya
        binding.btnPrevQuestion.setOnClickListener {
            if (currentIndex > 0) {
                currentIndex--
                renderCurrentQuestion(animate = true)
            }
        }

        // Tombol Berikutnya / Selesai
        binding.btnNextQuestion.setOnClickListener {
            if (currentIndex < questions.size - 1) {
                currentIndex++
                renderCurrentQuestion(animate = true)
            } else {
                isSubmittingExam = true
                confirmSubmitExam()
            }
        }

        // Tombol Ragu-ragu
        binding.btnToggleRagu.setOnClickListener {
            toggleRaguRagu()
        }

        // Tombol Grid Nomor Soal
        binding.btnOpenQuestionGrid.setOnClickListener {
            showQuestionGridDialog()
        }

        // Tombol Selesai Ujian di Header
        binding.btnFinishExamHeader.setOnClickListener {
            isSubmittingExam = true
            confirmSubmitExam()
        }
    }

    private fun loadExamQuestions() {
        binding.pbExamLoading.visibility = View.VISIBLE
        CbtApiClient.getQuestions(
            baseUrl = serverUrl,
            session = session,
            onSuccess = { list ->
                binding.pbExamLoading.visibility = View.GONE
                questions.clear()

                // Acak urutan soal & opsi secara dinamis per sesi masuk agar soal teracak kembali setelah freeze/re-login
                val nowSeed = System.currentTimeMillis()
                val random = java.util.Random(nowSeed)

                val shuffledList = list.shuffled(random).mapIndexed { idx, item ->
                    val optionRandom = java.util.Random(nowSeed + item.id * 17L)
                    val shuffledOptions = item.opsi.shuffled(optionRandom)
                    item.copy(nomorUrut = idx + 1, opsi = shuffledOptions)
                }

                questions.addAll(shuffledList)
                if (questions.isNotEmpty()) {
                    currentIndex = 0
                    renderCurrentQuestion(animate = true)
                } else {
                    showLuminaDialog("Informasi", "Belum ada butir soal yang diaktifkan untuk asesmen ini.")
                }
            },
            onError = { err ->
                binding.pbExamLoading.visibility = View.GONE
                showLuminaDialog("Gagal Memuat Soal", err)
            }
        )
    }

    private fun updateExamProgress() {
        if (questions.isEmpty()) return
        var answeredCount = 0
        for (q in questions) {
            val hasAns = q.jawabanTersimpan != null &&
                (q.jawabanTersimpan?.idOpsiDipilih != null || !q.jawabanTersimpan?.jawabanTeks.isNullOrBlank())
            if (hasAns) answeredCount++
        }
        val percent = (answeredCount * 100) / questions.size
        binding.progressExamProgress.progress = percent
        binding.tvExamProgressText.text = "$answeredCount dari ${questions.size} soal terjawab ($percent%)"
    }

    private fun renderCurrentQuestion(animate: Boolean = false) {
        if (questions.isEmpty()) return

        val q = questions[currentIndex]
        updateExamProgress()

        if (animate) {
            binding.cardQuestionScrollable.alpha = 0f
            binding.cardQuestionScrollable.translationY = 40f
            binding.cardQuestionScrollable.animate()
                .alpha(1f)
                .translationY(0f)
                .setDuration(300)
                .setInterpolator(android.view.animation.DecelerateInterpolator())
                .start()

            binding.cardFixedOptions.alpha = 0f
            binding.cardFixedOptions.translationY = 40f
            binding.cardFixedOptions.animate()
                .alpha(1f)
                .translationY(0f)
                .setDuration(300)
                .setStartDelay(50)
                .setInterpolator(android.view.animation.DecelerateInterpolator())
                .start()
        }

        // Update info nomor & tipe
        binding.tvQuestionNumber.text = "Soal No. ${q.nomorUrut} dari ${questions.size}"
        binding.tvQuestionTypeBadge.text = q.tipeSoal.uppercase()

        // Render teks stimulus & pertanyaan
        if (android.os.Build.VERSION.SDK_INT >= android.os.Build.VERSION_CODES.N) {
            binding.tvQuestionText.text = Html.fromHtml(q.pertanyaan, Html.FROM_HTML_MODE_COMPACT)
        } else {
            @Suppress("DEPRECATION")
            binding.tvQuestionText.text = Html.fromHtml(q.pertanyaan)
        }

        // Render In-App Enlargeable Stimulus Image
        val stimulusPath = q.gambarStimulus
        val stimulusUrl = q.gambarStimulusUrl ?: if (!stimulusPath.isNullOrBlank()) {
            if (stimulusPath.startsWith("http://", ignoreCase = true) || stimulusPath.startsWith("https://", ignoreCase = true)) {
                stimulusPath
            } else {
                "${serverUrl.trimEnd('/')}/${stimulusPath.trimStart('/')}"
            }
        } else null

        if (!stimulusUrl.isNullOrBlank()) {
            binding.imgStimulus.visibility = View.VISIBLE
            loadImageAsync(stimulusUrl, binding.imgStimulus)
            binding.imgStimulus.setOnClickListener {
                if (binding.imgStimulus.drawable != null) {
                    showLargeImagePreview(binding.imgStimulus.drawable)
                }
            }
        } else {
            binding.imgStimulus.visibility = View.GONE
            binding.imgStimulus.setImageDrawable(null)
        }

        // Render In-App Embedded YouTube Video Player jika ada link YouTube
        val videoSource = (q.linkVideo ?: "") + " " + q.pertanyaan + " " + (q.gambarStimulus ?: "")
        val videoId = extractYouTubeVideoId(videoSource)
        if (videoId != null) {
            binding.webYouTubePlayer.visibility = View.VISIBLE
            binding.webYouTubePlayer.settings.apply {
                javaScriptEnabled = true
                domStorageEnabled = true
                mediaPlaybackRequiresUserGesture = false
                allowFileAccess = true
                allowContentAccess = true
                userAgentString = "Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Mobile Safari/537.36"
                if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.LOLLIPOP) {
                    mixedContentMode = android.webkit.WebSettings.MIXED_CONTENT_ALWAYS_ALLOW
                }
            }
            binding.webYouTubePlayer.webViewClient = object : android.webkit.WebViewClient() {
                override fun shouldOverrideUrlLoading(view: android.webkit.WebView?, request: android.webkit.WebResourceRequest?): Boolean {
                    val urlStr = request?.url?.toString() ?: ""
                    // Blokir seluruh navigasi ke luar (seperti mengklik judul, logo, atau link eksternal YouTube)
                    if (!urlStr.contains("embed") && !urlStr.contains("youtube-nocookie")) {
                        return true
                    }
                    return false
                }
            }
            binding.webYouTubePlayer.webChromeClient = android.webkit.WebChromeClient()

            val cleanOrigin = if (serverUrl.startsWith("http")) serverUrl.trimEnd('/') else "http://localhost"
            val encodedOrigin = java.net.URLEncoder.encode(cleanOrigin, "UTF-8")

            val html = """
                <!DOCTYPE html>
                <html>
                <head>
                <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
                <style>
                * { margin: 0; padding: 0; box-sizing: border-box; }
                body, html { width: 100%; height: 100%; background-color: #000; overflow: hidden; display: flex; justify-content: center; align-items: center; }
                .video-wrapper { position: relative; width: 100%; height: 100%; overflow: hidden; }
                .video-wrapper iframe { position: absolute; top: 0; left: 0; width: 100%; height: 100%; border: 0; }
                
                /* Shield Transparan: Memblokir klik pada Judul, Logo YouTube, dan Link Eksternal */
                .top-shield { position: absolute; top: 0; left: 0; width: 100%; height: 50px; z-index: 10; background: transparent; }
                .logo-shield { position: absolute; bottom: 0; right: 0; width: 100px; height: 36px; z-index: 10; background: transparent; }
                </style>
                </head>
                <body>
                <div class="video-wrapper">
                <div class="top-shield" onclick="event.stopPropagation(); event.preventDefault();"></div>
                <div class="logo-shield" onclick="event.stopPropagation(); event.preventDefault();"></div>
                <iframe src="https://www.youtube.com/embed/$videoId?enablejsapi=1&origin=$encodedOrigin&widget_referrer=$encodedOrigin&autoplay=0&controls=1&rel=0&modestbranding=1&showinfo=0&iv_load_policy=3&disablekb=1&fs=0&playsinline=1" 
                        frameborder="0" 
                        allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture"></iframe>
                </div>
                </body>
                </html>
            """.trimIndent()

            binding.webYouTubePlayer.loadDataWithBaseURL(cleanOrigin, html, "text/html", "utf-8", null)
        } else {
            binding.webYouTubePlayer.visibility = View.GONE
            binding.webYouTubePlayer.loadUrl("about:blank")
        }

        // Update status tombol ragu-ragu
        val isRagu = q.jawabanTersimpan?.isRagu == 1
        updateRaguButtonState(isRagu)

        // Tombol Prev / Next state
        binding.btnPrevQuestion.isEnabled = (currentIndex > 0)
        if (currentIndex == questions.size - 1) {
            binding.btnNextQuestion.text = "Selesai"
            binding.btnNextQuestion.setIconResource(android.R.drawable.checkbox_on_background)
            binding.btnNextQuestion.backgroundTintList = ContextCompat.getColorStateList(this, R.color.status_safe)
        } else {
            binding.btnNextQuestion.text = "Berikutnya"
            binding.btnNextQuestion.setIconResource(android.R.drawable.ic_media_next)
            binding.btnNextQuestion.backgroundTintList = ContextCompat.getColorStateList(this, R.color.lumina_navy)
        }

        // Render Pilihan Ganda atau Essay / Tugas Terbimbing / Studi Kasus (Remedial & Asesmen)
        val isEssayOrTextQuestion = q.tipeSoal.contains("Essay", ignoreCase = true) ||
            q.tipeSoal.contains("Uraian", ignoreCase = true) ||
            q.tipeSoal.contains("Tugas", ignoreCase = true) ||
            q.tipeSoal.contains("Studi Kasus", ignoreCase = true) ||
            q.opsi.isEmpty()

        if (isEssayOrTextQuestion) {
            binding.cardFixedOptions.visibility = View.VISIBLE
            binding.tvFixedOptionsTitle.text = when {
                q.tipeSoal.contains("Tugas", ignoreCase = true) -> "Jawaban Tugas Terbimbing:"
                q.tipeSoal.contains("Studi Kasus", ignoreCase = true) -> "Jawaban Analisis Studi Kasus:"
                else -> "Jawaban Uraian / Esai:"
            }
            binding.nsvOptionsWrapper.visibility = View.GONE
            binding.layoutOptionsContainer.visibility = View.GONE
            binding.tilEssayContainer.visibility = View.VISIBLE

            activeEssayWatcher?.let { binding.etEssayAnswer.removeTextChangedListener(it) }
            val savedText = q.jawabanTersimpan?.jawabanTeks ?: ""
            binding.etEssayAnswer.setText(savedText)

            val watcher = object : TextWatcher {
                override fun beforeTextChanged(s: CharSequence?, start: Int, count: Int, after: Int) {}
                override fun onTextChanged(s: CharSequence?, start: Int, before: Int, count: Int) {}
                override fun afterTextChanged(s: Editable?) {
                    val text = s?.toString() ?: ""
                    if (q.jawabanTersimpan == null) {
                        q.jawabanTersimpan = SavedAnswer()
                    }
                    q.jawabanTersimpan?.jawabanTeks = text
                    updateExamProgress()

                    debounceHandler.removeCallbacksAndMessages(null)
                    debounceRunnable = Runnable {
                        saveAnswerToServer(q, null, null, text, q.jawabanTersimpan?.isRagu ?: 0)
                    }
                    debounceHandler.postDelayed(debounceRunnable!!, 800)
                }
            }
            activeEssayWatcher = watcher
            binding.etEssayAnswer.addTextChangedListener(watcher)
        } else {
            binding.cardFixedOptions.visibility = View.VISIBLE
            binding.tvFixedOptionsTitle.text = "Pilihan Jawaban:"
            binding.nsvOptionsWrapper.visibility = View.VISIBLE
            binding.tilEssayContainer.visibility = View.GONE
            binding.layoutOptionsContainer.visibility = View.VISIBLE
            binding.layoutOptionsContainer.removeAllViews()

            val selectedOptionId = q.jawabanTersimpan?.idOpsiDipilih
            val inflater = LayoutInflater.from(this)

            for ((index, opt) in q.opsi.withIndex()) {
                val optView = inflater.inflate(R.layout.item_exam_option, binding.layoutOptionsContainer, false)
                val card = optView.findViewById<MaterialCardView>(R.id.cardOption)
                val tvKey = optView.findViewById<TextView>(R.id.tvOptionKey)
                val tvText = optView.findViewById<TextView>(R.id.tvOptionText)
                val imgCheck = optView.findViewById<ImageView>(R.id.imgOptionCheck)

                val displayLabel = ('A' + index).toString()
                tvKey.text = displayLabel
                tvText.text = opt.teksOpsi

                val isSelected = (selectedOptionId != null && selectedOptionId == opt.id)
                if (isSelected) {
                    card.setCardBackgroundColor(Color.parseColor("#F0FDF9"))
                    card.strokeColor = ContextCompat.getColor(this, R.color.lumina_teal)
                    card.strokeWidth = dpToPx(2f)
                    tvKey.setBackgroundResource(R.drawable.bg_key_circle_selected)
                    tvKey.setTextColor(Color.WHITE)
                    imgCheck.visibility = View.VISIBLE
                } else {
                    card.setCardBackgroundColor(Color.WHITE)
                    card.strokeColor = Color.parseColor("#E2E8F0")
                    card.strokeWidth = dpToPx(1.5f)
                    tvKey.setBackgroundResource(R.drawable.bg_key_circle)
                    tvKey.setTextColor(ContextCompat.getColor(this, R.color.lumina_navy))
                    imgCheck.visibility = View.GONE
                }

                card.setOnClickListener {
                    selectOption(q, opt)
                }

                binding.layoutOptionsContainer.addView(optView)
            }
        }
    }

    private fun selectOption(q: QuestionItem, opt: OptionItem) {
        if (q.jawabanTersimpan == null) {
            q.jawabanTersimpan = SavedAnswer()
        }
        q.jawabanTersimpan?.idOpsiDipilih = opt.id
        q.jawabanTersimpan?.kodeOpsiDipilih = opt.kodeOpsi

        // Update UI secara langsung tanpa menghapus views / flashing
        updateOptionsUIInPlace(q)
        updateExamProgress()

        saveAnswerToServer(
            q = q,
            optionId = opt.id,
            optionCode = opt.kodeOpsi,
            jawabanTeks = null,
            isRagu = q.jawabanTersimpan?.isRagu ?: 0
        )
    }

    private fun updateOptionsUIInPlace(q: QuestionItem) {
        val selectedOptionId = q.jawabanTersimpan?.idOpsiDipilih
        val container = binding.layoutOptionsContainer

        for (i in 0 until container.childCount) {
            val optView = container.getChildAt(i)
            val card = optView.findViewById<MaterialCardView>(R.id.cardOption)
            val tvKey = optView.findViewById<TextView>(R.id.tvOptionKey)
            val imgCheck = optView.findViewById<ImageView>(R.id.imgOptionCheck)
            val opt = q.opsi.getOrNull(i) ?: continue

            val displayLabel = ('A' + i).toString()
            tvKey.text = displayLabel

            val isSelected = (selectedOptionId != null && selectedOptionId == opt.id)
            if (isSelected) {
                card.setCardBackgroundColor(Color.parseColor("#F0FDF9"))
                card.strokeColor = ContextCompat.getColor(this, R.color.lumina_teal)
                card.strokeWidth = dpToPx(2f)
                tvKey.setBackgroundResource(R.drawable.bg_key_circle_selected)
                tvKey.setTextColor(Color.WHITE)

                if (imgCheck.visibility != View.VISIBLE) {
                    imgCheck.visibility = View.VISIBLE
                    imgCheck.scaleX = 0f
                    imgCheck.scaleY = 0f
                    imgCheck.rotation = -45f
                    imgCheck.animate().scaleX(1f).scaleY(1f).rotation(0f).setDuration(250).start()
                }

                card.animate().scaleX(1.015f).scaleY(1.015f).setDuration(100).withEndAction {
                    card.animate().scaleX(1f).scaleY(1f).setDuration(100).start()
                }.start()
            } else {
                card.setCardBackgroundColor(Color.WHITE)
                card.strokeColor = Color.parseColor("#E2E8F0")
                card.strokeWidth = dpToPx(1.5f)
                tvKey.setBackgroundResource(R.drawable.bg_key_circle)
                tvKey.setTextColor(ContextCompat.getColor(this, R.color.lumina_navy))
                imgCheck.visibility = View.GONE
                card.scaleX = 1f
                card.scaleY = 1f
            }
        }
    }

    private fun toggleRaguRagu() {
        if (questions.isEmpty()) return
        val q = questions[currentIndex]
        if (q.jawabanTersimpan == null) {
            q.jawabanTersimpan = SavedAnswer()
        }

        val curRagu = (q.jawabanTersimpan?.isRagu == 1)
        val newRagu = if (curRagu) 0 else 1
        q.jawabanTersimpan?.isRagu = newRagu

        updateRaguButtonState(newRagu == 1)

        binding.btnToggleRagu.animate().scaleX(1.1f).scaleY(1.1f).setDuration(100).withEndAction {
            binding.btnToggleRagu.animate().scaleX(1f).scaleY(1f).setDuration(100).start()
        }.start()

        saveAnswerToServer(
            q = q,
            optionId = q.jawabanTersimpan?.idOpsiDipilih,
            optionCode = q.jawabanTersimpan?.kodeOpsiDipilih,
            jawabanTeks = q.jawabanTersimpan?.jawabanTeks,
            isRagu = newRagu
        )
    }

    private fun updateRaguButtonState(isRagu: Boolean) {
        if (isRagu) {
            binding.btnToggleRagu.setBackgroundColor(ContextCompat.getColor(this, R.color.lumina_gold))
            binding.btnToggleRagu.setTextColor(Color.WHITE)
            binding.btnToggleRagu.iconTint = ContextCompat.getColorStateList(this, R.color.white)
        } else {
            binding.btnToggleRagu.setBackgroundColor(Color.TRANSPARENT)
            binding.btnToggleRagu.setTextColor(ContextCompat.getColor(this, R.color.lumina_gold_dark))
            binding.btnToggleRagu.iconTint = ContextCompat.getColorStateList(this, R.color.lumina_gold_dark)
        }
    }

    private fun saveAnswerToServer(
        q: QuestionItem,
        optionId: Int?,
        optionCode: String?,
        jawabanTeks: String?,
        isRagu: Int
    ) {
        CbtApiClient.saveAnswer(
            baseUrl = serverUrl,
            session = session,
            soalId = q.id,
            nomorUrut = q.nomorUrut,
            tipeSoal = q.tipeSoal,
            optionId = optionId,
            optionCode = optionCode,
            jawabanTeks = jawabanTeks,
            isRagu = isRagu,
            sisaDetik = session.sisaWaktuDetik
        )
    }

    // --------------------------------------------------------------------------
    // TIMER ENGINE (AKURAT BERBASIS REAL SYSTEM CLOCK — BEBAS DRIFT & HEMAT SERVER)
    // --------------------------------------------------------------------------
    private var examEndTimeMs: Long = 0L

    private fun startTimer() {
        examEndTimeMs = System.currentTimeMillis() + (session.sisaWaktuDetik * 1000L)
        updateTimerDisplay()
        timerRunnable = object : Runnable {
            override fun run() {
                val remainingMs = examEndTimeMs - System.currentTimeMillis()
                session.sisaWaktuDetik = Math.max(0, (remainingMs / 1000L).toInt())
                updateTimerDisplay()

                // Heartbeat sync & lazy-load live database update (setiap 5 detik)
                if (session.sisaWaktuDetik % 5 == 0 && session.sisaWaktuDetik > 0) {
                    CbtApiClient.syncTimer(serverUrl, session.idCbtSesi, session.sisaWaktuDetik) { serverSisa, isFrozen, statusSesi ->
                        if (statusSesi != null && statusSesi.equals("selesai", ignoreCase = true)) {
                            onTimerFinished()
                            return@syncTimer
                        }
                        if (isFrozen) {
                            if (!isSessionFrozen) {
                                handleLeaveAppViolation("Dibekukan oleh Pengawas / Server")
                            }
                            return@syncTimer
                        } else if (isSessionFrozen) {
                            // Jika Pengawas/Admin telah membuka kunci (Unfreeze) dari dashboard web, otomatis pulihkan layar ujian
                            isSessionFrozen = false
                            tvFreezeTimerView = null
                            freezeDialog?.dismiss()
                            freezeDialog = null
                            binding.cardQuestionScrollable.visibility = View.VISIBLE
                            binding.cardFixedOptions.visibility = View.VISIBLE
                            binding.layoutBottomActions.visibility = View.VISIBLE
                            Toast.makeText(this@ExamActivity, "Kunci sesi telah dibuka oleh Pengawas. Silakan lanjutkan ujian.", Toast.LENGTH_LONG).show()
                        }
                        // Sinkronisasi otomatis jika ada perubahan waktuMulai/waktuSelesai/durasi di database oleh admin
                        if (Math.abs(serverSisa - session.sisaWaktuDetik) > 3) {
                            session.sisaWaktuDetik = serverSisa
                            examEndTimeMs = System.currentTimeMillis() + (serverSisa * 1000L)
                            updateTimerDisplay()
                        }
                    }
                }

                if (session.sisaWaktuDetik <= 0) {
                    onTimerFinished()
                } else {
                    timerHandler.postDelayed(this, 1000)
                }
            }
        }
        timerHandler.postDelayed(timerRunnable!!, 1000)
    }

    private fun updateTimerDisplay() {
        val s = Math.max(0, session.sisaWaktuDetik)
        val h = s / 3600
        val m = (s % 3600) / 60
        val sec = s % 60
        val formatted = String.format("%02d:%02d:%02d", h, m, sec)
        binding.tvCountdownTimer.text = formatted
        tvFreezeTimerView?.text = formatted

        if (s <= 180) {
            binding.layoutTimerPill.setBackgroundColor(Color.parseColor("#B91C1C"))
        } else if (s <= 600) {
            binding.layoutTimerPill.setBackgroundColor(Color.parseColor("#D97706"))
        } else {
            binding.layoutTimerPill.setBackgroundResource(R.drawable.bg_card_rounded)
            binding.layoutTimerPill.backgroundTintList = ContextCompat.getColorStateList(this, R.color.white)?.withAlpha(40)
        }
    }

    private fun onTimerFinished() {
        isInternalDialogOpen = true
        timerHandler.removeCallbacksAndMessages(null)
        tvFreezeTimerView = null
        freezeDialog?.dismiss()
        freezeDialog = null
        // Otomatis kumpulkan jawaban saat waktu habis (termasuk saat sesi sedang Freeze)
        submitFinishExam(isAutoTimeUp = true)
    }

    // --------------------------------------------------------------------------
    // QUESTION GRID DIALOG & SUBMISSION
    // --------------------------------------------------------------------------
    private fun showQuestionGridDialog() {
        isInternalDialogOpen = true
        val dialogView = LayoutInflater.from(this).inflate(R.layout.dialog_question_grid, null)
        val rv = dialogView.findViewById<RecyclerView>(R.id.rvQuestionGrid)
        val btnClose = dialogView.findViewById<View>(R.id.btnCloseGrid)
        val btnSubmit = dialogView.findViewById<MaterialButton>(R.id.btnSubmitExamFromGrid)

        rv.layoutManager = GridLayoutManager(this, 5)
        var dialog: androidx.appcompat.app.AlertDialog? = null

        val adapter = QuestionGridAdapter(questions, currentIndex) { selectedPos ->
            currentIndex = selectedPos
            renderCurrentQuestion(animate = true)
            dialog?.dismiss()
        }
        rv.adapter = adapter

        dialog = MaterialAlertDialogBuilder(this)
            .setView(dialogView)
            .setCancelable(true)
            .setOnDismissListener {
                if (!isSubmittingExam) {
                    isInternalDialogOpen = false
                }
            }
            .show()

        btnClose.setOnClickListener {
            dialog.dismiss()
        }

        btnSubmit.setOnClickListener {
            isSubmittingExam = true
            dialog.dismiss()
            confirmSubmitExam()
        }
    }

    private fun confirmSubmitExam() {
        isInternalDialogOpen = true
        isSubmittingExam = true
        var answered = 0
        var ragu = 0
        for (q in questions) {
            val hasAns = q.jawabanTersimpan != null && 
                (q.jawabanTersimpan?.idOpsiDipilih != null || !q.jawabanTersimpan?.jawabanTeks.isNullOrBlank())
            if (hasAns) answered++
            if (q.jawabanTersimpan?.isRagu == 1) ragu++
        }
        val unanswered = questions.size - answered

        val dialogView = LayoutInflater.from(this).inflate(R.layout.dialog_confirm_submit, null)
        val tvStats = dialogView.findViewById<TextView>(R.id.tvSubmitSummaryStats)
        val btnBack = dialogView.findViewById<MaterialButton>(R.id.btnBackToExam)
        val btnConfirm = dialogView.findViewById<MaterialButton>(R.id.btnConfirmSubmitFinal)

        val statsMsg = StringBuilder()
        statsMsg.append("✅ Anda telah menjawab <b>$answered</b> dari <b>${questions.size}</b> soal.")
        if (ragu > 0) statsMsg.append("<br>⚠️ Masih ada <b>$ragu</b> soal bertanda RAGU-RAGU.")
        if (unanswered > 0) statsMsg.append("<br>❌ Masih ada <b>$unanswered</b> soal yang BELUM DIJAWAB.")

        if (android.os.Build.VERSION.SDK_INT >= android.os.Build.VERSION_CODES.N) {
            tvStats.text = Html.fromHtml(statsMsg.toString(), Html.FROM_HTML_MODE_COMPACT)
        } else {
            @Suppress("DEPRECATION")
            tvStats.text = Html.fromHtml(statsMsg.toString())
        }

        val dialog = MaterialAlertDialogBuilder(this)
            .setView(dialogView)
            .setCancelable(true)
            .setOnDismissListener {
                isInternalDialogOpen = false
            }
            .create()

        dialog.window?.setBackgroundDrawable(android.graphics.drawable.ColorDrawable(Color.TRANSPARENT))

        btnBack.setOnClickListener {
            isSubmittingExam = false
            dialog.dismiss()
        }

        btnConfirm.setOnClickListener {
            isSubmittingExam = true
            dialog.dismiss()
            submitFinishExam(isAutoTimeUp = false)
        }

        dialog.show()
    }

    private fun submitFinishExam(isAutoTimeUp: Boolean = false) {
        isSubmittingExam = true
        debounceHandler.removeCallbacksAndMessages(null)
        timerHandler.removeCallbacksAndMessages(null)
        binding.pbExamLoading.visibility = View.VISIBLE

        CbtApiClient.finishExam(
            baseUrl = serverUrl,
            session = session,
            questions = questions,
            onSuccess = {
                binding.pbExamLoading.visibility = View.GONE

                val dialogView = LayoutInflater.from(this).inflate(R.layout.dialog_time_up, null)
                val tvTitle = dialogView.findViewById<TextView>(R.id.tvTimeUpTitle)
                val tvMessage = dialogView.findViewById<TextView>(R.id.tvTimeUpMessage)
                val btnExit = dialogView.findViewById<MaterialButton>(R.id.btnTimeUpExit)

                if (isAutoTimeUp) {
                    tvTitle.text = "⏰ Waktu Ujian Telah Habis!"
                    tvMessage.text = "Durasi pengerjaan telah berakhir. Seluruh jawaban Anda telah tersimpan dan dikumpulkan secara otomatis oleh sistem.\n\nTerima kasih atas partisipasi Anda."
                } else {
                    tvTitle.text = "🎉 Ujian Berhasil Dikumpulkan!"
                    tvMessage.text = "Alhamdulillah, ujian Anda telah berhasil diselesaikan.\n\nSeluruh jawaban Anda telah tersimpan dan dikumpulkan oleh sistem.\n\nTerima kasih atas partisipasi Anda."
                }

                val dialog = MaterialAlertDialogBuilder(this)
                    .setView(dialogView)
                    .setCancelable(false)
                    .create()

                dialog.window?.setBackgroundDrawable(android.graphics.drawable.ColorDrawable(Color.TRANSPARENT))

                btnExit.setOnClickListener {
                    isSupervisorExiting = true
                    dialog.dismiss()
                    startActivity(Intent(this, SplashActivity::class.java))
                    @Suppress("DEPRECATION")
                    overridePendingTransition(R.anim.anim_fade_in, R.anim.anim_fade_out)
                    finish()
                }

                dialog.show()
            },
            onError = { err ->
                binding.pbExamLoading.visibility = View.GONE
                showLuminaDialog("Gagal Mengumpulkan", err)
            }
        )
    }

    // --------------------------------------------------------------------------
    // KIOSK SUPERVISOR EXIT & ANTI-CHEAT FREEZE
    // --------------------------------------------------------------------------
    private fun showExitSupervisorDialog() {
        isInternalDialogOpen = true
        val etPin = EditText(this).apply {
            hint = "PIN Pengawas (Default: 123456)"
            inputType = android.text.InputType.TYPE_CLASS_NUMBER or android.text.InputType.TYPE_NUMBER_VARIATION_PASSWORD
        }

        MaterialAlertDialogBuilder(this)
            .setTitle("Buka Kunci Kiosk")
            .setMessage("Masukkan PIN Pengawas untuk keluar dari mode ujian:")
            .setView(etPin)
            .setPositiveButton("Buka Kunci") { _, _ ->
                if (etPin.text.toString().trim() == ExamLoginActivity.SUPERVISOR_PIN) {
                    isSupervisorExiting = true
                    Toast.makeText(this, "Mode Kiosk Dibuka oleh Pengawas.", Toast.LENGTH_SHORT).show()
                    startActivity(Intent(this, SplashActivity::class.java))
                    @Suppress("DEPRECATION")
                    overridePendingTransition(R.anim.anim_fade_in, R.anim.anim_fade_out)
                    finish()
                } else {
                    Toast.makeText(this, "PIN Pengawas Salah!", Toast.LENGTH_SHORT).show()
                }
            }
            .setNegativeButton("Batal", null)
            .setOnDismissListener {
                isInternalDialogOpen = false
            }
            .show()
    }

    override fun onUserLeaveHint() {
        super.onUserLeaveHint()
        if (hasInitialFocusGained && !isSupervisorExiting && !isSubmittingExam && !isSessionFrozen && !isInternalDialogOpen && !isFinishing) {
            handleLeaveAppViolation("Menekan Tombol Home / Navigasi Keluar")
        }
    }

    override fun onPause() {
        super.onPause()
        if (hasInitialFocusGained && !isSupervisorExiting && !isSubmittingExam && !isSessionFrozen && !isInternalDialogOpen && !isFinishing) {
            handleLeaveAppViolation("Aplikasi Berpindah ke Background / Keluar Layar")
        }
    }

    override fun onResume() {
        super.onResume()
        SecurityHelper.enableKioskMode(this)
        SecurityHelper.enableDoNotDisturbMode(this)
    }

    override fun onWindowFocusChanged(hasFocus: Boolean) {
        super.onWindowFocusChanged(hasFocus)
        if (hasFocus) {
            hasInitialFocusGained = true
            isInternalDialogOpen = false
            SecurityHelper.enableKioskMode(this)
            SecurityHelper.enableDoNotDisturbMode(this)
            if (isSessionFrozen && !isSubmittingExam) {
                showSessionFrozenDialog("Membuka Bar Notifikasi / Pengaturan Cepat / Navigasi")
            }
        } else {
            if (hasInitialFocusGained && !isSupervisorExiting && !isSubmittingExam && !isSessionFrozen && !isInternalDialogOpen && !isFinishing) {
                handleLeaveAppViolation("Membuka Bar Notifikasi / Pengaturan Cepat / Navigasi")
            }
        }
    }

    override fun onMultiWindowModeChanged(isInMultiWindowMode: Boolean) {
        super.onMultiWindowModeChanged(isInMultiWindowMode)
        if (isInMultiWindowMode && hasInitialFocusGained && !isSupervisorExiting && !isSubmittingExam && !isSessionFrozen && !isFinishing) {
            handleLeaveAppViolation("Membuka Mini Apps / Mode Layar Belah (Split Screen)")
        }
    }

    override fun onPictureInPictureModeChanged(isInPictureInPictureMode: Boolean) {
        super.onPictureInPictureModeChanged(isInPictureInPictureMode)
        if (isInPictureInPictureMode && hasInitialFocusGained && !isSupervisorExiting && !isSubmittingExam && !isSessionFrozen && !isFinishing) {
            handleLeaveAppViolation("Membuka Jendela Mini / Picture-in-Picture")
        }
    }

    private fun handleLeaveAppViolation(reason: String = "Keluar Aplikasi / Peralihan Layar") {
        if (isSessionFrozen || isSupervisorExiting || isSubmittingExam || isFinishing) return
        isSessionFrozen = true

        // 1. Langsung sembunyikan lembar soal & kontrol jawaban dari layar
        binding.cardQuestionScrollable.visibility = View.INVISIBLE
        binding.cardFixedOptions.visibility = View.INVISIBLE
        binding.layoutBottomActions.visibility = View.INVISIBLE

        // 2. Simpan jawaban aktif saat ini jika ada
        if (questions.isNotEmpty() && currentIndex in questions.indices) {
            val q = questions[currentIndex]
            saveAnswerToServer(
                q = q,
                optionId = q.jawabanTersimpan?.idOpsiDipilih,
                optionCode = q.jawabanTersimpan?.kodeOpsiDipilih,
                jawabanTeks = q.jawabanTersimpan?.jawabanTeks,
                isRagu = q.jawabanTersimpan?.isRagu ?: 0
            )
        }

        // 4. Kirim sinyal freeze ke backend dengan alasan spesifik
        CbtApiClient.freezeSession(
            baseUrl = serverUrl,
            session = session,
            reason = reason
        )

        // 5. Tampilkan dialog freeze langsung di layar
        showSessionFrozenDialog(reason)
    }

    private var freezeDialog: androidx.appcompat.app.AlertDialog? = null
    private var tvFreezeTimerView: TextView? = null

    private fun showSessionFrozenDialog(reason: String = "Keluar Aplikasi / Peralihan Layar") {
        if (isFinishing || isDestroyed) return
        if (freezeDialog?.isShowing == true) return

        binding.cardQuestionScrollable.visibility = View.INVISIBLE
        binding.cardFixedOptions.visibility = View.INVISIBLE
        binding.layoutBottomActions.visibility = View.INVISIBLE

        val dialogView = LayoutInflater.from(this).inflate(R.layout.dialog_session_frozen, null)
        val tvReason = dialogView.findViewById<TextView>(R.id.tvFreezeReason)
        val tvFreezeTime = dialogView.findViewById<TextView>(R.id.tvFreezeRemainingTime)
        val btnExit = dialogView.findViewById<View>(R.id.btnFreezeExit)

        tvReason.text = reason
        tvFreezeTimerView = tvFreezeTime
        updateTimerDisplay()

        val dialog = MaterialAlertDialogBuilder(this)
            .setView(dialogView)
            .setCancelable(false)
            .create()

        btnExit.setOnClickListener {
            isSupervisorExiting = true
            dialog.dismiss()
            startActivity(Intent(this, ExamLoginActivity::class.java))
            @Suppress("DEPRECATION")
            overridePendingTransition(R.anim.anim_fade_in, R.anim.anim_fade_out)
            finish()
        }

        dialog.window?.setBackgroundDrawable(android.graphics.drawable.ColorDrawable(Color.TRANSPARENT))
        freezeDialog = dialog
        dialog.show()
    }

    private fun extractYouTubeVideoId(text: String?): String? {
        if (text.isNullOrBlank()) return null
        val pattern = "(?:youtube\\.com\\/(?:[^\\/]+\\/.+\\/|(?:v|e(?:mbed)?)\\/" +
                "|.*[?&]v=)|youtu\\.be\\/)([^\"&?\\/\\s]{11})"
        val compiledPattern = java.util.regex.Pattern.compile(pattern, java.util.regex.Pattern.CASE_INSENSITIVE)
        val matcher = compiledPattern.matcher(text)
        return if (matcher.find()) matcher.group(1) else null
    }

    private fun showLargeImagePreview(drawable: android.graphics.drawable.Drawable) {
        isInternalDialogOpen = true
        val dialogView = LayoutInflater.from(this).inflate(R.layout.dialog_image_preview, null)
        val imgPreview = dialogView.findViewById<ImageView>(R.id.imgPreviewLarge)
        val btnClose = dialogView.findViewById<View>(R.id.btnCloseImagePreview)

        imgPreview.setImageDrawable(drawable)

        val dialog = MaterialAlertDialogBuilder(this)
            .setView(dialogView)
            .setCancelable(true)
            .setOnDismissListener {
                if (!isSubmittingExam) {
                    isInternalDialogOpen = false
                }
            }
            .create()

        btnClose.setOnClickListener {
            dialog.dismiss()
        }

        dialog.window?.setBackgroundDrawable(android.graphics.drawable.ColorDrawable(Color.TRANSPARENT))
        dialog.show()
    }

    private fun loadImageAsync(imageUrl: String, target: ImageView) {
        val cached = imageCache.get(imageUrl)
        if (cached != null) {
            target.setImageBitmap(cached)
            return
        }

        target.tag = imageUrl
        target.setImageResource(android.R.drawable.ic_menu_gallery)

        thread {
            try {
                val url = java.net.URL(imageUrl)
                val conn = url.openConnection() as java.net.HttpURLConnection
                conn.connectTimeout = 8000
                conn.readTimeout = 12000
                conn.instanceFollowRedirects = true
                conn.connect()
                if (conn.responseCode in 200..299) {
                    val stream = conn.inputStream
                    val bitmap = BitmapFactory.decodeStream(stream)
                    stream.close()
                    conn.disconnect()
                    if (bitmap != null) {
                        imageCache.put(imageUrl, bitmap)
                        runOnUiThread {
                            if (target.tag == imageUrl) {
                                target.setImageBitmap(bitmap)
                            }
                        }
                    }
                }
            } catch (_: Exception) {}
        }
    }

    @Deprecated("Deprecated in Java")
    override fun onBackPressed() {
        Toast.makeText(this, "Aplikasi dalam mode Kiosk Ujian. Selesaikan ujian melalui sistem CBT.", Toast.LENGTH_SHORT).show()
    }

    private fun showLuminaDialog(title: String, message: String) {
        val dialogView = LayoutInflater.from(this).inflate(R.layout.dialog_lumina_alert, null)
        val tvTitle = dialogView.findViewById<TextView>(R.id.tvAlertTitle)
        val tvMessage = dialogView.findViewById<TextView>(R.id.tvAlertMessage)
        val btnOk = dialogView.findViewById<MaterialButton>(R.id.btnAlertOk)

        tvTitle.text = title
        tvMessage.text = message

        val dialog = MaterialAlertDialogBuilder(this)
            .setView(dialogView)
            .setCancelable(true)
            .create()

        btnOk.setOnClickListener {
            dialog.dismiss()
        }

        dialog.window?.setBackgroundDrawable(android.graphics.drawable.ColorDrawable(Color.TRANSPARENT))
        dialog.show()
    }

    private fun dpToPx(dp: Float): Int {
        val density = resources.displayMetrics.density
        return (dp * density).toInt()
    }

    override fun onDestroy() {
        super.onDestroy()
        try {
            unregisterReceiver(screenOffReceiver)
        } catch (_: Exception) {}
        try {
            unregisterReceiver(phoneCallReceiver)
        } catch (_: Exception) {}
        SecurityHelper.disableDoNotDisturbMode(this)
        timerHandler.removeCallbacksAndMessages(null)
        debounceHandler.removeCallbacksAndMessages(null)
    }
}
