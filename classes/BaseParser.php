<?php

namespace Classes;

use DiDom\Document;

class BaseParser
{
    protected $url_form = 'https://search.ipaustralia.gov.au/trademarks/search/advanced';
    protected $url_search = 'https://search.ipaustralia.gov.au/trademarks/search/doSearch';
    protected $params_post = [];
    private $word = '';

    /**
     * BaseParser constructor.
     * @param $word
     */
    public function __construct($word)
    {
        if (!$this->word = $word) {
            $this->error('Incorrect search word');
        }
    }

    /**
     *
     */
    public function process()
    {
        // setting search word
        $this->log("Starting parser...");
        $this->params_post['wv[0]'] = $this->word;

        // getting CSRF key
        $this->log("Getting CSRF key...");
        $key = $this->getCsrfKey();

        // apply CSRF key to POST request
        $this->params_post['_csrf'] = $key;

        // getting POST response from search page
        $this->log("Getting search result page...");
        $result = $this->getRequestResult(
            $this->url_search,
            $this->params_post,
            ["cookie: XSRF-TOKEN=$key"]
        );

        // checking result location redirect
        if ($result['http_code'] != 302) {
            $this->error('Something went wrong on POST request to ' . $this->url_search);
        }

        // getting redirect to results page
        preg_match('/^Location:\s*(.+)/mi', $result['content'], $matches);
        if (!$url = trim($matches[1])) {
            $this->error('Result redirect location not found');
        }

        // getting result page information
        list($total, $last) = $this->getResultPageInfo($url);

        // starting processing data
        $this->log("Starting traversing pagination...");
        $this->log("Total pages found: " . (!$last ? 1 : $last));
        $data = [];
        for ($i = 0; $i <= $last; $i++) {
            $page = $i + 1;
            $document = new Document($url . "&p=$i", true);
            if (!$tbody = (array)$document->find('#resultsTable tbody')) {
                $this->log("No tbody found on page #$page");
                continue;
            }
            $this->log("Processing page #$page... (" . ($last - $i) . " left)");
            foreach ($tbody as $tb) {
                if ($number = $tb->find('a.qa-tm-number')) {
                    $number = (string)$number[0]->innerHtml();
                }
                if ($logo = $tb->find('td.image img')) {
                    $logo = (string)$logo[0]->attr('src');
                }
                if ($name = $tb->find('td.words')) {
                    $name = (string)$name[0]->innerHtml();
                }
                if ($classes = $tb->find('td.classes')) {
                    $classes = (string)$classes[0]->innerHtml();
                }
                if ($status = $tb->find('td.status')) {
                    $status = trim(strip_tags((string)$status[0]->innerHtml()));
                }
                if ($link = $tb->find('td.number a')) {
                    $link = 'https://search.ipaustralia.gov.au' . explode('?', (string)$link[0]->attr('href'))[0];
                }
                $data[] = [
                    "number" => $number,
                    "logo_url" => $logo,
                    "name" => $name,
                    "classes" => $classes,
                    "status" => $status,
                    "details_page_url" => $link,
                ];
            }
            // sleeping to prevent ban
            usleep(1000);
        }

        // information
        $this->log('Result page info elements count: ' . $total);
        $this->log('Result data array count: ' . count($data));
        $this->log('--- done ---');

        echo json_encode($data, JSON_PRETTY_PRINT);
    }

    /**
     * @param $url
     * @param array $params
     * @param array $header
     * @return mixed
     */
    protected function getRequestResult($url, $params = [], $header = [])
    {
        $paramsString = http_build_query($params);
        $header[] = "accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,image/apng,*/*;q=0.8";
        $header[] = "accept-encoding: gzip, deflate, br";
        $header[] = "accept-language: ru-RU,ru;q=0.9,en-US;q=0.8,en;q=0.7";
        $header[] = "cache-control: no-cache";
        $header[] = "content-length: " . strlen($paramsString);
        $header[] = 'content-type: application/x-www-form-urlencoded';
        $header[] = "origin: https://search.ipaustralia.gov.au";
        $header[] = "pragma: no-cache";
        $header[] = 'referer: https://search.ipaustralia.gov.au/trademarks/search/advanced';
        $header[] = "upgrade-insecure-requests: 1";
        $header[] = "user-agent: Mozilla/5.0 (Windows NT 6.1; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/70.0.3538.110 Safari/537.36";

        $options = [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HEADER => true,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_VERBOSE => true,
            CURLOPT_HTTPHEADER => $header,
            CURLOPT_POSTFIELDS => $paramsString,
            CURLINFO_HEADER_OUT => true,
            CURLOPT_POST => true,
        ];

        $ch = curl_init();
        curl_setopt_array($ch, $options);

        $content = curl_exec($ch);
        $err = curl_errno($ch);
        $errmsg = curl_error($ch);
        $arr = curl_getinfo($ch);

        curl_close($ch);

        $arr['errno'] = $err;
        $arr['errmsg'] = $errmsg;
        $arr['content'] = $content;

        return $arr;
    }

    /**
     * @return string
     */
    protected function getCsrfKey()
    {
        $document = new Document($this->url_form, true);
        if (!$input = $document->find('input[name="_csrf"]')[0]) {
            $this->error('input[name="_csrf"] not found');
        }
        if (!$key = (string)$input->attr('value')) {
            $this->error('input[name="_csrf"] has empty value');
        }

        return $key;
    }

    /**
     * @param $url
     * @return array
     */
    protected function getResultPageInfo($url)
    {
        $document = new Document($url, true);
        $total = $document->find('h2.qa-count');
        $last = $document->find('.goto-last-page');

        $total = $total ? (int)$total[0]->innerHtml() : 0;
        $last = $last ? (int)$last[0]->attr('data-gotopage') : 0;

        if (!$total) {
            $this->error('No search results');
        }

        return [$total, $last];
    }

    /**
     * @param $str
     */
    private function log($str)
    {
        echo $str . "\n";
    }

    /**
     * @param $error
     */
    private function error($error)
    {
        echo $error . "\n";
        exit();
    }
    
}
