<?php
declare(strict_types=1);

final class RuntimeAdminGuard
{
    public function __construct(
        private TelegramService $telegram,
        private array $config
    ) {}

    public function handle(array $update): bool
    {
        $adminId = $this->adminId($update);
        if ($adminId === '' || !$this->isAdmin($adminId)) return false;

        $flags = new FeatureFlagService($this->config);
        $message = $update['message'] ?? $update['edited_message'] ?? null;
        $callback = $update['callback_query'] ?? null;
        $text = trim((string)($message['text'] ?? ''));
        $callbackData = trim((string)($callback['data'] ?? ''));

        if ($this->isSystemCheck($text, $callbackData)) {
            $this->sendAlerts($flags, $message, $callback);
            return false;
        }

        if (!$flags->financialReadOnly()) return false;
        if (!$this->isFinancialMutation($text, $callbackData)) return false;

        $notice = 'Финансовые изменения временно отключены: включён режим только для чтения.';
        $chatId = $message['chat']['id'] ?? $callback['message']['chat']['id'] ?? null;
        $callbackId = (string)($callback['id'] ?? '');

        if ($callbackId !== '') {
            $this->telegram->api('answerCallbackQuery', [
                'callback_query_id' => $callbackId,
                'text' => 'Режим только для чтения',
                'show_alert' => true,
            ]);
        }
        if ($chatId !== null) {
            $this->telegram->api('sendMessage', [
                'chat_id' => $chatId,
                'text' => "⚠️ {$notice}",
            ]);
        }

        return true;
    }

    private function sendAlerts(FeatureFlagService $flags, mixed $message, mixed $callback): void
    {
        $alerts = $flags->adminAlerts();
        if (!$alerts) return;

        $chatId = is_array($message)
            ? ($message['chat']['id'] ?? null)
            : (is_array($callback) ? ($callback['message']['chat']['id'] ?? null) : null);
        if ($chatId === null) return;

        $lines = ["⚠️ Активные runtime-ограничения"];
        foreach ($alerts as $alert) $lines[] = '• ' . $alert;
        $this->telegram->api('sendMessage', [
            'chat_id' => $chatId,
            'text' => implode("\n", $lines),
        ]);
    }

    private function isSystemCheck(string $text, string $callbackData): bool
    {
        $command = (string)($this->config['admin_check_command'] ?? '/mgw_private_admin_7291_check');
        return $callbackData === 'admin:system_check'
            || ($command !== '' && ($text === $command || str_starts_with($text, $command . ' ')));
    }

    private function isFinancialMutation(string $text, string $callbackData): bool
    {
        $commands = [
            (string)($this->config['admin_order_done_command'] ?? '/mgw_private_admin_7291_order_done'),
            (string)($this->config['admin_order_reject_command'] ?? '/mgw_private_admin_7291_order_reject'),
            (string)($this->config['admin_payment_apply_command'] ?? '/mgw_private_admin_7291_payment_apply'),
            (string)($this->config['admin_payment_reject_command'] ?? '/mgw_private_admin_7291_payment_reject'),
            (string)($this->config['admin_gold_add_command'] ?? '/mgw_private_admin_7291_gold_add'),
        ];
        foreach (array_filter($commands) as $command) {
            if ($text === $command || str_starts_with($text, $command . ' ')) return true;
        }

        foreach ([
            'admin:payment_apply:',
            'admin:payment_reject_prompt:',
            'admin:order_done:',
            'admin:order_reject:',
            'admin:gold_add:',
        ] as $prefix) {
            if (str_starts_with($callbackData, $prefix)) return true;
        }

        return false;
    }

    private function adminId(array $update): string
    {
        return (string)(
            $update['callback_query']['from']['id']
            ?? $update['message']['from']['id']
            ?? $update['edited_message']['from']['id']
            ?? ''
        );
    }

    private function isAdmin(string $id): bool
    {
        foreach (($this->config['admin_ids'] ?? []) as $adminId) {
            if ((string)$adminId === $id) return true;
        }
        return false;
    }
}
