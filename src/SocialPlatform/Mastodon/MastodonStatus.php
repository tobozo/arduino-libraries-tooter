<?php

declare(strict_types=1);

namespace SocialPlatform;

use \MastodonAPI;
use \Composer\Semver\Comparator;


class MastodonStatus extends MastodonAPI
{

  private $default_tags = ['#Arduino', '#ArduinoLibs'];
  private $default_arch = 'arduino';
  private $account_id;

  public $logger;


  public function __construct($token, $instance_url, $account_id)
  {
    parent::__construct($token, $instance_url);
    $this->account_id = $account_id;
  }


  public function format( $item )
  {
    // populate message
    return sprintf( "%s (%s) for %s by %s\n➡️ %s\n%s\n%s ",
      $item['name'],
      $item['version'],
      $item['architectures'],
      $item['author'],
      $item['repository'],
      $item['sentence'],
      implode(" ", array_unique($item['tags']))
    );
  }


  public function publish( $item )
  {
    $item = $this->processItem( $item );
    return $this->post( $item );
  }


  public function processItem( $item )
  {
    // prepare message properties
    $item['author']     = trim( preg_replace("/<(.*)>/", "", $item['author'] ) ); // remove email address from author name
    $item['repository'] = trim( preg_replace("/\.git$/", "", $item['repository'] ) ); // remove trailing ".git" in repository URL
    $architectures = $this->default_arch; // (default)
    $item['tags']  = $this->default_tags; // ['#Arduino', '#ArduinoLibs']; // (defaults)
    // populate architectures (text and tags)
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


  private function post( $item )
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
    if( !$resp || empty($resp) ) {
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


  public function getLastItems( $max_count=30 )
  {
    $args = [ 'limit' => $max_count ];
    $ret = $this->callAPI( "/api/v1/accounts/".$this->account_id."/statuses", 'GET', $args);

    if( !$ret || ! is_array($ret ) || empty($ret) ) {
      $this->logger->logf("[ERROR] Could not fetch last posts (resp=%s)", $ret);
      return false;
    }

    $postedLibraries = [];

    foreach( $ret as $id=>$post ) {
      if(!isset($post['content']) || empty($post['content']) ) {
        $this->logger->logf("[WARNING] Post #%s has no content", $post['id'] );
        continue;
      }
      // fetch library name and version
      if( preg_match("/<p>([^(]+)\(([^)]+)\)/", $post['content'], $matches ) ) {
        if( isset($matches) && count($matches)==3 && !empty($matches[0]) && !empty($matches[1]) && !empty($matches[2]) ) {
          $name    = trim($matches[1]);
          $version = trim($matches[2]);
          if( !isset( $postedLibraries[$name] ) || \Composer\Semver\Comparator::greaterThan( $version, $postedLibraries[$name] ) ) {
            $postedLibraries[$name] = $version; // store in array[name]=version
          }
        }
      }
    }

    return $postedLibraries;
  }


}

