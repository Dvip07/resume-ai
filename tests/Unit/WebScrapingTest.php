<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Log;
use Symfony\Component\Panther\PantherTestCase;
use Symfony\Component\DomCrawler\Crawler;

class WebScrapingTest extends PantherTestCase
{
    public function testScrapePage()
    {
        // Start the headless browser
        $client = self::createPantherClient();

        // Navigate to the target URL
        $crawler = $client->request('GET', 'https://github.com/FriendsOfPHP/Goutte');

        // Extract content from the page
        $h1Text = $crawler->filter('h1')->text();

        // Check if the extracted text is as expected
        echo 'H1 Text: ' . $h1Text . PHP_EOL;
        // Log::info('H1 Text: ' . $h1Text);
    }
}
