<?php


declare(strict_types=1);

namespace CacheManager;

use JsonMachine\Items;
use JsonMachine\JsonDecoder\DecodingError;
use JsonMachine\JsonDecoder\ErrorWrappingDecoder;
use JsonMachine\JsonDecoder\ExtJsonDecoder;
use QueueManager\JSONQueue;
use LogManager\FileLogger;


class JSONCache
{
  private $cache_dir;
  private $cache_file;
  private $cache_file_old;
  private $wget_bin;
  private $gzip_bin;
  private $diff_bin;
  private $gz_url;
  private $gz_file;
  private $logger;
  public $queue;

  public function __construct($conf)
  {
    if(  !isset($conf['INDEX_CACHE_DIR'])
      || !isset($conf['INDEX_CACHE_FILE'])
      || !isset($conf['INDEX_CACHE_FILE_OLD'])
      || !isset($conf['WGET_BIN'])
      || !isset($conf['GZIP_BIN'])
      || !isset($conf['DIFF_BIN'])
      || !isset($conf['INDEX_GZ_URL'])
      || !isset($conf['FS_GZ_FILE'])
      || !isset($conf['JSON_QUEUE'])
      || !isset($conf['LOGGER'])
    ) throw new Exception('Bad configuration');;

    $this->cache_dir      = $conf['INDEX_CACHE_DIR'];
    $this->cache_file     = $conf['INDEX_CACHE_FILE'];
    $this->cache_file_old = $conf['INDEX_CACHE_FILE_OLD'];
    $this->wget_bin       = $conf['WGET_BIN'];
    $this->gzip_bin       = $conf['GZIP_BIN'];
    $this->diff_bin       = $conf['DIFF_BIN'];
    $this->gz_url         = $conf['INDEX_GZ_URL'];
    $this->gz_file        = $conf['FS_GZ_FILE'];
    $this->queue          = $conf['JSON_QUEUE']; // new JSONQueue( QUEUE_FILE );
    $this->logger         = $conf['LOGGER'];
  }


  // download remote file
  private function wget()
  {
    // TODO: stream this instead of using exec
    // TODO: don't overwrite directly, use temp file and transaction
    $ret = exec($this->wget_bin." -q ".$this->gz_url." -O ".$this->gz_file." && ".$this->gzip_bin." -d -f ".$this->gz_file);
    if( $ret===false || !file_exists($this->cache_file)) {
      return false;
    }
    return $ret;
  }


  // guess updated library names from JSON diff
  private function getLibraryNamesFromDiff( $diff )
  {
    // Regexp matches capture every "name":"blah" property values found in the diff result.
    // The pattern is perillous, it bets on the comma at the end of the pair declaration so that
    // "name":"blah" properties from [dependencies] childnode array can be safely ignored.
    if( preg_match_all('/>\s+"name": "(.*)",/', $diff, $matches ) ) {
      if( $matches[0] && $matches[1] && count($matches[0]) == count($matches[1]) ) {
        return array_unique($matches[1]);
      }
      $this->logger->log( "[WARNING] Regexp matches aren't complete: \n".print_r( $matches, 1) );
    }
    return [];
  }


  // load/download latest library index file
  public function load()
  {

    if(! is_dir( $this->cache_dir ) ) {
      mkdir( $this->cache_dir );
    }

    if(! is_dir( $this->cache_dir ) ) {
      $this->logger->log( "[ERROR] Unable to access cache dir ".$this->cache_dir );
      return false;
    }

    if(! file_exists( $this->cache_file ) ) { // first run, save a copy of the index file
      if( $this->wget() === false ) {
        $this->logger->log( "[ERROR] Library Registry Index download failed");
        return false;
      }
      $this->logger->log( "[INFO] Library Registry Index saved" );
    } else { // subsequent runs, backup the old index file and download a new copy
      rename( $this->cache_file, $this->cache_file_old );

      if( $this->wget() === false ) {
        $this->logger->log( "[WARNING] Library Registry Index download failed" );
        rename( $this->cache_file_old, $this->cache_file );
        return false;
      }
    }
    return true;
  }


  // in: array of library names
  // out: array of library properties + count
  // what: iterate library index to collect library info from provided library names
  private function process( $updatedLibraries )
  {
    // stream-open the index file for parsing
    $jsonNew = Items::fromFile( $this->cache_file, ['decoder' => new ExtJsonDecoder(true)] );
    // Populate recently updated libraries with the JSON from the index
    $notifyLibraries = $this->queue->getQueue();
    $libraries_count = 0;
    $items_count = 0;
    $last_library_name = "";

    foreach ($jsonNew as $id => $libraries) {
      if( $id === 'libraries' ) {
        foreach( $libraries as $pos => $library ) {
          if( in_array( $library["name"], $updatedLibraries ) ) {
            $notifyLibraries[$library["name"]] = $library;
          }
          if( $library["name"] != $last_library_name ) {
            $last_library_name = $library["name"];
            $libraries_count++;
          }
          $items_count++;
        }
      }
    }

    return [
      'notify'          => $notifyLibraries,
      'libraries_count' => $libraries_count,
      'items_count'     => $items_count
    ];
  }


  // what: compare old and new index file for differences
  // return: diff text or false if both files are simila
  public function changed()
  {
    // compare old and new file
    exec( $this->diff_bin." ".$this->cache_file_old." ".$this->cache_file, $diffResult );
    if( empty($diffResult) ) { // no change
      return false;
    }
    // join the diff string array into a single string, for later use with regexp
    return implode("\n", $diffResult );
  }


  // what: get new libraries since last cron run
  // return: updated libraries since last cron run
  public function getNewLibraries()
  {
    $diffResult = $this->changed();

    if( $diffResult===false ) {
      $this->logger->log( "[INFO] Library Registry Index is unchanged" );
      return false;
    }

    $updatedLibraries = $this->getLibraryNamesFromDiff( $diffResult );

    $this->logger->logf( "[INFO] Library Registry Index changed (found %d items):", count($updatedLibraries) );

    if( empty( $updatedLibraries ) ) {
      $this->logger->log( "[WARNING] No library names found in index diff" );
      $this->logger->log( "[DIFF]:\n$diffResult" );
      return false;
    }

    $report = $this->process( $updatedLibraries );

    if( empty($report['notify']) ) {
      $this->logger->logf( "[WARNING] Items found in diff are missing in index(%d items, %d libraries): %s",
        $report['items_count'],
        $report['libraries_count'],
        print_r( $updatedLibraries, 1 )
      );
      $this->queue->gcQueue();
    }

    return $report;
  }


}

