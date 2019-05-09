<?php

namespace Pumukit\SoftVideoEditorBundle\Controller;

use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Template;
use Pumukit\SchemaBundle\Document\MultimediaObject;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\HttpFoundation\Request;

class VideoController extends Controller
{
    /**
     * @Route("/video/{id}", name="pumukit_videoeditor_index" )
     * @Template()
     *
     * @param MultimediaObject $multimediaObject
     * @param Request          $request
     *
     * @return array|\Symfony\Component\HttpFoundation\RedirectResponse
     */
    public function indexAction(MultimediaObject $multimediaObject, Request $request)
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
