<?php

namespace App\Http\Controllers\Host\Edm;

use App\Http\Controllers\Admin\EdmCampaignController;
use App\Models\EdmAccount;
use App\Models\EdmSendingDomain;
use App\Models\EmailCampaign;
use App\Services\Edm\Credits;

/**
 * An organizer's campaigns. Everything is the platform's campaign controller,
 * scoped to the signed-in organizer — same builder, test, send and reports —
 * plus what only organizers have: credits, their account standing, and their
 * own verified sending domains.
 */
class CampaignController extends EdmCampaignController
{
    protected function scopeId(): ?int
    {
        return (int) request()->user()->id;
    }

    protected function base(): string
    {
        return '/host/edm';
    }

    protected function page(string $name): string
    {
        return 'host/edm/'.$name;
    }

    protected function extra(string $page, ?EmailCampaign $campaign = null): array
    {
        $user = request()->user();
        $account = EdmAccount::where('organizer_id', $user->id)->first();

        return [
            'credits' => Credits::summary($user),
            'account' => [
                'suspended' => (bool) $account?->isSuspended(),
                'reason' => $account?->suspended_reason,
            ],
            'domains' => EdmSendingDomain::where('organizer_id', $user->id)->where('status', 'verified')->pluck('domain'),
        ];
    }
}
