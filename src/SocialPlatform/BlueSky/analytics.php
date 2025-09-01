<?php
declare(strict_types=1);

namespace SocialPlatform;

require_once("bsky.php");


class BlueskyStats
{
    public $api;
    private $account;

    private $cache_dir = 'cache/bluesky';
    private $cache_notifs_dir;
    private $cache_posts_dir;
    private $cache_profiles_dir;

    private $posts_history_json;
    private $notifs_history_json;
    //private $records_history_json;
    private $profiles_history_json;

    private int $profile_expire_after = 86400*30;
    private int $posts_expire_after   = 86400*7;
    private int $notifs_expire_after  = 86400;
    private int $records_expire_after = 86400;

    private $stats_file_csv;

    private $posts = [];

    private $max = 10;

    private $posts_per_day = [];


    public function __construct($user, $token)
    {
        $this->api = new \SocialPlatform\BlueskyApi($user, $token, $this->cache_dir);
        $this->cache_notifs_dir      = $this->cache_dir.'/notifications';
        $this->cache_posts_dir       = $this->cache_dir.'/posts';
        $this->cache_profiles_dir    = $this->cache_dir.'/profiles';

        $this->posts_history_json    = $this->cache_posts_dir.'/posts.json';
        //$this->records_history_json  = $this->cache_posts_dir.'/records.json';
        $this->notifs_history_json   = $this->cache_notifs_dir.'/notifs.json';
        $this->profiles_history_json = $this->cache_profiles_dir.'/profiles.json';

        $this->stats_file_csv        = $this->cache_dir.'/stats.csv';

        foreach([$this->cache_dir, $this->cache_notifs_dir, $this->cache_posts_dir, $this->cache_profiles_dir] as $dir ) {
            if(!is_dir($dir)) {
                mkdir($dir, 0777, true) or php_die("Unable to create cache dir $dir".PHP_EOL);
            }
        }

        if( ! $this->api->getAccountDid() )
            php_die('Unable to get account id'.PHP_EOL);
    }


    public function arrayRemoveDuplicates( array $items, string $key )
    {
        $keys = [];
        $items_unique = [];

        foreach($items as $num => $item)
        {
            if(!isset($keys[$item[$key]]))
                $items_unique[] = $item;
            else
                php_logln("Duplicate key $key : $item[$key]");
            $keys[$item[$key]] = true;
        }
        return $items_unique;
    }



    public function fetchProfile( $author_ary )
    {
        $res = $this->api->request('GET', 'app.bsky.actor.getProfile', ['actor' => $author_ary['handle']]);
        if( !$res || isset( $res['curl_error_code'] ) || !isset($res['handle']) )
        {
            print_r($res);
            php_die("... Failed to fetch profile for ".$author_ary['handle'].PHP_EOL);
        }
        echo sprintf("Fetched profile %s".PHP_EOL, isset($res['handle'])?$res['handle']:$author_ary['handle']);
        //echo "Fetched profile for ".$author_ary['handle'].':'.$res['handle'].PHP_EOL;
        return $res;
    }



    public function hydrateAuthor( $author_ary )
    {
        $author_dir = $this->cache_profiles_dir.'/'.substr($author_ary['handle'], 0, 1);
        $author_filename = $author_dir.'/'.$author_ary['handle'].'.json';
        if(! file_exists($author_filename) || filemtime($author_filename)+($this->profile_expire_after) < time() )
        {
            if(!is_dir($author_dir))
                mkdir($author_dir, 0777, true) or php_die("Unable to create profile dir $author_dir".PHP_EOL);
            $profile = $this->fetchProfile($author_ary);
            $this->saveJSON($author_filename, $profile) or php_die("Unable to save profile file $author_filename".PHP_EOL);
        }
        else
            $profile = $this->loadJSON($author_filename);

        if(empty($profile))
            php_die("Invalid profile file $author_filename".PHP_EOL);
        return $profile;
    }


    public function getProfiles()
    {
        $cached_profiles = $this->loadJSON($this->profiles_history_json);
        $profiles = [];

        if(!empty($cached_profiles))
            echo "Loading ".count($cached_profiles)." cached profiles".PHP_EOL;

        $cached_notifs = $this->loadJSON($this->notifs_history_json);

        if(!empty($cached_notifs))
        {
            $total_notifs = 0;
            foreach($cached_notifs as $reason => $notifs)
                $total_notifs += count($notifs);
            echo "Extracting profiles from $total_notifs notifications".PHP_EOL;
        }

        foreach($cached_notifs as $reason => $notifs)
        {
            foreach( $notifs as $num => $notif )
            {
                $handle = $notif['author']['handle'];

                if( $handle == 'handle.invalid' )
                {
                    unset( $notifs[$num] );
                    continue;
                }
                $profiles[$handle] = $this->hydrateAuthor($notif['author']);
            }
        }

        echo "Got ".count($profiles)." profiles".PHP_EOL;

        $this->saveJSON($this->profiles_history_json, $profiles) or php_die("Unable to save profiles to $this->profiles_history_json".PHP_EOL);

        return $profiles;
    }




    public function getPostsFromMe()
    {
        if( file_exists($this->posts_history_json) && filemtime($this->posts_history_json)+$this->posts_expire_after > time() )
        {
            return $this->loadJSON($this->posts_history_json);
        }
        $posts = $this->fetchPostsFromMe();
        $this->saveJSON($this->posts_history_json, $posts ) or php_die("Unable to save posts".PHP_EOL);
        return $posts;
    }


    public function updatePostsFromMe()
    {
        $cached_posts = $this->loadJSON($this->posts_history_json);

        if( empty($cached_posts) )
            return $this->getPostsFromMe(); // fetch everything

        $since = $cached_posts[0]['createdAt'];
        $posts = $this->fetchPostsFromMe( $since );
        echo "Got ".count($cached_posts)." cached posts, and fetched ".count($posts)." additional posts".PHP_EOL;
        array_push($posts, ...$cached_posts);
        echo "Merged, now totalling ".count($posts)."  posts".PHP_EOL;
        $posts_nodupes = $this->arrayRemoveDuplicates($posts, 'cid');
        echo sprintf("Dirty: %d items, de-duped: %d items".PHP_EOL, count($posts), count($posts_nodupes));
        $this->saveJSON($this->posts_history_json, $posts_nodupes ) or php_die("Unable to save posts".PHP_EOL);
        return $posts_nodupes;
    }


    public function fetchPostsFromMe( $since = null )
    {
        echo "Fetching Posts 'from:me'".PHP_EOL;

        $cursor = null;
        $args = [
            'q'      => 'from:me',
            'sort'   => 'latest',
            'limit'  => 100
        ];

        if($since !== null)
           $args['since'] = $since;

        $posts = [];

        for(;;)
        {
            $args['cursor'] = $cursor;
            $resp = $this->api->request('GET', 'app.bsky.feed.searchPosts', $args );

            if( !$resp || isset( $resp['curl_error_code'] ) || !isset($resp['posts']) )
            {
                print_r($resp);
                php_die("... Search failed:".PHP_EOL);
            }

            if( empty($resp['posts']) )
            {
                echo "No more results".PHP_EOL;
                break;
            }

            $added = 0;

            foreach( $resp['posts'] as $item )
            {
                $record = $item['record'];

                $post = [
                    'text'        => $record['text'],
                    'createdAt'   => $record['createdAt'],
                    'cid'         => $item['cid'],
                    'uri'         => $item['uri'],
                    'replyCount'  => $item['replyCount'],
                    'repostCount' => $item['repostCount'],
                    'likeCount'   => $item['likeCount'],
                    'quoteCount'  => $item['quoteCount'],
                ];

                $date_ary = date_parse($post['createdAt']);

                $date_path = sprintf("%04d/%02d/%02d",
                    $date_ary['year'],
                    $date_ary['month'],
                    $date_ary['day']
                );

                $date_dir = $this->cache_posts_dir.'/'.$date_path;

                if( !is_dir($date_dir) )
                    mkdir($date_dir, 0777, true) or php_die("Unable to create dir $date_dir".PHP_EOL);

                $post_filename = sprintf("%s/%02dh%02dm%02ds.json",
                    $date_dir,
                    $date_ary['hour'],
                    $date_ary['minute'],
                    $date_ary['second']
                );

                $this->saveJSON($post_filename, $post) or php_die("Unable to save post".PHP_EOL);

                if( $since===null || ($since!==null && $post['createdAt'] != $since) )
                {
                  $posts[] = $post;
                  $added++;
                }
            }

            //echo sprintf("Added %d/%d items, cursor: %s", $added, count($resp['posts']), $cursor?$cursor:'initial').PHP_EOL;

            if(!isset($resp['cursor']) )
            {
                echo "No more cursor".PHP_EOL;
                break;
            }

            echo sprintf("+%d=%d, cursor: %s -> %s", count($resp['posts']), count($posts), $cursor?$cursor:'initial', $resp['cursor']).PHP_EOL;

            if( $cursor === $resp['cursor'] )
            {
                echo "No new cursor".PHP_EOL;
                break;
            }

            $cursor = $resp['cursor'];
        }

        return $posts;

    }



    public function getNotifications()
    {
        if( file_exists($this->notifs_history_json) && filemtime($this->notifs_history_json)+$this->notifs_expire_after > time() )
        {
            $notifications = $this->loadJSON($this->notifs_history_json);

            $notifications_unique = [];

            foreach($notifications as $reason => $notifs)
            {
                $notifications_unique[$reason] = $this->arrayRemoveDuplicates($notifs, 'cid');
            }
        }
        else
        {
          $notifications = $this->fetchNotifications();
          $this->saveJSON($this->notifs_history_json, $notifications) or php_die("Unable to save notifications".PHP_EOL);
        }

        return $notifications;
    }



    public function fetchNotifications( $since = null )
    {
        $cursor = null;
        $zero_count = ['count'=>0];

        $args = [
            'limit'  => 100,
            'cursor' => $cursor
        ];

        if($since !== null)
        {
           $args['since'] = $since;
           echo "Since : $since".PHP_EOL;
        }

        $notifications = [
            'follow'  => [],
            'like'    => [],
            'quote'   => [],
            'reply'   => [],
            'repost'  => [],
            'mention' => []
        ];

        echo "Fetching notifications".PHP_EOL;

        for(;;)
        {
            $args['cursor'] = $cursor;
            $resp = $this->api->request('GET', 'app.bsky.notification.listNotifications', $args);

            if( empty($resp['notifications']) )
            {
                echo "No more results".PHP_EOL;
                break;
            }

            $added = 0;

            foreach( $resp['notifications'] as $notification )
            {
                $date_ary = date_parse($notification['indexedAt']);

                $date_path = sprintf("%04d/%02d/%02d/%s",
                    $date_ary['year'],
                    $date_ary['month'],
                    $date_ary['day'],
                    $notification['reason']
                );

                $date_dir = $this->cache_notifs_dir.'/'.$date_path;

                if( !is_dir($date_dir) )
                    mkdir($date_dir, 0777, true) or php_die("Unable to create dir $date_dir".PHP_EOL);

                $notif_filename = sprintf("%s/%02dh%02dm%02ds.json",
                    $date_dir,
                    $date_ary['hour'],
                    $date_ary['minute'],
                    $date_ary['second']
                );

                $this->saveJSON($notif_filename, $notification) or php_die("Unable to save notification".PHP_EOL);

                $notifications[$notification['reason']][] = $notification;
                $added++;
            }

            //echo sprintf("Added %d/%d items, cursor: %s", $added, count($resp['notifications']), $cursor?$cursor:'initial').PHP_EOL;

            if(!isset($resp['cursor']) )
            {
                echo "No more cursor".PHP_EOL;
                break;
            }

            echo sprintf("+%d=%d, cursor: %s -> %s", count($resp['notifications']), $added, $cursor?$cursor:'initial', $resp['cursor']).PHP_EOL;

            if( $cursor == $resp['cursor'] )
            {
                echo "No new cursor".PHP_EOL;
                break;
            }

            $cursor = $resp['cursor'];
        }

        return $notifications;
    }



    // public function getRecords()
    // {
    //     if( file_exists($this->records_history_json) && filemtime($this->records_history_json)+$this->records_expire_after > time() )
    //     {
    //         return $this->loadJSON($this->records_history_json);
    //     }
    //     $records = $this->listRecords();
    //     file_put_contents($this->records_history_json, json_encode($records) ) or php_die("Unable to save records".PHP_EOL);
    //     return $records;
    // }
    //
    // private function listRecords()
    // {
    //     $cursor = null;
    //     $posts = [];
    //
    //     echo "Fetching records".PHP_EOL;
    //
    //     for(;;)
    //     {
    //         $resp = $this->api->request('GET', 'com.atproto.repo.listRecords', [
    //             'repo' => $this->api->getSession()['handle'],
    //             'collection' => 'app.bsky.feed.post',
    //             //'limit'=>100,
    //             'cursor' => $cursor
    //         ]);
    //
    //         if( empty($resp['records']) )
    //         {
    //             echo "No more results".PHP_EOL;
    //             break;
    //         }
    //
    //         $added = 0;
    //
    //         foreach( $resp['records'] as $record )
    //         {
    //             $post = $record['value'];
    //             $post['cid'] = $record['cid'];
    //             $post['uri'] = $record['uri'];
    //             unset($post['type']);
    //
    //             $date_ary = date_parse($post['createdAt']);
    //             $date_path = sprintf("%04d/%02d/%02d", // /,
    //                 $date_ary['year'],
    //                 $date_ary['month'],
    //                 $date_ary['day']
    //             );
    //
    //             $date_dir = $this->cache_posts_dir.'/'.$date_path;
    //
    //             if( !is_dir($date_dir) )
    //                 mkdir($date_dir, 0777, true) or php_die("Unable to create dir $date_dir".PHP_EOL);
    //
    //             $record_filename = sprintf("%s/record-%02dh%02dm%02ds.json",
    //                 $date_dir,
    //                 $date_ary['hour'],
    //                 $date_ary['minute'],
    //                 $date_ary['second']
    //             );
    //
    //             $this->saveJSON($record_filename, $record) or php_die("Unable to save post".PHP_EOL);
    //
    //             $posts[] = $post;
    //             $added++;
    //         }
    //
    //         echo sprintf("Added %d/%d posts, cursor: %s, last date: %s", $added, count($resp['records']), $cursor?$cursor:'initial', $date_path).PHP_EOL;
    //
    //
    //         if(!isset($resp['cursor']) )
    //         {
    //             echo "No more cursor".PHP_EOL;
    //             break;
    //         }
    //
    //         if( $cursor == $resp['cursor'] )
    //         {
    //             echo "No new cursor".PHP_EOL;
    //             break;
    //         }
    //
    //         $cursor = $resp['cursor'];
    //     }
    //
    //     return $posts;
    // }


    public function arrayToCsv($array/* [ columns => [], entries => [] ] */, $savepath)
    {
        if( empty($array ) )
            php_die("arrayToCsv: nothing to do".PHP_EOL);
        if(!isset($array['columns']) || empty($array['columns']))
            php_die("arrayToCsv: no columns".PHP_EOL);
        if(!isset($array['entries']) || empty($array['entries']))
            php_die("arrayToCsv: no entries".PHP_EOL);

        $columns_count = count($array['columns']);

        // save stats as csv
        $fp = fopen($savepath, 'w');
        // populate first row with column names
        fputcsv($fp, $array['columns'] );
        foreach($array['entries'] as $linenum => $entry)
        {
            $items_count = count($entry);
            if( $columns_count != $items_count )
                echo sprintf("[WARNING] Possible malformed data at line %d (has %d columns, expects %d)".PHP_EOL, $linenum, $items_count, $columns_count);
            fputcsv($fp, $entry );
        }
        fclose($fp);
    }



    public function plotStats( array $args )
    {
        if(!isset($args['plot_title']) || empty($args['plot_title']))
            die("[FATAL] missing arg: plot_title".PHP_EOL);

        if(!isset($args['csv_file']) || empty($args['csv_file']))
            die("[FATAL] missing arg: csv_file".PHP_EOL);
        if(!file_exists($args['csv_file']))
            die("[FATAL] plotStats: invalid csv file: ".$args['csv_file'].PHP_EOL);

        if(!isset($args['plot_file']) || empty($args['plot_file']))
            die("[FATAL] missing arg: plot_file".PHP_EOL);
        if(!file_exists($args['plot_file']))
            die("[FATAL] gnuplot: invalid plot file: ".$args['plot_file'].PHP_EOL);

        if(!isset($args['png_file']) || empty($args['png_file']))
            die("[FATAL] missing arg: png_file".PHP_EOL);
        $png_dir = dirname($args['png_file']);
        if(!is_dir($png_dir))
            mkdir( $png_dir, 0777, true ) or die("[FATAL] Unable to create png_dir: $png_dir".PHP_EOL);
        if(!is_writable($png_dir))
            die("[FATAL] png_dir exists but is not writable: $png_dir".PHP_EOL);

        if(!isset($args['width']) || empty($args['width']))
            $args['width'] = 1200;
        if(!filter_var($args['width'], FILTER_VALIDATE_INT))
            die("[FATAL] bad arg value for width: ".$args['width'].PHP_EOL);
        if(!isset($args['height']) || empty($args['height']))
            $args['height'] = 748;
        if(!filter_var($args['height'], FILTER_VALIDATE_INT))
            die("[FATAL] bad arg value for height: ".$args['height'].PHP_EOL);

        // TODO: implement better validation/filtering
        $gnuplot_args = "";
        foreach($args as $name => $value)
        {
            // gnuplot arg names may contain ::alnum:: and underscores but can't start with a number
            if(!preg_match('/^[a-zA-Z_]{1}[a-zA-Z0-9_]+$/', (string)$name))
                die("[FATAL] Invalid gnuplot arg name: $name".PHP_EOL);
            // gnuplot arg values may not contain quotes and backticks
            if(preg_match('/["\'`]/', (string)$value))
                die("[FATAL] gnuplot arg['$name'] should not use quotes: $value".PHP_EOL);
            // gnuplot -e "foo='bar'; baz='wat';" --> declarations are semicolon separated, value is single quoted
            $gnuplot_args .= sprintf("%s='%s'; ", $name, $value);
        }

        if( isset($args['gnuplot_path']) )
            $gnuplot = $args['gnuplot_path'];
        else
        {
          exec('which gnuplot', $out);

          if(empty($out) || empty($out[0]))
              die ("[FATAL] gnuplot is not installed!".PHP_EOL);
          $gnuplot = $out[0];
        }

        if(!file_exists($gnuplot))
            die ("[FATAL] gnuplot not reachable:  ".$gnuplot.PHP_EOL);

        $format = "%s -e \"%s\" %s"; // gnuplot -e  "csv_file='mydata.csv'; foo='bar'; baz='wat';" myplotfile.plot

        $gnuplotCmd = sprintf($format, $gnuplot, $gnuplot_args, $args['plot_file']);

        // echo "Gnuplot cmd: $gnuplotCmd".PHP_EOL;

        exec($gnuplotCmd);
    }



    private function saveJSON($path, $arr)
    {
        // TODO: check if is_writable( dirname($path) );
        return file_put_contents($path, json_encode($arr, JSON_PRETTY_PRINT));
    }


    private function loadJSON($path)
    {
        if(!file_exists($path))
            return [];

        return json_decode(file_get_contents($path), true);
    }


};

