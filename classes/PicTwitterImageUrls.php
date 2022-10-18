<?php

class PicTwitterImageUrls
{
    private string $url;
    public function __construct($url)
    {
	$this->url = $url;
    }

    private string $user_agent = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:95.0) Gecko/20100101 Firefox/95.0';

    public function getImageUrls() : array
    {
        $url = $this->getNormalizationUrl();

	// twitter url
	$location_url = $this->getHeaderLocation($url);
        if(!$location_url)
	{
	    return [];
	}

	// nitter url (nitter.kozono.org)
        $nitter_url = $this->convertNitterUrl($location_url);
        if(!$nitter_url)
	{
	    return [];
	}

	// get nitter content
	$doc = $this->getContentsDomdoc($nitter_url);
	$xpath = new DOMXPath($doc);

	// get nitter image url
        $urls = $this->getImageContents($doc, $xpath);

	return $urls;
    }
    private function getImageContents(DOMDocument $doc, DOMXPath $xpath) : array {
        $urls = [];
	$query = "(//div[contains(@class,'attachment') and contains(@class,'image')]/a/img)";
        $entries = $xpath->query($query);
	foreach($entries as $entry){
	    $path = $entry->getAttribute('src');
	    $urls[] = "https://nitter.kozono.org".$path;
	}
	return $urls;
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
    private function getHeaderLocation($url) : string
    {
        $default_opts = array(
            'http' => array(
                'method'=>"GET",
                'header'=>"User-Agent: ${user_agent}\n",
            )
        );
        stream_context_get_default($default_opts);
	$headers = get_headers($url, True);
	if(!$headers){
            return "";
	}
	if(!array_key_exists('location', $headers))
	{
            return "";
	}
	$location = $headers['location'];
	if(is_string($location)){
            return $location;
	}
	if(is_array($location)){
            return $location[0];
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

    private function startsWith($haystack, $needle)
    {
      return (strpos($haystack, $needle) === 0);
    }


}
