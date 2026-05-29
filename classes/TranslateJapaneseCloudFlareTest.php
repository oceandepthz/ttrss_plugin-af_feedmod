<?php
$s = file_get_contents('TranslateJapaneseGeminiTestHtml.txt'); 
$u = "https://www.example.com/";

require_once('TranslateJapaneseCloudFlare.php');
$tj = new TranslateJapaneseCloudFlare($s, $u);

echo "Checking if translation is needed:\n";
var_dump($tj->isTranslate());

if($tj->isTranslate()){
    echo "Attempting translation...\n";
    $result = $tj->translateString();
    if ($result) {
        echo "Translation result:\n";
        echo $result . "\n";
    } else {
        echo "Translation failed or environment variables not set.\n";
    }
}
