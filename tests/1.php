<?php

$doc = new DOMDocument();
$link = "http://blog.otakumode.com/2016/12/28/five-essences-to-success/";

$doc = new DOMDocument();
$html = file_get_contents($link);
$html = mb_convert_encoding($html, 'HTML-ENTITIES', 'ASCII, JIS, UTF-8, EUC-JP, SJIS');

@$doc->loadHTML($html);
$xpath = new DOMXPath($doc);

$ents = $xpath->query("(//article)");
foreach($ents as $ent){
   var_dump($doc->saveXML($ent)); 
}



