<?php

$url = "http://blog.livedoor.jp/tohopoke/archives/618274391.html";
require_once('FmUtils.php');
$u = new FmUtils();
$html = $u->url_file_get_contents($url);
$html = mb_convert_encoding($html, 'HTML-ENTITIES', 'ASCII, JIS, UTF-8, EUC-JP, SJIS');
$doc = new DOMDocument();
@$doc->loadHTML($html);

$xpath = new DOMXPath($doc);
$base = $doc->documentElement;

require_once('UpdateYoutubeEmbed.php');
UpdateYoutubeEmbed::Update($doc, $xpath, $base);

$html = $doc->saveHTML();
var_dump($html);
