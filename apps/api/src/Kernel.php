<?php

namespace App;

use App\Mcp\Skill\SkillsExtension;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;

class Kernel extends BaseKernel implements CompilerPassInterface
{
    use MicroKernelTrait;

    /** Annonce l'extension Skills (SEP-2640) dans capabilities.extensions du serveur MCP. */
    public function process(ContainerBuilder $container): void
    {
        if ($container->hasDefinition('mcp.server.builder')) {
            $container->getDefinition('mcp.server.builder')
                ->addMethodCall('enableExtension', [new Definition(SkillsExtension::class)]);
        }
    }
}
