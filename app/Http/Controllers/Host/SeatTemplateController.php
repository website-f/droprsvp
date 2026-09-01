<?php

namespace App\Http\Controllers\Host;

use App\Http\Controllers\Controller;
use App\Models\SeatTemplate;
use Illuminate\Http\Request;

class SeatTemplateController extends Controller
{
    /** Save the current seating layout as a reusable template for this organizer. */
    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'sections' => ['required', 'array', 'min:1'],
            'sections.*.name' => ['required', 'string', 'max:120'],
            'sections.*.color' => ['nullable', 'string', 'max:20'],
            'sections.*.kind' => ['required', 'in:seated,ga,stage'],
            'sections.*.price' => ['nullable', 'numeric', 'min:0'],
            'sections.*.rows' => ['nullable', 'integer', 'min:1', 'max:100'],
            'sections.*.cols' => ['nullable', 'integer', 'min:1', 'max:100'],
            'sections.*.capacity' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'sections.*.x' => ['nullable', 'integer'],
            'sections.*.y' => ['nullable', 'integer'],
            'sections.*.width' => ['nullable', 'integer'],
            'sections.*.height' => ['nullable', 'integer'],
            'sections.*.row_label_start' => ['nullable', 'string', 'max:4'],
        ]);

        SeatTemplate::create([
            'user_id' => $request->user()->id,
            'name' => $data['name'],
            'data' => collect($data['sections'])->map(fn ($s) => [
                'name' => $s['name'], 'color' => $s['color'] ?? '#6c63ff', 'kind' => $s['kind'],
                'price' => (float) ($s['price'] ?? 0), 'rows' => $s['rows'] ?? null, 'cols' => $s['cols'] ?? null,
                'capacity' => $s['capacity'] ?? null,
                'x' => $s['x'] ?? 20, 'y' => $s['y'] ?? 20, 'width' => $s['width'] ?? null, 'height' => $s['height'] ?? null,
                'row_label_start' => $s['row_label_start'] ?? 'A',
            ])->all(),
        ]);

        return back()->with('success', 'Seating template saved.');
    }

    public function destroy(Request $request, SeatTemplate $seatTemplate)
    {
        abort_unless($seatTemplate->user_id === $request->user()->id, 403);
        $seatTemplate->delete();

        return back()->with('success', 'Template deleted.');
    }
}
