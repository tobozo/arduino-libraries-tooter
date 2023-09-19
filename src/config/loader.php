<?php

$envDir = realpath( __DIR__.'/../../' );

$dotenv = Dotenv\Dotenv::createImmutable( $envDir );
$dotenv->load();
$dotenv->required(['MASTODON_API_KEY', 'MASTODON_API_URL', 'WGET_BIN', 'GZIP_BIN', 'DIFF_BIN']);

define("MASTODON_ACCOUNT_ID", $_ENV['MASTODON_ACCOUNT_ID'] );
define("MASTODON_API_KEY", $_ENV['MASTODON_API_KEY'] );
define("MASTODON_API_URL", $_ENV['MASTODON_API_URL'] );

define("WGET_BIN", $_ENV['WGET_BIN'] );
define("GZIP_BIN", $_ENV['GZIP_BIN'] );
define("DIFF_BIN", $_ENV['DIFF_BIN'] );

define("INDEX_CACHE_DIR", $envDir."/cache");
define("INDEX_FILE_NAME", "library_index.json");
define("INDEX_FILE_NAME_GZ", INDEX_FILE_NAME.".gz");
define("QUEUE_FILE_NAME", "queue.json");
define("LOG_FILE_NAME", $envDir."/logfile.txt");

define("INDEX_GZ_URL", "https://downloads.arduino.cc/libraries/".INDEX_FILE_NAME_GZ);
define("FS_GZ_FILE", INDEX_CACHE_DIR."/".INDEX_FILE_NAME_GZ);
define("INDEX_CACHE_FILE", INDEX_CACHE_DIR."/".INDEX_FILE_NAME);
define("INDEX_CACHE_FILE_OLD", INDEX_CACHE_FILE.".old");
define("QUEUE_FILE", INDEX_CACHE_DIR."/".QUEUE_FILE_NAME);

if( isset( $_ENV['DEBUG'] ) ) {

  echo "MASTODON_API_URL : ".MASTODON_API_URL."\n";
  echo "INDEX_GZ_URL     : ".INDEX_GZ_URL."\n";
  echo "INDEX_CACHE_DIR  : ".INDEX_CACHE_DIR."\n";
  echo "INDEX_CACHE_FILE : ".INDEX_CACHE_FILE."\n";
  echo "WGET_BIN         : ".WGET_BIN."\n";
  echo "GZIP_BIN         : ".GZIP_BIN."\n";
  echo "DIFF_BIN         : ".DIFF_BIN."\n";

}
