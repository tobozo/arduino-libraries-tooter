<?php

ini_set('memory_limit', '256M');
require_once(__DIR__ . '/vendor/autoload.php');
require_once(__DIR__ . '/src/crawler.php');

$app = new MastodonCrawler\App;
$app->crawl();
