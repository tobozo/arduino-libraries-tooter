<?php

declare(strict_types=1);

namespace SocialPlatform;

//use \MastodonAPI;
use \Composer\Semver\Comparator;
use \LogManager\FileLogger;
use \SocialPlatform\GithubInfoFetcher;
use \QueueManager\JSONQueue;


// TODO: remove email addresses from author and maintainer fields (some@address), <some@address>
// TODO: pull project 'repo.topics' from github API to generate more hashtags


class MastodonAPI
{
  private $token;
  private $instance_url;
  public $response_headers = [];
  public $reply;
  public $formatted_item;
  public JSONQueue $queue;

  public function __construct($token, $instance_url)
  {
    $this->token = $token;
    $this->instance_url = $instance_url;
    $this->queue = new JSONQueue( INDEX_CACHE_DIR, "queue.mastodon.json" );
  }

  public function postStatus($status)
  {
    return $this->callAPI('/api/v1/statuses', 'POST', $status);
  }

  public function uploadMedia($media)
  {
    return $this->callAPI('/api/v1/media', 'POST', $media);
  }

  public function callAPI($endpoint, $method, $data)
  {
    $headers = [
      'Authorization: Bearer '.$this->token,
      'Content-Type: multipart/form-data',
      'Accept: application/json'
    ];

    $response_headers = [];

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $this->instance_url.$endpoint);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_USERAGENT, 'PHP 8/Arduino-Libraries-Announcer 1.0');
    curl_setopt($ch, CURLOPT_HEADERFUNCTION, function(\CurlHandle $ch, string $header) use (&$response_headers) {
      $len = strlen($header);
      $header = explode(':', $header, 2);
      if (count($header) < 2) // ignore invalid headers
          return $len;
      $response_headers[strtolower(trim($header[0]))] = trim($header[1]);
      return $len;
    });
    $this->reply = curl_exec($ch);
    $this->response_headers = $response_headers;

    if (!$this->reply) {
      return json_encode(['ok'=>false, 'curl_error_code' => curl_errno($ch), 'curl_error' => curl_error($ch)]);
    }
    curl_close($ch);

    return json_decode($this->reply, true);
  }
}




class MastodonStatus extends MastodonAPI
{

  private array $default_tags = ['#Arduino', '#ArduinoLibs'];
  private int $max_tags = 7;
  private int $max_characters = 500;
  private array $server_config;

  private string $default_arch = 'arduino';
  private array $account;

  public FileLogger $logger;


  public function __construct( array $conf )
  {
    foreach( ['token', 'instance_url', 'logger'] as $name ) {
      if( !isset( $conf[$name] ) )
      throw new \Exception("Missing conf[$name]");
    }
    parent::__construct($conf['token'], $conf['instance_url']);
    $this->logger  = $conf['logger'];
    $this->account = $this->getAccount();
    // echo "Account id: ".$this->account['id'].PHP_EOL;
    // $this->account['statuses_count'];
    $this->getInstanceConfig();
  }


  // Retrieve instance configuration (no token required).
  // Evaluate statuses max_characters/characters_reserved_per_url
  private function getInstanceConfig()
  {
    $serverDomainParts = parse_url(MASTODON_API_APP_URL) or php_die("Invalid mastodon API URL");
    isset( $serverDomainParts['host'] ) or php_die("Invalid mastodon API hostname");
    $serverConfigFile = INDEX_CACHE_DIR.'/'.$serverDomainParts['host'].'.json';

    if( !file_exists( $serverConfigFile ) ) {
      // GET /api/v1/instance
      $server_config = file_get_contents( MASTODON_API_APP_URL.'/api/v1/instance' ) or php_die("Unable to fetch instance configuration".PHP_EOL);
      file_put_contents( $serverConfigFile, $server_config ) or php_die("Unable to save instance configuration".PHP_EOL);
    } else {
      // read /path/to/cachedir/server.name.json
      $server_config = file_get_contents( $serverConfigFile ) or php_die("Unable to open instance configuration file".PHP_EOL);;
    }

    $this->server_config = json_decode( $server_config, true ) or php_die("Unable to decode json response from instance configuration".PHP_EOL);
    isset( $this->server_config['configuration']['statuses']['max_characters'] ) or php_die("Unable get configuration.statuses.max_characters from instance configuration".PHP_EOL);

    $this->max_characters = $this->server_config['configuration']['statuses']['max_characters'];
  }


  // create a formatted message from item properties
  // return formatted message
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
      implode(" ", array_unique($item['tags']))
    );


    // TODO: figure out server max Text character limit
    // TODO: truncate properly to fit
    if( strlen( $this->formatted_item ) > $this->max_characters ) {
      $this->logger->logf("[WARNING] Message length (%d) exceeds server limit (%d)".PHP_EOL, strlen( $this->formatted_item ). $this->max_characters );
    }

    return $this->formatted_item;
  }



  // process and post $item to mastodon network
  // return bool
  public function publish( array $item ): bool
  {
    $item = $this->processItem( $item );
    return $this->post( $item );
  }


  // prepare message properties
  // return processed item
  public function processItem( array $item ): array
  {
    // cleanup author field from email artefacts (enclosed by <>)
    $item['author'] = trim( preg_replace("/<[^>]+>/", "", (string)$item['author'] ) );
    // remove trailing ".git" in repository URL
    $item['repository'] = trim( preg_replace("/\.git$/", "", (string)$item['repository'] ) );
    // populate architectures (text and tags)
    $architectures = $this->default_arch; // (default)
    $item['tags']  = $this->default_tags; // ['#Arduino', '#ArduinoLibs']; // (defaults)
    if( isset($item['architectures']) && !empty($item['architectures']) ) {
      if( count( $item['architectures'] ) > 1 ) {
        foreach( $item['architectures'] as $idx => $arch ) {
          if( $idx <= $this->max_tags ) {
            $item['tags'][] = '#'.$arch;
          } else {
            unset( $item['architectures'][$idx] );
          }
        }
        $architectures = implode("/", $item['architectures'] );
      } else {
        if( $item['architectures'][0] != '*' ) {
          $architectures = $item['architectures'][0];
          $item['tags'][] = '#'.$item['architectures'][0];
        }
      }
    }
    $item['architectures'] = $architectures;
    return $item;
  }


  // format and post $item as a new status to mastodon network
  // return bool
  private function post( array $item ): bool
  {
    // ActivityPub status properties
    $status_data = [
      'status'     => $this->format( $item ), // populate message
      'visibility' => 'public', // 'private'; // Public , Unlisted, Private, and Direct (default)
      'language'   => 'en',
    ];

    // Publish to fediverse
    $resp = $this->postStatus($status_data);
    // API call failed, something wrong, result should be JSON object or array
    if( !$resp /*|| empty($resp)*/ ) {
      $this->logger->logf("[ERROR] (bad response) for %s (%s)".PHP_EOL, $item['name'], $item['version'] );
      return false;
    }
    // got a curl error
    if( isset( $resp['curl_error'] ) ) {
      $this->logger->logf("[ERROR] (curl error) for %s (%s).\nError code:%s\nError: %s".PHP_EOL, $item['name'], $item['version'], $resp ['curl_error_code'], $resp ['curl_error'] );
      return false;
    }
    // got an {"error":"blah"} message in Mastodon's JSON Response
    if( isset( $resp['error'] ) ) {
      $this->logger->logf("[ERROR] (application error) for %s (%s)\nJSON Error: %s (%d bytes)", $item['name'], $item['version'], $resp ['error'], strlen($status_data['status']) );
      return false;
    }
    // Success
    $this->logger->logf("[SUCCESS] Published %s (%s) by %s".PHP_EOL, $item['name'], $item['version'], $item['author'] );
    return true;
  }


  // retrieve last $max_count statuses from mastodon account
  // extract library name+version from posts
  // return array of library pairs [$name] => [$version]
  public function getLastItems( int $max_count=30 ): array
  {
    $args = [ /*'limit' => $max_count*/ ];
    $apicall = "/api/v1/accounts/".$this->account['id']."/statuses";
    $ret = $this->callAPI( $apicall, 'GET', $args);

    if( !$ret || ! is_array($ret ) || empty($ret) ) {
      $this->logger->logf("[ERROR] Could not fetch last posts (resp=%s, apicall=%s, resp_headers=%s, reply=%s)", $ret, $apicall, print_r( $this->response_headers, true ), print_r($this->reply, true) );
      return [];
    }

    $items = [];

    foreach( $ret as $post ) {
      if(!isset($post['content']) || empty($post['content']) ) {
        print_r($post);exit;
        $this->logger->logf("[WARNING] Post #%s has no content", $post['id'] );
        continue;
      }
      // fetch library name and version
      if( preg_match("/<p>([^(]+)\(([^)]+)\)/", $post['content'], $matches ) ) {
        if( count($matches)==3 && !empty($matches[0]) && !empty($matches[1]) && !empty($matches[2]) ) {
          $name    = trim($matches[1]);
          $version = trim($matches[2]);
          // TODO: check if $name has a cooldown
          // check if the library/version from this post is unset or higher version
          if( !isset( $items[$name] ) || \Composer\Semver\Comparator::greaterThan( $version, $items[$name] ) ) {
            $items[$name] = $version; // store in array
          }
        }
      }
    }

    return $items;
  }


  // retrieve account information (account id, statuses_count, etc)
  // the operation also validates the token
  // return user info
  private function getAccount(): array
  {
    $resp = $this->callAPI("/api/v1/accounts/verify_credentials", "GET", []);
    // catch curl error or API error
    if( isset( $resp['curl_error'] ) || isset( $resp['error'] ) ) {
      $err = $resp['curl_error']??$resp['error'];
      $this->logger->log( "[ERROR] API Error: ".$err );
      exit;
    }

    if( empty( $resp ) ) {
      $this->logger->log( "[ERROR] Bad token permissions (empty response)");
      exit;
    }

    if( !isset( $resp['id'] ) ) {
      $this->logger->log( "[ERROR] Bad API response");
      exit;
    }

    return $resp;
  }



}

