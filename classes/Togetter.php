<?php
class Togetter {
    protected string $url;

    function __construct($url) {
        $this->url = $url;
    }

    function get_html() : string {
        $url = $this->url;
        $doc = $this->get_contents_domdoc($url);
        $xpath = new DOMXPath($doc);

        $html = '';
        $html .= $this->get_first_page_main($doc, $xpath);
//        $html .= $this->get_first_page_remain($doc, $xpath);
        $html .= $this->get_next_page_contents($url, $html);
//        $html .= $this->get_comment($doc, $xpath);

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

        $usedImages = $this->getUsedImages($doc, $xpath);
        $this->replaceLzpkImg($doc, $xpath, $usedImages);

        $query = "(//article/header/div[contains(@class,'info_box') or @class='info_status_box']|//article/section[contains(@class,'tweet_box')])";

        $entries = $xpath->query($query);
        foreach($entries as $entry){
            $html .= $doc->saveHTML($entry);
        }
        return $html;
    }

    // <img class="lzpk " data-s="2"  を置き換える。
    function replaceLzpkImg(DOMDocument $doc, DOMXPath $xpath, array $usedImages) : void {
        $query = "(//img[contains(@class,'lzpk')])";
        $entries = $xpath->query($query);
        foreach($entries as $entry){
            if (!$entry->hasAttribute('data-s')) {
                continue;
            }
            $imageNumber = intval($entry->getAttribute('data-s'));
            $entry->setAttribute('src', $usedImages[$imageNumber]);
            $entry->removeAttribute('data-s');
        }
    }

    function getUsedImages(DOMDocument $doc, DOMXPath $xpath) : array {
        $usedImages = [];
        $query = "(//script[contains(text(),'var usedImages')])";
        $entries = $xpath->query($query);
        if($entries->length !== 1) {
            var_dump("length:{$entries->length}");
            return $usedImages;
        }
        eval("\$usedImages = " . str_replace('var usedImages = ','', trim($entries[0]->textContent)));
        $usedImages = array_map([$this, 'normalizationUsedImage'], $usedImages); 
        return $usedImages;
    }
    function normalizationUsedImage(string $value) : string {
        if(strlen($value) === 0){
            return "";
        }
        $unescapedValue = stripslashes($value);
        if (strpos($unescapedValue, 'http') === 0) {
            return $unescapedValue;
        }
        if(strpos($unescapedValue, 'p') === 0) {
            $validValue = substr($unescapedValue, 1);
            return "https://pbs.twimg.com/profile_images/".$validValue;
        }
        if(strpos($unescapedValue, 'm') === 0) {
            $validValue = substr($unescapedValue, 1);
            return "https://pbs.twimg.com/media/".$validValue;
        }
        return $unescapedValue;
    }

/*
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
*/

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

        $usedImages = $this->getUsedImages($doc, $xpath);
        $this->replaceLzpkImg($doc, $xpath, $usedImages);

        $entries = $xpath->query("(//article/section[contains(@class,'tweet_box')])");
        foreach($entries as $entry){
            $html .= $doc->saveHTML($entry);
        }
        return $html;
    }
/*
    function get_comment(DOMDocument $doc, DOMXPath $xpath) : string {
        $html = '';
        $entries = $xpath->query("(//div[@id='comment_box'])");
        foreach($entries as $entry){
            $html .= $doc->saveHTML($entry);
        }
        return $html;
    }
*/
}
