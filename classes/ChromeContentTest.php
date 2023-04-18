<?php

# su -s /bin/bash - apache -c "/usr/bin/php /pub/ttrss/plugins.local/af_feedmod/classes/ChromeContentTest.php"


ini_set('error_reporting', E_ALL);
ini_set('display_errors', "1");
ini_set('display_startup_errors', "1");
ini_set('log_errors', "0");

$url = "https://japan.googleblog.com/2023/03/google.html";
        require_once('ChromeContent.php');
        $chrome = new ChromeContent($url);
        $c = $chrome->get_content();
var_dump($c);
