<?php

class ArticleHandler {
    private $plugin;

    public function __construct($plugin) {
        $this->plugin = $plugin;
    }

    private function strposa(string $haystack, array $needles): bool {
        foreach ($needles as $needle) {
            if (strpos($haystack, $needle) !== false) {
                return true;
            }
        }
        return false;
    }

    private function tag_nodes(DOMElement $basenode, string $tag): array {
        if ($basenode->nodeName === $tag) {
            return [$basenode];
        }
        $list = [];
        foreach ($basenode->getElementsByTagName($tag) as $node) {
            $list[] = $node;
        }
        return $list;
    }

    public function update_remote_src(DOMElement $basenode, string $tag): void {
        if (!$basenode) {
            return;
        }
        foreach ($this->tag_nodes($basenode, $tag) as $node) {
            $original = DomUtils::get_replace_src($node);
            if ($original) {
                $node->setAttribute('src', $original);
            }
        }
    }

    public function update_video_src(DOMElement $basenode): void {
        if (!$basenode) {
            return;
        }
        foreach ($basenode->getElementsByTagName('video') as $node) {
            $src = $node->getAttribute('src');
            if ($src) {
                continue;
            }
            $dataurl = $node->getAttribute('data-url');
            if (!$dataurl) {
                continue;
            }
            $node->setAttribute('src', urldecode($dataurl));
        }
    }

    public function update_srcset(DOMElement $basenode, string $link, string $tag): void {
        if (!$basenode) {
            return;
        }
        $attr = 'srcset';
        foreach ($this->tag_nodes($basenode, $tag) as $node) {
            if (!$node->hasAttribute($attr)) {
                continue;
            }
            $srcset_value = $node->getAttribute($attr);
            if (!$srcset_value) {
                continue;
            }
            if (strpos($srcset_value, 'data:') === 0) {
                $node->removeAttribute($attr);
                continue;
            }
            $rval = [];
            foreach (explode(',', $srcset_value) as $source) {
                $parts = preg_split('/\s+/', trim($source), 2);
                $src = $parts[0];
                $pixel = isset($parts[1]) ? $parts[1] : '';
                $image_url = DomUtils::update_absolute_url($link, $src);
                $rval[] = trim("${image_url} ${pixel}");
            }
            if (count($rval) > 0) {
                $node->setAttribute($attr, implode(',', $rval));
            }
        }
    }

    public function update_remote_file(DOMElement $basenode, string $link, string $tag, string $attr): void {
        if (!$basenode) {
            return;
        }
        foreach ($this->tag_nodes($basenode, $tag) as $node) {
            $src = $node->getAttribute($attr);
            if (!$src) {
                continue;
            }
            $node->setAttribute($attr, DomUtils::update_absolute_url($link, $src));
        }
    }

    public function update_instagram(DOMDocument $doc, DOMXPath $xpath, DOMElement $basenode, string $link): void {
        if (!$basenode) {
            return;
        }
        $this->update_instagram_bq($doc, $xpath, $basenode, $link);
    }

    private function update_instagram_bq(DOMDocument $doc, DOMXPath $xpath, DOMElement $basenode, string $link): void {
        $query = "(//blockquote[@class='instagram-media'])";
        $nodelist = $xpath->query($query, $basenode);
        if ($nodelist->length === 0) {
            return;
        }
        foreach ($nodelist as $node) {
            $instagram_url = $xpath->evaluate('string(@data-instgrm-permalink)', $node);
            if (strpos($instagram_url, 'https://www.instagram.com/p/') !== 0 &&
                strpos($instagram_url, 'https://www.instagram.com/reel/') !== 0) {
                continue;
            }
            if (!class_exists('Bibliogram')) {
                continue;
            }
            $bibliogram = new Bibliogram($instagram_url);
            $html = $bibliogram->getInstagramHtml();
            if (!$html) {
                continue;
            }
            $html = mb_convert_encoding($html, 'HTML-ENTITIES', 'ASCII, JIS, UTF-8, EUC-JP, SJIS');
            libxml_use_internal_errors(true);
            $sdom = new DOMDocument();
            @$sdom->loadHTML($html);
            libxml_clear_errors();
            $sdom_xpath = new DOMXPath($sdom);
            $div = $sdom_xpath->query("//div[@class='instagram-media']")->item(0);
            while ($node->hasChildNodes()) {
                $node->removeChild($node->firstChild);
            }
            $result = $doc->importNode($div, true);
            $node->appendChild($result);
        }
    }

    public function update_img_link(DOMDocument $doc, DOMXPath $xpath, DOMElement $basenode, string $link): void {
        if (!$basenode) {
            return;
        }
        $items = ["//a[contains(@href,'//i.imgur.com/') or contains(@href,'//imgur.com/')]"];
        foreach ($items as $item) {
            $node_list = $xpath->query($item, $basenode);
            if (!$node_list || $node_list->length === 0) {
                continue;
            }
            foreach ($node_list as $node) {
                $url = $xpath->evaluate('string(@href)', $node);
                if (!$url) {
                    continue;
                }
                $url_nl = $xpath->query("//img[contains(@src,'${url}')]", $basenode);
                if ($url_nl && $url_nl->length > 0) {
                    continue;
                }
                DomUtils::append_img_tag($doc, $node, $url);
            }
        }

        $items = ["//blockquote[@class='imgur-embed-pub']"];
        foreach ($items as $item) {
            $node_list = $xpath->query($item, $basenode);
            if (!$node_list || $node_list->length === 0) {
                continue;
            }
            foreach ($node_list as $node) {
                $dataid = $xpath->evaluate('string(@data-id)', $node);
                if (!$dataid) {
                    continue;
                }
                DomUtils::append_img_tag($doc, $node, "https://i.imgur.com/${dataid}l.jpg");
            }
        }
    }

    public function update_peing_net(DOMDocument $doc, DOMXPath $xpath, DOMElement $basenode, string $link): void {
        if (!$basenode) {
            return;
        }
        $item = "//a[contains(@data-expanded-url,'//peing.net/ja/qs/')]";
        $node_list = $xpath->query($item, $basenode);
        if (!$node_list || $node_list->length === 0) {
            return;
        }
        foreach ($node_list as $node) {
            if (!$node) {
                continue;
            }
            $peing_link = $xpath->evaluate('string(@data-expanded-url)', $node);
            if (!$peing_link) {
                continue;
            }
            $entries = $this->get_peing_content($peing_link);
            if (!$entries) {
                continue;
            }
            foreach ($entries as $entry) {
                $newnode = $doc->importNode($entry, true);
                $node->parentNode->insertBefore($newnode, $node->nextSibling);
            }
        }
    }

    private function get_peing_content(string $link) {
        $html = $this->plugin->get_html($link, []);
        libxml_use_internal_errors(true);
        $doc = new DOMDocument();
        @$doc->loadHTML($html);
        libxml_clear_errors();
        if (!$doc) {
            return null;
        }
        $xpath = new DOMXPath($doc);
        $entries = $xpath->query("(//div[@class='answer-box']/div[@class='eye-catch-wrapper']//img|//div[@class='answer-box']/div[@class='answer'])");
        if ($entries === false || $entries->length == 0) {
            return null;
        }
        return $entries;
    }

    public function update_sqex_to(DOMElement $basenode): void {
        if (!$basenode) {
            return;
        }
        $xpath = new DOMXPath($basenode->ownerDocument);
        $node_list = $xpath->query("(//a[contains(@href,'//sqex.to/')])", $basenode);
        foreach ($node_list as $node) {
            if (!$node) {
                continue;
            }
            $href = $node->getAttribute('href');
            if (!$href) {
                continue;
            }
            $url = $this->plugin->get_redirect_url($href);
            if (!$url) {
                $url = $href;
            }
            $node->setAttribute('href', $url);
        }
    }

    public function update_amzn_to(DOMElement $basenode): void {
        if (!$basenode) {
            return;
        }
        $xpath = new DOMXPath($basenode->ownerDocument);
        $node_list = $xpath->query("(//a[contains(@href,'//amzn.to/')])", $basenode);
        foreach ($node_list as $node) {
            if (!$node) {
                continue;
            }
            $href = $node->getAttribute('href');
            if (!$href) {
                continue;
            }
            $url = $this->plugin->get_redirect_url($href);
            if (!$url) {
                $url = $href;
            }
            $node->setAttribute('href', $url);
        }
    }

    public function sanitize_amazon(DOMXPath $xpath, DOMElement $basenode): void {
        if (!$basenode) {
            return;
        }
        $queries = ['//www.amazon.co.jp/', '//amazon.jp/', '//www.amazon.com/', '//amazon.com/'];
        foreach ($queries as $query) {
            $nodes = $xpath->query("(//a[contains(@href,'${query}')])", $basenode);
            foreach ($nodes as $node) {
                if (!$node) {
                    continue;
                }
                $href = $node->getAttribute('href');
                if (!$href) {
                    continue;
                }
                $purl = parse_url($href);
                if (!isset($purl['path'])) {
                    continue;
                }
                $path = explode('/', $purl['path']);
                $place = -1;
                foreach ($path as $i => $v) {
                    if ($this->strposa($v, ['ASIN', 'dp', 'product'])) {
                        $place = $i;
                        break;
                    }
                }
                if ($place >= 0) {
                    $place++;
                    $scheme = $purl['scheme'] ?? 'https';
                    $host = $purl['host'] ?? '';
                    $href = "${scheme}://${host}/dp/${path[$place]}/";
                    $node->setAttribute('href', $href);
                }
            }
        }
    }

    public function update_t_co(DOMXPath $xpath, DOMElement $basenode): void {
        if (!$basenode) {
            return;
        }
        $node_list = $xpath->query("(//a[contains(text(),'//t.co/') or contains(@href,'//t.co/')])", $basenode);
        if (!$node_list || $node_list->length === 0) {
            return;
        }
        foreach ($node_list as $node) {
            if (!$node) {
                continue;
            }
            $href = $node->getAttribute('href');
            if (!$href) {
                continue;
            }

            $ch = curl_init($href);
            curl_setopt($ch, CURLOPT_NOBODY, true);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HEADER, true);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($ch, CURLOPT_MAXREDIRS, 3);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
            curl_setopt($ch, CURLOPT_TIMEOUT, 5);
            curl_setopt($ch, CURLOPT_USERAGENT, USER_AGENT_FEEDMOD);
            $header_data = curl_exec($ch);
            $url = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
            curl_close($ch);

            if ($header_data === false || !$url) {
                continue;
            }
            $url = htmlspecialchars($url);
            if (strpos($node->nodeValue, 'pic.twitter.com/') === false) {
                $node->nodeValue = $url;
            }
            $node->setAttribute('href', $url);
        }
    }

    public function update_video_twimg_com(DOMDocument $doc, DOMXPath $xpath, DOMElement $basenode): void {
        if (!$basenode) {
            return;
        }
        $items = ["//a[contains(text(),'//video.twimg.com/') and contains(text(),'.mp4')]"];
        foreach ($items as $item) {
            $node_list = $xpath->query($item, $basenode);
            if (!$node_list || $node_list->length === 0) {
                continue;
            }
            foreach ($node_list as $node) {
                if (!$node) {
                    continue;
                }
                $link = trim($xpath->evaluate('string()', $node));
                $this->append_video_twimg_com($doc, $node, $link);
            }
        }
    }

    private function append_video_twimg_com(DOMDocument $doc, DOMElement $node, string $link): void {
        $video = $doc->createElement('video', '');
        $video->setAttribute('controls', '');
        $video->setAttribute('style', 'max-width:720px');
        $source = $doc->createElement('source', '');
        $source->setAttribute('src', $link);
        $source->setAttribute('type', 'video/mp4');
        $video->appendChild($source);
        $node->parentNode->insertBefore($video, $node->nextSibling);
    }

    public function update_iframe_youtube(DOMDocument $doc, DOMXPath $xpath, DOMElement $basenode): void {
        if (class_exists('UpdateYoutubeEmbed')) {
            UpdateYoutubeEmbed::Update($doc, $xpath, $basenode);
        }
    }

    public function update_pic_twitter_com(DOMDocument $doc, DOMXPath $xpath, DOMElement $basenode, string $current_url): void {
        if (!$basenode) {
            return;
        }
        $exclusion_list = ['//togetter.com/', '//kabumatome.doorblog.jp/'];
        foreach ($exclusion_list as $exclusion) {
            if (strpos($current_url, $exclusion) !== false) {
                return;
            }
        }

        $items = ["//a[contains(.,'pic.twitter.com/')]"];
        foreach ($items as $item) {
            $node_list = $xpath->query($item, $basenode);
            if (!$node_list || $node_list->length === 0) {
                continue;
            }
            foreach ($node_list as $node) {
                if (!$node) {
                    continue;
                }
                $link = $xpath->evaluate('string()', $node);
                if (!class_exists('PicTwitterImageUrls')) {
                    continue;
                }
                $p = new PicTwitterImageUrls($link);
                $urls = $p->getImageUrls();
                if (empty($urls)) {
                    $link = $xpath->evaluate('string(@href)', $node);
                    $p = new PicTwitterImageUrls($link);
                    $urls = $p->getImageUrls();
                }
                foreach (array_reverse($urls) as $url) {
                    if (strpos($url, '/pic/enc/') !== false) {
                        DomUtils::append_img_tag($doc, $node, $url);
                    }
                    if (strpos($url, '/video/enc/') !== false) {
                        $this->append_pic_twitter_com_video($doc, $node, $url);
                    }
                }
            }
        }
    }

    private function append_pic_twitter_com_video(DOMDocument $doc, DOMElement $node, string $url): void {
        $n = $doc->createElement('div', '');
        $n->appendChild($this->create_pic_twitter_com_video_tag($doc, $url));
        $node->parentNode->insertBefore($n, $node->nextSibling);
    }

    private function create_pic_twitter_com_video_tag(DOMDocument $doc, string $url): DOMElement {
        $v = $doc->createElement('video', '');
        $v->setAttribute('src', $url);
        $v->setAttribute('controls', '');
        $v->setAttribute('type', 'video/mp4');
        return $v;
    }

    public function update_twitter_tweet(DOMDocument $doc, DOMXPath $xpath, DOMElement $basenode): void {
        $expressions = ["//blockquote[@class='twitter-tweet']//a"];
        foreach ($expressions as $expression) {
            $entries = $xpath->query($expression, $basenode);
            if ($entries->length === 0) {
                continue;
            }
            foreach ($entries as $entry) {
                $contents = $xpath->query("//p[@dir='ltr']", $entry);
                if ($contents->length > 0) {
                    continue;
                }
                $tw_url = $entry->getAttribute('href');
                if (!$tw_url) {
                    continue;
                }
                if (!class_exists('TwitterContents')) {
                    continue;
                }
                $tc = new TwitterContents($tw_url);
                $h = $tc->getContents();
                if (!$h) {
                    continue;
                }
                $h = str_replace('href="/', 'href="https://nitter.kozono.org/', $h);
                $h = str_replace('src="/', 'src="https://nitter.kozono.org/', $h);
                $h = str_replace('poster="/', 'poster="https://nitter.kozono.org/', $h);
                $h = str_replace('data-url="/', 'data-url="https://nitter.kozono.org/', $h);
                $h = mb_convert_encoding($h, 'HTML-ENTITIES', 'ASCII, JIS, UTF-8, EUC-JP, SJIS');
                $sdom = new DOMDocument();
                @$sdom->loadHTML($h);
                $sdom_xpath = new DOMXPath($sdom);
                $div = $sdom_xpath->query("//div[@id='m']")->item(0);
                if (!$div) {
                    continue;
                }
                $result = $doc->importNode($div, true);
                $entry->appendChild($result);
            }
        }
    }

    public function update_tag(DOMDocument $doc, DOMXPath $xpath, DOMElement $basenode, string $link): void {
        $entries = $xpath->query("//div[contains(@class,'js-delayed-image-load')]");
        if ($entries->length === 0) {
            return;
        }
        foreach ($entries as $entry) {
            DomUtils::append_iframe_tag($doc, $entry, $entry->getAttribute('data-src'));
        }
    }

    public function update_img_proxy(DOMXPath $xpath): void {
        $entries = $xpath->query("//img[contains(@src,'//bunshun.ismcdn.jp/') and (contains(@src, '.jpg') or contains(@src, '.png'))]");
        foreach ($entries as $entry) {
            $src = $entry->getAttribute('src');
            $replaced_src = str_replace('https://bunshun.ismcdn.jp/', 'https://app.kozono.org/imgproxy/bunshunismcdn/', $src);
            $entry->setAttribute('src', $replaced_src);
        }

        $entries = $xpath->query("//img[contains(@src,'//assets.shueisha.online/') and (contains(@src, '.jpg') or contains(@src, '.png'))]");
        foreach ($entries as $entry) {
            $src = $entry->getAttribute('src');
            $replaced_src = str_replace('https://assets.shueisha.online/', 'https://app.kozono.org/imgproxy/assetsshueisha/', $src);
            $entry->setAttribute('src', $replaced_src);
        }
    }

    public function update_site_head_info(DOMDocument $doc, DOMXPath $xpath, DOMElement $basenode): void {
        if (!$basenode || !class_exists('SiteHeadInfoHtml')) {
            return;
        }
        $node_list = $xpath->query("//a[contains(@href,'//t.co/') and contains(text(),'//t.co/')]", $basenode);
        if (!$node_list || $node_list->length === 0) {
            return;
        }
        $renderer = new SiteHeadInfoHtml();
        foreach ($node_list as $node) {
            if (!$node) {
                continue;
            }
            $url = trim($node->getAttribute('href'));
            if (!$url) {
                continue;
            }
            $html = $renderer->renderFromUrl($url, false);
            if (!$html) {
                continue;
            }
            $this->plugin->fm_site_head_info_css = true;
            $this->insert_html_after_node($doc, $node, $html);
        }
    }

    private function insert_html_after_node(DOMDocument $doc, DOMElement $node, string $html): void
    {
        $html = mb_convert_encoding('<div>' . $html . '</div>', 'HTML-ENTITIES', 'UTF-8');
        libxml_use_internal_errors(true);
        $frag_doc = new DOMDocument();
        @$frag_doc->loadHTML($html);
        libxml_clear_errors();
        $frag_xpath = new DOMXPath($frag_doc);
        $container = $frag_xpath->query('//div')->item(0);
        if (!$container) {
            return;
        }
        $ref = $node;
        foreach ($container->childNodes as $child) {
            if ($child->nodeType === XML_TEXT_NODE && trim($child->nodeValue) === '') {
                continue;
            }
            $imported = $doc->importNode($child, true);
            if ($ref->nextSibling) {
                $ref->parentNode->insertBefore($imported, $ref->nextSibling);
            } else {
                $ref->parentNode->appendChild($imported);
            }
            $ref = $imported;
        }
    }

    public function update_tag_lazy_image(DOMDocument $doc, DOMXPath $xpath, DOMElement $basenode, string $link): void {
        $entries = $xpath->query("//lazy-image");
        if ($entries->length === 0) {
            return;
        }
        foreach ($entries as $entry) {
            $src = $entry->getAttribute('src');
            $width = $entry->getAttribute('width');
            $height = $entry->getAttribute('height');
            if (!$src) {
                continue;
            }
            $opt = [];
            if (!$width) {
                $opt['width'] = $width;
            }
            if (!$height) {
                $opt['height'] = $height;
            }
            DomUtils::append_img_tag($doc, $entry, $src, $opt);
        }
    }

    public function update_html_style(DOMXPath $xpath, DOMElement $basenode, string $link): void {
        if (!$basenode) {
            return;
        }
        $list = $xpath->query("(//*[string-length(@style) > 0])");
        if (!$list || $list->length === 0) {
            return;
        }
        foreach ($list as $item) {
            $s = '';
            if ($item->hasAttribute('style')) {
                $s = trim($item->getAttribute('style'));
            }
            if (!$s || strlen($s) === 0) {
                continue;
            }
            $style = DomUtils::css_style_to_array($s);
            $is_update = false;
            if (array_key_exists('display', $style) && $style['display'] == 'none') {
                $style['display'] = '';
                $is_update = true;
            }
            if (array_key_exists('background-image', $style)) {
                preg_match('/^.*[\'\"](.*)[\'\"].*$/', $style['background-image'], $match);
                if (count($match) != 2) {
                    continue;
                }
                $src = $match[1];
                $scheme = parse_url($link, PHP_URL_SCHEME);
                $host = parse_url($link, PHP_URL_HOST);
                if (!$scheme) {
                    $scheme = 'http';
                }
                if (substr($src, 0, 2) == '//') {
                    $src = $scheme . ':' . $src;
                } elseif (substr($src, 0, 1) == '/') {
                    $src = $scheme . '://' . $host . $src;
                } elseif (substr($src, 0, 4) != 'http') {
                    $pos = strrpos($link, '/');
                    if ($pos) {
                        $src = substr($link, 0, $pos + 1) . $src;
                    }
                }
                $style['background-image'] = "url('${src}')";
                $is_update = true;
            }

            if ($is_update) {
                $item->setAttribute('style', DomUtils::array_to_css_style($style));
            }
        }
    }

    public function update_jp_reuters_com(DOMDocument $doc, DOMXPath $xpath, DOMElement $basenode): void {
        $nodelist = $xpath->query("//div[contains(@class,'LazyImage_image_')]", $basenode);
        foreach ($nodelist as $node) {
            $style = $node->getAttribute('style');
            if (preg_match('/^.*\((.*)\).*$/', $style, $matches)) {
                $url = str_replace('&w=20', '&w=1280', $matches[1]);
                DomUtils::append_img_tag($doc, $node, $url);
            }
        }
    }

    public function change_attribute_value(DOMDocument $doc, DOMXPath $xpath, DOMElement $basenode, string $attr, string $old, string $new): void {
        $query_string = "//*[@${attr}='${old}']";
        $entries = $xpath->query($query_string);
        if ($entries->length === 0) {
            return;
        }
        foreach ($entries as $entry) {
            $entry->setAttribute($attr, $new);
        }
    }
}
