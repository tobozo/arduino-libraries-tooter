<?php

declare(strict_types=1);

namespace SocialPlatform;

use \MastodonAPI;
use \Composer\Semver\Comparator;


class MastodonStatus extends MastodonAPI
{

  private array $default_tags = ['#Arduino', '#ArduinoLibs'];
  private string $default_arch = 'arduino';
  private string $account_id;

  public object $logger;


  public function __construct( array $conf )
  {
    foreach( ['token', 'instance_url', 'account_id'] as $name ) {
      if( !isset( $conf[$name] ) )
      throw new \Exception("Missing conf[$name]");
    }

    parent::__construct($conf['token'], $conf['instance_url']);
    $this->account_id = $conf['account_id'];
  }

  // create a formatted message from item properties
  // return formatted message
  public function format( array $item ): string
  {
    // populate message
    return sprintf( "%s (%s) for %s by %s\n\n➡️ %s\n\n%s\n\n%s ",
      $item['name'],
      $item['version'],
      $item['architectures'],
      $item['author'],
      $item['repository'],
      $item['sentence'],
      implode(" ", array_unique($item['tags']))
    );
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
    $item['author'] = trim( preg_replace("/<[^>]+>/", "", $item['author'] ) );
    // remove trailing ".git" in repository URL
    $item['repository'] = trim( preg_replace("/\.git$/", "", $item['repository'] ) );
    // populate architectures (text and tags)
    $architectures = $this->default_arch; // (default)
    $item['tags']  = $this->default_tags; // ['#Arduino', '#ArduinoLibs']; // (defaults)
    if( isset($item['architectures']) && !empty($item['architectures']) ) {
      if( count( $item['architectures'] ) > 1 ) {
        $architectures = implode("/", $item['architectures'] );
        foreach( $item['architectures'] as $pos => $arch ) {
          $item['tags'][] = '#'.$arch;
        }
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
      $this->logger->logf("[ERROR] (bad response) for %s (%s)\n", $item['name'], $item['version'] );
      return false;
    }
    // got a curl error
    if( isset( $resp['curl_error'] ) ) {
      $this->logger->logf("[ERROR] (curl error) for %s (%s).\nError code:%s\nError: %s\n", $item['name'], $item['version'], $resp ['curl_error_code'], $resp ['curl_error'] );
      return false;
    }
    // got an {"error":"blah"} message in Mastodon's JSON Response
    if( isset( $resp['error'] ) ) {
      $this->logger->logf("[ERROR] (application error) for %s (%s)\nJSON Error: %s", $item['name'], $item['version'], $resp ['error'] );
    }
    // Success
    $this->logger->logf("[SUCCESS] Published %s (%s) / %s as %s\n", $item['name'], $item['version'], $item['author'], isset( $resp['id'] ) ? $resp['id'] : 'no-ID' );
    return true;
  }


  // retrieve last $max_count statuses from mastodon account
  // extract library name+version from posts
  // return array of library pairs [$name] => [$version]
  public function getLastItems( int $max_count=30 ): array
  {
    $args = [ 'limit' => $max_count ];
    $ret = $this->callAPI( "/api/v1/accounts/".$this->account_id."/statuses", 'GET', $args);

    if( !$ret || ! is_array($ret ) || empty($ret) ) {
      $this->logger->logf("[ERROR] Could not fetch last posts (resp=%s)", $ret);
      return [];
    }

    $items = [];

    foreach( $ret as $id=>$post ) {
      if(!isset($post['content']) || empty($post['content']) ) {
        $this->logger->logf("[WARNING] Post #%s has no content", $post['id'] );
        continue;
      }
      // fetch library name and version
      if( preg_match("/<p>([^(]+)\(([^)]+)\)/", $post['content'], $matches ) ) {
        if( count($matches)==3 && !empty($matches[0]) && !empty($matches[1]) && !empty($matches[2]) ) {
          $name    = trim($matches[1]);
          $version = trim($matches[2]);
          // check if the library/version from this post is unset or higher version
          if( !isset( $items[$name] ) || \Composer\Semver\Comparator::greaterThan( $version, $items[$name] ) ) {
            $items[$name] = $version; // store in array
          }
        }
      }
    }

    return $items;
  }


}

