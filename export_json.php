#!/usr/bin/php
<?php
    require_once 'config.php';
    $dbh = new PDO(PDO_DSN, DB_USER, DB_PASS);
    $sql = "SELECT content FROM ttrss_plugin_storage WHERE name = 'Af_Feedmod'";

    $content = '';
    foreach ($dbh->query($sql) as $row) {
        $content = unserialize($row['content']);
        break;
    }
    echo $content['json_conf'];
