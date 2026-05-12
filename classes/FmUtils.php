<?php
require __DIR__ . "/../vendor/autoload.php";
use HeaderParser\Parser;

class FmUtils {
  private $parser;

  function url_file_get_contents(string $url) : string {
    $dt = date("Y-m-d H:i:s");
    file_put_contents(dirname(__FILE__).'/../logs/url_fetch.txt', "[${dt}] START ${url}\n", FILE_APPEND|LOCK_EX);

    $opts = [
      'http'=>[
        'ignore_errors' => true,
        'method' => "GET",
        'timeout' => 30,
        'header' => "Accept-language: ja,en-US;q=0.7,en;q=0.3\r\n".
                    "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/146.0.0.0 Safari/537.36 Edg/146.0.0.0\r\n"
      ],
      'ssl' => [
        'verify_peer' => false,
        'verify_peer_name' => false,
      ]
    ];

                    //"Accept-Encoding: gzip, deflate, br\r\n".

    // config 確認。
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_NOBODY, true);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HEADER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_MAXREDIRS, 3);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    curl_setopt($ch, CURLOPT_USERAGENT, "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/146.0.0.0 Safari/537.36 Edg/146.0.0.0");
    $header_data = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $content_type = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    $content_length = curl_getinfo($ch, CURLINFO_CONTENT_LENGTH_DOWNLOAD);
    curl_close($ch);

    if ($header_data !== false && $http_code < 400) {
        // PDF または 10MB を超えるコンテンツはスキップ
        if (stripos($content_type, 'application/pdf') !== false || (int)$content_length > 10 * 1024 * 1024) {
            $dt = date("Y-m-d H:i:s");
            file_put_contents(dirname(__FILE__).'/../logs/url_fetch.txt', "[{$dt}] SKIP (Type:{$content_type}, Len:{$content_length}) {$url}\n", FILE_APPEND|LOCK_EX);
            return "";
        }
    }

    $context = stream_context_create($opts);
    $http_response_header = null;

    $data = @file_get_contents($url, false, $context);
    if($data === false){
      $dt = date("Y-m-d H:i:s");
      file_put_contents(dirname(__FILE__).'/../logs/url_fetch.txt', "[{$dt}] FAIL {$url}\n", FILE_APPEND|LOCK_EX);
      return "";
    }
    if($http_response_header == null){
      $dt = date("Y-m-d H:i:s");
      file_put_contents(dirname(__FILE__).'/../logs/url_fetch.txt', "[{$dt}] NULL_HEADER {$url}\n", FILE_APPEND|LOCK_EX);
      return "";
    }

    $dt = date("Y-m-d H:i:s");
    $len = strlen($data);
    file_put_contents(dirname(__FILE__).'/../logs/url_fetch.txt', "[{$dt}] END {$url} ({$len})\n", FILE_APPEND|LOCK_EX);

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

