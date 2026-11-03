<?php

declare(strict_types=1);

namespace Pumukit\SoftVideoEditorBundle\Services;

use Doctrine\ODM\MongoDB\DocumentManager;
use MongoDB\BSON\ObjectId;
use Pumukit\SchemaBundle\Document\Annotation;
use Pumukit\SchemaBundle\Document\MultimediaObject;
use Pumukit\SchemaBundle\Event\AnnotationsUpdateEvent;

class AnnotationsListenerService
{
    public const SOFT_DURATION_PROPERTY = 'soft-editing-duration';

    public function __construct(
        private readonly DocumentManager $documentManager,
        private readonly bool $enabled = true,
    ) {}

    public function onAnnotationsUpdate(AnnotationsUpdateEvent $event): void
    {
        if (!$this->enabled) {
            return;
        }

        $multimediaObject = $this->documentManager->getRepository(MultimediaObject::class)->find($event->getMultimediaObject());
        if (!$multimediaObject instanceof MultimediaObject) {
            return;
        }

        $realDuration = $multimediaObject->getRealDuration();
        if ($realDuration <= 0) {
            return;
        }

        $annotations = $this->annotationsOf($multimediaObject);
        $start = 0.0;
        $end = (float) $realDuration;
        if (isset($annotations['paella/trimming']['trimming'])) {
            $trimming = $annotations['paella/trimming']['trimming'];
            $start = max(0.0, (float) $trimming['start']);
            $end = min((float) $realDuration, (float) $trimming['end']);
            if ($end <= $start) {
                $start = 0.0;
                $end = (float) $realDuration;
            }
        }

        $softDuration = $end - $start;
        foreach ($this->mergedBreaks($annotations['paella/breaks']['breaks'] ?? [], $start, $end) as [$breakStart, $breakEnd]) {
            $softDuration -= $breakEnd - $breakStart;
        }
        $softDuration = (int) round($softDuration);

        if ($softDuration > 0 && $softDuration < $realDuration) {
            $multimediaObject->setProperty(self::SOFT_DURATION_PROPERTY, $softDuration);
            $multimediaObject->setDuration($softDuration);
        } else {
            $multimediaObject->removeProperty(self::SOFT_DURATION_PROPERTY);
            $multimediaObject->setDuration($realDuration);
        }
        $this->documentManager->flush();
    }

    private function annotationsOf(MultimediaObject $multimediaObject): array
    {
        $annotations = $this->documentManager->getRepository(Annotation::class)->createQueryBuilder()
            ->field('multimediaObject')->equals(new ObjectId($multimediaObject->getId()))
            ->getQuery()
            ->execute()
        ;

        $values = [];
        foreach ($annotations as $annotation) {
            $values[$annotation->getType()] = json_decode((string) $annotation->getValue(), true) ?: [];
        }

        return $values;
    }

    private function mergedBreaks(array $breaks, float $start, float $end): array
    {
        $ranges = [];
        foreach ($breaks as $break) {
            $breakStart = max($start, (float) ($break['s'] ?? 0));
            $breakEnd = min($end, (float) ($break['e'] ?? 0));
            if ($breakEnd > $breakStart) {
                $ranges[] = [$breakStart, $breakEnd];
            }
        }
        usort($ranges, static fn (array $a, array $b) => $a[0] <=> $b[0]);

        $merged = [];
        foreach ($ranges as $range) {
            $last = array_key_last($merged);
            if (null !== $last && $range[0] <= $merged[$last][1]) {
                $merged[$last][1] = max($merged[$last][1], $range[1]);
            } else {
                $merged[] = $range;
            }
        }

        return $merged;
    }
}
