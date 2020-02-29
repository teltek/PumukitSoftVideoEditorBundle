<?php

namespace Pumukit\SoftVideoEditorBundle\Services;

use Doctrine\ODM\MongoDB\DocumentManager;
use MongoDB\BSON\ObjectId;
use Pumukit\SchemaBundle\Document\Annotation;
use Pumukit\SchemaBundle\Document\MultimediaObject;
use Pumukit\SchemaBundle\Event\AnnotationsUpdateEvent;

class AnnotationsListenerService
{
    private $documentManager;

    public function __construct(DocumentManager $documentManager)
    {
        $this->documentManager = $documentManager;
    }

    public function onAnnotationsUpdate(AnnotationsUpdateEvent $event): void
    {
        $mmobjId = $event->getMultimediaObject();
        $mmobj = $this->documentManager->getRepository(MultimediaObject::class)->find($mmobjId);
        //get all annotations for this mmobj
        $annotations = $this->documentManager->getRepository(Annotation::class)->createQueryBuilder()
            ->field('multimediaObject')->equals(new ObjectId($mmobjId))
            ->getQuery()
            ->execute()
        ;

        $initialDuration = $mmobj->getDuration(); //init duration (in case there are no annotations
        $softDuration = $mmobj->getDuration();
        $allAnnotations = [];
        //Prepares the allAnnotations structure we will use
        foreach ($annotations as $annon) {
            $allAnnotations[$annon->getType()] = json_decode($annon->getValue(), true);
        }
        $trimTimes = null;
        //If there is a trimming, change the original time by the trimming time
        if (isset($allAnnotations['paella/trimming']['trimming'])) {
            $trimTimes = $allAnnotations['paella/trimming']['trimming'];
            $softDuration = $trimTimes['end'] - $trimTimes['start'];
        }
        //If there are any breaks, arrange the breaks array so they don't overlap and decrease the total time by the sum of the breaks.
        if (isset($allAnnotations['paella/breaks']['breaks'])) {
            $allBreaks = $allAnnotations['paella/breaks']['breaks'];
            $allBreaks = $this->getProperBreaks($allBreaks, $trimTimes);
            foreach ($allBreaks as $break) {
                $breakTime = $break['e'] - $break['s'];
                $softDuration -= $breakTime;
            }
        }
        //Add to the mmobj as 'soft-editing-duration'
        if ($softDuration != $initialDuration) {
            $mmobj->setProperty('soft-editing-duration', $softDuration);
            $mmobj->setDuration($softDuration);
            $this->documentManager->persist($mmobj);
            $this->documentManager->flush();
        }
    }

    private function getProperBreaks($breaks, $trim)
    {
        if (isset($trim)) {
            //Exclude breaks that aren't inside the trimming marks
            $breaks = array_filter($breaks, function ($a) use ($trim) {
                return $a['s'] <= $trim['end'] && $a['e'] >= $trim['start'];
            });
            //Cut breaks that are partially inside the trimming marks
            $breaks = array_map(function ($a) use ($trim) {
                if ($a['s'] < $trim['start']) {
                    $a['s'] = $trim['start'];
                }
                if ($a['e'] > $trim['end']) {
                    $a['e'] = $trim['end'];
                }

                return $a;
            }, $breaks);
        }
        //Sort breaks by their starting points
        usort($breaks, function ($a, $b) {
            return $a['s'] > $b['s'];
        });
        $allBreaks = [];
        //Join breaks that overlap
        foreach ($breaks as $brk) {
            if (!isset($temp)) {
                $temp = $brk;
            }
            if ($temp['e'] >= $brk['s']) {
                $temp['e'] = $brk['e'];
            } else {
                $allBreaks[] = $temp;
                $temp = $brk;
            }
        }
        if (isset($temp)) {
            $allBreaks[] = $temp;
        }

        return $allBreaks;
    }
}
