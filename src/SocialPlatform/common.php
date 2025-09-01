<?php

function php_die($msg, $status=1)
{
    echo $msg;
    exit($status);
}


function php_log($msg)
{
  echo sprintf("[%s] %s", date('Y-m-d\TH:i:s'), $msg );
}

function php_logln($msg)
{
    php_log($msg.PHP_EOL);
}

function php_logd($msg)
{
  if( defined('DEBUG_LEVEL') )
    switch( true )
    {
      case DEBUG_LEVEL>0:
        if(strlen($msg)>1) php_log($msg);
        else echo $msg;
      default:
        break;
    }
}
