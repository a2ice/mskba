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
                radial-gradient(circle at 82% 15%, rgba(255, 103, 0, .25), transparent 24%),
                radial-gradient(circle at 10% 90%, rgba(55, 182, 111, .13), transparent 24%),
                #090b0a;
        }
        .flyer {
            position: relative;
            min-height: 297mm;
            overflow: hidden;
            padding: 20mm 18mm 16mm;
            display: flex;
            flex-direction: column;
        }
        .flyer::before {
            content: "";
            position: absolute;
            width: 155mm;
            height: 155mm;
            right: -64mm;
            top: -54mm;
            border: 1.5mm solid rgba(255, 105, 0, .28);
            border-radius: 50%;
            box-shadow: 0 0 35mm rgba(255, 105, 0, .14);
        }
        .brand { position: relative; z-index: 1; min-height: 24mm; display: flex; align-items: center; }
        .brand img { display: block; width: 58mm; height: auto; }
        .brand-fallback { font-size: 15mm; font-weight: 900; letter-spacing: -.9mm; }
        .hero { position: relative; z-index: 1; margin-top: 16mm; max-width: 160mm; }
        .eyebrow {
            display: inline-block;
            margin-bottom: 7mm;
            padding: 2.2mm 4.5mm;
            border: .4mm solid rgba(255, 112, 0, .9);
            border-radius: 100mm;
            color: #ff7412;
            font-size: 4mm;
            font-weight: 800;
            letter-spacing: .8mm;
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
            max-width: 132mm;
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
        .placement { margin-top: 2mm; color: #aaa9a3; font-size: 4mm; }
        .conversion {
            position: relative;
            z-index: 1;
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
        .qr-card img { display: block; width: 48mm; height: 48mm; margin: 0 auto 3mm; }
        .qr-card strong { display: block; font-size: 3.5mm; }
        .url {
            position: relative;
            z-index: 1;
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
    <div class="brand">
        @if($logoDataUri)
            <img src="{{ $logoDataUri }}" alt="MSKBA">
        @else
            <span class="brand-fallback">MSK<span class="accent">BA</span></span>
        @endif
    </div>

    <section class="hero">
        <span class="eyebrow">Московская Баскетбольная Ассоциация</span>
        <h1>Баскетбол<br><span class="accent">рядом.</span></h1>
        <p class="lead">
            Игры, тренировки, команды, секции и площадки — в одном баскетбольном сообществе.
        </p>

        @if($venue)
            <div class="venue">{{ $venue->name }}</div>
        @endif
        @if($placement !== '')
            <div class="placement">{{ $placement }}</div>
        @endif
    </section>

    <section class="conversion">
        <div>
            <h2>Сканируй.<br>Выбери роль.<br><span class="accent">Присоединяйся.</span></h2>
            <ol class="steps">
                <li><strong>01</strong> Открой MSKBA по QR-коду.</li>
                <li><strong>02</strong> Выбери, зачем ты здесь: играть, тренировать, организовывать.</li>
                <li><strong>03</strong> Найди людей и баскетбол рядом с собой.</li>
            </ol>
        </div>
        <div class="qr-card">
            <img src="{{ $qrDataUri }}" alt="QR-код">
            <strong>Открыть MSKBA</strong>
        </div>
    </section>

    <div class="url">{{ $joinUrl }}</div>
</main>
</body>
</html>
