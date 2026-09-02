@php($style = ($style ?? []) + \App\Support\ReceiptTemplate::DEFAULTS)
@php($logo = $style['show_logo'] && $style['logo'] ? '<img src="'.e($style['logo']).'" alt="" style="max-height: 34px; margin-bottom: 8px;">' : '')
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        * { font-family: DejaVu Sans, sans-serif; }
        body { margin: 0; color: #27272a; font-size: 12px; }
        .wrap { padding: 36px 40px; }
        .muted { color: #8a8a92; }
        .right { text-align: right; }
        .eyebrow { font-size: 9px; letter-spacing: 1px; text-transform: uppercase; color: #8a8a92; }
        h1.doc { margin: 0; font-size: 18px; }
        .seller { font-size: 16px; font-weight: bold; }
        .badge { display: inline-block; padding: 3px 9px; border-radius: 20px; font-size: 10px; font-weight: bold; text-transform: capitalize; }
        .paid { background: #dcfce7; color: #166534; }
        .refunded { background: #fef3c7; color: #92400e; }
        table { width: 100%; border-collapse: collapse; }
        .rule td { border-top: 1px solid #e7e7ea; }
        .items th { border-top: 1px solid #e7e7ea; border-bottom: 1px solid #e7e7ea; padding: 8px 0; font-size: 9px; letter-spacing: .5px; text-transform: uppercase; color: #8a8a92; text-align: left; }
        .items td { padding: 9px 0; border-bottom: 1px solid #f0f0f2; }
        .totals td { padding: 4px 0; }
        .total-row td { border-top: 1px solid #e7e7ea; padding-top: 8px; font-size: 14px; font-weight: bold; }
        .footer { margin-top: 44px; border-top: 1px solid #e7e7ea; padding-top: 14px; text-align: center; color: #8a8a92; font-size: 10px; }
    </style>
</head>
<body>
<div class="wrap">
    {{-- Header --}}
    <table>
        <tr>
            <td style="width: 60%; vertical-align: top;">
                @if ($style['logo_align'] === 'left'){!! $logo !!}@endif
                <div class="seller">{{ $receipt['seller']['name'] }}</div>
                @if ($style['show_seller_detail'] && $receipt['seller']['detail'])
                    <div class="muted" style="margin-top: 3px;">{{ $receipt['seller']['detail'] }}</div>
                @endif
            </td>
            <td style="width: 40%; vertical-align: top;" class="right">
                @if ($style['logo_align'] === 'right'){!! $logo !!}@endif
                <div class="eyebrow">{{ $style['title'] ?: $receipt['title'] }}</div>
                <h1 class="doc" style="color: {{ $style['accent'] }};">{{ $receipt['number'] }}</h1>
                <div class="muted" style="margin-top: 3px;">{{ $receipt['date'] }}</div>
                @if ($style['show_status'])
                    <div style="margin-top: 7px;">
                        <span class="badge {{ $receipt['status'] === 'refunded' ? 'refunded' : 'paid' }}">{{ $receipt['status'] }}</span>
                    </div>
                @endif
            </td>
        </tr>
    </table>

    @if ($style['header_note'])
        <div style="margin-top: 14px; color: {{ $style['accent'] }}; font-weight: bold;">{{ $style['header_note'] }}</div>
    @endif

    {{-- Parties --}}
    <table style="margin-top: 26px;">
        <tr>
            <td style="width: 60%; vertical-align: top;">
                <div class="eyebrow">{{ $receipt['party_label'] }}</div>
                <div style="margin-top: 4px; font-weight: bold;">{{ $receipt['party']['name'] ?: '—' }}</div>
                @if ($receipt['party']['detail'])
                    <div class="muted">{{ $receipt['party']['detail'] }}</div>
                @endif
            </td>
            @if ($style['show_context'] && $receipt['context'])
                <td style="width: 40%; vertical-align: top;" class="right">
                    <div class="eyebrow">For</div>
                    <div style="margin-top: 4px; font-weight: bold;">{{ $receipt['context'] }}</div>
                </td>
            @endif
        </tr>
    </table>

    {{-- Line items --}}
    <table class="items" style="margin-top: 26px;">
        <thead>
            <tr>
                <th style="width: 52%;">Description</th>
                <th style="width: 12%; text-align: right;">Qty</th>
                <th style="width: 18%; text-align: right;">Unit</th>
                <th style="width: 18%; text-align: right;">Amount</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($receipt['items'] as $it)
                <tr>
                    <td>{{ $it['description'] }}</td>
                    <td class="right">{{ $it['qty'] }}</td>
                    <td class="right">{{ $receipt['currency'] }} {{ number_format($it['unit'], 2) }}</td>
                    <td class="right">{{ $receipt['currency'] }} {{ number_format($it['total'], 2) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    {{-- Totals --}}
    <table style="margin-top: 18px;">
        <tr>
            <td style="width: 62%;"></td>
            <td style="width: 38%;">
                <table class="totals">
                    <tr><td class="muted">Subtotal</td><td class="right">{{ $receipt['currency'] }} {{ number_format($receipt['subtotal'], 2) }}</td></tr>
                    @if ($style['show_tax'] && $receipt['tax'] > 0)
                        <tr><td class="muted">Tax</td><td class="right">{{ $receipt['currency'] }} {{ number_format($receipt['tax'], 2) }}</td></tr>
                    @endif
                    <tr class="total-row"><td style="color: {{ $style['accent'] }};">Total</td><td class="right" style="color: {{ $style['accent'] }};">{{ $receipt['currency'] }} {{ number_format($receipt['total'], 2) }}</td></tr>
                </table>
            </td>
        </tr>
    </table>

    @if (trim($style['notes']) !== '')
        <div style="margin-top: 28px; padding-top: 12px; border-top: 1px solid #e7e7ea; color: #8a8a92; font-size: 10px; white-space: pre-line;">{{ $style['notes'] }}</div>
    @endif

    <div class="footer">{{ $style['footer_note'] }}</div>
</div>
</body>
</html>
