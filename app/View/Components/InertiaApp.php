<?php

namespace App\View\Components;

use Inertia\View\Components\App;

/**
 * Inertia's mount point, with the page's real content already inside it.
 *
 * Production has no Node, so there is no Inertia SSR: the stock component emits
 * the page data as JSON and an EMPTY <div id="app">, and everything a person
 * reads is drawn by React afterwards. Crawlers that do not run JavaScript —
 * which is most AI fetchers, link unfurlers and many SEO tools — therefore got
 * a title and nothing else.
 *
 * The content used to be emitted in a <noscript> beside the mount point. That
 * turned out not to be enough: the common HTML-to-text extractors these tools
 * use (Readability and friends) discard <noscript> entirely, so the page still
 * read as blank. It now lives INSIDE #app, as ordinary body content.
 *
 * That is safe for the React app: Inertia only hydrates when #app is marked
 * data-server-rendered (real SSR). Without the marker it calls createRoot()
 * .render(), which replaces whatever is inside — so this markup is shown to
 * anything without JavaScript and simply swapped out for the full UI by
 * everything with it. The content is the same facts the React page shows, not
 * an alternative version of it.
 *
 * Identical to the parent otherwise, including the real-SSR branch, so turning
 * on Inertia SSR later still works untouched.
 */
class InertiaApp extends App
{
    public function render(): string
    {
        return <<<'blade'
@if($response)
{!! $response->body !!}
@else
<script data-page="{{ $id }}" type="application/json">{!! $pageJson !!}</script><div id="{{ $id }}">{{ $slot }}</div>
@endif
blade;
    }
}
