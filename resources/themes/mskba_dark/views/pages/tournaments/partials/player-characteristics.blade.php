<details class="border rounded p-3 mt-3">
    <summary><strong>Амплуа: {{ $characteristics['position'] }}</strong></summary>
    <details class="border rounded p-3 mt-3">
        <summary><strong>Физические данные</strong></summary>
        <dl class="mt-3 mb-0">
            @foreach($characteristics['physical'] as $label => $value)
                <div class="d-flex justify-content-between gap-3"><dt>{{ $label }}</dt><dd>{{ $value }}</dd></div>
            @endforeach
        </dl>
    </details>
    <details class="border rounded p-3 mt-3">
        <summary><strong>Игровые показатели</strong></summary>
        <dl class="mt-3 mb-0">
            @foreach($characteristics['game'] as $label => $value)
                <div class="d-flex justify-content-between gap-3"><dt>{{ $label }}</dt><dd>{{ $value }}</dd></div>
            @endforeach
        </dl>
    </details>
</details>
