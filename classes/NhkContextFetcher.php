<?php
class NhkContextFetcher
{
    private string $url;
    function __construct($url)
    {
        $this->url = $url;
    }
    public static function IsNhkContext($url) : bool
    {
        if(strpos($url, '//news.web.nhk/newsweb/') !== false)
        {
            return true;
        }
        if(strpos($url, '//www3.nhk.or.jp/news/html/') !== false)
        {
            return true;
        }
        return false;
    }
    public function Fetch() : string
    {
    $contents = $this->getContent($this->url);
    if(!$contents){
        return "";
    }
    $html = mb_convert_encoding($contents, 'HTML-ENTITIES', 'ASCII, JIS, UTF-8, EUC-JP, SJIS');
    $doc = new DOMDocument();
    @$doc->loadHTML($html);
    $xpath = new DOMXPath($doc);
    $query_selector = "/html/body/div/div/div/div/div/div/div/div/div/div/div/figure|/html/body/div/div/div/div/div/div/div/div/div/div/div/div/iframe|/html/body/div/div/div/div/div/div/div/div/div/div/div/div/div/div/div/time|/html/body/div/div/div/div/div/div/div/div/div/p|/html/body/div/div/div/div/div/div/div/div/div/h3|/html/body/div/div/div/div/div/div/div/div/div/figure|/html/body/div/div/div/div/div/div/div/div/div/p/../div";
    $html = "";
    foreach($xpath->query($query_selector) as $node){
        $html .= $doc->saveHTML($node);
    }
    return "<!DOCTYPE html><html><head><meta charset='utf-8'></head><body>${html}</body></html>";
    }
    protected function getContent($url) {
    $context = stream_context_create([
        'http' => [
            'timeout' => 60,
            'follow_location' => true,
        ],
        'ssl' => [
            'verify_peer' => false,
            'verify_peer_name' => false,
        ],
    ]);
    $build_context = $this->buildContext($context);
    $contents = @file_get_contents($url, false, $build_context);
    $this->http_response_header = $http_response_header;
    return $contents;
    }
  protected function buildContext($context)
  {
    $cookie_file = "/pub/fetch_nhk_id/nhk_cookie.txt";
    $cookie_header_string = '';
    $cookies = [];
    if (file_exists($cookie_file) && is_readable($cookie_file)) {
        $lines = file($cookie_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            if (strpos(trim($line), '#') === 0 || empty(trim($line))) {
                continue;
            }
            $parts = explode("\t", $line);
            if (count($parts) === 7) {
                $name = $parts[5];
                $value = $parts[6];
                $cookies[] = $name . '=' . $value;
            }
        }
        $cookie_header_string = implode('; ', $cookies);
    }
    if (empty($cookie_header_string)) {
        return $context;
    }

    $options = stream_context_get_options($context);
    $existing_headers = $options['http']['header'] ?? '';
    $cookie_header_line = "Cookie: " . $cookie_header_string . "\r\n";
    $new_headers = $existing_headers . $cookie_header_line;
    stream_context_set_option($context, 'http', 'header', $new_headers);

    return $context;
  }


}
