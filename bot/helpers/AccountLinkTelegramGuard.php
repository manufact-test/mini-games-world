<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/accounts/AccountLinkService.php';
require_once dirname(__DIR__) . '/localization/ServerLocalization.php';

final class AccountLinkTelegramGuard
{
    private const START_PATTERN = '/^\/start(?:@[A-Za-z0-9_]+)?\s+link_([A-Za-z0-9_-]{32})$/';
    private const CALLBACK_PATTERN = '/^account_link:(confirm|cancel):(lnk_[a-f0-9]{20})$/';

    public function __construct(
        private TelegramService $telegram,
        private array $config
    ) {}

    public function handle(array $update): bool
    {
        if (!empty($update['callback_query']) && is_array($update['callback_query'])) {
            return $this->handleCallback($update['callback_query']);
        }

        $message = $update['message'] ?? null;
        if (!is_array($message)) return false;

        $chatId = trim((string)($message['chat']['id'] ?? ''));
        $fromId = trim((string)($message['from']['id'] ?? $chatId));
        $chatType = trim((string)($message['chat']['type'] ?? ''));
        $text = trim((string)($message['text'] ?? ''));
        if ($chatId === '' || $fromId === '' || $chatType !== 'private') return false;

        if (preg_match(self::START_PATTERN, $text, $matches) !== 1) return false;

        try {
            $claimed = $this->service()->claimTelegramToken((string)$matches[1], $fromId);
            $challengeId = (string)$claimed['challenge_id'];
            $playerFallback = ServerLocalization::copy('server.account_chain.common.player_fallback', 'Player');
            $nickname = trim((string)($claimed['target_nickname'] ?? $playerFallback));
            if ($nickname === '') $nickname = $playerFallback;

            $this->telegram->api('sendMessage', [
                'chat_id'=>$chatId,
                'text'=>ServerLocalization::copy(
                    'server.account_link.telegram.prompt',
                    "Do you confirm signing the Android app into your MGW profile “{nickname}”?\n\nAfter confirmation, Android will use the same profile, balance, purchases, statistics and rating. Temporary 1000 starter coins from the Android profile are not transferred.",
                    ['nickname'=>$nickname]
                ),
                'reply_markup'=>[
                    'inline_keyboard'=>[
                        [[
                            'text'=>ServerLocalization::copy('server.account_link.telegram.confirm_button', '✅ Confirm linking'),
                            'callback_data'=>'account_link:confirm:' . $challengeId,
                        ]],
                        [[
                            'text'=>ServerLocalization::copy('server.account_link.telegram.cancel_button', 'Cancel'),
                            'callback_data'=>'account_link:cancel:' . $challengeId,
                        ]],
                    ],
                ],
                'disable_web_page_preview'=>true,
            ]);
        } catch (AccountLinkException $error) {
            $this->telegram->api('sendMessage', [
                'chat_id'=>$chatId,
                'text'=>ServerLocalization::copy('server.account_link.telegram.start_failed_prefix', 'Could not start linking: {message}', ['message'=>$this->publicMessage($error)]),
            ]);
        } catch (Throwable $error) {
            error_log('[MiniGamesWorld account link Telegram] ' . $error::class . ': ' . $error->getMessage());
            $this->telegram->api('sendMessage', [
                'chat_id'=>$chatId,
                'text'=>ServerLocalization::copy('server.account_link.telegram.start_failed_retry', 'Could not start linking. Return to Android and create a new attempt.'),
            ]);
        }

        return true;
    }

    private function handleCallback(array $callback): bool
    {
        $data = trim((string)($callback['data'] ?? ''));
        if (preg_match(self::CALLBACK_PATTERN, $data, $matches) !== 1) return false;

        $callbackId = trim((string)($callback['id'] ?? ''));
        $fromId = trim((string)($callback['from']['id'] ?? ''));
        $chatId = trim((string)($callback['message']['chat']['id'] ?? ''));
        $messageId = (int)($callback['message']['message_id'] ?? 0);
        $action = (string)$matches[1];
        $challengeId = (string)$matches[2];

        if ($fromId === '' || $chatId === '') return true;

        try {
            if ($action === 'confirm') {
                $this->service()->confirmTelegramChallenge($challengeId, $fromId);
                $text = ServerLocalization::copy('server.account_link.telegram.confirmed_text', "✅ Linking confirmed.\n\nReturn to the Android app and finish linking.");
                $answer = ServerLocalization::copy('server.account_link.telegram.confirmed_answer', 'Confirmed');
            } else {
                $this->service()->cancelTelegramChallenge($challengeId, $fromId);
                $text = ServerLocalization::copy('server.account_link.telegram.cancelled_text', "Linking cancelled.\n\nIf you change your mind, start a new attempt from the Android app.");
                $answer = ServerLocalization::copy('server.account_link.telegram.cancelled_answer', 'Cancelled');
            }

            if ($callbackId !== '') {
                $this->telegram->api('answerCallbackQuery', [
                    'callback_query_id'=>$callbackId,
                    'text'=>$answer,
                    'show_alert'=>false,
                ]);
            }

            if ($messageId > 0) {
                $result = $this->telegram->api('editMessageText', [
                    'chat_id'=>$chatId,
                    'message_id'=>$messageId,
                    'text'=>$text,
                    'disable_web_page_preview'=>true,
                ]);
                if (!empty($result['ok'])) return true;
            }

            $this->telegram->api('sendMessage', [
                'chat_id'=>$chatId,
                'text'=>$text,
                'disable_web_page_preview'=>true,
            ]);
        } catch (AccountLinkException $error) {
            if ($callbackId !== '') {
                $this->telegram->api('answerCallbackQuery', [
                    'callback_query_id'=>$callbackId,
                    'text'=>$this->publicMessage($error),
                    'show_alert'=>true,
                ]);
            }
        } catch (Throwable $error) {
            error_log('[MiniGamesWorld account link callback] ' . $error::class . ': ' . $error->getMessage());
            if ($callbackId !== '') {
                $this->telegram->api('answerCallbackQuery', [
                    'callback_query_id'=>$callbackId,
                    'text'=>ServerLocalization::copy('server.account_link.telegram.confirm_failed', 'Could not confirm linking. Try again.'),
                    'show_alert'=>true,
                ]);
            }
        }

        return true;
    }

    private function service(): AccountLinkService
    {
        $databaseConfig = DatabaseConfig::fromApplicationConfig($this->config);
        $router = new RuntimeStorageRouter($this->config);
        if (!$databaseConfig->enabled()
            || !$router->enabled()
            || $router->routeFor('accounts') !== RuntimeStorageRouter::DRIVER_DATABASE
            || $router->routeFor('economy') !== RuntimeStorageRouter::DRIVER_DATABASE) {
            throw new AccountLinkException(
                'linking_unavailable',
                ServerLocalization::copy('server.account_chain.link.unavailable', 'Account linking is currently unavailable.'),
                503
            );
        }

        return new AccountLinkService(
            $this->config,
            PdoConnectionFactory::create($databaseConfig),
            new JsonStorageAdapter((string)$this->config['data_dir'])
        );
    }

    private function publicMessage(AccountLinkException $error): string
    {
        return match ($error->reason) {
            'challenge_expired' => ServerLocalization::copy('server.account_link.telegram.challenge_expired', 'The link has expired. Create a new attempt in Android.'),
            'telegram_account_missing' => ServerLocalization::copy('server.account_link.telegram.telegram_account_missing', 'Open MINI GAMES WORLD in Telegram first, then retry linking.'),
            'target_android_conflict' => ServerLocalization::copy('server.account_link.telegram.target_android_conflict', 'This profile is already linked to another Android device.'),
            'challenge_claimed' => ServerLocalization::copy('server.account_link.telegram.challenge_claimed', 'This attempt is already being used by another Telegram profile.'),
            default => $error->getMessage(),
        };
    }
}
