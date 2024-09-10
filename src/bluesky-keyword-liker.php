<?php

declare(strict_types=1);

namespace BlueskyKeywordLiker;

require_once('config/loader.php');
require_once('LogManager/FileLogger.php');
require_once('QueueManager/JSONQueue.php');
require_once('CacheManager/JSONCache.php');
require_once("SocialPlatform/common.php");
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
use \DateTime;


class App
{
    private $cache_dir = 'cache/search';
    private $api = NULL;
    private $keyword = NULL;

    private $followers_file; // = $this->cache_dir.'/bluesky.followers.json';


    public function __construct()
    {
        $this->api = new \SocialPlatform\BlueskyApi(BSKY_API_APP_USER, BSKY_API_APP_TOKEN);
        if( ! $this->api->getAccountDid() ) php_die('Unable to get account id'.PHP_EOL);
        if( ! is_dir($this->cache_dir)) mkdir($this->cache_dir) or php_die('Unable to create cache dir'.PHP_EOL);
        $this->followers_file = $this->cache_dir.'/bluesky.followers.json';
    }


    public function likePost( $post )
    {
        if( $this->keyword == NULL )
            php_die("Cache needs a keyword, search first!".PHP_EOL);

        $liked_by_me_filename = $this->cache_dir.'/'.$post['cid'].'.json';
        $needs_like_filename  = $this->cache_dir.'/needs-like-'.$post['cid'].'.json';

        if( file_exists($liked_by_me_filename) )
        {
            @unlink($needs_like_filename);
            return ['validationStatus' => 'alreadyLiked'];
        }

        $record = [
            'subject'   => [
                'uri' => $post['uri'],
                'cid' => $post['cid']
            ],
            'createdAt' => date("c"),
            '$type'     => 'app.bsky.feed.like'
        ];

        $args = [
            'collection' => 'app.bsky.feed.like',
            'repo'       => $this->api->getAccountDid(),
            'record'     => $record
        ];

        $res = $this->api->request('POST', 'com.atproto.repo.createRecord', $args );

        if( !$res || isset( $res['curl_error_code'] ) )
        {
            print_r($res);
            php_die("... Like failed:".PHP_EOL);
        }

        if( $res['validationStatus'] == 'valid' )
        {
            rename($needs_like_filename, $liked_by_me_filename) or php_die("Unable to commit like in cache");
        }
        return $res;

    }



    public function getFollowers()
    {
        if( file_exists($this->followers_file) && filemtime($this->followers_file)+86400 > time() )
        {
            return json_decode(file_get_contents($this->followers_file), true);
        }
        $followers = $this->fetchFollowers();
        file_put_contents($this->followers_file, json_encode($followers, JSON_PRETTY_PRINT) ) or php_die("Unable to save followers".PHP_EOL);
        return $followers;
    }




    private function fetchFollowers()
    {
        $cursor = null;
        $followers = [];

        $dids = [];

        for(;;)
        {
            $resp = $this->api->request('GET', 'app.bsky.graph.getFollowers', ['actor'=>$this->api->getAccountDid(), 'limit'=>100, 'cursor' => $cursor]);

            if( empty($resp['followers'])  )
            {
                echo "No more results".PHP_EOL;
                break;
            }

            $added = 0;

            foreach( $resp['followers'] as $follower )
            {
                if(!isset($dids[$follower['did']]))
                {
                    $followers[] = $follower;
                    $added++;
                }
                $dids[$follower['did']] = true;
            }

            echo sprintf("Added %d/%d followers, cursor: %s", $added, count($resp['followers']), $cursor?$cursor:'initial').PHP_EOL;


            if(!isset($resp['cursor']) )
            {
                echo "No more cursor".PHP_EOL;
                break;
            }

            if( $cursor == $resp['cursor'] )
            {
                echo "No new cursor".PHP_EOL;
                break;
            }

            $cursor = $resp['cursor'];

            sleep(1);
        }

        return $followers;
    }


    public function getNotifiedDID( $max=100 )
    {
        return [
          $this->api->getAccountDid(),
        ];
    }


    public function getIgnoredHandles()
    {
        $followers = $this->getFollowers();
        $ret = [
            'arduinolibs.bsky.social',
            'savingsdeal.bsky.social'
        ];
        foreach($followers as $follower)
        {
            $ret[] = $follower['handle'];
        }
        return array_unique($ret);
    }



    public function saveNotifiedActors( $actors )
    {

    }

    public function wasNotified()
    {
        return false;
    }


    public function search( $keywords )
    {
        if( empty( $keywords ) )
            php_die("Nothing to do".PHP_EOL);

        $res = [];

        foreach($keywords as $keyword)
        {
            $res[$keyword] = $this->searchKeyword($keyword);
        }
        return $res;
    }



    public function searchKeyword( $keyword )
    {
        $this->keyword = $keyword;

        $posts = $this->api->request('GET', 'app.bsky.feed.searchPosts', ['q'=>$keyword, 'sort'=>'latest', 'limit'=>100]);

        if( !$posts || isset( $posts['curl_error_code'] ) || !isset($posts['posts']) )
        {
            print_r($posts);
            php_die("... Search failed:".PHP_EOL);
        }

        // for debug
        file_put_contents( $this->cache_dir.'/'.$keyword.'.json', json_encode( $posts, JSON_PRETTY_PRINT ) ) or php_die("Unable to save JSON".PHP_EOL);

        echo sprintf("Search for '%s' returned %d results".PHP_EOL, $keyword, count($posts['posts']) );

        $posts_to_like = [];


        //$notifiedDID = $this->getNotifiedDID();
        $ignoredHandles = $this->getIgnoredHandles();

        foreach( $posts['posts'] as $num => $post )
        {
            if( in_array($post['author']['handle'], $ignoredHandles) )
            {
                // echo "Ignoring post from ".$post['author']['handle'].PHP_EOL;
                continue; // ignore posts by me or blacklisted accounts
            }

            $needs_like = true;
            $liked_by_me_filename = $this->cache_dir.'/'.$post['cid'].'.json';
            $needs_like_filename  = $this->cache_dir.'/needs-like-'.$post['cid'].'.json';
            $likes = [];
            $uriParts = explode('/', $post['uri']);
            $url = sprintf("https://bsky.app/profile/%s/post/%s", $post['author']['handle'], end($uriParts) );

            if( file_exists($liked_by_me_filename) ) {
                //echo "[#$num] This cached post was already liked: ".$url.PHP_EOL;
                continue;
            }

            if( file_exists($needs_like_filename) ) {
                echo sprintf("[#$num] This cached post has %d likes but wasn't liked by me: %s".PHP_EOL, $post['likeCount'], $url);
                $posts_to_like[] = $post;
                continue;
            }

            if( $post['likeCount'] > 0 )
            {
                sleep(3);

                $likes = $this->api->request('GET', 'app.bsky.feed.getLikes', [ 'uri'=>$post['uri'], 'cid'=>$post['cid'] ]);

                if( !$likes || isset( $likes['curl_error_code'] ) || !isset($likes['likes']) )
                {
                    print_r($likes);
                    php_die("... Likers enumeration failed:".PHP_EOL);
                }

                foreach($likes['likes'] as $like)
                {
                    if( $like['actor']['did'] == $this->api->getAccountDid() )
                    {
                        $needs_like = false;
                        break;
                    }
                }
            }

            if( $needs_like )
            {
                echo sprintf("[#$num] This post has %d likes but wasn't liked by me: %s".PHP_EOL, $post['likeCount'], $url);
                file_put_contents( $needs_like_filename, json_encode( $likes, JSON_PRETTY_PRINT ) ) or php_die("Unable to save likes cache".PHP_EOL);
                $posts_to_like[] = $post;
                $ignoredHandles[] = $post['author']['handle'];
            }
            else
            {
                echo sprintf("[#$num] This post has %d likes and was liked by me: %s".PHP_EOL, $post['likeCount'], $url);
                file_put_contents( $liked_by_me_filename, json_encode( $likes, JSON_PRETTY_PRINT ) ) or php_die("Unable to save likes cache".PHP_EOL);
            }


        }

        echo sprintf("Agent found %d posts to like out of %d".PHP_EOL, count($posts_to_like), count($posts['posts']) );

        // for debug
        file_put_contents($this->cache_dir.'/like-queue.json', json_encode($posts_to_like, JSON_PRETTY_PRINT) ) or php_die("Unable to save likes queue");

        return $posts_to_like;
    }

};
