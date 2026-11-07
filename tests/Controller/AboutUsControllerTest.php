<?php

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class AboutUsControllerTest extends WebTestCase
{
    public function testAboutUsPageLoadsSuccessfully(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/about-us');

        self::assertResponseIsSuccessful();
        self::assertPageTitleContains('About Us | Estrera Botanicals');

        // Hero section assertions
        self::assertSelectorExists('.about-us-image-container img[alt="aboutus1"]');
        self::assertSelectorTextContains('.overlay-content h1', 'The Most Intelligent Skincare in the Philippines');

        // Mission section assertions
        self::assertSelectorExists('#mission-section');
        self::assertSelectorTextContains('#mission-section h1', 'Mission');
        self::assertSelectorTextContains('#mission-description', 'Estrera Botanicals formulates clean');

        // Founder section assertions
        self::assertSelectorExists('.founder-card-wrapper .spotlight-section');
        self::assertSelectorExists('.spotlight-photo[alt="Owen Estrera - Founder"]');
        self::assertSelectorTextContains('.spotlight-message h1', 'Our Truth-Seeking Founder');
        self::assertSelectorTextContains('.spotlight-name', 'Owen Estrera');
        self::assertSelectorTextContains('.spotlight-role', 'Founder & Chief Formulator');

        // Footer is included
        self::assertSelectorExists('footer');
    }

    public function testAboutUsAliasUrlLoadsSuccessfully(): void
    {
        $client = static::createClient();
        $client->request('GET', '/about/us');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.overlay-content h1', 'The Most Intelligent Skincare in the Philippines');
    }
}
