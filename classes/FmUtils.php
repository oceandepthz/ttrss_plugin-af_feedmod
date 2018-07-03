<?php
class FmUtils {
  function url_file_get_contents(string $url) : string {
    $opts = [
      'http'=>[
        'ignore_errors' => true,
        'method' => "GET",
        'header' => "Accept-language: ja,en-US;q=0.7,en;q=0.3\r\n".
                    "Accept-Encoding: gzip, deflate, br\r\n".
                    "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/64.0.3282.140 Safari/537.36 Edge/17.17134\r\n"
      ]
    ];
    $context = stream_context_create($opts);
    $data = file_get_contents($url, false, $context);
    if (self::is_gzip_response($http_response_header)) {  
      return gzdecode($data);
    } else {
      return $data;
    }
  }

  static private function is_gzip_response($headers) : bool {
    foreach($headers as $header) {
      if (stristr($header, 'content-encoding') and stristr($header, 'gzip')) {
        return true;
      }
    }
  }

}

