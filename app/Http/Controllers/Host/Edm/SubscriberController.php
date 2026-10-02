<?php

namespace App\Http\Controllers\Host\Edm;

use App\Http\Controllers\Admin\EdmAudienceController;

/**
 * An organizer's subscribers: people who ticked "hear from {organizer}" at
 * checkout. They can see, export and unsubscribe their own list; suppression
 * stays with DropRSVP's admins, since it protects the shared server.
 */
class SubscriberController extends EdmAudienceController
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
}
