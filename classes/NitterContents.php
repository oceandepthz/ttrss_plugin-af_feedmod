<?php

class NitterContents
{
    private string $url;

    function __construct(string $url)
    {
        $this->url = $url;
    }

    function isNitter() : bool
    {
        return strpos($this->url, "//nitter.kozono.org/") !== false;
    }

    function getContent() : string
    {
        require_once('FmUtils.php');
        $MAX_COUNT = 5;
        for ($i = 0; $i < $MAX_COUNT; $i++) {
            $u = new FmUtils();
            $html = $u->url_file_get_contents($this->url);
            if(!$html)
            {
                sleep(5);
                continue;
            }

            $html = mb_convert_encoding($html, 'HTML-ENTITIES', 'ASCII, JIS, UTF-8, EUC-JP, SJIS');
            $doc = new DOMDocument();
            @$doc->loadHTML($html);

            $this->replaceVideoTags($doc);
            $this->replaceYoutubeLinks($doc);

            $html = $doc->saveHTML();
            return $html;
        }
        return "";
    }

    private function replaceYoutubeLinks(DOMDocument $doc) : void
    {
        $xpath = new DOMXPath($doc);
        $query = "(//div[@class='main-thread']//a[@class='card-container' and contains(@href,'//youtu.be/')])";
        $entries = $xpath->query($query);
        $nodes = [];
        foreach($entries as $entry){
            $nodes[] = $entry;
        }

        foreach($nodes as $entry){
            $href = $entry->getAttribute('href');
            $videoId = substr(parse_url($href, PHP_URL_PATH), 1);
            if (!$videoId) continue;

            $title = "";
            $descEntries = $xpath->query(".//p[@class='card-description']", $entry);
            if ($descEntries->length > 0) {
                $title = $descEntries->item(0)->textContent;
            }

            $iframe = $doc->createElement('iframe');
            $iframe->setAttribute('class', 'youtube-player');
            $iframe->setAttribute('type', 'text/html');
            $iframe->setAttribute('width', '640');
            $iframe->setAttribute('height', '385');
            $iframe->setAttribute('title', $title);
            $iframe->setAttribute('src', "https://www.youtube-nocookie.com/embed/" . $videoId);
            $iframe->setAttribute('referrerpolicy', 'strict-origin-when-cross-origin');
            $iframe->setAttribute('allowfullscreen', 'allowfullscreen');
            $iframe->setAttribute('frameborder', '0');

            $entry->parentNode->replaceChild($iframe, $entry);
        }
    }

    private function replaceVideoTags(DOMDocument $doc) : void
    {
        $xpath = new DOMXPath($doc);

        $query = "(//div[@class='main-thread']//video)";
        $entries = $xpath->query($query);
        $nodes = [];
        foreach($entries as $entry){
            $nodes[] = $entry;
        }
        foreach($nodes as $entry){
            $class = $entry->getAttribute('class');
            if($class == 'gif'){
                continue;
            }
            $path = $entry->getAttribute('data-url');
            $fullpath = "https://nitter.kozono.org".$path;

            $this->replace_pic_twitter_com_video($doc, $entry, $fullpath);
        }
    }

    function replace_pic_twitter_com_video(DOMDocument $doc, DOMElement $node, string $url) : void
    {
        $n = $doc->createElement('div','');
        $n->appendChild($this->create_pic_twitter_com_video_tag($doc, $url));
        $node->parentNode->replaceChild($n, $node);
    }

    function create_pic_twitter_com_video_tag(DOMDocument $doc, string $url) : DOMElement
    {
        $v = $doc->createElement('video','');
        $v->setAttribute('controls', '');
        $v->setAttribute('style', 'width:100%;max-width:1024px;height: auto;max-height:1024px;');

        $s = $doc->createElement('source');
        $s->setAttribute('src', $url);
        $s->setAttribute('type', 'application/vnd.apple.mpegurl');
        $v->appendChild($s);

        return $v;
    }
}
