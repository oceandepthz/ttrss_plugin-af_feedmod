<?php
require_once('QiitaContextFetcher.php');

// 実際のQiita記事URL
$url = "https://qiita.com/tomokoro/items/5fecd09d139f810b2009";

echo "Checking URL: $url\n";
var_dump(QiitaContextFetcher::IsQiitaContext($url));

$n = new QiitaContextFetcher($url);
$c = $n->Fetch();
var_dump($c);
