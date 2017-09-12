<?php
//date_default_timezone_set('Asia/Tokyo');

ini_set('user_agent', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:50.0) Gecko/20100101 Firefox/50.0');

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

        foreach ($data as $urlpart=>$config) {
            if (strpos($article['link'], $urlpart) === false) {
                continue;   // skip this config if URL not matching
            }
            if (strpos($article['plugin_data'], "feedmod,$owner_uid:") !== false) {
                // do not process an article more than once
                if (isset($article['stored']['content'])) {
                    $article['content'] = $article['stored']['content'];
                }
                break;
            }

            $is_hit_link = true;
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

        // hatena contents
        if($is_hit_link === false){
            $link = $article['link'];
            $html = $this->get_html($link, array());
            if($html){
                $doc = new DOMDocument();
                @$doc->loadHTML($html);
                if($doc){
                    $xpath = new DOMXPath($doc);
                    $entries = $xpath->query("(//html[@data-admin-domain='//blog.hatena.ne.jp'])");
                    if ($entries->length > 0){
                        $entries = $xpath->query("(//div[@class='entry-content'])");
                        if ($entries->length > 0){
                            $entrysXML = '';
                            foreach ($entries as $entry) {
                                if ($entry) {
                                    $entrysXML .= $doc->saveXML($entry);
                                }
                            }
                            $article['content'] = $entrysXML;
                            $is_hit_link = true;
                            $is_execute = true;
                        }
                    }
                }
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
                $this->update_img_tags($entry, $link);
                $this->update_tags($entry, $link, "a", "href");
                $this->update_tags($entry, $link, "iframe", "src");
                $this->update_pic_twitter_com($doc, $xpath, $entry);                
                $article['content'] = str_replace(["<html><body>","</body></html>"],"",$doc->saveXML($entry));
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

        if(!$is_execute){
            $this->writeLog($article['link'],$is_hit_link);
        }
        return $article;
    }

    function __debug($v){
        file_put_contents(dirname(__FILE__).'/debug.txt', print_r($v, true)."\n", FILE_APPEND|LOCK_EX);
    }

    function writeLog(string $url, bool $is_hit_link) : void {
        $exclusionUrlList = json_decode(file_get_contents(dirname(__FILE__).'/exclusion_url_list.json'),true);
        foreach($exclusionUrlList as $v){
            if(strpos($url, $v) !== false){
                return;
            }
        }

        $suffix = "";
        if($is_hit_link){
            $suffix = "xpath";
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

    function get_html_pjs(string $url) : string {

        file_put_contents(dirname(__FILE__).'/af_feed_phantomjs.txt', date("Y-m-d H:i:s")."\t".$url."\n", FILE_APPEND|LOCK_EX);

        require_once('PhantomJsWarpper.php');
        $pjs = new PhantomJsWarpper();
        return $pjs->get_html($url);
    }

    function is_pjs(array $config) : bool {
        if(!isset($config['engine'])){
            return false;
        }
        return strtolower($config['engine']) == 'phantomjs';
    }
    
    function get_contents(string $url, array $config){
        if($this->is_pjs($config)){
            return $this->get_html_pjs($url);
        }else{
            return fetch_file_contents($url);
        }
    }

    function get_html(string $url, array $config){
        $html = $this->get_contents($url, $config);
        if(!$html){
            sleep(10);
            $html = $this->get_contents($url, $config);
            if(!$html){
                sleep(30);
                $html = $this->get_contents($url, $config);
            }
        }
        if(!$html){
            return $html;
        }

        $content_type = $fetch_last_content_type;

        $charset = false;
        if (!isset($config['force_charset'])) {
            if ($content_type) {
                preg_match('/charset=(\S+)/', $content_type, $matches);
                if (isset($matches[1]) && !empty($matches[1])) {
                    $charset = $matches[1];
                }
            }
        } else {
            // use forced charset
            $charset = $config['force_charset'];
        }

        if ($charset && isset($config['force_unicode']) && $config['force_unicode']) {
            $html = mb_convert_encoding($html, 'utf-8', $charset);
            $charset = 'utf-8';
        }

        if ($charset) {
            $html = '<?xml encoding="' . $charset . '">' . $html;
        }

        $html = mb_convert_encoding($html, 'HTML-ENTITIES', 'ASCII, JIS, UTF-8, EUC-JP, SJIS');
        return $html;
    }

    // number向け
    function get_np_links_number_bunshun_jp($xpath, $doc, $link){
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
    function get_np_links($xpath, $doc, $config, $link){
        $links = array();

        if(strpos($link, '//number.bunshun.jp/articles/') !== FALSE){
            return $this->get_np_links_number_bunshun_jp($xpath, $doc, $link);
        }
        if(isset($config['next_page']) && $config['next_page']){
            $next_page_basenode = false;
            $next_page_xpath = new DOMXPath($doc);
            $next_page_entries = $next_page_xpath->query('(//'.$config['next_page'].')');
            if ($next_page_entries->length > 0) {
                $next_page_basenode = $next_page_entries->item(0);
            }

            if ($next_page_basenode) {
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
                if($next_page_nodelist->length > 0){
                    foreach ($next_page_nodelist as $node) {
                        $next_page = $node->getAttribute('href');
                        if(strlen($next_page) == 0){
                            continue;
                        }
                        if(substr($next_page, 0, 1) == "?"){
                            $next_page = explode("?", $link)[0].$next_page;
                        }
                        if(substr($next_page, 0, 1) == "/"){
                            $url_item = parse_url($link);
                            $next_page = $url_item['scheme'].'://'.$url_item['host'].$next_page;
                        }
                        if(substr($next_page, 0,4) != "http"){
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
                }
            }
        }
        return $links;
    }

    function update_instagram($xpath, $basenode){
        if(!$basenode){
            return;
        }
        $query = "(//blockquote[@class='instagram-media'])";
        $nodelist = $xpath->query($query, $basenode);
        if(!$nodelist){
            return;
        }
        foreach ($nodelist as $node) {
            // a tag
            // kesu
            // touroku
        }
    }

    function update_pic_twitter_com($doc, $xpath, $basenode){
        if(!$basenode){
            return;
        }
        $item = "//a[contains(text(),'pic.twitter.com/')]";
        $node_list = $xpath->query($item, $basenode);
        if(!$node_list || $node_list->length === 0){
            return;
        }
        $add_urls = [];
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
            foreach($urls as $url){
                if(in_array($url, $add_urls) === true){
                    continue;
                }
                $add_urls[] = $url;
                $this->append_img_tag($doc, $node, $url);
            } 
        }
//        $this->__debug($add_urls);
    }

    function append_img_tag($doc, $node, $url){
        $img = $doc->createElement('img','');
        $img->setAttribute('src', $url);
        $node->parentNode->insertBefore($img, $node->nextSibling);
    }

    function get_pic_links(string $url){
        $html = $this->get_html($url, array());
        $doc = new DOMDocument();
        @$doc->loadHTML($html);

        if(!$doc){
            return array();
        }
        $xpath = new DOMXPath($doc);
        $entries = $xpath->query("(//meta[@property='og:image'])");
        if($entries->length == 0) {
            return array();
        }
        $urls = array();
        foreach ($entries as $entry) {
            $urls[] = $xpath->evaluate('string(@content)', $entry);
        }
        return array_unique($urls);
    }

    function default_cleanup($xpath, $basenode){
        if(!$basenode){
            return;
        }

        $default_cleanup = [
            "script[contains(@src,'ead2.googlesyndication.com/pag') or contains(text(),'adsbygoogle')]",
            "ins[contains(@class,'adsbygoogle')]",
            "div[@class='wp_social_bookmarking_light' or contains(@class,'e-adsense') or @id='my-footer' or @class='ninja_onebutton' or @class='social4i' or @class='yarpp-related' or @id='ads' or contains(@class,'fc2_footer')]",
            "a[contains(@href,'//px.a8.net/')]"
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

    function cleanup($xpath, $basenode, $config_cleanup){
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

    function update_tags($basenode, $link, $tag, $attr){
        if(!$basenode){
            return;
        }
        $img_list = $basenode->getElementsByTagName($tag);
        if($img_list->length == 0){
            return;
        }
        foreach($img_list as $node){
            $src = $node->getAttribute($attr);
            if(substr($src,0,2) == "//"){
                $url_item = parse_url($link);
                $src = $url_item['scheme'].':'.$src;
                $node->setAttribute($attr, $src);
            }else if(substr($src,0,1) == "/"){
                $url_item = parse_url($link);
                $src = $url_item['scheme'].'://'.$url_item['host'].$src;
                $node->setAttribute($attr, $src);
            }else if(substr($src, 0,4) != "http"){
                $pos = strrpos($link, "/");
                if($pos){
                    $src = substr($link, 0, $pos+1).$src;
                    $node->setAttribute($attr, $src);
                }
            }
        }
    }

    function get_replace_img(DOMNode $node) : string {
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
    function update_img_src(DOMNode $node, string $link) : string{
        $src = $node->getAttribute('src');
        if(substr($src,0,2) == "//"){
            $url_item = parse_url($link);
            $src = $url_item['scheme'].':'.$src;
        }else if(substr($src,0,1) == "/"){
            $url_item = parse_url($link);
            $src = $url_item['scheme'].'://'.$url_item['host'].$src;
        }else if(substr($src, 0,4) != "http"){
            $pos = strrpos($link, "/");
            if($pos){
                $src = substr($link, 0, $pos+1).$src;
            }
        }
        return $src;
    }
    function update_img_tags($basenode, $link){
        if(!$basenode){
            return;
        }
        if($basenode->nodeName == 'img'){
            $original = $this->get_replace_img($basenode);
            if ($original) {
                $basenode->setAttribute('src', $original);
            }
            $basenode->setAttribute('src', $this->update_img_src($basenode, $link));
            return;
        }

        $img_list = $basenode->getElementsByTagName('img');
        if($img_list->length == 0){
            return;
        }
        foreach($img_list as $node){
            // update src
            $original = $this->get_replace_img($node);
            if ($original) {
                $node->setAttribute('src', $original);
            }
            $node->setAttribute('src', $this->update_img_src($node, $link));
        }
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

    $this->host->set($this, 'json_conf', $json_conf);
    echo __("Configuration saved.");
}

}
