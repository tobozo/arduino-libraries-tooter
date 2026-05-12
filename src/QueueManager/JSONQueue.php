<?php

declare(strict_types=1);

namespace QueueManager;


class JSONQueue
{

  private string $queue_file;
  private string $queue_old_file;

  public function __construct( string $queue_dir, string $queue_file_name="queue.json")
  {
    if(! is_dir( $queue_dir ) ) {
      mkdir( $queue_dir );
    }
    if( !is_dir( $queue_dir ) ) {
      throw new \Exception("Queue dir not created: $queue_dir");
    }
    $this->queue_file = $queue_dir.'/'.$queue_file_name;
    $this->queue_old_file = $queue_dir.'/old_'.$queue_file_name;
  }


  // return JSON array from queue file
  public function get(): array
  {
    // TODO: handle invalid contents (non JSON) in QUEUE_FILE
    return file_exists($this->queue_file)
       ? json_decode(file_get_contents($this->queue_file), true)
       : []
    ;
  }


  // backup then delete queue file
  public function gc(): void
  {
    if( file_exists($this->queue_file) ) {
      file_put_contents( $this->queue_old_file, file_get_contents($this->queue_file) );
      unlink($this->queue_file);
    }
  }


  // save array in queue file
  public function save( array $queue ): void
  {
    if( !empty( $queue ) )
      file_put_contents( $this->queue_file, json_encode($queue) );
    else
      $this->gc();
  }


}
