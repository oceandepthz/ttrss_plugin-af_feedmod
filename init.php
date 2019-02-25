<?php
//date_default_timezone_set('Asia/Tokyo');

ini_set('user_agent', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/64.0.3282.140 Safari/537.36 Edge/17.17134');

class Af_Feedmod extends Plugin implements IHandler
{
    private $host;

    function about()
    {
        return [
                1.0,   // version
                'Replace feed contents by contents from the linked page',   // description
                'mbirth',   // author
                false,   // is_system
                ];
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
        $csrf_ignored = ["index", "edit"];
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

    function get_json_conf() : string {
        $json_file_name = __DIR__."/site_conf.json";
        if(file_exists($json_file_name)){
            $file_after_ten_min = strtotime("+5 minute", filemtime($json_file_name));
            if($file_after_ten_min > time()){
                return file_get_contents($json_file_name);
            }
        } 
        $json_conf = preg_replace("/\r\n|\r|\n/", "\n", gzuncompress(base64_decode($this->host->get($this, 'json_conf'))));
        //$json_conf = $this->host->get($this, 'json_conf');
        file_put_contents($json_file_name, $json_conf, LOCK_EX);
        return $json_conf;
    }

    function hook_article_filter($article)
    {
        global $fetch_last_content_type;

        $json_conf = $this->get_json_conf();
        $owner_uid = $article['owner_uid'];
        $data = json_decode($json_conf, true);

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
        require_once("classes/UrlUtils.php");
        $article['link'] = UrlUtils::get_original_url($article['link']);

        foreach ($data as $urlpart=>$config) {
            if(fnmatch('*//*/*.pdf', $article['link'])){
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
            $entrysXML = $this->get_xpath_contents($doc, $xpath, $config['xpath']);
            if(strlen($entrysXML) === 0){
                break;
            }
            $is_execute = true;
            $article['content'] = $entrysXML;
            $article['plugin_data'] = "feedmod,$owner_uid:" . $article['plugin_data'];

            $head_content = '';
            if(isset($config['head_xpath']) && $config['head_xpath']){
                $head_content = $this->get_xpath_contents($doc, $xpath, $config['head_xpath']);
            }
            $foot_content = '';
            if(isset($config['foot_xpath']) && $config['foot_xpath']){
                $foot_content = $this->get_xpath_contents($doc, $xpath, $config['foot_xpath']);
            }

            foreach($links as $url){
                $html = $this->get_html($url, $config);
                @$doc->loadHTML($html);

                if(!$doc){
                    break;
                }

                $xpath = new DOMXPath($doc);
                $article['content'] .= $this->get_xpath_contents($doc, $xpath, $config['xpath']);

                if(isset($config['head_xpath']) && $config['head_xpath'] && strlen($head_content) === 0){
                    $head_content = $this->get_xpath_contents($doc, $xpath, $config['head_xpath']);
                }
                if(isset($config['foot_xpath']) && $config['foot_xpath'] && strlen($foot_content) === 0 ){
                    $foot_content = $this->get_xpath_contents($doc, $xpath, $config['foot_xpath']);
                }
            }
            $article['content'] = $head_content . $article['content'] . $foot_content;
            break;   // if we got here, we found the correct entry in $data, do not process more
        }

        if($is_execute){
            $article['content'] = $article['content']."<div style='font-size:8px;'>xpath:".$urlpart."</div>";
        } else {
            // hatena content
            $content = $this->get_routine_content($article['link']);
            if(strlen($content) > 0){
                $article['content'] = $content;
                $is_hit_link = true;
                $is_execute = true;
            }

            // graby
            if(!$is_execute){
                $link = $article['link'];
                $this->writeLog($link,$is_hit_link,$hit_urlpart);

                $html_message = $this->get_html_graby($link);
                if(strlen($html_message) > 0){
                    $article['content'] = $article['content']."<div>".$html_message."</div><div style='font-size:8px;'>graby</div>";
                    $is_execute = true;
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

                $this->update_remote_src($entry, 'img');
                $this->update_remote_src($entry, 'iframe');

                $this->update_remote_file($entry, $link, "a", "href");
                $this->update_remote_file($entry, $link, "iframe", "src");
                $this->update_remote_file($entry, $link, "img", "src");

                $this->update_t_co($doc, $xpath, $entry, $link);
                $this->update_amzn_to($doc, $xpath, $entry, $link);
                $this->sanitize_amazon($doc, $xpath, $entry, $link);
                $this->update_sqex_to($doc, $xpath, $entry, $link);
                $this->update_pic_twitter_com($doc, $xpath, $entry, $link);
                $this->update_peing_net($doc, $xpath, $entry, $link);
                $this->update_img_link($doc, $xpath, $entry, $link);
                $this->update_instagram($doc, $xpath, $entry, $link);
                if(strpos($link, '//jp.reuters.com/article/') !== false){
                    $this->update_jp_reuters_com($doc, $xpath, $entry);
                }
                $this->update_html_style($xpath, $entry, $link);
                $this->update_tag($doc, $xpath, $entry, $link);
                $this->update_tag_lazy_image($doc, $xpath, $entry, $link);

                $article['content'] = str_replace(["<html><body>","</body></html>"],"",$doc->saveXML($entry));
            }

            // add css
            if(isset($config['append_css']) && $config['append_css']){
                $css = '';
                if(is_array($config['append_css'])){
                    $css = implode($config['append_css']);
                } else {
                    $css = $config['append_css'];
                }
                $article['content'] .= "<style type='text/css'>${css}</style>";
            }
        }

        // add hatebu comment
        require_once('classes/HatebuUtils.php');
        if(HatebuUtils::is_hatebu($article['feed']['fetch_url'])){
            $article['content'] .= HatebuUtils::get_hatebu_content($article['link']);
        }
        
        return $article;
    }

    function get_xpath_contents(DOMDocument $doc, DOMXPath $xpath, string $query_xpath): string {
        $entries = $xpath->query("(//${query_xpath})");
        if ($entries->length == 0) {
            return '';
        }
        $contents = '';
        foreach ($entries as $entry) {
            if ($entry) {
                $contents .= $doc->saveXML($entry);
            }
        }
        return $contents;
    }

    function get_redirect_url(string $url): string {
        if(strpos($url, '//t.co/') !== false){
            $html = $this->get_html($url, []);
            $ret = preg_match('/.*<title>(.*)<\/title>.*/', $html, $matches);
            if(count($matches) == 2){
                return $matches[1];
            }
            return $url;
        }

        $header = get_headers($url, true);
        if(isset($header['Location'])){
            $org_url = $header['Location'];
            if(is_array($org_url)){
                $org_url = end($org_url);
            }
            return $org_url;
        }
        return $url;
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

    function __debug_tm($v) {
        $dt = date("Y-m-d H:i:s") . "." . substr(explode(".", (microtime(true) . ""))[1], 0, 3);
        $um = memory_get_usage() / (1024 * 1024)."MB";
        $this->__debug("[${dt}][${um}]:${v}");
    }

    function __debug($v){
        file_put_contents(dirname(__FILE__).'/logs/debug.txt', print_r($v, true)."\n", FILE_APPEND|LOCK_EX);
    }

    function get_routine_content(string $url) : string {
        $html = $this->get_html($url, []);
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
        foreach(['index.html','index.htm','index.php'] as $p){
            if($up['path'] == '/'.$p){
                return;
            }
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
        file_put_contents(dirname(__FILE__).'/logs/replace_link.txt', date("Y-m-d H:i:s")."\t$link\t$rep_link\n", FILE_APPEND|LOCK_EX);
        return $rep_link;
    }

    function get_html_graby(string $url) : string {
        require_once('classes/GrabyWarpper.php');
        $graby = new GrabyWarpper();
        return $graby->get_html($url);
    }
    function get_html_pjs(string $url) : string {
        file_put_contents(dirname(__FILE__).'/logs/af_feed_phantomjs.txt', date("Y-m-d H:i:s")."\t".$url."\n", FILE_APPEND|LOCK_EX);
        require_once('classes/PhantomJsWarpper.php');
        $pjs = new PhantomJsWarpper();
        return $pjs->get_html($url);
    }
    function get_html_chrome(string $url) : string {
        file_put_contents(dirname(__FILE__).'/logs/af_feed_chromium.txt', date("Y-m-d H:i:s")."\t".$url."\n", FILE_APPEND|LOCK_EX);
        require_once('classes/Chrome.php');
        $ch = new Chrome();
        return $ch->get_html($url);
    }
    function get_html_chromium(string $url) : string {
        file_put_contents(dirname(__FILE__).'/logs/af_feed_chromium.txt', date("Y-m-d H:i:s")."\t".$url."\n", FILE_APPEND|LOCK_EX);
        require_once('classes/Chromium.php');
        $ch = new Chromium();
        return $ch->get_html($url);
    }
    function get_html_togetter(string $url) : string {
        file_put_contents(dirname(__FILE__).'/logs/af_feed_togetter.txt', date("Y-m-d H:i:s")."\t".$url."\n", FILE_APPEND|LOCK_EX);
        require_once('classes/Togetter.php');
        $to = new Togetter();
        return $to->get_html($url);
    }
    function get_html_note_mu(string $url) : string {
        file_put_contents(dirname(__FILE__).'/logs/af_feed_note_mu.txt', date("Y-m-d H:i:s")."\t".$url."\n", FILE_APPEND|LOCK_EX);
        $json_url = $this->get_note_mu_json_url($url);

        require_once('classes/FmUtils.php');
        $u = new FmUtils();
        $json = $u->url_file_get_contents($json_url);

        $json = json_decode($json, true);

        $eye = $json["data"]["eyecatch"];
        $title = $json["data"]["tweet_text"];
        $content = $json["data"]["body"];
        $pictures = $json["data"]["pictures"];

        $html = "<!DOCTYPE html><html><head><meta charset='utf-8'></head><body><main>";
        if(strlen($eye)){
          $html .= "<img src='${eye}' class='eyecatch' style='width:480px;'>";
        }
        $html .= "<h1>${title}</h1>";
        if(strlen($content)){
          $html .= "<article>${content}</article>";
        }
        if(is_array($pictures) && count($pictures) > 0){
          foreach($pictures as $picture){
            $caption = $picture["caption"];
            $picture_url = $picture["url"];
            $html .= "<div style='margin-bottom:12px' class='picture'><img src='${picture_url}' style='width:480px;'><p>${caption}</p></div>";
          }
        }
        $html .= "</main></body></html>";
        return $html;
    }
    function get_html_jp_reuters_com(string $url) : string {
        file_put_contents(dirname(__FILE__).'/logs/af_feed_jp_reuters_com.txt', date("Y-m-d H:i:s")."\t".$url."\n", FILE_APPEND|LOCK_EX);

        require_once('classes/FmUtils.php');
        $u = new FmUtils();
        $html = $u->url_file_get_contents($url);

        $html = mb_convert_encoding($html, 'HTML-ENTITIES', 'ASCII, JIS, UTF-8, EUC-JP, SJIS');
        $doc = new DOMDocument();
        @$doc->loadHTML($html);
        $xpath = new DOMXPath($doc);
        $entries = $xpath->query("(//script[contains(text(),'window.RCOM_Data')])");
        $entry = $entries->item(0);

        $item = $entry->textContent;
        $item = str_replace('window.RCOM_Data = ','',$item);
        $item = rtrim($item ,";");
        $item = json_decode($item, true);

        $article_list = [];
        foreach($item as $k => $v){
            if(strpos($k, 'article_list_') === 0){
                $article_list = $v;
                break;
            }
        }
        if(count($article_list) == 0){
            return "";
        }
        $first_article = [];
        foreach($article_list as $k => $v){
            if($k == 'first_article'){
                $first_article = $v;
                break;
            }
        }
        if(count($first_article) == 0){
            return "";
        }

        $body = '';
        if(array_key_exists('body', $first_article)){
            $body = html_entity_decode($first_article['body']);
        }

        $image_url = '';
        $image_caption = '';
        if(array_key_exists('images', $first_article) && array_key_exists(0, $first_article['images']) ){
            if(array_key_exists('url', $first_article['images'][0])){
                $image_url = $first_article['images'][0]['url'];
            }
            if(array_key_exists('caption', $first_article['images'][0])){
                $image_caption = html_entity_decode($first_article['images'][0]['caption']);
            }
        }
        return "<!DOCTYPE html><html><head><meta charset='utf-8'></head><body><main><img src='${image_url}' class='eyecatch' style='width:480px;'><p>${image_caption}</p><article>${body}</article></main></body></html>";
    }
    function get_note_mu_json_url(string $url) : string {
        $pattern = '/^https:\/\/note\.mu\/.*\/n\/(.*)$/';
        if(strpos($url, '//note.mu/') === false){
            $pattern = '/^https:\/\/.*\/n\/(.*)$/';
        }
        preg_match($pattern, $url, $match);
        if(count($match) == 2){
            $key = $match[1];
            return "https://note.mu/api/v1/notes/${key}";
        }
        return "";
    }
    function get_googleblog_com(string $url) : string {
        file_put_contents(dirname(__FILE__).'/logs/af_feed_googleblog.txt', date("Y-m-d H:i:s")."\t".$url."\n", FILE_APPEND|LOCK_EX);
        require_once('classes/GoogleblogCom.php');
        $g = new GoogleblogCom();
        return $g->get_content($url);
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
    function is_note_mu(string $url, array $config) : bool {
        if(strpos($url, "//note.mu/") !== false){
            return true;
        }
        if(!isset($config['engine'])){
            return false;
        }
        return strtolower($config['engine']) == 'note_mu';
    }
    function is_jp_reuters_com(string $url) : bool {
        if(strpos($url, "//jp.reuters.com/") !== false){
            return true;
        }
        return false;
    }
    function is_togetter_com(string $url) : bool {
        if(strpos($url, "//togetter.com/li/") !== false){
            return true;
        }
        return false;
    }
    function is_googleblog_com(string $url) : bool {
        if(strpos($url, ".googleblog.com/") !== false){
            return true;
        }
        if(strpos($url, "//blog.chromium.org/") !== false){
            return true;
        }
        return false;
    }
    function get_contents(string $url, array $config) : string {
        if($this->is_googleblog_com($url)) {
            return $this->get_googleblog_com($url);
        } elseif($this->is_jp_reuters_com($url)) {
            return $this->get_html_jp_reuters_com($url);
        } elseif($this->is_togetter_com($url)){
            return $this->get_html_togetter($url);
        } elseif($this->is_note_mu($url,$config)){
            return $this->get_html_note_mu($url);
        } elseif($this->is_pjs($config)){
            return $this->get_html_pjs($url);
        } elseif ($this->is_chromium($config)){
            return $this->get_html_chrome($url);
        } else {
            $r = fetch_file_contents($url);
/*
            require_once('classes/FmUtils.php');
            $u = new FmUtils();
            $r = $u->url_file_get_contents($url);
*/
            return $r ? $r : "";
        }
    }
    function get_html(string $url, array $config) : string {
        $html = $this->get_contents($url, $config);
        if(!$html || $html == ''){
            sleep(30);
            $html = $this->get_contents($url, $config);
            if(!$html || $html == ''){
                sleep(30);
                $html = $this->get_contents($url, $config);
            }
        }
        if(!$html){
            return $html;
        }

        return mb_convert_encoding($html, 'HTML-ENTITIES', 'ASCII, JIS, UTF-8, EUC-JP, SJIS');
    }

    // www.newsweekjapan.jp 向け
    function get_np_www_newsweekjapan_jp(DOMXPath $xpath, DOMDocument $doc, string $link) : array {
        $links = [];
        $nwxpath = new DOMXPath($doc);
        $nw = $nwxpath->query("//div[@class='entryPagenate']/ul/li/a[contains(@href,'_\$i.php')]");
        if($nw == false || $nw->length === 0){
            return [];
        }
        $u = str_replace('.php', '', $link);
        for($i = 0;$i < $nw->length;$i++){
            $page_num = $i+2;
            $page = "${u}_${page_num}.php";
            $links[] = $page;
        }
        return array_unique($links);
    }

    // russia2018.yahoo.co.jp 向け
    function get_np_links_russia2018_yahoo_co_jp(DOMXPath $xpath, DOMDocument $doc, string $link) : array {
        $links = [];
        $yxpath = new DOMXPath($doc);
        $ye = $yxpath->query("//ul[@class='sn-pagination__list']//span[contains(@class,'sn-pagination__number--duration')]");
        if($ye == false || $ye->length === 0){
            return [];
        }
        $ye = $ye->item(0);
        $n = intval($ye->nodeValue);
        for($i = 2; $i <= $n; $i++){
            $links[] = $link."?p=".$i;
        }
        return $links;
    }
    function get_np_links(DOMXPath $xpath, DOMDocument $doc, array $config, string $link) : array {
        $links = [];

        if(strpos($link, '//russia2018.yahoo.co.jp/') !== false){
            return $this->get_np_links_russia2018_yahoo_co_jp($xpath, $doc, $link);
        }
        if(strpos($link, '//www.newsweekjapan.jp/') !== false){
            return $this->get_np_www_newsweekjapan_jp($xpath, $doc, $link);
        }
        if(!isset($config['next_page']) || !$config['next_page']){
            return [];
        }
        $config_next_page = $config['next_page'];
        if(!$config_next_page){
            return [];
        }

        $next_page_xpath = new DOMXPath($doc);
        $next_page_entries = $next_page_xpath->query('(//'.$config['next_page'].')');
        if ($next_page_entries === false || $next_page_entries->length === 0){
            return [];
        }
        $next_page_basenode = $next_page_entries->item(0);
        if (!$next_page_basenode) {
            return [];
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
            return [];
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
            if(substr($next_page, 0, 2) == "//"){
                $url_item = parse_url($link);
                $next_page = $url_item['scheme'].':'.$next_page;
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

    function update_instagram_bq(DOMDocument $doc, DOMXPath $xpath, DOMElement $basenode, string $link): void {
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

    function update_instagram_tw(DOMDocument $doc, DOMXPath $xpath, DOMElement $basenode, string $link): void {
        $query = "(//a[contains(@data-expanded-url,'//www.instagram.com/p/')])";
        $nodelist = $xpath->query($query, $basenode);
        if(!$nodelist || $nodelist->length === 0){
            return;
        }
        foreach ($nodelist as $node) {
            $link = $xpath->evaluate('string(@data-expanded-url)',$node);
            if(strpos($link, 'https://www.instagram.com/p/') !== 0){
                continue;
            }
            $img_link = $this->get_instagram_img_url($link);
            if(!$img_link){
                continue;
            }
            if(strpos($img_link, ".jpg")){
                $this->append_img_tag($doc, $node, $img_link);
            }else if(strpos($img_link, ".mp4")){
                $this->append_videomp4_tag($doc, $node, $img_link);
            }
        }
    }

    function update_instagram_url(DOMDocument $doc, DOMXPath $xpath, DOMElement $basenode, string $link): void {
        $query = "(//a[contains(text(),'//www.instagram.com/p/') and contains(@href,'//www.instagram.com/p/')])";
        $nodelist = $xpath->query($query, $basenode);
        if(!$nodelist || $nodelist->length === 0){
            return;
        }
        foreach ($nodelist as $node) {
            $link = $xpath->evaluate('string(@href)',$node);
            if(strpos($link, 'https://www.instagram.com/p/') !== 0){
                continue;
            }
            $img_link = $this->get_instagram_img_url($link);
            if(!$img_link){
                continue;
            }
            if(strpos($img_link, ".jpg")){
                $this->append_img_tag($doc, $node, $img_link);
            }else if(strpos($img_link, ".mp4")){
                $this->append_videomp4_tag($doc, $node, $img_link);
            }
        }
    }

    function update_instagram(DOMDocument $doc, DOMXPath $xpath, DOMElement $basenode, string $link): void {
        if(!$basenode){
            return;
        }
        $this->update_instagram_bq($doc, $xpath, $basenode, $link);
        $this->update_instagram_tw($doc, $xpath, $basenode, $link);
        $this->update_instagram_url($doc, $xpath, $basenode, $link);
    }

    function get_instagram_img_url(string $url) : string {
        file_put_contents(dirname(__FILE__).'/logs/af_feed_instagram.txt', date("Y-m-d H:i:s")."\t".$url."\n", FILE_APPEND|LOCK_EX);
        require_once('classes/Instagram.php');
        $in = new Instagram();
        return $in->get_content($url);
    }

    function update_img_link(DOMDocument $doc, DOMXPath $xpath, DOMElement $basenode, string $link) : void {
        if(!$basenode){
            return;
        }
        $items = ["//a[contains(@href,'//i.imgur.com/') or contains(@href,'//imgur.com/')]"];
        foreach($items as $item){
            $node_list = $xpath->query($item, $basenode);
            if(!$node_list || $node_list->length === 0){
                continue;
            }
            foreach($node_list as $node){
                $url = $xpath->evaluate('string(@href)', $node);
                if(!$url){
                    continue;
                }
                $url_nl = $xpath->query("//img[contains(@src,'${url}')]", $basenode);
                if($url_nl && $url_nl->length > 0){
                    continue;
                }
                $this->append_img_tag($doc, $node, $url); 
            }
        }
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
            $this->__debug("peing.net url :${link}");
            $url = $this->get_peing_img_link($link);
            if(!$url){
                continue;
            }
            $this->__debug("peing.net img url :${url}");
            $this->append_img_tag($doc, $node, $url);
        }
    }
    function get_peing_img_link(string $link) : string {
        $html = $this->get_html($link, []);
        $doc = new DOMDocument();
        @$doc->loadHTML($html);

        if(!$doc){
            $this->__debug("peing.net img link loadHTML error");
            return "";
        }
        $xpath = new DOMXPath($doc);

        $entries = $xpath->query("(//div[@class='answer-box']//a[contains(@href,'.jpg')])");
        if($entries === false || $entries->length == 0) {
            $this->__debug("peing.net img link query error");
            return "";
        }
        return $xpath->evaluate('string(@src)', $entries->item(0));
    }

    function update_sqex_to(DOMDocument $doc, DOMXPath $xpath, DOMElement $basenode, string $link) : void {
        if(!$basenode){
            return;
        }
        $node_list = $xpath->query("(//a[contains(@href,'//sqex.to/')])", $basenode);
        foreach ($node_list as $node){
            if(!$node){
                continue;
            }
            $href = $node->getAttribute('href');
            if(!$href){
                continue;
            }
            $url = $this->get_redirect_url($href);
            if(!$url){
                $url = $href;
            }
            $node->setAttribute('href', $url);
        }
    }

    function update_amzn_to(DOMDocument $doc, DOMXPath $xpath, DOMElement $basenode, string $link) : void {
        if(!$basenode){
            return;
        }
        $node_list = $xpath->query("(//a[contains(@href,'//amzn.to/')])", $basenode);
        foreach ($node_list as $node){
            if(!$node){
                continue;
            }
            $href = $node->getAttribute('href');
            if(!$href){
                continue;
            }
            $url = $this->get_redirect_url($href);
            if(!$url){
                $url = $href;
            }
            $node->setAttribute('href', $url); 
        }
    }
    function sanitize_amazon(DOMDocument $doc, DOMXPath $xpath, DOMElement $basenode, string $link) : void {
        if(!$basenode){
            return;
        }
        $queries = ['//www.amazon.co.jp/','//amazon.jp/','//www.amazon.com/','//amazon.com/'];
        foreach ($queries as $query){
            $nodes = $xpath->query("(//a[contains(@href,'${query}')])", $basenode);
            foreach ($nodes as $node){
                if(!$node){
                    continue;
                }
                $href = $node->getAttribute('href');
                if(!$href){
                    continue;
                }
                $purl = parse_url($href);
                $path = explode('/',$purl['path']);
                $place = -1;
                foreach($path as $i=>$v){
                    if($this->strposa($v, ['ASIN','dp','product'])){
                        $place = $i;
                        break;
                    }
                }
                if($place >= 0){
                    $place++;
                    $href = "${purl['scheme']}://${purl['host']}/dp/${path[$place]}/";
                }
                $node->setAttribute('href', $href);
            }
        }
    }

    function update_t_co(DOMDocument $doc, DOMXPath $xpath, DOMElement $basenode, string $link) : void {
        if(!$basenode){
            return;
        }

        $node_list = $xpath->query("(//a[contains(text(),'//t.co/') or contains(@href,'//t.co/')])", $basenode);
        if(!$node_list || $node_list->length === 0){
            return;
        }
        foreach ($node_list as $node){
            if(!$node){
                continue;
            }
            $href = $node->getAttribute('href');
            if(!$href){
                continue;
            }
            $html = $this->get_html($href, []);
            preg_match('/.*<title>(.*)<\/title>.*/', $html, $matches);
            if(count($matches) == 2){
                $url = $matches[1];
                $node->nodeValue = $url;
                $node->setAttribute('href', $url);
            }
        }
    }

    function update_pic_twitter_com(DOMDocument $doc, DOMXPath $xpath, DOMElement $basenode, string $link) : void {
        if(!$basenode){
            return;
        }
        $exclusion_list = ['//togetter.com/','//kabumatome.doorblog.jp/','//twitter.com/'];
        foreach ($exclusion_list as $exclusion){
            if(strpos($link, $exclusion) !== false){
                return;
            }
        }

        $items = ["//a[contains(text(),'pic.twitter.com/') or contains(@href,'pic.twitter.com/')]", "//a[contains(@href,'//twitter.com/') and (contains(@href,'/photo/') or contains(@href,'/video/'))]"];
        foreach ($items as $item){
            $node_list = $xpath->query($item, $basenode);
            if(!$node_list || $node_list->length === 0){
                continue;
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
                        //$this->append_iframe_tag($doc, $node, 'https://pp.kozono.org?q='.urlencode($url));
                        $this->append_iframe_tag($doc, $node, $url);
                    } else {
                        $this->append_img_tag($doc, $node, $url);
                    }
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

    function append_img_tag(DOMDocument $doc, DOMElement $node, string $url, array $opt = null) : void {
        $img = $doc->createElement('img','');
        $img->setAttribute('src', $url);
        if($opt){
            foreach($opt as $k => $v){
                $img->setAttribute($k, $v);
            }
        }
        $node->parentNode->insertBefore($img, $node->nextSibling);
    }
    function append_videomp4_tag(DOMDocument $doc, DOMElement $node, string $url) : void {
        $video = $doc->createElement('video','');
        $video->setAttribute('src', $url);
        $video->setAttribute('type', 'video/mp4');
        $video->setAttribute('preload', 'none');
        $node->parentNode->insertBefore($video, $node->nextSibling);
    }

    function get_pic_links(string $url) : array {
        if(strpos($url, '//t.co/') !== false){
            $html = $this->get_html($url, []);
            $ret = preg_match('/.*<title>(.*)<\/title>.*/', $html, $matches);
            if(count($matches) == 2){
                $url = $matches[1];
            }
        }

        $html = $this->get_html($url, []);
        $doc = new DOMDocument();
        @$doc->loadHTML($html);
        if(!$doc){
            return [];
        }
        $xpath = new DOMXPath($doc);

        $urls = [];

        // video
        $entries = $xpath->query("(//meta[@property='og:video:url'])");
        if($entries !== false && $entries->length > 0) {
            $urls[] = str_replace("?embed_source=facebook", "", $xpath->evaluate('string(@content)', $entries->item(0)));
        }

        // image
        $entries = $xpath->query("(//meta[@property='og:image'])");
        if($entries === false || $entries->length == 0) {
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
            "div[@class='wp_social_bookmarking_light' or contains(@class,'e-adsense') or @id='my-footer' or @class='ninja_onebutton' or @class='social4i' or @class='yarpp-related' or @id='ads' or contains(@class,'fc2_footer') or @id='jp-post-flair']",
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

    function update_html_style(DOMXPath $xpath, DOMElement $basenode, string $link) : void {
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
            if(array_key_exists('background-image', $style)){
                preg_match('/^.*[\'\"](.*)[\'\"].*$/', $style['background-image'], $match);
                if(count($match) != 2){
                    continue;
                }
                $src = $match[1];
                $scheme = parse_url($link, PHP_URL_SCHEME);
                $host = parse_url($link, PHP_URL_HOST);
                if(!$scheme){
                    $scheme = 'http';
                }
                if(substr($src,0,2) == "//"){
                    $src = $scheme.':'.$src;
                }else if(substr($src,0,1) == "/"){
                    $src = $scheme.'://'.$host.$src;
                }else if(substr($src, 0,4) != "http"){
                    $pos = strrpos($link, "/");
                    if($pos){
                        $src = substr($link, 0, $pos+1).$src;
                    }
                }
                $style['background-image'] = "url('${src}')";
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
            $config_cleanup = [$config_cleanup];
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
        $attr_list = ['data-original', 'data-lazy-src', 'data-src', 'data-srcset', 'data-img-path', 'srcset',
            'ng-src', 'rel:bf_image_src', 'ajax', 'data-lazy-original', 'data-orig-file'];
        foreach($attr_list as $attr){
            if(!$node->hasAttribute($attr)){
                continue;
            }
            if($attr == 'srcset'){
                $url = explode(" ", explode(",", $node->getAttribute('srcset'))[0])[0];
            } else {
                $url = $node->getAttribute($attr);
            }
            if(strpos($url, 'data:image/') === 0){
                $url = '';
                continue;
            }
            if($url){
                break;
            }
        }
        return $url;
    }
    function update_tag(DOMDocument $doc, DOMXPath $xpath, DOMElement $basenode, string $link): void {
        $entries = $xpath->query("//div[contains(@class,'js-delayed-image-load')]");
        if($entries->length === 0){
            return;
        }
        foreach($entries as $entry){
            $this->append_iframe_tag($doc, $entry, $entry->getAttribute('data-src'));
        }
    }

    // logmi.jp
    function update_tag_lazy_image(DOMDocument $doc, DOMXPath $xpath, DOMElement $basenode, string $link): void {
        $entries = $xpath->query("//lazy-image");
        if($entries->length === 0){
            return;
        }
        foreach($entries as $entry){
            $src = $entry->getAttribute('src');
            $width = $entry->getAttribute('width');
            $height = $entry->getAttribute('height');
            if(!$src){
                continue;
            }
            $opt = [];
            if(!$width){
                $opt['width'] = $width;
            }
            if(!$height){
                $opt['height'] = $height;
            }
            $this->append_img_tag($doc, $entry, $src, $opt);
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
        $json_conf = gzuncompress(base64_decode($pluginhost->get($this, 'json_conf')));
        //$json_conf = $pluginhost->get($this, 'json_conf');
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
print "<textarea dojoType=\"dijit.form.SimpleTextarea\" class=\"json_conf\" name=\"json_conf\" style=\"font-size: 12px; width: 99%; height: 500px;\">$json_conf</textarea>";
print "</td></tr></table>";

print "<p><button dojoType=\"dijit.form.Button\" type=\"submit\">".__("Save")."</button>";

print "</form>";
print "<style type=\"text/css\">.json_conf::-webkit-scrollbar{width:16px;}</style>";
}

function save()
{
    $json_conf = $_POST['json_conf'];

    if (is_null(json_decode($json_conf))) {
        echo __("error: Invalid JSON!");
        return false;
    }

    //$json_conf = json_encode(json_decode($json_conf), JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES); // compress
    $this->host->set($this, 'json_conf', base64_encode(gzcompress($json_conf,1)));
    //$this->host->set($this, 'json_conf', $json_conf);
    echo __("Configuration saved.");
}

function jq_format($json) {
  $descriptorspec = [ 
     0 => ["pipe", "r"],
     1 => ["pipe", "w"],
  ];

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
