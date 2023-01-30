<?php

class Imgur {
    private string $url;

    public function __construct(string $url) {
        $this->url = $url;
    }

    public function getImgurUrls() : array {

	// url取得
	$url = $this->getUrl();

	// 拡張子.jpgならば、そのまま戻す
	if(!$this->isProcess($url))
	{
	    return [$url];
	}

	// コンテンツから og img 取得
        $doc = $this->getContentsDomdoc($url);
        $xpath = new DOMXPath($doc);





	return [];
    }
    private function getContentsDomdoc($url) : DOMDocument {
        require_once('FmUtils.php');
        $u = new FmUtils();
        $html = $u->url_file_get_contents($url);

        $html = mb_convert_encoding($html, 'HTML-ENTITIES', 'ASCII, JIS, UTF-8, EUC-JP, SJIS');

        $doc = new DOMDocument();
        @$doc->loadHTML($html);
        return $doc;
    }
    private function getUrl():string {
        if(strpos($this->url, '//') === 0){
	    return "https:".$this->url;
	}
	return $this->url;
    }
    private function isProcess(string $url): bool {
	$ex = substr($filePath, strrpos($url, '.') + 1);
	if(!$ex)
	{
	    return true;
	}
	return $ex == "jpg" || $ex == "gif" || $ex == "jpeg" || $ex == "png";
    }
}
