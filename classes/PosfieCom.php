<?php
class PosfieCom
{
    protected string $url;
    function __construct($url)
    {
        $this->url = $url;
    }

    function get_html() : string
    {
        $html = "";

        $p = new PosfieComFirstPage($this->url);
        $html .= $p->get_html();

        $list = $p->get_page_list();
        foreach($list as $page)
        {
            $ap = new PosfieComAdditionalPage($page);
            $html .= $ap->get_html();
        }
        return "<!DOCTYPE html><html><head><meta charset='utf-8'></head><body>${html}</body></html>";
    }
    function is_posfie() : bool
    {
        $pattern = '//posfie.com/@';
        return strpos($this->url, $pattern) !== false;
    }
}
class PosfieComAdditionalPage
{
    protected string $url;
    protected DOMDocument $doc;
    protected DOMXPath $xpath;

    function __construct($url)
    {
        $this->url = $url;
        $this->doc = $this->get_contents_domdoc($url);
        $this->xpath = new DOMXPath($this->doc);
    }

    function get_html() : string
    {
        $html .= $this->get_page_main();
        return $html;
    }
    static function get_contents_domdoc(string $url) : DOMDocument {
        require_once('FmUtils.php');
        $u = new FmUtils();
        $html = $u->url_file_get_contents($url);

        $html = mb_convert_encoding($html, 'HTML-ENTITIES', 'ASCII, JIS, UTF-8, EUC-JP, SJIS');

        $doc = new DOMDocument();
        @$doc->loadHTML($html, LIBXML_NOWARNING | LIBXML_NOERROR | LIBXML_HTML_NODEFDTD);
        return $doc;
    }
    function get_page_main() : string
    {
        $images = $this->get_used_images();
        $this->replace_lzpk_img($images);

        $nodes = $this->xpath->query("//article/section[contains(@class,'entry_main')]");
        $html = '';
        foreach ($nodes as $node) {
            $html .= $this->doc->saveHTML($node);
        }
        return $html;
    }
    // <img class="lzpk " data-s="2"  を置き換える。
    function replace_lzpk_img(array $usedImages) : void {
        $query = "(//img[contains(@class,'lzpk')])";
        $entries = $this->xpath->query($query);
        foreach($entries as $entry){
            if (!$entry->hasAttribute('data-s')) {
                continue;
            }
            $imageNumber = intval($entry->getAttribute('data-s'));
            $entry->setAttribute('src', $usedImages[$imageNumber]);
            $entry->removeAttribute('data-s');
        }
    }

    function get_used_images() : array {
        $usedImages = [];
        $query = "(//script[contains(text(),'var usedImages')])";
        $entries = $this->xpath->query($query);
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
}
class PosfieComFirstPage
{
    protected string $url;
    protected DOMDocument $doc;
    protected DOMXPath $xpath;

    function __construct($url)
    {
        $this->url = $url;
        $this->doc = $this->get_contents_domdoc($url);
        $this->xpath = new DOMXPath($this->doc);
    }

    function get_html() : string
    {
        $html .= $this->get_page_main();
        return $html;
    }
    static function get_contents_domdoc(string $url) : DOMDocument {
        require_once('FmUtils.php');
        $u = new FmUtils();
        $html = $u->url_file_get_contents($url);

        $html = mb_convert_encoding($html, 'HTML-ENTITIES', 'ASCII, JIS, UTF-8, EUC-JP, SJIS');

        $doc = new DOMDocument();
        @$doc->loadHTML($html, LIBXML_NOWARNING | LIBXML_NOERROR | LIBXML_HTML_NODEFDTD);
        return $doc;
    }
    function get_page_list(): array
    {
        $query = "//div[@class='pagenation']/a";
        $entries = $this->xpath->query($query);
        if($entries->length === 0){
            return [];
        }
        $max_number = 1;
        foreach($entries as $entry){
            $nodeValue = trim($entry->nodeValue);
            if(!is_numeric($nodeValue))
            {
                continue;
            }
            $number = (int)$nodeValue;

            if($max_number < $number)
            {
                $max_number = $number;
            }
        }
        if($max_number <= 1)
        {
            return [];
        }
        $list = [];
        for($i = 2 ; $i <= $max_number ; $i++)
        {
            $list[] = $this->url."?page=${i}";
        }
        return $list;        
    }

    function get_page_main() : string
    {
        $images = $this->get_used_images();
        $this->replace_lzpk_img($images);

        $nodes = $this->xpath->query("//div[@class='headline_box' or contains(@class,'description_box') or @class='tag_box']|//article/section[contains(@class,'entry_main')]");
        $html = '';
        foreach ($nodes as $node) {
            $html .= $this->doc->saveHTML($node);
        }
        return $html;
    }
    // <img class="lzpk " data-s="2"  を置き換える。
    function replace_lzpk_img(array $usedImages) : void {
        $query = "(//img[contains(@class,'lzpk')])";
        $entries = $this->xpath->query($query);
        foreach($entries as $entry){
            if (!$entry->hasAttribute('data-s')) {
                continue;
            }
            $imageNumber = intval($entry->getAttribute('data-s'));
            $entry->setAttribute('src', $usedImages[$imageNumber]);
            $entry->removeAttribute('data-s');
        }
    }

    function get_used_images() : array {
        $usedImages = [];
        $query = "(//script[contains(text(),'var usedImages')])";
        $entries = $this->xpath->query($query);
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
        return $unescapedValue;
    }

}
