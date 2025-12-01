<?php
class Zenn {
    protected string $url;

    function __construct($url) {
        $this->url = $url;
    }

    function is_target(): bool
    {
        return fnmatch('*//zenn.dev/*/articles/*', $this->url);
    }

    function get_html(): string {
        $url = $this->url;
        $doc = $this->get_contents_domdoc($url);
        $xpath = new DOMXPath($doc);

// 本文取得
        $main_contents = $this->build_main_contents($doc, $xpath);

// その他取得

// 組み立て
        $html = '<!doctype html><html class="no-js" lang="ja"><body>'.$main_contents.'</body></html>';
        $output_doc = new DOMDocument();
        @$output_doc->loadHTML($html);
        return $output_doc->saveHTML();
    }

    function get_contents_domdoc(string $url) : DOMDocument
    {
        require_once('FmUtils.php');
        $u = new FmUtils();
        $html = $u->url_file_get_contents($url);

        $html = mb_convert_encoding($html, 'HTML-ENTITIES', 'ASCII, JIS, UTF-8, EUC-JP, SJIS');

        $doc = new DOMDocument();
        @$doc->loadHTML($html);
        return $doc;
    }
    function build_main_contents(DOMDocument $doc, DOMXPath $xpath) : string
    {
        $query = "(//script[@id='__NEXT_DATA__'])";
        $entries = $xpath->query($query);
        $html = '';
        foreach($entries as $entry)
        {
            $json_string = $entry->nodeValue;
            $json_contents = json_decode($json_string);
            $html .= $json_contents->{"props"}->{"pageProps"}->{"article"}->{"bodyHtml"};
        }
        return $html;
    }

}
