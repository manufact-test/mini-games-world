<?php
declare(strict_types=1);

final class RuntimeRequestGuard
{
    public static function enforce(array $config, array $server): void
    {
        $method = strtoupper((string)($server['REQUEST_METHOD'] ?? ''));
        if ($method === '' || PHP_SAPI === 'cli') return;

        $path = (string)(parse_url((string)($server['REQUEST_URI'] ?? ''), PHP_URL_PATH) ?? '');
        $script = basename($path !== '' ? $path : (string)($server['SCRIPT_NAME'] ?? ''));
        if (!in_array($script, ['api.php', 'invites.php'], true)) return;

        $payload = self::payload();
        $action = trim((string)($payload['action'] ?? ''));
        if ($action === '') return;

        $flags = new FeatureFlagService($config);
        $reason = null;

        if ($script === 'api.php') {
            if ($action === 'start_search') {
                $reason = $flags->newMatchBlockReason(trim((string)($payload['gameType'] ?? 'tictactoe')));
            } elseif ($action === 'payment_create_draft') {
                $reason = $flags->paymentBlockReason();
            } elseif ($action === 'shop_order') {
                $reason = $flags->shopBlockReason();
            }
        }

        if ($script === 'invites.php' && in_array($action, [
            'create_link_draft',
            'confirm_shared',
            'create_direct',
            'accept',
            'start',
            'rematch',
        ], true)) {
            $gameType = trim((string)($payload['gameType'] ?? ''));
            $reason = $flags->invitationBlockReason($gameType !== '' ? $gameType : null);
        }

        if ($reason !== null) self::respondBlocked($flags, $reason);
    }

    private static function payload(): array
    {
        $raw = file_get_contents('php://input') ?: '{}';
        $payload = json_decode($raw, true);
        return is_array($payload) ? $payload : [];
    }

    private static function respondBlocked(FeatureFlagService $flags, string $reason): never
    {
        http_response_code($flags->maintenanceEnabled() ? 503 : 409);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        echo json_encode([
            'ok' => false,
            'error' => $reason,
            'runtime' => $flags->publicStatus(),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}
