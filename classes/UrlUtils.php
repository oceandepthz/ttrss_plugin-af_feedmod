<?php
class UrlUtils {
    static public function is_shortcur_url(string $url) : bool {
        $target = ['//ift.tt/', '//goo.gl/', '//bit.ly/', '//t.co/', '//tinyurl.com/', '//ow.ly/', '//amzn.to/', '//sqex.to/', '//sports.yahoo.co.jp/column/', '//feedproxy.google.com/', '//rss.rssad.jp/', '//search.app/'];
        return self::strposa($url, $target);
    }
    static public function get_original_url(string $url) : string {
        if(!self::is_shortcur_url($url)){
            return $url;
        }
        if(strpos($url, '//t.co/') !== false){
            require_once('FmUtils.php');
            $u = new FmUtils();
            $dt = date("Y-m-d H:i:s");
            file_put_contents(dirname(__FILE__).'/../logs/url_fetch.txt', "[{$dt}] UrlUtils: t.co fetch START {$url}\n", FILE_APPEND|LOCK_EX);
            $html = $u->url_file_get_contents($url);
            $ret = preg_match('/.*<title>(.*)<\/title>.*/', $html, $matches);
            if(count($matches) == 2){
                return $matches[1];
            }
            return $url;
        }

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_NOBODY, true);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HEADER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false); // リダイレクト先を知りたいので自身は追わない
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        curl_setopt($ch, CURLOPT_USERAGENT, "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/146.0.0.0 Safari/537.36 Edg/146.0.0.0");
        $header_data = curl_exec($ch);
        $redirect_url = curl_getinfo($ch, CURLINFO_REDIRECT_URL);
        curl_close($ch);

        if($redirect_url){
            return $redirect_url;
        }
        return $url;        
    }
    static private function strposa(string $haystack,array $needles) : bool {
        foreach($needles as $needle) {
            $res = strpos($haystack, $needle);
            if ($res !== false) {
                return true;
            }
        }
        return false;
    }
}
