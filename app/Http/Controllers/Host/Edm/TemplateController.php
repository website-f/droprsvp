<?php

namespace App\Http\Controllers\Host\Edm;

use App\Http\Controllers\Admin\EdmTemplateController;

/** An organizer's own template library, plus the built-in starters. */
class TemplateController extends EdmTemplateController
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
