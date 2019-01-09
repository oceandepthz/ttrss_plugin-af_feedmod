<?php
   $url = 'https://twitter.com/i/moments/1025255838317826050';

//var_dump(file_get_contents($url));

        require_once('FmUtils.php');
        $u = new FmUtils();
        $html = $u->url_file_get_contents($url);

//var_dump($u->get_parser());
//var_dump($u->is_gzip_response($u->get_parser()));
//var_dump($http_response_header);
echo $html;
