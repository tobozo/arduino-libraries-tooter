<?php

declare(strict_types=1);

namespace SocialPlatform;

use \MastodonAPI;


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


  public function publish( $item )
  {
    $item = $this->processItem( $item );
    return $this->post( $item );
  }


  public function getLastItems( $max_count )
  {
    $args = [
      'limit' => $max_count
    ];

    $ret = $this->callAPI( "/api/v1/accounts/".$this->account_id."/statuses", 'GET', $args);

    if( !$ret || empty($ret) ) {
      $this->logger->log("[ERROR] Could not fetch last posts, this is needed to prevent duplicate posts");
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
          // echo sprintf("[INFO] History: %s (%s)\n", trim($matches[1]), trim($matches[2]) );
          $postedLibraries[trim($matches[1])] = trim($matches[2]); // store in array[name]=version
        } else {
          if(!isset($matches) ) {
            $this->logger->logf("[WARNING] Bad regexp match: %s", print_r($matches, 1) );
          } else {
            $this->logger->logf("[WARNING] No match for regexp in content: %s", $post['content'] );
          }
        }
      } else {
        $this->logger->logf("[WARNING] Ignored post: %s\n", $post['content']); // requires ['card']['type']=='link'
      }
    }

    return $postedLibraries;
  }


  private function processItem( $item )
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

    if( !$resp || empty($resp) ) { // API call failed, something wrong, result should be JSON object or array
      $this->logger->logf("[ERROR] (bad response) for %s (%s)\n", $item['name'], $item['version'] );
      return false;
    }

    if( isset( $resp['curl_error'] ) ) { // got a curl error
      $this->logger->logf("[ERROR] (curl error) for %s (%s).\nError code:%s\nError: %s\n", $item['name'], $item['version'], $resp ['curl_error_code'], $resp ['curl_error'] );
      return false;
    }

    if( isset( $resp['error'] ) ) { // got an {"error":"blah"} message in Mastodon's JSON Response
      $this->logger->logf("[ERROR] (application error) for %s (%s)\nJSON Error: %s", $item['name'], $item['version'], $resp ['error'] );
    }
    // Success
    $this->logger->logf("[SUCCESS] Published %s (%s) / %s as %s\n", $item['name'], $item['version'], $item['author'], isset( $resp['id'] ) ? $resp['id'] : 'no-ID' );
    return true;
  }


  private function format( $item )
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



}





