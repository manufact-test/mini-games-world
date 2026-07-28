<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/services/GameInviteService.php';

final class UserWelcomeGuard
{
    public function __construct(private TelegramService $telegram, private array $config) {}

    public function handle(array $update): bool
    {
        if (!empty($update['callback_query'])) return false;

        $message = $update['message'] ?? null;
        if (!is_array($message)) return false;

        $chatId = trim((string)($message['chat']['id'] ?? ''));
        $fromId = trim((string)($message['from']['id'] ?? $chatId));
        $chatType = (string)($message['chat']['type'] ?? 'private');
        $text = trim((string)($message['text'] ?? ''));
        if ($chatId === '' || $chatType !== 'private') return false;

        $isAdmin = (new AdminService($this->config))->isAdmin($fromId);
        if ($isAdmin && str_starts_with($text, '/mgw_private_admin_')) return false;

        // Retained no-store rollback entrypoints:
        // '/app/v96.php?v=96', '/app/v97.php?v=97', '/app/v98.php?v=98', '/app/v99.php?v=99',
        // '/app/v100.php?v=100', '/app/v101.php?v=101', '/app/v102.php?v=102', '/app/v103.php?v=103',
        // '/app/v104.php?v=104', '/app/v105.php?v=105', '/app/v106.php?v=106', '/app/v107.php?v=107'.
        $baseWebAppUrl = rtrim((string)($this->config['base_url'] ?? ''), '/') . '/app/v108.php?v=108';
        if ($baseWebAppUrl === '/app/v108.php?v=108') return false;

        $inviteToken = '';
        if (preg_match('/^\/start(?:@[a-zA-Z0-9_]+)?\s+invite_([a-f0-9]{24})$/i', $text, $matches)) {
            $inviteToken = strtolower((string)$matches[1]);
            $this->registerInviteRecipient($message, $inviteToken);
        }

        $buttonWebAppUrl = $inviteToken !== ''
            ? $baseWebAppUrl . '&invite=' . rawurlencode($inviteToken)
            : $baseWebAppUrl;

        if (!$isAdmin) {
            try {
                $this->telegram->api('setChatMenuButton', [
                    'chat_id' => $chatId,
                    'menu_button' => [
                        'type' => 'web_app',
                        'text' => 'Играть',
                        'web_app' => ['url' => $baseWebAppUrl],
                    ],
                ]);
            } catch (Throwable $e) {
                error_log('Mini Games World user menu button setup failed for ' . $chatId . ': ' . $e->getMessage());
            }
        }

        $this->telegram->api('sendMessage', [
            'chat_id' => $chatId,
            'text' => $inviteToken !== ''
                ? "🎮 Вам бросили вызов в Mini Games World!\n\nОткройте приглашение, проверьте условия и примите матч."
                : "🎮 Mini Games World\n\nНажмите кнопку ниже, чтобы начать играть.",
            'reply_markup' => [
                'inline_keyboard' => [[
                    [
                        'text' => $inviteToken !== '' ? '🎮 Открыть приглашение' : '🎮 Начать игру',
                        'web_app' => ['url' => $buttonWebAppUrl],
                    ],
                ]],
            ],
            'disable_web_page_preview' => true,
        ]);

        return true;
    }

    private function registerInviteRecipient(array $message, string $token): void
    {
        try {
            $telegramUser = is_array($message['from'] ?? null) ? $message['from'] : [];
            $telegramUser['id'] = (string)($telegramUser['id'] ?? $message['chat']['id'] ?? '');
            if ($telegramUser['id'] === '') return;

            $db = StorageFactory::createJson((string)($this->config['data_dir'] ?? (dirname(__DIR__) . '/data')));
            $users = new UserService($this->config);
            $catalog = new GameCatalogService($this->config);
            $games = new ChessRuntimeService($this->config, $catalog, new GameService($this->config));
            $invites = new GameInviteService($this->config, $catalog, $games);

            $db->transaction(function (array &$data) use ($users, $invites, $telegramUser, $token): void {
                $user = $users->ensureUser($data, $telegramUser);
                $userId = (string)($user['id'] ?? '');
                if ($userId === '') return;
                $data['users'][$userId] = $user;
                $invites->bindFromLink($data, $data['users'][$userId], $token, false, false);
            });
        } catch (Throwable $e) {
            error_log('Mini Games World invite recipient registration failed: ' . $e->getMessage());
        }
    }
}
