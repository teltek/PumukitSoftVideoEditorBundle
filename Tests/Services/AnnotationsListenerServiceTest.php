<?php

declare(strict_types=1);

namespace Pumukit\SoftVideoEditorBundle\Tests\Services;

use Pumukit\CoreBundle\Tests\PumukitTestCase;
use Pumukit\SchemaBundle\Document\Annotation;
use Pumukit\SchemaBundle\Document\MediaType\Metadata\VideoAudio;
use Pumukit\SchemaBundle\Document\MediaType\Storage;
use Pumukit\SchemaBundle\Document\MediaType\Track;
use Pumukit\SchemaBundle\Document\MultimediaObject;
use Pumukit\SchemaBundle\Document\Role;
use Pumukit\SchemaBundle\Document\ValueObject\i18nText;
use Pumukit\SchemaBundle\Document\ValueObject\Path;
use Pumukit\SchemaBundle\Document\ValueObject\StorageUrl;
use Pumukit\SchemaBundle\Document\ValueObject\Tags;
use Pumukit\SchemaBundle\Event\AnnotationsUpdateEvent;
use Pumukit\SchemaBundle\Services\FactoryService;
use Pumukit\SoftVideoEditorBundle\Services\AnnotationsListenerService;

/**
 * @internal
 *
 * @coversNothing
 */
class AnnotationsListenerServiceTest extends PumukitTestCase
{
    private AnnotationsListenerService $listener;
    private MultimediaObject $multimediaObject;

    public function setUp(): void
    {
        parent::setUp();

        $this->listener = self::getContainer()->get(AnnotationsListenerService::class);

        $role = new Role();
        $role->setCod('owner');
        $this->dm->persist($role);
        $this->dm->flush();

        $factory = self::getContainer()->get(FactoryService::class);
        $this->multimediaObject = $factory->createMultimediaObject($factory->createSeries());
        $this->multimediaObject->addTrack(Track::create(
            'video.mp4',
            i18nText::create(['en' => '']),
            'en',
            Tags::create(['master', 'display']),
            false,
            true,
            0,
            Storage::create(StorageUrl::create(''), Path::create(self::getContainer()->getParameter('kernel.project_dir').'/tests/files/pumukit.mp4')),
            VideoAudio::create('{"format":{"duration":"100.000000"}}')
        ));
        $this->dm->flush();
    }

    public function testTrimmingAndBreaksReduceTheDuration(): void
    {
        $this->annotate('paella/trimming', ['trimming' => ['start' => 10, 'end' => 90]]);
        $this->annotate('paella/breaks', ['breaks' => [['id' => 1, 's' => 20, 'e' => 30], ['id' => 2, 's' => 25, 'e' => 35], ['id' => 3, 's' => 85, 'e' => 95]]]);

        $this->update();

        $this->assertSame(60, $this->multimediaObject->getDuration());
        $this->assertSame(60, $this->multimediaObject->getProperty('soft-editing-duration'));
    }

    public function testSavingTwiceDoesNotSubtractTheBreaksAgain(): void
    {
        $this->annotate('paella/breaks', ['breaks' => [['id' => 1, 's' => 0, 'e' => 20]]]);

        $this->update();
        $this->update();

        $this->assertSame(80, $this->multimediaObject->getDuration());
    }

    public function testRemovingTheEditsRestoresTheRealDuration(): void
    {
        $trimming = $this->annotate('paella/trimming', ['trimming' => ['start' => 10, 'end' => 50]]);
        $this->update();
        $this->assertSame(40, $this->multimediaObject->getDuration());

        $trimming->setValue(json_encode(['trimming' => ['start' => 0, 'end' => 100]]));
        $this->dm->flush();
        $this->update();

        $this->assertSame(100, $this->multimediaObject->getDuration());
        $this->assertNull($this->multimediaObject->getProperty('soft-editing-duration'));
    }

    public function testDecimalTimesAreRounded(): void
    {
        $this->annotate('paella/trimming', ['trimming' => ['start' => 0.4, 'end' => 60.9]]);

        $this->update();

        $this->assertSame(61, $this->multimediaObject->getDuration());
    }

    public function testTheDisabledBundleKeepsTheDuration(): void
    {
        $this->annotate('paella/trimming', ['trimming' => ['start' => 10, 'end' => 50]]);
        $this->listener = new AnnotationsListenerService($this->dm, false);

        $this->update();

        $this->assertSame(100, $this->multimediaObject->getDuration());
    }

    private function annotate(string $type, array $value): Annotation
    {
        $annotation = new Annotation();
        $annotation->setMultimediaObject($this->multimediaObject->getId());
        $annotation->setType($type);
        $annotation->setValue(json_encode($value));
        $this->dm->persist($annotation);
        $this->dm->flush();

        return $annotation;
    }

    private function update(): void
    {
        $this->listener->onAnnotationsUpdate(new AnnotationsUpdateEvent($this->multimediaObject->getId()));
        $this->dm->refresh($this->multimediaObject);
    }
}
