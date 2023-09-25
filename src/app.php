<?php

declare(strict_types=1);

namespace ArduinoLibToot;

require_once('config/loader.php');
require_once('LogManager/FileLogger.php');
require_once('QueueManager/JSONQueue.php');
require_once('CacheManager/JSONCache.php');
require_once('SocialPlatform/Mastodon/MastodonStatus.php');

use LogManager\FileLogger;
use CacheManager\JSONCache;
use QueueManager\JSONQueue;
use SocialPlatform\MastodonStatus;
use Composer\Semver\Comparator;


class Manager
{
  private object $cache;
  private object $queue;
  private object $mastodon;
  private object $logger;
  private int $max_posts_per_run = 5;

  public function __construct()
  {
    $this->logger   = new FileLogger( ENV_DIR );
    $this->queue    = new JSONQueue( INDEX_CACHE_DIR );
    $this->mastodon = new MastodonStatus(MASTODON_API_KEY, MASTODON_API_URL, MASTODON_ACCOUNT_ID);
    $this->cache    = new JSONCache([
      'cache_dir' => INDEX_CACHE_DIR,
      'wget_bin'  => WGET_BIN,
      'gzip_bin'  => GZIP_BIN,
      'logger'    => $this->logger
    ]);
    $this->mastodon->logger  = $this->logger;
  }


  public function run()
  {
    // 1) load queued libraries from local file
    $queuedLibraries = $this->queue->get();

    // 2) get mastodon last posted items
    $lastItems = $this->mastodon->getLastItems();

    if( !$lastItems || count($lastItems)==0 ) {
      $this->logger->log("[ERROR] No post history to process, skipping this run");
      // goto _processQueue;
      return;
    }

    // 3) fetch arduino registry index
    if( !$this->cache->load() ) {
      // cache load failed, no need to compare indexes, only process queue
      goto _processQueue;
      return;
    }

    // 4) compute diff between current and old indexes
    $pruned = $this->cache->getPrunedIndexes();

    // 5) if $pruned has new stuff, merge it in $queuedLibraries and save queue
    if( count($pruned['current'])>0 && count($pruned['old'])>0 ) {
      $diff = $this->cache->array_diff_by_key($pruned['current'], $pruned['old'], 'version');
      if( count($diff['>'])>0 ) {
        foreach( $diff['>'] as $name => $props ) {
          $queuedLibraries[$name] = $props;
        }
        $this->queue->save( $queuedLibraries );
      }
    }


    _processQueue:

    if( count( $queuedLibraries ) == 0 ) {
      $this->logger->log("[INFO] Library Registry Index is unchanged");
      return;
    }

    // process notification queue
    foreach( $queuedLibraries as $name => $item ) {
      // handle queue item
      if( in_array( $name, array_keys($lastItems) ) && $item['version']==$lastItems[$name] ) {
        // duplicate post, skip !
        $this->logger->logf("[WARNING] Duplicate post for %s (%s), skipping", $item['name'], $item['version'] );
        unset($queuedLibraries[$name]);
        $this->queue->save( $queuedLibraries );
        continue;
      }
      // $item = $this->mastodon->processItem( $item );
      // echo sprintf("[DEBUG][SHOULD NOTIFY] %s => %s\n%s\n", $name, print_r( $item, true ), $this->mastodon->format( $item ) );
      // unset($queuedLibraries[$name]);
      // // save updated queue file
      // $this->queue->save( $queuedLibraries );
      if( $this->mastodon->publish( $item ) ) {
        unset($queuedLibraries[$name]);
        // save updated queue file
        $this->queue->save( $queuedLibraries );
        // avoid spam
        if( $this->max_posts_per_run--<=0 ) return;
      }
      sleep(1); // throttle
    }

  }
}

