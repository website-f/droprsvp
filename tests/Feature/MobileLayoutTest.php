<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Layout rules that are easy to break and invisible until someone opens the app
 * on a phone.
 *
 * A wide table inside `overflow-hidden` does not shrink — it is CLIPPED, so the
 * last columns simply cannot be reached on a narrow screen, with no scrollbar to
 * hint that anything is missing. That is what happened to the admin events list,
 * the CMS lists, host orders and host invoices: five tables, the same one-word
 * mistake. A source scan catches the sixth before it ships.
 */
class MobileLayoutTest extends TestCase
{
    /** Where the interface lives. */
    private function sourceFiles(): array
    {
        $directory = new \RecursiveDirectoryIterator(resource_path('js'), \FilesystemIterator::SKIP_DOTS);
        $files = [];

        foreach (new \RecursiveIteratorIterator($directory) as $file) {
            if ($file->getExtension() === 'tsx') {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }

    public function test_every_table_can_be_scrolled_sideways(): void
    {
        $offenders = [];

        foreach ($this->sourceFiles() as $file) {
            $source = file_get_contents($file);

            if (! str_contains($source, '<table')) {
                continue;
            }

            // The wrapper does not have to be adjacent — some tables sit a few
            // elements deep — so this asks the weaker question: does the file
            // that renders a table provide any horizontal scrolling at all?
            if (! preg_match('/overflow-x-auto|overflow-auto/', $source)) {
                $offenders[] = str_replace(base_path(), '', str_replace('\\', '/', $file));
            }
        }

        $this->assertSame([], $offenders, implode("\n", [
            'These files render a <table> with no horizontal scroll container, so',
            'on a phone the right-hand columns are clipped and unreachable. Wrap it:',
            '',
            '    <div className="overflow-x-auto rounded-xl border border-border">',
            '        <table className="w-full min-w-[720px] text-sm">',
            '',
            ...$offenders,
            '',
        ]));
    }

    public function test_no_table_is_wrapped_in_overflow_hidden(): void
    {
        $offenders = [];

        foreach ($this->sourceFiles() as $file) {
            $source = file_get_contents($file);

            if (! str_contains($source, '<table')) {
                continue;
            }

            // The exact mistake: the wrapper looks right, and clips.
            if (preg_match('/overflow-hidden[^"]*"\s*>\s*<table/s', $source)) {
                $offenders[] = str_replace(base_path(), '', str_replace('\\', '/', $file));
            }
        }

        $this->assertSame([], $offenders, implode("\n", [
            'A <table> is wrapped in overflow-hidden, which clips the columns that',
            'do not fit instead of letting them scroll. Use overflow-x-auto:',
            '',
            ...$offenders,
            '',
        ]));
    }
}
