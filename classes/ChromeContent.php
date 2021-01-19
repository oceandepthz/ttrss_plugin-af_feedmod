<?php
require __DIR__ . "/../vendor/autoload.php";
use HeadlessChromium\BrowserFactory;
use HeadlessChromium\Page;

class ChromeContent {
	private $url;
	function __construct($url) {
		$this->url = $url;
	}
	function get_content() : string {
                $browserFactory = new BrowserFactory('google-chrome');
                $browser = $browserFactory->createBrowser([
                        'windowSize'      => [1920, 2160],
                ]);
		$page = $browser->createPage();
		$page->navigate($this->url)->waitForNavigation(Page::NETWORK_IDLE, 60000);
		$data = $page->evaluate('document.querySelector("html").outerHTML')->getReturnValue();
		return $data;
	}
}

