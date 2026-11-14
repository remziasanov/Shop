{{-- Dompdf supports a CSS 2.1 subset: tables and floats instead of flex/grid. All sizes in pt. --}}
<style>
    @page { margin: 42pt 36pt 52pt 36pt; }
    * { font-family: 'DejaVu Sans', sans-serif; }
    body { font-size: 8.5pt; line-height: 1.35; color: #111111; }
    h1 { font-size: 14pt; margin: 14pt 0 4pt 0; }
    h2 { font-size: 10pt; margin: 12pt 0 3pt 0; }
    p { margin: 0 0 3pt 0; }
    .muted { color: #555555; }
    .right { text-align: right; }
    .nowrap { white-space: nowrap; }
    table { width: 100%; border-collapse: collapse; }
    table.plain td { border: none; padding: 1pt 4pt 1pt 0; vertical-align: top; }
    table.grid { margin-top: 4pt; }
    table.grid th, table.grid td { border: 0.6pt solid #888888; padding: 3pt 4pt; text-align: left; vertical-align: top; }
    table.grid th { background-color: #eeeeee; font-weight: bold; }
    table.grid td.num, table.grid th.num { text-align: right; white-space: nowrap; }
    tr { page-break-inside: avoid; }
    .head td { vertical-align: top; }
    .head { border-bottom: 1.2pt solid #111111; padding-bottom: 6pt; }
    .logo { max-height: 46pt; max-width: 150pt; }
    .order { margin-top: 8pt; }
    .order-head td { background-color: #f3f3f3; border: 0.6pt solid #888888; padding: 3pt 4pt; font-weight: bold; }
    .totals { width: 55%; margin-left: 45%; margin-top: 6pt; }
    .totals td { padding: 1.5pt 0; }
    .totals .grand td { border-top: 0.8pt solid #111111; font-size: 10.5pt; font-weight: bold; padding-top: 4pt; }
    .words { margin-top: 8pt; font-weight: bold; }
    .signs { margin-top: 26pt; }
    .signs td { width: 50%; padding-right: 20pt; vertical-align: bottom; }
    .sign-line { border-top: 0.6pt solid #111111; padding-top: 2pt; font-size: 7.5pt; color: #555555; }
    .stamp { max-height: 70pt; max-width: 160pt; }
    .footer { margin-top: 14pt; font-size: 7.5pt; color: #555555; }
</style>
