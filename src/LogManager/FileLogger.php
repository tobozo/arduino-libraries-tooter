<?php

declare(strict_types=1);

namespace LogManager;


class FileLogger
{

  private $log_file;

  public function __construct($log_file)
  {
    $this->log_file = $log_file;
    // guess the timezone from a real system source, don't trust the php.ini
    $timezone = $_SERVER['TZ'] ??
        trim(file_get_contents('/etc/timezone') ?: file_get_contents('/etc/localtime'));
    date_default_timezone_set( $timezone ); // override default timezone value (usually UTC)
  }


  public function logf()
  {
    $argv = func_get_args();

    $format = array_shift( $argv );
    $msg = sprintf( $format, ...$argv );
    $this->log( $msg );
  }


  public function log( $msg )
  {
    return file_put_contents($this->log_file, $this->timestamp().trim($msg).PHP_EOL , FILE_APPEND | LOCK_EX);
  }


  private function timestamp()
  {
    return (new \DateTime())->format("[Y-m-d H:i:s] ");
  }


}

