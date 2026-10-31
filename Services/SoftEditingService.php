<?php

declare(strict_types=1);

namespace Pumukit\SoftVideoEditorBundle\Services;

use Doctrine\ODM\MongoDB\DocumentManager;
use MongoDB\BSON\ObjectId;
use Pumukit\SchemaBundle\Document\Annotation;
use Pumukit\SchemaBundle\Document\MultimediaObject;
use Pumukit\SchemaBundle\Event\AnnotationsEvents;
use Pumukit\SchemaBundle\Event\AnnotationsUpdateEvent;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

class SoftEditingService
{
    public const TRIMMING = 'paella/trimming';
    public const BREAKS = 'paella/breaks';
    public const MARKS = 'paella/marks';
    private const MAX_TEXT_LENGTH = 255;

    public function __construct(
        private readonly DocumentManager $documentManager,
        private readonly EventDispatcherInterface $eventDispatcher,
    ) {}

    public function load(MultimediaObject $multimediaObject): array
    {
        $values = [];
        foreach ($this->annotations($multimediaObject) as $annotation) {
            $values[$annotation->getType()] = json_decode((string) $annotation->getValue(), true) ?: [];
        }

        $trimming = $values[self::TRIMMING]['trimming'] ?? null;

        return [
            'trimming' => isset($trimming['start'], $trimming['end']) ? ['start' => (float) $trimming['start'], 'end' => (float) $trimming['end']] : null,
            'breaks' => array_values(array_map(static fn (array $break) => [
                's' => (float) ($break['s'] ?? 0),
                'e' => (float) ($break['e'] ?? 0),
                'text' => (string) ($break['text'] ?? $break['name'] ?? $break['content'] ?? ''),
            ], array_filter($values[self::BREAKS]['breaks'] ?? [], 'is_array'))),
            'marks' => array_values(array_map(static fn (array $mark) => [
                's' => (float) ($mark['s'] ?? 0),
                'name' => (string) ($mark['name'] ?? $mark['content'] ?? ''),
            ], array_filter($values[self::MARKS]['marks'] ?? [], 'is_array'))),
        ];
    }

    public function save(MultimediaObject $multimediaObject, array $data, string $userId): array
    {
        $edition = $this->normalize($data, (float) $multimediaObject->getRealDuration());

        $breaks = [];
        foreach ($edition['breaks'] as $index => $break) {
            $breaks[] = ['id' => $index + 1, 's' => $break['s'], 'e' => $break['e'], 'text' => $break['text'], 'name' => $break['text'], 'content' => $break['text']];
        }
        $marks = [];
        foreach ($edition['marks'] as $index => $mark) {
            $marks[] = ['id' => $index + 1, 's' => $mark['s'], 'e' => $mark['s'], 'name' => $mark['name'], 'content' => $mark['name']];
        }

        $this->store($multimediaObject, self::TRIMMING, ['trimming' => $edition['trimming']], $userId);
        $this->store($multimediaObject, self::BREAKS, ['breaks' => $breaks], $userId);
        $this->store($multimediaObject, self::MARKS, ['marks' => $marks], $userId);
        $this->documentManager->flush();

        $this->eventDispatcher->dispatch(new AnnotationsUpdateEvent($multimediaObject->getId()), AnnotationsEvents::UPDATE);

        return $edition;
    }

    private function normalize(array $data, float $duration): array
    {
        $limit = $duration > 0 ? $duration : (float) ($data['trimming']['end'] ?? 0);
        if ($limit <= 0) {
            throw new \InvalidArgumentException('Unknown duration');
        }

        $start = $this->time($data['trimming']['start'] ?? 0, $limit);
        $end = $this->time($data['trimming']['end'] ?? $limit, $limit);
        if ($end <= $start) {
            throw new \InvalidArgumentException('The end of the trimming must be after its start');
        }

        $breaks = [];
        foreach ($this->items($data['breaks'] ?? []) as $break) {
            $breakStart = $this->time($break['s'] ?? null, $limit);
            $breakEnd = $this->time($break['e'] ?? null, $limit);
            if ($breakEnd <= $breakStart) {
                throw new \InvalidArgumentException('The end of a break must be after its start');
            }
            $breakStart = max($breakStart, $start);
            $breakEnd = min($breakEnd, $end);
            if ($breakEnd <= $breakStart) {
                throw new \InvalidArgumentException('A break must be inside the trimming');
            }
            $breaks[] = ['s' => $breakStart, 'e' => $breakEnd, 'text' => $this->text($break['text'] ?? '')];
        }
        if ($breaks && $start > 0) {
            throw new \InvalidArgumentException('Breaks cannot be combined with a trimmed start');
        }
        usort($breaks, static fn (array $a, array $b) => $a['s'] <=> $b['s']);

        $marks = [];
        foreach ($this->items($data['marks'] ?? []) as $mark) {
            $marks[] = ['s' => $this->time($mark['s'] ?? null, $limit), 'name' => $this->text($mark['name'] ?? '')];
        }
        usort($marks, static fn (array $a, array $b) => $a['s'] <=> $b['s']);

        return ['trimming' => ['start' => $start, 'end' => $end], 'breaks' => $breaks, 'marks' => $marks];
    }

    private function items(mixed $items): array
    {
        if (!is_array($items)) {
            throw new \InvalidArgumentException('Expected a list');
        }

        return array_filter($items, 'is_array');
    }

    private function time(mixed $value, float $limit): float
    {
        if (!is_int($value) && !is_float($value) && !(is_string($value) && is_numeric($value))) {
            throw new \InvalidArgumentException('Expected a time in seconds');
        }

        return round(min(max(0.0, (float) $value), $limit), 1);
    }

    private function text(mixed $value): string
    {
        return mb_substr(trim(strip_tags((string) $value)), 0, self::MAX_TEXT_LENGTH);
    }

    private function store(MultimediaObject $multimediaObject, string $type, array $value, string $userId): void
    {
        $annotations = array_values(array_filter($this->annotations($multimediaObject), static fn (Annotation $annotation) => $annotation->getType() === $type));
        $annotation = array_shift($annotations);
        foreach ($annotations as $duplicate) {
            $this->documentManager->remove($duplicate);
        }

        if (!$annotation instanceof Annotation) {
            $annotation = new Annotation();
            $annotation->setMultimediaObject(new ObjectId($multimediaObject->getId()));
            $annotation->setType($type);
            $annotation->setInPoint(0);
            $annotation->setOutPoint(100);
            $annotation->setLength(0);
            $annotation->setPrivate(false);
            $annotation->setCreated(new \DateTime());
            $annotation->setUserId($userId);
            $this->documentManager->persist($annotation);
        }
        $annotation->setValue(json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }

    private function annotations(MultimediaObject $multimediaObject): array
    {
        return $this->documentManager->getRepository(Annotation::class)->createQueryBuilder()
            ->field('multimediaObject')->equals(new ObjectId($multimediaObject->getId()))
            ->field('type')->in([self::TRIMMING, self::BREAKS, self::MARKS])
            ->getQuery()
            ->execute()
            ->toArray()
        ;
    }
}
