<?php

namespace Pumukit\SoftVideoEditorBundle\Controller;

use Pumukit\NewAdminBundle\Controller\NewAdminControllerInterface;
use Pumukit\SchemaBundle\Document\MultimediaObject;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Method;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Security;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * @Security("is_granted('ROLE_ACCESS_MULTIMEDIA_SERIES')")
 */
class PaellaRepositoryController extends Controller implements NewAdminControllerInterface
{
    /**
     * @Route("/paellarepository/{id}.{_format}", defaults={"_format"="json"}, requirements={"_format": "json|xml"})
     * @Method("GET")
     */
    public function indexAction(MultimediaObject $mmobj, Request $request)
    {
        //TODO: Do the annotation getting using a service function.
        //$opencastAnnotationService = $this->container->get('video_editor.opencast_annotations');
        $serializer = $this->get('jms_serializer');
        $picService = $this->get('pumukitschema.pic');
        $trackService = $this->get('pumukit_baseplayer.trackurl');
        $pic = $picService->getFirstUrlPic($mmobj, true, false);

        $data = array();
        $data['streams'] = array();

        $tracks = $this->getMmobjTracks($mmobj);
        if (isset($tracks['display'])) {
            $track = $tracks['display'];
            $src = $this->getAbsoluteUrl($request, $trackService->generateTrackFileUrl($track, true));
            $mimeType = $track->getMimetype();
            $dataStream = array(
                'sources' => array(
                    'mp4' => array(
                        array(
                            'src' => $src,
                            'mimetype' => $mimeType,
                        ),
                    ),
                ),
                'preview' => $pic,
            );

            // If pumukit doesn't know the resolution, paella can guess it.
            if ($track->getWidth() && $track->getHeight()) {
                $dataStream['sources']['mp4'][0]['res'] = array('w' => $track->getWidth(), 'h' => $track->getHeight());
            }

            $data['streams'][] = $dataStream;
        }
        if (isset($tracks['presentation'])) {
            $track = $tracks['presentation'];
            $src = $this->getAbsoluteUrl($request, $trackService->generateTrackFileUrl($track, true));
            $mimeType = $track->getMimetype();
            $dataStream = array(
                'sources' => array(
                    'mp4' => array(
                        array(
                            'src' => $src,
                            'mimetype' => $mimeType, ),
                    ),
                ),
            );

            // If pumukit doesn't know the resolution, paella can guess it.
            if ($track->getWidth() && $track->getHeight()) {
                $dataStream['sources']['mp4'][0]['res'] = array('w' => $track->getWidth(), 'h' => $track->getHeight());
            }

            $data['streams'][] = $dataStream;
        }

        $data['metadata'] = array(
            'title' => $mmobj->getTitle(),
            'description' => $mmobj->getDescription(),
            'duration' => 0,
        );

        $frameList = $this->getOpencastFrameList($mmobj);
        if ($frameList) {
            $data['frameList'] = $frameList;
        }

        $response = $serializer->serialize($data, $request->getRequestFormat());

        return new Response($response);
    }

    /**
     * Returns the absolute url from a given path or url.
     */
    private function getAbsoluteUrl($request, $url)
    {
        if (false !== strpos($url, '://') || 0 === strpos($url, '//')) {
            return $url;
        }

        if ('' === $host = $request->getHost()) {
            return $url;
        }

        return $request->getSchemeAndHttpHost().$request->getBasePath().$url;
    }

    /**
     * Returns an array (can be empty) of tracks for the mmobj.
     */
    private function getMmobjTracks(MultimediaObject $mmobj)
    {
        $tracks = array();
        $availableCodecs = array('h264', 'vp8', 'vp9');
        if ($mmobj->isMultistream()) {
            $presenterTracks = $mmobj->getFilteredTracksWithTags(array('presenter/delivery'));
            $presentationTracks = $mmobj->getFilteredTracksWithTags(array('presentation/delivery'));

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
                $track = $mmobj->getFilteredTrackWithTags(array('sbs'));
                if ($track) {
                    $tracks['sbs'] = $track;
                }
            }
        } else {
            $track = $mmobj->getFilteredTrackWithTags(array('display'));
            if ($track) {
                $tracks['display'] = $track;
            }
        }

        return $tracks;
    }

    /**
     * Returns a frameList formatted to be added to the paella.
     */
    private function getOpencastFrameList($mmobj)
    {
        //If there is no opencast client this won't work
        if (!$this->has('pumukit_opencast.client')) {
            return array();
        }

        $opencastClient = $this->get('pumukit_opencast.client');
        $images = array();
        //Only works if the video is an opencast video
        if ($opencastId = $mmobj->getProperty('opencast')) {
            $mediaPackage = $opencastClient->getMediaPackage($opencastId);
            //If it doesn't have attachments as opencast should, we return an empty result
            if (!isset($mediaPackage['attachments']['attachment'])) {
                return array();
            }

            foreach ($mediaPackage['attachments']['attachment'] as $attachmnt) {
                if ('presentation/segment+preview' == $attachmnt['type']) {
                    $result = array();

                    //Getting time by parsing hours, minutes and second of a string of this type ->  time=T12:12:12:0F1000
                    preg_match('/time\=T(.*?):(.*?):(.*?):;*/', $attachmnt['ref'], $result);
                    $time = $result[1] * 3600 + $result[2] * 60 + $result[3];

                    $images[] = array(
                        'id' => 'frame_'.$time,
                        'mimetype' => $attachmnt['mimetype'],
                        'time' => $time,
                        'url' => $attachmnt['url'],
                        'thumb' => $attachmnt['url'],
                    );
                }
            }
        }

        return $images;
    }
}
