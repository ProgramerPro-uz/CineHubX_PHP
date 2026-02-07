<?php

declare(strict_types=1);

namespace CineHubX\PhpBot;

final class Config
{
    public function __construct(
        public readonly string $botToken,
        public readonly string $botUsername,
        /** @var list<int> */
        public readonly array $admins,
        /** @var list<int> */
        public readonly array $contentChannelIds,
        public readonly string $databaseUrl,
        public readonly ?string $databaseFallbackUrl,
        /** @var list<int> */
        public readonly array $forcedChannels,
        /** @var array<int, string> */
        public readonly array $forcedChannelLinks,
        /** @var array<string, int> */
        public readonly array $shareChannels,
    ) {
    }

    public static function load(string $projectRoot): self
    {
        self::loadDotenv($projectRoot . '/.env');

        $botToken = self::getEnv('BOT_TOKEN');
        $botUsername = self::getEnv('BOT_USERNAME', '');
        $admins = self::parseIntCsv(self::getEnv('ADMIN_IDS', ''));
        $contentChannelIds = self::parseIntCsv(self::getEnv('CONTENT_CHANNEL_ID', '0'));

        $dbUrl = self::getOptionalEnv('DATABASE_URL');
        $publicUrl = self::getOptionalEnv('DATABASE_PUBLIC_URL');
        $fallbackUrl = null;

        if ($publicUrl !== null) {
            $publicUrl = self::ensureSslMode($publicUrl);
        }

        if ($dbUrl === null && $publicUrl === null) {
            throw new \RuntimeException('Missing required env var: DATABASE_URL or DATABASE_PUBLIC_URL');
        }

        if ($dbUrl === null) {
            $dbUrl = $publicUrl;
        } elseif ($publicUrl !== null && $publicUrl !== $dbUrl) {
            $fallbackUrl = $publicUrl;
        }

        $forcedChannels = self::parseIntCsv(self::getEnv('FORCE_CHANNELS', ''));
        $forcedLinksRaw = self::getEnv('FORCE_CHANNEL_URLS', '');

        return new self(
            botToken: $botToken,
            botUsername: $botUsername,
            admins: $admins,
            contentChannelIds: $contentChannelIds,
            databaseUrl: $dbUrl,
            databaseFallbackUrl: $fallbackUrl,
            forcedChannels: $forcedChannels,
            forcedChannelLinks: Utils::parseForceLinks($forcedLinksRaw),
            shareChannels: [
                'anime' => (int) self::getEnv('anime_sharing', '0'),
                'series' => (int) self::getEnv('drama_sharing', '0'),
                'movie' => (int) self::getEnv('movie_sharing', '0'),
            ],
        );
    }

    private static function loadDotenv(string $envPath): void
    {
        if (!is_file($envPath)) {
            return;
        }

        $lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            return;
        }

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                continue;
            }

            [$key, $value] = explode('=', $line, 2);
            $key = trim($key);
            $value = trim($value);
            $value = trim($value, "\"'");

            if (getenv($key) !== false) {
                continue;
            }

            putenv($key . '=' . $value);
            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
        }
    }

    private static function getEnv(string $name, ?string $default = null): string
    {
        $value = getenv($name);
        if ($value === false) {
            if ($default !== null) {
                return $default;
            }
            throw new \RuntimeException(sprintf('Missing required env var: %s', $name));
        }

        return $value;
    }

    private static function getOptionalEnv(string $name): ?string
    {
        $value = getenv($name);
        if ($value === false) {
            return null;
        }

        $value = trim((string) $value);
        return $value === '' ? null : $value;
    }

    private static function ensureSslMode(string $url, string $sslMode = 'require'): string
    {
        if (str_contains($url, 'sslmode=')) {
            return $url;
        }

        $separator = str_contains($url, '?') ? '&' : '?';
        return $url . $separator . 'sslmode=' . $sslMode;
    }

    /**
     * @return list<int>
     */
    private static function parseIntCsv(string $value): array
    {
        $parts = array_filter(array_map('trim', explode(',', $value)), static fn (string $v): bool => $v !== '');
        $result = [];

        foreach ($parts as $part) {
            if (is_numeric($part)) {
                $result[] = (int) $part;
            }
        }

        return $result;
    }
}
