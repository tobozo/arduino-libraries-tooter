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
  private const index_base_url   = "https://downloads.arduino.cc/libraries"; // no trailing slash
  private const index_file_name  = "library_index.json"; // json document name, no gz extension

  private string $cache_file;     // latest version
  private string $cache_file_old; // backup version
  private string $cache_file_tmp; // temp version
  private string $gz_url;
  private string $gz_file;
  private FileLogger $logger;

  public function __construct( array $conf )
  {
    foreach( ['cache_dir', 'logger'] as $name ) {
      if( !isset( $conf[$name] ) )
      throw new \Exception("Missing conf[$name]");
    }

    $this->logger                = $conf['logger'];
    $this->cache_file            = $conf['cache_dir']."/".self::index_file_name;
    $this->cache_file_tmp        = $conf['cache_dir']."/".self::index_file_name.".tmp";
    $this->cache_file_old        = $conf['cache_dir']."/".self::index_file_name.".old";
    $this->gz_file               = $conf['cache_dir']."/".self::index_file_name.".gz";
    $this->gz_url                = self::index_base_url."/".self::index_file_name.".gz";

    if(! is_dir( $conf['cache_dir'] ) ) {
      mkdir( $conf['cache_dir'] );
    }

    if(! is_dir( $conf['cache_dir'] ) ) {
      throw new \Exception( "[ERROR] Unable to create cache dir ".$conf['cache_dir'] );
    }
  }


  // load/download latest library index file
  // return bool
  public function load(): bool
  {
    if(! file_exists( $this->cache_file ) || filesize($this->cache_file)==0 ) { // first run, no backup needed
      if( $this->gunzip_index($this->gz_url, $this->gz_file, $this->cache_file) === false ) {
        $this->logger->log( "[ERROR] Library Registry Index download failed");
        return false;
      }
      $this->logger->log( "[INFO] Library Registry Index saved (first run)" );
      return false;
    } else { // subsequent runs, backup the old index file and download a new copy
      rename( $this->cache_file, $this->cache_file_tmp );

      if( $this->gunzip_index($this->gz_url, $this->gz_file, $this->cache_file) === false ) {
        $this->logger->log( "[WARNING] Library Registry Index download skipped" );
        rename( $this->cache_file_tmp, $this->cache_file ); // restore backup since download failed
        return false;
      }
      rename( $this->cache_file_tmp, $this->cache_file_old ); // commit backup
    }
    return true;
  }



  // decompress $gz_file to $out_file
  // return bool
  private function gz_uncompress( string $gz_file, string $out_file ): bool
  {
    if( !file_exists( $gz_file ) ) {
      $this->logger->log( "[ERROR] File $gz_file does not exist and can't be decompressed");
      return false;
    }

    $out = fopen($out_file, "w");

    if( !$out ) {
      $this->logger->log( "[ERROR] Output file $out_file is not writable");
      return false;
    }

    $gz  = gzopen($gz_file, "r");

    if( !$gz ) {
      $this->logger->log( "[ERROR] File $gz_file is not a valid gzip file");
      return false;
    }

    while (!gzeof($gz)) {
      $buff = gzgets ($gz, 4096) ;
      fputs($out, $buff) ;
    }

    gzclose($gz) ;
    fclose($out) ;

    return true;
  }



  // gz_uncompress wrapper with extra checks
  private function gunzip( string $gz_file, string $out_file ): bool
  {
    $ret = $this->gz_uncompress( $gz_file, $out_file );
    if( $ret && file_exists($out_file) && filesize($out_file)>0 )
      return true;
    return false;
  }



  // decompress local (if http-head[status]=304) or remote file
  // return bool
  private function gunzip_index( string $gz_url, string $gz_file, string $cache_file ): bool
  {
    if( file_exists( $gz_file ) ) { // check if remote changed using file modification time
      $mod = gmdate('D, d M Y H:i:s T', filemtime( $gz_file ));
      $resp = $this->curl_http_head( $gz_url, ["If-Modified-Since: ".$mod] );
      if( $resp['status'] == 304 ) {
        // $this->logger->logf("[INFO] Remote file is unchanged (status 304, mod: %s, last_mod: %s, expires:%s), extracting from local",
        //   $mod,
        //   $resp['headers']['last-modified'][0],
        //   $resp['headers']['expires'][0]
        // );
        if( $this->gunzip( $gz_file, $cache_file ) )
          return true;
      }
    }

    $out_file = fopen($gz_file, "w");

    if( !$out_file ) {
      $this->logger->log("[ERROR] $gz_file is not writable");
      return false;
    }

    $ch = curl_init();

    curl_setopt($ch, CURLOPT_URL, $gz_url);
    curl_setopt($ch, CURLOPT_FILE, $out_file);
    curl_setopt($ch, CURLOPT_USERAGENT, 'PHP 8/Arduino-Libraries-Announcer 1.0');
    curl_setopt($ch, CURLOPT_TIMEOUT, 30); // timeout is 30 seconds, to download the large files you may need to increase the timeout limit.

    curl_exec($ch);

    $errno  = curl_errno($ch);
    $errmsg = curl_error($ch);
    $status = curl_getinfo($ch);

    curl_close($ch);
    fclose($out_file);

    if ($errno) {
      $this->logger->log("[ERROR] cURL error occured while fetching $gz_url : " . $errmsg);
    } else {
      if( $status["http_code"] == 200 ) {
        return $this->gunzip( $gz_file, $cache_file ); // $gz_file download successful, proceed with unzipping
      } else {
        $this->logger->log("[ERROR] cURL status code: " . $status["http_code"]);
      }
    }

    // $gz_file is probably corrupted, delete it
    if( file_exists( $gz_file ) ) {
      unlink( $gz_file );
    }
    // also delete the cache file
    if( file_exists( $cache_file ) ) {
      unlink( $cache_file );
    }

    return false;
  }



  // send http HEAD query, return status/headers
  // return array of status/data/headers
  private function curl_http_head( string $url, array $extra_header=[] ): array
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
    curl_setopt($ch, CURLOPT_USERAGENT, 'PHP 8/Arduino-Libraries-Announcer 1.0');
    // this function is called by curl for each header received
    curl_setopt($ch, CURLOPT_HEADERFUNCTION, function($ch, string $header) use (&$headers) {
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
  public function array_diff_by_key( array $arr1, array $arr2, string $key='version' ): array
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
  private function populateIfCompare( array $lib_obj, array &$storage, callable $comparator ): void
  {
    $name = $lib_obj['name'];
    $item = &$storage[$name];

    if( isset( $item ) ) {
      if( $comparator($lib_obj['version'], $item['version'] ) ) {
        return; // don't update storage
      }
    }

    $item['name']          = $name;
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
  private function getPrunedIndex( string $index_file_path, array $items=[] ): array
  {
    try {
      $jsonIndex = Items::fromFile( $index_file_path, ['decoder' => new ExtJsonDecoder(true)] );
    } catch ( Exception $e ) {
      $this->logger->log( "[ERROR] While decoding JSON: ".$e->getMessage() );
      exit;
    }

    foreach ($jsonIndex as $id => $libraries) {
      if( $id === 'libraries' ) {
        foreach( $libraries as $library ) {
          $this->populateIfCompare( $library, $items, "Composer\Semver\Comparator::lessThanOrEqualTo" );
        }
      }
    }
    return $items;
  }


  // get unique libraries with their highest versions for current and old indexes
  // return pruned indexes for current and old indexes
  public function getPrunedIndexes(): array
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

