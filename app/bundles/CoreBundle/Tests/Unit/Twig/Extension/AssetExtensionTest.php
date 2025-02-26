<?php

declare(strict_types=1);

namespace Mautic\CoreBundle\Tests\Unit\Twig\Extension;

use Mautic\CoreBundle\Test\AbstractMauticTestCase;
use Mautic\CoreBundle\Twig\Extension\AssetExtension;
use PHPUnit\Framework\Assert;

class AssetExtensionTest extends AbstractMauticTestCase
{
    public function testGetCountryFlag(): void
    {
        $assetExtension = self::$container->get(AssetExtension::class);
        \assert($assetExtension instanceof AssetExtension);

        Assert::assertStringStartsWith('/media/images/flags/Belgium.png', $assetExtension->getCountryFlag('Belgium'));
    }
}
