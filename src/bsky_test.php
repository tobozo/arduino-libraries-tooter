<?php

declare(strict_types=1);
namespace SocialPlatform;

//use cjrasmussen\BlueskyApi\BlueskyApi;




require_once('config/loader.php');
require_once('LogManager/FileLogger.php');
require_once('SocialPlatform/BlueSky/bsky.php');


// class BlueSkyStatus
// {
//
//
//   // WARNING: this is a text-only implementation, posting links is more complex, see:
//   // - https://atproto.com/blog/create-post
//   // - https://packagist.org/packages/cjrasmussen/bluesky-api
//   // - https://github.com/cjrasmussen/BlueskyApi
//   // - https://james.cridland.net/blog/2023/php-posting-to-bluesky/
//
//   public $lang = "en";
//   private $session = NULL;
//   private $bluesky = NULL;
//
//
//   public function __construct()
//   {
//     $this->bluesky = new \SocialPlatform\BlueskyApi($_ENV['BSKY_API_APP_USER'], $_ENV['BSKY_API_APP_TOKEN']);
//     if( ! $this->bluesky->getAccountDid() ) die("Unable to get account id");
//   }
//
//
//   public function getEmbedCard( $url)
//   {
//     # the required fields for every embed card
//     $card = [
//       "uri" => $url,
//       "title" => "",
//       "description" => "",
//     ];
//
//     # fetch the HTML
//     $resp = file_get_contents( $url ) or die("Unable to fetch $url ");
//
//     libxml_use_internal_errors(true); // don't spam the console with XML warnings
//     $doc = new \DOMDocument();
//     $doc->loadHTML($resp);
//     $selector = new \DOMXPath($doc);
//     $title_tags_arr = $selector->query('//meta[@property="og:title"]');
//     $desc_tags_arr  = $selector->query('//meta[@property="og:description"]');
//     $img_url_arr    = $selector->query('//meta[@property="og:image"]');
//     // loop through all found items
//     foreach($title_tags_arr as $node) {
//       $title_tag = $node->getAttribute('content');
//     }
//     foreach($desc_tags_arr as $node) {
//       $description_tag = $node->getAttribute('content');
//     }
//     foreach($img_url_arr as $node) {
//       $img_url = $node->getAttribute('content');
//     }
//     # parse out the "og:title" and "og:description" HTML meta tags
//     if( $title_tag ) {
//       $card['title'] = $title_tag;
//     }
//     if( $description_tag ) {
//       $card['description'] = $description_tag;
//     }
//     if( $img_url ) {
//       if(!strstr($img_url, '://') ) {
//         $img_url = $url.$img_url;
//       }
//       $img_path = "/tmp/".md5($url).'.image';
//
//       if(! file_exists( $img_path ) ) {
//         $blobImage = file_get_contents( $img_url ) or die("Unable to fetch og:image at url $url");
//         file_put_contents($img_path, $blobImage ) or die("Unable to save og:image at url $url");
//       } else {
//         $blobImage = file_get_contents( $img_path ) or die("Unable to fetch og:image at path $img_path");
//       }
//       // get image mimetype
//       $img_mime_type = image_type_to_mime_type(exif_imagetype($img_path));
//       $response = $this->bluesky->request('POST', 'com.atproto.repo.uploadBlob', [], $blobImage, $img_mime_type);
//       if( !isset($response->blob) ) die("No blob in response");
//       echo "uploadBlob response for $img_mime_type: ".print_r($response, true)."\n";
//       $card["thumb"] = $response->blob;
//     }
//
//     return [
//         '$type' => "app.bsky.embed.external",
//         'external' => $card
//     ];
//   }
//
//
//
//   public function publish( $text )
//   {
//     //Get the URL from the text
//     preg_match_all('#\bhttps?://[^\s()<>]+(?:\([\w\d]+\)|([^[:punct:]\s]|/))#', $text, $matches);
//
//     if (!empty($matches) /*AND strpos($text," #")*/) {
//       //We have a URL, and a hashtag. Likely that this is press release or another thing
//       $url=$matches[0][0];
//
//       $text = trim(preg_replace('/#\w+\s*/', '', $text)); // remove hashtags
//
//       echo sprintf("Cleaned up text: %s\n", $text);// exit;
//
//       $args = [
//         "repo"       => $this->bluesky->getAccountDid(),
//         "collection" => "app.bsky.feed.post",
//         "record"     => [
//           '$type'      => "app.bsky.feed.post",
//           "langs"      => [$this->lang],
//           "createdAt"  => date("c"),
//           "text"       => $text,
//           "facets"     => [[
//             "index"      => [
//               "byteStart"  =>  strpos($text,'https:'),
//               "byteEnd"    =>  (strpos($text,'https:')+strlen($url))
//             ],
//             "features"   =>  [[
//               "uri"        =>  $url,
//               '$type'      =>  "app.bsky.richtext.facet#link"
//             ]]
//           ]]
//         ]
//       ];
//
//       echo "LINK DETECTED\n";
//
//       $embed = $this->getEmbedCard($url);
//
//       if( $embed ) {
//         echo "EMBED GENERATED\n";
//         $args['record']['embed'] = $embed;
//       }
//
//     } else {
//       // We won't try to do anything clever with this
//
//       $args = [
//         "repo"       => $this->bluesky->getAccountDid(),
//         "collection" => "app.bsky.feed.post",
//         "record"     => [
//           '$type'      => "app.bsky.feed.post",
//           "createdAt"  => date("c"), "text" => $text
//         ]
//       ];
//
//       echo "TEXTONLY DETECTED\n";
//
//     }
//
//     // return;
//     // post the message
//     $response = $this->bluesky->request('POST', 'com.atproto.repo.createRecord', $args);
//     echo "createRecord response: ".print_r($response, true)."\n";
//   }
//
// }
