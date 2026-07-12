<?php
declare(strict_types=1);

final class AdminGoldTopupNotificationGuard
{
    public function __construct(private TelegramService $telegram, private array $config) {}

    /**
     * Handles only a real admin Gold top-up command.
     * The balance change, history transaction and in-app notification are saved
     * in one JsonDatabase transaction. Telegram remains an additional channel.
     */
    public function handle(array $update): bool
    {
        $message = $update['message'] ?? $update['edited_message'] ?? null;
        if (!is_array($message)) {
            return false;
        }

        $chatId = (string)($message['chat']['id'] ?? '');
        $fromId = (string)($message['from']['id'] ?? $chatId);
        $text = trim((string)($message['text'] ?? ''));
        $command = (string)($this->config['admin_gold_add_command'] ?? '/mgw_private_admin_7291_gold_add');

        if ($chatId === ''
            || $text === ''
            || !$this->startsWithCommand($text, $command)
            || !(new AdminService($this->config))->isAdmin($fromId)) {
            return false;
        }

        $argument = $this->commandArgument($text);
        $admin = new AdminService($this->config);
        $db = new JsonDatabase($this->dataDir());

        $result = $db->transaction(function (array &$data) use ($admin, $argument, $fromId): array {
            $beforeCount = count($data['transactions'] ?? []);
            $adminMessage = $admin->addGoldToUser($data, $argument, $fromId);
            $transaction = null;

            if (count($data['transactions'] ?? []) > $beforeCount) {
                $candidate = $data['transactions'][array_key_last($data['transactions'])] ?? null;
                if (is_array($candidate)
                    && (string)($candidate['category'] ?? '') === 'admin_gold_topup'
                    && (string)($candidate['admin_id'] ?? '') === $fromId) {
                    $transaction = $candidate;
                    (new NotificationService())->addAdminGoldTopup($data, $transaction);
                }
            }

            return [
                'message' => $adminMessage,
                'transaction' => $transaction,
            ];
        });

        $this->telegram->api('sendMessage', [
            'chat_id' => $chatId,
            'text' => (string)($result['message'] ?? ''),
            'reply_markup' => $admin->keyboard(),
            'disable_web_page_preview' => true,
        ]);

        if (is_array($result['transaction'] ?? null)) {
            $this->notifyUser($result['transaction']);
        }

        return true;
    }

    private function notifyUser(array $transaction): void
    {
        $userId = trim((string)($transaction['user_id'] ?? ''));
        if ($userId === '') {
            return;
        }

        $amount = max(0, (int)($transaction['amount'] ?? 0));
        $reason = trim((string)($transaction['reason'] ?? ''));

        $text = "🪙 Gold начислен\n\nНачислено: +{$amount} Gold";
        if ($reason !== '') {
            $text .= "\nПричина: {$reason}";
        }

        try {
            $this->telegram->api('sendMessage', [
                'chat_id' => $userId,
                'text' => $text,
                'disable_web_page_preview' => true,
            ]);
        } catch (Throwable $e) {
            error_log('Mini Games World admin Gold user notification failed for ' . $userId . ': ' . $e->getMessage());
        }
    }

    private function startsWithCommand(string $text, string $command): bool
    {
        if ($command === '') {
            return false;
        }

        $token = preg_split('/\s+/', trim($text), 2)[0] ?? '';
        $tokenWithoutBot = explode('@', (string)$token, 2)[0];

        return $token === $command || $tokenWithoutBot === $command;
    }

    private function commandArgument(string $text): string
    {
        $parts = preg_split('/\s+/', trim($text), 2);
        return trim((string)($parts[1] ?? ''));
    }

    private function dataDir(): string
    {
        return (string)($this->config['data_dir'] ?? (__DIR__ . '/../data'));
    }
}
