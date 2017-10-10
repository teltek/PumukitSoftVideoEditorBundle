<?php

namespace Pumukit\SoftVideoEditorBundle\DependencyInjection\Compiler;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

class OverrideServiceCompilerPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container)
    {
        $definition = $container->getDefinition('pumukitschema.mmsduration');
        $definition->setClass('Pumukit\SoftVideoEditorBundle\Services\MultimediaObjectDurationService');
    }
}
