<?php
declare(strict_types=1);

final class TelegramService
{
    public function __construct(private array $config) {}

    public function api(string $method, array $params = []): array
    {
        $token = (string)$this->config['bot_token'];
        if ($token === '' || $token === 'PASTE_BOT_TOKEN_HERE') {
            throw new RuntimeException('Не указан токен бота в config.php.');
        }

        $url = 'https://api.telegram.org/bot' . $token . '/' . $method;
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($params, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
        ]);

        $raw = curl_exec($ch);
        $error = curl_error($ch);
        curl_close($ch);

        if ($raw === false) {
            throw new RuntimeException('Telegram API error: ' . $error);
        }

        $data = json_decode($raw, true);
        if (!is_array($data)) {
            throw new RuntimeException('Telegram API returned invalid JSON.');
        }

        return $data;
    }

    public function sendStartMessage(int|string $chatId): void
    {
        $webAppUrl = rtrim((string)$this->config['base_url'], '/') . '/app/?v=21';

        $this->api('sendMessage', [
            'chat_id' => $chatId,
            'text' => "Добро пожаловать в Mini Games World.\n\nОткройте приложение и сыграйте первый матч.",
            'reply_markup' => [
                'inline_keyboard' => [[
                    ['text' => 'Открыть игру', 'web_app' => ['url' => $webAppUrl]],
                ]],
            ],
        ]);
    }
}
