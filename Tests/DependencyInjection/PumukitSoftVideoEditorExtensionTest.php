<?php

declare(strict_types=1);

namespace Pumukit\SoftVideoEditorBundle\Tests\DependencyInjection;

use PHPUnit\Framework\TestCase;
use Pumukit\SoftVideoEditorBundle\Controller\EditorController;
use Pumukit\SoftVideoEditorBundle\DependencyInjection\PumukitSoftVideoEditorExtension;
use Pumukit\SoftVideoEditorBundle\Routing\RouteLoader;
use Pumukit\SoftVideoEditorBundle\Services\AnnotationsListenerService;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * @internal
 *
 * @coversNothing
 */
class PumukitSoftVideoEditorExtensionTest extends TestCase
{
    public function testTheDisabledBundleOnlyRegistersTheRouteLoader(): void
    {
        $container = $this->load(['enable' => false]);

        $this->assertSame([RouteLoader::class], $this->bundleServices($container));
        $this->assertFalse($container->getDefinition(RouteLoader::class)->getArgument(0));
    }

    public function testTheEnabledBundleRegistersItsServices(): void
    {
        $container = $this->load([]);

        $this->assertTrue($container->getDefinition(RouteLoader::class)->getArgument(0));
        $this->assertTrue($container->hasDefinition(EditorController::class));
        $this->assertTrue($container->hasDefinition(AnnotationsListenerService::class));
    }

    private function load(array $config): ContainerBuilder
    {
        $container = new ContainerBuilder();
        (new PumukitSoftVideoEditorExtension())->load([$config], $container);

        return $container;
    }

    private function bundleServices(ContainerBuilder $container): array
    {
        return array_values(array_filter(array_keys($container->getDefinitions()), static fn (string $id): bool => str_starts_with($id, 'Pumukit\SoftVideoEditorBundle\\')));
    }
}
