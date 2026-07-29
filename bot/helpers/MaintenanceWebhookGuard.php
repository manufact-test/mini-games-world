<?php
declare(strict_types=1);

final class MaintenanceWebhookGuard
{
    public function __construct(
        private TelegramService $telegram,
        private array $config
    ) {}

    public function handle(array $update): bool
    {
        $response = self::response($this->config, $update);
        if ($response === null) return false;

        $callbackId = (string)($response['callback_query_id'] ?? '');
        if ($callbackId !== '') {
            $this->telegram->api('answerCallbackQuery', [
                'callback_query_id' => $callbackId,
                'text' => 'Технические работы',
                'show_alert' => true,
            ]);
        }

        $chatId = $response['chat_id'] ?? null;
        if ($chatId !== null && trim((string)$chatId) !== '') {
            $this->telegram->api('sendMessage', [
                'chat_id' => $chatId,
                'text' => '🛠 ' . (string)$response['message'],
                'disable_web_page_preview' => true,
            ]);
        }

        return true;
    }

    public static function response(array $config, array $update): ?array
    {
        $flags = new FeatureFlagService($config);
        if (!$flags->maintenanceEnabled()) return null;

        $message = $update['message'] ?? $update['edited_message'] ?? null;
        $callback = $update['callback_query'] ?? null;

        $chatId = is_array($message)
            ? ($message['chat']['id'] ?? null)
            : (is_array($callback) ? ($callback['message']['chat']['id'] ?? null) : null);
        $callbackId = is_array($callback) ? trim((string)($callback['id'] ?? '')) : '';

        return [
            'blocked' => true,
            'chat_id' => $chatId,
            'callback_query_id' => $callbackId,
            'message' => $flags->maintenanceMessage(),
        ];
    }
}
