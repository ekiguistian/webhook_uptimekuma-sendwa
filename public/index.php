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
      max-width: 980px;
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
    .note { margin: 10px 0 0; color: #94a3b8; font-size: 12px; line-height: 1.6; }
    .monitor {
      margin-top: 28px;
      padding: 20px;
      border: 1px solid #1e293b;
      border-radius: 10px;
      background: #0b1220;
      text-align: left;
    }
    .monitor-heading { display: flex; align-items: baseline; justify-content: space-between; gap: 16px; margin-bottom: 14px; }
    .monitor h2 { margin: 0; color: #e2e8f0; font-size: 20px; }
    .updated { color: #94a3b8; font-size: 12px; }
    .monitor-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 10px; }
    .monitor-card {
      min-height: 102px;
      box-sizing: border-box;
      padding: 14px;
      border: 1px solid #263449;
      border-radius: 8px;
      background: #111b2d;
    }
    .monitor-card h3 { margin: 0 0 12px; color: #cbd5e1; font-size: 13px; font-weight: 600; }
    .metric-value { display: block; color: #f8fafc; font-size: 24px; font-weight: 700; }
    .metric-state { display: block; margin-top: 7px; color: #94a3b8; font-size: 12px; }
    .metric-state.up, .config-value.ready { color: #86efac; }
    .metric-state.down, .config-value.missing { color: #fca5a5; }
    .config-list { display: grid; grid-template-columns: repeat(auto-fit, minmax(190px, 1fr)); gap: 8px 18px; margin-top: 18px; }
    .config-item { display: flex; justify-content: space-between; gap: 12px; padding: 8px 0; border-bottom: 1px solid #1e293b; color: #cbd5e1; font-size: 13px; }
    .config-value { color: #94a3b8; font-weight: 700; text-align: right; }
    @media (max-width: 600px) {
      .box { padding: 20px; }
      .monitor { padding: 15px; }
      .monitor-heading { display: block; }
      .updated { display: block; margin-top: 6px; }
    }
  </style>
</head>
<body>
  <main class="box">
    <h1>Webhook Uptime Kuma</h1>

    <label for="webhook-url">URL Webhook</label>
    <a class="webhook-url" id="webhook-url" href="#">Memuat URL...</a>
    <button class="copy" id="copy-webhook-url" type="button">Salin URL</button>
    <p class="message" id="url-copy-status" role="status" aria-live="polite"></p>
    <p class="note">Agar URL ini dapat diakses, aktifkan URL rewrite. Apache: edit <code>public/.htaccess</code>. Nginx: tambahkan aturan di konfigurasi server domain. Petunjuk ada di README.</p>

    <section class="monitor" aria-labelledby="monitor-title">
      <div class="monitor-heading">
        <h2 id="monitor-title">Live Monitor</h2>
        <span class="updated" id="monitor-updated">Menunggu data worker cron...</span>
      </div>
      <div class="monitor-grid">
        <article class="monitor-card">
          <h3>DNS 1.1.1.1</h3>
          <strong class="metric-value" id="metric-dns">—</strong>
          <span class="metric-state" id="state-dns">Menunggu worker</span>
        </article>
        <article class="monitor-card">
          <h3>Koneksi NgirimWA</h3>
          <strong class="metric-value" id="metric-ngirimwa">—</strong>
          <span class="metric-state" id="state-ngirimwa">Menunggu worker</span>
        </article>
        <article class="monitor-card">
          <h3>Koneksi Uptime Kuma</h3>
          <strong class="metric-value" id="metric-uptime-kuma">—</strong>
          <span class="metric-state" id="state-uptime-kuma">Menunggu worker</span>
        </article>
        <article class="monitor-card">
          <h3>Pesan berhasil</h3>
          <strong class="metric-value" id="messages-success">0</strong>
          <span class="metric-state">Total pengiriman diterima API</span>
        </article>
        <article class="monitor-card">
          <h3>Pesan gagal</h3>
          <strong class="metric-value" id="messages-failure">0</strong>
          <span class="metric-state">Total pengiriman gagal</span>
        </article>
      </div>

      <div class="config-list" aria-label="Status konfigurasi">
        <div class="config-item"><span>URL API NgirimWA</span><span class="config-value" data-config="ngirimwa_api_url">Memeriksa...</span></div>
        <div class="config-item"><span>API key NgirimWA</span><span class="config-value" data-config="api_key">Memeriksa...</span></div>
        <div class="config-item"><span>Token webhook</span><span class="config-value" data-config="webhook_token">Memeriksa...</span></div>
        <div class="config-item"><span>Group ID WhatsApp</span><span class="config-value" data-config="group_id">Memeriksa...</span></div>
        <div class="config-item"><span>URL Uptime Kuma</span><span class="config-value" data-config="uptime_kuma_url">Memeriksa...</span></div>
      </div>
      <p class="note">Data diperbarui worker setiap 5 detik. DNS 1.1.1.1 diukur dengan kueri UDP, bukan ICMP. Latensi koneksi domain dipisahkan dari DNS; nilai API key, token, dan group ID tidak ditampilkan.</p>
    </section>

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
    <?php endif; ?>
  </main>

  <script>
    const webhookUrl = `${window.location.origin}/webhook`;
    const webhookLink = document.getElementById('webhook-url');
    webhookLink.href = webhookUrl;
    webhookLink.textContent = webhookUrl;

    async function copyText(value) {
      if (navigator.clipboard && window.isSecureContext) {
        await navigator.clipboard.writeText(value);
        return;
      }

      const temporaryField = document.createElement('textarea');
      temporaryField.value = value;
      temporaryField.setAttribute('readonly', '');
      temporaryField.style.position = 'fixed';
      temporaryField.style.opacity = '0';
      document.body.appendChild(temporaryField);
      temporaryField.select();
      const copied = document.execCommand('copy');
      temporaryField.remove();
      if (!copied) {
        throw new Error('Clipboard copy failed');
      }
    }

    document.getElementById('copy-webhook-url').addEventListener('click', async function () {
      const status = document.getElementById('url-copy-status');
      try {
        await copyText(webhookUrl);
        status.textContent = 'URL webhook berhasil disalin.';
      } catch (error) {
        status.textContent = 'URL tidak dapat disalin otomatis. Silakan salin dari tautan di atas.';
      }
    });

    const tokenField = document.getElementById('webhook-token');
    if (tokenField) {
      document.getElementById('copy-token').addEventListener('click', async function () {
        const status = document.getElementById('copy-status');
        try {
          await copyText(tokenField.value);
          status.textContent = 'Token berhasil disalin.';
        } catch (error) {
          tokenField.focus();
          tokenField.select();
          status.textContent = 'Salin token yang terseleksi secara manual.';
        }
      });
    }

    function updateMonitorCheck(name, check) {
      const value = document.getElementById(`metric-${name}`);
      const state = document.getElementById(`state-${name}`);
      if (!check || check.ok === null) {
        value.textContent = '—';
        state.textContent = check && check.configured === false ? 'Belum diatur' : 'Menunggu worker';
        state.className = 'metric-state';
        return;
      }

      if (check.ok === true) {
        value.textContent = Number.isFinite(Number(check.ms)) ? `${Number(check.ms).toFixed(2)} ms` : 'Terhubung';
        state.textContent = 'Terhubung';
        state.className = 'metric-state up';
      } else {
        value.textContent = '—';
        state.textContent = check.configured === false ? 'Belum diatur' : 'Tidak terhubung';
        state.className = check.configured === false ? 'metric-state' : 'metric-state down';
      }
    }

    async function refreshLiveMonitor() {
      try {
        const response = await fetch(`/api/live-status.php?t=${Date.now()}`, {
          method: 'GET',
          cache: 'no-store',
          headers: { 'Accept': 'application/json' }
        });
        if (!response.ok) throw new Error('Live monitor unavailable');
        const data = await response.json();

        updateMonitorCheck('dns', data.checks && data.checks.dns);
        updateMonitorCheck('ngirimwa', data.checks && data.checks.ngirimwa);
        updateMonitorCheck('uptime-kuma', data.checks && data.checks.uptime_kuma);

        const counts = new Intl.NumberFormat('id-ID');
        document.getElementById('messages-success').textContent = counts.format(data.messages?.success ?? 0);
        document.getElementById('messages-failure').textContent = counts.format(data.messages?.failure ?? 0);

        document.querySelectorAll('[data-config]').forEach(function (element) {
          const configured = Boolean(data.configuration && data.configuration[element.dataset.config]);
          const defaultApiUrl = element.dataset.config === 'ngirimwa_api_url'
            && Boolean(data.configuration && data.configuration.ngirimwa_api_url_default);
          element.textContent = configured
            ? 'Sudah diatur'
            : (defaultApiUrl ? 'Belum diatur · default aktif' : 'Belum diatur');
          element.className = configured || defaultApiUrl ? 'config-value ready' : 'config-value missing';
        });

        const updated = document.getElementById('monitor-updated');
        if (data.checked_at) {
          const checkedDate = new Date(data.checked_at);
          const ageSeconds = Math.max(0, Math.floor((Date.now() - checkedDate.getTime()) / 1000));
          const displayTime = checkedDate.toLocaleTimeString('id-ID');
          updated.textContent = ageSeconds > 15
            ? `Data worker tertinggal · terakhir ${displayTime}`
            : `Update ${displayTime} · ${ageSeconds} dtk lalu`;
        } else {
          updated.textContent = 'Worker monitor belum dijalankan';
        }
      } catch (error) {
        document.getElementById('monitor-updated').textContent = 'Data monitor tidak dapat dimuat';
      }
    }

    refreshLiveMonitor();
    window.setInterval(refreshLiveMonitor, 5000);
  </script>
</body>
</html>
