<?php

class NitterContents
{
    private string $url;

    function __construct(string $url)
    {
        $this->url = $url;
    }

    function isNitter() : bool
    {
        return strpos($this->url, "//nitter.kozono.org/") !== false;
    }

    function getContent() : string
    {
        require_once('FmUtils.php');
        $MAX_COUNT = 10;
        for ($i = 0; $i < $MAX_COUNT; $i++) {
            $u = new FmUtils();
            $html = $u->url_file_get_contents($this->url);
            if($html)
            {
                return $html;
            }
           sleep(5);
        }
        return "";
    }
}
