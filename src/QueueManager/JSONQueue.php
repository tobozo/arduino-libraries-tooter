<?php

declare(strict_types=1);

namespace QueueManager;


class JSONQueue
{

  private $queue_file_name = "queue.json";

  private $queue_file;

  public function __construct($cache_dir)
  {
    if(! is_dir( $cache_dir ) ) {
      mkdir( $cache_dir );
    }
    if( !is_dir( $cache_dir ) ) {
      throw new \Exception("Cache dir not created: $cache_dir");
    }
    $this->queue_file = $cache_dir.'/'.$this->queue_file_name;
  }


  public function getQueue()
  {
    // TODO: handle invalid contents (non JSON) in QUEUE_FILE
    return file_exists($this->queue_file)
       ? json_decode(file_get_contents($this->queue_file), true)
       : []
    ;
  }


  public function gcQueue()
  {
    if( file_exists($this->queue_file) )
      unlink($this->queue_file);
  }


  public function saveQueue( $queue )
  {
    if( !empty( $queue ) )
      file_put_contents( $this->queue_file, json_encode($queue) );
    else
      $this->gcQueue();
  }


}
