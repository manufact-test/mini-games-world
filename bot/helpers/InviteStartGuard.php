<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/localization/ServerLocalization.php';

require_once __DIR__ . '/WebAppLaunchUrl.php';

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
        $url = WebAppLaunchUrl::invitation($this->config, $token);
        if ($url === '') {
            throw new RuntimeException('Mini Games World invite Web App URL is unavailable.');
        }

        $response = $this->telegram->api('sendMessage', [
            'chat_id' => $chatId,
            'text' => ServerLocalization::copy('server.invites.start_text', "🎮 You were invited to play Mini Games World.\n\nOpen the invitation using the button below."),
            'reply_markup' => [
                'inline_keyboard' => [[
                    [
                        'text' => ServerLocalization::copy('server.invites.start_button', '🎮 Open invitation'),
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
