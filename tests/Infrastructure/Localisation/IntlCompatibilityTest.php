<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Localisation;

use App\Infrastructure\Localisation\IntlCompatibility;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Intl\Countries;

final class IntlCompatibilityTest extends TestCase
{
    public function testGetCountryNamesSupportsRegionalLocalesWithoutIntlExtension(): void
    {
        $countryNames = IntlCompatibility::getCountryNames('fr_FR');

        $this->assertArrayHasKey('BE', $countryNames);
        $this->assertSame(Countries::getName('BE', 'fr_FR'), $countryNames['BE']);
        $this->assertArrayHasKey('US', $countryNames);
    }

    public function testGetNumberFormattingLocaleFallsBackToEnglishWithoutIntlExtension(): void
    {
        $this->assertSame(
            extension_loaded('intl') ? 'nl_BE' : 'en',
            IntlCompatibility::getNumberFormattingLocale('nl_BE'),
        );
    }
}