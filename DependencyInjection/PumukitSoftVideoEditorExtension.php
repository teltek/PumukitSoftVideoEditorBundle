<?php

declare(strict_types=1);

namespace Pumukit\SoftVideoEditorBundle\DependencyInjection;

use Pumukit\CoreBundle\DependencyInjection\FrontendAssets;
use Pumukit\SoftVideoEditorBundle\Routing\RouteLoader;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Extension\PrependExtensionInterface;
use Symfony\Component\DependencyInjection\Loader;

class PumukitSoftVideoEditorExtension extends Extension implements PrependExtensionInterface
{
    public function prepend(ContainerBuilder $container): void
    {
        FrontendAssets::register($container, dirname(__DIR__), '@pumukit/soft-video-editor');
    }

    public function load(array $configs, ContainerBuilder $container): void
    {
        $config = $this->processConfiguration(new Configuration(), $configs);
        $container->setParameter('pumukit_soft_video_editor.enable', $config['enable']);

        $container->register(RouteLoader::class)
            ->setArguments([$config['enable'], '%kernel.environment%'])
            ->addTag('routing.loader')
        ;

        if (!$config['enable']) {
            return;
        }

        $loader = new Loader\YamlFileLoader($container, new FileLocator(__DIR__.'/../Resources/config'));
        $loader->load('pumukit_soft_video_editor.yaml');
    }
}
