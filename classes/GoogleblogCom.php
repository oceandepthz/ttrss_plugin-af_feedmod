<?php
class GoogleblogCom {
    function get_content(string $url) : string {
        require_once('FmUtils.php');
        $u = new FmUtils();
        $html = $u->url_file_get_contents($url);
        $html = str_replace(array("\r\n", "\r", "\n"), '', $html);
        $r = preg_match('/<script type=\'text\/template\'>(.*?)<\/script>/u', $html, $matches);
        $h = '';
        if($r === 1){
            $h = trim($matches[1]);
        }
        return "<!DOCTYPE html><html><head><meta charset='utf-8'></head><body>${h}</body></html>";
    }
}
