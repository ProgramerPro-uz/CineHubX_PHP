<?php

declare(strict_types=1);

namespace CineHubX\PhpBot;

final class TelegramApi
{
    private string $baseUrl;

    public function __construct(private readonly string $token)
    {
        $this->baseUrl = 'https://api.telegram.org/bot' . $this->token . '/';
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getUpdates(int $offset = 0, int $timeout = 25): array
    {
        $response = $this->call('getUpdates', [
            'offset' => $offset,
            'timeout' => $timeout,
            'allowed_updates' => ['message', 'callback_query'],
        ]);

        return isset($response['result']) && is_array($response['result']) ? $response['result'] : [];
    }

    /** @return array<string, mixed> */
    public function sendMessage(int $chatId, string $text, ?array $replyMarkup = null): array
    {
        $params = [
            'chat_id' => $chatId,
            'text' => $text,
            'parse_mode' => 'HTML',
        ];
        if ($replyMarkup !== null) {
            $params['reply_markup'] = $replyMarkup;
        }

        return $this->call('sendMessage', $params);
    }

    /** @return array<string, mixed> */
    public function sendPhoto(int $chatId, string $photo, string $caption, ?array $replyMarkup = null): array
    {
        $params = [
            'chat_id' => $chatId,
            'photo' => $photo,
            'caption' => $caption,
            'parse_mode' => 'HTML',
        ];

        if ($replyMarkup !== null) {
            $params['reply_markup'] = $replyMarkup;
        }

        return $this->call('sendPhoto', $params);
    }

    /** @return array<string, mixed> */
    public function copyMessage(int $chatId, int $fromChatId, int $messageId): array
    {
        return $this->call('copyMessage', [
            'chat_id' => $chatId,
            'from_chat_id' => $fromChatId,
            'message_id' => $messageId,
        ]);
    }

    /** @return array<string, mixed> */
    public function editMessageCaption(int $chatId, int $messageId, string $caption): array
    {
        return $this->call('editMessageCaption', [
            'chat_id' => $chatId,
            'message_id' => $messageId,
            'caption' => $caption,
            'parse_mode' => 'HTML',
        ]);
    }

    /** @return array<string, mixed> */
    public function editMessageText(int $chatId, int $messageId, string $text, ?array $replyMarkup = null): array
    {
        $params = [
            'chat_id' => $chatId,
            'message_id' => $messageId,
            'text' => $text,
            'parse_mode' => 'HTML',
        ];
        if ($replyMarkup !== null) {
            $params['reply_markup'] = $replyMarkup;
        }

        return $this->call('editMessageText', $params);
    }

    /** @return array<string, mixed> */
    public function editMessageReplyMarkup(int $chatId, int $messageId, array $replyMarkup): array
    {
        return $this->call('editMessageReplyMarkup', [
            'chat_id' => $chatId,
            'message_id' => $messageId,
            'reply_markup' => $replyMarkup,
        ]);
    }

    /** @return array<string, mixed> */
    public function deleteMessage(int $chatId, int $messageId): array
    {
        return $this->call('deleteMessage', [
            'chat_id' => $chatId,
            'message_id' => $messageId,
        ]);
    }

    /** @return array<string, mixed> */
    public function answerCallbackQuery(string $callbackQueryId, string $text = '', bool $showAlert = false): array
    {
        $params = ['callback_query_id' => $callbackQueryId];
        if ($text !== '') {
            $params['text'] = $text;
        }
        if ($showAlert) {
            $params['show_alert'] = true;
        }

        return $this->call('answerCallbackQuery', $params);
    }

    public function getChatMember(int $chatId, int $userId): ?string
    {
        $response = $this->call('getChatMember', [
            'chat_id' => $chatId,
            'user_id' => $userId,
        ], suppressErrors: true);

        if (!($response['ok'] ?? false)) {
            return null;
        }

        $status = $response['result']['status'] ?? null;
        return is_string($status) ? $status : null;
    }

    /** @return array<string, mixed> */
    public function call(string $method, array $params = [], bool $suppressErrors = false): array
    {
        $url = $this->baseUrl . $method;

        $payload = [];
        foreach ($params as $key => $value) {
            if ($key === 'reply_markup' || $key === 'allowed_updates') {
                $payload[$key] = $value;
                continue;
            }
            $payload[$key] = $value;
        }

        $ch = curl_init($url);
        if ($ch === false) {
            throw new \RuntimeException('Failed to initialize cURL');
        }

        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 60,
        ]);

        $raw = curl_exec($ch);
        $error = curl_error($ch);
        curl_close($ch);

        if ($raw === false) {
            if ($suppressErrors) {
                return ['ok' => false, 'description' => $error];
            }
            throw new \RuntimeException('Telegram API request failed: ' . $error);
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            if ($suppressErrors) {
                return ['ok' => false, 'description' => 'Invalid JSON response'];
            }
            throw new \RuntimeException('Invalid Telegram API response: ' . $raw);
        }

        if (($decoded['ok'] ?? false) !== true && !$suppressErrors) {
            $desc = is_string($decoded['description'] ?? null) ? $decoded['description'] : 'Unknown Telegram API error';
            throw new \RuntimeException('Telegram API error: ' . $desc);
        }

        return $decoded;
    }
}
