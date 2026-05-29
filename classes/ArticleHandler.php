<?php

class ArticleHandler {
    private $plugin;

    public function __construct($plugin) {
        $this->plugin = $plugin;
    }

    public function update_remote_src(DOMElement $basenode, string $tag): void {
        $nodelist = $basenode->getElementsByTagName($tag);
        foreach ($nodelist as $node) {
            $src = $node->getAttribute('data-src');
            if (!$src) $src = $node->getAttribute('data-original');
            if (!$src) $src = $node->getAttribute('data-lazy-src');
            if (!$src) $src = $node->getAttribute('data-src-2x');

            if ($src) {
                $node->setAttribute('src', $src);
            }
        }
    }

    public function update_video_src(DOMElement $basenode): void {
        $nodelist = $basenode->getElementsByTagName('video');
        foreach ($nodelist as $node) {
            $src = $node->getAttribute('data-src');
            if ($src) {
                $node->setAttribute('src', $src);
            }
        }
    }

    public function update_srcset(DOMElement $basenode, string $link, string $tag): void {
        $nodelist = $basenode->getElementsByTagName($tag);
        foreach ($nodelist as $node) {
            $srcset = $node->getAttribute('srcset');
            if ($srcset) {
                $items = explode(',', $srcset);
                $new_items = [];
                foreach ($items as $item) {
                    $parts = preg_split('/\s+/', trim($item));
                    if (isset($parts[0])) {
                        $parts[0] = DomUtils::update_absolute_url($link, $parts[0]);
                    }
                    $new_items[] = implode(' ', $parts);
                }
                $node->setAttribute('srcset', implode(', ', $new_items));
            }
        }
    }

    public function update_remote_file(DOMElement $basenode, string $link, string $tag, string $attr): void {
        $nodelist = $basenode->getElementsByTagName($tag);
        foreach ($nodelist as $node) {
            $val = $node->getAttribute($attr);
            if ($val) {
                $node->setAttribute($attr, DomUtils::update_absolute_url($link, $val));
            }
        }
    }

    public function update_instagram(DOMDocument $doc, DOMXPath $xpath, DOMElement $basenode, string $link): void {
        if (!$basenode) return;
        $this->update_instagram_bq($doc, $xpath, $basenode, $link);
    }

    private function update_instagram_bq(DOMDocument $doc, DOMXPath $xpath, DOMElement $basenode, string $link): void {
        $query = "(//blockquote[@class='instagram-media'])";
        $nodelist = $xpath->query($query, $basenode);
        foreach ($nodelist as $node) {
            $instagram_url = $xpath->evaluate('string(@data-instgrm-permalink)', $node);
            if (strpos($instagram_url, 'https://www.instagram.com/p/') !== 0 && 
                strpos($instagram_url, 'https://www.instagram.com/reel/') !== 0) {
                continue;
            }
            if (class_exists('Bibliogram')) {
                $bibliogram = new Bibliogram($instagram_url);
                $html = $bibliogram->getInstagramHtml();
                if (!$html) continue;
                $html = mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8');
                libxml_use_internal_errors(true);
                $sdom = new DOMDocument();
                @$sdom->loadHTML($html);
                libxml_clear_errors();
                $sdom_xpath = new DOMXPath($sdom);
                $div = $sdom_xpath->query("//div[@class='instagram-media']")->item(0);
                if ($div) {
                    while ($node->hasChildNodes()) {
                        $node->removeChild($node->firstChild);
                    }
                    $result = $doc->importNode($div, true);
                    $node->appendChild($result);
                }
            }
        }
    }

    public function update_img_link(DOMDocument $doc, DOMXPath $xpath, DOMElement $basenode, string $link): void {
        if (!$basenode) return;
        $items = ["//a[contains(@href,'//i.imgur.com/') or contains(@href,'//imgur.com/')]"];
        foreach ($items as $item) {
            $node_list = $xpath->query($item, $basenode);
            foreach ($node_list as $node) {
                $url = $node->getAttribute('href');
                if (!$url) continue;
                $url_nl = $xpath->query("//img[contains(@src,'${url}')]", $basenode);
                if ($url_nl && $url_nl->length > 0) continue;
                DomUtils::append_img_tag($doc, $node, $url);
            }
        }
        $node_list = $xpath->query("//blockquote[@class='imgur-embed-pub']", $basenode);
        foreach ($node_list as $node) {
            $dataid = $node->getAttribute('data-id');
            if ($dataid) {
                DomUtils::append_img_tag($doc, $node, "https://i.imgur.com/${dataid}l.jpg");
            }
        }
    }

    public function update_peing_net(DOMDocument $doc, DOMXPath $xpath, DOMElement $basenode, string $link): void {
        if (!$basenode) return;
        $node_list = $xpath->query("//a[contains(@data-expanded-url,'//peing.net/ja/qs/')]", $basenode);
        foreach ($node_list as $node) {
            $link = $node->getAttribute('data-expanded-url');
            if (!$link) continue;
            $entries = $this->get_peing_content($link);
            if ($entries) {
                foreach ($entries as $entry) {
                    $newnode = $doc->importNode($entry, true);
                    $node->parentNode->insertBefore($newnode, $node->nextSibling);
                }
            }
        }
    }

    private function get_peing_content(string $link) {
        $html = $this->plugin->get_html($link, []);
        libxml_use_internal_errors(true);
        $doc = new DOMDocument();
        @$doc->loadHTML($html);
        libxml_clear_errors();
        if (!$doc) return null;
        $xpath = new DOMXPath($doc);
        return $xpath->query("(//div[@class='answer-box']/div[@class='eye-catch-wrapper']//img|//div[@class='answer-box']/div[@class='answer'])");
    }

    public function update_sqex_to(DOMElement $basenode): void {
        if (!$basenode) return;
        $xpath = new DOMXPath($basenode->ownerDocument);
        $node_list = $xpath->query("(//a[contains(@href,'//sqex.to/')])", $basenode);
        foreach ($node_list as $node) {
            $href = $node->getAttribute('href');
            if ($href) {
                $node->setAttribute('href', $this->plugin->get_redirect_url($href));
            }
        }
    }

    public function update_amzn_to(DOMElement $basenode): void {
        if (!$basenode) return;
        $xpath = new DOMXPath($basenode->ownerDocument);
        $node_list = $xpath->query("(//a[contains(@href,'//amzn.to/')])", $basenode);
        foreach ($node_list as $node) {
            $href = $node->getAttribute('href');
            if ($href) {
                $node->setAttribute('href', $this->plugin->get_redirect_url($href));
            }
        }
    }

    public function sanitize_amazon(DOMXPath $xpath, DOMElement $basenode): void {
        if (!$basenode) return;
        $queries = ['//www.amazon.co.jp/', '//amazon.jp/', '//www.amazon.com/', '//amazon.com/'];
        foreach ($queries as $query) {
            $nodes = $xpath->query("(//a[contains(@href,'${query}')])", $basenode);
            foreach ($nodes as $node) {
                $href = $node->getAttribute('href');
                if (!$href) continue;
                $purl = parse_url($href);
                if (!isset($purl['path'])) continue;
                $path = explode('/', $purl['path']);
                $place = -1;
                foreach ($path as $i => $v) {
                    if (strpos($v, 'ASIN') !== false || strpos($v, 'dp') !== false || strpos($v, 'product') !== false) {
                        $place = $i;
                        break;
                    }
                }
                if ($place >= 0 && isset($path[$place + 1])) {
                    $href = ($purl['scheme'] ?? 'https') . "://" . $purl['host'] . "/dp/" . $path[$place + 1] . "/";
                    $node->setAttribute('href', $href);
                }
            }
        }
    }

    public function update_t_co(DOMXPath $xpath, DOMElement $basenode): void {
        if (!$basenode) return;
        $node_list = $xpath->query("(//a[contains(text(),'//t.co/') or contains(@href,'//t.co/')])", $basenode);
        foreach ($node_list as $node) {
            $href = $node->getAttribute('href');
            if (!$href) continue;

            $ch = curl_init($href);
            curl_setopt($ch, CURLOPT_NOBODY, true);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($ch, CURLOPT_MAXREDIRS, 3);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
            curl_setopt($ch, CURLOPT_TIMEOUT, 5);
            curl_setopt($ch, CURLOPT_USERAGENT, USER_AGENT_FEEDMOD);
            curl_exec($ch);
            $url = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
            curl_close($ch);

            if ($url) {
                $url = htmlspecialchars($url);
                if (strpos($node->nodeValue, 'pic.twitter.com/') === false) {
                    $node->nodeValue = $url;
                }
                $node->setAttribute('href', $url);
            }
        }
    }

    public function update_video_twimg_com(DOMDocument $doc, DOMXPath $xpath, DOMElement $basenode): void {
        if (!$basenode) return;
        $node_list = $xpath->query("//a[contains(text(),'//video.twimg.com/') and contains(text(),'.mp4')]", $basenode);
        foreach ($node_list as $node) {
            $link = trim($node->nodeValue);
            $video = $doc->createElement('video', '');
            $video->setAttribute('controls', '');
            $video->setAttribute('style', 'max-width:720px');
            $source = $doc->createElement('source', '');
            $source->setAttribute('src', $link);
            $source->setAttribute('type', 'video/mp4');
            $video->appendChild($source);
            $node->parentNode->insertBefore($video, $node->nextSibling);
        }
    }

    public function update_iframe_youtube(DOMDocument $doc, DOMXPath $xpath, DOMElement $basenode): void {
        if (class_exists('UpdateYoutubeEmbed')) {
            UpdateYoutubeEmbed::Update($doc, $xpath, $basenode);
        }
    }

    public function update_pic_twitter_com(DOMDocument $doc, DOMXPath $xpath, DOMElement $basenode, string $current_url): void {
        if (!$basenode) return;
        $exclusion_list = ['//togetter.com/', '//kabumatome.doorblog.jp/'];
        foreach ($exclusion_list as $exclusion) {
            if (strpos($current_url, $exclusion) !== false) return;
        }

        $node_list = $xpath->query("//a[contains(.,'pic.twitter.com/')]", $basenode);
        foreach ($node_list as $node) {
            $link = $node->nodeValue;
            if (class_exists('PicTwitterImageUrls')) {
                $p = new PicTwitterImageUrls($link);
                $urls = $p->getImageUrls();
                if (empty($urls)) {
                    $urls = (new PicTwitterImageUrls($node->getAttribute('href')))->getImageUrls();
                }
                foreach (array_reverse($urls) as $url) {
                    if (strpos($url, '/pic/enc/') !== false) {
                        DomUtils::append_img_tag($doc, $node, $url);
                    } elseif (strpos($url, '/video/enc/') !== false) {
                        $v = $doc->createElement('video', '');
                        $v->setAttribute('src', $url);
                        $v->setAttribute('controls', '');
                        $v->setAttribute('type', 'video/mp4');
                        $node->parentNode->insertBefore($v, $node->nextSibling);
                    }
                }
            }
        }
    }

    public function update_twitter_tweet(DOMDocument $doc, DOMXPath $xpath, DOMElement $basenode): void {
        $entries = $xpath->query("//blockquote[@class='twitter-tweet']//a", $basenode);
        foreach ($entries as $entry) {
            $contents = $xpath->query("//p[@dir='ltr']", $entry);
            if ($contents->length > 0) continue;
            $link = $entry->getAttribute('href');
            if (class_exists('TwitterContents')) {
                $t = new TwitterContents($link);
                $c = $t->getContents();
                if ($c) {
                    $entry->nodeValue = $c;
                }
            }
        }
    }

    public function update_tag(DOMDocument $doc, DOMXPath $xpath, DOMElement $basenode): void {
        $tags = $xpath->query("//a[contains(@href, '/tags/')]");
        foreach ($tags as $tag) {
            $tag->setAttribute('style', 'color: #3d94d9;');
        }
    }

    public function update_img_proxy(DOMXPath $xpath, DOMElement $basenode): void {
        $imgs = $xpath->query("//img[contains(@src, 'bunshun.ismcdn.jp') or contains(@src, 'assets.shueisha.online')]", $basenode);
        foreach ($imgs as $img) {
            $src = $img->getAttribute('src');
            $img->setAttribute('src', 'https://images.weserv.nl/?url=' . urlencode($src));
        }
    }

    public function update_tag_lazy_image(DOMDocument $doc, DOMXPath $xpath, DOMElement $basenode): void {
        $nodelist = $xpath->query("//div[contains(@class, 'lazy-image')]", $basenode);
        foreach ($nodelist as $node) {
            $src = $node->getAttribute('data-src');
            if ($src) {
                DomUtils::append_img_tag($doc, $node, $src);
            }
        }
    }

    public function update_html_style(DOMXPath $xpath, DOMElement $basenode, string $link): void {
        $list = $xpath->query("(//*[string-length(@style) > 0])", $basenode);
        foreach ($list as $item) {
            $style_str = $item->getAttribute('style');
            $style = DomUtils::css_style_to_array($style_str);
            if (isset($style['background-image'])) {
                if (preg_match('/url\([\'\"]?(.*?)[\'\"]?\)/', $style['background-image'], $match)) {
                    $url = DomUtils::update_absolute_url($link, $match[1]);
                    DomUtils::append_img_tag($item->ownerDocument, $item, $url);
                }
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
        $nodelist = $xpath->query("//*[@${attr}='${old}']", $basenode);
        foreach ($nodelist as $node) {
            $node->setAttribute($attr, $new);
        }
    }
}
