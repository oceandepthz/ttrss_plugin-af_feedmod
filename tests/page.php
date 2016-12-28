<?php

$doc = new DOMDocument();
$link = "http://number.bunshun.jp/articles/-/827152";

$doc = new DOMDocument();
$html = file_get_contents($link);
$html = mb_convert_encoding($html, 'HTML-ENTITIES', 'ASCII, JIS, UTF-8, EUC-JP, SJIS');

@$doc->loadHTML($html);
$xpath = new DOMXPath($doc);

var_dump(get_np_links($xpath, $doc, $link));

function get_np_links($xpath, $doc, $link){
    $links = array();

    if(strpos($link, '//number.bunshun.jp/articles/') !== FALSE){
        $numberXpath = new DOMXPath($doc);
        $numberEntry = $numberXpath->query("(//div[@class='list-pagination-append-02']/span)");
        if($numberEntry->length === 0){
            return array();
        }
        $numberEntry = $numberEntry->item(0);
        preg_match('/1\/([0-9]+).*/', $numberEntry->nodeValue, $match);
        for($i = 2;$i <= $match[1]; $i++){
            $links[] = $link."?page=".$i;
        }
    }
    return $links;
}

