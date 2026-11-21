<div class="studio-map-canvas">
    <svg class="studio-map-land" viewBox="0 0 620 350" preserveAspectRatio="xMidYMid slice" aria-hidden="true">
        <rect width="620" height="350" fill="#f5f2ec"/>
        <g fill="#e4edda"><path d="M0 0H112L150 83L84 113H0Z"/><path d="M385 0H620V71L541 90L438 52Z"/><path d="M0 232L96 211L161 267L136 350H0Z"/><path d="M470 226L620 197V350H493L461 297Z"/></g>
        <g fill="#e9e7e3" stroke="#fff" stroke-width="3"><path d="M156 22h88v50h-88zM257 22h76v50h-76zM170 108h82v50h-82zM275 104h80v50h-80zM390 84h94v54h-94zM505 108h90v65h-90zM53 137h93v62h-93zM180 193h84v65h-84zM284 187h80v71h-80zM389 171h71v69h-71zM175 290h109v54H175zM310 285h107v61H310z"/></g>
        <g fill="none" stroke="#fff" stroke-width="10"><path d="M0 119L620 106M0 206L620 181M0 282L620 261M153 0L166 350M268 0L278 350M373 0L382 350M489 0L468 350M605 0L576 350"/></g>
        <g fill="none" stroke="#fff" stroke-width="14"><path d="M0 67Q175 139 330 88T620 53M100 350Q189 232 344 186T620 135M345 0L421 350"/></g>
        <g fill="none" stroke="#f1d58c" stroke-width="7"><path d="M0 67Q175 139 330 88T620 53M100 350Q189 232 344 186T620 135M345 0L421 350"/></g>
        <path d="M468 0L456 350" stroke="#b2b6b9" stroke-width="2" stroke-dasharray="5 5"/>
        <g fill="#46617a" font-size="11" font-family="Arial,sans-serif"><text x="159" y="82">Яхромская</text><text x="75" y="239">Селигерская</text><text x="442" y="158">Дегунино</text><text x="488" y="55">Бескудниково</text><text x="286" y="140" transform="rotate(-10 286 140)">Лобненская улица</text></g>
        <g stroke="#fff" stroke-width="2" fill="#a4c341"><circle cx="191" cy="98" r="8"/><circle cx="113" cy="254" r="8"/></g>
    </svg>
    <div class="studio-map-controls"><button type="button" data-map-zoom="1" aria-label="Приблизить">+</button><button type="button" data-map-zoom="-1" aria-label="Отдалить">−</button></div>
    <button type="button" class="studio-map-pin-button" data-map-current-pin aria-label="{{ $mapPreview['name'] }}"><span class="mskba-venues-map__pin"><span>🏀</span></span></button>
    <button type="button" class="studio-map-pin-button" data-map-nearby-pin aria-label="Много спорта — Алтуфьево" hidden><span class="mskba-venues-map__pin"><span>🏀</span></span></button>
    <div class="studio-map-balloon" data-studio-map-balloon hidden><div class="studio-map-balloon-head"><span data-studio-map-balloon-title></span><button type="button" data-studio-map-balloon-close aria-label="Закрыть">×</button></div><div data-studio-map-balloon-action></div></div>
    <span class="studio-map-label">Демонстрационная карта</span>
</div>
