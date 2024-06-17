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
            $html = $u->url_file_get_contents($url);
            $ret = preg_match('/.*<title>(.*)<\/title>.*/', $html, $matches);
            if(count($matches) == 2){
                return $matches[1];
            }
            return $url;
        }

        $header = get_headers($url, true);
        if(isset($header['Location'])){
            $org_url = $header['Location'];
            if(is_array($org_url)){
                $org_url = end($org_url);
            }
            return $org_url;
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
