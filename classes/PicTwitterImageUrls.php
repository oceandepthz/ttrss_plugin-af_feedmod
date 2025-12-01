<?php

class PicTwitterImageUrls
{
    private string $url;
    public function __construct($url)
    {
	    $this->url = $url;
    }

    private string $user_agent = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:142.0) Gecko/20100101 Firefox/142.0';

    public function getImageUrls() : array
    {
        $url = $this->getNormalizationUrl();
	    // twitter url
    	$location_url = $this->getHeaderLocation($url);
//var_dump($location_url);
        if(!$location_url)
	    {
	        return [];
	    }

    	// nitter url (nitter.kozono.org)
        $nitter_url = $this->convertNitterUrl($location_url);
//var_dump($nitter_url);
        if(!$nitter_url)
	    {
	        return [];
	    }

    	// get nitter content
	    $doc = $this->getContentsDomdoc($nitter_url);
    	$xpath = new DOMXPath($doc);

	    // get nitter image url
        $img_urls = $this->getImageContents($doc, $xpath);

        // get nitter video url
        $video_urls = $this->getVideoContents($doc, $xpath);
//var_dump($video_urls);
    	return array_merge($img_urls, $video_urls); 
    }
    private function getVideoContents(DOMDocument $doc, DOMXPath $xpath) : array
    {
        $urls = [];
        $query = "(//div[@class='main-thread']//video)";
        $entries = $xpath->query($query);
        foreach($entries as $entry){
            $path = $entry->getAttribute('data-url');
            $urls[] = "https://nitter.kozono.org".$path;
        }
        return $urls;
    }
    private function getImageContents(DOMDocument $doc, DOMXPath $xpath) : array
    {
        $urls = [];
    	$query = "(//div[@id='m']//div[contains(@class,'attachment') and contains(@class,'image')]/a/img)";
        $entries = $xpath->query($query);
	    foreach($entries as $entry){
	        $path = $entry->getAttribute('src');
    	    $urls[] = "https://nitter.kozono.org".$path;
	    }
    	return $urls;
    }
    private function getContentsDomdoc(string $url) : DOMDocument
    {
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
    private function getHeaderLocation($url) : string
    {
        $doc = $this->getContentsDomdoc($url);
        $title_tags = $doc->getElementsByTagName('title');
        if ($title_tags->length > 0) {
            return $title_tags->item(0)->nodeValue;
        }
        return "";
    }

    private function getNormalizationUrl() : string
    {
        $url = $this->url;
        if($this->startsWith($url, 'pic.twitter.com'))
        {
            return "https://${url}";
        }
        return $url;
    }

    private function startsWith($haystack, $needle) : bool
    {
      return (strpos($haystack, $needle) === 0);
    }


}
