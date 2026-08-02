<?php
declare(strict_types=1);

final class InviteStartGuard
{
    public function __construct(
        private TelegramService $telegram,
        private array $config
    ) {}

    public function handle(array $update): bool
    {
        $message = $update['message'] ?? $update['edited_message'] ?? null;
        if (!is_array($message)) {
            return false;
        }

        $chatId = $message['chat']['id'] ?? null;
        $text = trim((string)($message['text'] ?? ''));
        if ($chatId === null || $text === '') {
            return false;
        }

        if (preg_match('/\A\/start(?:@[A-Za-z0-9_]+)?\s+invite_([a-f0-9]{24})\z/i', $text, $matches) !== 1) {
            return false;
        }

        $token = strtolower((string)$matches[1]);
        $url = rtrim((string)($this->config['base_url'] ?? ''), '/')
            . '/app/?v=87&invite=' . rawurlencode($token);

        $response = $this->telegram->api('sendMessage', [
            'chat_id' => $chatId,
            'text' => "🎮 Вас пригласили сыграть в Mini Games World.\n\nОткройте приглашение кнопкой ниже.",
            'reply_markup' => [
                'inline_keyboard' => [[
                    [
                        'text' => '🎮 Открыть приглашение',
                        'web_app' => ['url' => $url],
                    ],
                ]],
            ],
            'disable_web_page_preview' => true,
        ]);

        if (empty($response['ok'])) {
            throw new RuntimeException('Telegram did not accept the invite start response.');
        }

        return true;
    }
}
