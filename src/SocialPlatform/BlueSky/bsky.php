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
* Changes by @tobozo: removed the id lookup in the constructor
*
*/
class BlueskyApi
{
  private ?string $accountDid = null;
  private ?string $apiKey = null;
  private string $apiUri;

  public function __construct(?string $handle = null, ?string $app_password = null, string $api_uri = 'https://bsky.social/xrpc/')
  {
    $this->apiUri = $api_uri;

    if (($handle) && ($app_password)) {

      $args = [
        'identifier' => $handle,
        'password'   => $app_password,
      ];

      $data = $this->request('POST', 'com.atproto.server.createSession', $args);

      $this->accountDid = $data->did;

      $this->apiKey = $data->accessJwt;
    }
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
    $url = $this->apiUri . $request;

    if (($type === 'GET') && (count($args))) {
      $url .= '?' . http_build_query($args);
    } elseif (($type === 'POST') && (!$content_type)) {
      $content_type = 'application/json';
    }

    $headers = [];
    if ($this->apiKey) {
      $headers[] = 'Authorization: Bearer ' . $this->apiKey;
    }

    if ($content_type) {
      $headers[] = 'Content-Type: ' . $content_type;

      if (($content_type === 'application/json') && (count($args))) {
        $body = json_encode($args, JSON_THROW_ON_ERROR);
        $args = [];
      }
    }

    $c = curl_init();
    curl_setopt($c, CURLOPT_URL, $url);

    if (count($headers)) {
      curl_setopt($c, CURLOPT_HTTPHEADER, $headers);
    }

    switch ($type) {
      case 'POST':
        curl_setopt($c, CURLOPT_POST, 1);
        break;
      case 'GET':
        curl_setopt($c, CURLOPT_HTTPGET, 1);
        break;
      default:
        curl_setopt($c, CURLOPT_CUSTOMREQUEST, $type);
    }

    if ($body) {
      curl_setopt($c, CURLOPT_POSTFIELDS, $body);
    } elseif (($type !== 'GET') && (count($args))) {
      curl_setopt($c, CURLOPT_POSTFIELDS, json_encode($args, JSON_THROW_ON_ERROR));
    }

    curl_setopt($c, CURLOPT_HEADER, 0);
    curl_setopt($c, CURLOPT_VERBOSE, 0);
    curl_setopt($c, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($c, CURLOPT_SSL_VERIFYPEER, 1);

    $data = curl_exec($c);
    curl_close($c);

    return json_decode($data, false, 512, JSON_THROW_ON_ERROR);
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
    if( ! $this->api->getAccountDid() ) die("Unable to get account id");
    if(! is_dir( $this->img_cache_dir ) ) mkdir( $this->img_cache_dir ) or die("Please create directory ".$this->img_cache_dir." manually");
    $this->queue = new JSONQueue( INDEX_CACHE_DIR, "queue.bluesky.json" );
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




  public function get_uri_class(string $uri)
  {
    if (empty($uri)) {
      return null;
    }

    $elements = explode(':', $uri);
    if (empty($elements) || ($elements[0] != 'at')) {
      php_die("malformed URI".PHP_EOL);
    }

    $arr = [];

    $arr['cid'] = array_pop($elements);
    $arr['uri'] = implode(':', $elements);

    if ((substr_count($arr['uri'], '/') == 2) && (substr_count($arr['cid'], '/') == 2)) {
      $arr['uri'] .= ':' . $arr['cid'];
      $arr['cid'] = '';
    }

    return $arr;
  }


  public function get_uri_parts(string $uri)
  {
    $arr = $this->get_uri_class($uri);
    if (empty($arr)) {
      return null;
    }

    $parts = explode('/', substr($arr['uri'], 5));

    $arr = [];

    $arr['repo']       = $parts[0];
    $arr['collection'] = $parts[1];
    $arr['rkey']       = $parts[2];

    return $arr;
  }


  function delete_post(string $uri)
  {
    $parts = $this->get_uri_parts($uri);
    if (empty($parts)) {
      Logger::debug('No uri delected', ['uri' => $uri]);
      return;
    }
    $this->api->request('POST', 'com.atproto.repo.deleteRecord', $parts);
    //Logger::debug('Deleted', ['parts' => $parts]);
  }


  public function checkDupe( $text )
  {
    $last_10_posts = $this->api->request('GET', 'app.bsky.feed.getTimeline');

    $deleteCount = 0;

    foreach( $last_10_posts->feed as $pos => $item ) {
      if( $text == $item->post->record->text ) { // uh-oh, post already there
        if( $deleteCount > 0 ) {
          echo sprintf("Entry %d/%s is duplicate, deleting...\n", $pos, $item->post->uri);
          $this->delete_post( $item->post->uri );
        }
        $deleteCount++;
      }
    }
    if( $deleteCount > 0 ) {
      php_die("QOTD already posted, aborting".PHP_EOL );
    }
  }




  public function getEmbedCard( $url)
  {

    $info_card = GithubInfoFetcher::getCardInfo( $url );

    $card = [
      'uri'         => $url,
      'title'       => $info_card['og_title'],
      'description' => $info_card['og_description']
    ];
    $img_url = $info_card['og_image'];

    if( $img_url ) {
      if(!strstr($img_url, '://') ) {
        $img_url = $url.$img_url;
      }
      $img_path = $this->img_cache_dir."/".md5($url).'.image';

      if(! file_exists( $img_path ) ) {
        // dirty fix: github gives 429 (toot many requests) if the download is made too early after fetching the og: tags
        $blobImage = file_get_contents_exp_backoff( $img_url ) or die("Unable to fetch og:image at url $url");
        // TODO: use a more stubborn method for fetching the og:image
        file_put_contents($img_path, $blobImage ) or die("Unable to save og:image at url $url");
      } else {
        $blobImage = file_get_contents( $img_path ) or die("Unable to fetch og:image at path $img_path");
      }
      // get image mimetype
      $img_mime_type = image_type_to_mime_type(exif_imagetype($img_path));
      $response = $this->api->request('POST', 'com.atproto.repo.uploadBlob', [], $blobImage, $img_mime_type);
      if( !isset($response->blob) ) die("No blob in response");
      // echo "uploadBlob response for $img_mime_type: ".print_r($response, true)."\n";
      $card["thumb"] = $response->blob;
    }

    return [
        '$type' => "app.bsky.embed.external",
        'external' => $card
    ];
  }



  public function publish( $text )
  {
    //Get the URL from the text
    preg_match_all('#\bhttps?://[^\s()<>]+(?:\([\w\d]+\)|([^[:punct:]\s]|/))#', $text, $matches);

    if (!empty($matches) && !empty($matches[0][0]) ) {

      $url  = $matches[0][0];
      $text = trim(preg_replace('/#\w+\s*/', '', $text)); // remove hashtags, trim

      $this->checkDupe( $text );

      $args = [
        "repo"       => $this->api->getAccountDid(),
        "collection" => "app.bsky.feed.post",
        "record"     => [
          '$type'      => "app.bsky.feed.post",
          "langs"      => [$this->lang],
          "createdAt"  => date("c"),
          "text"       => $text,
          "facets"     => [[
            "index"      => [
              "byteStart"  =>  strpos($text,'https:'),
              "byteEnd"    =>  (strpos($text,'https:')+strlen($url))
            ],
            "features"   =>  [[
              "uri"        =>  $url,
              '$type'      =>  "app.bsky.richtext.facet#link"
            ]]
          ]]
        ]
      ];

      $embed = $this->getEmbedCard($url);

      if( $embed ) {
        $args['record']['embed'] = $embed;
      }

    } else {
      // We won't try to do anything clever with this
      $this->checkDupe( $text );

      $args = [
        "repo"       => $this->api->getAccountDid(),
        "collection" => "app.bsky.feed.post",
        "record"     => [
          '$type'      => "app.bsky.feed.post",
          "createdAt"  => date("c"), "text" => $text
        ]
      ];

    }

    // post the message
    $response = $this->api->request('POST', 'com.atproto.repo.createRecord', $args);
  }

}

