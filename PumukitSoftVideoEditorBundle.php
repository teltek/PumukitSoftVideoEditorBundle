<?php

namespace Pumukit\SoftVideoEditorBundle;

use Pumukit\SoftVideoEditorBundle\DependencyInjection\Compiler\OverrideServiceCompilerPass;
use Symfony\Component\HttpKernel\Bundle\Bundle;
use Symfony\Component\DependencyInjection\ContainerBuilder;

class PumukitSoftVideoEditorBundle extends Bundle
{
    public function build(ContainerBuilder $container)
    {
        parent::build($container);
        $container->addCompilerPass(new OverrideServiceCompilerPass());
    }
}
