<?php

namespace App\Support\Edm;

/**
 * Built-in designs every account starts with, alongside its saved templates.
 *
 * Defined in code rather than seeded, so they can never be deleted or edited
 * into something broken, and improve with the app. "Use" copies one into a
 * new campaign; "Customise" copies it into the saved library.
 */
final class StarterTemplates
{
    /** @return list<array{key: string, name: string, description: string, subject: string, preheader: string, design: array}> */
    public static function all(): array
    {
        $root = ['props' => ['brandColor' => Renderer::DEFAULT_BRAND, 'backgroundColor' => '#f3f4f6', 'showLogo' => true]];
        $browse = url('/en-my/all/');

        return [
            [
                'key' => 'spotlight',
                'name' => 'Event spotlight',
                'description' => 'One event, front and centre: a short pitch, the event card and a clear button.',
                'subject' => '{{first_name}}, this one is worth a look',
                'preheader' => 'Date, venue and tickets inside.',
                'design' => ['root' => $root, 'content' => [
                    ['type' => 'Heading', 'props' => ['id' => 'Heading-1', 'text' => 'Don’t miss this one', 'level' => 'h1', 'align' => 'left']],
                    ['type' => 'Text', 'props' => ['id' => 'Text-1', 'align' => 'left', 'html' => '<p>Hi {{first_name}},</p><p>We think you will like this. Here are the details — tickets are going, so do not leave it too late.</p>']],
                    ['type' => 'EventCard', 'props' => ['id' => 'EventCard-1', 'event' => '', 'buttonLabel' => 'Get tickets']],
                    ['type' => 'Text', 'props' => ['id' => 'Text-2', 'align' => 'left', 'html' => '<p>See you there,<br>The DropRSVP team</p>']],
                ]],
            ],
            [
                'key' => 'roundup',
                'name' => 'Weekly round-up',
                'description' => 'Three events in a list, for a "what’s on this week" newsletter.',
                'subject' => 'What’s on this week, {{first_name}}',
                'preheader' => 'Three picks for the days ahead.',
                'design' => ['root' => $root, 'content' => [
                    ['type' => 'Heading', 'props' => ['id' => 'Heading-1', 'text' => 'This week’s picks', 'level' => 'h1', 'align' => 'left']],
                    ['type' => 'Text', 'props' => ['id' => 'Text-1', 'align' => 'left', 'html' => '<p>Hi {{first_name}},</p><p>Here is what we are looking forward to this week.</p>']],
                    ['type' => 'EventCard', 'props' => ['id' => 'EventCard-1', 'event' => '', 'buttonLabel' => 'Details']],
                    ['type' => 'Spacer', 'props' => ['id' => 'Spacer-1', 'size' => 'sm']],
                    ['type' => 'EventCard', 'props' => ['id' => 'EventCard-2', 'event' => '', 'buttonLabel' => 'Details']],
                    ['type' => 'Spacer', 'props' => ['id' => 'Spacer-2', 'size' => 'sm']],
                    ['type' => 'EventCard', 'props' => ['id' => 'EventCard-3', 'event' => '', 'buttonLabel' => 'Details']],
                    ['type' => 'Divider', 'props' => ['id' => 'Divider-1']],
                    ['type' => 'Button', 'props' => ['id' => 'Button-1', 'label' => 'See everything on', 'url' => $browse, 'align' => 'center', 'color' => '']],
                ]],
            ],
            [
                'key' => 'announcement',
                'name' => 'Announcement',
                'description' => 'A headline, an image and a paragraph — for news, a new season, or a line-up reveal.',
                'subject' => 'News from DropRSVP',
                'preheader' => 'Something new we wanted you to hear first.',
                'design' => ['root' => $root, 'content' => [
                    ['type' => 'Image', 'props' => ['id' => 'Image-1', 'src' => '', 'alt' => '', 'href' => '']],
                    ['type' => 'Heading', 'props' => ['id' => 'Heading-1', 'text' => 'Big news', 'level' => 'h1', 'align' => 'center']],
                    ['type' => 'Text', 'props' => ['id' => 'Text-1', 'align' => 'center', 'html' => '<p>Hi {{first_name}}, we have something to share. Tell the story in two or three sentences here.</p>']],
                    ['type' => 'Button', 'props' => ['id' => 'Button-1', 'label' => 'Find out more', 'url' => $browse, 'align' => 'center', 'color' => '']],
                ]],
            ],
            [
                'key' => 'letter',
                'name' => 'Plain letter',
                'description' => 'Just text, like a personal note. Often lands best of all: it looks like real mail.',
                'subject' => 'A quick note, {{first_name}}',
                'preheader' => '',
                'design' => ['root' => ['props' => ['brandColor' => Renderer::DEFAULT_BRAND, 'backgroundColor' => '#ffffff', 'showLogo' => false]], 'content' => [
                    ['type' => 'Text', 'props' => ['id' => 'Text-1', 'align' => 'left', 'html' => '<p>Hi {{first_name}},</p><p>Write as you would to a friend. Keep it short, say one thing, and end with what you would like them to do.</p><p>Thanks,<br>The DropRSVP team</p>']],
                ]],
            ],
        ];
    }

    public static function find(string $key): ?array
    {
        foreach (self::all() as $t) {
            if ($t['key'] === $key) {
                return $t;
            }
        }

        return null;
    }
}
