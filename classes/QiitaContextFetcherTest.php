<?php
require_once('QiitaContextFetcher.php');

// 実際のQiita記事URL
$url = "https://qiita.com/Katayama_Studio/items/07825ca1b1fe4b2b2fe1";

echo "Checking URL: $url\n";
var_dump(QiitaContextFetcher::IsQiitaContext($url));

$n = new QiitaContextFetcher($url);
$c = $n->Fetch();
var_dump($c);
