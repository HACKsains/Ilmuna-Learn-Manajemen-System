/**
 * ==============================================================================
 * ILMUNA LEARN — GOOGLE APPS SCRIPT WEB APP FOR DRIVE UPLOAD
 * ==============================================================================
 * Skrip ini menerima file upload dari Portal Guru Ilmuna Learn dan secara
 * otomatis mengorganisasikan folder di Google Drive:
 * 
 * Root Folder: "materi"
 *   └── Subfolder: "{nama_kelas}" (contoh: "X PAI-1 (Imam Al-Bukhari)")
 *         └── Subfolder: "{nama_mapel}" (contoh: "Pendidikan Agama Islam")
 *               └── File: "{nama_materi}.{ext}" (contoh: "01 - Asmaul Husna.pdf")
 * 
 * Fitur:
 * 1. Otomatis membuat folder jika belum ada (getOrCreateFolder).
 * 2. Mengubah izin file menjadi Siapa saja yang memiliki link dapat melihat (Public Read).
 * 3. Mengembalikan response JSON lengkap:
 *    - success: true
 *    - fileId: ID unik Google Drive
 *    - fileName: Nama file yang tersimpan
 *    - viewUrl: Link pratinjau (https://drive.google.com/file/d/.../view)
 *    - downloadUrl: Link unduh langsung
 *    - folderHierarchy: "materi/{kelas}/{mapel}"
 * 
 * PANDUAN DEPLOYMENT (HANYA 2 MENIT):
 * 1. Buka https://script.google.com/
 * 2. Klik "New Project" / "Proyek Baru", beri nama: "Ilmuna Learn - Materi Drive Uploader"
 * 3. Hapus kode default di Code.gs, lalu tempel SELURUH KODE di bawah ini.
 * 4. Klik tombol "Save" (Simpan / Ctrl+S).
 * 5. Klik "Deploy" (Terapkan) di pojok kanan atas > Pilih "New deployment" (Penerapan baru).
 * 6. Klik ikon gerigi (Select type) > Pilih "Web app" (Aplikasi Web).
 * 7. Isi konfigurasi:
 *    - Description: "Upload Materi LMS"
 *    - Execute as: "Me (email-anda@gmail.com)"
 *    - Who has access: "Anyone" (Siapa saja, bahkan anonim - PENTING!)
 * 8. Klik tombol "Deploy".
 * 9. Klik "Authorize access" > Pilih akun Google Anda > Klik "Advanced" (Lanjutan)
 *    > Klik "Go to Ilmuna Learn (unsafe)" > Klik "Allow" (Izinkan).
 * 10. Salin "Web App URL" (URL berakhiran /exec).
 * 11. Buka menu Materi Guru di LMS > Klik tombol "Google Drive (GAS)" > Tempel URL dan Simpan!
 * ==============================================================================
 */

/**
 * PENTING: JALANKAN FUNGSI INI 1 KALI DI DALAM EDITOR GOOGLE APPS SCRIPT!
 * 1. Di bilah atas editor, pilih fungsi: "testAuth"
 * 2. Klik tombol ▶ "Run" (Jalankan)
 * 3. Akan muncul popup "Authorization required" (Otorisasi diperlukan)
 * 4. Klik "Review permissions" > Pilih akun Google Anda > "Advanced" > "Go to ... (unsafe)" > "Allow"
 * 5. Setelah selesai, klik Deploy > Manage deployments > Edit (pensil) > Version: New version > Deploy!
 */
function testAuth() {
  var root = DriveApp.getRootFolder();
  Logger.log("Akses Google Drive Berhasil: " + root.getName());
  return "Alhamdulillah, Google Drive Berhasil Diotorisasi!";
}

function doPost(e) {
  var lock = LockService.getScriptLock();
  // Lock thread selama 30 detik untuk menghindari konflik duplikasi pembuatan folder paralel
  lock.tryLock(30000);

  try {
    if (!e || !e.postData || !e.postData.contents) {
      return responseJson({
        success: false,
        message: "Tidak ada payload data yang diterima (postData kosong)."
      });
    }

    var payload = {};
    try {
      payload = JSON.parse(e.postData.contents);
    } catch (parseErr) {
      return responseJson({
        success: false,
        message: "Format payload bukan JSON valid: " + parseErr.toString()
      });
    }

    var base64Data   = payload.fileData || "";
    var originalName = payload.fileName || "dokumen-materi";
    var mimeType     = payload.mimeType || "application/octet-stream";
    var namaKelas    = sanitizeFolderName(payload.namaKelas || "Umum");
    var namaMapel    = sanitizeFolderName(payload.namaMapel || "Mata Pelajaran");
    var namaMateri   = (payload.namaMateri || "").trim();

    if (!base64Data) {
      return responseJson({
        success: false,
        message: "Data berkas file (fileData base64) wajib disertakan."
      });
    }

    // Ekstrak ekstensi file asli
    var ext = "";
    var lastDot = originalName.lastIndexOf(".");
    if (lastDot !== -1) {
      ext = originalName.substring(lastDot).toLowerCase();
    }

    // Tentukan nama file yang akan disimpan di Google Drive
    // Sesuai permintaan: format "nama materi.ext"
    var finalFileName = originalName;
    if (namaMateri) {
      var cleanTitle = sanitizeFolderName(namaMateri);
      if (ext && !cleanTitle.toLowerCase().endsWith(ext)) {
        finalFileName = cleanTitle + ext;
      } else {
        finalFileName = cleanTitle;
      }
    }

    // 1. Dapatkan atau Buat Root Folder (Dukungan Folder ID spesifik atau default folder "materi")
    var baseFolder = null;
    var targetFolderId = (payload.targetFolderId || payload.folderId || "").toString().trim();
    if (targetFolderId) {
      try {
        baseFolder = DriveApp.getFolderById(targetFolderId);
      } catch (fErr) {
        Logger.log("Folder ID tidak ditemukan, fallback ke Root: " + fErr.toString());
      }
    }
    if (!baseFolder) {
      var rootDriveFolder = DriveApp.getRootFolder();
      var rootFolderName = (payload.rootFolder || "materi").trim();
      baseFolder = getOrCreateFolder(rootDriveFolder, rootFolderName);
    }

    // 2. Dapatkan atau Buat Subfolder Kelas: "{baseFolder}/{namaKelas}"
    var folderKelas = getOrCreateFolder(baseFolder, namaKelas);

    // 3. Dapatkan atau Buat Subfolder Mapel: "{baseFolder}/{namaKelas}/{namaMapel}"
    var folderMapel = getOrCreateFolder(folderKelas, namaMapel);

    // 4. Decode base64 dan bentuk file blob
    var decodedBytes = Utilities.base64Decode(base64Data);
    var blob = Utilities.newBlob(decodedBytes, mimeType, finalFileName);

    // 5. Buat file baru di dalam subfolder mapel
    var driveFile = folderMapel.createFile(blob);

    // 6. Set izin akses agar dapat dilihat oleh siswa / guru melalui tautan
    try {
      driveFile.setSharing(DriveApp.Access.ANYONE_WITH_LINK, DriveApp.Permission.VIEW);
    } catch (shareErr) {
      Logger.log("Peringatan sharing Drive: " + shareErr.toString());
    }

    var fileId = driveFile.getId();
    var viewUrl = "https://drive.google.com/file/d/" + fileId + "/view";
    var directDownloadUrl = "https://drive.google.com/uc?export=download&id=" + fileId;

    return responseJson({
      success: true,
      message: "Alhamdulillah! Berkas berhasil diunggah dan disimpan di Google Drive.",
      data: {
        fileId: fileId,
        fileName: driveFile.getName(),
        fileSize: driveFile.getSize(),
        mimeType: driveFile.getMimeType(),
        viewUrl: viewUrl,
        downloadUrl: directDownloadUrl,
        driveUrl: driveFile.getUrl(),
        folderPath: "materi/" + namaKelas + "/" + namaMapel + "/" + driveFile.getName(),
        parentFolderId: folderMapel.getId()
      }
    });

  } catch (error) {
    return responseJson({
      success: false,
      message: "Terjadi kesalahan internal pada Google Apps Script: " + error.toString()
    });
  } finally {
    lock.releaseLock();
  }
}

function doGet(e) {
  // Health check endpoint
  return responseJson({
    success: true,
    status: "online",
    service: "Ilmuna Learn Google Drive Materi Uploader",
    timestamp: new Date().toISOString()
  });
}

function getOrCreateFolder(parentFolder, folderName) {
  var cleanName = (folderName || "Folder").toString().trim();
  var iterator = parentFolder.getFoldersByName(cleanName);
  if (iterator.hasNext()) {
    return iterator.next();
  }
  return parentFolder.createFolder(cleanName);
}

function sanitizeFolderName(str) {
  if (!str) return "Umum";
  return str.toString()
    .replace(/[\/\\?%*:|"<>]/g, "-") // Hapus karakter ilegal untuk file/folder
    .replace(/\s+/g, " ")
    .trim();
}

function responseJson(obj) {
  return ContentService
    .createTextOutput(JSON.stringify(obj))
    .setMimeType(ContentService.MimeType.JSON);
}
