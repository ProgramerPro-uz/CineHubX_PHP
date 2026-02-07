<?php

declare(strict_types=1);

namespace CineHubX\PhpBot;

final class Utils
{
    public static function formatValue(string|int|null $value): string
    {
        if ($value === null || trim((string) $value) === '') {
            return '—';
        }

        return (string) $value;
    }

    public static function formatCard(array $data): string
    {
        $parts = $data['parts_total'] ?? $data['parts_count'] ?? null;
        $partsLine = '';

        if (($data['type'] ?? '') !== 'movie') {
            $partsLine = '🎥 Qismi: ' . self::formatValue(is_scalar($parts) ? (string) $parts : null) . "\n";
        }

        $codeLine = '';

        return strtr(Texts::CARD_TEMPLATE, [
            '{title}' => self::formatValue(isset($data['title']) ? (string) $data['title'] : null),
            '{type}' => self::typeLabel(isset($data['type']) ? (string) $data['type'] : null),
            '{year}' => self::formatValue(isset($data['year']) ? (string) $data['year'] : null),
            '{country}' => self::formatValue(isset($data['country']) ? (string) $data['country'] : null),
            '{language}' => self::formatValue(isset($data['language']) ? (string) $data['language'] : null),
            '{genres}' => self::formatValue(isset($data['genres']) ? (string) $data['genres'] : null),
            '{parts_line}' => $partsLine,
            '{code_line}' => $codeLine,
        ]);
    }

    public static function formatShareCard(array $data): string
    {
        $parts = $data['parts_total'] ?? $data['parts_count'] ?? null;
        $partsLine = '';

        if (($data['type'] ?? '') !== 'movie') {
            $partsLine = '🎥 Qismi: ' . self::formatValue(is_scalar($parts) ? (string) $parts : null) . "\n";
        }

        $codeLine = '';
        if (array_key_exists('code', $data) && $data['code'] !== null) {
            $codeLine = '🆔 Kodi: ' . self::formatValue((string) $data['code']) . "\n";
        }

        return strtr(Texts::CARD_TEMPLATE, [
            '{title}' => self::formatValue(isset($data['title']) ? (string) $data['title'] : null),
            '{type}' => self::typeLabel(isset($data['type']) ? (string) $data['type'] : null),
            '{year}' => self::formatValue(isset($data['year']) ? (string) $data['year'] : null),
            '{country}' => self::formatValue(isset($data['country']) ? (string) $data['country'] : null),
            '{language}' => self::formatValue(isset($data['language']) ? (string) $data['language'] : null),
            '{genres}' => self::formatValue(isset($data['genres']) ? (string) $data['genres'] : null),
            '{parts_line}' => $partsLine,
            '{code_line}' => $codeLine,
        ]);
    }

    /**
     * @return array<int, string>
     */
    public static function parseForceLinks(string $raw): array
    {
        $links = [];
        if (trim($raw) === '') {
            return $links;
        }

        foreach (explode(',', $raw) as $item) {
            $chunk = trim($item);
            if ($chunk === '' || !str_contains($chunk, '|')) {
                continue;
            }

            [$key, $url] = explode('|', $chunk, 2);
            $key = trim($key);
            $url = trim($url);

            if ($key === '' || !is_numeric($key) || $url === '') {
                continue;
            }

            $links[(int) $key] = $url;
        }

        return $links;
    }

    public static function forcedChannelUrl(int $channelId, array $links): string
    {
        if (isset($links[$channelId])) {
            return $links[$channelId];
        }

        return 'https://t.me/' . str_replace('-100', '', (string) $channelId);
    }

    private static function typeLabel(?string $value): string
    {
        return match ($value) {
            'movie' => 'Kino',
            'series' => 'Serial',
            'anime' => 'Anime',
            default => self::formatValue($value),
        };
    }
}
