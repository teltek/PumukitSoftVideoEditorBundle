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
use Pumukit\SchemaBundle\Services\FactoryService;
use Pumukit\SoftVideoEditorBundle\Services\SoftEditingService;

/**
 * @internal
 *
 * @coversNothing
 */
class SoftEditingServiceTest extends PumukitTestCase
{
    private SoftEditingService $service;
    private MultimediaObject $multimediaObject;

    public function setUp(): void
    {
        parent::setUp();

        $this->service = self::getContainer()->get(SoftEditingService::class);

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

    public function testSaveStoresTheFormatsThePlayerAndThePortalRead(): void
    {
        $this->service->save($this->multimediaObject, [
            'trimming' => ['start' => 0, 'end' => 95],
            'breaks' => [['s' => 40, 'e' => 50, 'text' => 'Coffee']],
            'marks' => [['s' => 60, 'name' => 'Second part'], ['s' => 10, 'name' => 'Intro']],
        ], 'user');

        $this->assertSame(['trimming' => ['start' => 0, 'end' => 95]], $this->stored(SoftEditingService::TRIMMING));
        $this->assertSame(['breaks' => [['id' => 1, 's' => 40, 'e' => 50, 'text' => 'Coffee', 'name' => 'Coffee', 'content' => 'Coffee']]], $this->stored(SoftEditingService::BREAKS));
        $this->assertSame(['marks' => [
            ['id' => 1, 's' => 10, 'e' => 10, 'name' => 'Intro', 'content' => 'Intro'],
            ['id' => 2, 's' => 60, 'e' => 60, 'name' => 'Second part', 'content' => 'Second part'],
        ]], $this->stored(SoftEditingService::MARKS));
        $this->dm->refresh($this->multimediaObject);
        $this->assertSame(85, $this->multimediaObject->getDuration());
    }

    public function testBreaksAreClippedToTheTrimming(): void
    {
        $edition = $this->service->save($this->multimediaObject, [
            'trimming' => ['start' => 0, 'end' => 60],
            'breaks' => [['s' => 50, 'e' => 80, 'text' => '']],
        ], 'user');

        $this->assertSame([['s' => 50.0, 'e' => 60.0, 'text' => '']], $edition['breaks']);
    }

    public function testABreakOutsideTheTrimmingIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->service->save($this->multimediaObject, ['trimming' => ['start' => 0, 'end' => 60], 'breaks' => [['s' => 70, 'e' => 80]]], 'user');
    }

    public function testBreaksCannotBeCombinedWithATrimmedStart(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->service->save($this->multimediaObject, ['trimming' => ['start' => 10, 'end' => 90], 'breaks' => [['s' => 40, 'e' => 50]]], 'user');
    }

    public function testATrimmedStartWithoutBreaksIsAccepted(): void
    {
        $edition = $this->service->save($this->multimediaObject, ['trimming' => ['start' => 10, 'end' => 90], 'marks' => [['s' => 20, 'name' => 'Intro']]], 'user');

        $this->assertSame(['start' => 10.0, 'end' => 90.0], $edition['trimming']);
    }

    public function testSaveClampsTimesStripsTagsAndReplacesDuplicates(): void
    {
        $this->service->save($this->multimediaObject, ['trimming' => ['start' => 0, 'end' => 100]], 'user');
        $duplicate = new Annotation();
        $duplicate->setMultimediaObject($this->multimediaObject->getId());
        $duplicate->setType(SoftEditingService::MARKS);
        $duplicate->setValue('{"marks":[]}');
        $this->dm->persist($duplicate);
        $this->dm->flush();

        $edition = $this->service->save($this->multimediaObject, [
            'trimming' => ['start' => -3, 'end' => 250],
            'marks' => [['s' => '12.34', 'name' => '<b>Intro</b><script>x()</script>']],
        ], 'user');

        $this->assertSame(['start' => 0.0, 'end' => 100.0], $edition['trimming']);
        $this->assertSame([['s' => 12.3, 'name' => 'Introx()']], $edition['marks']);
        $this->assertCount(1, $this->dm->getRepository(Annotation::class)->findBy(['multimediaObject' => $this->multimediaObject->getId(), 'type' => SoftEditingService::MARKS]));
    }

    public function testSaveRejectsInvalidRanges(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->service->save($this->multimediaObject, ['trimming' => ['start' => 0, 'end' => 50], 'breaks' => [['s' => 30, 'e' => 20]]], 'user');
    }

    public function testSaveRejectsNonNumericTimes(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->service->save($this->multimediaObject, ['trimming' => ['start' => 'soon', 'end' => 50]], 'user');
    }

    public function testLoadReadsTheAnnotationsOfTheOldEditor(): void
    {
        foreach ([
            SoftEditingService::TRIMMING => '{"trimming":{"start":2.5,"end":80}}',
            SoftEditingService::BREAKS => '{"breaks":[{"id":1,"s":10,"e":20,"content":"Old break","name":"Old break"}]}',
            SoftEditingService::MARKS => '{"marks":[{"id":1,"s":30,"e":30,"name":"Old chapter"}]}',
        ] as $type => $value) {
            $annotation = new Annotation();
            $annotation->setMultimediaObject($this->multimediaObject->getId());
            $annotation->setType($type);
            $annotation->setValue($value);
            $this->dm->persist($annotation);
        }
        $this->dm->flush();

        $this->assertSame([
            'trimming' => ['start' => 2.5, 'end' => 80.0],
            'breaks' => [['s' => 10.0, 'e' => 20.0, 'text' => 'Old break']],
            'marks' => [['s' => 30.0, 'name' => 'Old chapter']],
        ], $this->service->load($this->multimediaObject));
    }

    public function testLoadWithoutAnnotations(): void
    {
        $this->assertSame(['trimming' => null, 'breaks' => [], 'marks' => []], $this->service->load($this->multimediaObject));
    }

    private function stored(string $type): array
    {
        $annotation = $this->dm->getRepository(Annotation::class)->findOneBy(['multimediaObject' => $this->multimediaObject->getId(), 'type' => $type]);

        return json_decode($annotation->getValue(), true);
    }
}
