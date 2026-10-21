<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Etiqueta {{ $pallet->pallet_code }}</title>
    {{-- Self-contained on purpose: prints the same without the app's assets. Final layout is DP-07. --}}
    <style>
        @page { size: {{ $widthMm }}mm {{ $heightMm }}mm; margin: 0; }
        * { box-sizing: border-box; }
        body { margin: 0; font-family: Arial, Helvetica, sans-serif; color: #000; background: #fff; }
        .label { width: {{ $widthMm }}mm; height: {{ $heightMm }}mm; padding: 6mm; display: flex; flex-direction: column; gap: 3mm; border: 1px dashed #999; }
        .code { font-family: "Courier New", monospace; font-size: 9mm; font-weight: bold; text-align: center; letter-spacing: .5mm; }
        .qr { display: flex; justify-content: center; }
        .qr svg { width: 55mm; height: 55mm; }
        table { width: 100%; border-collapse: collapse; font-size: 4mm; }
        th { text-align: left; font-weight: normal; color: #333; width: 32%; padding: 1mm 0; vertical-align: top; }
        td { font-weight: bold; padding: 1mm 0; }
        .boxes { font-size: 8mm; }
        .meta { margin-top: auto; font-size: 3mm; color: #333; display: flex; justify-content: space-between; }
        .reprint { border: .6mm solid #000; padding: .5mm 2mm; font-weight: bold; }
        .actions { padding: 4mm 6mm; font-family: system-ui, sans-serif; }
        @media print { .actions { display: none; } .label { border: 0; } }
    </style>
</head>
<body>
    <div class="actions">
        <button type="button" onclick="window.print()">Imprimir</button>
        <a href="{{ url()->previous() }}">Volver</a>
    </div>

    <main class="label">
        <div class="code">{{ $pallet->pallet_code }}</div>
        <div class="qr">{!! $qrSvg !!}</div>
        <table>
            <tr><th>Orden</th><td>{{ $pallet->production_order_ref }}</td></tr>
            <tr><th>Producto</th><td>{{ $pallet->item_code }}<br><span style="font-weight: normal">{{ $pallet->item->description }}</span></td></tr>
            <tr><th>Lote</th><td>{{ $pallet->lot_code }}</td></tr>
            <tr><th>Vence</th><td>{{ $expiration?->format('d/m/Y') ?? '—' }}</td></tr>
            <tr><th>Cajas</th><td class="boxes">{{ $pallet->boxes_count }}</td></tr>
        </table>
        <div class="meta">
            <span>Impresión #{{ $print->print_number }} · {{ $print->printed_at->timezone(config('app.timezone'))->format('d/m/Y H:i') }}</span>
            @if ($print->print_number > 1)<span class="reprint">REIMPRESIÓN</span>@endif
        </div>
    </main>

    <script>window.addEventListener('load', () => window.print());</script>
</body>
</html>
