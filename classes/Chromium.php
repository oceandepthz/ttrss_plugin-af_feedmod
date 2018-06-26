<?php
class Chromium {
  function get_html($url) : string {
    $cmd = "/usr/bin/chromium-browser --headless --disable-gpu --dump-dom ".$url;
    $body = shell_exec($cmd);
    if(strlen($body) > 0){
      return '<html>'.$body.'</html>';
    }
    return '';
  }
}
