<?php

// NOTE: run this hourly

ini_set('memory_limit', '256M');
require_once(__DIR__ . '/vendor/autoload.php');
require_once(__DIR__ . '/src/bluesky-keyword-liker.php');

$app = new BlueskyKeywordLiker\App;

$keywords = [ 'ArduinoIDE', 'Arduino', 'Arduino Library', 'rp2040', 'rp2350', 'stm32', 'esp32 arduino', 'esp8266' ];

$search_results = $app->search(['keywords' => $keywords]);

$app->favourite( $search_results );


