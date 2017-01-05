<?php
$json_conf = file_get_contents('./2.json');
$config = json_decode($json_conf, true);

$link = 'http://dime.jp/genre/262685/';
var_dump(replace_link($link, $config));

    function replace_link($link, $config) : string {
        if(!isset($config['rep_pattern'])){
            return $link;
        }
        if(preg_match($config['rep_pattern'], $link) !== 1){
            return $link;
        }
        $rep_link = preg_replace($config['rep_pattern'], $config['rep_replacement'], $link);
        return $rep_link;
    }




