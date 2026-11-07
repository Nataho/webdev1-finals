<?php

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class ErrorControllerTest extends WebTestCase
{
    public function testError404EndpointReturnsCustomTemplate(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/error?code=404');

        self::assertResponseStatusCodeSame(404);
        self::assertPageTitleContains('404 - Not Found | Estrera Botanicals');
        self::assertSelectorTextContains('main h1', '404 - Not Found');
        self::assertSelectorTextContains('main p', 'We dug everywhere, but the page you are looking for has been uprooted.');
        self::assertSelectorExists('main a[href="/"]');
    }

    public function testError403EndpointReturnsCustomTemplate(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/error?code=403');

        self::assertResponseStatusCodeSame(403);
        self::assertPageTitleContains('403 - Forbidden | Estrera Botanicals');
        self::assertSelectorTextContains('main h1', '403 - Forbidden');
        self::assertSelectorTextContains('main p', 'Nice try! You do not have the required permissions to view this page.');
        self::assertSelectorExists('main a[href="/"]');
    }

    public function testLegacyErrorPhpEndpointIsAccessible(): void
    {
        $client = static::createClient();
        $client->request('GET', '/error.php?code=404');

        self::assertResponseStatusCodeSame(404);
        self::assertSelectorTextContains('main h1', '404 - Not Found');
    }

    public function testDefaultGenericError(): void
    {
        $client = static::createClient();
        $client->request('GET', '/error?code=500');

        self::assertResponseStatusCodeSame(500);
        self::assertSelectorTextContains('main h1', '500 - An error occurred');
        self::assertSelectorTextContains('main p', 'An unexpected error occurred while tending to this page.');
    }
}
