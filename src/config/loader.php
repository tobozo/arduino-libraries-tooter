<?php

define("ENV_DIR", realpath( __DIR__.'/../../' ) );

$dotenv = Dotenv\Dotenv::createImmutable( ENV_DIR );
$dotenv->load();
$dotenv->required(['MASTODON_API_KEY', 'MASTODON_API_URL', 'MASTODON_ACCOUNT_ID', 'WGET_BIN', 'GZIP_BIN']);

define("MASTODON_ACCOUNT_ID", $_ENV['MASTODON_ACCOUNT_ID'] );
define("MASTODON_API_KEY", $_ENV['MASTODON_API_KEY'] );
define("MASTODON_API_URL", $_ENV['MASTODON_API_URL'] );
define("INDEX_CACHE_DIR", ENV_DIR."/cache");

unset( $_ENV['MASTODON_API_KEY'] );

if( isset( $_ENV['DEBUG'] ) ) {

  echo "MASTODON_API_URL : ".MASTODON_API_URL."\n";
  echo "INDEX_CACHE_DIR  : ".INDEX_CACHE_DIR."\n";

}
