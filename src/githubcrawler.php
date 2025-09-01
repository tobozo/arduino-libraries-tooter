<?php

declare(strict_types=1);

namespace GithubCrawler;

require_once('config/loader.php');
require_once('LogManager/FileLogger.php');
require_once("SocialPlatform/common.php");
require_once('SocialPlatform/Github/github.php'); // compensate for composer fuckery
require_once('SocialPlatform/BlueSky/bsky.php');
require_once('SocialPlatform/Mastodon/MastodonStatus.php');
require_once('CacheManager/JSONCache.php'); // compensate for composer fuckery

use \SocialPlatform\GithubInfoFetcher;
use LogManager\FileLogger;
use CacheManager\JSONCache;
use JsonMachine\Items;
use JsonMachine\JsonDecoder\DecodingError;
use JsonMachine\JsonDecoder\ErrorWrappingDecoder;
use JsonMachine\JsonDecoder\ExtJsonDecoder;


class App
{
    //private MastodonAPI $mastodon;
    private FileLogger $logger;
    private String $gcachedir;
    private JSONCache $cache;
    private $bsky;
    private $bsky_followed_by_me = [];
    private $instancesocialapi;
    private $fedi_hosts = [];

    public function __construct()
    {
        $this->logger    = new FileLogger( ENV_DIR );
        $this->gcachedir = INDEX_CACHE_DIR . '/rel';
        $this->cache    = new JSONCache([
            'cache_dir' => INDEX_CACHE_DIR,
            'logger'    => $this->logger
        ]);

        if(!is_dir($this->gcachedir))
            mkdir($this->gcachedir, 0777, true) or php_die("FATAL: Unable to create ".$this->gcachedir.PHP_EOL);

        $this->instancesocialapi = new \SocialPlatform\MastodonInstance(['token' => INSTANCE_SOCIAL_TOKEN]);

        $instances = $this->instancesocialapi->getInstances();

        foreach($instances['instances'] as $instance)
        {
            $this->fedi_hosts[] = $instance['name'];
        }


        $this->bsky = new \SocialPlatform\BlueskyProfile(BSKY_API_APP_USER, BSKY_API_APP_TOKEN);
        $this->bsky_followed_by_me = $this->bsky->getFollowedByMe();

        // app.bsky.graph.getFollows?actor=did:plc:3ya6fekbfwfvs6bx4zio5p2k&limit=30


        //$this->mastodon = new MastodonAPI( MASTODON_API_CRAWLER_TOKEN, MASTODON_API_CRAWLER_URL );
        //$this->mastodon->logger = $this->logger;
    }

    public function parseHeaders( $headers )
    {
        if( !is_array($headers) ) return [];
        $head = array();
        foreach( $headers as $k=>$v ) {
            $t = explode( ':', $v, 2 );
            if( isset( $t[1] ) )
                $head[ trim($t[0]) ] = trim( $t[1] );
            else {
                $head[] = $v;
                if( preg_match( "#HTTP/[0-9\.]+\s+([0-9]+)#",$v, $out ) )
                    $head['reponse_code'] = intval($out[1]);
            }
        }
        return $head;
    }


    public function crawl(): void
    {
        $index = $this->cache->getPrunedIndex($this->cache->cache_file);
        $authors = [];
        $socialdomains = [];
        $max_age = 86400 * 30; // 1 month
        global $http_response_header;

        foreach($index as $pos => $item) {
            //print_r($item);exit;
            if( isset($item['github.com']) && isset($item['github.com']['user']) ) {
                $user = $item['github.com']['user'];
                if(!isset($authors[$user])) $authors[$user] = [$item['repository']];
                else $authors[$user][] = $item['repository'];
                //print( $item['github.com']['user']."\n");
            }

        }

        printf("Authors: %d\n", count($authors));

        foreach( $authors as $author => $repositories ) {
            $authorCachePath = $this->gcachedir.'/'.$author.'.json';
            $url = sprintf("https://github.com/%s", $author);
            $found = false;
            $delay = 2;

            if(file_exists($authorCachePath) && filemtime($authorCachePath)+$max_age>time() ) {
                $info_card = json_decode(file_get_contents($authorCachePath), true);
                $delay = 0;
            } else {
                $info_card = GithubInfoFetcher::getCardInfo( $url, false );

                $headers = get_headers($url);
                $responseCode = substr($headers[0], 9, 3);

                if( $responseCode == 404 ) {

                    print('?');

                    @file_get_contents($repositories[0]);
                    $headers = $this->parseHeaders($http_response_header);

                    if( !isset( $headers['reponse_code']))
                        die("network error");

                    if( $headers[0] == 'HTTP/1.1 301 Moved Permanently') {
                        // project url is redirected, get profile URL
                        if( preg_match("#^(https|git)(:\/\/|@)([^\/:]+)[\/:]([^\/:]+)\/(.+)$#", $headers['Location'], $match ) ) {
                            print('[');
                            if(!empty($match[3]) && !empty($match[4]) && $match[4]!=$author ) {
                                printf("%s -> %s", $author, $match[4]);
                                $info_card = GithubInfoFetcher::getCardInfo( "https://".$match[3]."/".$match[4], false );
                            }
                            print(']');
                        } else {
                            print('#');
                        }
                    } else {
                        echo "unknown response code : $responseCode, halting".PHP_EOL;
                        print_r($headers); exit;
                    }

                    if( empty($info_card) ) {
                        touch($authorCachePath);
                        print('!');
                        continue;
                    }
                }

                if( !empty($info_card) )
                    file_put_contents($authorCachePath, json_encode($info_card, JSON_PRETTY_PRINT));
            }

            if( !empty($info_card['og_me'])) {
                unset($info_card['topics']);
                unset($info_card['og_image']);
                foreach($info_card['og_me'] as $meURL) {
                    $urlParts = parse_url($meURL);

                    if( empty($urlParts['host']) )
                        goto _next;
                    if( empty($urlParts['path']) )
                        goto _next;


                            //                 if(  )
                            // echo $urlParts['host']." was identified as fediverse URL but is NOT in instances list".PHP_EOL;

                    if( $urlParts['host']=='bsky.app' || substr_count($urlParts['path'], '@') > 0 ) {
                        printf("\n***Social account***: %s\n", $meURL );
                        $found = true;
                        if( !isset($socialdomains[$urlParts['host']]) ) {
                            $socialdomains[$urlParts['host']] = [$meURL];
                        } else {
                            $socialdomains[$urlParts['host']][] = $meURL;
                        }
                    }

/*                    if( !empty($urlParts['host']) && !empty($urlParts['path']) && substr_count($urlParts['path'], '@') > 0 ) {
                        printf("\n***Social account***: %s\n", $meURL );
                        $found = true;
                        $urlParts = parse_url($meURL);
                        if( isset($urlParts['host']) ) {
                            if( !isset($socialdomains[$urlParts['host']]) ) {
                                $socialdomains[$urlParts['host']] = [$meURL];
                            } else {
                                $socialdomains[$urlParts['host']][] = $meURL;
                            }
                        }
                    } else {
                        printf("\n***INCOMPLETE Social account***: %s\n", $meURL );
                    }*/
                }
            }

            if(!$found )
                print('.');

            _next:

            sleep($delay);
        }

        $filterdomains = [
          'youtube.com',
          'm.youtube.com',
          'threads.net',
          'medium.com',
          'matrix.to',
          'tiktok.com',
          'twitch.tv',
          'twitter.com',
          'x.com',
          'linkedin.com',
          'printables.com',
        ];

        $fediverseAccounts = []; // resolved fedi accounts
        $blueskyAccounts = [];   // unresolved bsky accounts

        // TODO: load followed accounts

        echo "\n";

        foreach( $socialdomains as $domain => $meURLs ) {
            printf("%s => %d elements\n", $domain, count($meURLs) );
            foreach( $meURLs as $meURL ) {
                $urlParts = parse_url($meURL);

                if( $urlParts['host']=='bsky.app' ) {

                    $pathParts = explode('/', $urlParts['path']);
                    $bskyHandle = end($pathParts);

                    $followed_by_me = false;

                    foreach($this->bsky_followed_by_me as $bskyUser)
                    {
                        if( $bskyUser['handle'] == $bskyHandle )
                        {
                            $followed_by_me = true;
                            break;
                        }
                    }

                    if(!$followed_by_me)
                    {
                        $res = $this->bsky->request('GET', 'com.atproto.identity.resolveHandle?handle='.$bskyHandle, []);

                        if(isset($res['did']))
                        {

                            $blueskyAccounts[] = $bskyHandle;

                            $args = [
                                'collection' => 'app.bsky.graph.follow',
                                'repo' => $this->bsky->getAccountDid(),
                                'record' => [
                                    'subject'   => $res['did'],
                                    'createdAt' => date('c'),
                                    '$type'     => 'app.bsky.graph.follow',
                                ],
                            ];

                            $res = $this->bsky->request('POST', 'com.atproto.repo.createRecord', $args);

                            echo "$bskyHandle is now followed by me".PHP_EOL;
                        }
                        else
                        {
                            echo "$bskyHandle cannot be resolved".PHP_EOL;
                        }
                    }
                    else
                    {
                        echo "$bskyHandle is already followed by me".PHP_EOL;
                    }

                } else {

                    $isFediverse = true;

                    foreach($filterdomains as $filterdomain) {
                        if( $filterdomain == $urlParts['host'] || 'www.'.$filterdomain == $urlParts['host'] )
                            $isFediverse = false;
                    }

                    if( $isFediverse ) {
                        $urlParts['path'] = substr($urlParts['path'], 1);
                        $fediverseAccounts[] = $urlParts['path']."@".$urlParts['host'];
                        printf("  - %s\n", $meURL);
                        //print_r( $urlParts ); exit;
                        if( !in_array( $urlParts['host'], $this->fedi_hosts ) )
                            echo $urlParts['host']." was identified as maybe-fediverse instance but is NOT in instances list".PHP_EOL;
                    }
                }
            }
        }

        print( implode(",false,false,\n", $fediverseAccounts ). "\n" );


    }
}
