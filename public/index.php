<?php

require_once dirname(__DIR__) . '/api/config.php';

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');

$token = null;
$generationFailed = false;
$unsupportedMethod = !in_array($_SERVER['REQUEST_METHOD'] ?? '', ['GET', 'POST'], true);

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    try {
        $token = bin2hex(random_bytes(32));
    } catch (Throwable $exception) {
        app_log('system', 'Webhook token generation failed');
        http_response_code(500);
        $generationFailed = true;
    }
} elseif ($unsupportedMethod) {
    header('Allow: GET, POST');
    http_response_code(405);
}

?>
<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex,nofollow,noarchive">
  <meta http-equiv="Cache-Control" content="no-store, no-cache, must-revalidate">
  <title>Webhook Uptime Kuma</title>
  <style>
    body {
      min-height: 100vh;
      box-sizing: border-box;
      display: flex;
      align-items: center;
      justify-content: center;
      margin: 0;
      padding: 24px;
      background: #0f172a;
      color: #e5e7eb;
      font-family: Arial, Helvetica, sans-serif;
    }
    .box {
      width: 100%;
      max-width: 560px;
      box-sizing: border-box;
      padding: 30px;
      border: 1px solid #1e293b;
      border-radius: 10px;
      background: #020617;
      box-shadow: 0 0 40px rgba(0, 0, 0, .5);
      text-align: center;
    }
    h1 { margin-top: 0; color: #38bdf8; }
    label { display: block; margin: 22px 0 8px; font-size: 14px; font-weight: 700; }
    .webhook-url, input {
      display: block;
      width: 100%;
      box-sizing: border-box;
      padding: 12px;
      border: 1px solid #334155;
      border-radius: 6px;
      background: #0f172a;
      color: #bae6fd;
      font-family: Consolas, monospace;
      font-size: 14px;
      overflow-wrap: anywhere;
    }
    .webhook-url { text-decoration: underline; }
    input { margin-top: 22px; }
    button {
      display: inline-block;
      margin: 14px 4px 0;
      padding: 11px 16px;
      border: 0;
      border-radius: 7px;
      background: #0284c7;
      color: #fff;
      font-size: 14px;
      font-weight: 700;
      cursor: pointer;
    }
    button:hover { background: #0369a1; }
    button.copy { background: #334155; }
    button.copy:hover { background: #475569; }
    button:disabled { opacity: .55; cursor: not-allowed; }
    .message { min-height: 20px; margin: 14px 0 0; color: #86efac; font-size: 14px; }
    .error { color: #fca5a5; }
  </style>
</head>
<body>
  <main class="box">
    <h1>Webhook Uptime Kuma</h1>

    <label for="webhook-url">URL Webhook</label>
    <a class="webhook-url" id="webhook-url" href="#">Memuat URL...</a>

    <form method="post" action="/" autocomplete="off">
      <button type="submit">Generate Token</button>
    </form>

    <?php if ($generationFailed): ?>
      <p class="message error" role="alert">Token tidak dapat dibuat. Silakan coba lagi.</p>
    <?php elseif ($unsupportedMethod): ?>
      <p class="message error" role="alert">Metode request tidak didukung.</p>
    <?php endif; ?>

    <?php if ($token !== null): ?>
      <input id="webhook-token" type="text" value="<?= htmlspecialchars($token, ENT_QUOTES, 'UTF-8') ?>" readonly autocomplete="off" spellcheck="false" aria-label="Token webhook">
      <button class="copy" id="copy-token" type="button">Salin Token</button>
      <p class="message" id="copy-status" role="status" aria-live="polite">Simpan token ini sebelum meninggalkan halaman.</p>
      <script>
        document.getElementById('copy-token').addEventListener('click', async function () {
          const field = document.getElementById('webhook-token');
          const status = document.getElementById('copy-status');
          try {
            if (navigator.clipboard && window.isSecureContext) {
              await navigator.clipboard.writeText(field.value);
            } else {
              field.focus();
              field.select();
              if (!document.execCommand('copy')) {
                throw new Error('Clipboard copy failed');
              }
            }
            status.textContent = 'Token berhasil disalin.';
          } catch (error) {
            field.focus();
            field.select();
            status.textContent = 'Salin token yang terseleksi secara manual.';
          }
        });
      </script>
    <?php endif; ?>
  </main>

  <script>
    const webhookUrl = `${window.location.origin}/webhook`;
    const webhookLink = document.getElementById('webhook-url');
    webhookLink.href = webhookUrl;
    webhookLink.textContent = webhookUrl;
  </script>
</body>
</html>
