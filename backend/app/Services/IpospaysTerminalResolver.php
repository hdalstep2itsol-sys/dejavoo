<?php

namespace App\Services;

use App\Enums\IpospaysFeedEventStatus;
use App\Exceptions\IpospaysTerminalResolutionException;
use App\Models\DejavooTerminal;

class IpospaysTerminalResolver
{
    public function resolve(?string $tpn, ?string $termId): DejavooTerminal
    {
        $tpn = $this->normalize($tpn);
        $termId = $this->normalize($termId);
        $byTpn = $tpn === null ? null : DejavooTerminal::query()->where('tpn', $tpn)->first();
        $byTermId = $termId === null
            ? null
            : DejavooTerminal::query()->where('term_id', $termId)->first();

        if ($tpn !== null && $termId !== null) {
            if (! $byTpn || ! $byTermId || ! $byTpn->is($byTermId)) {
                throw new IpospaysTerminalResolutionException(
                    IpospaysFeedEventStatus::Conflict,
                    'terminal_identifier_conflict',
                );
            }

            $terminal = $byTpn;
        } else {
            $terminal = $byTpn ?? $byTermId;
        }

        if (! $terminal) {
            throw new IpospaysTerminalResolutionException(
                IpospaysFeedEventStatus::TerminalUnknown,
                'terminal_unknown',
            );
        }

        if (! $terminal->is_active) {
            throw new IpospaysTerminalResolutionException(
                IpospaysFeedEventStatus::TerminalInactive,
                'terminal_inactive',
            );
        }

        $terminal->loadMissing('location');
        if (! $terminal->location->is_active) {
            throw new IpospaysTerminalResolutionException(
                IpospaysFeedEventStatus::LocationInactive,
                'location_inactive',
            );
        }

        return $terminal;
    }

    private function normalize(?string $identifier): ?string
    {
        if ($identifier === null || trim($identifier) === '') {
            return null;
        }

        return trim($identifier);
    }
}
