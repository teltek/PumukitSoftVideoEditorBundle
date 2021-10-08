<?php

namespace Pumukit\SoftVideoEditorBundle\Controller;

use Pumukit\SchemaBundle\Document\MultimediaObject;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Template;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

/**
 * @Route("admin/videoeditor")
 */
class VideoController extends AbstractController
{
    /**
     * @Route("/video/{id}", name="pumukit_videoeditor_index")
     * @Template("@PumukitSoftVideoEditor/Video/index.html.twig")
     */
    public function indexAction(MultimediaObject $multimediaObject, Request $request): array
    {
        $tracks = [];
        $track = $request->query->has('track_id') ?
            $multimediaObject->getTrackById($request->query->get('track_id')) :
            $multimediaObject->getFilteredTrackWithTags(['display']);
        if ($track) {
            $tracks[] = $track;
        }

        return [
            'multimediaObject' => $multimediaObject,
            'tracks' => $tracks,
        ];
    }
}
