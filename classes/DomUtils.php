<?php

class DomUtils {
    public static function get_xpath_contents(DOMDocument $doc, DOMXPath $xpath, ?string $query_xpath): string {
        if (!$query_xpath) return '';
        $entries = $xpath->query("(//${query_xpath})");
        if (!$entries || $entries->length == 0) return '';
        $contents = '';
        foreach ($entries as $entry) {
            $contents .= $doc->saveXML($entry);
        }
        return $contents;
    }

    public static function cleanup(DOMXPath $xpath, DOMElement $basenode, $cleanup_config): void {
        if (!$basenode) return;

        // Default cleanup from original code
        $default_cleanup = [
            "script[contains(@src,'ead2.googlesyndication.com/pag') or contains(text(),'adsbygoogle')]",
            "ins[contains(@class,'adsbygoogle')]",
            "div[@class='wp_social_bookmarking_light' or contains(@class,'e-adsense') or @id='my-footer' or @class='ninja_onebutton' or @class='social4i' or @class='yarpp-related' or @id='ads' or contains(@class,'fc2_footer') or @id='jp-post-flair' or contains(@class,'addtoany_share_save_container')]",
            "a[contains(@href,'//px.a8.net/')]",
            "noscript"
        ];

        $items = $default_cleanup;
        if ($cleanup_config) {
            if (is_array($cleanup_config)) {
                $items = array_merge($items, $cleanup_config);
            } else {
                $items[] = $cleanup_config;
            }
        }

        foreach ($items as $item) {
            if (!$item) continue;
            $query = (strpos($item, "./") !== 0) ? '//' . $item : $item;
            $nodelist = $xpath->query($query, $basenode);
            if (!$nodelist) continue;
            foreach ($nodelist as $node) {
                if ($node instanceof DOMAttr) {
                    $node->ownerElement->removeAttributeNode($node);
                } elseif ($node->parentNode) {
                    $node->parentNode->removeChild($node);
                }
            }
        }
    }

    public static function update_absolute_url(string $base_url, string $rel_url): string {
        if (preg_match('/^https?:\/\//i', $rel_url)) return $rel_url;
        if (strpos($rel_url, '//') === 0) {
            $p = parse_url($base_url);
            return ($p['scheme'] ?? 'http') . ':' . $rel_url;
        }
        $p = parse_url($base_url);
        if (strpos($rel_url, '/') === 0) {
            return ($p['scheme'] ?? 'http') . '://' . ($p['host'] ?? '') . $rel_url;
        }
        return rtrim(dirname($base_url), '/') . '/' . ltrim($rel_url, './');
    }

    public static function append_img_tag(DOMDocument $doc, DOMElement $node, string $url): void {
        $img = $doc->createElement('img', '');
        $img->setAttribute('src', $url);
        $node->parentNode->insertBefore($img, $node->nextSibling);
    }

    public static function fix_style_tags(string $content): string {
        $content = str_replace('<style><![CDATA[<![CDATA[', '<style>', $content);
        $content = str_replace(']]]]><![CDATA[>]]></style>', '</style>', $content);
        return $content;
    }

    public static function css_style_to_array(string $style): array {
        $a = [];
        $items = explode(';', $style);
        foreach ($items as $item) {
            $kv = explode(':', $item, 2);
            if (count($kv) === 2) {
                $k = trim($kv[0]);
                $v = trim($kv[1]);
                if ($k !== '' && $v !== '') $a[$k] = $v;
            }
        }
        return $a;
    }
}
