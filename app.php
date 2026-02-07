<?php

declare(strict_types=1);

use CineHubX\PhpBot\BotApp;
use CineHubX\PhpBot\Config;
use CineHubX\PhpBot\Database;
use CineHubX\PhpBot\TelegramApi;

require_once __DIR__ . '/src/Texts.php';
require_once __DIR__ . '/src/Utils.php';
require_once __DIR__ . '/src/Config.php';
require_once __DIR__ . '/src/Keyboards.php';
require_once __DIR__ . '/src/Database.php';
require_once __DIR__ . '/src/TelegramApi.php';
require_once __DIR__ . '/src/BotApp.php';

$projectRoot = __DIR__;

$config = Config::load($projectRoot);
$db = new Database($config->databaseUrl, $config->databaseFallbackUrl);
$db->init();

$api = new TelegramApi($config->botToken);
$app = new BotApp($config, $db, $api);

$app->run();
