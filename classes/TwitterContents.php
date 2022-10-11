<?php

class TwitterContents 
{
    private string $url;
    public function __construct($url)
    {
	$this->url = $url;
    }

    private string $user_agent = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:95.0) Gecko/20100101 Firefox/95.0';

    public function getContents() : string 
    {

	// nitter url (nitter.kozono.org)
        $nitter_url = $this->convertNitterUrl($this->url);
	if(!$nitter_url)
	{
	    return "";
	}

	// get nitter content
	$doc = $this->getContentsDomdoc($nitter_url);
	$xpath = new DOMXPath($doc);

	// get nitter contents
        $contents = $this->getArticle($doc, $xpath);

        // cleanup
	//$contents = str_replace("middot", "#183", $contents);
	//$contents = str_replace("・", "&#183;", $contents);

	return $contents;
    }
    private function getArticle(DOMDocument $doc, DOMXPath $xpath) : string {
        $query = "(//div[@class='main-tweet'])";
        $entries = $xpath->query($query);
        if($entries->length > 0){
            $entry = $entries[0];
            return $doc->saveXML($entry);
	}	
	return "";
    }
    private function getContentsDomdoc(string $url) : DOMDocument {
        require_once('FmUtils.php');
        $u = new FmUtils();
        $html = $u->url_file_get_contents($url);

        $html = mb_convert_encoding($html, 'HTML-ENTITIES', 'ASCII, JIS, UTF-8, EUC-JP, SJIS');

        $doc = new DOMDocument();
        @$doc->loadHTML($html);
        return $doc;
    }
    private function convertNitterUrl($url) : string
    {
        $pattern = '/^https:\/\/twitter\.com\/(.*\/status\/[0-9]*).*$/';
        preg_match($pattern, $url, $match);
	if(count($match) != 2)
	{
            return "";
        }
        $match_value = $match[1];

        $nitter_url = "https://nitter.kozono.org/${match_value}";
        return $nitter_url;
    }
}
