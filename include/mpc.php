<?php

class Mpc {
  public $playing;

  public function __construct() {
    $this->playing = $this->checkPlaying();     
  }  

  //fade music in, accepts starting volume, finishing volume, and duration for the transition 
  public function fadeIn($start, $stop, $duration = 30) {
    $delay = $duration / ($stop - $start);
    for (;$start <= $stop; $start++) {
      $this->run('volume ' . $start);
      usleep($delay * 1000000);
    }
  }
  
  //Determine if MPD is currently playing music
  public function checkPlaying() {
    return $this->run('current') ? true : false;
  }

  //send a command to mpc
  public function run($command = null) {
    if (!empty($command)) {
      switch (strtolower($command)) {
        case 'play':
          $this->playing = true;
          break;
        case 'stop':
          $this->playing = false;
          break;
        default:
          break;
      }
      return shell_exec(escapeshellcmd('mpc ' . $command));
    }
    return false;
  }
}

?>
