<?php

declare(strict_types=1);

namespace Specrig\Symfony;

use Symfony\Component\DependencyInjection\ContainerBuilder;

trait Rigged
{
    // The kernel's own guess starts from its class's file, which is the run's.
    public function getProjectDir(): string
    {
        return Seams::root();
    }

    public function getCacheDir(): string
    {
        return Seams::directory() . '/cache/' . $this->environment;
    }

    public function getBuildDir(): string
    {
        return $this->getCacheDir();
    }

    public function getLogDir(): string
    {
        return Seams::directory() . '/log';
    }

    protected function build(ContainerBuilder $container): void
    {
        parent::build($container);
        Seams::build($container);
    }
}
