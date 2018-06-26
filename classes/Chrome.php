<?php
class Chrome {
  function get_html($url) : string {
    $cmd = "/usr/bin/google-chrome --headless --disable-gpu --dump-dom --no-sandbox ".$url;
    return shell_exec($cmd);
  }
}
