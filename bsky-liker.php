<?php

// NOTE: run this hourly

ini_set('memory_limit', '256M');
require_once(__DIR__ . '/vendor/autoload.php');
require_once(__DIR__ . '/src/bluesky-keyword-liker.php');

$app = new BlueskyKeywordLiker\App;

///$followers = $app->fetchFollowers();
//echo sprintf("Fetched %d followers".PHP_EOL, count($followers) );
//exit;


$keywords = [ 'ArduinoIDE', 'Arduino', 'Arduino Library', 'rp2040', 'stm32', 'esp32 arduino', 'esp8266' ];

$search_results = $app->search($keywords);


foreach( $search_results as $keyword => $posts_to_like )
{
    echo sprintf("Keyword %s has %d likes to perform".PHP_EOL, $keyword, count($posts_to_like));
    foreach($posts_to_like as $post)
    {
        $uriParts = explode('/', $post['uri']);
        $url = sprintf("https://bsky.app/profile/%s/post/%s", $post['author']['handle'], end($uriParts) );
        echo "Liking url ... $url".PHP_EOL;
        $res = $app->likePost( $post, $keyword );
        //exit;
        //sleep(3);
    }
}


//print_r($search_results);


