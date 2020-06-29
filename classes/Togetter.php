<?php
class Togetter {
    function get_html(string $url) : string {
        $doc = $this->get_contents_domdoc($url);
        $xpath = new DOMXPath($doc);

        $html = '';
        $html .= $this->get_first_page_main($doc, $xpath);
        $html .= $this->get_first_page_remain($doc, $xpath);
        $html .= $this->get_next_page_contents($url, $html);
        $html .= $this->get_comment($doc, $xpath);

        return "<!DOCTYPE html><html><head><meta charset='utf-8'></head><body>${html}</body></html>";
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
    function get_first_page_main(DOMDocument $doc, DOMXPath $xpath) : string {
        $html = '';

        $query = "(//article/header/div[@class='info_box' or @class='info_status_box']|//article/div[@class='tweet_box'])";

        $entries = $xpath->query($query);
        foreach($entries as $entry){
            $html .= $doc->saveHTML($entry);
        }
        return $html;
    }
    function get_first_page_remain(DOMDocument $doc, DOMXPath $xpath) : string {
        $html = '';
        $entries = $xpath->query("//script[contains(text(),'moreTweetContent')]");
        if($entries->length == 1){
           $item = trim($entries->item(0)->textContent);
           $item = str_replace('var moreTweetContent = "','',$item);
           $item = rtrim($item ,';');
           $item = rtrim($item ,'"');

           $item = str_replace('\n',"\n",$item);
           $item = str_replace('\"','"',$item);
           $item = str_replace('\/','/',$item);

           $item = $this->unicode_encode($item);
           $html = $item;
        }
        return $html;
    }
    function unicode_encode(string $str) : string {
        return preg_replace_callback("/\\\\u([0-9a-zA-Z]{4})/", [$this,"unicode_encode_callback"], $str);
    }

    function unicode_encode_callback(array $matches) : string {
        return mb_convert_encoding(pack("H*", $matches[1]), "UTF-8", "UTF-16");
    }

    function get_next_page_contents(string $url, string $html) : string {
        $html = mb_convert_encoding("<!DOCTYPE html><html><head><meta charset='utf-8'></head><body>${html}</body></html>", 'HTML-ENTITIES', 'ASCII, JIS, UTF-8, EUC-JP, SJIS');
        $doc = new DOMDocument();
        @$doc->loadHTML($html);
        $xpath = new DOMXPath($doc);

        $entries = $xpath->query("(//div[@class='pagenation']//a)");
        if($entries->length == 0){
            return "";
        }
        $list = [];
        $page_max = 0;
        foreach($entries as $entry){
            if(!is_numeric($entry->textContent)){
                continue;
            }
            $i = intval($entry->textContent);
            if($i <= $page_max){
                continue;
            }
            $page_max = $i;
        }
        if($page_max < 2){
            return "";
        }
        foreach(range(2, $page_max) as $page_num){
            $p_url = $url.'?page='.$page_num;
            if(in_array($p_url, $list)){
                continue;
            }
            $list[] = $p_url;
        }
/*
        foreach($entries as $entry){
            $nurl = "https://togetter.com".$entry->getAttribute('href');
            if($nurl == $url){
                continue;
            }
            if(in_array($nurl, $list)){
                continue;
            }
            $list[] = $nurl;
        }*/
        if(count($list) == 0){
            return "";
        }
        $html = '';
        foreach($list as $u){
            $doc = $this->get_contents_domdoc($u);
            $xpath = new DOMXPath($doc);
            $html .= $this->get_second_page_main($doc, $xpath);
        }
        return $html;
    }
    function get_second_page_main(DOMDocument $doc, DOMXPath $xpath) : string {
        $html = '';

        $entries = $xpath->query("(//div[@class='contents_main']/div[@class='tweet_box']/div[contains(@class,'list_box') or @class='type_markdown'])");
        foreach($entries as $entry){
            $html .= $doc->saveHTML($entry);
        }
        return $html;
    }
    function get_comment(DOMDocument $doc, DOMXPath $xpath) : string {
        $html = '';
        $entries = $xpath->query("(//div[@id='comment_box'])");
        foreach($entries as $entry){
            $html .= $doc->saveHTML($entry);
        }
        return $html;
    }
}
