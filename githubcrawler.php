<?php

ini_set('memory_limit', '256M');
require_once(__DIR__ . '/vendor/autoload.php');
require_once(__DIR__ . '/src/githubcrawler.php');

/*
 * Follow bluesky/fediverse users found in arduino libraries registry and/or project pages
 */


$app = new \GithubCrawler\App;
$app->crawl();
