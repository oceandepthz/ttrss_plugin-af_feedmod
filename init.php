<?php
//date_default_timezone_set('Asia/Tokyo');

define("USER_AGENT_FEEDMOD", "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/146.0.0.0 Safari/537.36 Edg/146.0.0.0");
ini_set('user_agent', USER_AGENT_FEEDMOD);

spl_autoload_register(function ($class) {
    $file = __DIR__ . "/classes/" . str_replace("\\", "/", $class) . ".php";
    if (file_exists($file)) {
        require_once $file;
    }
});

class Af_Feedmod extends Plugin implements IHandler
{
    /** @var PluginHost */
    private $host;

    function about()
    {
        return [1.0, 'Replace feed contents by contents from the linked page', 'mbirth'];
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

    function csrf_ignore($method) : bool
    {
        $csrf_ignored = ["index", "edit", "save"];
        return array_search($method, $csrf_ignored) !== false;
    }

    function before($method) : bool
    {
        if (isset($_SESSION["uid"]) && $_SESSION["uid"]) return true;
        return false;
    }

    function after() : bool { return true; }

    function get_json_conf() : string {
        $json_file_name = __DIR__."/site_conf.json";
        if(file_exists($json_file_name)){
            if(strtotime("+10 minute", filemtime($json_file_name)) > time()){
                return file_get_contents($json_file_name);
            }
        } 
        $host = $this->host ?? PluginHost::getInstance();
        $json_conf = $host->get($this, 'json_conf');
        if ($json_conf) file_put_contents($json_file_name, $json_conf, LOCK_EX);
        return $json_conf ?? "";
    }

    function write_url(string $url, string $urlpart) : void {
        $dt = date("Y-m-d H:i:s") . "." . substr(explode(".", (microtime(true) . ""))[1] ?? "000", 0, 3);
        @file_put_contents(__DIR__.'/logs/site.txt', "[${dt}] ${url} ${urlpart}\n", FILE_APPEND|LOCK_EX);
    }

    function write_chrome_url(string $url) : void {
        $dt = date("Y-m-d H:i:s") . "." . substr(explode(".", (microtime(true) . ""))[1] ?? "000", 0, 3);
        @file_put_contents(__DIR__.'/logs/site_chrome.txt', "[${dt}] ${url}\n", FILE_APPEND|LOCK_EX);
    }

    function write_url_containsJapanese(string $url, bool $containsJapanese, string $str) : void {
        $cjs = json_encode($containsJapanese);
        $dt = date("Y-m-d H:i:s") . "." . substr(explode(".", (microtime(true) . ""))[1] ?? "000", 0, 3);
        @file_put_contents(__DIR__.'/logs/site_containsJapanese.txt', "[${dt}] ${url} ${cjs} ${str} \n", FILE_APPEND|LOCK_EX);
    }

    function write_url_log(string $url, string $str) : void {
        $dt = date("Y-m-d H:i:s") . "." . substr(explode(".", (microtime(true) . ""))[1] ?? "000", 0, 3);
        @file_put_contents(__DIR__.'/logs/url_log.txt', "[${dt}] ${url} ${str}\n", FILE_APPEND|LOCK_EX);
    }

    function hook_article_filter($article)
    {
        $dt = date("Y-m-d H:i:s");
        @file_put_contents(__DIR__.'/logs/article_filter_log.txt', "[{$dt}] START {$article['link']} (Feed: {$article['feed']['fetch_url']})\n", FILE_APPEND|LOCK_EX);

        $json_conf = $this->get_json_conf();
        $data = json_decode($json_conf, true);

        if (!is_array($data)) return $article;

        $is_execute = false;
        $is_hit_link = false;
        $hit_urlpart = '';

        if(isset($article['link']) && strpos($article['link'], '//') === 0){
            $article['link'] = 'http:'.$article['link'];
        }

        $article['link'] = UrlUtils::get_original_url($article['link']);

        if (preg_match('/\.pdf(\?.*)?$/i', $article['link'])) return $article;

        $handler = new ArticleHandler($this);
        $current_config = [];

        foreach ($data as $urlpart=>$config) {
            $match_type = $config['match_type'] ?? 'default';
            if($match_type === 'default'){
                if(strpos($article['link'], $urlpart) === false) continue;
            }elseif($match_type === 'fnmatch'){
                if(fnmatch($urlpart, $article['link']) === false) continue;
            }else continue;

            $hit_urlpart = $urlpart;
            $is_hit_link = true;
            $current_config = $config;

            $this->write_url_log($article['link'], 'hit: '.$hit_urlpart);

            if(isset($config['no_fetch']) && $config['no_fetch']){
                $is_execute = true;
                break;
            }

            $this->write_url($article['link'], $urlpart);

            $link = $this->replace_link(trim($article['link']), $config);
            $html = $this->get_html($link, $config);
            if(!$html) break;
            
            libxml_use_internal_errors(true);
            $doc = new DOMDocument();
            @$doc->loadHTML($html);
            libxml_clear_errors();
            $xpath = new DOMXPath($doc);
            
            $links = $this->get_np_links($xpath, $doc, $config, $link);
            $entrysXML = DomUtils::get_xpath_contents($doc, $xpath, $config['xpath'] ?? null);
            
            if(strlen($entrysXML) === 0) break;
            
            $is_execute = true;
            $article['content'] = $entrysXML;
            $method_label = "xpath:" . $hit_urlpart;

            $head_content = (isset($config['head_xpath']) && $config['head_xpath']) ? DomUtils::get_xpath_contents($doc, $xpath, $config['head_xpath']) : '';
            $foot_content = (isset($config['foot_xpath']) && $config['foot_xpath']) ? DomUtils::get_xpath_contents($doc, $xpath, $config['foot_xpath']) : '';

            foreach($links as $url){
                $phtml = $this->get_html($url, $config);
                if(!$phtml) break;
                libxml_use_internal_errors(true);
                $pdoc = new DOMDocument();
                @$pdoc->loadHTML($phtml);
                libxml_clear_errors();
                $pxpath = new DOMXPath($pdoc);
                $article['content'] .= DomUtils::get_xpath_contents($pdoc, $pxpath, $current_config['xpath'] ?? null);
            }
            $article['content'] = $head_content . $article['content'] . $foot_content;
            break;
        }

        if(!$is_execute){
            $content = $this->get_routine_content($article['link'], $current_config);
            if(strlen($content) > 0){
                $article['content'] = $content;
                $is_hit_link = true;
                $is_execute = true;
            }

            if(!$is_execute){
                $link = $this->replace_link(trim($article['link']), $current_config);    
                $this->writeLog($link, $is_hit_link, $hit_urlpart);
                $html_message = $this->get_html_graby($link);
                if(strlen($html_message) > 0){
                    $article['content'] = ($article['content'] ?? "")."<div>".$html_message."</div>";
                    $method_label = "graby";
                    $is_execute = true;
                }
            }
        }

        if($is_execute){
            $this->write_url_log($article['link'], 'is_execute: true');
            $link = $this->replace_link(trim($article['link']), $current_config);
            $article['content'] = $this->process_translations($article, $link);

            $content = mb_convert_encoding("<div>".$article['content']."</div>", 'HTML-ENTITIES', 'UTF-8');
            libxml_use_internal_errors(true);
            $doc = new DOMDocument();
            @$doc->loadHTML($content);
            libxml_clear_errors();
            if($doc){
                $xpath = new DOMXPath($doc);
                $entry = $doc->documentElement;
                
                DomUtils::cleanup($xpath, $entry, $current_config['cleanup'] ?? null);
                
                $handler->update_remote_src($entry, 'img');
                $handler->update_remote_src($entry, 'iframe');
                $handler->update_video_src($entry);
                $handler->update_srcset($entry, $link, 'img');
                $handler->update_srcset($entry, $link, 'source');

                foreach (['a' => 'href', 'iframe' => 'src', 'img' => 'src', 'source' => 'src'] as $tag => $attr) {
                    $handler->update_remote_file($entry, $link, $tag, $attr);
                }
                foreach (['poster', 'data-url', 'src'] as $attr) {
                    $handler->update_remote_file($entry, $link, 'video', $attr);
                }

                $handler->update_t_co($xpath, $entry);
                $handler->update_amzn_to($entry);
                $handler->sanitize_amazon($xpath, $entry);
                $handler->update_sqex_to($entry);
                $handler->update_twitter_tweet($doc, $xpath, $entry);
                $handler->update_video_twimg_com($doc, $xpath, $entry);
                $handler->update_pic_twitter_com($doc, $xpath, $entry, $link);
                $handler->update_iframe_youtube($doc, $xpath, $entry);
                $handler->update_peing_net($doc, $xpath, $entry, $link);
                $handler->update_img_link($doc, $xpath, $entry, $link);
                $handler->update_instagram($doc, $xpath, $entry, $link);
                if(strpos($link, '//jp.reuters.com/article/') !== false) $handler->update_jp_reuters_com($doc, $xpath, $entry);
                $handler->update_html_style($xpath, $entry, $link);
                $handler->update_tag($doc, $xpath, $entry);
                $handler->update_tag_lazy_image($doc, $xpath, $entry);
                $handler->change_attribute_value($doc, $xpath, $entry, "id", "container", "container_chg");
                $handler->change_attribute_value($doc, $xpath, $entry, "id", "main", "main_chg");
                $handler->update_img_proxy($xpath, $entry);

                $article['content'] = str_replace(["<html><body>","</body></html>"], "", $doc->saveHTML($entry));
            }

            if(isset($current_config['append_css'])){
                $css = is_array($current_config['append_css']) ? implode($current_config['append_css']) : $current_config['append_css'];
                $article['content'] .= "<style type='text/css'>${css}</style>";
            }

            if (isset($method_label)) {
                $dt_obj = new DateTime('now', new DateTimeZone('Asia/Tokyo'));
                $dt = $dt_obj->format("Y-m-d H:i:s");
                $article['content'] .= "<div style='font-size:8px;'>{$method_label} ({$dt})</div>";
            }
        }

        if(class_exists('HatebuUtils') && HatebuUtils::is_hatebu($article['feed']['fetch_url'] ?? "")) {
            $article['content'] .= HatebuUtils::get_hatebu_content($article['link']);
        }

        if(!empty($article['content'])) $article['content'] = DomUtils::fix_style_tags($article['content']);
        @file_put_contents(__DIR__.'/logs/article_filter_log.txt', "[".date("Y-m-d H:i:s")."] END {$article['link']}\n", FILE_APPEND|LOCK_EX);

        return $article;
    }

    private function process_translations($article, $link) {
        $translateString = '';
        $tjClasses = ['TranslateJapaneseGemini'];
        foreach($tjClasses as $tjClass) {
            if ($translateString) break;
            if (class_exists($tjClass)) {
                $tj = new $tjClass("<h2>".$article["title"]."</h2>".$article['content'], $link);
                if($tj->isTranslate()){
                    $this->write_url_containsJapanese($article['link'], true, $tjClass . ' start');
                    $this->write_url_log($link, $tjClass . ' start');
                    $translateString = $tj->translateString();
                    if ($translateString) {
                        $this->write_url_containsJapanese($link, true, $tjClass . ' length:'.strlen($translateString));
                        $this->write_url_log($link, $tjClass . ' length:'.strlen($translateString));
                        return $translateString."<hr>".$article['content'];
                    } else {
                        $this->write_url_containsJapanese($link, true, $tjClass . ' failed');
                        $this->write_url_log($link, $tjClass . ' failed');
                    }
                }
            }
        }
        return $article['content'];
    }

    function replace_link(string $link, array $config) : string {
        $link = trim($link);
        if(isset($config['replace_link'])){
            foreach($config['replace_link'] as $search => $replace) $link = str_replace($search, $replace, $link);
        }
        if(isset($config['rep_pattern']) && isset($config['rep_replacement'])){
            $rep_link = preg_replace($config['rep_pattern'], $config['rep_replacement'], $link);
            @file_put_contents(__DIR__.'/logs/replace_link.txt', date("Y-m-d H:i:s")."\t$link\t$rep_link\n", FILE_APPEND|LOCK_EX);
            $link = $rep_link;
        }
        return $link;
    }

    function get_contents(string $url, array $config) : string {
        $dt = date("Y-m-d H:i:s");
        @file_put_contents(__DIR__.'/logs/url_fetch.txt', "[{$dt}] get_contents START {$url}\n", FILE_APPEND|LOCK_EX);
        if (preg_match('/\.pdf(\?.*)?$/i', $url)) return "";

        // Check for special engines
        if(strpos($url, ".googleblog.com/") !== false || strpos($url, "//blog.chromium.org/") !== false) {
            if (class_exists('GoogleblogCom')) return (new GoogleblogCom())->get_content($url);
        }
        if(strpos($url, "//togetter.com/li/") !== false) return (new Togetter($url))->get_html();
        
        $engine = strtolower($config['engine'] ?? '');
        if($engine == 'phantomjs' && class_exists('PhantomJsWarpper')) return (new PhantomJsWarpper())->get_html($url);
        if(($engine == 'chromium' || strpos($url, "//twitter.com/i/events/") !== false) && class_exists('ChromeContent')) return (new ChromeContent($url))->get_content();

        if(class_exists('NhkContextFetcher') && NhkContextFetcher::IsNhkContext($url)) {
            $this->write_url_log($url, 'NhkContext');
            return (new NhkContextFetcher($url))->Fetch();
        }
        if(class_exists('QiitaContextFetcher') && QiitaContextFetcher::IsQiitaContext($url)) {
            $this->write_url_log($url, 'QiitaContext');
            return (new QiitaContextFetcher($url))->Fetch();
        }
        if(class_exists('NitterContents')) {
            $nitter = new NitterContents($url);
            if($nitter->isNitter()) return $nitter->getContent();
        }
        if(class_exists('PosfieCom')) {
            $posfie = new PosfieCom($url);
            if($posfie->is_posfie()) return $posfie->get_html();
        }

        $options = ["url"=>$url, "useragent" => USER_AGENT_FEEDMOD, "timeout" => 15];
        $ua = $config['user_agent'] ?? '';
        if($ua == "ie11") $options["useragent"] = 'Mozilla/5.0 (Windows NT 10.0; WOW64; Trident/7.0; rv:11.0) like Gecko';
        elseif($ua == "ie9") $options["useragent"] = 'Mozilla/5.0 (compatible; MSIE 9.0; Windows NT 6.0; Trident/5.0)';

        $r = class_exists('UrlHelper') ? UrlHelper::fetch($options) : (new FmUtils())->url_file_get_contents($url);
        @file_put_contents(__DIR__.'/logs/url_fetch.txt', "[".date("Y-m-d H:i:s")."] get_contents END {$url}\n", FILE_APPEND|LOCK_EX);
        return $r ?: "";
    }

    function get_html(string $url, array $config) : string {
        $html = $this->get_contents($url, $config);
        if(!$html) return "";
        $mb_conv_html = mb_convert_encoding($html, 'HTML-ENTITIES', 'ASCII, JIS, UTF-8, EUC-JP, SJIS');
        if(!$mb_conv_html) return $html;
        $patterns = ['/<script.*?>.*?<\/script>/ims', '/<noscript.*?>.*?<\/noscript>/ims'];
        foreach($patterns as $p) $mb_conv_html = preg_replace($p, '', $mb_conv_html);
        return $mb_conv_html;
    }

    function get_np_links(DOMXPath $xpath, DOMDocument $doc, array $config, string $link) : array {
        if(strpos($link, '//russia2018.yahoo.co.jp/') !== false) return $this->get_np_yahoo($doc, $link);
        if(strpos($link, '//www.newsweekjapan.jp/') !== false) return $this->get_np_newsweek($doc, $link);
        
        $np_xpath = $config['next_page'] ?? $config['next_page_xpath'] ?? null;
        if(!$np_xpath) return [];

        $entries = $xpath->query('(//'.$np_xpath.')');
        if (!$entries || $entries->length === 0) return [];
        $basenode = $entries->item(0);

        $links = [];
        foreach ($basenode->getElementsByTagName('a') as $node) {
            $href = $node->getAttribute('href');
            if(!$href || strpos($href, '#') === 0) continue;
            $abs = DomUtils::update_absolute_url($link, $href);
            if($link !== $abs) $links[] = $abs;
        }
        return array_unique($links);
    }

    private function get_np_yahoo($doc, $link) {
        $xpath = new DOMXPath($doc);
        $nodes = $xpath->query("//ul[@class='sn-pagination__list']//span[contains(@class,'sn-pagination__number--duration')]");
        if(!$nodes || $nodes->length === 0) return [];
        $n = intval($nodes->item(0)->nodeValue);
        $links = [];
        for($i = 2; $i <= $n; $i++) $links[] = $link."?p=".$i;
        return $links;
    }

    private function get_np_newsweek($doc, $link) {
        $xpath = new DOMXPath($doc);
        $nodes = $xpath->query("//div[@class='entryPagenate']/ul/li/a[contains(@href,'_$i.php')]");
        if(!$nodes || $nodes->length === 0) return [];
        $u = str_replace('.php', '', $link);
        $links = [];
        for($i = 2; $i <= $nodes->length + 1; $i++) $links[] = "${u}_${i}.php";
        return array_unique($links);
    }

    function get_routine_content(string $url, array $config) : string {
        $html = $this->get_html($url, []);
        if(!$html) return "";
        libxml_use_internal_errors(true);
        $doc = new DOMDocument();
        @$doc->loadHTML($html);
        libxml_clear_errors();
        $xpath = new DOMXPath($doc);

        if ($xpath->query("//html[@data-admin-domain='//blog.hatena.ne.jp']")->length > 0){
            $c = DomUtils::get_xpath_contents($doc, $xpath, "div[contains(@class,'entry-content')]");
            if($c) return $c."<style type='text/css'>div.entry-content > div.embed-responsive { padding-bottom:0!important; }</style><div style='font-size:8px;'>hatena</div>";
        }
        if ($xpath->query("//head/meta[@property='og:site_name']")->length > 0){
            $c = DomUtils::get_xpath_contents($doc, $xpath, "figure[@class='o-noteEyecatch']|//div[@data-name='body']");
            if($c) return $c."<style type='text/css'>div.entry-content > div.embed-responsive { padding-bottom:0!important; }</style><div style='font-size:8px;'>note</div>";
        }
        if(strpos($url, 'togetter.com') !== false) return (new Togetter($url))->get_html();
        if(strpos($url, 'twitter.com') !== false || strpos($url, 'x.com') !== false) return (new TwitterContents($url))->getContents();
        return "";
    }

    function get_html_graby(string $url) : string {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_NOBODY, true);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_MAXREDIRS, 3);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        curl_setopt($ch, CURLOPT_USERAGENT, USER_AGENT_FEEDMOD);
        curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $type = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        $len = curl_getinfo($ch, CURLINFO_CONTENT_LENGTH_DOWNLOAD);
        curl_close($ch);

        if ($http_code >= 400 || stripos($type, 'application/pdf') !== false || (int)$len > 10 * 1024 * 1024) return "";

        return (new GrabyWarpper())->get_html($url);
    }

    function get_redirect_url(string $url): string {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_NOBODY, true);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HEADER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        curl_setopt($ch, CURLOPT_USERAGENT, USER_AGENT_FEEDMOD);
        curl_exec($ch);
        $redir = curl_getinfo($ch, CURLINFO_REDIRECT_URL);
        curl_close($ch);
        return $redir ?: $url;
    }

    function writeLog(string $url, bool $is_hit_link, string $hit_urlpart = '') : void {
        $up = parse_url($url);
        if(($up['path'] ?? '') == '/' && ($up['query'] ?? '') == '') return;
        $dt = date("Y-m-d H:i:s");
        $host = $up["host"] ?? "";
        $suffix = $is_hit_link ? "xpath:".$hit_urlpart : "";
        @file_put_contents(__DIR__.'/af_feed_no_entry.txt', "$dt\t$host\t$url\t$suffix\n", FILE_APPEND|LOCK_EX);
    }

    function hook_prefs_tabs() : void {
        print '<div id="feedmodConfigTab" dojoType="dijit.layout.ContentPane" href="backend.php?op=af_feedmod" title="' . __('FeedMod') . '"></div>';
    }

    function index() {
        $pluginhost = $this->host ?? PluginHost::getInstance();
        $json_conf = $pluginhost->get($this, 'json_conf');
        print "<form dojoType=\"dijit.form.Form\">";
        print "<script type=\"dojo/method\" event=\"onSubmit\" args=\"evt\">
            evt.preventDefault();
            if (this.validate()) {
                new Ajax.Request('backend.php', {
                    parameters: dojo.objectToQuery(this.getValues()),
                    onComplete: function(transport) {
                        Notify.info(transport.responseText);
                    }
                });
            }
        </script>";
        print "<input dojoType=\"dijit.form.TextBox\" style=\"display : none\" name=\"op\" value=\"pluginhandler\">";
        print "<input dojoType=\"dijit.form.TextBox\" style=\"display : none\" name=\"method\" value=\"save\">";
        print "<input dojoType=\"dijit.form.TextBox\" style=\"display : none\" name=\"plugin\" value=\"af_feedmod\">";
        print "<table width='100%'><tr><td><textarea dojoType=\"dijit.form.SimpleTextarea\" name=\"json_conf\" style=\"font-size: 12px; width: 99%; height: 500px;\">" . htmlspecialchars($json_conf) . "</textarea></td></tr></table>";
        print "<p><button dojoType=\"dijit.form.Button\" type=\"submit\">".__("Save")."</button></form>";
    }

    function save() {
        $json_conf = $_POST['json_conf'] ?? "";
        @file_put_contents(__DIR__.'/logs/save_log.txt', date("Y-m-d H:i:s")." save attempt\n", FILE_APPEND|LOCK_EX);
        if (is_null(json_decode($json_conf))) {
            @file_put_contents(__DIR__.'/logs/save_log.txt', date("Y-m-d H:i:s")." Invalid JSON\n", FILE_APPEND|LOCK_EX);
            echo __("error: Invalid JSON!");
            return;
        }
        $pluginhost = $this->host ?? PluginHost::getInstance();
        $pluginhost->set($this, 'json_conf', $json_conf);
        
        // 即座にファイルキャッシュを更新
        file_put_contents(__DIR__."/site_conf.json", $json_conf, LOCK_EX);
        
        @file_put_contents(__DIR__.'/logs/save_log.txt', date("Y-m-d H:i:s")." Configuration saved success\n", FILE_APPEND|LOCK_EX);
        echo __("Configuration saved.");
    }
}
