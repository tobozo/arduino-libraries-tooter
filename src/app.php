<?php

declare(strict_types=1);

namespace ArduinoLibToot;

require_once('config/loader.php');
require_once('LogManager/FileLogger.php');
require_once('QueueManager/JSONQueue.php');
require_once('CacheManager/JSONCache.php');
require_once('SocialPlatform/Mastodon/MastodonStatus.php');

use CacheManager\JSONCache;
use QueueManager\JSONQueue;
use SocialPlatform\MastodonStatus;
use LogManager\FileLogger;

class Manager
{
  private $cache;
  private $queue;
  private $mastodon;
  private $bsky;
  private $logger;

  public function __construct()
  {
    $this->logger   = new FileLogger( LOG_FILE_NAME );
    $this->queue    = new JSONQueue( QUEUE_FILE );
    $this->mastodon = new MastodonStatus(MASTODON_API_KEY, MASTODON_API_URL);
    $this->cache    = new JSONCache([
      'INDEX_CACHE_DIR'      => INDEX_CACHE_DIR,
      'INDEX_CACHE_FILE'     => INDEX_CACHE_FILE,
      'INDEX_CACHE_FILE_OLD' => INDEX_CACHE_FILE_OLD,
      'WGET_BIN'             => WGET_BIN,
      'GZIP_BIN'             => GZIP_BIN,
      'DIFF_BIN'             => DIFF_BIN,
      'INDEX_GZ_URL'         => INDEX_GZ_URL,
      'FS_GZ_FILE'           => FS_GZ_FILE,
      'JSON_QUEUE'           => $this->queue,
      'LOGGER'               => $this->logger
    ]);
    $this->mastodon->logger  = $this->logger;
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

    $this->logger->logf("Cached index has %d items and %d libraries\n", $report['items_count'], $report['libraries_count'] );
    $this->logger->logf("%d Libraries updated in this index: %s\n", count( $report['notify'] ), implode(", ", array_keys($report['notify']) ) );

    $this->queue->saveQueue( $report['notify'] );

    // process library notification queue
    foreach( $report['notify'] as $libraryName => $notifyLibrary ) {
      // manage queue
      if( $this->mastodon->publish( $notifyLibrary ) ) {
        unset($report['notify'][$libraryName]);
        // save updated queue file
        $this->queue->saveQueue( $report['notify'] );
      }
      // throttle
      sleep(1);
    }
  }

}
