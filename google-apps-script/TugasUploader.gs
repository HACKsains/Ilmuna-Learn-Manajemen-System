/**
 * ==============================================================================
 * ILMUNA LEARN — GOOGLE APPS SCRIPT WEB APP FOR TUGAS & PENILAIAN DRIVE UPLOADER
 * ==============================================================================
 * Skrip ini menerima berkas tugas (Individu & Kelompok) dari Portal Ilmuna Learn
 * dan secara otomatis membangun hirarki folder rapi di Google Drive:
 * 
 * Root: "tugas"
 *   └── Subfolder: "{namaKelas}" (contoh: "X PAI-1 (Imam Al-Bukhari)")
 *         └── Subfolder: "{namaMapel}" (contoh: "Pendidikan Agama Islam")
 *               └── Subfolder: "{judulTugas}" (contoh: "Analisis Karakter Ulil Albab")
 *                     └── Subfolder: "{namaSiswa / namaKelompok}"
 *                           (Individu: "Ahmad Dahlan" / Kelompok: "Kelompok 1 - Abu Bakar Ash-Shiddiq")
 *                           └── File: "{namaFileAsli}.{ext}"
 * 
 * Fitur:
 * 1. Otomatis membuat struktur folder bertingkat (getOrCreateFolder).
 * 2. Mendukung tugas Individu maupun tugas Kelompok.
 * 3. Mengatur hak akses file menjadi "Anyone with link can VIEW" (Hanya Lihat / View-Only).
 * 4. Mengembalikan respons JSON lengkap:
 *    - success: true
 *    - fileId: ID berkas unik di Google Drive
 *    - fileName: Nama berkas yang tersimpan
 *    - viewUrl: URL Google Drive View
 *    - previewUrl: URL Google Drive Preview (untuk disematkan di iframe penilai tanpa download)
 *    - folderPath: Path lengkap di Google Drive
 * 
 * PANDUAN PENERAPAN (DEPLOYMENT):
 * 1. Buka https://script.google.com/
 * 2. Klik "+ Proyek Baru" (New project), beri nama: "Ilmuna Learn - Tugas Drive Uploader"
 * 3. Hapus kode bawaan di Code.gs, lalu tempel SELURUH KODE di bawah ini.
 * 4. Klik Simpan (Ctrl+S).
 * 5. Jalankan fungsi "testAuth" sekali dan izinkan otorisasi akses Google Drive Anda.
 * 6. Klik "Deploy" (Terapkan) > "New deployment" (Penerapan baru).
 * 7. Pilih jenis "Web app" (Aplikasi Web).
 * 8. Konfigurasi:
 *    - Execute as: "Me (email akun Google Anda)"
 *    - Who has access: "Anyone" (Siapa saja - PENTING!)
 * 9. Klik "Deploy", salin Web App URL (berakhiran /exec).
 * 10. Masukkan URL tersebut ke config.php pada konstanta GAS_TUGAS_URL.
 * ==============================================================================
 */

function testAuth() {
  var root = DriveApp.getRootFolder();
  Logger.log("Otorisasi Google Drive Berhasil: " + root.getName());
  return "Alhamdulillah, Google Drive Berhasil Diotorisasi!";
}

function doPost(e) {
  var lock = LockService.getScriptLock();
  // Lock 30 detik agar tidak terjadi konflik duplikasi folder saat siswa upload bersamaan
  lock.tryLock(30000);

  try {
    if (!e || !e.postData || !e.postData.contents) {
      return responseJson({
        success: false,
        message: "Tidak ada data payload yang diterima (postData kosong)."
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

    var base64Data      = payload.fileData || "";
    var originalName    = payload.fileName || "berkas_tugas";
    var mimeType        = payload.mimeType || "application/octet-stream";
    var namaKelas       = sanitizeFolderName(payload.namaKelas || "Kelas Umum");
    var namaMapel       = sanitizeFolderName(payload.namaMapel || "Mata Pelajaran");
    var judulTugas      = sanitizeFolderName(payload.judulTugas || payload.namaTugas || "Tugas");
    var jenisPenugasan  = (payload.jenisPenugasan || "Individu").toString().trim();
    
    // Nama Pengumpul: Nama Lengkap Siswa jika Individu, atau Nama Kelompok jika Kelompok
    var namaPengumpul = "";
    if (jenisPenugasan.toLowerCase() === "kelompok") {
      namaPengumpul = sanitizeFolderName(payload.namaKelompok || payload.namaPengumpul || "Kelompok");
    } else {
      namaPengumpul = sanitizeFolderName(payload.namaSiswa || payload.namaLengkap || payload.namaPengumpul || "Siswa");
    }

    if (!base64Data) {
      return responseJson({
        success: false,
        message: "Data berkas file (fileData base64) wajib disertakan."
      });
    }

    // 1. Root Folder "tugas" di Google Drive (atau target folder tertentu)
    var baseFolder = null;
    var targetFolderId = (payload.targetFolderId || payload.folderId || "").toString().trim();
    if (targetFolderId) {
      try {
        baseFolder = DriveApp.getFolderById(targetFolderId);
      } catch (fErr) {
        Logger.log("Folder ID khusus tidak ditemukan, fallback ke Root: " + fErr.toString());
      }
    }
    if (!baseFolder) {
      var rootDriveFolder = DriveApp.getRootFolder();
      var rootFolderName = (payload.rootFolder || "tugas").trim();
      baseFolder = getOrCreateFolder(rootDriveFolder, rootFolderName);
    }

    // 2. Hirarki Folder:
    //    /tugas/{namaKelas}/{namaMapel}/{judulTugas}/{namaPengumpul}/
    var folderKelas      = getOrCreateFolder(baseFolder, namaKelas);
    var folderMapel      = getOrCreateFolder(folderKelas, namaMapel);
    var folderTugas      = getOrCreateFolder(folderMapel, judulTugas);
    var folderPengumpul  = getOrCreateFolder(folderTugas, namaPengumpul);

    // 3. Nama file rapi
    var finalFileName = originalName;
    if (payload.renameFile !== false) {
      var ext = "";
      var lastDot = originalName.lastIndexOf(".");
      if (lastDot !== -1) {
        ext = originalName.substring(lastDot);
      }
      var cleanBaseName = originalName.substring(0, lastDot !== -1 ? lastDot : originalName.length);
      finalFileName = sanitizeFolderName(namaPengumpul + " - " + cleanBaseName) + ext;
    }

    // 4. Decode base64 dan simpan berkas
    var decodedBytes = Utilities.base64Decode(base64Data);
    var blob = Utilities.newBlob(decodedBytes, mimeType, finalFileName);
    var driveFile = folderPengumpul.createFile(blob);

    // 5. Atur izin akses agar guru dan siswa dapat melihat via tautan terenkripsi
    try {
      driveFile.setSharing(DriveApp.Access.ANYONE_WITH_LINK, DriveApp.Permission.VIEW);
    } catch (shareErr) {
      Logger.log("Peringatan sharing Drive: " + shareErr.toString());
    }

    var fileId = driveFile.getId();
    var viewUrl = "https://drive.google.com/file/d/" + fileId + "/view";
    var previewUrl = "https://drive.google.com/file/d/" + fileId + "/preview";
    var downloadUrl = "https://drive.google.com/uc?export=download&id=" + fileId;
    var folderPath = "tugas/" + namaKelas + "/" + namaMapel + "/" + judulTugas + "/" + namaPengumpul + "/" + driveFile.getName();

    return responseJson({
      success: true,
      message: "Berkas tugas berhasil diunggah dan disimpan ke Google Drive!",
      data: {
        fileId: fileId,
        fileName: driveFile.getName(),
        fileSize: driveFile.getSize(),
        mimeType: driveFile.getMimeType(),
        viewUrl: viewUrl,
        previewUrl: previewUrl,
        downloadUrl: downloadUrl,
        driveUrl: driveFile.getUrl(),
        folderPath: folderPath,
        parentFolderId: folderPengumpul.getId(),
        jenisPenugasan: jenisPenugasan,
        namaPengumpul: namaPengumpul
      }
    });

  } catch (error) {
    return responseJson({
      success: false,
      message: "Terjadi kesalahan pada Google Apps Script Tugas: " + error.toString()
    });
  } finally {
    lock.releaseLock();
  }
}

function doGet(e) {
  return responseJson({
    success: true,
    status: "online",
    service: "Ilmuna Learn Google Drive Tugas & Penilaian Uploader",
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
    .replace(/[\/\\?%*:|"<>]/g, "-")
    .replace(/\s+/g, " ")
    .trim();
}

function responseJson(obj) {
  return ContentService
    .createTextOutput(JSON.stringify(obj))
    .setMimeType(ContentService.MimeType.JSON);
}
