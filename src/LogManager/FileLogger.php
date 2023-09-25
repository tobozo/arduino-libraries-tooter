<?php

declare(strict_types=1);

namespace LogManager;


class FileLogger
{

  private string $log_file;
  private string $timezone;

  public function __construct( string $log_file_dir, string $log_file_name="logfile.txt")
  {
    if(! is_dir( $log_file_dir ) ) {
      mkdir( $log_file_dir );
    }
    if(! is_dir( $log_file_dir ) ) {
      throw new \Exception( "[ERROR] Unable to create log dir ".$log_file_dir );
    }
    $this->log_file = $log_file_dir.'/'.$log_file_name;
    // guess the timezone from a real system source, don't trust the php.ini
    $this->timezone = $_SERVER['TZ'] ?? trim(file_get_contents('/etc/timezone') ?: file_get_contents('/etc/localtime'));
    date_default_timezone_set($this->timezone);
  }


  // same signature as sprintf()
  public function logf(): bool
  {
    $argv = func_get_args();
    $format = array_shift( $argv );
    $msg = sprintf( $format, ...$argv );
    return $this->log( $msg );
  }


  // append message to log file
  // return bool
  public function log( string $msg ): bool
  {
    return !!file_put_contents($this->log_file, $this->timestamp().trim($msg).PHP_EOL , FILE_APPEND | LOCK_EX);
  }


  // return a formatted timestamp
  private function timestamp(): string
  {
    $dt = new \DateTime();
    // override default timezone value (usually UTC)
    $dt->setTimezone(new \DateTimeZone($this->timezone));
    return $dt->format("[Y-m-d H:i:s] ");
  }


}

