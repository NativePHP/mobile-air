<?php

namespace Native\Mobile\Edge\Asgard;

/**
 * Several regions of one component instance, asked for in one request
 * (docs/ASGARD-PROTOCOL.md §3.1). Handed to FragmentSource::batch().
 *
 * Each region is still its own RegionRequest, with its own id, timer and
 * supersede rule. The batch only groups them on the wire: one body with
 * `regions` instead of `region`, sharing the component, state and
 * platform. The source answers with ServerRegion::deliverBatch(), or
 * ServerRegion::failedBatch() when the whole request failed.
 */
final class RegionBatch
{
    /** @param list<RegionRequest> $requests  All for one component instance and state. */
    public function __construct(public readonly array $requests)
    {
        if (count($requests) < 1) {
            throw new \InvalidArgumentException('A batch needs at least one region request.');
        }
    }

    /** @return list<string> */
    public function regions(): array
    {
        return array_map(fn (RegionRequest $request) => $request->region, $this->requests);
    }

    public function component(): string
    {
        return $this->requests[0]->component;
    }

    public function state(): array
    {
        return $this->requests[0]->state;
    }

    /** The body a transport signs, encrypts and sends. */
    public function toArray(): array
    {
        $first = $this->requests[0];

        return [
            'component' => $first->component,
            'regions' => $this->regions(),
            'state' => $first->state,
            'platform' => $first->platform,
        ];
    }
}
