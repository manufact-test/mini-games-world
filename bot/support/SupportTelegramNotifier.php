<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/helpers/WebAppLaunchUrl.php';

final class SupportTelegramNotifier
{
    public function __construct(private array $config, private TelegramService $telegram) {}

    public function notifyCreated(array $ticket, array $metrics): bool
    {
        $adminIds = $this->config['admin_ids'] ?? [];
        if (!is_array($adminIds) || $adminIds === []) return false;

        $priority = (string)($ticket['priority'] ?? 'normal');
        $isCritical = $priority === 'critical';
        $message = $isCritical
            ? $this->criticalText($ticket)
            : $this->summaryText($ticket, $metrics);
        $keyboard = $this->adminKeyboard((string)($ticket['ticket_number'] ?? ''));

        $sent = false;
        foreach ($adminIds as $adminId) {
            $chatId = trim((string)$adminId);
            if ($chatId === '') continue;
            try {
                $result = $this->telegram->api('sendMessage', [
                    'chat_id' => $chatId,
                    'text' => $message,
                    'reply_markup' => $keyboard,
                    'disable_web_page_preview' => true,
                ]);
                if (!empty($result['ok'])) $sent = true;
            } catch (Throwable $error) {
                error_log('[MiniGamesWorld support telegram] ' . $error->getMessage());
            }
        }
        return $sent;
    }

    public function notifyUserReply(array $ticket): bool
    {
        $adminIds = $this->config['admin_ids'] ?? [];
        if (!is_array($adminIds) || $adminIds === []) return false;

        $message = $this->userReplyText($ticket);
        $keyboard = $this->adminKeyboard((string)($ticket['ticket_number'] ?? ''));
        $sent = false;

        foreach ($adminIds as $adminId) {
            $chatId = trim((string)$adminId);
            if ($chatId === '') continue;
            try {
                $result = $this->telegram->api('sendMessage', [
                    'chat_id' => $chatId,
                    'text' => $message,
                    'reply_markup' => $keyboard,
                    'disable_web_page_preview' => true,
                ]);
                if (!empty($result['ok'])) $sent = true;
            } catch (Throwable $error) {
                error_log('[MiniGamesWorld support telegram user reply] ' . $error->getMessage());
            }
        }

        return $sent;
    }

    private function criticalText(array $ticket): string
    {
        $first = $ticket['messages'][0]['body'] ?? '';
        $body = trim((string)$first);
        if (function_exists('mb_strlen') && mb_strlen($body) > 700) {
            $body = mb_substr($body, 0, 700) . '…';
        } elseif (strlen($body) > 700) {
            $body = substr($body, 0, 700) . '…';
        }

        return "🚨 Критическое обращение поддержки\n\n"
            . "Тикет: " . (string)($ticket['ticket_number'] ?? '—') . "\n"
            . "Категория: " . (string)($ticket['category_label'] ?? '—') . "\n"
            . "Платформа: " . (string)($ticket['platform_label'] ?? '—') . "\n"
            . "Игрок: " . (string)($ticket['requester_mgw_id'] ?? '—') . "\n"
            . "Тема: " . (string)($ticket['subject'] ?? '—') . "\n\n"
            . ($body !== '' ? $body : 'Без текста.');
    }

    private function summaryText(array $ticket, array $metrics): string
    {
        return "📬 Сводка поддержки\n\n"
            . "Новое обращение: " . (string)($ticket['ticket_number'] ?? '—') . "\n"
            . "Категория: " . (string)($ticket['category_label'] ?? '—') . "\n"
            . "Платформа: " . (string)($ticket['platform_label'] ?? '—') . "\n"
            . "Приоритет: " . (string)($ticket['priority_label'] ?? '—') . "\n\n"
            . "Открытых сейчас: " . (int)($metrics['open_total'] ?? 0) . "\n"
            . "Без владельца: " . (int)($metrics['unowned_open'] ?? 0) . "\n"
            . "Критических: " . (int)($metrics['critical_open'] ?? 0);
    }

    private function userReplyText(array $ticket): string
    {
        $body = '';
        $messages = is_array($ticket['messages'] ?? null) ? $ticket['messages'] : [];
        for ($index = count($messages) - 1; $index >= 0; $index--) {
            $row = $messages[$index] ?? null;
            if (!is_array($row) || (string)($row['actor_type'] ?? '') !== 'user') continue;
            $body = trim((string)($row['body'] ?? ''));
            break;
        }
        if (function_exists('mb_strlen') && mb_strlen($body) > 500) {
            $body = mb_substr($body, 0, 500) . '…';
        } elseif (strlen($body) > 500) {
            $body = substr($body, 0, 500) . '…';
        }

        return "📨 Новое сообщение в поддержке\n\n"
            . "Тикет: " . (string)($ticket['ticket_number'] ?? '—') . "\n"
            . "Игрок: " . (string)($ticket['requester_mgw_id'] ?? '—') . "\n"
            . "Категория: " . (string)($ticket['category_label'] ?? '—') . "\n"
            . "Приоритет: " . (string)($ticket['priority_label'] ?? '—') . "\n\n"
            . ($body !== '' ? $body : 'Сообщение без текста.');
    }

    private function adminKeyboard(string $ticketNumber): array
    {
        $url = WebAppLaunchUrl::admin($this->config);
        if ($url === '') return ['inline_keyboard' => []];
        $url .= (str_contains($url, '?') ? '&' : '?') . 'ticket=' . rawurlencode($ticketNumber);
        return [
            'inline_keyboard' => [[
                ['text' => '🌐 Открыть в Web Admin', 'web_app' => ['url' => $url]],
            ]],
        ];
    }
}
