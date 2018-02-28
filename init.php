<?php
//date_default_timezone_set('Asia/Tokyo');

ini_set('user_agent', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:57.0) Gecko/20100101 Firefox/57.0');

class Af_Feedmod extends Plugin implements IHandler
{
    private $host;

    function about()
    {
        return array(
                1.0,   // version
                'Replace feed contents by contents from the linked page',   // description
                'mbirth',   // author
                false,   // is_system
                );
    }

    function api_version()
    {
        return 2;
    }

    function init($host)
    {
        $this->host = $host;

        $host->add_hook($host::HOOK_PREFS_TABS, $this);
        $host->add_hook($host::HOOK_ARTICLE_FILTER, $this);
    }

    function csrf_ignore($method)
    {
        $csrf_ignored = array("index", "edit");
        return array_search($method, $csrf_ignored) !== false;
    }

    function before($method)
    {
        if ($_SESSION["uid"]) {
            return true;
        }
        return false;
    }

    function after()
    {
        return true;
    }

    function get_file_json_data(){
        $data = array();
        foreach (glob(__DIR__."/json/*.json") as $filename) {
            $data = array_merge($data, json_decode(file_get_contents($filename),true));
        }
        return $data;
    }

    function hook_article_filter($article)
    {
        global $fetch_last_content_type;

        $json_conf = $this->host->get($this, 'json_conf');
        $owner_uid = $article['owner_uid'];
        $data = json_decode($json_conf, true);
/*
        $data = $this->get_file_json_data();
        if(is_array($data)){
            $data = array_merge($data, $file_data);
        }else{
            $data = $file_data;
        }
*/

        if (!is_array($data)) {
            // no valid JSON or no configuration at all
            return $article;
        }

        $is_execute = false;
        $is_hit_link = false;
        $hit_urlpart = '';

        if(strpos($article['link'], '//') === 0){
            $article['link'] = 'http:'.$article['link'];
        }

        // shoutcut url
        $sc_url = ['//ift.tt/', '//goo.gl/', '//bit.ly/', '//t.co/', '//tinyurl.com/', '//ow.ly/'];
        if($this->strposa($article['link'], $sc_url)){
            $this->__debug("hit shoutcut url ".$article['link']);
            $rd_url = $this->get_redirect_url($article['link']);
            if(strpos($article['link'], $rd_url) !== false){
                $this->__debug("update url ".$article['link']." => ".$rd_url);
                $article['link'] = $rd_url;
            }
        }

        foreach ($data as $urlpart=>$config) {
            if(fnmatch('*//*/*.pdf', $article['link'])){
                $this->__debug("hit pdf ".$article['link']);
                $is_hit_link = true;
                $is_execute = true;
                break;
            }

            $match_type = 'default';
            if(isset($config['match_type']) && $config['match_type'] === 'fnmatch'){
                $match_type = 'fnmatch';
            }
            if($match_type === 'default'){
                if(strpos($article['link'], $urlpart) === false) {
                    continue;
                }
            }elseif($match_type === 'fnmatch'){
                if(fnmatch($urlpart, $article['link']) === false) {
                    continue;
                }
            }else{
                continue;
            }

            $hit_urlpart = $urlpart;
            if (strpos($article['plugin_data'], "feedmod,$owner_uid:") !== false) {
                // do not process an article more than once
                if (isset($article['stored']['content'])) {
                    $article['content'] = $article['stored']['content'];
                }
                break;
            }

            $is_hit_link = true;

            if(isset($config['no_fetch']) && $config['no_fetch']){
                $is_execute = true;
                break;
            }

            $doc = new DOMDocument();
            $link = $this->replace_link(trim($article['link']),$config);

            $html = $this->get_html($link, $config);
            @$doc->loadHTML($html);
            if(!$doc){
                break;
            }

            $xpath = new DOMXPath($doc);

            $links = $this->get_np_links($xpath, $doc, $config, $link);
            $entries = $xpath->query('(//'.$config['xpath'].')');   // find main DIV according to config
            if ($entries->length == 0) {
                break;
            }

            $is_execute = true;
            $entrysXML = '';
            foreach ($entries as $entry) {
                if ($entry) {
                    $entrysXML .= $doc->saveXML($entry);
                }
            }
            $article['content'] = $entrysXML;
            $article['plugin_data'] = "feedmod,$owner_uid:" . $article['plugin_data'];

            foreach($links as $url){
                $html = $this->get_html($url, $config);
                @$doc->loadHTML($html);

                if(!$doc){
                    break;
                }

                $xpath = new DOMXPath($doc);
                $entries = $xpath->query('(//'.$config['xpath'].')');   // find main DIV according to config
                if($entries->length == 0) {
                    break;
                }
                $entrysXML = '';
                foreach ($entries as $entry) {
                    if ($entry) {
                        $entrysXML .= $doc->saveXML($entry);
                    }
                }
                $article['content'] .= $entrysXML;
            }
            break;   // if we got here, we found the correct entry in $data, do not process more
        }

        if($is_execute){
            $article['content'] = $article['content']."<div style='font-size:8px;'>xpath:".$urlpart."</div>";
        }

        // hatena content
        if(!$is_execute){
            $content = $this->get_routine_content($article['link']);
            if(strlen($content) > 0){
                $article['content'] = $content;
                $is_hit_link = true;
                $is_execute = true;
            }
        }

        if(!$is_execute){
            $link = $article['link'];
            $this->writeLog($link,$is_hit_link,$hit_urlpart);

            $html_message = $this->get_html_graby($link);
            if(strlen($html_message) > 0){
                $article['content'] = $article['content']."<div>".$html_message."</div><div style='font-size:8px;'>graby</div>";
                $is_execute = true;
            }
        }

        if($is_execute){
            $link = $article['link'];
            $content = mb_convert_encoding("<div>".$article['content']."</div>", 'HTML-ENTITIES', 'ASCII, JIS, UTF-8, EUC-JP, SJIS');
            $doc = new DOMDocument();
            @$doc->loadHTML($content);
            if($doc){
                $xpath = new DOMXPath($doc);
                $entry = $doc->documentElement;
                $this->cleanup($xpath, $entry, $config['cleanup']);

                $this->update_remote_src($entry, 'img');
                $this->update_remote_src($entry, 'iframe');

                $this->update_remote_file($entry, $link, "a", "href");
                $this->update_remote_file($entry, $link, "iframe", "src");
                $this->update_remote_file($entry, $link, "img", "src");

                $this->update_pic_twitter_com($doc, $xpath, $entry, $link);
                $this->update_peing_net($doc, $xpath, $entry, $link);
                $this->update_instagram($doc, $xpath, $entry, $link);
                if(strpos($link, '//jp.reuters.com/article/') !== false){
                    $this->update_jp_reuters_com($doc, $xpath, $entry);
                }

                $this->update_html_style($xpath, $entry);

                $article['content'] = str_replace(["<html><body>","</body></html>"],"",$doc->saveXML($entry));
            }

            // add css
            if(isset($config['append_css']) && $config['append_css']){
                $article['content'] .= "<style type='text/css'>".$config['append_css']."</style>";
            }
        }
        

        // add hatebu comment
        if(strpos($article['feed']['fetch_url'],'//b.hatena.ne.jp/hotentry/it.rss') !== false ||
           strpos($article['feed']['fetch_url'],'//feeds.feedburner.com/hatena/b/hotentry') !== false){

            // create hatena url
            $is_ssl = strpos($article['link'],'https://') === 0;
            $url = 'http://b.hatena.ne.jp/entry/';
            if($is_ssl){
                $url .= 's/';
                $url .= str_replace('https://','',$article['link']);
            }else{
                $url .= str_replace('http://','',$article['link']);
            }
            $html = $this->get_html($url, array());
            $doc = new DOMDocument();
            @$doc->loadHTML($html);
            if($doc){
                $h_comment = "";
                $xpath = new DOMXPath($doc);

                $users = 0;
                $entries = $xpath->query("(//div[@class='entry-bookmark']//span[@class='entry-info-users']/a/span)");
                if ($entries->length > 0){
                  $users = $xpath->evaluate('string()', $entries[0]);    
                }
                $entries = $xpath->query("(//div[contains(@class,'js-bookmarks') and contains(@class,'js-bookmarks-recent')])");
                if ($entries->length > 0) {
                    foreach ($entries as $entry) {
                        $this->cleanup($xpath, $entry, array("p[@class='entry-comment-meta']","button[contains(@class,'entry-comment-menu')]","ul[contains(@class,'entry-comment-menu-list')]"));
                        $h_comment .= $doc->saveXML($entry);
                    }
                    if(strlen($h_comment) > 0){
                        $style = <<<EOD
<style type='text/css'>
div.hatebu-comment { 
    border:solid 2px;
    padding:10px;
}
div.hatebu-comment img {
    width: 16px;
    height: 16px;
}
div.hatebu-comment ul.entry-comment-tags {
    display:table;
    table-layout:fixed;
    margin:0;
}
div.hatebu-comment ul.entry-comment-tags li {
    display:table-cell;
}
</style>
EOD;
                        $article['content'] .= "<div>${style}<div class='hatebu-comment'><p>hatebu comment (${users}users)</p>${h_comment}</div></div>";
                    }
                }
            }
        }

        return $article;
    }

    function get_redirect_url(string $url): string {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_HEADER, false);
        curl_setopt($ch, CURLOPT_USERAGENT,'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/58.0.3029.110 Safari/537.36 Edge/16.16299');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_BINARYTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT ,0);
        curl_setopt($ch, CURLOPT_TIMEOUT, 60);
        curl_setopt($ch, CURLOPT_SSLVERSION, CURL_SSLVERSION_TLSv1);
        curl_exec($ch);
        $redirectURL = curl_getinfo($ch,CURLINFO_EFFECTIVE_URL );
        curl_close($ch);
        return $redirectURL;
    }

    function strposa(string $haystack,array $needles) : bool {
        foreach($needles as $needle) {
                $res = strpos($haystack, $needle);
                if ($res !== false) {
                    return true;
                }
        }
        return false;
    }

    function __debug($v){
        file_put_contents(dirname(__FILE__).'/debug.txt', print_r($v, true)."\n", FILE_APPEND|LOCK_EX);
    }

    function get_routine_content(string $url) : string {
        $html = $this->get_html($url, array());
        if(!$html){
            return "";
        }
        $doc = new DOMDocument();
        @$doc->loadHTML($html);
        if(!$doc){
            return "";
        }
        $xpath = new DOMXPath($doc);

        // hatena
        $entries = $xpath->query("(//html[@data-admin-domain='//blog.hatena.ne.jp'])");
        if ($entries->length > 0){
            $entries = $xpath->query("(//div[contains(@class,'entry-content')])");
            if ($entries->length > 0){
                $entrysXML = '';
                foreach ($entries as $entry) {
                    $entrysXML .= $doc->saveXML($entry);
                }
                return $entrysXML."<div style='font-size:8px;'>hatena</div>";
            }
        }

        // etc...

        return "";
    }

    function writeLog(string $url, bool $is_hit_link, string $hit_urlpart = '') : void {
        $up = parse_url($url);
        if($up['path'] == '/' && $up['query'] == ''){
            return;
        }

        $suffix = "";
        if($is_hit_link){
            $suffix = "xpath:".$hit_urlpart;
        }

        $dt = date("Y-m-d H:i:s");
        $not_execute_url = parse_url($url);
        $host = $not_execute_url["host"];
        file_put_contents(dirname(__FILE__).'/af_feed_no_entry.txt', "$dt\t$host\t$url\t$suffix\n", FILE_APPEND|LOCK_EX);
    }

    function replace_link(string $link, array $config) : string {
        if(!isset($config['rep_pattern'])){
            return $link;
        }
        if(preg_match($config['rep_pattern'], $link) !== 1){
            return $link;
        }
        $rep_link = preg_replace($config['rep_pattern'], $config['rep_replacement'], $link);
        file_put_contents(dirname(__FILE__).'/replace_link.txt', date("Y-m-d H:i:s")."\t$link\t$rep_link\n", FILE_APPEND|LOCK_EX);
        return $rep_link;
    }

    function get_html_graby(string $url) : string {
        require_once('GrabyWarpper.php');
        $graby = new GrabyWarpper();
        return $graby->get_html($url);
    }
    function get_html_pjs(string $url) : string {
        file_put_contents(dirname(__FILE__).'/af_feed_phantomjs.txt', date("Y-m-d H:i:s")."\t".$url."\n", FILE_APPEND|LOCK_EX);
        require_once('PhantomJsWarpper.php');
        $pjs = new PhantomJsWarpper();
        return $pjs->get_html($url);
    }
    function get_html_chrome(string $url) : string {
        file_put_contents(dirname(__FILE__).'/af_feed_chromium.txt', date("Y-m-d H:i:s")."\t".$url."\n", FILE_APPEND|LOCK_EX);
        require_once('Chrome.php');
        $ch = new Chrome();
        return $ch->get_html($url);
    }
    function get_html_chromium(string $url) : string {
        file_put_contents(dirname(__FILE__).'/af_feed_chromium.txt', date("Y-m-d H:i:s")."\t".$url."\n", FILE_APPEND|LOCK_EX);
        require_once('Chromium.php');
        $ch = new Chromium();
        return $ch->get_html($url);
    }
    function is_pjs(array $config) : bool {
        if(!isset($config['engine'])){
            return false;
        }
        return strtolower($config['engine']) == 'phantomjs';
    }
    function is_chromium(array $config) : bool {
        if(!isset($config['engine'])){
            return false;
        }
        return strtolower($config['engine']) == 'chromium';
    }
    function get_contents(string $url, array $config) : string {
        if($this->is_pjs($config)){
            return $this->get_html_pjs($url);
        } elseif ($this->is_chromium($config)){
            return $this->get_html_chrome($url);
        } else {
            $r = fetch_file_contents($url);
            return $r ? $r : "";
        }
    }
    function get_html(string $url, array $config) : string {
        $html = $this->get_contents($url, $config);
        if(!$html){
            sleep(10);
            $html = $this->get_contents($url, $config);
            if(!$html){
                sleep(60);
                $html = $this->get_contents($url, $config);
            }
        }
        if(!$html){
            return $html;
        }

        return mb_convert_encoding($html, 'HTML-ENTITIES', 'ASCII, JIS, UTF-8, EUC-JP, SJIS');
    }

    // number向け
    function get_np_links_number_bunshun_jp(DOMXPath $xpath, DOMDocument $doc, string $link) : array {
        $links = array();

        $numberXpath = new DOMXPath($doc);
        $numberEntry = $numberXpath->query("(//div[@class='list-pagination-append-02']/span)");
        if($numberEntry->length === 0){
            return array();
        }
        $numberEntry = $numberEntry->item(0);
        preg_match('/1\/([0-9]+).*/', $numberEntry->nodeValue, $match);
        for($i = 2;$i <= $match[1]; $i++){
            $links[] = $link."?page=".$i;
        }
        return $links;
    }
    function get_np_links(DOMXPath $xpath, DOMDocument $doc, array $config, string $link) : array {
        $links = array();

        if(strpos($link, '//number.bunshun.jp/articles/') !== FALSE){
            return $this->get_np_links_number_bunshun_jp($xpath, $doc, $link);
        }
        if(!isset($config['next_page']) || !$config['next_page']){
            return array();
        }
        $config_next_page = $config['next_page'];
        if(!$config_next_page){
            return array();
        }

        $next_page_xpath = new DOMXPath($doc);
        $next_page_entries = $next_page_xpath->query('(//'.$config['next_page'].')');
        if ($next_page_entries === false || $next_page_entries->length === 0){
            return array();
        }
        $next_page_basenode = $next_page_entries->item(0);
        if (!$next_page_basenode) {
            return array();
        }

        if (isset($config['next_page_cleanup'])) {
            if (!is_array($config['next_page_cleanup'])) {
                $config['next_page_cleanup'] = array($config['next_page_cleanup']);
            }
            foreach ($config['next_page_cleanup'] as $next_page_cleanup) {
                $next_page_cleanup_nodelist = $xpath->query('//'.$next_page_cleanup, $next_page_basenode);
                foreach ($next_page_cleanup_nodelist as $node) {
                    if ($node instanceof DOMAttr) {
                        $node->ownerElement->removeAttributeNode($node);
                    } else {
                        $node->parentNode->removeChild($node);
                    }
                }
            }
        }

        $next_page_nodelist = $next_page_basenode->getElementsByTagName('a');
        if($next_page_nodelist->length == 0){
            return array();
        }
        foreach ($next_page_nodelist as $node) {
            $next_page = $node->getAttribute('href');
            if(strlen($next_page) == 0){
                continue;
            }
            if(substr($next_page, 0, 1) == "#"){
                continue;
            }
            if(substr($next_page, 0, 1) == "?"){
                $next_page = explode("?", $link)[0].$next_page;
            }
            if(substr($next_page, 0, 1) == "/"){
                $url_item = parse_url($link);
                $next_page = $url_item['scheme'].'://'.$url_item['host'].$next_page;
            }
            if(substr($next_page, 0, 2) == "./"){
                $next_page = $link.substr($next_page, 1);
            }
            if(substr($next_page, 0, 4) != "http"){
                $pos = strrpos($link, "/");
                if($pos){
                    $next_page = substr($link, 0, $pos+1).$next_page;
                }
            }
            if($link === $next_page){
                continue;
            }
            $links[] = $next_page;
        }
        $links = array_unique($links);

        return $links;
    }

    function update_jp_reuters_com(DOMDocument $doc, DOMXPath $xpath, DOMElement $basenode): void {
        if(!$basenode){
            return;
        }
        $query = "(//div[contains(@class,'LazyImage_image_')])";
        $nodelist = $xpath->query($query, $basenode);
        if($nodelist->length === 0){
            return;
        }
        foreach ($nodelist as $node) {
            $style = $xpath->evaluate('string(@style)', $node);
            preg_match('/^.*\((.*)\).*$/', $style, $matches);
            if(!isset($matches[1])){
                return;
            }
            $url = str_replace('&w=20','&w=1280', $matches[1]);
            if(strlen($url) === 0){
                return;
            }
            $this->append_img_tag($doc, $node, $url);            
        }
    }
    function update_instagram(DOMDocument $doc, DOMXPath $xpath, DOMElement $basenode, string $link): void {
        if(!$basenode){
            return;
        }
        $query = "(//blockquote[@class='instagram-media'])";
        $nodelist = $xpath->query($query, $basenode);
        if($nodelist->length === 0){
            return;
        }
        foreach ($nodelist as $node) {
            $link_node = $xpath->query("(.//div/p/a)", $node);
            if($link_node->length ===0){
                continue;
            }
            $link = $xpath->evaluate('string(@href)',$link_node[0]);
            if(strpos($link, 'https://www.instagram.com/p/') !== 0){
                continue;
            }
            $img_link = $this->get_instagram_img_url($link);
            if(!$img_link){
                continue;
            }
            $d_nodes = $xpath->query("(.//div/div/div)", $node);
            if($d_nodes->length > 0){
                $d_node = $d_nodes[0];
                $d_node->parentNode->removeChild($d_node);
            }
            $i_nodes = $xpath->query("(.//div/div)", $node);
            if($i_nodes->length > 0){
               $i_node = $i_nodes[0];

               if(strpos($img_link, ".jpg") !== false){
                   $img = $doc->createElement('img','');
                   $img->setAttribute('src', $img_link);
                   $i_node->appendChild($img);
                   $i_node->setAttribute('style','');
               }else if(strpos($img_link, ".mp4") !== false){
                   $img = $doc->createElement('video','');
                   $img->setAttribute('src', $img_link);
                   $img->setAttribute('type', 'video/mp4');
                   $img->setAttribute('preload','none');
                   $i_node->appendChild($img);
                   $i_node->setAttribute('style','');
               }
            }
        }
    }
    function get_instagram_img_url(string $url) : string {
        $html = $this->get_html_chrome($url);
        $doc = new DOMDocument();
        @$doc->loadHTML($html);
        if(!$doc){
            return "";
        }

        $xpath = new DOMXPath($doc);
        $entries = $xpath->query("(//span[@id='react-root']//article/div//img)");
        if($entries->length > 0) {
            $entry = $entries[0];
            return $xpath->evaluate('string(@src)', $entry);
        }
        $entries = $xpath->query("(//span[@id='react-root']//article/div//video)");
        if($entries->length > 0) {
            $entry = $entries[0];
            return $xpath->evaluate('string(@src)', $entry);
        }
        return "";
    }

    function update_peing_net(DOMDocument $doc, DOMXPath $xpath, DOMElement $basenode, string $link) : void {
        if(!$basenode){
            return;
        }

        $item = "//a[contains(@data-expanded-url,'https://peing.net/ja/qs/')]";
        $node_list = $xpath->query($item, $basenode);
        if(!$node_list || $node_list->length === 0){
            return;
        }
        foreach ($node_list as $node){
            if(!$node){
                continue;
            }
            $link = $xpath->evaluate('string(@data-expanded-url)', $node);
            if(!$link){
                continue;
            }
            $url = $this->get_peing_img_link($link);
            if(!$url){
                continue;
            }
            $this->append_img_tag($doc, $node, $url);
        }
    }
    function get_peing_img_link(string $link) : string {
        $html = $this->get_html($link, array());
        $doc = new DOMDocument();
        @$doc->loadHTML($html);

        if(!$doc){
            return array();
        }
        $xpath = new DOMXPath($doc);

        $entries = $xpath->query("(//div[@class='answer-box']/div/a/img[@class='question-eye-catch'])");
        if($entries === false || $entries->length == 0) {
            return "";
        }
        return $xpath->evaluate('string(@src)', $entries->item(0));
    }
    function update_pic_twitter_com(DOMDocument $doc, DOMXPath $xpath, DOMElement $basenode, string $link) : void {
        if(!$basenode){
            return;
        }
        $exclusion_list = ['//togetter.com/','//kabumatome.doorblog.jp/'];
        foreach ($exclusion_list as $exclusion){
            if(strpos($link, $exclusion) !== false){
                return;
            }
        }

        $item = "//a[contains(text(),'pic.twitter.com/')]";
        $node_list = $xpath->query($item, $basenode);
        if(!$node_list || $node_list->length === 0){
            return;
        }
        foreach ($node_list as $node){
            if(!$node){
                continue;
            }
            $link = $xpath->evaluate('string(@href)', $node);
            if(!$link){
                continue;
            }
            $urls = $this->get_pic_links($link);
            if(!$urls){
                continue;
            }
            foreach(array_reverse($urls) as $url){
                if(strpos($url, 'https://twitter.com/i/videos/') === 0){
                    $this->append_iframe_tag($doc, $node, $url);
                } else {
                    $this->append_img_tag($doc, $node, $url);
                }
            } 
        }
    }

    function append_iframe_tag(DOMDocument $doc, DOMElement $node, string $url) : void {
        $if = $doc->createElement('iframe','');
        $if->setAttribute('src', $url);
        $if->setAttribute('width', '640');
        $if->setAttribute('height', '480');
        $if->setAttribute('sandbox', 'allow-scripts');
        $node->parentNode->insertBefore($if, $node->nextSibling);
    }

    function append_img_tag(DOMDocument $doc, DOMElement $node, string $url) : void {
        $img = $doc->createElement('img','');
        $img->setAttribute('src', $url);
        $node->parentNode->insertBefore($img, $node->nextSibling);
    }

    function get_pic_links(string $url) : array {
        $html = $this->get_html($url, array());
        $doc = new DOMDocument();
        @$doc->loadHTML($html);

        if(!$doc){
            return array();
        }
        $xpath = new DOMXPath($doc);

        $urls = array();

        // video
        $entries = $xpath->query("(//meta[@property='og:video:url'])");
        if($entries->length > 0) {
            $urls[] = str_replace("?embed_source=facebook", "", $xpath->evaluate('string(@content)', $entries->item(0)));
        }

        // image
        $entries = $xpath->query("(//meta[@property='og:image'])");
        if($entries->length == 0) {
            return $urls;
        }
        foreach ($entries as $entry) {
            $urls[] = str_replace(":large","",$xpath->evaluate('string(@content)', $entry));
        }
        return array_unique($urls);
    }

    function default_cleanup(DOMXPath $xpath, DOMElement $basenode) : void {
        if(!$basenode){
            return;
        }

        $default_cleanup = [
            "script[contains(@src,'ead2.googlesyndication.com/pag') or contains(text(),'adsbygoogle')]",
            "ins[contains(@class,'adsbygoogle')]",
            "div[@class='wp_social_bookmarking_light' or contains(@class,'e-adsense') or @id='my-footer' or @class='ninja_onebutton' or @class='social4i' or @class='yarpp-related' or @id='ads' or contains(@class,'fc2_footer')]",
            "a[contains(@href,'//px.a8.net/')]",
            "noscript"
        ];

        foreach ($default_cleanup as $cleanup_item) {
            if(!$cleanup_item){
                continue;
            }
            if(strpos($cleanup_item, "./") !== 0){
                $cleanup_item = '//'.$cleanup_item;
            }
            $nodelist = $xpath->query($cleanup_item, $basenode);
            if(!$nodelist || $nodelist->length === 0){
                continue;
            }
            foreach ($nodelist as $node) {
                if ($node instanceof DOMAttr) {
                    $node->ownerElement->removeAttributeNode($node);
                } else {
                    $node->parentNode->removeChild($node);
                }
            }
        }
    }

    function update_html_style(DOMXPath $xpath, DOMElement $basenode) : void {
        if(!$basenode){
            return;
        }
        $list = $xpath->query("(//*[string-length(@style) > 0])");
        if(!$list || $list->length === 0){
            return;
        }
        foreach($list as $item){
            $s = '';
            if($item->hasAttribute('style')){
                $s = trim($item->getAttribute('style'));
            }
            if(!$s || strlen($s) === 0){
                continue;
            }
            $style = $this->css_style_to_array($s);
            $is_update = false;
            if(array_key_exists('display', $style) && $style['display'] == 'none'){
                $style['display'] = '';
                $is_update = true;
            }

            if($is_update){
                $item->setAttribute('style', $this->array_to_css_style($style));
            }
        }
    }
    function array_to_css_style(array $style) : string {
        $s = '';
        foreach($style as $k => $v){
            if(!$v){
                continue;
            }
            $s .= "${k}:${v};";
        }
        return $s;
    }

    function css_style_to_array(string $style) : array {
        $l = [];

        foreach(explode(";", $style) as $i){
            $kv = explode(":", $i);
            $k = trim($kv[0]);
            $v = trim($kv[1]);
            if($k && $v){
                $l[$k] = $v;
            }
        }
        return $l;
    }

    function cleanup(DOMXPath $xpath, DOMElement $basenode, $config_cleanup) : void {
        if(!$basenode){
            return;
        }
        $this->default_cleanup($xpath, $basenode);
        if(!isset($config_cleanup)){
            return;
        }
        if (!is_array($config_cleanup)) {
            $config_cleanup = array($config_cleanup);
        }
        foreach ($config_cleanup as $cleanup_item) {
            if(!$cleanup_item){
                continue;
            }
            if(strpos($cleanup_item, "./") !== 0){
                $cleanup_item = '//'.$cleanup_item;
            }
            $nodelist = $xpath->query($cleanup_item, $basenode);
            if(!$nodelist || $nodelist->length === 0){
                continue;
            }
            foreach ($nodelist as $node) {
                if ($node instanceof DOMAttr) {
                    $node->ownerElement->removeAttributeNode($node);
                } else {
                    $node->parentNode->removeChild($node);
                }
            }
        }
    }
    function update_remote_src(DOMElement $basenode, string $tag) : void {
        if(!$basenode){
            return;
        }
        $list = [];
        if($basenode->nodeName == $tag){
            $list[] = $basenode;
        }else{
            $list = $basenode->getElementsByTagName($tag);
        }
        foreach($list as $node){
            $original = $this->get_replace_src($node);
            if ($original) {
                $node->setAttribute('src', $original);
            }
        }
    }
    function update_remote_file(DOMElement $basenode, string $link, string $tag, string $attr) : void {
        if(!$basenode){
            return;
        }
        
        $list = [];
        if($basenode->nodeName == $tag){
            $list[] = $basenode;
        } else {
            $list = $basenode->getElementsByTagName($tag);
        }
        foreach($list as $node){
            $src = $node->getAttribute($attr);
	        $url_item = parse_url($link);
	        $scheme = $url_item['scheme'];
	        if(!$scheme){
	            $scheme = 'http';
	        }
            if(substr($src,0,2) == "//"){
                $src = $scheme.':'.$src;
            }else if(substr($src,0,1) == "/"){
                $src = $scheme.'://'.$url_item['host'].$src;
            }else if(substr($src, 0,4) != "http"){
                $pos = strrpos($link, "/");
                if($pos){
                    $src = substr($link, 0, $pos+1).$src;
                }
            }
            $node->setAttribute($attr, $src);
        }
    }
    function get_replace_src(DOMElement $node) : string {
        $url = '';
        $attr_list = ['data-original', 'data-lazy-src', 'data-src', 'data-img-path', 'srcset',
            'ng-src', 'rel:bf_image_src', 'ajax', 'data-lazy-original'];
        foreach($attr_list as $attr){
            if(!$node->hasAttribute($attr)){
                continue;
            }
            if($attr == 'srcset'){
                $url = explode(" ", explode(",", $node->getAttribute('srcset'))[0])[0];
            } else {
                $url = $node->getAttribute($attr);
            }
            if($url){
                break;
            }
        }
        return $url;
    }
    function hook_prefs_tabs($args)
    {
        print '<div id="feedmodConfigTab" dojoType="dijit.layout.ContentPane"
            href="backend.php?op=af_feedmod"
            title="' . __('FeedMod') . '"></div>';
    }

    function index()
    {
        $pluginhost = PluginHost::getInstance();
        $json_conf = $pluginhost->get($this, 'json_conf');
        //$json_conf = $this->jq_format($json_conf);
        //$json_conf = json_encode(json_decode ($json_conf), JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES); // decompress

        print "<form dojoType=\"dijit.form.Form\">";

        print "<script type=\"dojo/method\" event=\"onSubmit\" args=\"evt\">
            evt.preventDefault();
        if (this.validate()) {
            new Ajax.Request('backend.php', {
parameters: dojo.objectToQuery(this.getValues()),
onComplete: function(transport) {
if (transport.responseText.indexOf('error')>=0) notify_error(transport.responseText);
else notify_info(transport.responseText);
}
});
//this.reset();
}
</script>";

print "<input dojoType=\"dijit.form.TextBox\" style=\"display : none\" name=\"op\" value=\"pluginhandler\">";
print "<input dojoType=\"dijit.form.TextBox\" style=\"display : none\" name=\"method\" value=\"save\">";
print "<input dojoType=\"dijit.form.TextBox\" style=\"display : none\" name=\"plugin\" value=\"af_feedmod\">";

print "<table width='100%'><tr><td>";
print "<textarea dojoType=\"dijit.form.SimpleTextarea\" name=\"json_conf\" style=\"font-size: 12px; width: 99%; height: 500px;\">$json_conf</textarea>";
print "</td></tr></table>";

print "<p><button dojoType=\"dijit.form.Button\" type=\"submit\">".__("Save")."</button>";

print "</form>";
}

function save()
{
    $json_conf = $_POST['json_conf'];

    if (is_null(json_decode($json_conf))) {
        echo __("error: Invalid JSON!");
        return false;
    }

    //$json_conf = json_encode(json_decode($json_conf), JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES); // compress
    $this->host->set($this, 'json_conf', $json_conf);
    echo __("Configuration saved.");
}

function jq_format($json) {
  $descriptorspec = array(
     0 => array("pipe", "r"),
     1 => array("pipe", "w"),
  );

  $process = proc_open('/usr/bin/jq --indent 4 .', $descriptorspec, $pipes);

  if (is_resource($process)) {
      fwrite($pipes[0], $json);
      fclose($pipes[0]);
      $return_value = stream_get_contents($pipes[1]);
      fclose($pipes[1]);
      proc_close($process);
      return $return_value;
  }
}

}
