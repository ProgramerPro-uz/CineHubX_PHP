<?php

declare(strict_types=1);

namespace CineHubX\PhpBot;

final class Keyboards
{
    /** @return array<string, mixed> */
    public static function mainMenu(): array
    {
        return [
            'inline_keyboard' => [
                [
                    ['text' => '🔎 Qidiruv', 'callback_data' => 'menu:search'],
                    ['text' => '🆕 Yangi', 'callback_data' => 'menu:latest'],
                ],
                [
                    ['text' => '🔥 Top', 'callback_data' => 'menu:top'],
                    ['text' => '⭐ Saqlangan', 'callback_data' => 'menu:favs'],
                ],
                [
                    ['text' => '👤 Profil', 'callback_data' => 'menu:account'],
                    ['text' => 'ℹ️ Yordam', 'callback_data' => 'menu:help'],
                ],
            ],
        ];
    }

    /**
     * @param list<int> $channels
     * @param array<int, string> $links
     * @return array<string, mixed>
     */
    public static function subscribe(array $channels, array $links, ?string $payload = null): array
    {
        $rows = [];
        $index = 1;
        foreach ($channels as $channelId) {
            $url = Utils::forcedChannelUrl($channelId, $links);
            $text = count($channels) === 1 ? '🔗 Kanalga obuna' : sprintf('🔗 %d-kanalga obuna', $index);
            $rows[] = [['text' => $text, 'url' => $url]];
            $index++;
        }

        $callback = $payload === null ? 'sub:check' : 'sub:check:' . $payload;
        $rows[] = [['text' => '✅ Tekshirdim', 'callback_data' => $callback]];

        return ['inline_keyboard' => $rows];
    }

    /**
     * @param list<array<string, mixed>> $items
     * @return array<string, mixed>
     */
    public static function contentList(array $items, int $page, int $total, string $prefix): array
    {
        $rows = [];
        foreach ($items as $item) {
            $rows[] = [[
                'text' => (string) ($item['title'] ?? '-'),
                'callback_data' => 'content:' . (string) ($item['id'] ?? '0'),
            ]];
        }

        if ($total > 10) {
            $navRow = [];
            if ($page > 1) {
                $navRow[] = ['text' => '⬅️ Oldingi', 'callback_data' => sprintf('page:%s:%d', $prefix, $page - 1)];
            }
            if ($page * 10 < $total) {
                $navRow[] = ['text' => 'Keyingi ➡️', 'callback_data' => sprintf('page:%s:%d', $prefix, $page + 1)];
            }
            if ($navRow !== []) {
                $rows[] = $navRow;
            }
        }

        $rows[] = [['text' => '⬅️ Orqaga', 'callback_data' => 'back:menu']];

        return ['inline_keyboard' => $rows];
    }

    /** @return array<string, mixed> */
    public static function contentCard(int $contentId, bool $isFav): array
    {
        return [
            'inline_keyboard' => [
                [['text' => '▶️ Tomosha qilish', 'callback_data' => 'watch:' . $contentId . ':1']],
                [['text' => $isFav ? '✅ Saqlangan' : '⭐ Saqlash', 'callback_data' => 'fav:' . $contentId]],
                [['text' => '⬅️ Orqaga', 'callback_data' => 'back:menu']],
            ],
        ];
    }

    /**
     * @param list<array<string, mixed>> $parts
     * @return array<string, mixed>
     */
    public static function parts(int $contentId, array $parts, int $page, int $season, int $perPage = 24): array
    {
        $rows = [];
        $start = ($page - 1) * $perPage;
        $slice = array_slice($parts, $start, $perPage);

        $chunkRow = [];
        foreach ($slice as $part) {
            $chunkRow[] = [
                'text' => (string) ($part['part_number'] ?? '-'),
                'callback_data' => sprintf(
                    'part:%d:%d:%d',
                    $contentId,
                    $season,
                    (int) ($part['part_number'] ?? 0)
                ),
            ];
            if (count($chunkRow) === 4) {
                $rows[] = $chunkRow;
                $chunkRow = [];
            }
        }
        if ($chunkRow !== []) {
            $rows[] = $chunkRow;
        }

        $totalPages = (int) ceil(max(1, count($parts)) / max(1, $perPage));
        if ($page > 1 || $page < $totalPages) {
            $nav = [];
            if ($page > 1) {
                $nav[] = [
                    'text' => '⬅️ Oldingi',
                    'callback_data' => sprintf('parts:%d:%d:%d', $contentId, $season, $page - 1),
                ];
            }
            if ($page < $totalPages) {
                $nav[] = [
                    'text' => 'Keyingi ➡️',
                    'callback_data' => sprintf('parts:%d:%d:%d', $contentId, $season, $page + 1),
                ];
            }
            if ($nav !== []) {
                $rows[] = $nav;
            }
        }

        $rows[] = [
            ['text' => "📄 Ro'yxat", 'callback_data' => 'content:' . $contentId],
            ['text' => '⬅️ Orqaga', 'callback_data' => 'back:menu'],
        ];

        return ['inline_keyboard' => $rows];
    }

    /**
     * @param list<int> $seasons
     * @return array<string, mixed>
     */
    public static function seasons(int $contentId, array $seasons): array
    {
        $rows = [];
        $row = [];
        foreach ($seasons as $season) {
            $row[] = [
                'text' => sprintf('%d-fasl', $season),
                'callback_data' => sprintf('season:%d:%d', $contentId, $season),
            ];
            if (count($row) === 2) {
                $rows[] = $row;
                $row = [];
            }
        }
        if ($row !== []) {
            $rows[] = $row;
        }

        $rows[] = [
            ['text' => "📄 Ro'yxat", 'callback_data' => 'content:' . $contentId],
            ['text' => '⬅️ Orqaga', 'callback_data' => 'back:menu'],
        ];

        return ['inline_keyboard' => $rows];
    }

    /** @return array<string, mixed> */
    public static function adminMenu(): array
    {
        return [
            'inline_keyboard' => [
                [
                    ['text' => "➕ Kontent qo'shish", 'callback_data' => 'admin:add_content'],
                    ['text' => "➕ Qism qo'shish", 'callback_data' => 'admin:add_part'],
                ],
                [
                    ['text' => '🧑‍💻 Kontent (ID)', 'callback_data' => 'admin:edit'],
                    ['text' => '🔒 Majburiy obuna', 'callback_data' => 'admin:forced'],
                ],
                [
                    ['text' => '👥 Adminlar', 'callback_data' => 'admin:admins'],
                    ['text' => '📊 Statistika', 'callback_data' => 'admin:stats'],
                ],
                [
                    ['text' => '📢 Reklama yuborish', 'callback_data' => 'admin:broadcast'],
                    ['text' => '⚙️ Sozlamalar', 'callback_data' => 'admin:settings'],
                ],
            ],
        ];
    }

    /** @return array<string, mixed> */
    public static function adminForced(): array
    {
        return [
            'inline_keyboard' => [
                [
                    ['text' => "➕ Kanal qo'shish", 'callback_data' => 'admin:forced:add'],
                    ['text' => "➖ Kanal o'chirish", 'callback_data' => 'admin:forced:remove'],
                ],
                [
                    ['text' => "📄 Ro'yxat", 'callback_data' => 'admin:forced:list'],
                    ['text' => '⬅️ Orqaga', 'callback_data' => 'back:admin'],
                ],
            ],
        ];
    }

    /** @return array<string, mixed> */
    public static function adminAdmins(): array
    {
        return [
            'inline_keyboard' => [
                [
                    ['text' => "➕ Admin qo'shish", 'callback_data' => 'admin:admins:add'],
                    ['text' => "➖ Admin o'chirish", 'callback_data' => 'admin:admins:remove'],
                ],
                [
                    ['text' => "📄 Ro'yxat", 'callback_data' => 'admin:admins:list'],
                    ['text' => '⬅️ Orqaga', 'callback_data' => 'back:admin'],
                ],
            ],
        ];
    }

    /** @return array<string, mixed> */
    public static function adminSettings(): array
    {
        return [
            'inline_keyboard' => [
                [
                    ['text' => 'Anime', 'callback_data' => 'admin:settings:anime'],
                    ['text' => 'Kino', 'callback_data' => 'admin:settings:movie'],
                ],
                [
                    ['text' => 'Drama', 'callback_data' => 'admin:settings:drama'],
                    ['text' => '⬅️ Orqaga', 'callback_data' => 'back:admin'],
                ],
            ],
        ];
    }

    /** @return array<string, mixed> */
    public static function shareWatch(string $url): array
    {
        return [
            'inline_keyboard' => [
                [
                    ['text' => '✨ Tomosha qilish ✨', 'url' => $url],
                ],
            ],
        ];
    }
}
