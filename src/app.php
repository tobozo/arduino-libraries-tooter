<?php

declare(strict_types=1);

namespace ArduinoLibToot;

require_once('config/loader.php');
require_once('LogManager/FileLogger.php');
require_once('QueueManager/JSONQueue.php');
require_once('CacheManager/JSONCache.php');
require_once('SocialPlatform/Github/github.php');
require_once('SocialPlatform/Mastodon/MastodonStatus.php');
require_once('SocialPlatform/BlueSky/bsky.php');

use LogManager\FileLogger;
use CacheManager\JSONCache;
//use QueueManager\JSONQueue;
use SocialPlatform\MastodonStatus;
use SocialPlatform\BlueSkyStatus;
use SocialPlatform\GithubInfoFetcher;
use Composer\Semver\Comparator;


class App
{
  private JSONCache $cache;
  //private JSONQueue $queue;
  private MastodonStatus $mastodon;
  private BlueSkyStatus $bluesky;
  private FileLogger $logger;
  private int $max_posts_per_run = 2;

  public function __construct()
  {
    $this->logger   = new FileLogger( ENV_DIR );
    //$this->queue    = new JSONQueue( INDEX_CACHE_DIR, "queue.json" );
    $this->mastodon = new MastodonStatus([
      'token'        => MASTODON_API_APP_TOKEN,
      'instance_url' => MASTODON_API_APP_URL,
      'logger'       => $this->logger
    ]);
    $this->cache    = new JSONCache([
      'cache_dir' => INDEX_CACHE_DIR,
      'logger'    => $this->logger
    ]);
    $this->mastodon->logger = $this->logger;

    $this->bluesky = new BlueSkyStatus( $_ENV['BSKY_API_APP_USER'], $_ENV['BSKY_API_APP_TOKEN'] );

  }


  public function run(): void
  {
    // load queued libraries from local file
    // TODO: 24h Cooldown for every $item['name']
    $queuedLibraries = $this->mastodon->queue->get();

    // get mastodon last posted items
    $lastItems = $this->mastodon->getLastItems();

    if( !$lastItems || count($lastItems)==0 ) {
      $this->logger->log("[WARNING] No post history to process (first run?)");
      $lastItems = [];
      return;
    }

    if( count($queuedLibraries)>0 ) { // queue not empty, skip registry update
      goto _processQueue;
    }

    // fetch arduino registry index
    if( !$this->cache->load() ) {
      // cache load failed, no need to compare indexes, only process queue
      goto _processQueue;
      return;
    }

    // compute diff between current and old indexes
    $pruned = $this->cache->getPrunedIndexes();

    // if $pruned has new stuff, merge it in $queuedLibraries and save queue
    if( count($pruned['current'])>0 && count($pruned['old'])>0 ) {
      $diff = $this->cache->array_diff_by_key($pruned['current'], $pruned['old'], 'version');
      if( count($diff['>'])>0 ) {
        foreach( $diff['>'] as $name => $props ) {
          $queuedLibraries[$name] = $props;
        }
        $this->mastodon->queue->save( $queuedLibraries );
      }
    }


    _processQueue:

    if( count( $queuedLibraries ) == 0 ) {
      //$this->logger->log("[INFO] Library Registry Index is unchanged");
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
      // $this->mastodon->queue->save( $queuedLibraries );
      if( $this->mastodon->publish( $item ) ) {
        unset($queuedLibraries[$name]);
        // save updated queue file
        $this->mastodon->queue->save( $queuedLibraries );
        // now that duplicate post is prevented, cross post to other networks
        $this->bluesky->publish( $this->mastodon->formatted_item );
      }
      exit; // avoid spam
    }

  }
}

