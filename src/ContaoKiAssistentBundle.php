<?php

declare(strict_types=1);

namespace VenneMedia\ContaoKiAssistent;

use Symfony\Component\HttpKernel\Bundle\Bundle;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use VenneMedia\ContaoKiAssistent\DependencyInjection\ContaoKiAssistentExtension;

final class ContaoKiAssistentBundle extends Bundle
{
    public function getContainerExtension(): ?\Symfony\Component\DependencyInjection\Extension\ExtensionInterface
    {
        return new ContaoKiAssistentExtension();
    }

    public function getPath(): string
    {
        return \dirname(__DIR__);
    }

    public function build(ContainerBuilder $container): void
    {
        parent::build($container);
    }
}
