<?php

namespace SocialPlatform;


function file_get_contents_exp_backoff($url, $maxRetries = 5, $initialWait = 1.0)
{

  global $http_response_header;
  $result      = false;

  try {
    $retry   = false;
    $retries = 0;
    do {
      if ($retries > 0) {
        // calculate exponential backoff and wait
        $waitTime = pow(2, $retries);
        sleep($initialWait * $waitTime);
      }
      $result = @file_get_contents($url);
      preg_match('/([0-9])\d+/',$http_response_header[0],$matches);
      $responsecode = intval($matches[0]);

      // Retry if server error (5XX) or throttling error (429) occurred
      $retry = ($responsecode == 500 || $responsecode == 503 || $responsecode == 429);
    } while ($retry && ($retries++ < $maxRetries));
  } catch (Exception $e) {
    return false;
  }

  return $result;
}



class GithubInfoFetcher
{

  public static $cache = [];


  public static function getCardInfo($url)
  {
    if( isset( GithubInfoFetcher::$cache[$url] ) ) {
      return GithubInfoFetcher::$cache[$url];
    }

    $card_info = [
      "og_image"       => "",
      "og_title"       => "",
      "og_description" => "",
      "topics"         => []
    ];

    # fetch the HTML
    $resp = file_get_contents( $url ) or die("Unable to fetch $url ");

    libxml_use_internal_errors(true); // don't spam the console with XML warnings
    $doc = new \DOMDocument();
    $doc->loadHTML($resp);
    $selector = new \DOMXPath($doc);
    $title_tags_arr = $selector->query('//meta[@property="og:title"]');
    $desc_tags_arr  = $selector->query('//meta[@property="og:description"]');
    $img_url_arr    = $selector->query('//meta[@property="og:image"]');
    $topics_arr     = $selector->query('//a[@data-octo-click="topic_click"]');

    // loop through all found items
    foreach($title_tags_arr as $node) {
      $title_tag = $node->getAttribute('content');
    }
    foreach($desc_tags_arr as $node) {
      $description_tag = $node->getAttribute('content');
    }
    foreach($img_url_arr as $node) {
      $img_url = $node->getAttribute('content');
    }
    foreach($topics_arr as $node) {
      $card['topics'][] = str_replace('topic:', '', $node->getAttribute('data-octo-dimensions') );
    }
    if( isset($img_url) ) {
      $card_info['og_image'] = $img_url;
    }
    # parse out the "og:title" and "og:description" HTML meta tags
    if( isset($title_tag) ) {
      $card_info['og_title'] = $title_tag;
    }
    if( isset($description_tag) ) {
      $card_info['og_description'] = $description_tag;
    }
    foreach($topics_arr as $node) {
      $card_info['topics'][] = str_replace('topic:', '', $node->getAttribute('data-octo-dimensions') );
    }

    GithubInfoFetcher::$cache[$url] = $card_info;

    return $card_info;

  }

};
