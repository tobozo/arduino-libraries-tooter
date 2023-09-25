<?php

// declare(strict_types=1);
//
// namespace SocialPlatform;
//
//
// class BlueSky
// {
//
//
//   // WARNING: this is a text-only implementation, posting links is more complex, see:
//   // - https://atproto.com/blog/create-post
//   // - https://packagist.org/packages/cjrasmussen/bluesky-api
//   // - https://github.com/cjrasmussen/BlueskyApi
//
//
//   public function publish( $text )
//   {
//
//
//     $config['bluesky-username']="your.username";
//     $config['bluesky-password']="your-app-password";
//     // $text="This is a test post";
//
//     $curl = curl_init();
//
//     curl_setopt_array($curl, array(
//       CURLOPT_URL => 'https://bsky.social/xrpc/com.atproto.server.createSession',
//       CURLOPT_RETURNTRANSFER => true,
//       CURLOPT_ENCODING => '',
//       CURLOPT_MAXREDIRS => 10,
//       CURLOPT_TIMEOUT => 0,
//       CURLOPT_FOLLOWLOCATION => true,
//       CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
//       CURLOPT_CUSTOMREQUEST => 'POST',
//       CURLOPT_POSTFIELDS =>'{
//         "identifier":"'.$config['bluesky-username'].'",
//         "password":"'.$config['bluesky-password'].'"
//     }',
//       CURLOPT_HTTPHEADER => array(
//         'Content-Type: application/json'
//       ),
//     ));
//
//     $response = curl_exec($curl);
//     curl_close($curl);
//     $session=json_decode($response,TRUE);
//
//     // That's got the auth bearer, and other bits of session data
//     // So now we need to post the message
//
//     $curl = curl_init();
//
//     curl_setopt_array($curl, array(
//       CURLOPT_URL => 'https://bsky.social/xrpc/com.atproto.repo.createRecord',
//       CURLOPT_RETURNTRANSFER => true,
//       CURLOPT_ENCODING => '',
//       CURLOPT_MAXREDIRS => 10,
//       CURLOPT_TIMEOUT => 0,
//       CURLOPT_FOLLOWLOCATION => true,
//       CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
//       CURLOPT_CUSTOMREQUEST => 'POST',
//       CURLOPT_POSTFIELDS =>'{
//         "repo":"'.$session['did'].'",
//         "collection":"app.bsky.feed.post",
//         "record":{
//             "$type":"app.bsky.feed.post",
//             "createdAt":"'.date("c").'",
//             "text":"'.$text.'"
//         }
//     }',
//       CURLOPT_HTTPHEADER => array(
//         'Content-Type: application/json',
//         'Authorization: Bearer '.$session['accessJwt']
//       ),
//     ));
//
//     $response = curl_exec($curl);
//
//     curl_close($curl);
//
//     echo "SKEET: Sent: ".$text.' skeet';
//
//   }
//
//
//
// }

