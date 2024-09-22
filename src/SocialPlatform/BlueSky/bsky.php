<?php

namespace SocialPlatform;

use \SocialPlatform\GithubInfoFetcher;
use \QueueManager\JSONQueue;

// Source/inspiration
// - https://atproto.com/blog/create-post
// - https://packagist.org/packages/cjrasmussen/bluesky-api
// - https://github.com/cjrasmussen/BlueskyApi
// - https://james.cridland.net/blog/2023/php-posting-to-bluesky/
// - https://github.com/friendica/friendica-addons/tree/develop/bluesky

/**
* Class for interacting with the Bluesky API/AT protocol
* - https://github.com/cjrasmussen/BlueskyApi
* Changes by @tobozo:
* - removed the id lookup in the constructor
* - added session cache
* - added rate limit pseudo support
* - added session create/refresh/revoke
*
*/
class BlueskyApi
{
  private ?string $accountDid = null;
  private ?string $apiKey = null;
  private ?array $session = null;
  private string $apiUri;

  private $lastRequestTime = 0;
  private $minDelayBetweenQueries = 1; // seconds

  private $response_headers = [];
  private $session_file = INDEX_CACHE_DIR.'/session.json';
  private $ratelimit_file = INDEX_CACHE_DIR.'/ratelimit.json';


  public function __construct(?string $handle = null, ?string $app_password = null, string $api_uri = 'https://bsky.social/xrpc/')
  {
    if( empty($handle) || empty($app_password) || empty($api_uri) )
      php_die("Missing credentials".PHP_EOL);

    $reset = $this->isRateLimited();

    if( $reset>0 )
    {
      echo("[WARNING] Bluesky rate limit in effect, will reset in $reset seconds".PHP_EOL);
      return;
    }

    $this->apiUri = $api_uri;

    if( !file_exists($this->session_file) )
    {
      if( !$this->authorizeAccess($handle, $app_password) )
      {
        return;
      }
    }
    else
    {
      if( !$this->refreshAccessToken() )
      {
        $this->revokeAccess();
      }
    }

  }


  /**
   * Check if the api is rate limited
   *
   * @return bool|int (false or the number of seconds left before reset)
   */
  public function isRateLimited()
  {
    if( file_exists($this->ratelimit_file) )
    {
      $ratelimit = json_decode(file_get_contents($this->ratelimit_file), true);
      $remaining = 0;

      if( array_key_exists('ratelimit-remaining', $ratelimit ) )
      {
        $remaining = intval($ratelimit['ratelimit-remaining']);
      }

      if( array_key_exists('ratelimit-reset', $ratelimit ) )
      {
        $reset = intval($ratelimit['ratelimit-reset'])-time();
        if( $remaining == 0 )
          return $reset;
      }
    }
    return false;
  }


  /**
   * Check if $session contains necessary entries
   *
   * @param array $session
   * @return bool
   */
  public function isValidSession($session): bool
  {
    if( empty($session) || !is_array($session) )
      return false;
    foreach(['did', 'accessJwt', 'refreshJwt'] as $key)
    {
      if(!array_key_exists($key, $session))
      {
        print_r($session);
        echo("Invalid bluesky session data (missing key '$key')".PHP_EOL);
        return false;
      }
    }
    return true;
  }


  /**
   * Check if $curl_response does not contain any error
   *
   * @param array $curl_response
   * @return bool
   */
  public function isValidResponse($curl_response): bool
  {
    foreach(['error', 'curl_error_code', 'curl_error'] as $key)
    {
      if(array_key_exists($key, $curl_response))
      {
        echo("[$key] $curl_response[$key]".PHP_EOL);
        return false;
      }
    }
    return true;
  }


  /**
   * Create session
   *
   * @param string $identifier
   * @param string $password
   * @return bool
   */
  public function authorizeAccess( $identifier, $password ): bool
  {
    $args = [
      'identifier' => $identifier,
      'password'   => $password,
    ];

    $session = $this->request('POST', 'com.atproto.server.createSession', $args);

    if(!$this->isValidResponse($session) || !$this->isValidSession($session) )
      return false;

    // save session
    file_put_contents($this->session_file, json_encode($session, JSON_PRETTY_PRINT)) or php_die("Unable to save bluesky session".PHP_EOL);

    $this->session    = $session;
    $this->accountDid = $session['did'];
    $this->apiKey     = $session['accessJwt'];

    return true;
  }


  /**
   * Refresh current session access
   *
   * @return bool
   */
  public function refreshAccessToken(): bool
  {
    $session = json_decode(file_get_contents($this->session_file), true);

    if(!is_array($session))
    {
      echo("Unable to read session file".PHP_EOL);
      return false;
    }

    if( !$this->isValidSession($session) )
      return false;

    $this->apiKey = $session['refreshJwt'];

    $session = $this->request('POST', 'com.atproto.server.refreshSession', []);

    if(!$this->isValidResponse($session) || !$this->isValidSession($session) )
      return false;

    file_put_contents($this->session_file, json_encode($session, JSON_PRETTY_PRINT)) or php_die("Unable to save refreshed session".PHP_EOL);

    $this->apiKey     = $session['accessJwt'];
    $this->accountDid = $session['did'];
    $this->session    = $session;

    return true;
  }


  /**
   * Revoke current session access
   *
   * @return void
   */
  public function revokeAccess(): void
  {
    if( file_exists($this->session_file))
    {
      echo "Revoked access".PHP_EOL;
      unlink($this->session_file);
    }
    $this->session = null;
  }


  /**
   * Get the current session
   *
   * @return array or null
   */
  public function getSession(): ?array
  {
    return $this->session;
  }


  /**
  * Get the current account DID
  *
  * @return string
  */
  public function getAccountDid(): ?string
  {
    return $this->accountDid;
  }


  /**
  * Set the account DID for future requests
  *
  * @param string|null $account_did
  * @return void
  */
  public function setAccountDid(?string $account_did): void
  {
    $this->accountDid = $account_did;
  }


  /**
  * Set the API key for future requests
  *
  * @param string|null $api_key
  * @return void
  */
  public function setApiKey(?string $api_key): void
  {
    $this->apiKey = $api_key;
  }


  /**
  * Return whether an API key has been set
  *
  * @return bool
  */
  public function hasApiKey(): bool
  {
    return $this->apiKey !== null;
  }


  /**
   * Retrieve response headers from the last query
   *
   * @return array
   */
  public function getResponseHeaders(): array
  {
    return $this->response_headers;
  }


  /**
  * Make a request to the Bluesky API
  *
  * @param string $type
  * @param string $request
  * @param array $args
  * @param string|null $body
  * @param string|null $content_type
  * @return mixed|object
  * @throws \JsonException
  */
  public function request(string $type, string $request, array $args = [], ?string $body = null, string $content_type = null)
  {
    $secondsSinceLastQuery = time()-$this->lastRequestTime;
    if( $secondsSinceLastQuery < $this->minDelayBetweenQueries )
    {
      // querying too fast, throttle
      sleep( $this->minDelayBetweenQueries );
    }

    $url = $this->apiUri . $request;

    if (($type === 'GET') && (count($args)))
      $url .= '?' . http_build_query($args);
    elseif (($type === 'POST') && (!$content_type))
      $content_type = 'application/json';

    $headers = [];
    if ($this->apiKey)
      $headers[] = 'Authorization: Bearer ' . $this->apiKey;

    if ($content_type)
    {
      $headers[] = 'Content-Type: ' . $content_type;

      if (($content_type === 'application/json') && (count($args)))
      {
        $body = json_encode($args, JSON_THROW_ON_ERROR);
        $args = [];
      }
    }

    $c = curl_init();
    curl_setopt($c, CURLOPT_URL, $url);

    if (count($headers))
      curl_setopt($c, CURLOPT_HTTPHEADER, $headers);

    switch ($type)
    {
      case 'POST':
        curl_setopt($c, CURLOPT_POST, 1);
        break;
      case 'GET':
        curl_setopt($c, CURLOPT_HTTPGET, 1);
        break;
      default:
        curl_setopt($c, CURLOPT_CUSTOMREQUEST, $type);
    }

    if ($body)
      curl_setopt($c, CURLOPT_POSTFIELDS, $body);
    elseif (($type !== 'GET') && (count($args)))
      curl_setopt($c, CURLOPT_POSTFIELDS, json_encode($args, JSON_THROW_ON_ERROR));

    curl_setopt($c, CURLOPT_HEADER, 0);
    curl_setopt($c, CURLOPT_VERBOSE, 0);
    curl_setopt($c, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($c, CURLOPT_SSL_VERIFYPEER, 1);
    curl_setopt($c, CURLOPT_USERAGENT, 'PHP 8/ArduinoLibs bot 1.2');
    curl_setopt($c, CURLOPT_HEADERFUNCTION, function($c, string $header) use (&$response_headers) {
      $len = strlen($header);
      $header = explode(':', $header, 2);
      if (count($header) < 2) // ignore invalid headers
        return $len;
      $response_headers[strtolower(trim($header[0]))] = trim($header[1]);
      return $len;
    });

    $data = curl_exec($c);

    $this->lastRequestTime = time();

    $this->response_headers = $response_headers;

    $http_code = curl_getinfo($c, CURLINFO_HTTP_CODE);

    $ratelimit = [];

    foreach(['limit', 'remaining', 'reset', 'policy'] as $key)
    {
      //     "ratelimit-limit"=>["30"],
      //     "ratelimit-remaining"=>["0"],
      //     "ratelimit-reset"=>["1694912614"],
      //     "ratelimit-policy"=>["30;w=300"],
      if( array_key_exists('ratelimit-'.$key, $response_headers ) )
      {
        $ratelimit['ratelimit-'.$key] = $response_headers['ratelimit-'.$key];
      }
    }


    if( !empty($ratelimit) )
    {
      file_put_contents( $this->ratelimit_file, json_encode($ratelimit, JSON_PRETTY_PRINT) );
    }


    if ($http_code != 200)
    {
      $ret = ['ok'=>false, 'curl_error_code' => curl_errno($c), 'curl_error' => curl_error($c), 'response_headers' => $response_headers];
      if( array_key_exists('ratelimit-reset', $ratelimit ) )
      {
        $ret['error'] = sprintf("Bluesky rate limit encountered, will reset in %d seconds", intval($response_headers['ratelimit-reset'])-strtotime($response_headers['date']) );
      }
    }
    else
      $ret = json_decode($data, true, 512, JSON_THROW_ON_ERROR);

    curl_close($c);

    return $ret;
  }
}



class BlueSkyStatus
{


  public $lang = "en";
  private $session = NULL;
  private $api = NULL;
  private $img_cache_dir = INDEX_CACHE_DIR.'/img';

  public $formatted_item;
  public JSONQueue $queue;


  public function __construct($username, $pass)
  {
    $this->api = new BlueskyApi($username, $pass);

    if( ! $this->hasSession() )
    {
      // echo("No Bluesky session".PHP_EOL);
      return;
    }
    if(! is_dir( $this->img_cache_dir ) ) mkdir( $this->img_cache_dir ) or php_die("Please create directory ".$this->img_cache_dir." manually".PHP_EOL);
    $this->queue = new JSONQueue( INDEX_CACHE_DIR, "queue.bluesky.json" );
  }


  public function hasSession()
  {
    $session = $this->api->getSession();
    if( $session == null )
      return false;
    if( !$this->api->isValidSession($session) )
      return false;
    return true;
  }


  public function format( array $item ): string
  {
    // populate message
    $this->formatted_item = sprintf( "%s (%s) for %s by %s\n\n➡️ %s\n\n%s\n\n%s ",
      $item['name'],
      $item['version'],
      $item['architectures'],
      $item['author'],
      $item['repository'],
      $item['sentence'],
      "Topics: ".implode(" ", array_unique($item['topics']))
    );
    return $this->formatted_item;
  }


  private function get_uri_class(string $uri)
  {
    if (empty($uri))
      return null;

    $elements = explode(':', $uri);

    if (empty($elements) || ($elements[0] != 'at'))
      php_die("malformed URI: $uri".PHP_EOL);

    $arr = [];

    $arr['cid'] = array_pop($elements);
    $arr['uri'] = implode(':', $elements);

    if ((substr_count($arr['uri'], '/') == 2) && (substr_count($arr['cid'], '/') == 2))
    {
      $arr['uri'] .= ':' . $arr['cid'];
      $arr['cid'] = '';
    }

    return $arr;
  }


  private function get_uri_parts(string $uri)
  {
    $arr = $this->get_uri_class($uri);
    if (empty($arr))
      return null;

    $parts = explode('/', substr($arr['uri'], 5));

    $arr = [];

    $arr['repo']       = $parts[0];
    $arr['collection'] = $parts[1];
    $arr['rkey']       = $parts[2];

    return $arr;
  }


  private function delete_post(string $uri)
  {
    $parts = $this->get_uri_parts($uri);
    if (empty($parts))
    {
      Logger::debug('No uri delected', ['uri' => $uri]);
      return;
    }
    $this->api->request('POST', 'com.atproto.repo.deleteRecord', $parts);
    //Logger::debug('Deleted', ['parts' => $parts]);
  }


  public function checkDupe( $text )
  {
    if(! $this->hasSession() )
      return;

    $last_10_posts = $this->api->request('GET', 'app.bsky.feed.getTimeline');

    $deleteCount = 0;

    foreach( $last_10_posts['feed'] as $pos => $item )
    {
      if( $text == $item['post']['record']['text'] )
      { // uh-oh, post already there
        if( $deleteCount > 0 )
        {
          echo sprintf("Entry %d/%s is duplicate, deleting...\n", $pos, $item['post']['uri']);
          $this->delete_post( $item['post']['uri'] );
        }
        $deleteCount++;
      }
    }
    if( $deleteCount > 0 )
      php_die("QOTD already posted, aborting".PHP_EOL );
  }




  public function getEmbedCard($url)
  {
    $info_card = GithubInfoFetcher::getCardInfo($url);

    $card = [
      'uri'         => $url,
      'title'       => $info_card['og_title'],
      'description' => $info_card['og_description']
    ];
    $img_url = $info_card['og_image'];

    if( $img_url )
    {
      if( !strstr($img_url, '://') ) // relative image URL?
        $img_url = $url.$img_url;

      $img_path = $this->img_cache_dir."/".md5($url).'.image';

      if(! file_exists($img_path) )
      {
        // dirty fix: github gives 429 (toot many requests) if the download is made too early after fetching the og: tags
        $blobImage = file_get_contents_exp_backoff( $img_url ) or php_die("Unable to fetch og:image at url $url".PHP_EOL);
        // TODO: use a more stubborn method for fetching the og:image
        file_put_contents($img_path, $blobImage ) or php_die("Unable to save og:image at url $url".PHP_EOL);
      }
      else
        $blobImage = file_get_contents( $img_path ) or php_die("Unable to fetch og:image at path $img_path".PHP_EOL);
      // get image mimetype
      $img_mime_type = image_type_to_mime_type(exif_imagetype($img_path));
      $response = $this->api->request('POST', 'com.atproto.repo.uploadBlob', [], $blobImage, $img_mime_type);
      if( !array_key_exists('blob', $response) ) php_die("No blob in response".PHP_EOL);
      // echo "uploadBlob response for $img_mime_type: ".print_r($response, true)."\n";
      $card['thumb'] = $response['blob'];
    }

    return [
        '$type' => "app.bsky.embed.external",
        'external' => $card
    ];
  }



  public function publish( $text )
  {
    if(! $this->hasSession() )
      return ['error' => 'no session'];

    $this->checkDupe( $text );

    $args = [
      'repo'       => $this->api->getAccountDid(),
      'collection' => 'app.bsky.feed.post',
      'record'     => [
        '$type'      => 'app.bsky.feed.post',
        'langs'      => [$this->lang],
        'createdAt'  => date("c"),
        'text'       => $text
      ]
    ];

    $this->addFacets( $text, $args );

    // post the message
    return $this->api->request('POST', 'com.atproto.repo.createRecord', $args);
  }


  private function addHashTagFacets( $text, &$args )
  {
    // see https://docs.bsky.app/docs/advanced-guides/post-richtext#producing-facets

    if( ! preg_match_all('/(?:^|\s)(#[^\d\s]\S*)(?=\s)?/', $text, $matches) )
      return;

    if( !isset($matches[0]) || count($matches[0])==0 )
      return;

    foreach($matches[0] as $match)
    {
      $hashtag = $match;

      $hasLeadingSpace = preg_match('/^\s/', $hashtag, $whatever);

      $hashtag = trim($hashtag);

      $hashtag = preg_replace('/\p{P}+$/', '', $hashtag ); // strip ending punctuation

      if( strlen($hashtag) > 66 )
        continue;

      if(!isset($args['record']['facets']))
        $args['record']['facets'] = [];

      $index = strpos($text, $match)+($hasLeadingSpace?1:0);

      $args['record']['facets'][] = [
        'index' => [
          'byteStart' => $index,
          'byteEnd'   => $index+strlen($hashtag) // inclusive of last char
        ],
        'features' => [[
          '$type' => 'app.bsky.richtext.facet#tag',
          'tag' => str_replace('#', '', $hashtag)
        ]]
      ];

    }
  }

  private function addLinkFacets( $text, &$args )
  {
    // see https://docs.bsky.app/docs/advanced-guides/post-richtext#producing-facets

    if( ! preg_match_all('#\bhttps?://[^\s()<>]+(?:\([\w\d]+\)|([^[:punct:]\s]|/))#', $text, $matches) )
      return;

    if( !isset($matches[0]) || count($matches[0])==0 || empty($matches[0][0] ) )
      return;

    $url  = $matches[0][0];

    if( ! isset($args['record']['facets']) )
      $args['record']['facets'] = [];

    $args['record']['facets'][] = [
      'index'      => [
        'byteStart'  =>  strpos($text,'https:'),
        'byteEnd'    =>  (strpos($text,'https:')+strlen($url))
      ],
      'features'   =>  [[
        'uri'        =>  $url,
        '$type'      =>  'app.bsky.richtext.facet#link'
      ]]
    ];

    // generate the oembed card
    $embed = $this->getEmbedCard($url);

    if( $embed )
      $args['record']['embed'] = $embed;

  }


  private function addFacets( $text, &$args )
  {
    $this->addLinkFacets( $text, $args );
    $this->addHashTagFacets( $text, $args );
  }



}

