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
        require_once('NitterContents.php');
        $n = new NitterContents($nitter_url);
        if($n->isNitter())
        {
            return $n->getContent();
        }
        return "";
    }
    private function convertNitterUrl($url) : string
    {
        $pattern = '/^https:\/\/(?:twitter|x)\.com\/(.*\/status\/[0-9]*).*$/';
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
