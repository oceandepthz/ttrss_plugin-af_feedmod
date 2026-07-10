<?php
$s = file_get_contents('TranslateJapaneseGeminiTestHtml.txt'); 
$u = "https://www.example.com/";

require_once('TranslateJapaneseInterface.php');
require_once('AbstractTranslateJapanese.php');
require_once('TranslateJapaneseOpenRouter.php');
$tj = new TranslateJapaneseOpenRouter($s, $u);

echo "isTranslate: ";
var_dump($tj->isTranslate());

echo "translateString";
var_dump($tj->translateString());
