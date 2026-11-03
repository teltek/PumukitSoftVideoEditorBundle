<?php

declare(strict_types=1);

namespace Pumukit\SoftVideoEditorBundle\Tests\Routing;

use Pumukit\SoftVideoEditorBundle\Routing\RouteLoader;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * @internal
 *
 * @coversNothing
 */
class RouteLoaderTest extends KernelTestCase
{
    public function testTheEnabledBundleLoadsTheEditorRoutes(): void
    {
        $routes = self::getContainer()->get('router')->getRouteCollection();

        $this->assertNotNull($routes->get('pumukit_videoeditor_index'));
        $this->assertNotNull($routes->get('pumukit_videoeditor_save'));
    }

    public function testTheDisabledBundleLoadsNoRoute(): void
    {
        $loader = new RouteLoader(false);

        $this->assertTrue($loader->supports('@PumukitSoftVideoEditorBundle/Controller/', RouteLoader::TYPE));
        $this->assertCount(0, $loader->load('@PumukitSoftVideoEditorBundle/Controller/', RouteLoader::TYPE));
    }
}
