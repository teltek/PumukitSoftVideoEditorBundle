<?php

declare(strict_types=1);

namespace Pumukit\SoftVideoEditorBundle\Controller;

use Pumukit\BasePlayerBundle\Services\TrackUrlService;
use Pumukit\NewAdminBundle\Controller\NewAdminControllerInterface;
use Pumukit\SchemaBundle\Document\MediaType\Track;
use Pumukit\SchemaBundle\Document\MultimediaObject;
use Pumukit\SchemaBundle\Services\MultimediaObjectVoter;
use Pumukit\SchemaBundle\Services\PicService;
use Pumukit\SoftVideoEditorBundle\Services\SoftEditingService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin/videoeditor')]
#[IsGranted('ROLE_ACCESS_MULTIMEDIA_SERIES')]
class EditorController extends AbstractController implements NewAdminControllerInterface
{
    private const CODECS = ['h264', 'vp8', 'vp9'];

    public function __construct(
        private readonly SoftEditingService $softEditingService,
        private readonly TrackUrlService $trackUrlService,
        private readonly PicService $picService,
    ) {}

    #[Route('/{id}/editor', name: 'pumukit_videoeditor_index', methods: ['GET'])]
    public function editorAction(MultimediaObject $multimediaObject): Response
    {
        $this->denyAccessUnlessGranted(MultimediaObjectVoter::EDIT, $multimediaObject);

        $tracks = $this->tracks($multimediaObject);

        return $this->render('@PumukitSoftVideoEditor/Editor/editor.html.twig', [
            'multimediaObject' => $multimediaObject,
            'sources' => array_map(fn (Track $track) => $this->trackUrlService->generateTrackFileUrl($track), $tracks),
            'poster' => $this->picService->getFirstUrlPic($multimediaObject, false, false),
            'duration' => $multimediaObject->getRealDuration(),
            'edition' => $this->softEditingService->load($multimediaObject),
        ]);
    }

    #[Route('/{id}/edition', name: 'pumukit_videoeditor_save', methods: ['POST'])]
    public function saveAction(Request $request, MultimediaObject $multimediaObject): JsonResponse
    {
        $this->denyAccessUnlessGranted(MultimediaObjectVoter::EDIT, $multimediaObject);

        try {
            $data = json_decode($request->getContent(), true, 16, JSON_THROW_ON_ERROR);
            if (!is_array($data)) {
                throw new \InvalidArgumentException('Expected an object');
            }
            $edition = $this->softEditingService->save($multimediaObject, $data, (string) $this->getUser()?->getId());
        } catch (\InvalidArgumentException|\JsonException $exception) {
            return new JsonResponse(['error' => $exception->getMessage()], Response::HTTP_BAD_REQUEST);
        }

        return new JsonResponse(['edition' => $edition, 'duration' => $multimediaObject->getDuration()]);
    }

    private function tracks(MultimediaObject $multimediaObject): array
    {
        if (!$multimediaObject->isMultistream()) {
            $track = $multimediaObject->getFilteredTrackWithTags(['display']);

            return $track instanceof Track ? ['presenter' => $track] : [];
        }

        $tracks = [];
        foreach (['presenter' => 'presenter/delivery', 'presentation' => 'presentation/delivery'] as $role => $tag) {
            foreach ($multimediaObject->getFilteredTracksWithTags([$tag]) as $track) {
                if ($track instanceof Track && in_array($track->metadata()->codecName(), self::CODECS, true)) {
                    $tracks[$role] = $track;

                    break;
                }
            }
        }
        if (!isset($tracks['presenter']) && isset($tracks['presentation'])) {
            $tracks = ['presenter' => $tracks['presentation']];
        }
        $sideBySide = $tracks ? null : $multimediaObject->getFilteredTrackWithTags(['sbs']);
        if ($sideBySide instanceof Track) {
            $tracks['presenter'] = $sideBySide;
        }

        return $tracks;
    }
}
