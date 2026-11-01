# PANDUAN KONEKSI SSH — ILMUNA SYSTEM & LEARN.ILMUNA.MY.ID

## Informasi Kredensial Server:
* **Host / Server IP**: `163.223.227.36` (atau `furina.kawaiihost.net`)
* **SSH Port**: **`6401`** *(Port khusus SSH hosting cPanel AnymHost / KawaiiHost)*
* **Username**: `uofruaju`
* **Lokasi Kunci Privat di Laptop**: `C:\Users\Syahriel Andika\.ssh\id_rsa`
* **Passphrase Asal**: `Ilmuna28@` *(Kunci telah diotentikasi & disinkronkan)*
* **Direktori Home Server**: `/home/uofruaju`
* **Direktori Web Subdomain**: `/home/uofruaju/learn.ilmuna.my.id/`

---

## 3 Cara Masuk ke SSH Kapan Saja:

### Cara 1: Cukup Ketik 1 Perintah (Sangat Praktis)
Buka PowerShell atau Command Prompt di mana saja, lalu ketik:
```bash
ssh ilmuna
```
*(atau bisa juga `ssh learn`)*
Perintah ini akan langsung menghubungkan Anda ke server tanpa perlu menghafal IP, Port, Username, atau mengetik password!

### Cara 2: Klik Ganda Shortcut `connect-ssh.bat`
Klik ganda file shortcut berikut di File Explorer:
[`c:\Users\Syahriel Andika\Videos\Ilmuna System\connect-ssh.bat`](file:///c:/Users/Syahriel%20Andika/Videos/Ilmuna%20System/connect-ssh.bat)
Jendela terminal hitam akan langsung terbuka dan masuk ke session `/home/uofruaju`.

### Cara 3: Perintah Manual Lengkap
```powershell
ssh -i "$env:USERPROFILE\.ssh\id_rsa" -p 6401 uofruaju@163.223.227.36
```

---

## Bagaimana Koneksi Ini Bekerja?
1. **Otorisasi Kunci**: Kunci publik `id_rsa.pub` di-authorize di cPanel SSH Access server.
2. **Penetapan Port Kustom**: Hosting AnymHost/KawaiiHost tidak memakai port default `22`, melainkan port **`6401`**. Port ini dideteksi dan diverifikasi terbuka.
3. **Penyimpanan Kunci Lokal**: Berkas privat `id_rsa` dari folder Downloads telah dipindahkan ke direktori standar SSH Windows: `C:\Users\Syahriel Andika\.ssh\id_rsa` dan hak akses ACL telah diamankan.
4. **Konfigurasi Alias Global**: Berkas `C:\Users\Syahriel Andika\.ssh\config` telah dibuat sehingga Windows OpenSSH mengenali alias `ilmuna` secara otomatis.
