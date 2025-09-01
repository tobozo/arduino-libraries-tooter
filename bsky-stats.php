<?php

// NOTE: run this daily

ini_set('memory_limit', '256M');
require_once(__DIR__ . '/vendor/autoload.php');
require_once(__DIR__ . '/src/bluesky-analytics.php');

$app = new BlueskyAnalytics\App;
$app->run();


