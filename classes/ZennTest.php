<?php

$url = 'https://zenn.dev/isawa/articles/a721641613f013';
//$url = 'https://zenn.dev/akira_papa/books/html-css-book/viewer/01-what-is-webpage';

        require_once('Zenn.php');
        $to = new Zenn($url);
var_dump($to->is_target());

	$html =  $to->get_html();
	var_dump($html);
