<?php

class Bibliogram {
    private string $instagram_url;
    private string $bibliogram_url;

    public function __construct(string $instagram_url) {
        $this->instagram_url = $instagram_url;
    }

    public function getInstagramHtml() {
        $this->bibliogram_url = $this->convertBibliogramUrl();

        $doc = $this->getContentsDomdoc();
	$xpath = new DOMXPath($doc);	

        $images = $this->getImageContents($doc, $xpath);
        $text = $this->getTextContents($doc, $xpath);
        $videos = $this->getVideoContents($doc, $xpath);

	$images_html = $this->getImageHtml($images);
        $video_html = $this->getVideoHtml($videos);

        $html = "<div class='instagram-media'>${images_html}${video_html}${text}</div>";

	return $html;
    }

    private function getImageHtml(array $images) : string {
        $html = "";    
	foreach($images as $url){
	    $html .= "<img src='${url}' max-width='720' />";
	}
        return $html;
    }

    private function getVideoHtml(array $videos) : string {
        $html = "";
	foreach($videos as $url){
	    $html .= "<video src='${url}' max-width='720' />";
	}
	return $html;
    }

    private function getContentsDomdoc() : DOMDocument {
        require_once('FmUtils.php');
        $u = new FmUtils();
        $html = $u->url_file_get_contents($this->bibliogram_url);

        $html = mb_convert_encoding($html, 'HTML-ENTITIES', 'ASCII, JIS, UTF-8, EUC-JP, SJIS');

        $doc = new DOMDocument();
        @$doc->loadHTML($html);
        return $doc;
    }

    private function getImageContents(DOMDocument $doc, DOMXPath $xpath) : array {
        $urls = [];
        $query = "(//img[@class='sized-image'])";
	$entries = $xpath->query($query);
        foreach($entries as $entry){
            $path = $entry->getAttribute('src');
            $urls[] = "https://bib.kozono.org".$path;
        }
        return $urls;
    }

    private function getVideoContents(DOMDocument $doc, DOMXPath $xpath) : array {
        $urls = [];
        $query = "(//video[@class='sized-video'])";
        $entries = $xpath->query($query);
        foreach($entries as $entry){
            $path = $entry->getAttribute('src');
            $urls[] = "https://bib.kozono.org".$path;
        }
        return $urls;
    }


    private function getTextContents(DOMDocument $doc, DOMXPath $xpath) : string {
        $query = "(//p[contains(@class,'structured-text')])";
	$entries = $xpath->query($query);
	if($entries->length == 0){
            return "";
	}
	$entry = $entries[0];
	$text = $doc->saveXML($entry);
	return $text;
    }

    private function convertBibliogramUrl() : string
    {
        $pattern = '/^https:\/\/www\.instagram\.com\/(p\/.*\/).*$/';
        preg_match($pattern, $this->instagram_url, $match);
        if(count($match) != 2)
        {
            return "";
        }
        $match_value = $match[1];

        $bib_url = "https://bib.kozono.org/${match_value}";
        return $bib_url;
    }
}
