<?php

declare(strict_types=1);

namespace Pumukit\SoftVideoEditorBundle\Tests\Controller;

use MongoDB\BSON\ObjectId;
use Pumukit\CoreBundle\Tests\PumukitTestCase;
use Pumukit\SchemaBundle\Document\MediaType\Metadata\VideoAudio;
use Pumukit\SchemaBundle\Document\MediaType\Storage;
use Pumukit\SchemaBundle\Document\MediaType\Track;
use Pumukit\SchemaBundle\Document\MultimediaObject;
use Pumukit\SchemaBundle\Document\PermissionProfile;
use Pumukit\SchemaBundle\Document\Role;
use Pumukit\SchemaBundle\Document\User;
use Pumukit\SchemaBundle\Document\ValueObject\i18nText;
use Pumukit\SchemaBundle\Document\ValueObject\Path;
use Pumukit\SchemaBundle\Document\ValueObject\StorageUrl;
use Pumukit\SchemaBundle\Document\ValueObject\Tags;
use Pumukit\SchemaBundle\Services\FactoryService;
use Pumukit\SchemaBundle\Services\MediaCreator;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * @internal
 *
 * @coversNothing
 */
class EditorControllerTest extends PumukitTestCase
{
    private KernelBrowser $client;
    private MultimediaObject $multimediaObject;

    public function setUp(): void
    {
        self::ensureKernelShutdown();
        $this->client = static::createClient();
        $this->dm = self::getContainer()->get('doctrine_mongodb.odm.document_manager');
        $this->clearBBDD();

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
            Storage::create(StorageUrl::create('/storage/video.mp4'), Path::create(self::getContainer()->getParameter('kernel.project_dir').'/tests/files/pumukit.mp4')),
            VideoAudio::create('{"format":{"duration":"100.000000"}}')
        ));
        $this->dm->flush();
    }

    public function testTheEditorRendersTheVideoAndTheSavedEdition(): void
    {
        $this->client->loginUser($this->user('admin', PermissionProfile::SCOPE_GLOBAL), 'pumukit');

        $crawler = $this->client->request('GET', '/admin/videoeditor/'.$this->multimediaObject->getId().'/editor');

        $this->assertResponseIsSuccessful();
        $editor = $crawler->filter('[data-controller="soft-video-editor--editor"]');
        $this->assertCount(1, $editor);
        $this->assertSame('100', $editor->attr('data-soft-video-editor--editor-duration-value'));
        $this->assertSame(['trimming' => null, 'breaks' => [], 'marks' => []], json_decode($editor->attr('data-soft-video-editor--editor-edition-value'), true));
        $this->assertCount(1, $crawler->filter('video[data-soft-video-editor--editor-target="video"]'));
        $this->assertCount(0, $crawler->filter('script'));
    }

    public function testSavingUpdatesTheEditionAndTheDuration(): void
    {
        $this->client->loginUser($this->user('admin', PermissionProfile::SCOPE_GLOBAL), 'pumukit');

        $this->post(['trimming' => ['start' => 0, 'end' => 90], 'breaks' => [['s' => 20, 'e' => 30, 'text' => '']], 'marks' => []]);

        $this->assertResponseIsSuccessful();
        $response = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertSame(80, $response['duration']);
        $this->assertSame(['start' => 0, 'end' => 90], $response['edition']['trimming']);
    }

    public function testInvalidEditionIsABadRequest(): void
    {
        $this->client->loginUser($this->user('admin', PermissionProfile::SCOPE_GLOBAL), 'pumukit');

        $this->post(['trimming' => ['start' => 50, 'end' => 10]]);
        $this->assertResponseStatusCodeSame(400);

        $this->post(['trimming' => ['start' => 10, 'end' => 90], 'breaks' => [['s' => 20, 'e' => 30]]]);
        $this->assertResponseStatusCodeSame(400);
    }

    public function testThePersonalScopeCannotEditSomeoneElsesVideo(): void
    {
        $this->client->loginUser($this->user('teacher', PermissionProfile::SCOPE_PERSONAL), 'pumukit');

        $this->client->request('GET', '/admin/videoeditor/'.$this->multimediaObject->getId().'/editor');
        $this->assertContains($this->client->getResponse()->getStatusCode(), [403, 404]);

        $this->post(['trimming' => ['start' => 0, 'end' => 10]]);
        $this->assertContains($this->client->getResponse()->getStatusCode(), [403, 404]);
        $stored = self::getContainer()->get('doctrine_mongodb.odm.document_manager')->getDocumentCollection(MultimediaObject::class)->findOne(['_id' => new ObjectId($this->multimediaObject->getId())]);
        $this->assertSame(100, $stored['duration']);
    }

    public function testAnExternalVideoShowsTheNoTrackNotice(): void
    {
        $this->client->loginUser($this->user('admin', PermissionProfile::SCOPE_GLOBAL), 'pumukit');
        $factory = self::getContainer()->get(FactoryService::class);
        $external = $factory->createMultimediaObject($factory->createSeries(), true);
        self::getContainer()->get(MediaCreator::class)->createMediaFromExternalURL($external, 'https://example.org/player');
        $this->dm->flush();

        $crawler = $this->client->request('GET', '/admin/videoeditor/'.$external->getId().'/editor');

        $this->assertResponseIsSuccessful();
        $this->assertCount(0, $crawler->filter('video'));
        $this->assertCount(1, $crawler->filter('.alert-warning'));
    }

    public function testSavingDoesNotAcceptGet(): void
    {
        $this->client->loginUser($this->user('admin', PermissionProfile::SCOPE_GLOBAL), 'pumukit');

        $this->client->request('GET', '/admin/videoeditor/'.$this->multimediaObject->getId().'/edition');

        $this->assertResponseStatusCodeSame(405);
    }

    private function post(array $edition): void
    {
        $this->client->request('POST', '/admin/videoeditor/'.$this->multimediaObject->getId().'/edition', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode($edition));
    }

    private function user(string $username, string $scope): User
    {
        $user = new User();
        $user->setUsername($username);
        $user->setEmail($username.'@example.com');
        $user->addRole($scope);
        $user->addRole('ROLE_ACCESS_MULTIMEDIA_SERIES');
        $this->dm->persist($user);
        $this->dm->flush();

        return $user;
    }
}
