<?php
declare(strict_types=1);

final class UserWelcomeGuard
{
    public function __construct(private TelegramService $telegram, private array $config) {}

    public function handle(array $update): bool
    {
        if (!empty($update['callback_query'])) {
            return false;
        }

        $message = $update['message'] ?? null;
        if (!is_array($message)) {
            return false;
        }

        $chatId = trim((string)($message['chat']['id'] ?? ''));
        $fromId = trim((string)($message['from']['id'] ?? $chatId));
        $chatType = (string)($message['chat']['type'] ?? 'private');
        $text = trim((string)($message['text'] ?? ''));

        if ($chatId === '' || $chatType !== 'private') {
            return false;
        }

        $isAdmin = (new AdminService($this->config))->isAdmin($fromId);
        if ($isAdmin && str_starts_with($text, '/mgw_private_admin_')) {
            return false;
        }

        $webAppUrl = rtrim((string)($this->config['base_url'] ?? ''), '/') . '/app/?v=70';
        if ($webAppUrl === '/app/?v=70') {
            return false;
        }

        // Telegram itself shows the native Start button before the first message.
        // Regular players then keep a permanent "Играть" menu button in the chat.
        if (!$isAdmin) {
            try {
                $this->telegram->api('setChatMenuButton', [
                    'chat_id' => $chatId,
                    'menu_button' => [
                        'type' => 'web_app',
                        'text' => 'Играть',
                        'web_app' => ['url' => $webAppUrl],
                    ],
                ]);
            } catch (Throwable $e) {
                error_log('Mini Games World user menu button setup failed for ' . $chatId . ': ' . $e->getMessage());
            }
        }

        $this->telegram->api('sendMessage', [
            'chat_id' => $chatId,
            'text' => "🎮 Mini Games World\n\nНажмите кнопку ниже, чтобы начать играть.",
            'reply_markup' => [
                'inline_keyboard' => [[
                    [
                        'text' => '🎮 Начать игру',
                        'web_app' => ['url' => $webAppUrl],
                    ],
                ]],
            ],
            'disable_web_page_preview' => true,
        ]);

        return true;
    }
}
