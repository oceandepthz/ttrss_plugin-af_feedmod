<?php
        require_once('../PhantomJsWarpper.php');
        $pjs = new PhantomJsWarpper();
        var_dump($pjs->get_html("https://www.synapse.jp/"));



