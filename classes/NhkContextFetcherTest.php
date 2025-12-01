<?php
require_once('NhkContextFetcher.php');

//$url = "https://news.web.nhk/newsweb/na/nb-5050032917";
//$url = "https://news.web.nhk/newsweb/na/na-k10014903351000";
//$url = "http://www3.nhk.or.jp/news/html/20251007/k10014943071000.html";
$url = "https://www3.nhk.or.jp/news/html/20250723/k10014872321000.html";
var_dump(NhkContextFetcher::IsNhkContext($url));

$n = new NhkContextFetcher($url);
$c = $n->Fetch();
var_dump($c);

