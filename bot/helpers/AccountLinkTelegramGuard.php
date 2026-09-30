<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/accounts/AccountLinkService.php';

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
            $nickname = trim((string)($claimed['target_nickname'] ?? 'Игрок'));
            if ($nickname === '') $nickname = 'Игрок';

            $this->telegram->api('sendMessage', [
                'chat_id'=>$chatId,
                'text'=>"🔐 Привязка Android\n\n"
                    . "Вы подтверждаете вход Android-приложения в ваш MGW-профиль «{$nickname}»?\n\n"
                    . "После подтверждения Android будет использовать этот же профиль, баланс, покупки, статистику и рейтинг. "
                    . "Временные 1000 стартовых коинов Android-профиля не переносятся.",
                'reply_markup'=>[
                    'inline_keyboard'=>[
                        [[
                            'text'=>'✅ Подтвердить привязку',
                            'callback_data'=>'account_link:confirm:' . $challengeId,
                        ]],
                        [[
                            'text'=>'Отмена',
                            'callback_data'=>'account_link:cancel:' . $challengeId,
                        ]],
                    ],
                ],
                'disable_web_page_preview'=>true,
            ]);
        } catch (AccountLinkException $error) {
            $this->telegram->api('sendMessage', [
                'chat_id'=>$chatId,
                'text'=>'Не удалось начать привязку: ' . $this->publicMessage($error),
            ]);
        } catch (Throwable $error) {
            error_log('[MiniGamesWorld account link Telegram] ' . $error::class . ': ' . $error->getMessage());
            $this->telegram->api('sendMessage', [
                'chat_id'=>$chatId,
                'text'=>'Не удалось начать привязку. Вернитесь в Android и создайте новую попытку.',
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
                $text = "✅ Привязка подтверждена.\n\nВернитесь в Android-приложение и завершите привязку.";
                $answer = 'Подтверждено';
            } else {
                $this->service()->cancelTelegramChallenge($challengeId, $fromId);
                $text = "Привязка отменена.\n\nЕсли передумаете, начните новую попытку из Android-приложения.";
                $answer = 'Отменено';
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
                    'text'=>'Не удалось подтвердить привязку. Попробуйте ещё раз.',
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
                'Привязка аккаунта сейчас недоступна.',
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
            'challenge_expired' => 'Ссылка устарела. Создайте новую попытку в Android.',
            'telegram_account_missing' => 'Сначала откройте MINI GAMES WORLD в Telegram, затем повторите привязку.',
            'target_android_conflict' => 'Этот профиль уже привязан к другому Android-устройству.',
            'challenge_claimed' => 'Эта попытка уже используется другим Telegram-профилем.',
            default => $error->getMessage(),
        };
    }
}
