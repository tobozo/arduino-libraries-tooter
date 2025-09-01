<?php

declare(strict_types=1);

namespace BlueskyAnalytics;

require_once('config/loader.php');
require_once('LogManager/FileLogger.php');
require_once('QueueManager/JSONQueue.php');
require_once('CacheManager/JSONCache.php');
require_once("SocialPlatform/common.php");
require_once('SocialPlatform/Github/github.php');
require_once('SocialPlatform/Mastodon/MastodonStatus.php');
require_once('SocialPlatform/BlueSky/bsky.php');
require_once('SocialPlatform/BlueSky/analytics.php');


class App
{
    private $me;
    private $stats; // new SocialPlatform\BlueskyStats($env BSKY_API_APP_USER / BSKY_API_APP_TOKEN
    private $posts; //  $stats->getPostsFromMe();
    private $notifications; // $stats->getNotifications();
    private $profiles; // $stats->getProfiles();

    private $notifs_count = []; // array_keys($notifications);
    private $notifs_by_day = [];
    private $notifs_by_user = [];

    private $posts_by_cid = [];
    private $items_by_date = [];

    public function __construct()
    {
        $this->stats = new \SocialPlatform\BlueskyStats(BSKY_API_APP_USER, BSKY_API_APP_TOKEN);
        $this->me = $this->stats->fetchProfile(['handle' => $this->stats->api->getAccountDid() ]);
    }

    public function run()
    {
        //$this->posts = $this->stats->getPostsFromMe();
        $this->posts = $this->stats->updatePostsFromMe();
        $this->notifications = $this->stats->getNotifications();
        //$records = $stats->getRecords();
        $this->profiles = $this->stats->getProfiles();

        $this->notifs_count = array_keys($this->notifications);
        $this->notifs_by_day = [];
        $this->notifs_by_user = [];

        foreach($this->notifs_count as $idx => $key)
        {
            $this->notifs_count[$key] = count($this->notifications[$key]);
            $this->notifs_by_day[$key] = [];
            $this->notifs_by_user[$key] = [];
            unset($this->notifs_count[$idx]);
        }

        print_r([
            'posts'    => count($this->posts),
            //'records'  => count($records),
            'notifs'   => $this->notifs_count,
            'profiles' => count($this->profiles),

        ]);

        // storage for report data (posts and notifications)
        // by { day of year, month of year, hour of day, day of week, url }
        $bydoy = $bymoy = $byhod = $bydow = $byurl =
        [
            'post'    => [],
            'follow'  => [],
            'quote'   => [],
            'reply'   => [],
            'repost'  => [],
            'mention' => [],
            'like'    => []
        ];


        foreach( $this->posts as $post )
        {
            // extract project URL from $post['text']
            preg_match_all('#\bhttps?://[^,\s()<>]+(?:\([\w\d]+\)|([^,[:punct:]\s]|/))#', $post['text'], $matches);
            if(!isset($matches[0][0]))
                continue; // not a library announcement
            $url = $matches[0][0];
            $date_ary = date_parse($post['createdAt']);
            $doy_sig = sprintf("%04d-%02d-%02d", $date_ary['year'], $date_ary['month'], $date_ary['day'] );
            $moy_sig = sprintf("%02d", $date_ary['month']);
            $hod_sig = sprintf("%02d", $date_ary['hour']);
            $dow_sig = date("w", strtotime($post['createdAt']));
            $byurl['post'][$url][]     = $post; // increase posts by project
            $bydoy['post'][$doy_sig][] = $post; // increase posts by day of year
            $bymoy['post'][$moy_sig][] = $post; // increase posts by month of year
            $byhod['post'][$hod_sig][] = $post; // increase posts by hour of day
            $bydow['post'][$dow_sig][] = $post; // increase posts by day of week
        }

        // notifications to parse
        $reasons = [
            'follow'  => [],
            'quote'   => [],
            'reply'   => [],
            'repost'  => [],
            'mention' => [],
            'like'    => []
        ];

        foreach(array_keys($reasons) as $reason)
        {
          if(!isset($this->notifications[$reason]))
          {
              echo "No $reason in notifications".PHP_EOL;
              continue;
          }

          foreach($this->notifications[$reason] as $notification)
          {
              $reach = 0;
              if( isset( $notification['author']['handle'] ) && isset( $this->profiles[ $notification['author']['handle'] ] ) )
              {
                  $reach = $this->profiles[ $notification['author']['handle'] ]['followersCount'];
              }

              $date_ary = date_parse($notification['record']['createdAt']);
              $doy_sig = sprintf("%04d-%02d-%02d", $date_ary['year'], $date_ary['month'], $date_ary['day'] );
              $moy_sig = sprintf("%02d", $date_ary['month']);
              $hod_sig = sprintf("%02d", $date_ary['hour']);
              $dow_sig = date("w", strtotime($notification['record']['createdAt']));
              $bydoy[$reason][$doy_sig][] = $reach; // increase posts by day of year
              $bymoy[$reason][$moy_sig][] = $reach; // increase posts by month of year
              $byhod[$reason][$hod_sig][] = $reach; // increase posts by hour of day
              $bydow[$reason][$dow_sig][] = $reach; // increase posts by day of week

          }
        }

        $this->genReportByDOW($bydow);
        $this->genReportByDOY($bydoy);
        $this->printReportByURL($byurl);
    }


    public function genReportByDOY($bydoy)
    {
        $data = $this->getYTDListByDOY( $bydoy );
        echo "YTD List has ".count($data['entries'])." entries".PHP_EOL;
        $firstEntry = current($data['entries']);
        $lastEntry  = end($data['entries']);

        // gnuplot args for this report
        //$output_dir = 'cache/bluesky'; // output dir for csv and png graph file
        $csv_file = 'cache/bluesky/ytd-by-day-of-year.csv'; // csv data storage file for gnuplot
        // store data in csv file
        $this->stats->arrayToCsv($data, $csv_file);

        // generate "Followers Growth" graph
        $plot_title_fmt = "ArduinoLibs Bluesky YTD(%s): Followers Growth"; // graph title (populated with date)
        $png_file = 'cache/bluesky/'.date('Y-m-d').'.followers.png'; // output graph file generated by gnuplot
        $plot_file = 'data/followers.gnuplot'; // plot file for gnuplot
        // gnuplot the csv file
        $plotArgs = [
          'plot_title' => sprintf($plot_title_fmt, date("Y-m-d") ),
          'plot_file'  => $plot_file,
          'csv_file'   => $csv_file,
          'png_file'   => $png_file,
          'width'      => 640,
          'height'     => 400,
          'xrange_start' => $firstEntry[0],
          'xrange_end'   => $lastEntry[0]
        ];

        if( defined("GNUPLOT_CLI") )
          $plotArgs['gnuplot_path'] = GNUPLOT_CLI;

        $this->stats->plotStats($plotArgs);
    }


    public function genReportByDOW($bydow)
    {
        $data = ['columns' => [ 'dayOfWeek', 'hourOfDay', 'posts' ], 'entries' => [] ];
        $matrix = [];
        $max_count = 0;
        $min_count = PHP_INT_MAX;

        for($dow=0;$dow<7;$dow++)
        {
            $matrix[$dow] = [];
            for($hod=0;$hod<24;$hod++)
                $matrix[$dow][$hod] = 0;
        }

        foreach($bydow['post'] as $dow => $posts_of_the_day)
        {
            // usort($posts_of_the_day, fn($a, $b) => (date_parse($a['createdAt']) < date_parse($b['createdAt'])));
            foreach($posts_of_the_day as $post)
            {
                $date_ary = date_parse($post['createdAt']);
                //$hod_sig = sprintf("%02d", $date_ary['hour']);

                $matrix[$dow][$date_ary['hour']]++;
                //$data['entries'][] = [ $dow, $hod_sig ];
            }
        }

        for($dow=0;$dow<7;$dow++)
        {
            for($hod=0;$hod<24;$hod++)
            {
                if( $matrix[$dow][$hod] < $min_count )
                    $min_count = $matrix[$dow][$hod];
                if( $matrix[$dow][$hod] > $max_count )
                    $max_count = $matrix[$dow][$hod];
                $data['entries'][] = [ $dow, $hod, $matrix[$dow][$hod] ];
            }
        }


        $csv_file = 'cache/bluesky/ytd-by-dow.csv'; // csv data storage file for gnuplot
        // store data in csv file
        // $data['entries'] = array_reverse($data['entries']);
        $this->stats->arrayToCsv($data, $csv_file);

        // generate "Followers Growth" graph
        $plot_title_fmt = "ArduinoLibs Bluesky YTD(%s): Posting frequency"; // graph title (populated with date)
        $png_file = 'cache/bluesky/'.date('Y-m-d').'.dow-vs-hod.png'; // output graph file generated by gnuplot
        $plot_file = 'data/dow-vs-hod.gnuplot'; // plot file for gnuplot
        // gnuplot the csv file



        $plotArgs = [
          'plot_title' => sprintf($plot_title_fmt, date("Y-m-d") ),
          'plot_file'  => $plot_file,
          'csv_file'   => $csv_file,
          'png_file'   => $png_file,
          'min_count'  => 0,
          'max_count'  => $max_count
        ];

        if( defined("GNUPLOT_CLI") )
          $plotArgs['gnuplot_path'] = GNUPLOT_CLI;

        $this->stats->plotStats($plotArgs);

    }


    public function getYTDListByDOY( $bydoy )
    {
        // yup, PHP allows this too :)
        function getEntriesCount($bydoy, $reason, $date_sig)
        {
            return isset($bydoy[$reason][$date_sig]) ? count($bydoy[$reason][$date_sig]) : 0;
        }

        $leap_day = date('L');
        $oneYearAgo = $leap_day==0 ? strtotime('-1 year') : strtotime('-1 year +1 day');
        $year_length = 365 + $leap_day;

        $entries = []; // returned dataset

        $followers = 0; // total follow notifications (may differ from total followers reported by profile)
        $followers_avg = 0;
        $avg_size = 0;

        for( $then=$oneYearAgo, $d=0; $d<$year_length; $d++ )
        {
            $date_sig = gmdate("Y-m-d", $then);
            // based on FILTERED notifications (excludes muted/blocked accounts)
            $follows_today  = getEntriesCount($bydoy, 'follow',  $date_sig);
            //$growth_today = ( $followers > 0 ) ? sprintf("%0.2f", ($follows_today/$followers)*100.0) : 0;
            $followers += $follows_today;

            if( $followers > 0 )
            {
                $avg_size++;
                $followers_avg += $followers/$avg_size;
            }
            // based on FILTERED notifications (excludes muted/blocked accounts)
            $mentions_today = getEntriesCount($bydoy, 'mention', $date_sig);

            // based on posts history (from:me)
            $posts_today    = getEntriesCount($bydoy, 'post',    $date_sig);
            $replies_today = $reposts_today =  $likes_today =  $quotes_today = 0;

            if( isset($bydoy['post'][$date_sig] ) )
            {
              foreach( $bydoy['post'][$date_sig] as $num => $post )
              {
                  $replies_today += $post['replyCount'];
                  $reposts_today += $post['repostCount'];
                  $likes_today   += $post['likeCount'];
                  $quotes_today  += $post['quoteCount'];
              }
            }

            $entries[] = [$date_sig, $follows_today, $followers, $followers_avg, $posts_today, $quotes_today, $replies_today, $reposts_today, $mentions_today, $likes_today, 0, 0, 0];
            $then += 86400;
        }

        // adjust followers count to compensate for missing notifications after account filters are applied
        $followers_offset = $this->me['followersCount'] - $followers;
        foreach($entries as $num => $entry)
        {
            $entries[$num][2] += $followers_offset; // adjust followers count

            if($num>=1) { // daily growth
                $followers_yesterday = $entries[$num-1][2];
                $follows_today = $entries[$num][2]-$followers_yesterday;
                $growth = ( $followers_yesterday > 0 ) ? ($follows_today/$followers_yesterday)*100.0 : 0;
                $growth = ($entries[$num-1][10] + $growth) / 2.0;  // avg
                $entries[$num][10] = sprintf("%0.2f", $growth);
            }
            if($num>=7) { // weekly growth
                $followers_lastweek = $entries[$num-7][2];
                $follows_thisweek = $entries[$num][2]-$followers_lastweek;
                $entries[$num][11] = ( $followers_lastweek > 0 ) ? sprintf("%0.2f", ($follows_thisweek/$followers_lastweek)*100.0) : 0;
            }
            if($num>=30) { // monthly growth
                $followers_lastmonth = $entries[$num-30][2];
                $follows_thismonth = $entries[$num][2]-$followers_lastmonth;
                $entries[$num][12] = ( $followers_lastmonth > 0 ) ? sprintf("%0.2f", ($follows_thismonth/$followers_lastmonth)*100.0) : 0;
            }
        }

        echo "Total follows (notifications): $followers, Followers Count (profile): ".$this->me['followersCount'].", Offset: $followers_offset".PHP_EOL;

        $columns = ['date', 'follows', 'followers', 'follower (avg)', 'posts', 'quoteCount', 'replyCount', 'repostCount', 'mentions', 'likeCount', 'growth_daily', 'growth_weekly', 'growth_monthly'];

        return ['columns' => $columns, 'entries' => $entries];
    }



    public function printReportByURL($byurl)
    {
        $report_by_url = [];
        $report_by_user = [];


        foreach( $byurl['post'] as $url => $posts )
        {
            $replyCount = $repostCount = $likeCount = $quoteCount = 0;
            foreach($posts as $post)
            {
                $replyCount  += $post['replyCount'];
                $repostCount += $post['repostCount'];
                $likeCount   += $post['likeCount'];
                $quoteCount  += $post['quoteCount'];
            }

            $report_by_url[$url] = [
                'postCount'   => count($posts),
                'replyCount'  => $replyCount,
                'repostCount' => $repostCount,
                'likeCount'   => $likeCount,
                'quoteCount'  => $quoteCount,
                'url'         => $url
            ];

            if(preg_match_all("@^https?://([^/]+)/([^/]+)/(.*)$@", $url, $matches))
            {
                if( count($matches) == 4 && !empty($matches[2][0]) && !empty($matches[3][0]) )
                {
                    $domain   = $matches[1][0];
                    $username = $matches[2][0];
                    $project  = $matches[3][0];
                    if(!isset($report_by_user[$username]))
                      $report_by_user[$username] = ['username' => $username, 'domain' => $domain, 'projects' => [$project => count($posts)], 'count' => count($posts)];
                    else
                    {
                      $report_by_user[$username]['count'] += count($posts);
                      $report_by_user[$username]['projects'][$project] = count($posts);
                    }
                }
            }
        }

        $this->genReportByUser($report_by_user); // plot scattered data

        $by_url_report_size = [
            'postCount'   => 20,
            'replyCount'  => 5,
            'repostCount' => 10,
            'likeCount'   => 20,
            'quoteCount'  => 5
        ];

        foreach( $by_url_report_size as $indexname => $max_items)
        {
            $dataset = $report_by_url; // better work with a copy of the dataset as it'll be modified by usort()
            usort($dataset, fn($a, $b) => ($a[$indexname] < $b[$indexname]));
            $count = 0;
            foreach($dataset as $url => $data)
            {
              echo sprintf("%-12s: %4d\tURL: %s".PHP_EOL, ucfirst($indexname), $data[$indexname], $data['url']);
              if(++$count == $max_items) break;
            }
        }
    }



    public function genReportByUser($report_by_user)
    {
        usort($report_by_user, fn($a, $b) => ($a['count'] < $b['count']));

        $data = ['columns' => [ 'releasesCount', 'projectsCount', 'username' ], 'entries' => [] ];
        $count = 0;
        foreach($report_by_user as $user)
        {
            $avg = $user['count']/count($user['projects']);
            if( ++$count<50)
            {
                echo sprintf("User %-42s owns %3d libraries totalling %3d releases and averaging %4.1f releases per library".PHP_EOL, $user['username'], count($user['projects']), $user['count'], $avg);
                //print_r($user);
                //break;
            }
            $data['entries'][] = [ $user['count'], count($user['projects']), $user['username'] ];
        }


        $csv_file = 'cache/bluesky/ytd-by-user.csv'; // csv data storage file for gnuplot
        // store data in csv file
        $data['entries'] = array_reverse($data['entries']);
        $this->stats->arrayToCsv($data, $csv_file);

        // generate "Followers Growth" graph
        $plot_title_fmt = "ArduinoLibs Bluesky YTD(%s): Scattered libs vs releases"; // graph title (populated with date)
        $png_file = 'cache/bluesky/'.date('Y-m-d').'.libs.scattered.png'; // output graph file generated by gnuplot
        $plot_file = 'data/lib.scattered.gnuplot'; // plot file for gnuplot
        // gnuplot the csv file

        $plotArgs = [
            'plot_title' => sprintf($plot_title_fmt, date("Y-m-d") ),
            'plot_file'  => $plot_file,
            'csv_file'   => $csv_file,
            'png_file'   => $png_file
        ];

        if( defined("GNUPLOT_CLI") )
          $plotArgs['gnuplot_path'] = GNUPLOT_CLI;

        $this->stats->plotStats($plotArgs);

    }



};



// // consolidate with notification data
// foreach(['follow', 'like', 'quote', 'reply', 'repost', 'mention'] as $notif_key)
// {
//     if( !array_key_exists($notif_key, $notifications))
//         continue;
//     $items = $notifications[$notif_key];
//     foreach($items as $item)
//     {
//         switch($notif_key)
//         {
//             case 'like'   :
//             case 'repost' :
//                 if( isset($item['record']['subject']['cid']) )
//                     $cid = $item['record']['subject']['cid'];
//                 else
//                     continue 2; // malformed
//             break;
//
//             case 'quote'  :
//                 if(isset($item['record']['embed']['record']['cid']))
//                     $cid = $item['record']['embed']['record']['cid'];
//                 else if(isset($item['record']['embed']['record']['record']['cid']))
//                     $cid = $item['record']['embed']['record']['record']['cid'];
//                 else
//                     continue 2; // quote of a quote of a quote ?
//             break;
//
//             case 'reply'  :
//             case 'mention':
//                 if(isset($item['record']['reply']['root']['cid']))
//                     $cid = $item['record']['reply']['root']['cid'];
//                 else
//                     continue 2; // not directly attached to a tracked post
//             break;
//             case 'follow' :
//                 $follow_date = $item['record']['createdAt'];
//                 $date_ary = date_parse($follow_date);
//                 $date_sig = sprintf("%04d-%02d-%02d", $date_ary['year'], $date_ary['month'], $date_ary['day'] );
//                 if(!isset($notifs_by_day['follow'][$date_sig]))
//                     $notifs_by_day['follow'][$date_sig] = [];
//                 $notifs_by_day['follow'][$date_sig][] = $item['author']['handle'];
//                 continue 2;
//             break;
//             default       :
//                 php_die("WTF".PHP_EOL);
//         }
//
//         if(!array_key_exists($cid, $posts_by_cid )) // not related to a tracked post
//             continue;
//
//         $profile = $stats->hydrateAuthor($item['author']);
//
//         $post_date = $posts_by_cid[$cid]['createdAt'];
//         $date_ary = date_parse($post_date);
//         $date_sig = sprintf("%04d-%02d-%02d", $date_ary['year'], $date_ary['month'], $date_ary['day'] );
//
//         if( array_key_exists('followersCount', $profile) )
//             $items_by_date[$date_sig]['reach'] += $profile['followersCount'];
//
//         $items_by_date[$date_sig]['rank']++;
//
//         $notifs_by_day[$notif_key][$date_sig][] = $item;
//
//         if(!isset($notifs_by_user[$notif_key][$profile['handle']]))
//             $notifs_by_user[$notif_key][$profile['handle']] = 0;
//
//         $notifs_by_user[$notif_key][$profile['handle']]++;
//     }
// }
//
//
// // evaluate followers count
// $items = $items_by_date;
// usort($items_by_date, fn($a, $b) => (date_parse($a['created_at']) > date_parse($b['created_at'])));
//
// $followers = 0;
// $followers_avg = 0;
// $avg_size = 0;
//
// $reach_total = 0;
// $reach_days  = 0;
//
// // print_r($notifs_by_day['follow']);
//
// foreach($items_by_date as $idx => $post)
// {
//     $date_sig = $post['created_at'];
//     $follows = 0;
//     if( isset($notifs_by_day['follow'][$date_sig]) )
//     {
//         $follows = count($notifs_by_day['follow'][$date_sig]);
//         $followers += $follows;
//         //echo "$follows follows on $date_sig (total=$followers, last avg=$followers_avg) (idx $idx)".PHP_EOL;
//     }
//
//     $reach_days++;
//     $reach_total += $items_by_date[$idx]['reach'];
//     $reach_avg = $reach_total/$reach_days;
//     $items_by_date[$idx]['reach_avg']     = $reach_avg;
//
//     if( $followers > 0 )
//     {
//         $avg_size++;
//         $followers_avg += $followers/$avg_size;
//     }
//     $items_by_date[$idx]['follows']       = $follows;
//     $items_by_date[$idx]['followers']     = $followers;
//     $items_by_date[$idx]['followers_avg'] = $followers_avg;
// }
//
//
// // compute rank
// usort($items_by_date, fn($a, $b) => ($a['rank'] < $b['rank']));
// $rank = 1;
// foreach($items_by_date as $num => $toot)
// {
//     $items_by_date[$num]['rank'] = $rank;
//     $rank++;
// }
//
// $stats->genCSVStats($items_by_date);
// $stats->plotStats();
//
//
//
// echo PHP_EOL."SaintObjets with most reached accounts (REACH=rebloggers' followers):".PHP_EOL;
//
// usort($items_by_date, fn($a, $b) => ($a['reach'] < $b['reach']));
// $count = 0;
// foreach($items_by_date as $cid => $post)
// {
//     echo sprintf("[REACH:%5d] (RT:%2d, RE:%2d, FAV:%2d) [%s] %s".PHP_EOL, $post['reach'], $post['reblogs_count'], $post['replies_count'], $post['favourites_count'], $post['created_at'], $post['object'] );
//     if(++$count>=10)
//         break;
// }
//
//
//
// echo PHP_EOL."SaintObjets with most interactions (RANK=RT+RE+FAV):".PHP_EOL;
//
// usort($items_by_date, fn($a, $b) => ($a['rank'] > $b['rank']));
// $count = 0;
// foreach($items_by_date as $cid => $post)
// {
//     echo sprintf("[RANK: %d] (RT:%2d, RE:%2d, FAV:%2d) [%s] %s".PHP_EOL, $post['rank'], $post['reblogs_count'], $post['replies_count'], $post['favourites_count'], $post['created_at'], $post['object'] );
//     if(++$count>=10)
//         break;
// }
