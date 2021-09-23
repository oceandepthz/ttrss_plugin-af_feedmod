<?php

class HatebuUtils {
    static public function is_hatebu(string $fetch_url) : bool {
	    $target = [
		    '//b.hatena.ne.jp/hotentry/it.rss',
		       '//feeds.feedburner.com/hatena/b/hotentry',
		       '//rss.kozono.org/rss/hatebu_marge_hotentry.rss',
	               '//b.hatena.ne.jp/hotentry.rss'
	       ];
        return self::strposa($fetch_url, $target);
    }
    static public function get_hatebu_content(string $url) : string {
        // comment
        $comment = self::get_comment($url); 

        // relation
        $relation = self::get_relation($url);
        
        // css
        $style = self::get_style();

        return "<div>${style}${comment}${relation}</div>";
    }
    static function get_style() : string {
        $style = <<<EOD
<style type='text/css'>
.hatebu-comment {
    border:solid 2px;
    padding:10px;
}
.hatebu-comment img {
    width: 32px!important;
    height: 32px!important;
    border-radius:3px;
    position: absolute;
    display: inline-block;
    top: 14px;
    left: 10px;
    vertical-align: middle;
}
.hatebu-comment .entry-comment-username {
    margin-right:1em;
}
.hatebu-comment .entry-comment-contents {
    display: block;
    border-bottom: 1px solid #ececec;
    position: relative;
    padding: 9px 36px 9px 52px;
    font-size: 13px;
}
.hatebu-comment .entry-comment-meta {
    margin: 2px 0 0 0;
}
.hatebu-comment .entry-comment-tags {
    display: inline;
    margin: 0 0 0 5px;
}
.hatebu-comment .entry-comment-tags li:first-child {
    background: url(https://b.hatena.ne.jp/images/v4/public/entry/tag.svg?version=1afa398e3bf939de1cb5e62aa8e0bb25f0aa4724) no-repeat left center;
    background-size: 10px 10px;
    padding: 0 0 0 14px;
}
.hatebu-comment .entry-comment-tags li {
    display: inline;
    color: #b3b3b3;
    margin: 0 6px 0 0;
}

.entry-relationContents .entry-relationContents-sectionTitle {
    background: #efefef;
    font-size: 14px;
    padding: 5px 10px;
}
.entry-relationContents .entry-relationContents-list {
    margin: 0 0 40px 0;
}
.entry-relationContents .entry-relationContents-list > li {
    border-bottom: 1px solid #ececec;
    padding: 10px 12px 10px 52px;
    display: flex;
    align-items: center;
    font-size: 13px;
    position: relative;
}
.entry-relationContents .entry-relationContents-text {
    flex: 1;
    min-width: 0;
    position: relative;
}
.entry-relationContents .entry-relationContents-text > p {
    margin:0;
    padding:0;
}
.entry-relationContents .entry-relationContents-favicon {
    position: absolute;
    top: 3px;
    left: -35px;
}
.entry-relationContents .entry-relationContents-title {
    margin:0;
    padding:0;
    font-size: 13px;
    overflow: hidden;
}
.entry-relationContents .entry-relationContents-title a {
    color: #333;
}
.entry-relationContents .entry-relationContents-users {
	font-size: 13px;
	margin: 0 10px 0 0;
}
.entry-relationContents .entry-relationContents-users a {
	color: #ff4166;
}
.entry-relationContents .entry-relationContents-domain {
	font-size: 13px;
	color: #999;
}
.entry-relationContents .entry-relationContents-domain a {
	color: #999;
}
.entry-relationContents .entry-relationContents-thumb {
	display: inline-block;
	width: 50px;
	height: 50px;
	flex-shrink: 0;
	margin: 0 0 0 10px;
	vertical-align: middle;
	background-size: cover;
	background-position: center;
}
</style>
EOD;
        return $style;
    }
    static function get_relation(string $url) : string {
        $target = 'https://b.hatena.ne.jp/api/ipad.related_entry.json?url='.urlencode($url);

        require_once('FmUtils.php');
        $u = new FmUtils();
        $json = $u->url_file_get_contents($target);

        if($json == ''){
            return '';
        }
        $d = json_decode($json, true);

        $c = '';
        foreach($d['entries'] as $e){
            $favicon_url = $e['favicon_url'];
            $url = $e['url'];
            $title = $e['title'];
            $count = $e['count'];
            $image = $e['image'];
            $hatebu_url = self::get_hatebu_url($url);
            $root_url = $e['root_url'];
            $url_host = parse_url($url, PHP_URL_HOST);
            $hatebu_domain_url = "https://b.hatena.ne.jp/entrylist?url=${url_host}";
            $c .= "<li><div class='entry-relationContents-text'><img src='${favicon_url}' alt='' class='entry-relationContents-favicon' width='16px' height='16px'><h4 class='entry-relationContents-title'><a rel='nofollow' href='${url}' title='${title}' >${title}</a></h4><p><span class='entry-relationContents-users'><a href='${hatebu_url}' >${count} users</a></span><span class='entry-relationContents-domain'><a href='${hatebu_domain_url}'>${url_host}</a></span></p></div><a rel='nofollow' href='${url}'><span class='entry-relationContents-thumb' style='background-image:url(${image})'></span></a></li>\n";
        }
        return "<section class='entry-relationContents'><h3 class='entry-relationContents-sectionTitle'>関連記事</h3><ul class='entry-relationContents-list'>${c}</ul></section>\n";; 
    }
 
    static function get_comment(string $url) : string {
        $target = 'https://b.hatena.ne.jp/api/entry/'.urlencode($url).'/bookmarks?limit=500&commented_only=1';

        require_once('FmUtils.php');
        $u = new FmUtils();
        $json = $u->url_file_get_contents($target);

        if($json == ''){
            return '';
        }
        $d = json_decode($json, true);
        if(!is_array($d['bookmarks'])){
            return '';
        }
        $c = '';
        foreach($d['bookmarks'] as $b){
            $name = $b['user']['name'];
            $img = $b['user']['image']['image_url'];
            $comment = $b['comment'];
            $tags =  $b['tags'];
            $time = new DateTime($b['created']);
            $time->setTimeZone(new DateTimeZone('Asia/Tokyo'));
            $time = $time->format('Y/m/d H:i');
            $tags = $b['tags'];
            $c .= "<div class='entry-comment-contents'><img src='${img}' alt='${name}' title='${name}'><span class='entry-comment-username'>${name}</span><span class='entry-comment-text'>${comment}</span><ul class='entry-comment-tags'>";
            if(is_array($tags)){
                foreach($tags as $tag){
                    $c .= "<li>${tag}</li>";
                }
            }
            $c .= "</ul><p class='entry-comment-meta'><span class='entry-comment-timestamp'>${time}</span></p></div>\n";
        }
        return "<div class='hatebu-comment'>${c}</div>\n";
    }


    static function cleanup(DOMXPath $xpath, DOMElement $basenode, array $cleanup_items) : void {
        foreach ($cleanup_items as $cleanup_item) {
            $cleanup_item = '//'.$cleanup_item;
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

    static private function get_hatebu_url($url) : string {
        $is_ssl = strpos($url,'https://') === 0;
        $hurl = 'https://b.hatena.ne.jp/entry/';
        if($is_ssl){
            $hurl .= 's/';
            $hurl .= str_replace('https://','',$url);
        }else{
            $hurl .= str_replace('http://','',$url);
        }
        return $hurl;
    }
    static private function get_hatebu_html($url) : string {
        require_once('FmUtils.php');
        $u = new FmUtils();
        $html = $u->url_file_get_contents(self::get_hatebu_url($url));

        $html = mb_convert_encoding($html, 'HTML-ENTITIES', 'ASCII, JIS, UTF-8, EUC-JP, SJIS');
        return $html;
    }

    static private function strposa(string $haystack,array $needles) : bool {
        foreach($needles as $needle) {
            $res = strpos($haystack, $needle);
            if ($res !== false) {
                return true;
            }
        }
        return false;
    }

}
