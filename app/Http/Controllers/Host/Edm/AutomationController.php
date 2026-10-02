<?php

namespace App\Http\Controllers\Host\Edm;

use App\Http\Controllers\Admin\EdmAutomationController;

/** An organizer's own automations: reminders, follow-ups and recovery for their events. */
class AutomationController extends EdmAutomationController
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
