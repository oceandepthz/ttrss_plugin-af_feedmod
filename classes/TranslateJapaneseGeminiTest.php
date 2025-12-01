<?php
$s = file_get_contents('TranslateJapaneseGeminiTestHtml.txt'); 
//$u = "https://www.nhk.jp/p/ts/4V23PRP3YR/episode/te/JY4W6PN9V5/";
$u = "https://www.example.com/";

require_once('TranslateJapaneseGemini.php');
$tj = new TranslateJapaneseGemini($s, $u);
var_dump($tj->isTranslate());
var_dump($tj->translateString());

