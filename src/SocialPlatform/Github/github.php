<?php

namespace SocialPlatform;

// stubborn file_get_contents(), because on average 1/5th queries to github will fail
function file_get_contents_exp_backoff($url, $maxRetries = 5, $initialWait = 1.0)
{
  global $http_response_header;
  $result = false;

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

  public static $cache = []; // memory cache
  public static $cache_dir = "cache/github"; // filesystem cache


  // Translate URL to local file path for cache persistence
  // $url   -> http://blah.com/user/project
  // return -> blah.com/user/project.json or (bool)false
  public static function urlToPath($url)
  {
    $urlParts = parse_url($url);

    if(empty($urlParts) || empty($urlParts['host']) || empty($urlParts['path'])) // malformed url
    {
      echo sprintf("[github][WARNING] Malformed URL: $url".PHP_EOL);
      return false;
    }

    $pathParts = explode('/', $urlParts['path']);

    if(empty($pathParts) || count($pathParts)!=3) // not a github repo url
    {
      echo sprintf("[github][WARNING] Malformed path in URL: $url".PHP_EOL);
      return false;
    }

    return sprintf('%s/%s/%s/%s', GithubInfoFetcher::$cache_dir, $urlParts['host'], $pathParts[1], $pathParts[2]);
  }


  public static function saveCardInfo($url, $card_info)
  {
    // save to ram
    GithubInfoFetcher::$cache[$url] = $card_info;
    // save to filesystem
    $fileName = GithubInfoFetcher::urlToPath($url);
    if($fileName===false)
      return false;
    $dirName = dirname($fileName);
    if(!is_dir($dirName))
      if(!mkdir($dirName, 0777, true)) return false;
    if(!file_put_contents( $fileName, json_encode($card_info) )) return false;

    return true;
  }


  public static function loadCachedCardInfo($url)
  {
    $fileName = GithubInfoFetcher::urlToPath($url);
    if( !file_exists($fileName) ) return [];
    $txt = @file_get_contents($fileName);
    if( empty($txt) ) return [];
    $json = @json_decode($txt, true);
    if( empty($json ) ) return [];
    GithubInfoFetcher::$cache[$url] = $json; // store in ram
    return $json;
  }



  // collect opengraph data from a github/gitlab/codeberg project page
  public static function getCardInfo($url, $die_on_fail=true )
  {
    if( isset( GithubInfoFetcher::$cache[$url] ) )
      return GithubInfoFetcher::$cache[$url];

    $card_info = [
      "og_image"       => "",
      "og_title"       => "",
      "og_description" => "",
      "og_me"          => [],
      "topics"         => []
    ];

    // try to fetch the freshest HTML first, be stubborn
    $resp = @file_get_contents_exp_backoff( $url );

    if(!$resp) { // network fetching failed, try filesystem cache
      $cached = GithubInfoFetcher::loadCachedCardInfo($url);
      if(empty($cached) && $die_on_fail)
        die("[github][ERROR] Unable to fetch $url ".PHP_EOL);
      return $cached; // NOTE: can be empty
    }

    libxml_use_internal_errors(true); // don't spam the console with XML warnings
    $doc = new \DOMDocument();
    $doc->loadHTML($resp);
    $selector = new \DOMXPath($doc);
    $title_tags_arr = $selector->query('//meta[@property="og:title"]');
    $desc_tags_arr  = $selector->query('//meta[@property="og:description"]');
    $img_url_arr    = $selector->query('//meta[@property="og:image"]');
    // <a rel="me nofollow" href="https://mastodon.social/@tobozo">@tobozo@mastodon.social</a>
    $me_url_arr     = $selector->query('//a[contains(@rel,"me")]');
    $topics_arr     = $selector->query('//a[@data-octo-click="topic_click"]');

    // loop through all found items
    foreach($title_tags_arr as $node) {
      $title_tag = $node->getAttribute('content');
    }
    foreach($desc_tags_arr as $node) {
      $description_tag = $node->getAttribute('content');
    }
    foreach($me_url_arr as $node) {
      $me_url[] = $node->getAttribute('href');
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
    if( isset( $me_url )) {
      $card_info['og_me'] = $me_url;
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

    if(! GithubInfoFetcher::saveCardInfo($url, $card_info) )
      echo sprintf("[github][WARNING] Unable to save card info for $url".PHP_EOL);

    return $card_info;

  }

};
