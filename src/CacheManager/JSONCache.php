<?php


declare(strict_types=1);

namespace CacheManager;

use LogManager\FileLogger;
use Composer\Semver\Comparator;
use JsonMachine\Items;
use JsonMachine\JsonDecoder\DecodingError;
use JsonMachine\JsonDecoder\ErrorWrappingDecoder;
use JsonMachine\JsonDecoder\ExtJsonDecoder;


class JSONCache
{
  private string $index_base_url   = "https://downloads.arduino.cc/libraries"; // no trailing slash
  private string $index_file_name  = "library_index.json"; // json document name, no gz extension

  private string $cache_dir;
  private string $cache_file;     // latest version
  private string $cache_file_old; // backup version
  private string $cache_file_tmp; // temp version
  private string $wget_bin;
  private string $gzip_bin;
  private string $gz_url;
  private string $gz_file;
  private object $logger;

  public function __construct( array $conf )
  {
    foreach( ['cache_dir', 'wget_bin', 'gzip_bin', 'logger'] as $name ) {
      if( !isset( $conf[$name] ) )
      throw new \Exception("Missing conf[$name]");
    }

    $this->logger                = $conf['logger'];
    $this->wget_bin              = $conf['wget_bin'];
    $this->gzip_bin              = $conf['gzip_bin'];
    $this->cache_dir             = $conf['cache_dir'];
    $this->cache_file            = $this->cache_dir."/".$this->index_file_name;
    $this->cache_file_tmp        = $this->cache_dir."/".$this->index_file_name.".tmp";
    $this->cache_file_old        = $this->cache_dir."/".$this->index_file_name.".old";
    $this->gz_file               = $this->cache_dir."/".$this->index_file_name.".gz";
    $this->gz_url                = $this->index_base_url."/".$this->index_file_name.".gz";

    if(! is_dir( $this->cache_dir ) ) {
      mkdir( $this->cache_dir );
    }

    if(! is_dir( $this->cache_dir ) ) {
      throw new \Exception( "[ERROR] Unable to create cache dir ".$this->cache_dir );
    }
  }


  // load/download latest library index file
  // return bool
  public function load()
  {
    if(! file_exists( $this->cache_file ) || filesize($this->cache_file)==0 ) { // first run, no backup needed
      if( $this->wget() === false ) {
        $this->logger->log( "[ERROR] Library Registry Index download failed");
        return false;
      }
      $this->logger->log( "[INFO] Library Registry Index saved (first run)" );
      return false;
    } else { // subsequent runs, backup the old index file and download a new copy
      rename( $this->cache_file, $this->cache_file_tmp );

      if( $this->wget() === false ) {
        $this->logger->log( "[WARNING] Library Registry Index download skipped" );
        rename( $this->cache_file_tmp, $this->cache_file ); // restore backup since download failed
        return false;
      }
      rename( $this->cache_file_tmp, $this->cache_file_old ); // commit backup
    }
    return true;
  }


  // http-head remote file, and download if status !=304
  // return bool
  private function wget()
  {
    if( file_exists( $this->gz_file ) ) {
      $resp = $this->curl_http_head( $this->gz_url, ["If-Modified-Since: ".gmdate('D, d M Y H:i:s T', filemtime( $this->gz_file ))] );
      // if( isset($resp['headers']['last-modified']) && isset($resp['headers']['expires']) )
      //   $this->logger->logf("[DEBUG] Status:%s, last-modified:%s, expires:%s\n", $resp['status'], $resp['headers']['last-modified'][0], $resp['headers']['expires'][0] );
      if( $resp['status'] == 304 ) {
        $this->logger->log("[INFO] Remote file is unchanged (status 304), extracting from local");
        $ret = exec($this->gzip_bin." -k -d -f ".$this->gz_file);
        if( file_exists($this->cache_file) )
          return true;
      }
    }

    $ret = exec($this->wget_bin." -q ".$this->gz_url." -O ".$this->gz_file." && ".$this->gzip_bin." -k -d -f ".$this->gz_file);
    if( $ret===false || !file_exists($this->cache_file)) {
      return false;
    }
    return true;
  }


  // send http HEAD query, return status/headers
  // return array of status/data/headers
  private function curl_http_head( string $url, array $extra_header=[] )
  {
    $ch = curl_init();
    $headers = [];
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    if(!empty($extra_header))
      curl_setopt($ch, CURLOPT_HTTPHEADER, $extra_header );
    // This changes the request method to HEAD
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'HEAD');
    curl_setopt($ch, CURLOPT_NOBODY, true);
    // this function is called by curl for each header received
    curl_setopt($ch, CURLOPT_HEADERFUNCTION, function($curl, $header) use (&$headers) {
      $len = strlen($header);
      $header = explode(':', $header, 2);
      if (count($header) < 2) // ignore invalid headers
        return $len;
      $headers[strtolower(trim($header[0]))][] = trim($header[1]);
      return $len;
    });

    $data = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    return [
      'status'   => $status,
      'response' => $data,
      'headers'  => $headers
    ];
  }


  // compare arr1 and arr2 items by key
  // return bidirectional diff, git style
  public function array_diff_by_key( array $arr1, array $arr2, string $key='version' )
  {
    $ret = [
      '>' => [], // added
      '<' => []  // removed
    ];
    foreach( $arr1 as $name => $props ) {
      if( !isset( $arr2[$name] ) || $arr2[$name][$key]!=$props[$key]) {
        $ret['>'][$name] = $props;
      }
    }
    foreach( $arr2 as $name => $props ) {
      if( !isset( $arr1[$name] ) || $arr1[$name][$key]!=$props[$key]) {
        $ret['<'][$name] = $props;
      }
    }
    return $ret;
  }


  // find $lib_obj item in $storage collection
  // if item exists and comparator matches: keep existing item
  // else: insert or update item
  private function populateIfCompare( array $lib_obj, array &$storage, callable $comparator )
  {
    $item = &$storage[$lib_obj['name']];

    if( isset( $item ) ) {
      if( $comparator($lib_obj['version'], $item['version'] ) ) {
        return; // don't update storage
      }
    }

    $item['name']          = $lib_obj['name'];
    $item['version']       = $lib_obj['version'];
    $item['author']        = $lib_obj['author'];
    $item['repository']    = $lib_obj['repository'];
    $item['sentence']      = $lib_obj['sentence'];
    $item['architectures'] = $lib_obj['architectures'];

    if( preg_match("#^(https|git)(:\/\/|@)([^\/:]+)[\/:]([^\/:]+)\/(.+).git$#", $lib_obj["repository"], $match ) ) {
      $item[$match[3]] = [
        'user'    => $match[4],
        'repo'    => $match[5]
      ];
    }
  }


  // store libraries by names, keep highest version
  // return populated array
  private function getPrunedIndex( string $index_file_path, array $items=[] )
  {
    $jsonIndex = Items::fromFile( $index_file_path, ['decoder' => new ExtJsonDecoder(true)] );
    foreach ($jsonIndex as $id => $libraries) {
      if( $id === 'libraries' ) {
        foreach( $libraries as $pos => $library ) {
          $this->populateIfCompare( $library, $items, "Composer\Semver\Comparator::lessThanOrEqualTo" );
        }
      }
    }
    return $items;
  }


  // get unique libraries with their highest versions for current and old indexes
  // return pruned indexes for current and old indexes
  public function getPrunedIndexes()
  {
    $ret = [
      'current' => [],
      'old'     => []
    ];
    if( file_exists( $this->cache_file ) ) {
      $ret['current'] = $this->getPrunedIndex( $this->cache_file );
    }
    if( file_exists( $this->cache_file_old ) ) {
      $ret['old']     = $this->getPrunedIndex( $this->cache_file_old );
    }
    return $ret;
  }



}

