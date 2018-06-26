<?php

require __DIR__ . "/../vendor/autoload.php";
use Graby\Graby;

class GrabyWarpper {
    function get_html($url) : string{
        $graby = new Graby();
        $result = $graby->fetchContent($url);
        if($result['status'] === 200){
            return $result['html'];
        }
        return "";
    }
}
