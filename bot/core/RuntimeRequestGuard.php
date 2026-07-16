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
        $reason = self::blockReason($config, $script, $payload);
        if ($reason !== null) {
            self::respondBlocked(new FeatureFlagService($config), $reason);
        }
    }

    public static function blockReason(array $config, string $script, array $payload): ?string
    {
        $script = basename(trim($script));
        if (!in_array($script, ['api.php', 'invites.php'], true)) return null;

        $action = trim((string)($payload['action'] ?? ''));
        if ($action === '') return null;

        $flags = new FeatureFlagService($config);

        if ($script === 'api.php') {
            return match ($action) {
                'start_search' => $flags->newMatchBlockReason(
                    trim((string)($payload['gameType'] ?? 'tictactoe'))
                ),
                'payment_create_draft' => $flags->paymentBlockReason(),
                'shop_order' => $flags->shopBlockReason(),
                default => null,
            };
        }

        if (in_array($action, [
            'create_link_draft',
            'confirm_shared',
            'create_direct',
            'accept',
            'start',
            'rematch',
        ], true)) {
            $gameType = trim((string)($payload['gameType'] ?? ''));
            return $flags->invitationBlockReason($gameType !== '' ? $gameType : null);
        }

        return null;
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
