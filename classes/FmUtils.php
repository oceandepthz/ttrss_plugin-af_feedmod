<?php
require __DIR__ . "/../vendor/autoload.php";
use HeaderParser\Parser;

class FmUtils {
  private $parser;

  function url_file_get_contents(string $url) : string {
    $opts = [
      'http'=>[
        'ignore_errors' => true,
        'method' => "GET",
        'timeout' => 10,
        'header' => "Accept-language: ja,en-US;q=0.7,en;q=0.3\r\n".
                    "Accept-Encoding: gzip, deflate, br\r\n".
                    "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/64.0.3282.140 Safari/537.36 Edge/17.17134\r\n"
      ],
      'ssl' => [
        'verify_peer' => false,
        'verify_peer_name' => false,
      ]
    ];

    // config 確認。



    $context = stream_context_create($opts);
    $http_response_header = null;
    $data = file_get_contents($url, false, $context);
    if($data === false){
      return "";
    }
    if($http_response_header == null){
      return "";
    }

    $this->parser = new Parser($http_response_header);
    $status = $this->parser->getHttpStatus();
    if($status == "200"){
      if ($this->is_gzip_response($this->parser)) {
        $data = gzdecode($data);
      }
      if($this->is_cache($this->parser)){
//        $this->save_json($url, $this->parser);
//        $this->save_html($url, $data);
      }
      return $data;

    } elseif ($status == "304"){
//      $filepath = (__DIR__.'/cache/html/'.$this->get_filename_base($url).'.html';
//      if(file_exists($filepath)){
//        return file_get_contents($filepath);
//      }
    }
    return "";
  }
  public function get_parser() : Parser {
    return $this->parser;
  }

  private function is_cache(Parser $parser) : bool {
    return $parser->getHeaderValue('etag') !== null ||
      $parser->getHeaderValue('last-modified') !== null;
  }

  private function save_html(string $url, string $html) : void {
    $filename = $this->get_filename_base($url).'.html';
    file_put_contents(__DIR__.'/cache/html/'.$filename, $html, LOCK_EX);
  }
  private function save_json(string $url, Parser $parser) : void {
    $json = [];
    $json['url'] = $url;
    $json['etag'] = $parser->getHeaderValue('etag');
    $json['last-modified'] = $parser->getHeaderValue('last-modified');
    $filename = $this->get_filename_base($url).'.json';
    file_put_contents(__DIR__.'/cache/config/'.$filename, json_encode($json), LOCK_EX); 
  }

  public function is_gzip_response(Parser $parser) : bool {
    $ce = $parser->getHeaderValue('content-encoding');
    return $ce !== null && stristr($ce, 'gzip') !== false;
  }

  private function get_filename_base(string $url) : string {
    return hash('sha256', $url);
  }

}

