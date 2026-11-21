# Panduan kontribusi

Kontribusi berupa perbaikan bug, dokumentasi, dan usulan fitur dapat dikirim melalui GitHub Issues atau Pull Request.

## Usulan perubahan

- Jelaskan masalah atau kebutuhan yang ingin ditangani.
- Untuk perubahan besar, diskusikan rancangan melalui issue sebelum membuka Pull Request.
- Sertakan ringkasan perubahan dan cara memeriksanya.
- Perbarui README bila perubahan mengubah konfigurasi, alur kerja, atau kebutuhan server.

## Keamanan dan konfigurasi

- Jangan sertakan `.env`, API key, token webhook, log server, atau data penerima pesan dalam commit dan issue.
- Gunakan `.env.example` untuk nama konfigurasi tanpa nilai rahasia.
- Laporkan masalah keamanan secara privat melalui fitur pelaporan kerentanan GitHub bila tersedia di repository ini.

## Pull Request

Pull Request sebaiknya berisi perubahan yang terfokus, deskripsi dampaknya, dan informasi pemeriksaan yang dilakukan. Perubahan pada alur pengiriman perlu mempertahankan perilaku gagal-aman: pesan yang gagal tidak dikirim ulang tanpa keputusan yang disengaja.
