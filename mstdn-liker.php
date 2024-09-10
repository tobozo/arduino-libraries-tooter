<?php

// NOTE: run this hourly

ini_set('memory_limit', '256M');
require_once(__DIR__ . '/vendor/autoload.php');
require_once(__DIR__ . '/src/mastodon-keyword-liker.php');

$app = new MastodonKeywordLiker\App;

$keywords = [ 'ArduinoIDE', 'Arduino', 'Arduino Library', 'rp2040', 'stm32', 'esp32 arduino', 'esp8266' ];

$search_results = $app->search([
    'keywords'=> $keywords,
    'latest' => true // max age is one week, comment this out to crawl the past
]);


$app->favourite( $search_results );


