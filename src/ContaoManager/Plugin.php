<?php

declare(strict_types=1);

namespace VenneMedia\ContaoKiAssistent\ContaoManager;

use Contao\ManagerPlugin\Bundle\BundlePluginInterface;
use Contao\ManagerPlugin\Bundle\Config\BundleConfig;
use Contao\ManagerPlugin\Bundle\Parser\ParserInterface;
use VenneMedia\ContaoKiAssistent\ContaoKiAssistentBundle;

final class Plugin implements BundlePluginInterface
{
    /**
     * @return array<int, BundleConfig>
     */
    public function getBundles(ParserInterface $parser): array
    {
        return [
            BundleConfig::create(ContaoKiAssistentBundle::class)
                ->setLoadAfter(['Contao\CoreBundle\ContaoCoreBundle']),
        ];
    }
}
