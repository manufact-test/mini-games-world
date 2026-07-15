<?php
declare(strict_types=1);

require dirname(__DIR__) . '/core/bootstrap.php';
require_once dirname(__DIR__) . '/core/StagingSetupGuard.php';

header('X-Robots-Tag: noindex, nofollow, noarchive', true);
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0', true);
header('Pragma: no-cache', true);

try {
    StagingSetupGuard::assertStaging($config);
} catch (Throwable $e) {
    http_response_code(404);
    exit;
}

$message = '';
$messageType = 'neutral';
$status = null;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    try {
        $setupKey = trim((string)($_POST['setup_key'] ?? ''));
        $action = trim((string)($_POST['action'] ?? 'status'));
        StagingSetupGuard::authorize($config, $setupKey);

        $telegram = new TelegramService($config);
        $bot = mgw_staging_telegram_result($telegram->api('getMe'));
        StagingSetupGuard::assertExpectedBot($config, $bot);
        $expectedWebhookUrl = StagingSetupGuard::webhookUrl($config);

        if ($action === 'install') {
            mgw_staging_telegram_result($telegram->api('setWebhook', [
                'url' => $expectedWebhookUrl,
                'drop_pending_updates' => false,
            ]));
            $message = 'Staging webhook installed.';
            $messageType = 'success';
        } elseif ($action === 'remove') {
            mgw_staging_telegram_result($telegram->api('deleteWebhook', [
                'drop_pending_updates' => false,
            ]));
            $message = 'Staging webhook removed.';
            $messageType = 'success';
        } elseif ($action !== 'status') {
            throw new RuntimeException('Unsupported staging setup action.');
        }

        $webhook = mgw_staging_telegram_result($telegram->api('getWebhookInfo'));
        $actualWebhookUrl = trim((string)($webhook['url'] ?? ''));
        $status = [
            'environment' => (string)($config['environment'] ?? ''),
            'bot_username' => '@' . ltrim((string)($bot['username'] ?? ''), '@'),
            'expected_webhook' => $expectedWebhookUrl,
            'actual_webhook' => $actualWebhookUrl !== '' ? $actualWebhookUrl : 'not installed',
            'webhook_matches' => $actualWebhookUrl !== '' && hash_equals($expectedWebhookUrl, $actualWebhookUrl),
            'pending_updates' => (int)($webhook['pending_update_count'] ?? 0),
            'last_error' => trim((string)($webhook['last_error_message'] ?? '')),
        ];

        if ($message === '') {
            $message = 'Staging bot and webhook status loaded.';
            $messageType = 'success';
        }
    } catch (Throwable $e) {
        http_response_code(400);
        $message = $e->getMessage();
        $messageType = 'error';
    }
}

function mgw_staging_telegram_result(array $response): array
{
    if (empty($response['ok'])) {
        $description = trim((string)($response['description'] ?? ''));
        throw new RuntimeException($description !== '' ? $description : 'Telegram API request failed.');
    }

    $result = $response['result'] ?? [];
    return is_array($result) ? $result : [];
}

function mgw_staging_escape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex,nofollow,noarchive">
  <title>MGW staging webhook</title>
  <style>
    body{font-family:Arial,sans-serif;max-width:760px;margin:40px auto;padding:0 18px;background:#111827;color:#f9fafb}
    .card{padding:22px;border:1px solid #374151;border-radius:16px;background:#1f2937}
    input,button{box-sizing:border-box;width:100%;padding:12px;border-radius:10px;border:1px solid #4b5563;font:inherit}
    input{background:#111827;color:#fff;margin:8px 0 12px}button{cursor:pointer;font-weight:700;margin-top:8px}
    .success{color:#86efac}.error{color:#fca5a5}.neutral{color:#cbd5e1}
    dl{display:grid;grid-template-columns:minmax(150px,220px) 1fr;gap:8px 14px;overflow-wrap:anywhere}
    dt{color:#9ca3af}dd{margin:0}.actions{display:grid;grid-template-columns:repeat(3,1fr);gap:8px}
    @media(max-width:560px){.actions{grid-template-columns:1fr}dl{grid-template-columns:1fr}}
  </style>
</head>
<body>
  <div class="card">
    <h1>Mini Games World staging</h1>
    <p>This tool works only in the staging environment and never displays bot tokens.</p>
    <?php if ($message !== ''): ?>
      <p class="<?= mgw_staging_escape($messageType) ?>"><?= mgw_staging_escape($message) ?></p>
    <?php endif; ?>
    <form method="post" autocomplete="off">
      <label for="setup_key">One-time setup key</label>
      <input id="setup_key" name="setup_key" type="password" required autocomplete="current-password">
      <div class="actions">
        <button name="action" value="status" type="submit">Check status</button>
        <button name="action" value="install" type="submit">Install webhook</button>
        <button name="action" value="remove" type="submit">Remove webhook</button>
      </div>
    </form>
    <?php if (is_array($status)): ?>
      <h2>Status</h2>
      <dl>
        <?php foreach ($status as $label => $value): ?>
          <dt><?= mgw_staging_escape(str_replace('_', ' ', $label)) ?></dt>
          <dd><?= mgw_staging_escape(is_bool($value) ? ($value ? 'yes' : 'no') : (string)$value) ?></dd>
        <?php endforeach; ?>
      </dl>
    <?php endif; ?>
  </div>
</body>
</html>
