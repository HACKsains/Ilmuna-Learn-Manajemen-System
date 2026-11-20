/**
 * ==============================================================================
 * ILMUNA LEARN — GOOGLE APPS SCRIPT WEB APP FOR EMAIL & KARTU UJIAN PDF A5 RELAY
 * ==============================================================================
 * Versi : 3.0-secure-keypair (Public Key + Private Key HMAC-SHA256 + Anti-Replay)
 * Fitur :
 * 1. Keamanan Berlapis (Zero-Trust Relay Auth):
 *    - Relay Token (`ILMUNA_RELAY_TOKEN`)
 *    - Public Key (`ILMUNA_RELAY_PUBLIC_KEY`) sebagai identitas klien resmi
 *    - Private Key (`ILMUNA_RELAY_PRIVATE_KEY`) untuk verifikasi tanda tangan
 *      kriptografi HMAC-SHA256 (Private Key TIDAK PERNAH dikirim lewat jaringan!)
 *    - Anti-Tampering (`body_hash` SHA-256 atas action, tujuan, subjek, filename)
 *    - Anti-Replay Attack (validasi `timestamp` ±5 menit + `nonce` unik di CacheService)
 *    - Mendukung penyimpanan kunci di Script Properties Google Apps Script secara opsional.
 * 2. Bebas Pembatasan Buatan:
 *    - TIDAK ada pembatasan kuota buatan di dalam skrip (tidak memblokir pengiriman).
 *    - Mendukung mode `action: 'generate_pdf'` murni untuk merender HTML Kartu Ujian
 *      ke PDF A5 (0 pemakaian kuota kirim Gmail) agar server PHP dapat otomatis
 *      melanjutkan pengiriman tanpa batas lewat SMTP cPanel jika akun Gmail penuh.
 * 3. Dual Engine Pengiriman (`GmailApp.sendEmail` utama + fallback `MailApp.sendEmail`)
 *    dengan dukungan lampiran PDF A5, Base64 `attachments`, dan `inline_images` CID.
 *
 * PANDUAN PEMBARUAN (RE-DEPLOY) DI GOOGLE APPS SCRIPT:
 * 1. Buka proyek Google Apps Script Relay Anda di https://script.google.com/
 * 2. Hapus seluruh kode lama di `Code.gs`, lalu tempel SELURUH kode di file ini.
 * 3. Klik Simpan (Ctrl + S).
 *    (Opsional lebih aman: simpan `ILMUNA_RELAY_TOKEN`, `ILMUNA_RELAY_PUBLIC_KEY`,
 *    dan `ILMUNA_RELAY_PRIVATE_KEY` di Project Settings > Script Properties).
 * 4. WAJIB: Pilih fungsi `testAuth` pada dropdown toolbar atas lalu klik **"Run"**.
 *    Klik **Review permissions > pilih akun Google > Advanced > Go to ... (unsafe) > Allow**.
 * 5. Klik **"Deploy" > "Manage deployments" > ikon pensil (Edit)**.
 * 6. Pastikan:
 *    - Version: **"New version"** (mis. "v3.0 Secure Keypair HMAC")
 *    - Execute as: **"Me"** (WAJIB!)
 *    - Who has access: **"Anyone"**
 *    Klik **"Deploy"**. (URL `/exec` TETAP SAMA!)
 * 7. Buka URL `/exec` di browser untuk memastikan tampil `"version":"3.0-secure-keypair"`
 *    dan `"ready_to_send":true`.
 * ==============================================================================
 */

var ILMUNA_RELAY_TOKEN       = '1LmuN@28';
var ILMUNA_RELAY_PUBLIC_KEY  = 'pk_ilmuna_7c7cbb2a802e0bce86aa7c682859e9ce';
var ILMUNA_RELAY_PRIVATE_KEY = 'sk_ilmuna_8d5e0907150fb0054ce9be16fcde014c464ca4cbb28482ecbfd16cea0835c957';
var ILMUNA_RELAY_VERSION     = '3.0-secure-keypair';
var ILMUNA_SIGNATURE_TTL_SEC = 300; // Jendela anti-replay signature (±5 menit), BUKAN batas kuota harian

/**
 * Mengambil nilai secret dari Script Properties terlebih dahulu (jika diset admin),
 * lalu fallback ke konstanta di skrip.
 */
function getRelayConfigValue(propName, fallbackVal) {
  try {
    var val = PropertiesService.getScriptProperties().getProperty(propName);
    if (val && String(val).trim() !== '') {
      return String(val).trim();
    }
  } catch (e) {
    // Abaikan jika ScriptProperties belum diakses
  }
  return String(fallbackVal || '').trim();
}

/**
 * WAJIB jalankan fungsi ini sekali di editor Apps Script (pilih `testAuth` -> klik Run)
 * agar Google memunculkan dialog otorisasi izin kirim email (GmailApp & MailApp).
 */
function testAuth() {
  var aliases = GmailApp.getAliases();
  var quota   = safeGetRemainingQuota();
  var testPdf = Utilities.newBlob(
    '<!DOCTYPE html><html><head><meta charset="UTF-8"><style>@page{size:148mm 210mm;margin:8mm;}</style></head><body><h3>Ilmuna Learn PDF A5 Test OK</h3></body></html>',
    'text/html',
    'test_kartu_a5.html'
  ).getAs('application/pdf').setName('test_kartu_a5.pdf');

  // Uji fungsi kriptografi HMAC-SHA256 & SHA-256
  var sampleHash = sha256Hex('ilmuna_crypto_check');
  var sampleSig  = hmacSha256Hex('ilmuna_crypto_check', getRelayConfigValue('ILMUNA_RELAY_PRIVATE_KEY', ILMUNA_RELAY_PRIVATE_KEY));

  Logger.log('Ilmuna Email Relay ' + ILMUNA_RELAY_VERSION + ' TEROTORISASI PENUH!');
  Logger.log('GmailApp Aliases: ' + JSON.stringify(aliases));
  Logger.log('MailApp quota info: ' + quota);
  Logger.log('PDF Test Bytes: ' + testPdf.getBytes().length);
  Logger.log('Crypto Check OK | SHA256 len=' + sampleHash.length + ' | HMAC len=' + sampleSig.length);
  return 'OK | Versi: ' + ILMUNA_RELAY_VERSION + ' | GmailApp: OK | PDF Bytes: ' + testPdf.getBytes().length;
}

/**
 * Endpoint GET diagnostik publik (TIDAK PERNAH membocorkan token atau private key).
 */
function doGet(e) {
  var gmailOk   = isGmailAppAuthorized();
  var mailAppOk = (safeGetRemainingQuota() !== null);
  var pubKey    = getRelayConfigValue('ILMUNA_RELAY_PUBLIC_KEY', ILMUNA_RELAY_PUBLIC_KEY);
  var pubMask   = pubKey.length > 16
    ? (pubKey.substring(0, 14) + '...' + pubKey.substring(pubKey.length - 4))
    : '***';

  return buildJsonResponse({
    success: true,
    status: 'online',
    service: 'Ilmuna Learn Secure Email & PDF A5 Relay',
    version: ILMUNA_RELAY_VERSION,
    auth_mode: 'token + public_key + private_key_hmac_sha256 + anti_replay',
    public_key_preview: pubMask,
    gmail_authorized: gmailOk,
    mailapp_authorized: mailAppOk,
    ready_to_send: (gmailOk || mailAppOk),
    features: [
      'hmac_sha256_keypair_auth',
      'anti_replay_timestamp_nonce',
      'html_email',
      'html_to_pdf_a5',
      'cloud_pdf_renderer_unlimited',
      'base64_attachments',
      'inline_images',
      'gmail_and_mailapp_dual_engine'
    ]
  });
}

/**
 * Endpoint POST utama dengan verifikasi ketat Token + Public Key + Private Key HMAC-SHA256.
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

    // Verifikasi Kriptografi Berlapis (Token + Public Key + Private Key HMAC-SHA256 + Anti-Replay)
    var authCheck = verifySignedRequest(payload);
    if (!authCheck.valid) {
      return buildJsonResponse({
        success: false,
        auth_error: true,
        message: authCheck.message,
        relay_version: ILMUNA_RELAY_VERSION
      });
    }

    var action = String(payload.action || 'send_email').trim().toLowerCase();

    // Mode 1: Generate PDF A5 murni (0 kuota kirim Gmail — bebas batas harian!)
    if (action === 'generate_pdf') {
      return handleGeneratePdfOnly(payload);
    }

    // Mode 2: Kirim batch tanpa pembatasan buatan
    if (action === 'send_batch' && Array.isArray(payload.items)) {
      return handleBatchSend(payload.items);
    }

    // Mode 3 (Default): Kirim 1 email (HTML + PDF A5 + inline images)
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
 * Verifikasi keamanan request:
 * 1. Token cocok (`ILMUNA_RELAY_TOKEN`)
 * 2. Public Key cocok (`ILMUNA_RELAY_PUBLIC_KEY`)
 * 3. Timestamp valid (±300 detik) & Nonce unik (Anti-Replay)
 * 4. Body Hash SHA-256 cocok (Anti-Tampering)
 * 5. HMAC-SHA256 Signature menggunakan Private Key cocok (Constant-Time)
 */
function verifySignedRequest(payload) {
  if (!payload || typeof payload !== 'object') {
    return { valid: false, message: 'Payload request tidak valid.' };
  }

  var expectedToken   = getRelayConfigValue('ILMUNA_RELAY_TOKEN', ILMUNA_RELAY_TOKEN);
  var expectedPubKey  = getRelayConfigValue('ILMUNA_RELAY_PUBLIC_KEY', ILMUNA_RELAY_PUBLIC_KEY);
  var expectedPrivKey = getRelayConfigValue('ILMUNA_RELAY_PRIVATE_KEY', ILMUNA_RELAY_PRIVATE_KEY);

  var reqToken     = String(payload.token || '').trim();
  var reqPubKey    = String(payload.public_key || '').trim();
  var reqTimestamp = String(payload.timestamp || '').trim();
  var reqNonce     = String(payload.nonce || '').trim();
  var reqBodyHash  = String(payload.body_hash || '').trim().toLowerCase();
  var reqSignature = String(payload.signature || '').trim().toLowerCase();

  // 1. Verifikasi Relay Token
  if (!timingSafeEqual(reqToken, expectedToken)) {
    return { valid: false, message: 'Otorisasi ditolak: Relay Token tidak cocok.' };
  }

  // 2. Verifikasi Public Key
  if (!reqPubKey || !timingSafeEqual(reqPubKey, expectedPubKey)) {
    return { valid: false, message: 'Otorisasi ditolak: Public Key tidak valid.' };
  }

  // 3. Verifikasi Timestamp (±5 menit untuk mencegah replay attack)
  var tsNum = parseInt(reqTimestamp, 10);
  var nowSec = Math.floor(Date.now() / 1000);
  if (isNaN(tsNum) || Math.abs(nowSec - tsNum) > ILMUNA_SIGNATURE_TTL_SEC) {
    return { valid: false, message: 'Otorisasi ditolak: Timestamp request kedaluwarsa atau tidak sinkron.' };
  }

  // 4. Verifikasi Nonce unik (Anti-Replay Attack)
  if (reqNonce.length < 12 || reqNonce.length > 128) {
    return { valid: false, message: 'Otorisasi ditolak: Nonce keamanan tidak valid.' };
  }
  try {
    var cache = CacheService.getScriptCache();
    var nonceKey = 'ilmuna_nonce_' + reqNonce;
    if (cache.get(nonceKey)) {
      return { valid: false, message: 'Otorisasi ditolak: Request duplikat terdeteksi (Anti-Replay).' };
    }
    cache.put(nonceKey, '1', ILMUNA_SIGNATURE_TTL_SEC);
  } catch (cacheErr) {
    // Jika CacheService sementara sibuk, tetap lanjutkan verifikasi HMAC
  }

  // 5. Verifikasi Integritas Isi Request (Body Hash SHA-256)
  var action      = String(payload.action || 'send_email').trim().toLowerCase();
  var to          = String(payload.to || '').trim();
  var subject     = String(payload.subject || '').trim();
  var pdfFilename = String(payload.pdf_filename || '').trim();
  var expectedBodyHash = sha256Hex(action + '|' + to + '|' + subject + '|' + pdfFilename);

  if (!reqBodyHash || !timingSafeEqual(reqBodyHash, expectedBodyHash)) {
    return { valid: false, message: 'Otorisasi ditolak: Integritas payload (body_hash) tidak cocok.' };
  }

  // 6. Verifikasi Tanda Tangan HMAC-SHA256 memakai Private Key (Private Key tidak pernah dikirim via HTTP)
  var canonical = reqPubKey + '|' + reqToken + '|' + reqTimestamp + '|' + reqNonce + '|' + expectedBodyHash;
  var expectedSignature = hmacSha256Hex(canonical, expectedPrivKey);

  if (!reqSignature || !timingSafeEqual(reqSignature, expectedSignature)) {
    return { valid: false, message: 'Otorisasi ditolak: Tanda tangan Private Key (HMAC-SHA256) tidak valid.' };
  }

  return { valid: true };
}

/**
 * Konversi HTML Kartu Ujian menjadi PDF A5 murni tanpa mengirim email (0 kuota kirim Gmail).
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
 * Pengiriman batch (tanpa pembatasan buatan jumlah item).
 */
function handleBatchSend(items) {
  var results = [];
  var sent    = 0;
  var failed  = 0;

  for (var i = 0; i < items.length; i++) {
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
    relay_version: ILMUNA_RELAY_VERSION
  });
}

/**
 * Proses pengiriman 1 email (HTML + opsional PDF A5 + lampiran Base64 + inline CID images).
 * Tanpa pembatasan buatan — langsung kirim melalui Dual Engine (GmailApp -> MailApp).
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

  var subject  = String(payload.subject || 'Notifikasi Ilmuna Learn').trim();
  var htmlBody = String(payload.html || payload.body_html || payload.body || '');
  var fromName = String(payload.from_name || 'Ilmuna Learn').trim();
  var replyTo  = String(payload.reply_to || '').trim();

  var mailAttachments = [];
  var pdfAttached     = false;
  var pdfSource       = null;
  var finalPdfName    = null;

  // 1. Decode lampiran Base64 jika dikirim oleh server PHP
  if (Array.isArray(payload.attachments) && payload.attachments.length > 0) {
    for (var i = 0; i < payload.attachments.length; i++) {
      var att = payload.attachments[i];
      if (!att || !att.contentBase64) continue;
      try {
        var attMime  = String(att.mimeType || att.mime || 'application/octet-stream');
        var attName  = String(att.filename || att.name || ('lampiran_' + (i + 1)));
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
        relay_version: ILMUNA_RELAY_VERSION
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

  // 4. Susun opsi pengiriman (kompatibel penuh dengan GmailApp & MailApp)
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

  // 5. Dual Engine Send: GmailApp -> fallback MailApp (tanpa pembatasan buatan di skrip)
  var sentVia = '';
  try {
    GmailApp.sendEmail(to, subject, plainText, mailOptions);
    sentVia = 'GmailApp';
  } catch (gmailErr) {
    try {
      MailApp.sendEmail(to, subject, plainText, mailOptions);
      sentVia = 'MailApp';
    } catch (mailErr) {
      var combinedErr = gmailErr.toString() + ' | ' + mailErr.toString();
      var isGoogleLimit = /limit exceeded|too many times|quota/i.test(combinedErr);
      return {
        success: false,
        to: to,
        quota_exceeded: isGoogleLimit,
        message: isGoogleLimit
          ? 'Batas kirim akun Gmail Google tercapai — mengalihkan otomatis ke server SMTP cPanel.'
          : 'Gagal mengirim via Google Relay (pastikan testAuth sudah dijalankan & Execute as = Me): ' + combinedErr,
        relay_version: ILMUNA_RELAY_VERSION
      };
    }
  }

  return {
    success: true,
    to: to,
    message: pdfAttached
      ? 'Kartu ujian PDF A5 berhasil dikirimkan ke Gmail (' + to + ') via Google Relay!'
      : 'Email berhasil dikirim via Google Relay HTTPS!',
    sent_via: sentVia,
    pdf_attached: pdfAttached,
    pdf_filename: pdfAttached ? finalPdfName : null,
    pdf_source: pdfSource,
    relay_version: ILMUNA_RELAY_VERSION
  };
}

/**
 * Helper SHA-256 hex lowercase (UTF-8, identik dengan PHP hash('sha256', ...)).
 */
function sha256Hex(str) {
  var bytes = Utilities.computeDigest(Utilities.DigestAlgorithm.SHA_256, String(str), Utilities.Charset.UTF_8);
  return bytesToHex(bytes);
}

/**
 * Helper HMAC-SHA256 hex lowercase (UTF-8, identik dengan PHP hash_hmac('sha256', ...)).
 */
function hmacSha256Hex(message, secretKey) {
  var bytes = Utilities.computeHmacSha256Signature(String(message), String(secretKey), Utilities.Charset.UTF_8);
  return bytesToHex(bytes);
}

function bytesToHex(bytes) {
  var hex = [];
  for (var i = 0; i < bytes.length; i++) {
    var v = bytes[i];
    if (v < 0) v += 256;
    var h = v.toString(16);
    hex.push(h.length === 1 ? '0' + h : h);
  }
  return hex.join('');
}

/**
 * Perbandingan string constant-time (mencegah timing attack).
 */
function timingSafeEqual(a, b) {
  var strA = String(a || '');
  var strB = String(b || '');
  var len  = Math.max(strA.length, strB.length);
  var diff = strA.length ^ strB.length;
  for (var i = 0; i < len; i++) {
    var ca = i < strA.length ? strA.charCodeAt(i) : 0;
    var cb = i < strB.length ? strB.charCodeAt(i) : 0;
    diff |= (ca ^ cb);
  }
  return diff === 0;
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
 * Cek apakah izin GmailApp sudah terotorisasi.
 */
function isGmailAppAuthorized() {
  try {
    GmailApp.getAliases();
    return true;
  } catch (e) {
    return false;
  }
}

/**
 * Membaca status otorisasi MailApp secara aman.
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
