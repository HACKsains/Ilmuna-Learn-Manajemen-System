# Margo One SSO ↔ Ilmuna Learn Real-Time API & WebSocket SDK

Dokumentasi resmi integrasi **REST API Zero-Trust (Public Key + Private Key / RSA-2048)** dan **WebSocket Real-Time Push (RFC 6455, Zero Polling)** antara `https://learn.ilmuna.my.id` dan `https://margo-one.ilmuna.my.id`.

## 1. Keamanan Berlapis (Zero-Trust)
- **Public Key (`pk_sso_*`)** + **Private Key (`sk_sso_*`, 256-bit AES-256 encrypted at-rest)**
- **Mode Tanda Tangan**: `HMAC-SHA256`, `RSA-SHA256` (RSA-2048 PEM), atau `DUAL` (HMAC + RSA wajib bersamaan)
- **Anti-Replay**: `X-Ilmuna-Timestamp` (+/- 300 detik) + `X-Ilmuna-Nonce` (sekali pakai di tabel `sso_api_nonces`)
- **Domain / Origin / IP Whitelist** + **Rate Limiter** + **Signed JSON Response** (`X-Ilmuna-Response-Signature`)
- **Ephemeral Single-Use WebSocket Ticket (`wst_*`)**: Browser SSO hanya memakai tiket 120 detik sekali pakai untuk membuka WebSocket sehingga **Private Key tidak pernah bocor ke browser**.

## 2. Endpoint REST API
`GET /api/sso-broadcast.php?resource=...`

| Resource | Kegunaan |
| :--- | :--- |
| `health` | Status API, jam server, mode signature, status daemon WebSocket |
| `classes` | Daftar seluruh master rombel kelas di `learn` |
| `tugas_broadcast` | Broadcast & deadline tugas + pembagian kelompok tugas sesuai kelas |
| `proyek_broadcast` | Broadcast & deadline proyek P5/PjBL + kelompok proyek sesuai kelas |
| `kelompok_broadcast` | Rekap kelompok tugas & proyek beserta peran ketua/anggota |
| `jadwal_asesmen` | Jadwal Sumatif, Formatif, Diagnostik, Pengayaan/Remedial dari Bank & Master Asesmen sesuai kelas |
| `student_feed` / `all` | Gabungan lengkap + `upcoming_deadlines` + `recent_notifications` (+ `include_ws_ticket=1`) |
| `ws_ticket` | Terbitkan Single-Use WebSocket Ticket terikat pada `kelas_id` pengguna SSO |

### Filter Identitas / Kelas Otomatis
Kirim salah satu parameter query berikut agar payload **otomatis difilter sesuai kelas siswa/guru yang login di SSO**:
`?nis=26271001` atau `?nisn=3405220` atau `?nip=...` atau `?email=...` atau `?kelas_id=12` atau `?kode_kelas=XI-A`.

## 3. Contoh Pemakaian di Backend Margo One SSO (`margo-one.ilmuna.my.id`)
```php
require_once __DIR__ . '/IlmunaLearnSsoNotifierClient.php';

$client = new IlmunaLearnSsoNotifierClient([
    'endpoint_url'    => 'https://learn.ilmuna.my.id/api/sso-broadcast.php',
    'public_key'      => 'pk_sso_...',
    'private_key'     => 'sk_sso_...',
    'sig_mode'        => 'HMAC-SHA256', // atau 'RSA-SHA256' / 'DUAL'
    'rsa_private_pem' => '',            // wajib untuk RSA-SHA256 / DUAL
    'site_host'       => 'margo-one.ilmuna.my.id',
]);

$feed = $client->getStudentFeedWithWebSocketTicket([
    'nis'      => $ssoUser['nisn_nip'],
    'email'    => $ssoUser['email'],
    'role'     => $ssoUser['active_role'],
]);

$wsConnectUrl  = $feed['ws_ticket']['ws_connect_url'];
$initialNotifs = $feed['recent_notifications'];
```

## 4. Menjalankan WebSocket Server & Reverse Proxy Produksi
Jalankan daemon PHP murni (tanpa Composer):
```bash
php websocket/sso-realtime-server.php
```
Untuk Apache/LiteSpeed reverse proxy WSS pada domain produksi `wss://learn.ilmuna.my.id/ws/sso`:
```apache
RewriteEngine On
RewriteCond %{HTTP:Upgrade} =websocket [NC]
RewriteRule ^ws/sso/?$ ws://127.0.0.1:8090/ [P,L]
```
