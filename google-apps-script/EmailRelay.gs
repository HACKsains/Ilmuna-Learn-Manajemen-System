/**
 * ==============================================================================
 * ILMUNA LEARN — GOOGLE APPS SCRIPT WEB APP FOR EMAIL & KARTU UJIAN PDF A5 RELAY
 * ==============================================================================
 * Versi : 2.0-pdf-a5
 * Fungsi:
 * 1. Pengiriman Email HTML resmi Ilmuna Learn (kredensial akun, verifikasi,
 *    reset password, notifikasi) — 100% kompatibel dengan payload Relay v1.0.
 * 2. Konversi HTML Kartu Peserta Ujian (Formatif, Diagnostik, Remedial/Pengayaan)
 *    menjadi berkas PDF A5 langsung di server Google (tanpa perlu Headless Chrome
 *    atau port SMTP 587/465 di shared hosting cPanel!).
 * 3. Mendukung lampiran berkas Base64 (`attachments`) dan gambar inline CID
 *    (`inline_images`, mis. `cid:ilmuna_logo`) agar logo tampil sempurna di Gmail.
 * 4. Mendukung endpoint health-check (`doGet`) dan pengecekan sisa kuota harian
 *    Gmail (`remaining_quota`).
 *
 * PANDUAN PEMBARUAN (RE-DEPLOY) DI GOOGLE APPS SCRIPT:
 * 1. Buka proyek Google Apps Script Relay Anda di https://script.google.com/
 *    (proyek yang URL-nya terpasang di `ILMUNA_GOOGLE_RELAY_URL` pada config.php).
 * 2. Hapus seluruh kode lama di `Code.gs`, lalu tempel SELURUH kode di file ini.
 * 3. Klik Simpan (Ctrl + S).
 * 4. WAJIB: Pilih fungsi `testAuth` pada dropdown toolbar lalu klik **"Run" (Jalankan)**.
 *    Saat muncul popup "Authorization required / Otorisasi diperlukan", klik
 *    **Review permissions > pilih akun Google > Advanced (Lanjutan) > Go to ... (unsafe) > Allow (Izinkan)**
 *    agar izin `https://www.googleapis.com/auth/script.send_mail` aktif!
 * 5. Klik tombol biru "Deploy" (Terapkan) > "Manage deployments" (Kelola penerapan).
 * 6. Klik ikon pensil (Edit) pada deployment Web App yang sedang aktif.
 * 7. Pastikan konfigurasi:
 *    - Version: **"New version"** (Versi baru), deskripsi misal "v2.0 PDF A5 Kartu Ujian"
 *    - Execute as (Jalankan sebagai): **"Me"** (email akun Google Anda — WAJIB!)
 *    - Who has access (Yang memiliki akses): **"Anyone"** (Siapa saja)
 *    Lalu klik **"Deploy"**. (URL `/exec` TETAP SAMA, tidak perlu ubah config.php!)
 * 8. Buka URL `/exec` di browser untuk verifikasi — akan muncul JSON:
 *    `{"success":true,"status":"online","version":"2.0-pdf-a5","remaining_quota":100,...}`.
 * ==============================================================================
 */

var ILMUNA_RELAY_TOKEN   = 'IlmunaBypass2026';
var ILMUNA_RELAY_VERSION = '2.0-pdf-a5';

/**
 * WAJIB jalankan fungsi ini sekali di editor Apps Script agar Google memunculkan
 * dialog otorisasi izin `MailApp.sendEmail` (script.send_mail) & cek kuota harian.
 */
function testAuth() {
  var quota = MailApp.getRemainingDailyQuota();
  var testPdf = Utilities.newBlob(
    '<!DOCTYPE html><html><head><meta charset="UTF-8"><style>@page{size:148mm 210mm;margin:8mm;}</style></head><body><h3>Ilmuna Learn PDF A5 Test OK</h3></body></html>',
    'text/html',
    'test_kartu_a5.html'
  ).getAs('application/pdf').setName('test_kartu_a5.pdf');

  Logger.log('Ilmuna Email Relay ' + ILMUNA_RELAY_VERSION + ' siap digunakan.');
  Logger.log('Sisa kuota email harian: ' + quota);
  Logger.log('Ukuran PDF uji coba: ' + testPdf.getBytes().length + ' bytes');
  return 'OK | Versi: ' + ILMUNA_RELAY_VERSION + ' | Sisa Kuota: ' + quota + ' | PDF Bytes: ' + testPdf.getBytes().length;
}

/**
 * Endpoint GET untuk cek status online & versi Relay dari browser / email-debug.php.
 */
function doGet(e) {
  return buildJsonResponse({
    success: true,
    status: 'online',
    service: 'Ilmuna Learn Email & PDF A5 Relay',
    version: ILMUNA_RELAY_VERSION,
    remaining_quota: safeGetRemainingQuota(),
    features: ['html_email', 'html_to_pdf_a5', 'base64_attachments', 'inline_images', 'batch_send']
  });
}

/**
 * Endpoint POST utama untuk menerima permintaan kirim email / generate PDF dari Ilmuna LMS.
 */
function doPost(e) {
  try {
    if (!e || !e.postData || !e.postData.contents) {
      return buildJsonResponse({
        success: false,
        message: 'Payload POST kosong.'
      });
    }

    var payload;
    try {
      payload = JSON.parse(e.postData.contents);
    } catch (parseErr) {
      return buildJsonResponse({
        success: false,
        message: 'Format JSON payload tidak valid: ' + parseErr.toString()
      });
    }

    if (!payload || payload.token !== ILMUNA_RELAY_TOKEN) {
      return buildJsonResponse({
        success: false,
        message: 'Token otorisasi Google Relay tidak valid.'
      });
    }

    var action = String(payload.action || 'send_email').toLowerCase();

    // Mode 1: Generate PDF A5 saja (mengembalikan Base64 PDF ke server PHP)
    if (action === 'generate_pdf') {
      return handleGeneratePdfOnly(payload);
    }

    // Mode 2: Kirim batch kecil (opsional, maksimal 10 item per request)
    if (action === 'send_batch' && Array.isArray(payload.items)) {
      return handleBatchSend(payload.items);
    }

    // Mode 3 (Default): Kirim 1 email (kompatibel penuh dengan Relay v1.0 + dukungan PDF A5)
    var singleResult = processSingleEmail(payload);
    return buildJsonResponse(singleResult);

  } catch (err) {
    return buildJsonResponse({
      success: false,
      message: 'Kesalahan internal Google Apps Script Relay: ' + err.toString(),
      relay_version: ILMUNA_RELAY_VERSION
    });
  }
}

/**
 * Konversi HTML Kartu Ujian menjadi PDF tanpa mengirim email (opsional).
 */
function handleGeneratePdfOnly(payload) {
  var pdfHtml = String(payload.pdf_html || '').trim();
  if (!pdfHtml) {
    return buildJsonResponse({
      success: false,
      message: 'Parameter pdf_html tidak boleh kosong.'
    });
  }

  try {
    var pdfName = sanitizePdfFilename(payload.pdf_filename || 'Kartu_Peserta_Ujian_A5.pdf');
    var pdfBlob = convertHtmlToPdfBlob(pdfHtml, pdfName);
    var bytes   = pdfBlob.getBytes();

    return buildJsonResponse({
      success: true,
      message: 'PDF A5 berhasil dibuat di server Google.',
      pdf_filename: pdfName,
      size_bytes: bytes.length,
      pdf_base64: Utilities.base64Encode(bytes),
      relay_version: ILMUNA_RELAY_VERSION
    });
  } catch (err) {
    return buildJsonResponse({
      success: false,
      message: 'Gagal mengonversi HTML menjadi PDF A5: ' + err.toString(),
      relay_version: ILMUNA_RELAY_VERSION
    });
  }
}

/**
 * Pengiriman batch terbatas (maks. 10 item per request agar bebas timeout GAS).
 */
function handleBatchSend(items) {
  var maxItems = Math.min(items.length, 10);
  var results  = [];
  var sent     = 0;
  var failed   = 0;

  for (var i = 0; i < maxItems; i++) {
    var res = processSingleEmail(items[i] || {});
    results.push(res);
    if (res.success) {
      sent++;
    } else {
      failed++;
    }
  }

  return buildJsonResponse({
    success: sent > 0,
    message: 'Batch selesai: ' + sent + ' berhasil, ' + failed + ' gagal.',
    sent: sent,
    failed: failed,
    results: results,
    relay_version: ILMUNA_RELAY_VERSION,
    remaining_quota: safeGetRemainingQuota()
  });
}

/**
 * Proses pengiriman 1 email (HTML + opsional PDF A5 + lampiran Base64 + inline CID images).
 */
function processSingleEmail(payload) {
  var to = String(payload.to || '').trim();
  if (!to || to.indexOf('@') === -1) {
    return {
      success: false,
      message: 'Alamat email tujuan tidak valid: ' + to,
      relay_version: ILMUNA_RELAY_VERSION
    };
  }

  var quotaBefore = safeGetRemainingQuota();
  if (quotaBefore !== null && quotaBefore <= 0) {
    return {
      success: false,
      to: to,
      message: 'Kuota pengiriman harian Gmail Apps Script telah habis (0 tersisa). Coba lagi setelah reset kuota harian Google.',
      relay_version: ILMUNA_RELAY_VERSION,
      remaining_quota: 0
    };
  }

  var subject  = String(payload.subject || 'Notifikasi Ilmuna Learn').trim();
  var htmlBody = String(payload.html || payload.body_html || payload.body || '');
  var fromName = String(payload.from_name || 'Ilmuna Learn').trim();
  var replyTo  = String(payload.reply_to || '').trim();

  var mailAttachments = [];
  var pdfAttached     = false;
  var pdfSource       = null;
  var finalPdfName    = null;

  // 1. Decode lampiran Base64 jika dikirim oleh server PHP (mis. PDF lokal dari Laragon)
  if (Array.isArray(payload.attachments) && payload.attachments.length > 0) {
    for (var i = 0; i < payload.attachments.length; i++) {
      var att = payload.attachments[i];
      if (!att || !att.contentBase64) continue;
      try {
        var attMime = String(att.mimeType || att.mime || 'application/octet-stream');
        var attName = String(att.filename || att.name || ('lampiran_' + (i + 1)));
        var attBytes = Utilities.base64Decode(String(att.contentBase64));
        var attBlob  = Utilities.newBlob(attBytes, attMime, attName);
        mailAttachments.push(attBlob);

        if (attMime.toLowerCase().indexOf('pdf') !== -1 || /\.pdf$/i.test(attName)) {
          pdfAttached  = true;
          pdfSource    = 'php_base64_attachment';
          finalPdfName = attName;
        }
      } catch (attErr) {
        // Lanjut ke fallback pdf_html jika lampiran base64 bermasalah
      }
    }
  }

  // 2. Generate PDF A5 langsung di Google Apps Script jika `pdf_html` dikirim dan belum ada PDF
  var pdfHtml = String(payload.pdf_html || '').trim();
  if (pdfHtml && !pdfAttached) {
    try {
      finalPdfName = sanitizePdfFilename(payload.pdf_filename || 'Kartu_Peserta_Ujian_A5.pdf');
      var generatedPdfBlob = convertHtmlToPdfBlob(pdfHtml, finalPdfName);
      mailAttachments.push(generatedPdfBlob);
      pdfAttached = true;
      pdfSource   = 'gas_html_to_pdf';
    } catch (pdfErr) {
      return {
        success: false,
        to: to,
        message: 'Gagal menghasilkan berkas PDF Kartu Peserta Ujian A5 di Google Relay: ' + pdfErr.toString(),
        relay_version: ILMUNA_RELAY_VERSION,
        remaining_quota: safeGetRemainingQuota()
      };
    }
  }

  // 3. Decode inline CID images (contoh: cid:ilmuna_logo) agar tampil di Gmail
  var inlineImages = {};
  var hasInlineImages = false;
  if (payload.inline_images && typeof payload.inline_images === 'object') {
    var keys = Object.keys(payload.inline_images);
    for (var k = 0; k < keys.length; k++) {
      var cidKey = keys[k];
      var imgObj = payload.inline_images[cidKey];
      if (!imgObj || !imgObj.contentBase64) continue;
      try {
        var imgMime  = String(imgObj.mimeType || imgObj.mime || 'image/png');
        var imgBytes = Utilities.base64Decode(String(imgObj.contentBase64));
        inlineImages[cidKey] = Utilities.newBlob(imgBytes, imgMime, cidKey);
        hasInlineImages = true;
      } catch (imgErr) {
        // Abaikan gambar inline jika gagal decode
      }
    }
  }

  // 4. Susun opsi pengiriman MailApp
  var plainText = stripHtmlToPlainText(htmlBody) || subject;
  var mailOptions = {
    htmlBody: htmlBody,
    name: fromName
  };

  if (replyTo && replyTo.indexOf('@') !== -1) {
    mailOptions.replyTo = replyTo;
  }
  if (mailAttachments.length > 0) {
    mailOptions.attachments = mailAttachments;
  }
  if (hasInlineImages) {
    mailOptions.inlineImages = inlineImages;
  }

  try {
    MailApp.sendEmail(to, subject, plainText, mailOptions);
  } catch (sendErr) {
    return {
      success: false,
      to: to,
      message: 'Gagal mengirim email melalui MailApp Google Relay: ' + sendErr.toString(),
      relay_version: ILMUNA_RELAY_VERSION,
      remaining_quota: safeGetRemainingQuota()
    };
  }

  return {
    success: true,
    to: to,
    message: pdfAttached
      ? 'Kartu ujian PDF A5 berhasil dikirimkan ke Gmail (' + to + ') via Google Relay!'
      : 'Email berhasil dikirim via Google Relay HTTPS!',
    pdf_attached: pdfAttached,
    pdf_filename: pdfAttached ? finalPdfName : null,
    pdf_source: pdfSource,
    relay_version: ILMUNA_RELAY_VERSION,
    remaining_quota: safeGetRemainingQuota()
  };
}

/**
 * Mengonversi HTML lengkap menjadi PDF Blob resmi.
 */
function convertHtmlToPdfBlob(htmlString, pdfFilename) {
  var safeName = sanitizePdfFilename(pdfFilename);
  var htmlName = safeName.replace(/\.pdf$/i, '') + '.html';
  var htmlBlob = Utilities.newBlob(htmlString, 'text/html', htmlName);
  return htmlBlob.getAs('application/pdf').setName(safeName);
}

/**
 * Sanitasi nama berkas PDF agar aman untuk header MIME & sistem berkas.
 */
function sanitizePdfFilename(name) {
  var cleaned = String(name || 'Kartu_Peserta_Ujian_A5.pdf')
    .replace(/[^a-zA-Z0-9_\-\.]/g, '_')
    .replace(/_+/g, '_');
  if (!/\.pdf$/i.test(cleaned)) {
    cleaned += '.pdf';
  }
  return cleaned;
}

/**
 * Konversi ringan HTML ke Plain Text untuk fallback MIME multipart/alternative.
 */
function stripHtmlToPlainText(html) {
  if (!html) return '';
  return String(html)
    .replace(/<style[\s\S]*?<\/style>/gi, ' ')
    .replace(/<script[\s\S]*?<\/script>/gi, ' ')
    .replace(/<br\s*\/?>/gi, '\n')
    .replace(/<\/(p|div|tr|h1|h2|h3|h4|li)>/gi, '\n')
    .replace(/<[^>]+>/g, ' ')
    .replace(/&nbsp;/gi, ' ')
    .replace(/&bull;/gi, '•')
    .replace(/&amp;/gi, '&')
    .replace(/&lt;/gi, '<')
    .replace(/&gt;/gi, '>')
    .replace(/[ \t]+/g, ' ')
    .replace(/\n{3,}/g, '\n\n')
    .trim();
}

/**
 * Membaca sisa kuota harian MailApp secara aman.
 */
function safeGetRemainingQuota() {
  try {
    return MailApp.getRemainingDailyQuota();
  } catch (e) {
    return null;
  }
}

/**
 * Helper output JSON ContentService.
 */
function buildJsonResponse(obj) {
  return ContentService
    .createTextOutput(JSON.stringify(obj))
    .setMimeType(ContentService.MimeType.JSON);
}
