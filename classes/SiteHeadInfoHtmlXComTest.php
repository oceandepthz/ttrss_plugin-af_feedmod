<?php
require_once('SiteHeadInfoHtml.php');

$url = 'https://t.co/CyXnruOvDR';
$renderer = new SiteHeadInfoHtml();
$html = $renderer->renderFromUrl($url, false);
var_dump($html);
