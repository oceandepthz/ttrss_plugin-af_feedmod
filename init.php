<?php
//date_default_timezone_set('Asia/Tokyo');

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
                    $this->cleanup($xpath, $entry, $config['cleanup']);
                    $this->update_img_tags($entry, $link);

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
                        $this->cleanup($xpath, $entry, $config['cleanup']);
                        $this->update_img_tags($entry, $link);

                        $entrysXML .= $doc->saveXML($entry);
                    }
                }
                $article['content'] .= $entrysXML;
            }
            break;   // if we got here, we found the correct entry in $data, do not process more
        }
        if(!$is_execute){
            $this->writeLog($article['link'],$is_hit_link);
        }
        return $article;
    }

    function writeLog($url,$is_hit_link){
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

    function replace_link($link, $config) : string {
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

    function get_html_pjs($url) : string {

        file_put_contents(dirname(__FILE__).'/af_feed_phantomjs.txt', date("Y-m-d H:i:s")."\t".$url."\n", FILE_APPEND|LOCK_EX);

        require_once('PhantomJsWarpper.php');
        $pjs = new PhantomJsWarpper();
        return $pjs->get_html($url);
    }

    function is_pjs($config) : bool {
        if(!isset($config['engine'])){
            return false;
        }
        return strtolower($config['engine']) == 'phantomjs';
    }
    
    function get_contents($url, $config){
        if($this->is_pjs($config)){
            return $this->get_html_pjs($url);
        }else{
            return fetch_file_contents($url);
        }
    }

    function get_html($url, $config){
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

    function get_np_links($xpath, $doc, $config, $link){
        $links = array();
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
                            $next_page = $link.$next_page;
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
                        $links[] = $next_page;
                    }
                    $links = array_unique($links);
                }
            }
        }
        return $links;
    }

    function cleanup($xpath, $basenode, $config_cleanup){
        if(!$basenode){
            return;
        }
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
            if(!$nodelist){
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

    function update_img_tags($basenode, $link){
        if(!$basenode){
            return;
        }
        $img_list = $basenode->getElementsByTagName('img');
        if($img_list->length == 0){
            return;
        }
        foreach($img_list as $node){
            $original = $node->getAttribute('data-original');
            if(!$original){
                $original = $node->getAttribute('data-lazy-src');
            }
            if(!$original){
                $original = $node->getAttribute('data-src');
            }
            if(!$original){
                $original = $node->getAttribute('data-img-path');
            }
            if(!$original){
                $original = explode(" ", explode(",", $node->getAttribute('srcset'))[0])[0];
            }
            if(!$original){
                $original = $node->getAttribute('ng-src');
            }
            if(!$original){
                $original = $node->getAttribute('rel:bf_image_src');
            }
            if(!$original){
                $original = $node->getAttribute('ajax');
            }
            if ($original) {
                $node->setAttribute('src', $original);
            }

            $src = $node->getAttribute('src');
            if(substr($src,0,2) == "//"){
                $url_item = parse_url($link);
                $src = $url_item['scheme'].':'.$src;
                $node->setAttribute('src', $src);
            }else if(substr($src,0,1) == "/"){
                $url_item = parse_url($link);
                $src = $url_item['scheme'].'://'.$url_item['host'].$src;
                $node->setAttribute('src', $src);
            }else if(substr($src, 0,4) != "http"){
                $pos = strrpos($link, "/");
                if($pos){
                    $src = substr($link, 0, $pos+1).$src;
                    $node->setAttribute('src', $src);
                }
            }

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
