<?php

namespace Pumukit\SoftVideoEditorBundle\Controller;

use Pumukit\BasePlayerBundle\Services\TrackUrlService;
use Pumukit\CoreBundle\Services\SerializerService;
use Pumukit\NewAdminBundle\Controller\NewAdminControllerInterface;
use Pumukit\SchemaBundle\Document\MultimediaObject;
use Pumukit\SchemaBundle\Services\PicService;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Security;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

/**
 * @Security("is_granted('ROLE_ACCESS_MULTIMEDIA_SERIES')")
 */
class PaellaRepositoryController extends AbstractController implements NewAdminControllerInterface
{
    private $serializer;
    private $picService;
    private $trackUrlService;

    public function __construct(SerializerService $serializer, PicService $picService, TrackUrlService $trackUrlService)
    {
        $this->serializer = $serializer;
        $this->picService = $picService;
        $this->trackUrlService = $trackUrlService;
    }

    /**
     * @Route("/paellarepository/{id}.{_format}", methods={"GET"}, defaults={"_format"="json"}, requirements={"_format": "json|xml"})
     */
    public function indexAction(Request $request, MultimediaObject $multimediaObject): Response
    {
        $pic = $this->picService->getFirstUrlPic($multimediaObject, true, false);

        $data = [];
        $data['streams'] = [];

        $tracks = $this->getMultimediaObjectTracks($multimediaObject);
        if (isset($tracks['display'])) {
            $track = $tracks['display'];
            $src = $this->getAbsoluteUrl($request, $this->trackUrlService->generateTrackFileUrl($track));
            $mimeType = $track->getMimetype();
            $dataStream = [
                'sources' => [
                    'mp4' => [
                        [
                            'src' => $src,
                            'mimetype' => $mimeType,
                        ],
                    ],
                ],
                'preview' => $pic,
            ];

            // If pumukit doesn't know the resolution, paella can guess it.
            if ($track->getWidth() && $track->getHeight()) {
                $dataStream['sources']['mp4'][0]['res'] = ['w' => $track->getWidth(), 'h' => $track->getHeight()];
            }

            $data['streams'][] = $dataStream;
        }
        if (isset($tracks['presentation'])) {
            $track = $tracks['presentation'];
            $src = $this->getAbsoluteUrl($request, $this->trackUrlService->generateTrackFileUrl($track));
            $mimeType = $track->getMimetype();
            $dataStream = [
                'sources' => [
                    'mp4' => [
                        [
                            'src' => $src,
                            'mimetype' => $mimeType, ],
                    ],
                ],
            ];

            // If pumukit doesn't know the resolution, paella can guess it.
            if ($track->getWidth() && $track->getHeight()) {
                $dataStream['sources']['mp4'][0]['res'] = ['w' => $track->getWidth(), 'h' => $track->getHeight()];
            }

            $data['streams'][] = $dataStream;
        }

        $data['metadata'] = [
            'title' => $multimediaObject->getTitle(),
            'description' => $multimediaObject->getDescription(),
            'duration' => 0,
        ];

        $frameList = $this->getOpencastFrameList($multimediaObject);
        if ($frameList) {
            $data['frameList'] = $frameList;
        }

        $response = $this->serializer->dataSerialize($data, $request->getRequestFormat());

        return new Response($response);
    }

    private function getAbsoluteUrl(Request $request, string $url)
    {
        if (false !== strpos($url, '://') || 0 === strpos($url, '//')) {
            return $url;
        }

        if ('' === $request->getHost()) {
            return $url;
        }

        return $request->getSchemeAndHttpHost().$request->getBasePath().$url;
    }

    private function getMultimediaObjectTracks(MultimediaObject $multimediaObject)
    {
        $tracks = [];
        $availableCodecs = ['h264', 'vp8', 'vp9'];
        if ($multimediaObject->isMultistream()) {
            $presenterTracks = $multimediaObject->getFilteredTracksWithTags(['presenter/delivery']);
            $presentationTracks = $multimediaObject->getFilteredTracksWithTags(['presentation/delivery']);

            foreach ($presenterTracks as $track) {
                if (in_array($track->getVcodec(), $availableCodecs)) {
                    $tracks['display'] = $track;

                    break;
                }
            }
            foreach ($presentationTracks as $track) {
                if (in_array($track->getVcodec(), $availableCodecs)) {
                    $tracks['presentation'] = $track;

                    break;
                }
            }
            if (count($tracks) <= 0) {
                $track = $multimediaObject->getFilteredTrackWithTags(['sbs']);
                if ($track) {
                    $tracks['sbs'] = $track;
                }
            }
        } else {
            $track = $multimediaObject->getFilteredTrackWithTags(['display']);
            if ($track) {
                $tracks['display'] = $track;
            }
        }

        return $tracks;
    }

    private function getOpencastFrameList(MultimediaObject $multimediaObject)
    {
        //If there is no opencast client this won't work
        if (!$this->has('pumukit_opencast.client')) {
            return [];
        }

        $opencastClient = $this->get('pumukit_opencast.client');
        $images = [];
        //Only works if the video is an opencast video
        if ($opencastId = $multimediaObject->getProperty('opencast')) {
            $mediaPackage = $opencastClient->getMediaPackage($opencastId);
            //If it doesn't have attachments as opencast should, we return an empty result
            if (!isset($mediaPackage['attachments']['attachment'])) {
                return [];
            }

            foreach ($mediaPackage['attachments']['attachment'] as $attachmnt) {
                if ('presentation/segment+preview' == $attachmnt['type']) {
                    $result = [];

                    //Getting time by parsing hours, minutes and second of a string of this type ->  time=T12:12:12:0F1000
                    preg_match('/time\=T(.*?):(.*?):(.*?):;*/', $attachmnt['ref'], $result);
                    $time = $result[1] * 3600 + $result[2] * 60 + $result[3];

                    $images[] = [
                        'id' => 'frame_'.$time,
                        'mimetype' => $attachmnt['mimetype'],
                        'time' => $time,
                        'url' => $attachmnt['url'],
                        'thumb' => $attachmnt['url'],
                    ];
                }
            }
        }

        return $images;
    }
}
