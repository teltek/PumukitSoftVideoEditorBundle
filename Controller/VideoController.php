<?php

namespace Pumukit\SoftVideoEditorBundle\Controller;

use Pumukit\SchemaBundle\Document\MultimediaObject;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

/**
 * @Route("admin/videoeditor")
 */
class VideoController extends AbstractController
{
    /**
     * @Route("/video/{id}", name="pumukit_videoeditor_index")
     */
    public function indexAction(MultimediaObject $multimediaObject, Request $request): Response
    {
        $tracks = [];
        $track = $request->query->has('track_id') ?
            $multimediaObject->getTrackById($request->query->get('track_id')) :
            $multimediaObject->getFilteredTrackWithTags(['display']);
        if ($track) {
            $tracks[] = $track;
        }

        return $this->render('@PumukitSoftVideoEditor/Video/index.html.twig', [
            'multimediaObject' => $multimediaObject,
            'tracks' => $tracks,
        ]);
    }
}
