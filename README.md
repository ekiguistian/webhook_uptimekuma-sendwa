# Uptime Kuma → NgirimWA

Webhook Uptime Kuma mengantrekan perubahan status. api/send-queue.php mengirim satu pesan per eksekusi ke grup WhatsApp melalui NgirimWA.

## Konfigurasi

1. Salin .env.example menjadi .env.
2. Isi NGIRIMWA_API_KEY dari Dashboard NgirimWA → Perangkat → Kunci API.
3. Isi NGIRIMWA_GROUP_ID dengan ID grup WhatsApp yang berakhiran @g.us.
4. Pastikan NGIRIMWA_BASE_URL bernilai https://dash.ngirimwa.com/api/v1.

File .env tidak dimasukkan ke Git. .env.example hanya berisi placeholder.

## Keamanan file konfigurasi

.htaccess menolak akses web langsung ke .env, file antrean, status, lock, dan log pada Apache. Jika server memakai Nginx, pasang aturan penolakan yang setara atau letakkan .env di luar document root; Nginx tidak membaca .htaccess.

Jika NgirimWA mengembalikan HTTP 401/403, pengirim menghentikan retry untuk API key tersebut agar tidak memicu blokir autentikasi. Setelah memperbaiki langganan tanpa mengganti key, hapus api/ngirimwa-auth.lock untuk membuka retry.

Error sementara memakai Retry-After atau jeda bertahap. Error validasi lain tetap di antrean dengan field blocked_error untuk diperiksa sebelum dicoba ulang.
