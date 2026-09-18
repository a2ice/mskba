<!doctype html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <title>{{ $campaign->name }} · MSKBA</title>
    <style>
        @page { size: A4; margin: 0; }
        * { box-sizing: border-box; }
        html, body {
            width: 210mm;
            min-height: 297mm;
            margin: 0;
            padding: 0;
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
        }
        body {
            font-family: Arial, Helvetica, sans-serif;
            color: #f8f8f5;
            background:
                radial-gradient(circle at 83% 25%, rgba(255, 103, 0, .11), transparent 26%),
                radial-gradient(circle at 10% 90%, rgba(55, 182, 111, .10), transparent 24%),
                #090b0a;
        }
        .flyer {
            position: relative;
            isolation: isolate;
            min-height: 297mm;
            overflow: hidden;
            padding: 20mm 18mm 16mm;
            display: flex;
            flex-direction: column;
        }
        .flyer::before {
            content: "";
            position: absolute;
            z-index: -2;
            width: 128mm;
            height: 128mm;
            right: -34mm;
            top: 8mm;
            border-radius: 50%;
            background: radial-gradient(circle, rgba(255, 102, 0, .15), rgba(255, 102, 0, .045) 44%, transparent 70%);
            filter: blur(8mm);
        }
        .hero-art {
            position: absolute;
            z-index: -1;
            top: 27mm;
            right: -5mm;
            width: 112mm;
            height: 146mm;
            display: flex;
            align-items: flex-start;
            justify-content: center;
            pointer-events: none;
        }
        .hero-art img {
            display: block;
            width: 112mm;
            height: 112mm;
            object-fit: contain;
            transform: rotate(2deg);
            transform-origin: 56% 52%;
            filter:
                drop-shadow(0 0 8mm rgba(255, 89, 0, .24))
                drop-shadow(0 5mm 7mm rgba(0, 0, 0, .45));
        }
        .brand {
            position: relative;
            z-index: 2;
            min-height: 24mm;
            display: flex;
            align-items: center;
        }
        .brand img {
            display: block;
            width: 58mm;
            height: auto;
        }
        .brand-fallback {
            font-size: 15mm;
            font-weight: 900;
            letter-spacing: -.9mm;
        }
        .hero {
            position: relative;
            z-index: 2;
            margin-top: 16mm;
            max-width: 118mm;
        }
        .eyebrow {
            display: inline-block;
            margin-bottom: 7mm;
            padding: 2.2mm 4.5mm;
            border: .4mm solid rgba(255, 112, 0, .9);
            border-radius: 100mm;
            color: #ff7412;
            background: rgba(9, 11, 10, .66);
            font-size: 3.55mm;
            font-weight: 800;
            letter-spacing: .65mm;
            text-transform: uppercase;
        }
        h1 {
            margin: 0;
            font-size: 19mm;
            line-height: .93;
            letter-spacing: -1.1mm;
            text-transform: uppercase;
        }
        .lead {
            max-width: 108mm;
            margin: 8mm 0 0;
            color: #cecec8;
            font-size: 5.4mm;
            line-height: 1.38;
        }
        .venue {
            margin-top: 9mm;
            font-size: 7mm;
            font-weight: 800;
        }
        .placement {
            margin-top: 2mm;
            color: #aaa9a3;
            font-size: 4mm;
        }
        .conversion {
            position: relative;
            z-index: 2;
            margin-top: auto;
            display: grid;
            grid-template-columns: 1fr 58mm;
            gap: 12mm;
            align-items: end;
            padding-top: 12mm;
        }
        .conversion h2 {
            margin: 0 0 5mm;
            font-size: 10mm;
            line-height: 1.04;
            letter-spacing: -.4mm;
        }
        .steps {
            margin: 0;
            padding: 0;
            list-style: none;
            display: grid;
            gap: 3mm;
            color: #d9d9d3;
            font-size: 4.7mm;
        }
        .steps strong { color: #ff7412; }
        .qr-card {
            padding: 5mm;
            border-radius: 6mm;
            background: #fff;
            color: #111;
            text-align: center;
        }
        .qr-card img {
            display: block;
            width: 48mm;
            height: 48mm;
            margin: 0 auto 3mm;
        }
        .qr-card strong {
            display: block;
            font-size: 3.5mm;
        }
        .url {
            position: relative;
            z-index: 2;
            margin-top: 8mm;
            padding-top: 5mm;
            border-top: .3mm solid rgba(255, 255, 255, .16);
            color: #85867f;
            font-size: 3.2mm;
            overflow-wrap: anywhere;
        }
        .accent { color: #ff7412; }
    </style>
</head>
<body>
<main class="flyer">
    @if($heroImageDataUri ?? null)
        <div class="hero-art" aria-hidden="true">
            <img src="{{ $heroImageDataUri }}" alt="">
        </div>
    @endif

    <div class="brand">
        @if($logoDataUri)
            <img src="{{ $logoDataUri }}" alt="MSKBA">
        @else
            <span class="brand-fallback">MSK<span class="accent">BA</span></span>
        @endif
    </div>

    <section class="hero">
        @if(($templateFields['eyebrow'] ?? '') !== '')
            <span class="eyebrow">{{ $templateFields['eyebrow'] }}</span>
        @endif
        <h1>
            {{ $templateFields['headline'] ?? '' }}
            @if(($templateFields['headline_accent'] ?? '') !== '')
                <br><span class="accent">{{ $templateFields['headline_accent'] }}</span>
            @endif
        </h1>
        @if(($templateFields['lead'] ?? '') !== '')
            <p class="lead">{{ $templateFields['lead'] }}</p>
        @endif

        @if(($templateFields['venue_title'] ?? '') !== '')
            <div class="venue">{{ $templateFields['venue_title'] }}</div>
        @endif
        @if(($templateFields['venue_subtitle'] ?? '') !== '')
            <div class="placement">{{ $templateFields['venue_subtitle'] }}</div>
        @endif
    </section>

    <section class="conversion">
        <div>
            <h2>
                {{ $templateFields['cta_line_1'] ?? '' }}
                @if(($templateFields['cta_line_2'] ?? '') !== '')<br>{{ $templateFields['cta_line_2'] }}@endif
                @if(($templateFields['cta_line_accent'] ?? '') !== '')<br><span class="accent">{{ $templateFields['cta_line_accent'] }}</span>@endif
            </h2>
            <ol class="steps">
                @if(($templateFields['step_1'] ?? '') !== '')<li><strong>01</strong> {{ $templateFields['step_1'] }}</li>@endif
                @if(($templateFields['step_2'] ?? '') !== '')<li><strong>02</strong> {{ $templateFields['step_2'] }}</li>@endif
                @if(($templateFields['step_3'] ?? '') !== '')<li><strong>03</strong> {{ $templateFields['step_3'] }}</li>@endif
            </ol>
        </div>
        <div class="qr-card">
            <img src="{{ $qrDataUri }}" alt="QR-код">
            @if(($templateFields['qr_caption'] ?? '') !== '')
                <strong>{{ $templateFields['qr_caption'] }}</strong>
            @endif
        </div>
    </section>

    <div class="url">{{ $joinUrl }}</div>
</main>
</body>
</html>
