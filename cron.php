<?php

ini_set('memory_limit', '256M');
require_once(__DIR__ . '/vendor/autoload.php');

use JsonMachine\Items;
use JsonMachine\JsonDecoder\DecodingError;
use JsonMachine\JsonDecoder\ErrorWrappingDecoder;
use JsonMachine\JsonDecoder\ExtJsonDecoder;

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
$dotenv->load();
$dotenv->required(['MASTODON_API_KEY', 'MASTODON_API_URL', 'WGET_BIN', 'GZIP_BIN', 'DIFF_BIN']);

define("MASTODON_API_KEY", $_ENV['MASTODON_API_KEY'] );
define("MASTODON_API_URL", $_ENV['MASTODON_API_URL'] );

define("WGET_BIN", $_ENV['WGET_BIN'] );
define("GZIP_BIN", $_ENV['GZIP_BIN'] );
define("DIFF_BIN", $_ENV['DIFF_BIN'] );

define("INDEX_CACHE_DIR", dirname(__FILE__)."/cache");
define("INDEX_FILE_NAME", "library_index.json");
define("INDEX_FILE_NAME_GZ", INDEX_FILE_NAME.".gz");
define("QUEUE_FILE_NAME", "queue.json");

define("INDEX_GZ_URL", "https://downloads.arduino.cc/libraries/".INDEX_FILE_NAME_GZ);
define("FS_GZ_FILE", INDEX_CACHE_DIR."/".INDEX_FILE_NAME_GZ);
define("INDEX_CACHE_FILE", INDEX_CACHE_DIR."/".INDEX_FILE_NAME);
define("INDEX_CACHE_FILE_OLD", INDEX_CACHE_FILE.".old");
define("QUEUE_FILE", INDEX_CACHE_DIR."/".QUEUE_FILE_NAME);

echo "MASTODON_API_URL : ".MASTODON_API_URL."\n";
echo "INDEX_GZ_URL     : ".INDEX_GZ_URL."\n";
echo "INDEX_CACHE_DIR  : ".INDEX_CACHE_DIR."\n";
echo "INDEX_CACHE_FILE : ".INDEX_CACHE_FILE."\n";
echo "WGET_BIN         : ".WGET_BIN."\n";
echo "GZIP_BIN         : ".GZIP_BIN."\n";
echo "DIFF_BIN         : ".DIFF_BIN."\n";

//exit;

if(! is_dir( INDEX_CACHE_DIR ) ) {
  mkdir( INDEX_CACHE_DIR );
}

if(! file_exists( INDEX_CACHE_FILE ) ) { // first run, save a copy of the index file
  // TODO: stream this instead of using exec
  $ret = exec(WGET_BIN." ".INDEX_GZ_URL." -O ".FS_GZ_FILE." && ".GZIP_BIN." -d -f ".FS_GZ_FILE);
  if( $ret===false || !file_exists(INDEX_CACHE_FILE)) {
    echo "Library Registry Index download failed\n";
    exit(0);
  }
  echo "Library Registry Index saved\n";
  exit(0);
} else { // subsequent runs, backup the old index file and download a new copy
  rename( INDEX_CACHE_FILE, INDEX_CACHE_FILE_OLD );
  // TODO: stream this instead of using exec
  $ret = exec(WGET_BIN." ".INDEX_GZ_URL." -O ".FS_GZ_FILE." && ".GZIP_BIN." -d -f ".FS_GZ_FILE);
  if( $ret===false || !file_exists(INDEX_CACHE_FILE)) {
    echo "Library Registry Index download failed\n";
    exit(0);
  }
}

// compare old and new file
exec( DIFF_BIN." ".INDEX_CACHE_FILE_OLD." ".INDEX_CACHE_FILE, $diffResult );
if( empty($diffResult) ) { // no change
  echo "Library Registry Index is unchanged\n";
  exit(0);
}

echo "Library Registry Index changed:\n";

// join the diff string array into a single string, for later use with regexp
$diffResult = implode("\n", $diffResult );

// Collect library names from the diff and store them into an array
$updatedLibraries = [];
// Regexp matches capture every "name":"blah" property values found in
// the diff result except those found in "dependencies" node array.
if( preg_match_all('/>       "name": "(.*)"/', $diffResult, $matches ) ) {
  if( $matches[0] && $matches[1] && count($matches[0]) == count($matches[1]) ) {
    $updatedLibraries = array_unique($matches[1]);
  }
} else { // no "name" properties found, error or anomaly ?
  echo $diffResult."\n";
  echo "No library names found in diff, index is unchanged\n";
  // TODO: notify error
  exit(0);
}

// stream-open the index file for parsing
$jsonNew = Items::fromFile( INDEX_CACHE_FILE, ['decoder' => new ExtJsonDecoder(true)] );
// Populate recently updated libraries with the JSON from the index
$notifyLibraries = file_exists(QUEUE_FILE) ? json_decode(file_get_contents(QUEUE_FILE), true) : []; // TODO: populate with
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


if( empty( $notifyLibraries ) ) { // delete queue file
  if( file_exists(QUEUE_FILE) ) unlink(QUEUE_FILE);
} else {
  echo sprintf("Cached index has %d items and %d libraries\n", $items_count, $libraries_count );
  echo sprintf("Libraries updated in this index: %d, %s\n", count( $notifyLibraries ), implode(", ", array_keys($notifyLibraries) ) );

  file_put_contents( QUEUE_FILE, json_encode($notifyLibraries) );

  $mastodon = new MastodonAPI(MASTODON_API_KEY, MASTODON_API_URL);

  // process library notification queue
  foreach( $notifyLibraries as $libraryName => $notifyLibrary ) {
    // cleanup some values
    $author        = trim( preg_replace("/<(.*)>/", "", $notifyLibrary['author'] ) ); // remove email address from author name
    $repository    = trim( preg_replace("/\.git$/", "", $notifyLibrary['repository'] ) ); // remove trailing ".git" in repository URL
    $architectures = "arduino"; // (default)
    $tags          = ['#Arduino', '#ArduinoLibs']; // (defaults)
    // populate architectures (text and tags)
    if( isset($notifyLibrary['architectures']) && !empty($notifyLibrary['architectures']) ) {
      if( count( $notifyLibrary['architectures'] ) > 1 ) {
        $architectures = implode("/", $notifyLibrary['architectures'] );
        foreach( $notifyLibrary['architectures'] as $pos => $arch ) {
          $tags[] = '#'.$arch;
        }
      } else {
        if( $notifyLibrary['architectures'][0] != '*' ) {
          $architectures = $notifyLibrary['architectures'][0];
          $tags[] = '#'.$notifyLibrary['architectures'][0];
        }
      }
    }
    // populate message
    $statusText = sprintf( "%s (%s) for %s by %s\n➡️ %s\n%s\n%s ",
      $notifyLibrary['name'],
      $notifyLibrary['version'],
      $architectures,
      $author,
      $repository,
      $notifyLibrary['sentence'],
      implode(" ", array_unique($tags))
    );
    // ActivityPub status properties
    $visibility = 'private'; // Public , Unlisted, Private, and Direct (default)
    $language   = 'en';
    $status_data = [
      'status'     => $statusText,
      'visibility' => $visibility,
      'language'   => $language,
    ];
    // Publish to fediverse
    $resp = $mastodon->postStatus($status_data);
    // Check response
    $failed = isset($resp['ok']) && $resp['ok']===false;
    // Log results
    echo sprintf("Publishing %s (%s) / %s ... [%s]\n",
      $notifyLibrary['name'],
      $notifyLibrary['version'],
      $author,
      $failed ? 'FAILED' : 'SUCCESS'
    );
    // manage queue
    if( !$failed ) {
      unset($notifyLibraries[$libraryName]);
      // save updated queue file
      if( !empty( $notifyLibraries ) ) {
        file_put_contents( QUEUE_FILE, json_encode($notifyLibraries) );
      } else {
        if( file_exists(QUEUE_FILE) ) unlink(QUEUE_FILE);
      }
    }
    // throttle
    sleep(1);
  }

  exit(0);
}
