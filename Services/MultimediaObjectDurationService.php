<?php

namespace Pumukit\SoftVideoEditorBundle\Services;

use Pumukit\SchemaBundle\Document\MultimediaObject;

class MultimediaObjectDurationService
{
    public function getMmobjDuration(MultimediaObject $multimediaObject): ?int
    {
        if ($duration = $multimediaObject->getProperty('soft-editing-duration')) {
            return $duration;
        }

        if (0 === $multimediaObject->getDuration() && $multimediaObject->getProperty('externalplayer')) {
            return null;
        }

        return $multimediaObject->getDuration();
    }
}
