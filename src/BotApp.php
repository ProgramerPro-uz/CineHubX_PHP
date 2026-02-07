<?php

declare(strict_types=1);

namespace CineHubX\PhpBot;

final class BotApp
{
    private int $offset = 0;

    /** @var array<int, float> */
    private array $rateLimit = [];

    /** @var array<string, array{expires_at: float, ok: bool}> */
    private array $subscriptionCache = [];

    private const SUB_CACHE_OK_TTL_SECONDS = 20.0;
    private const SUB_CACHE_FAIL_TTL_SECONDS = 2.0;

    public function __construct(
        private readonly Config $config,
        private readonly Database $db,
        private readonly TelegramApi $api,
    ) {
    }

    public function run(): void
    {
        while (true) {
            try {
                $updates = $this->api->getUpdates($this->offset, 25);
                foreach ($updates as $update) {
                    if (!isset($update['update_id'])) {
                        continue;
                    }
                    $this->offset = max($this->offset, (int) $update['update_id'] + 1);
                    $this->handleUpdate($update);
                }
            } catch (\Throwable $e) {
                error_log('Polling error: ' . $e->getMessage());
                usleep(500_000);
            }
        }
    }

    private function handleUpdate(array $update): void
    {
        if (isset($update['message']) && is_array($update['message'])) {
            $this->handleMessage($update['message']);
            return;
        }

        if (isset($update['callback_query']) && is_array($update['callback_query'])) {
            $this->handleCallback($update['callback_query']);
        }
    }

    private function handleMessage(array $message): void
    {
        $from = $message['from'] ?? null;
        if (!is_array($from) || !isset($from['id'])) {
            return;
        }

        $userId = (int) $from['id'];
        if ($this->isRateLimited($userId)) {
            return;
        }

        $chatId = (int) ($message['chat']['id'] ?? 0);
        if ($chatId === 0) {
            return;
        }

        $username = isset($from['username']) && is_string($from['username']) ? $from['username'] : null;
        $text = isset($message['text']) && is_string($message['text']) ? trim($message['text']) : '';

        if (str_starts_with($text, '/start')) {
            $this->db->upsertUser($userId, $username);
            $this->handleStart($message, $text);
            return;
        }

        if (preg_match('/^\/admin(?:@\w+)?$/', $text) === 1) {
            $this->handleAdminCommand($chatId, $userId);
            return;
        }

        $state = $this->db->getState($userId);
        if ($state === null) {
            return;
        }

        $stateName = $state['state'];

        switch ($stateName) {
            case 'search_waiting_query':
                $this->handleSearchQueryMessage($message, $text);
                return;
            case 'admin_forced_add':
                $this->handleAdminForcedAddInput($message, $text);
                return;
            case 'admin_forced_remove':
                $this->handleAdminForcedRemoveInput($message, $text);
                return;
            case 'admin_admins_add':
                $this->handleAdminAdminsAddInput($message, $text);
                return;
            case 'admin_admins_remove':
                $this->handleAdminAdminsRemoveInput($message, $text);
                return;
            case 'broadcast_waiting_text':
                $this->handleBroadcastSend($message, $text);
                return;
            default:
                $this->db->clearState($userId);
        }
    }

    private function handleCallback(array $call): void
    {
        $from = $call['from'] ?? null;
        if (!is_array($from) || !isset($from['id'])) {
            return;
        }

        $userId = (int) $from['id'];
        if ($this->isRateLimited($userId)) {
            return;
        }

        $id = (string) ($call['id'] ?? '');
        $data = isset($call['data']) && is_string($call['data']) ? $call['data'] : '';
        $message = $call['message'] ?? null;
        $chatId = isset($message['chat']['id']) ? (int) $message['chat']['id'] : $userId;
        $messageId = isset($message['message_id']) ? (int) $message['message_id'] : 0;

        if ($data === '') {
            $this->safeAnswerCallback($id);
            return;
        }

        if (str_starts_with($data, 'sub:check')) {
            $payload = null;
            $parts = explode(':', $data, 3);
            if (count($parts) === 3) {
                $payload = $parts[2];
            }
            $this->handleSubCheck($id, $chatId, $messageId, $userId, $payload);
            return;
        }

        if ($data === 'menu:search') {
            if (!$this->ensureSubscribed($userId, $chatId, null, true, true)) {
                $this->safeAnswerCallback($id);
                return;
            }
            $this->db->setState($userId, 'search_waiting_query');
            $this->safeSendMessage($chatId, Texts::SEARCH_PROMPT);
            $this->safeAnswerCallback($id);
            return;
        }

        if ($data === 'menu:latest') {
            if (!$this->ensureSubscribed($userId, $chatId, null, true, true)) {
                $this->safeAnswerCallback($id);
                return;
            }
            $total = $this->db->countContent();
            $items = $this->db->listLatest(10, 0);
            $kb = Keyboards::contentList($items, 1, $total, 'latest');
            $this->safeSendMessage($chatId, "So'nggi yuklanganlar:", $kb);
            $this->safeAnswerCallback($id);
            return;
        }

        if ($data === 'menu:top') {
            if (!$this->ensureSubscribed($userId, $chatId, null, true, true)) {
                $this->safeAnswerCallback($id);
                return;
            }
            $total = $this->db->countContent();
            $items = $this->db->listTop(10, 0);
            $kb = Keyboards::contentList($items, 1, $total, 'top');
            $this->safeSendMessage($chatId, "Eng ko'p ko'rilganlar:", $kb);
            $this->safeAnswerCallback($id);
            return;
        }

        if ($data === 'menu:favs') {
            if (!$this->ensureSubscribed($userId, $chatId, null, true, true)) {
                $this->safeAnswerCallback($id);
                return;
            }
            $total = $this->db->countFavorites($userId);
            if ($total === 0) {
                $this->safeSendMessage($chatId, "Saqlanganlar bo'sh.");
                $this->safeAnswerCallback($id);
                return;
            }
            $items = $this->db->listFavorites($userId, 10, 0);
            $kb = Keyboards::contentList($items, 1, $total, 'favs');
            $this->safeSendMessage($chatId, 'Saqlanganlar:', $kb);
            $this->safeAnswerCallback($id);
            return;
        }

        if ($data === 'menu:account') {
            if (!$this->ensureSubscribed($userId, $chatId, null, true, true)) {
                $this->safeAnswerCallback($id);
                return;
            }
            $username = (string) ($from['username'] ?? '-');
            $text = strtr(Texts::ACCOUNT_TEMPLATE, [
                '{user_id}' => (string) $userId,
                '{username}' => $username !== '' ? $username : '-',
            ]);
            $this->safeSendMessage($chatId, $text);
            $this->safeAnswerCallback($id);
            return;
        }

        if ($data === 'menu:help') {
            if (!$this->ensureSubscribed($userId, $chatId, null, true, true)) {
                $this->safeAnswerCallback($id);
                return;
            }
            $this->safeSendMessage($chatId, Texts::HELP_TEXT);
            $this->safeAnswerCallback($id);
            return;
        }

        if (str_starts_with($data, 'content:')) {
            if (!$this->ensureSubscribed($userId, $chatId, null, true, true)) {
                $this->safeAnswerCallback($id);
                return;
            }
            $contentId = (int) substr($data, strlen('content:'));
            $ok = $this->sendContentCard($chatId, $contentId, $userId);
            if (!$ok) {
                $this->safeAnswerCallback($id, 'Topilmadi');
                return;
            }
            $this->safeAnswerCallback($id);
            return;
        }

        if (str_starts_with($data, 'fav:')) {
            $contentId = (int) substr($data, strlen('fav:'));
            $isFav = $this->db->toggleFavorite($userId, $contentId);
            $this->safeAnswerCallback($id, $isFav ? 'Saqlangan' : "O'chirildi");
            return;
        }

        if (str_starts_with($data, 'watch:')) {
            if (!$this->ensureSubscribed($userId, $chatId, null, true, true)) {
                $this->safeAnswerCallback($id);
                return;
            }
            $parts = explode(':', $data, 3);
            if (count($parts) < 2) {
                $this->safeAnswerCallback($id);
                return;
            }
            $contentId = (int) ($parts[1] ?? 0);
            $this->handleWatchContent($id, $chatId, $messageId, $userId, $contentId);
            return;
        }

        if (str_starts_with($data, 'parts:')) {
            $this->handlePartsPage($id, $chatId, $messageId, $data);
            return;
        }

        if (str_starts_with($data, 'season:')) {
            $this->handleSeasonPick($id, $chatId, $messageId, $data);
            return;
        }

        if (str_starts_with($data, 'part:')) {
            $this->handleSendPart($id, $userId, $data);
            return;
        }

        if (str_starts_with($data, 'page:latest:')) {
            $page = (int) substr($data, strlen('page:latest:'));
            $offset = max(0, ($page - 1) * 10);
            $items = $this->db->listLatest(10, $offset);
            $total = $this->db->countContent();
            $kb = Keyboards::contentList($items, $page, $total, 'latest');
            $this->safeEditMessageReplyMarkup($chatId, $messageId, $kb);
            $this->safeAnswerCallback($id);
            return;
        }

        if (str_starts_with($data, 'page:top:')) {
            $page = (int) substr($data, strlen('page:top:'));
            $offset = max(0, ($page - 1) * 10);
            $items = $this->db->listTop(10, $offset);
            $total = $this->db->countContent();
            $kb = Keyboards::contentList($items, $page, $total, 'top');
            $this->safeEditMessageReplyMarkup($chatId, $messageId, $kb);
            $this->safeAnswerCallback($id);
            return;
        }

        if (str_starts_with($data, 'page:favs:')) {
            $page = (int) substr($data, strlen('page:favs:'));
            $offset = max(0, ($page - 1) * 10);
            $items = $this->db->listFavorites($userId, 10, $offset);
            $total = $this->db->countFavorites($userId);
            $kb = Keyboards::contentList($items, $page, $total, 'favs');
            $this->safeEditMessageReplyMarkup($chatId, $messageId, $kb);
            $this->safeAnswerCallback($id);
            return;
        }

        if (str_starts_with($data, 'page:search:')) {
            $payload = substr($data, strlen('page:search:'));
            $pos = strrpos($payload, ':');
            if ($pos === false) {
                $this->safeAnswerCallback($id);
                return;
            }
            $encoded = substr($payload, 0, $pos);
            $page = (int) substr($payload, $pos + 1);
            $query = rawurldecode($encoded);

            $total = $this->db->countSearch($query);
            $offset = max(0, ($page - 1) * 10);
            $items = $this->db->searchContent($query, 10, $offset);
            $kb = Keyboards::contentList($items, $page, $total, 'search:' . rawurlencode($query));
            $this->safeEditMessageReplyMarkup($chatId, $messageId, $kb);
            $this->safeAnswerCallback($id);
            return;
        }

        if ($data === 'back:menu') {
            $this->handleBackMenu($id, $chatId, $messageId);
            return;
        }

        if ($data === 'back:admin') {
            if (!$this->isAdmin($userId)) {
                $this->safeAnswerCallback($id, Texts::ADMIN_ONLY, true);
                return;
            }
            $this->safeEditMessageText($chatId, $messageId, Texts::ADMIN_MENU, Keyboards::adminMenu());
            $this->safeAnswerCallback($id);
            return;
        }

        if ($data === 'admin:stats') {
            if (!$this->isAdmin($userId)) {
                $this->safeAnswerCallback($id, Texts::ADMIN_ONLY, true);
                return;
            }
            $stats = $this->db->stats();
            $text = "📊 Statistika\n"
                . 'Kontent: ' . $stats['content'] . "\n"
                . 'Qismlar: ' . $stats['parts'] . "\n"
                . 'Foydalanuvchilar: ' . $stats['users'] . "\n"
                . "Ko'rishlar: " . $stats['views'];
            $this->safeSendMessage($chatId, $text);
            $this->safeAnswerCallback($id);
            return;
        }

        if ($data === 'admin:forced') {
            if (!$this->isAdmin($userId)) {
                $this->safeAnswerCallback($id, Texts::ADMIN_ONLY, true);
                return;
            }
            $this->safeSendMessage($chatId, Texts::ADMIN_FORCED_MENU, Keyboards::adminForced());
            $this->safeAnswerCallback($id);
            return;
        }

        if ($data === 'admin:forced:list') {
            if (!$this->isAdmin($userId)) {
                $this->safeAnswerCallback($id, Texts::ADMIN_ONLY, true);
                return;
            }
            $channels = $this->db->getForcedChannels($this->config->forcedChannels);
            $links = $this->db->getForcedLinks($this->config->forcedChannelLinks);
            if ($channels === []) {
                $this->safeSendMessage($chatId, Texts::ADMIN_FORCED_LIST_EMPTY);
                $this->safeAnswerCallback($id);
                return;
            }
            $lines = ['Majburiy obuna kanallari:'];
            foreach ($channels as $channelId) {
                if (isset($links[$channelId])) {
                    $lines[] = sprintf('- %d | %s', $channelId, $links[$channelId]);
                } else {
                    $lines[] = sprintf('- %d', $channelId);
                }
            }
            $this->safeSendMessage($chatId, implode("\n", $lines));
            $this->safeAnswerCallback($id);
            return;
        }

        if ($data === 'admin:forced:add') {
            if (!$this->isAdmin($userId)) {
                $this->safeAnswerCallback($id, Texts::ADMIN_ONLY, true);
                return;
            }
            $this->db->setState($userId, 'admin_forced_add');
            $this->safeSendMessage($chatId, Texts::ADMIN_FORCED_ADD);
            $this->safeAnswerCallback($id);
            return;
        }

        if ($data === 'admin:forced:remove') {
            if (!$this->isAdmin($userId)) {
                $this->safeAnswerCallback($id, Texts::ADMIN_ONLY, true);
                return;
            }
            $this->db->setState($userId, 'admin_forced_remove');
            $this->safeSendMessage($chatId, Texts::ADMIN_FORCED_REMOVE);
            $this->safeAnswerCallback($id);
            return;
        }

        if ($data === 'admin:admins') {
            if (!$this->isAdmin($userId)) {
                $this->safeAnswerCallback($id, Texts::ADMIN_ONLY, true);
                return;
            }
            $this->safeSendMessage($chatId, Texts::ADMIN_ADMINS_MENU, Keyboards::adminAdmins());
            $this->safeAnswerCallback($id);
            return;
        }

        if ($data === 'admin:admins:list') {
            if (!$this->isAdmin($userId)) {
                $this->safeAnswerCallback($id, Texts::ADMIN_ONLY, true);
                return;
            }
            $admins = $this->db->getAdminIds($this->config->admins);
            if ($admins === []) {
                $this->safeSendMessage($chatId, Texts::ADMIN_ADMINS_LIST_EMPTY);
                $this->safeAnswerCallback($id);
                return;
            }
            $lines = ['Adminlar:'];
            foreach ($admins as $adminId) {
                $lines[] = '- ' . $adminId;
            }
            $this->safeSendMessage($chatId, implode("\n", $lines));
            $this->safeAnswerCallback($id);
            return;
        }

        if ($data === 'admin:admins:add') {
            if (!$this->isAdmin($userId)) {
                $this->safeAnswerCallback($id, Texts::ADMIN_ONLY, true);
                return;
            }
            $this->db->setState($userId, 'admin_admins_add');
            $this->safeSendMessage($chatId, Texts::ADMIN_ADMINS_ADD);
            $this->safeAnswerCallback($id);
            return;
        }

        if ($data === 'admin:admins:remove') {
            if (!$this->isAdmin($userId)) {
                $this->safeAnswerCallback($id, Texts::ADMIN_ONLY, true);
                return;
            }
            $this->db->setState($userId, 'admin_admins_remove');
            $this->safeSendMessage($chatId, Texts::ADMIN_ADMINS_REMOVE);
            $this->safeAnswerCallback($id);
            return;
        }

        if ($data === 'admin:broadcast') {
            if (!$this->isAdmin($userId)) {
                $this->safeAnswerCallback($id, Texts::ADMIN_ONLY, true);
                return;
            }
            $this->db->setState($userId, 'broadcast_waiting_text');
            $this->safeSendMessage($chatId, Texts::BROADCAST_PROMPT);
            $this->safeAnswerCallback($id);
            return;
        }

        if ($data === 'admin:settings') {
            if (!$this->isAdmin($userId)) {
                $this->safeAnswerCallback($id, Texts::ADMIN_ONLY, true);
                return;
            }
            $this->safeSendMessage($chatId, Texts::ADMIN_SETTINGS_MENU, Keyboards::adminSettings());
            $this->safeAnswerCallback($id);
            return;
        }

        if (str_starts_with($data, 'admin:settings:')) {
            if (!$this->isAdmin($userId)) {
                $this->safeAnswerCallback($id, Texts::ADMIN_ONLY, true);
                return;
            }
            $key = substr($data, strlen('admin:settings:'));
            $title = '';
            if ($key === 'anime') {
                $items = $this->db->listByType('anime');
                $total = $this->db->countByType('anime');
                $title = 'Anime';
            } elseif ($key === 'movie') {
                $items = $this->db->listByType('movie');
                $total = $this->db->countByType('movie');
                $title = 'Kino';
            } elseif ($key === 'drama') {
                $items = $this->db->listByType('series');
                $total = $this->db->countByType('series');
                $title = 'Drama';
            } else {
                $this->safeAnswerCallback($id);
                return;
            }

            if ($items === []) {
                $this->safeSendMessage($chatId, Texts::ADMIN_SETTINGS_EMPTY);
                $this->safeAnswerCallback($id);
                return;
            }

            $lines = [sprintf('%s (%d ta):', $title, $total)];
            foreach ($items as $item) {
                $partsCount = (int) ($item['parts_total'] ?? $item['parts_count'] ?? 0);
                $lines[] = sprintf('- %s - qismi %d (content raqami %d)', (string) $item['title'], $partsCount, (int) $item['id']);
            }
            $this->safeSendMessage($chatId, implode("\n", $lines));
            $this->safeAnswerCallback($id);
            return;
        }

        if ($data === 'admin:add_content' || $data === 'admin:add_part' || $data === 'admin:edit') {
            if (!$this->isAdmin($userId)) {
                $this->safeAnswerCallback($id, Texts::ADMIN_ONLY, true);
                return;
            }
            $this->safeSendMessage($chatId, 'PHP versiyada bu bo\'lim hali qo\'shilmoqda.');
            $this->safeAnswerCallback($id);
            return;
        }

        $this->safeAnswerCallback($id);
    }

    private function handleStart(array $message, string $text): void
    {
        $from = $message['from'];
        $chatId = (int) ($message['chat']['id'] ?? 0);
        if ($chatId === 0) {
            return;
        }

        $userId = (int) $from['id'];
        $payload = null;
        if (str_contains($text, ' ')) {
            $parts = explode(' ', $text, 2);
            $payload = trim($parts[1] ?? '');
            if ($payload === '') {
                $payload = null;
            }
        }

        if ($payload !== null && str_starts_with($payload, 'dlp_')) {
            if (!$this->ensureSubscribed($userId, $chatId, $payload, true, true)) {
                return;
            }
            $partId = (int) str_replace('dlp_', '', $payload);
            $part = $this->db->getPart($partId);
            if ($part !== null) {
                $copiedMessageId = $this->copyMessageFromChannels(
                    $chatId,
                    (int) ($part['channel_message_id'] ?? 0),
                );
                if ($copiedMessageId !== null) {
                    $contentId = (int) ($part['content_id'] ?? 0);
                    $this->db->incrementViews($contentId);
                    $this->applyPartCaption(
                        $chatId,
                        $copiedMessageId,
                        $contentId,
                        (int) ($part['part_number'] ?? 1),
                        (int) ($part['season'] ?? 1),
                    );
                } else {
                    $this->safeSendMessage($chatId, 'Kontent topilmadi.');
                }
            }
            return;
        }

        if ($payload !== null && str_starts_with($payload, 'content_')) {
            if (!$this->ensureSubscribed($userId, $chatId, $payload, true, true)) {
                return;
            }
            $contentId = (int) str_replace('content_', '', $payload);
            $ok = $this->sendContentCard($chatId, $contentId, $userId);
            if (!$ok) {
                $this->safeSendMessage($chatId, 'Topilmadi');
            }
            return;
        }

        if (!$this->ensureSubscribed($userId, $chatId, null, true, true)) {
            return;
        }

        $this->db->clearState($userId);
        $this->safeSendMessage($chatId, Texts::WELCOME, Keyboards::mainMenu());
    }

    private function handleSubCheck(
        string $callbackId,
        int $chatId,
        int $messageId,
        int $userId,
        ?string $payload,
    ): void {
        if (!$this->ensureSubscribed($userId, $chatId, $payload, false, false)) {
            $this->safeAnswerCallback($callbackId, "❗️ Avval obuna bo'ling", true);
            return;
        }

        $this->safeAnswerCallback($callbackId, '✅ Obuna tekshirildi');
        if ($messageId > 0) {
            $this->safeDeleteMessage($chatId, $messageId);
        }

        if ($payload !== null && str_starts_with($payload, 'dlp_')) {
            $partId = (int) str_replace('dlp_', '', $payload);
            $part = $this->db->getPart($partId);
            if ($part !== null) {
                $copiedMessageId = $this->copyMessageFromChannels(
                    $userId,
                    (int) ($part['channel_message_id'] ?? 0),
                );
                if ($copiedMessageId !== null) {
                    $contentId = (int) ($part['content_id'] ?? 0);
                    $this->db->incrementViews($contentId);
                    $this->applyPartCaption(
                        $userId,
                        $copiedMessageId,
                        $contentId,
                        (int) ($part['part_number'] ?? 1),
                        (int) ($part['season'] ?? 1),
                    );
                } else {
                    $this->safeSendMessage($chatId, 'Kontent topilmadi.');
                }
            }
            return;
        }

        $this->safeSendMessage($chatId, Texts::WELCOME, Keyboards::mainMenu());
    }

    private function handleSearchQueryMessage(array $message, string $text): void
    {
        $from = $message['from'] ?? [];
        $userId = (int) ($from['id'] ?? 0);
        $chatId = (int) ($message['chat']['id'] ?? 0);
        if ($userId === 0 || $chatId === 0) {
            return;
        }

        if (!$this->ensureSubscribed($userId, $chatId, null, true, true)) {
            return;
        }

        $query = trim($text);
        if ($query === '') {
            $this->safeSendMessage($chatId, Texts::SEARCH_PROMPT);
            return;
        }

        $this->showSearchResults($chatId, $query, 1);
        $this->db->clearState($userId);
    }

    private function showSearchResults(int $chatId, string $query, int $page): void
    {
        $total = $this->db->countSearch($query);
        if ($total === 0) {
            $this->safeSendMessage($chatId, Texts::NO_RESULTS);
            return;
        }

        $offset = max(0, ($page - 1) * 10);
        $items = $this->db->searchContent($query, 10, $offset);
        $encoded = rawurlencode($query);
        $kb = Keyboards::contentList($items, $page, $total, 'search:' . $encoded);
        $this->safeSendMessage($chatId, 'Natijalar:', $kb);
    }

    private function handleWatchContent(string $callbackId, int $chatId, int $messageId, int $userId, int $contentId): void
    {
        $seasons = $this->db->listSeasons($contentId);
        if ($seasons === []) {
            $this->safeAnswerCallback($callbackId, 'Qism topilmadi');
            return;
        }

        if (count($seasons) === 1) {
            $season = (int) $seasons[0];
            $parts = $this->db->getParts($contentId, $season);
            $part = $parts[0] ?? null;
            if ($part === null) {
                $this->safeAnswerCallback($callbackId, 'Qism topilmadi');
                return;
            }

            if (count($parts) <= 1) {
                $copiedMessageId = $this->copyMessageFromChannels($userId, (int) ($part['channel_message_id'] ?? 0));
                if ($copiedMessageId !== null) {
                    $this->db->incrementViews($contentId);
                    $this->applyPartCaption(
                        $userId,
                        $copiedMessageId,
                        $contentId,
                        (int) ($part['part_number'] ?? 1),
                        $season,
                    );
                    $this->safeAnswerCallback($callbackId);
                } else {
                    $this->safeAnswerCallback($callbackId, 'Kontent topilmadi');
                }
                return;
            }

            $kb = Keyboards::parts($contentId, $parts, 1, $season);
            $this->safeSendMessage($chatId, 'Qismni tanlang:', $kb);
            $this->safeAnswerCallback($callbackId);
            return;
        }

        $kb = Keyboards::seasons($contentId, $seasons);
        $this->safeSendMessage($chatId, 'Faslni tanlang:', $kb);
        $this->safeAnswerCallback($callbackId);
    }

    private function handlePartsPage(string $callbackId, int $chatId, int $messageId, string $data): void
    {
        $parts = explode(':', $data);
        if (count($parts) === 3) {
            [, $contentIdStr, $pageStr] = $parts;
            $season = 1;
        } elseif (count($parts) === 4) {
            [, $contentIdStr, $seasonStr, $pageStr] = $parts;
            $season = (int) $seasonStr;
        } else {
            $this->safeAnswerCallback($callbackId);
            return;
        }

        $contentId = (int) $contentIdStr;
        $page = (int) $pageStr;

        $items = $this->db->getParts($contentId, $season);
        $kb = Keyboards::parts($contentId, $items, $page, $season);
        $this->safeEditMessageReplyMarkup($chatId, $messageId, $kb);
        $this->safeAnswerCallback($callbackId);
    }

    private function handleSeasonPick(string $callbackId, int $chatId, int $messageId, string $data): void
    {
        $parts = explode(':', $data, 3);
        if (count($parts) !== 3) {
            $this->safeAnswerCallback($callbackId);
            return;
        }

        [, $contentIdStr, $seasonStr] = $parts;
        $contentId = (int) $contentIdStr;
        $season = (int) $seasonStr;

        $rows = $this->db->getParts($contentId, $season);
        if ($rows === []) {
            $this->safeAnswerCallback($callbackId, 'Qism topilmadi');
            return;
        }

        $kb = Keyboards::parts($contentId, $rows, 1, $season);
        try {
            $this->api->editMessageText($chatId, $messageId, 'Qismni tanlang:', $kb);
        } catch (\Throwable) {
            $this->safeSendMessage($chatId, 'Qismni tanlang:', $kb);
        }

        $this->safeAnswerCallback($callbackId);
    }

    private function handleSendPart(string $callbackId, int $userId, string $data): void
    {
        $parts = explode(':', $data);
        if (count($parts) === 3) {
            [, $contentIdStr, $partStr] = $parts;
            $season = 1;
        } elseif (count($parts) === 4) {
            [, $contentIdStr, $seasonStr, $partStr] = $parts;
            $season = (int) $seasonStr;
        } else {
            $this->safeAnswerCallback($callbackId);
            return;
        }

        $contentId = (int) $contentIdStr;
        $partNumber = (int) $partStr;

        $part = $this->db->getPartByNumber($contentId, $partNumber, $season);
        if ($part === null) {
            $this->safeAnswerCallback($callbackId, 'Qism topilmadi');
            return;
        }

        $copiedMessageId = $this->copyMessageFromChannels($userId, (int) ($part['channel_message_id'] ?? 0));
        if ($copiedMessageId !== null) {
            $this->db->incrementViews($contentId);
            $this->applyPartCaption($userId, $copiedMessageId, $contentId, $partNumber, $season);
            $this->safeAnswerCallback($callbackId);
            return;
        }

        $this->safeAnswerCallback($callbackId, 'Kontent topilmadi');
    }

    private function handleBackMenu(string $callbackId, int $chatId, int $messageId): void
    {
        try {
            $this->api->editMessageText($chatId, $messageId, Texts::WELCOME, Keyboards::mainMenu());
        } catch (\Throwable) {
            try {
                $this->api->editMessageCaption($chatId, $messageId, Texts::WELCOME);
            } catch (\Throwable) {
                $this->safeSendMessage($chatId, Texts::WELCOME, Keyboards::mainMenu());
            }
        }

        $this->safeAnswerCallback($callbackId);
    }

    private function handleAdminCommand(int $chatId, int $userId): void
    {
        if (!$this->isAdmin($userId)) {
            $this->safeSendMessage($chatId, Texts::ADMIN_ONLY);
            return;
        }

        $this->safeSendMessage($chatId, Texts::ADMIN_MENU, Keyboards::adminMenu());
    }

    private function handleAdminForcedAddInput(array $message, string $text): void
    {
        $userId = (int) ($message['from']['id'] ?? 0);
        $chatId = (int) ($message['chat']['id'] ?? 0);
        if ($userId === 0 || $chatId === 0) {
            return;
        }

        if (!$this->isAdmin($userId)) {
            $this->safeSendMessage($chatId, Texts::ADMIN_ONLY);
            return;
        }

        $raw = trim($text);
        $idPart = $raw;
        $link = '';

        if (str_contains($raw, '|')) {
            [$idPart, $link] = explode('|', $raw, 2);
            $idPart = trim($idPart);
            $link = trim($link);
        }

        if (!is_numeric($idPart)) {
            $this->safeSendMessage($chatId, "ID raqam bo'lishi kerak.");
            return;
        }

        $channelId = (int) $idPart;
        $channels = $this->db->getForcedChannels($this->config->forcedChannels);
        $links = $this->db->getForcedLinks($this->config->forcedChannelLinks);

        if (!in_array($channelId, $channels, true)) {
            $channels[] = $channelId;
            $this->db->setForcedChannels($channels);
        }

        if ($link !== '') {
            $links[$channelId] = $link;
            $this->db->setForcedLinks($links);
        }

        $this->db->clearState($userId);
        $this->safeSendMessage($chatId, Texts::SAVED, Keyboards::adminMenu());
    }

    private function handleAdminForcedRemoveInput(array $message, string $text): void
    {
        $userId = (int) ($message['from']['id'] ?? 0);
        $chatId = (int) ($message['chat']['id'] ?? 0);
        if ($userId === 0 || $chatId === 0) {
            return;
        }

        if (!$this->isAdmin($userId)) {
            $this->safeSendMessage($chatId, Texts::ADMIN_ONLY);
            return;
        }

        if (!is_numeric(trim($text))) {
            $this->safeSendMessage($chatId, "ID raqam bo'lishi kerak.");
            return;
        }

        $channelId = (int) trim($text);
        $channels = $this->db->getForcedChannels($this->config->forcedChannels);
        $links = $this->db->getForcedLinks($this->config->forcedChannelLinks);

        if (in_array($channelId, $channels, true)) {
            $channels = array_values(array_filter($channels, static fn (int $id): bool => $id !== $channelId));
            unset($links[$channelId]);
            $this->db->setForcedChannels($channels);
            $this->db->setForcedLinks($links);
        }

        $this->db->clearState($userId);
        $this->safeSendMessage($chatId, Texts::SAVED, Keyboards::adminMenu());
    }

    private function handleAdminAdminsAddInput(array $message, string $text): void
    {
        $userId = (int) ($message['from']['id'] ?? 0);
        $chatId = (int) ($message['chat']['id'] ?? 0);
        if ($userId === 0 || $chatId === 0) {
            return;
        }

        if (!$this->isAdmin($userId)) {
            $this->safeSendMessage($chatId, Texts::ADMIN_ONLY);
            return;
        }

        if (!is_numeric(trim($text))) {
            $this->safeSendMessage($chatId, "ID raqam bo'lishi kerak.");
            return;
        }

        $adminId = (int) trim($text);
        $admins = $this->db->getAdminIds($this->config->admins);
        if (!in_array($adminId, $admins, true)) {
            $admins[] = $adminId;
            $this->db->setAdminIds($admins);
        }

        $this->db->clearState($userId);
        $this->safeSendMessage($chatId, Texts::SAVED, Keyboards::adminMenu());
    }

    private function handleAdminAdminsRemoveInput(array $message, string $text): void
    {
        $userId = (int) ($message['from']['id'] ?? 0);
        $chatId = (int) ($message['chat']['id'] ?? 0);
        if ($userId === 0 || $chatId === 0) {
            return;
        }

        if (!$this->isAdmin($userId)) {
            $this->safeSendMessage($chatId, Texts::ADMIN_ONLY);
            return;
        }

        if (!is_numeric(trim($text))) {
            $this->safeSendMessage($chatId, "ID raqam bo'lishi kerak.");
            return;
        }

        $adminId = (int) trim($text);
        if ($adminId === $userId) {
            $this->safeSendMessage($chatId, "O'zingizni o'chira olmaysiz.");
            return;
        }

        $admins = $this->db->getAdminIds($this->config->admins);
        if (in_array($adminId, $admins, true)) {
            $admins = array_values(array_filter($admins, static fn (int $id): bool => $id !== $adminId));
            $this->db->setAdminIds($admins);
        }

        $this->db->clearState($userId);
        $this->safeSendMessage($chatId, Texts::SAVED, Keyboards::adminMenu());
    }

    private function handleBroadcastSend(array $message, string $text): void
    {
        $userId = (int) ($message['from']['id'] ?? 0);
        $chatId = (int) ($message['chat']['id'] ?? 0);
        if ($userId === 0 || $chatId === 0) {
            return;
        }

        if (!$this->isAdmin($userId)) {
            $this->safeSendMessage($chatId, Texts::ADMIN_ONLY);
            return;
        }

        $sent = 0;
        foreach ($this->db->listUserIds() as $targetUserId) {
            try {
                $this->api->sendMessage($targetUserId, $text);
                $sent++;
            } catch (\Throwable) {
            }
        }

        $this->db->clearState($userId);
        $doneText = strtr(Texts::BROADCAST_DONE, ['{sent}' => (string) $sent]);
        $this->safeSendMessage($chatId, $doneText, Keyboards::adminMenu());
    }

    private function sendContentCard(int $chatId, int $contentId, int $userId): bool
    {
        $content = $this->db->getContent($contentId);
        if ($content === null) {
            return false;
        }

        $isFav = $this->db->isFavorite($userId, $contentId);
        $text = Utils::formatCard($content);
        $kb = Keyboards::contentCard($contentId, $isFav);

        $poster = $content['poster_file_id'] ?? null;
        if (is_string($poster) && trim($poster) !== '') {
            $this->safeSendPhoto($chatId, $poster, $text, $kb);
        } else {
            $this->safeSendMessage($chatId, $text, $kb);
        }

        return true;
    }

    private function applyPartCaption(int $chatId, int $messageId, int $contentId, int $partNumber, int $season): void
    {
        $content = $this->db->getContent($contentId);
        if ($content === null) {
            return;
        }

        $title = trim((string) ($content['title'] ?? ''));
        if ($title === '') {
            return;
        }

        $type = (string) ($content['type'] ?? '');
        if ($type === 'series' || $type === 'anime') {
            $caption = sprintf('%s [%d-fasl %d-qism]', $title, $season, $partNumber);
        } else {
            $caption = sprintf('%s [%d-qism]', $title, $partNumber);
        }

        try {
            $this->api->editMessageCaption($chatId, $messageId, $caption);
        } catch (\Throwable) {
        }
    }

    private function copyMessageFromChannels(int $chatId, int $messageId): ?int
    {
        foreach ($this->config->contentChannelIds as $channelId) {
            try {
                $response = $this->api->copyMessage($chatId, $channelId, $messageId);
                $newMessageId = $response['result']['message_id'] ?? null;
                if ($newMessageId !== null) {
                    return (int) $newMessageId;
                }
            } catch (\Throwable) {
            }
        }

        return null;
    }

    private function ensureSubscribed(
        int $userId,
        int $chatId,
        ?string $payload = null,
        bool $showPrompt = true,
        bool $useCache = true,
    ): bool {
        $forcedChannels = $this->db->getForcedChannels($this->config->forcedChannels);
        if ($forcedChannels === []) {
            return true;
        }

        sort($forcedChannels);
        $cacheKey = $this->subscriptionCacheKey($userId, $forcedChannels);

        $ok = null;
        if ($useCache) {
            $ok = $this->getCachedSubscriptionStatus($cacheKey);
        }

        if ($ok === null) {
            $ok = $this->checkForcedSub($userId, $forcedChannels);
            $this->setCachedSubscriptionStatus($cacheKey, $ok);
        }

        if ($ok) {
            return true;
        }

        if (!$showPrompt) {
            return false;
        }

        $forcedLinks = $this->db->getForcedLinks($this->config->forcedChannelLinks);
        $kb = Keyboards::subscribe($forcedChannels, $forcedLinks, $payload);
        $this->safeSendMessage($chatId, Texts::SUB_REQUIRED, $kb);

        return false;
    }

    /** @param list<int> $channels */
    private function checkForcedSub(int $userId, array $channels): bool
    {
        foreach ($channels as $channelId) {
            $status = $this->api->getChatMember($channelId, $userId);
            if (!in_array($status, ['member', 'administrator', 'creator'], true)) {
                return false;
            }
        }
        return true;
    }

    /** @param list<int> $channels */
    private function subscriptionCacheKey(int $userId, array $channels): string
    {
        return $userId . '|' . implode(',', $channels);
    }

    private function getCachedSubscriptionStatus(string $cacheKey): ?bool
    {
        if (!isset($this->subscriptionCache[$cacheKey])) {
            return null;
        }

        $cached = $this->subscriptionCache[$cacheKey];
        if ($cached['expires_at'] <= microtime(true)) {
            unset($this->subscriptionCache[$cacheKey]);
            return null;
        }

        return $cached['ok'];
    }

    private function setCachedSubscriptionStatus(string $cacheKey, bool $ok): void
    {
        $ttl = $ok ? self::SUB_CACHE_OK_TTL_SECONDS : self::SUB_CACHE_FAIL_TTL_SECONDS;
        $this->subscriptionCache[$cacheKey] = [
            'expires_at' => microtime(true) + $ttl,
            'ok' => $ok,
        ];

        if (count($this->subscriptionCache) > 10_000) {
            $now = microtime(true);
            foreach ($this->subscriptionCache as $key => $value) {
                if ($value['expires_at'] <= $now) {
                    unset($this->subscriptionCache[$key]);
                }
            }
            if (count($this->subscriptionCache) > 10_000) {
                $this->subscriptionCache = [];
            }
        }
    }

    private function isAdmin(int $userId): bool
    {
        $admins = $this->db->getAdminIds($this->config->admins);
        return in_array($userId, $admins, true);
    }

    private function isRateLimited(int $userId): bool
    {
        $now = microtime(true);
        $last = $this->rateLimit[$userId] ?? 0.0;

        if (($now - $last) < 0.5) {
            return true;
        }

        $this->rateLimit[$userId] = $now;
        return false;
    }

    private function safeSendMessage(int $chatId, string $text, ?array $replyMarkup = null): void
    {
        try {
            $this->api->sendMessage($chatId, $text, $replyMarkup);
        } catch (\Throwable $e) {
            error_log('sendMessage error: ' . $e->getMessage());
        }
    }

    private function safeSendPhoto(int $chatId, string $photo, string $caption, ?array $replyMarkup = null): void
    {
        try {
            $this->api->sendPhoto($chatId, $photo, $caption, $replyMarkup);
        } catch (\Throwable $e) {
            error_log('sendPhoto error: ' . $e->getMessage());
        }
    }

    private function safeEditMessageReplyMarkup(int $chatId, int $messageId, array $replyMarkup): void
    {
        if ($messageId <= 0) {
            return;
        }

        try {
            $this->api->editMessageReplyMarkup($chatId, $messageId, $replyMarkup);
        } catch (\Throwable) {
        }
    }

    private function safeEditMessageText(int $chatId, int $messageId, string $text, ?array $replyMarkup = null): void
    {
        if ($messageId <= 0) {
            return;
        }

        try {
            $this->api->editMessageText($chatId, $messageId, $text, $replyMarkup);
        } catch (\Throwable) {
        }
    }

    private function safeAnswerCallback(string $callbackId, string $text = '', bool $showAlert = false): void
    {
        if ($callbackId === '') {
            return;
        }

        try {
            $this->api->answerCallbackQuery($callbackId, $text, $showAlert);
        } catch (\Throwable) {
        }
    }

    private function safeDeleteMessage(int $chatId, int $messageId): void
    {
        if ($messageId <= 0) {
            return;
        }

        try {
            $this->api->deleteMessage($chatId, $messageId);
        } catch (\Throwable) {
        }
    }
}
