<?php
require_once dirname(__DIR__) . '/api/config.php';
?>
<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8">
  <title>Webhook Uptime Kuma | Nexan</title>
  <meta name="robots" content="noindex,nofollow">
  <style>
    body {
      font-family: Arial, Helvetica, sans-serif;
      background: #0f172a;
      color: #e5e7eb;
      display: flex;
      align-items: center;
      justify-content: center;
      height: 100vh;
      margin: 0;
    }
    .box {
      background: #020617;
      border: 1px solid #1e293b;
      border-radius: 10px;
      padding: 30px 40px;
      max-width: 520px;
      text-align: center;
      box-shadow: 0 0 40px rgba(0,0,0,.5);
    }
    h1 {
      color: #38bdf8;
      margin-bottom: 10px;
    }
    p {
      color: #cbd5f5;
      line-height: 1.6;
      font-size: 14px;
    }
    .badge {
      display: inline-block;
      margin-top: 15px;
      padding: 6px 12px;
      background: #1e40af;
      color: #e0f2fe;
      border-radius: 20px;
      font-size: 12px;
      letter-spacing: .5px;
    }
    .footer {
      margin-top: 20px;
      font-size: 12px;
      color: #64748b;
    }
    code {
      display: inline-block;
      padding: 6px 10px;
      border-radius: 6px;
      background: #0f172a;
      color: #bae6fd;
      overflow-wrap: anywhere;
    }
  </style>
</head>
<body>
  <div class="box">
    <h1>Webhook Uptime Kuma</h1>
    <p>
      Domain <strong>webhook.nexan.id</strong> menyediakan endpoint webhook
      untuk meneruskan notifikasi perubahan status dari Uptime Kuma ke WhatsApp.
    </p>
    <p>
      Alamat yang dipasang di konfigurasi notifikasi Uptime Kuma:
      <br><code>https://webhook.nexan.id/api/uptime.php</code>
    </p>
    <p>
      Halaman ini hanya informasi umum. Membukanya di browser tidak mengirim
      pesan dan tidak memeriksa jadwal cron atau koneksi WhatsApp.
    </p>
    <div class="badge">UPTIME KUMA WEBHOOK · INFO</div>
    <div class="footer">
      © Nexan Network Monitoring · System Endpoint
    </div>
  </div>
</body>
</html>
