<?php

declare(strict_types=1);

namespace ArduinoLibToot;

require_once('config/loader.php');
require_once('QueueManager/JSONQueue.php');
require_once('CacheManager/JSONCache.php');

use CacheManager\JSONCache;
use QueueManager\JSONQueue;
use \MastodonAPI;

class Manager
{
  private $cache;
  private $queue;
  private $mastodon;

  public function __construct()
  {
    $this->queue    = new JSONQueue( QUEUE_FILE );
    $this->mastodon = new MastodonAPI(MASTODON_API_KEY, MASTODON_API_URL);
    $this->cache    = new JSONCache([
      'INDEX_CACHE_DIR'      => INDEX_CACHE_DIR,
      'INDEX_CACHE_FILE'     => INDEX_CACHE_FILE,
      'INDEX_CACHE_FILE_OLD' => INDEX_CACHE_FILE_OLD,
      'WGET_BIN'             => WGET_BIN,
      'GZIP_BIN'             => GZIP_BIN,
      'DIFF_BIN'             => DIFF_BIN,
      'INDEX_GZ_URL'         => INDEX_GZ_URL,
      'FS_GZ_FILE'           => FS_GZ_FILE,
      'JSON_QUEUE'           => $this->queue
    ]);
  }


  public function manage()
  {
    if( !$this->cache->load() ) {
      exit(0);
    }

    $report = $this->cache->getNewLibraries();

    if( $report === false || empty($report['notify']) ) {
      exit(0);
    }

    echo sprintf("Cached index has %d items and %d libraries\n", $report['items_count'], $report['libraries_count'] );
    echo sprintf("%d Libraries updated in this index: %s\n", count( $report['notify'] ), implode(", ", array_keys($report['notify']) ) );

    $this->queue->saveQueue( $report['notify'] );

    // process library notification queue
    foreach( $report['notify'] as $libraryName => $notifyLibrary ) {
      // manage queue
      if( $this->publish( $notifyLibrary ) ) {
        unset($report['notify'][$libraryName]);
        // save updated queue file
        $this->queue->saveQueue( $report['notify'] );
      }
      // throttle
      sleep(1);
    }
  }


  private function publish( $notifyLibrary )
  {
    $author        = trim( preg_replace("/<(.*)>/", "", $notifyLibrary['author'] ) ); // remove email address from author name
    $repository    = trim( preg_replace("/\.git$/", "", $notifyLibrary['repository'] ) ); // remove trailing ".git" in repository URL
    $architectures = "arduino"; // (default)
    $tags          = ['#Arduino', '#ArduinoLibs']; // (defaults)
    // populate architectures (text and tags)
    if( isset($notifyLibrary['architectures']) && !empty($notifyLibrary['architectures']) ) {
      if( count( $notifyLibrary['architectures'] ) > 1 ) {
        $architectures = implode("/", $notifyLibrary['architectures'] );
        foreach( $notifyLibrary['architectures'] as $pos => $arch ) {
          $tags[] = '#'.$arch;
        }
      } else {
        if( $notifyLibrary['architectures'][0] != '*' ) {
          $architectures = $notifyLibrary['architectures'][0];
          $tags[] = '#'.$notifyLibrary['architectures'][0];
        }
      }
    }
    // populate message
    $statusText = sprintf( "%s (%s) for %s by %s\n➡️ %s\n%s\n%s ",
      $notifyLibrary['name'],
      $notifyLibrary['version'],
      $architectures,
      $author,
      $repository,
      $notifyLibrary['sentence'],
      implode(" ", array_unique($tags))
    );
    // ActivityPub status properties
    $visibility = 'private'; // Public , Unlisted, Private, and Direct (default)
    $language   = 'en';
    $status_data = [
      'status'     => $statusText,
      'visibility' => $visibility,
      'language'   => $language,
    ];
    // Publish to fediverse
    $resp = $this->mastodon->postStatus($status_data);
    // Check response
    $failed = isset($resp['ok']) && $resp['ok']===false;
    // Log results
    echo sprintf("Publishing %s (%s) / %s ... [%s]\n",
      $notifyLibrary['name'],
      $notifyLibrary['version'],
      $author,
      $failed ? 'FAILED' : 'SUCCESS'
    );
    return !$failed;
  }

}
