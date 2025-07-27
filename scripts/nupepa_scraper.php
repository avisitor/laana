<?php

require '../../vendor/autoload.php';

use GuzzleHttp\Client;
use Symfony\Component\DomCrawler\Crawler;

$client = new Client(['base_uri' => 'https://nupepa.org']);

$startUrl = '/?a=cl&cl=CL2&e=-------en-20--1--txt-txIN%7CtxNU%7CtxTR%7CtxTI---------0';

// Fix #2: Validate start page
$response = $client->get($startUrl);
if ($response->getStatusCode() !== 200) {
    die("Failed to retrieve start page");
}

$html = (string) $response->getBody();
$crawler = new Crawler($html);

$results = [];

$crawler->filter('#datebrowserrichardtoplevelcalendar > div')->each(function ($yearBlock) use ($client, &$results) {
    $yearHeaderNode = $yearBlock->filter('h2');
    $yearHeader = $yearHeaderNode->count() ? $yearHeaderNode->text() : 'Unknown Year';

    echo "\n=== Debug: Year Block HTML ===\n";
    echo $yearBlock->html() . "\n";

    $yearBlock->filter('a[href*="a=cl&cl=CL2."]')->each(function ($linkNode) use ($client, $yearHeader, &$results) {
        $monthText = trim($linkNode->text());
        $monthUrl = $linkNode->attr('href');

        $monthResponse = $client->get($monthUrl);
        $monthHtml = (string) $monthResponse->getBody();
        $monthCrawler = new Crawler($monthHtml);

        $monthCrawler->filter('.datebrowserrichardmonthlevelcalendardaycellcontents')->each(function ($dayCell) use (&$results) {
            if (!$dayCell->filter('.datebrowserrichardmonthdaynumdocs')->count()) return;

            $dateNode = $dayCell->filter('b.hiddenwhennotsmall');
            $dateText = $dateNode->count() ? trim($dateNode->text()) : null;

            $dayCell->filter('li.list-group-item')->each(function ($itemNode) use ($dateText, &$results) {
                $linkNode = $itemNode->filter('a[href*="a=d&"]');
                if (!$linkNode->count()) return;

                $href = $linkNode->attr('href');
                $titleText = trim($linkNode->text());
                $fullUrl = 'https://nupepa.org' . $href;

                // Image (if present in this block)
                $imgNode = $itemNode->filter('img');
                $imgSrc = $imgNode->count() ? $imgNode->attr('src') : null;
                if ($imgSrc && !str_starts_with($imgSrc, 'http')) {
                    $imgSrc = 'https://nupepa.org' . $imgSrc;
                }

                // Placeholder for author (if there's some nearby identifier—custom logic could go here)
                $authorText = null; // Not structured reliably, likely needs OCR or advanced parsing

                $results[] = [
                    'sourcename' => "{$titleText} ({$dateText})",
                    'url' => $fullUrl,
                    'image' => $imgSrc,
                    'title' => "{$titleText} ({$dateText})",
                    'date' => $dateText,
                    'author' => $authorText,
                    'groupname' => 'nupepa',
                ];
            });
        });
    });
});

// Output structured array
echo "\n=== STRUCTURED RESULTS ===\n";
foreach ($results as $entry) {
    print_r($entry);
}
