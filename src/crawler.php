<?php

declare(strict_types=1);

namespace MastodonCrawler;


require_once('config/loader.php');
require_once('LogManager/FileLogger.php');

use LogManager\FileLogger;
use \MastodonAPI;


class App
{
  private MastodonAPI $mastodon;
  private FileLogger $logger;

  public function __construct()
  {
    $this->logger   = new FileLogger( ENV_DIR );
    $this->mastodon = new MastodonAPI( MASTODON_API_CRAWLER_TOKEN, MASTODON_API_CRAWLER_URL );
  }

  public function crawl(): void
  {

    // 1) load known followers from manually saved csv file
    $csv_file = 'data/following_accounts.csv';
    $queries_file = 'cache/queries.json';

    $file_to_read = fopen( $csv_file, 'r');
    if($file_to_read === FALSE){
      throw new \Exception("can't read csv");
    }

    while(($data = fgetcsv($file_to_read, 100, ',')) !== FALSE) {
      if( strstr($data[0], '@' ) ) {
        $accounts[] = $data[0];
      }
    }
    fclose($file_to_read);



    if( file_exists($queries_file) ) {
      $queries_need_update = false;
      $json_files = json_decode( file_get_contents( $queries_file ), true );
      if( gettype($json_files)==='array' && count($json_files)>0 ) {
        foreach( $json_files as $query_cache_file ) {
          if ( time()-filemtime($query_cache_file) > 86400) {
            //echo 'Older than 24 hours';
            $queries_need_update = true;
          }
        }
      }
    } else {
      $queries_need_update = true;
      $json_files = [];
    }

    $initial_count = count( $accounts );
    echo sprintf("Loaded %d accounts\n", $initial_count );

    function buildUrl( $args )
    {
      $url = "/api/v2/search?q=".$args['q'];
      if( isset($args['type']   )) $url .= '&type='.$args['type'];
      if( isset($args['offset'] )) $url .= '&offset='.$args['offset'];
      if( isset($args['limit']  )) $url .= '&limit='.$args['limit'];
      if( isset($args['resolve'])) $url .= '&resolve='.$args['resolve'];
      return $url;
    }

    function buildFileName( $args ) {
      $path = "cache/query-".$args['q'];
      if( isset($args['type']   )) $path .= '_'.$args['type'];
      if( isset($args['offset'] )) $path .= '_'.sprintf("0x%04x", $args['offset'] );
      if( isset($args['limit']  )) $path .= '_limit_'.$args['limit'];
      if( isset($args['resolve'])) $path .= '_resolve_'.$args['resolve'];
      return $path.'.json';
    }


    //$json_files = [];
    $urlParts = parse_url( MASTODON_API_CRAWLER_URL );
    $mastodon_host = $urlParts['host'];
    $keywords = ['m5stack', 'arduino', 'esp32', 'esp8266', 'raspberrypi', 'adafruit', 'rp2040', 'nrf52'];


    foreach( $keywords as $kpos => $keyword ) {

      $args = [
        'q' => $keyword,
        'type'=>'accounts',
        'offset' => 0,
      ]; // other args: type, limit, offset, min_id, max_id, account_id


      if( $queries_need_update ) do {

        $linkRelNext = buildUrl( $args ); // "/api/v2/search?q=arduino&type=accounts&limit=40";
        $cacheName = buildFileName( $args );

        echo sprintf("[->] Calling API to %s\n", $linkRelNext );
        $ret = $this->mastodon->callAPI( $linkRelNext, 'GET', []);

        if( !$ret || isset($ret['error']) || isset($ret['curl_error']) ) {
          echo sprintf("[ERROR] (resp=%s)\n", $ret);
          return;
        }

        //
        $response_headers = $this->mastodon->response_headers;
        if( isset($response_headers['x-ratelimit-remaining'])
        && isset($response_headers['x-ratelimit-limit'])
        && isset($response_headers['x-ratelimit-reset']) ) {
          echo sprintf("[<-] X-rate: remain:%s, limit:%s, reset:%s\n",
            $response_headers['x-ratelimit-remaining'][0],
            $response_headers['x-ratelimit-limit'][0],
            $response_headers['x-ratelimit-reset'][0]
          );
        }

        if( isset($ret['accounts']) && count($ret['accounts'])>0 ) {
          $args['offset'] += count($ret['accounts']);
          $linkRelNext = buildUrl( $args );
          //$cacheName = buildFileName( $args );
          file_put_contents($cacheName, json_encode( $ret, JSON_PRETTY_PRINT ) );
          $json_files[] = $cacheName;
          sleep( 1 ); // don't tickle limit rate
        } else {
          $linkRelNext = false;
        }


      } while( $linkRelNext );


      if( count($json_files)>0 ) {

        if( $queries_need_update ) file_put_contents('cache/queries.json', json_encode( $json_files, JSON_PRETTY_PRINT ) );

        foreach( $json_files as $jpos => $json_file ) {
          $json = json_decode( file_get_contents($json_file), true );
          if( $json && isset($json['accounts']) && count($json['accounts'])>0 ) {
            foreach( $json['accounts'] as $apos => $account ) {
              if( strstr($account['acct'], '@') ) {
                $acct = $account['acct'];
              } else {
                $acct = $account['acct'].'@'.$mastodon_host;
              }

              if( $account['locked'] == true || $account['locked'] == 'true' ) continue; // don't want to be followed
              if( $account['discoverable'] == false || $account['locked'] == 'false' ) continue; // don't want to be found

              if( preg_match("/nobot|no bot/", $account['note'] ) ) continue; // doesn't want to be followed by bots, per bio
              //note

              if( $account['acct'] == 'kescher@catcatnya.com' ) continue; // crybully

              if( !in_array( $acct, $accounts ) ) {
                $accounts[] = $acct;
              }
            }
          }
        }
      }

      if( count($accounts)>0 ) {
        file_put_contents($csv_file, "Account address,Show boosts,Notify on new posts,Languages\n");
        foreach( $accounts as $account ) {
          file_put_contents($csv_file, sprintf("%s,false,false,\n", $account ), FILE_APPEND | LOCK_EX);
        }
        //echo sprintf("Saved %d accounts\n", count($accounts) );
      }

    }

    echo sprintf("Saved %d accounts (initial count=%d)\n", count($accounts), $initial_count );

  }

}


