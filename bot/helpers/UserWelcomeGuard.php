<?php
declare(strict_types=1);

require_once __DIR__ . '/WebAppLaunchUrl.php';
require_once dirname(__DIR__) . '/services/GameInviteService.php';
require_once dirname(__DIR__) . '/social/SocialInviteGuard.php';

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

        // The accepted v110 graph remains the single user-facing /start and invite
        // application graph while Phase B presentation is migrated onto it. The
        // Telegram chat menu must not own a second Mini App URL.
        $baseWebAppUrl = WebAppLaunchUrl::base($this->config);
        if ($baseWebAppUrl === '') return false;

        $inviteToken = '';
        if (preg_match('/^\/start(?:@[a-zA-Z0-9_]+)?\s+invite_([a-f0-9]{24})$/i', $text, $matches)) {
            $inviteToken = strtolower((string)$matches[1]);
            $this->registerInviteRecipient($message, $inviteToken);
        }

        $buttonWebAppUrl = $inviteToken !== ''
            ? WebAppLaunchUrl::invitation($this->config, $inviteToken)
            : $baseWebAppUrl;

        // Older staging code installed a chat-specific Web App menu button for
        // each user. Replace that exact private-chat override with commands so
        // removal is deterministic and does not depend on any inherited default.
        try {
            $this->telegram->api('setChatMenuButton', [
                'chat_id' => $chatId,
                'menu_button' => [
                    'type' => 'commands',
                ],
            ]);
        } catch (Throwable $e) {
            error_log('Mini Games World user menu button reset failed for ' . $chatId . ': ' . $e->getMessage());
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
            $socialInviteGuard = $this->socialInviteGuard();

            $db->transaction(function (array &$data) use (
                $users,
                $invites,
                $socialInviteGuard,
                $telegramUser,
                $token
            ): void {
                $user = $users->ensureUser($data, $telegramUser);
                $userId = (string)($user['id'] ?? '');
                if ($userId === '') return;
                $data['users'][$userId] = $user;

                // The bot /start transport must enforce the same canonical social
                // block boundary as the authenticated Mini App open_link action.
                // A brand-new Telegram user may not have a DB identity yet; in
                // that case there cannot be an existing canonical block pair.
                if ($socialInviteGuard instanceof SocialInviteGuard) {
                    $actorMgwId = $socialInviteGuard->mgwIdForRuntimeSubject($userId, 'telegram');
                    if ($actorMgwId !== '') {
                        foreach ($data['invites'] ?? [] as $storedInvite) {
                            if (!is_array($storedInvite)
                                || (string)($storedInvite['token'] ?? '') !== $token) {
                                continue;
                            }
                            $inviterRuntimeId = trim((string)($storedInvite['inviter_id'] ?? ''));
                            if ($inviterRuntimeId !== '') {
                                $socialInviteGuard->assertRuntimeSubjectNotBlocked(
                                    $actorMgwId,
                                    $inviterRuntimeId,
                                    'telegram'
                                );
                            }
                            break;
                        }
                    }
                }

                // Bind without requesting an immediate modal open. The resulting
                // canonical invite_received row is intentionally left unread and
                // visible so a later ordinary Start launch hydrates the bell.
                $invites->bindFromLink($data, $data['users'][$userId], $token, false, false);
            });
        } catch (Throwable $e) {
            error_log('Mini Games World invite recipient registration failed: ' . $e->getMessage());
        }
    }

    private function socialInviteGuard(): ?SocialInviteGuard
    {
        $databaseConfig = DatabaseConfig::fromApplicationConfig($this->config);
        $router = new RuntimeStorageRouter($this->config);
        if (!$databaseConfig->enabled()
            || ($router->enabled()
                && $router->routeFor('accounts') !== RuntimeStorageRouter::DRIVER_DATABASE)) {
            return null;
        }

        return new SocialInviteGuard(PdoConnectionFactory::create($databaseConfig));
    }
}
