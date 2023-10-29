<?php

define("ENV_DIR", realpath( __DIR__.'/../../' ) );

$dotenv = Dotenv\Dotenv::createImmutable( ENV_DIR );
$dotenv->load();
$dotenv->required(['MASTODON_API_APP_TOKEN', 'MASTODON_API_APP_TOKEN', 'WGET_BIN', 'GZIP_BIN']);

//define("MASTODON_ACCOUNT_ID", $_ENV['MASTODON_ACCOUNT_ID'] );
define("MASTODON_API_APP_TOKEN", $_ENV['MASTODON_API_APP_TOKEN'] );
define("MASTODON_API_APP_URL", $_ENV['MASTODON_API_APP_URL'] );

define("MASTODON_API_CRAWLER_URL", $_ENV['MASTODON_API_CRAWLER_URL'] );
define("MASTODON_API_CRAWLER_TOKEN", $_ENV['MASTODON_API_CRAWLER_TOKEN'] );

define("INDEX_CACHE_DIR", ENV_DIR."/cache");

unset( $_ENV['MASTODON_API_TOKEN'] );

if( isset( $_ENV['DEBUG'] ) ) {

  echo "MASTODON_API_APP_URL : ".MASTODON_API_APP_URL."\n";
  echo "INDEX_CACHE_DIR  : ".INDEX_CACHE_DIR."\n";

}
