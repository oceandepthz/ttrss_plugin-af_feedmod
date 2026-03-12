<?php
require_once('QiitaContextFetcher.php');

// 実際のQiita記事URL
$url = "https://qiita.com/emi_ndk/items/02d8a4ef8541ad43d6c6";

echo "Checking URL: $url\n";
var_dump(QiitaContextFetcher::IsQiitaContext($url));

$n = new QiitaContextFetcher($url);
$c = $n->Fetch();
var_dump($c);
