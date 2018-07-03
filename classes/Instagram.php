<?php
class Instagram {
    function get_content(string $url) : string {
        $doc = $this->get_contents_domdoc($url);
        $xpath = new DOMXPath($doc);

        return $this->get_meta_content($doc, $xpath, $this->get_medium($doc, $xpath));
    }
    function get_meta_content(DOMDocument $doc, DOMXPath $xpath, string $medium) : string {
        $prop = "og:${medium}";
        $entries = $xpath->query("//html/head//meta[@property='${prop}']"); 
        if($entries->length == 0){
            return '';
        }
        return $entries->item(0)->getAttribute('content');
    }
    function get_contents_domdoc(string $url) : DOMDocument {
        require_once('FmUtils.php');
        $u = new FmUtils();
        $html = $u->url_file_get_contents($url);
        $html = mb_convert_encoding($html, 'HTML-ENTITIES', 'ASCII, JIS, UTF-8, EUC-JP, SJIS');

        $doc = new DOMDocument();
        @$doc->loadHTML($html);
        return $doc;
    }
    function get_medium(DOMDocument $doc, DOMXPath $xpath) : string {
        $entries = $xpath->query("//html/head//meta[@name='medium']");
        if($entries->length == 0){
            return '';
        }
        return $entries->item(0)->getAttribute('content');
    }
}
