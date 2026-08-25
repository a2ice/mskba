<svg
    class="account-player-character-svg"
    data-player-character-svg
    viewBox="0 0 360 540"
    xmlns="http://www.w3.org/2000/svg"
    aria-hidden="true"
    focusable="false"
>
    <ellipse class="account-player-character-svg__shadow" cx="183" cy="527" rx="98" ry="10" />

    {{-- Hair behind the head for styles that need depth. --}}
    <g class="account-player-character-svg__hair account-player-character-svg__hair--back" data-character-hairstyle="female_ponytail" hidden>
        <path d="M218 72 C245 80 252 102 247 127 C243 146 247 164 263 176 C240 181 222 168 216 151 C209 131 217 102 218 72Z" />
        <path d="M245 123 C271 126 278 145 271 163 C266 177 257 184 246 188 C251 170 247 149 238 137Z" opacity=".82" />
    </g>
    <g class="account-player-character-svg__hair account-player-character-svg__hair--back" data-character-hairstyle="female_long" hidden>
        <path d="M134 78 C120 105 120 145 126 181 C130 207 123 223 112 238 C135 240 150 229 154 210 L153 120Z" />
        <path d="M223 77 C238 108 240 148 233 185 C229 207 235 223 247 239 C223 241 209 228 206 207 L207 118Z" />
    </g>
    <g class="account-player-character-svg__hair account-player-character-svg__hair--back" data-character-hairstyle="female_braids" hidden>
        <path d="M139 87 C122 120 129 167 123 204 C121 223 114 238 104 250 C117 253 130 246 135 235 C142 217 142 189 145 165Z" />
        <path d="M218 88 C235 121 229 168 235 205 C238 224 245 239 255 250 C242 253 228 246 224 235 C216 215 217 189 212 163Z" />
        <circle cx="120" cy="219" r="5" opacity=".65" />
        <circle cx="237" cy="220" r="5" opacity=".65" />
    </g>

    <g class="account-player-character-svg__body" data-character-body>
        {{-- Rear leg: thigh, knee, shin and ankle are independent anatomical segments. --}}
        <g class="account-player-character-svg__leg account-player-character-svg__leg--rear" data-character-limb="leg">
            <path class="account-player-character-svg__skin" d="M187 294 C203 292 216 299 219 313 L215 387 C213 400 205 408 194 407 C184 406 179 398 180 386 L178 315 C177 305 180 298 187 294Z" />
            <path class="account-player-character-svg__skin account-player-character-svg__skin--shadow" d="M190 398 C204 394 216 401 218 414 L213 490 C212 503 205 511 194 510 C183 509 178 502 179 490 L180 418 C179 408 182 402 190 398Z" />
            <path class="account-player-character-svg__joint" d="M183 392 C191 387 207 388 215 394 C218 400 218 407 215 413 C207 418 191 418 182 412 C179 406 179 399 183 392Z" />
            <path class="account-player-character-svg__skin" d="M189 489 C201 487 211 492 212 502 L210 514 L185 514 L181 502 C181 495 184 491 189 489Z" />
        </g>

        {{-- Front leg. --}}
        <g class="account-player-character-svg__leg account-player-character-svg__leg--front" data-character-limb="leg">
            <path class="account-player-character-svg__skin" d="M139 294 C154 291 168 298 170 312 L166 386 C165 400 157 408 146 408 C135 407 129 400 130 387 L128 316 C127 305 132 298 139 294Z" />
            <path class="account-player-character-svg__skin account-player-character-svg__skin--highlight" d="M137 398 C149 394 163 399 165 412 L158 490 C157 503 149 511 139 510 C128 509 122 501 124 488 L128 417 C128 407 131 401 137 398Z" />
            <path class="account-player-character-svg__joint" d="M130 392 C139 387 155 388 163 394 C166 401 166 408 162 414 C153 419 138 419 129 412 C126 406 126 399 130 392Z" />
            <path class="account-player-character-svg__skin" d="M132 488 C144 486 154 491 156 501 L154 514 L127 514 L123 501 C123 495 127 490 132 488Z" />
        </g>

        {{-- Rear arm: upper arm, elbow, forearm, wrist and hand are separate. --}}
        <g class="account-player-character-svg__arm account-player-character-svg__arm--rear" data-character-limb="arm">
            <path class="account-player-character-svg__skin account-player-character-svg__skin--shadow" d="M225 163 C239 165 247 175 249 189 C250 207 252 222 257 238 C260 250 255 260 246 263 C236 265 228 258 226 248 L218 195 C215 179 217 168 225 163Z" />
            <path class="account-player-character-svg__joint" d="M239 251 C245 247 253 248 258 253 C261 259 259 267 254 271 C247 274 239 271 236 265 C234 260 235 255 239 251Z" />
            <path class="account-player-character-svg__skin account-player-character-svg__skin--shadow" d="M246 267 C256 267 263 274 261 286 L252 329 C250 340 244 346 236 344 C228 342 224 335 227 325 L234 279 C236 271 240 268 246 267Z" />
            <path class="account-player-character-svg__skin" d="M232 337 C240 335 247 339 249 346 L247 361 C245 369 238 374 231 371 L225 362 C223 357 224 351 227 348 L224 344 C223 341 226 338 229 340Z" />
        </g>

        {{-- Front arm. --}}
        <g class="account-player-character-svg__arm account-player-character-svg__arm--front" data-character-limb="arm">
            <path class="account-player-character-svg__skin account-player-character-svg__skin--highlight" d="M132 162 C117 166 109 177 107 192 C104 211 102 229 97 247 C94 259 99 268 108 271 C118 273 126 266 128 256 L138 198 C141 180 139 168 132 162Z" />
            <path class="account-player-character-svg__joint" d="M101 260 C108 256 117 258 121 264 C124 270 121 278 115 282 C108 284 100 281 97 275 C95 269 97 264 101 260Z" />
            <path class="account-player-character-svg__skin account-player-character-svg__skin--highlight" d="M106 278 C96 279 91 287 93 299 L103 342 C105 353 111 359 120 357 C128 355 132 347 129 337 L121 292 C119 282 113 278 106 278Z" />
            <path class="account-player-character-svg__skin" d="M109 349 C117 346 125 350 128 357 L129 370 C128 378 121 383 114 381 L105 374 C101 370 101 363 104 359 L101 356 C99 353 102 349 106 351Z" />
        </g>

        {{-- Neck and head. --}}
        <path class="account-player-character-svg__skin account-player-character-svg__neck" d="M160 132 C166 139 194 139 201 132 L205 166 C193 177 166 177 154 166Z" />
        <ellipse class="account-player-character-svg__skin account-player-character-svg__ear" cx="137" cy="96" rx="8" ry="13" />
        <ellipse class="account-player-character-svg__skin account-player-character-svg__ear account-player-character-svg__ear--rear" cx="221" cy="95" rx="7" ry="12" />
        <path class="account-player-character-svg__skin account-player-character-svg__face" d="M179 43 C151 43 136 62 137 88 C138 111 146 130 162 140 C171 146 183 148 193 143 C210 135 220 114 222 90 C224 64 208 44 179 43Z" />
        <path class="account-player-character-svg__skin account-player-character-svg__skin--highlight account-player-character-svg__face-highlight" d="M154 59 C144 72 145 101 154 119 C160 132 170 139 180 140 C168 127 164 106 165 84 C165 71 169 60 176 51 C167 51 160 54 154 59Z" opacity=".32" />

        {{-- Subtle gender-dependent torso underneath the uniform keeps silhouette believable at arm holes. --}}
        <path class="account-player-character-svg__skin account-player-character-svg__torso-base" data-character-gender-layer="male" d="M137 159 C151 151 164 149 179 149 C199 149 217 154 229 164 L218 286 C198 296 158 297 137 286 L126 178 C126 169 130 163 137 159Z" />
        <path class="account-player-character-svg__skin account-player-character-svg__torso-base" data-character-gender-layer="female" d="M140 160 C152 153 164 151 179 151 C196 151 211 155 222 163 C229 187 228 216 224 243 C221 267 216 284 208 296 C192 301 165 301 149 295 C140 280 135 260 133 239 C130 210 130 181 140 160Z" hidden />
    </g>

    {{-- Basketball uniform as an equipment layer. --}}
    <g class="account-player-character-svg__uniform" data-character-uniform>
        <path class="account-player-character-svg__jersey" data-character-gender-layer="male" d="M139 155 C151 151 163 150 179 150 C197 150 215 154 228 162 L242 184 L224 203 L217 286 C196 296 160 297 137 286 L130 203 L113 184 L129 162Z" />
        <path class="account-player-character-svg__jersey" data-character-gender-layer="female" d="M141 157 C153 153 164 151 179 151 C195 151 210 154 221 161 L237 184 L220 202 L215 285 C196 295 162 296 143 286 L136 202 L120 184 L135 162Z" hidden />
        <path class="account-player-character-svg__jersey-shadow" d="M139 279 C158 287 198 288 218 279 L217 291 C195 300 159 301 137 291Z" />
        <path class="account-player-character-svg__uniform-accent-stroke" d="M159 154 C164 169 194 169 200 154" />
        <path class="account-player-character-svg__uniform-accent-stroke" d="M130 165 C129 181 122 192 114 199" />
        <path class="account-player-character-svg__uniform-accent-stroke" d="M228 165 C230 181 236 191 242 198" />

        <g data-character-uniform-pattern="mskba_home">
            <path class="account-player-character-svg__uniform-accent" d="M137 181 L148 178 L153 280 L137 287 L130 205Z" />
            <path class="account-player-character-svg__uniform-accent" d="M220 180 L230 184 L224 207 L218 286 L205 281 L210 183Z" opacity=".8" />
        </g>
        <g data-character-uniform-pattern="mskba_light" hidden>
            <path class="account-player-character-svg__uniform-accent" d="M125 174 C139 184 152 188 164 189 L159 200 C143 199 127 192 115 183Z" />
            <path class="account-player-character-svg__uniform-secondary" d="M194 151 C207 153 220 157 228 163 L238 181 L226 190 C216 177 206 168 194 165Z" />
        </g>
        <g data-character-uniform-pattern="street_black" hidden>
            <path class="account-player-character-svg__uniform-secondary" d="M163 151 L190 151 L195 290 L163 291Z" opacity=".68" />
            <path class="account-player-character-svg__uniform-accent" d="M172 151 L181 151 L185 291 L176 291Z" />
        </g>
        <g data-character-uniform-pattern="city_night" hidden>
            <path class="account-player-character-svg__uniform-accent" d="M128 172 L141 157 L225 267 L218 289 L203 288Z" opacity=".88" />
            <path class="account-player-character-svg__uniform-secondary" d="M145 156 L158 152 L228 241 L225 263Z" opacity=".7" />
        </g>

        <text class="account-player-character-svg__chest-mark" x="178" y="211" text-anchor="middle">MSKBA</text>
        <text class="account-player-character-svg__jersey-number" x="178" y="259" text-anchor="middle">00</text>

        <path class="account-player-character-svg__shorts" d="M136 286 C156 296 200 296 220 286 L224 333 L199 338 L179 326 L159 339 L133 334Z" />
        <path class="account-player-character-svg__uniform-accent" d="M135 292 L149 297 L146 333 L133 334Z" />
        <path class="account-player-character-svg__uniform-accent" d="M207 296 L220 291 L224 333 L212 336Z" opacity=".82" />
        <path class="account-player-character-svg__shorts-seam" d="M179 296 L179 326" />

        <path class="account-player-character-svg__sock" d="M126 466 L159 466 L156 503 L124 503Z" />
        <path class="account-player-character-svg__sock" d="M180 466 L213 466 L211 503 L180 503Z" />

        <path class="account-player-character-svg__shoe" d="M121 499 C132 497 148 499 157 505 L171 517 C174 522 170 526 162 527 L121 527 C114 525 112 521 116 515Z" />
        <path class="account-player-character-svg__shoe" d="M180 500 C191 497 206 499 214 505 L231 517 C234 522 230 526 222 527 L181 527 C175 525 172 521 176 515Z" />
        <path class="account-player-character-svg__shoe-accent" d="M120 514 C134 517 151 517 164 514" />
        <path class="account-player-character-svg__shoe-accent" d="M179 514 C194 517 213 517 225 514" />
    </g>

    {{-- Face details stay intentionally stylised but anatomically placed. --}}
    <g class="account-player-character-svg__face-details">
        <path class="account-player-character-svg__brow" d="M151 83 C160 78 168 79 174 82" />
        <path class="account-player-character-svg__brow" d="M187 82 C195 78 204 79 210 83" />
        <path class="account-player-character-svg__eye" d="M152 91 C159 95 167 95 173 91" />
        <path class="account-player-character-svg__eye" d="M188 91 C195 95 203 95 209 91" />
        <circle class="account-player-character-svg__pupil" cx="163" cy="92" r="2.2" />
        <circle class="account-player-character-svg__pupil" cx="199" cy="92" r="2.2" />
        <path class="account-player-character-svg__nose" d="M181 91 C179 104 175 112 179 116 C183 118 188 116 191 114" />
        <path class="account-player-character-svg__mouth" d="M166 126 C176 131 189 131 198 125" />
    </g>

    {{-- Front hairstyle layers. --}}
    <g class="account-player-character-svg__hair" data-character-hairstyle="male_bald" hidden></g>
    <g class="account-player-character-svg__hair" data-character-hairstyle="male_buzz" hidden>
        <path d="M139 85 C138 58 154 43 179 42 C205 42 221 58 221 83 C208 71 196 66 180 66 C163 66 150 72 139 85Z" opacity=".78" />
    </g>
    <g class="account-player-character-svg__hair" data-character-hairstyle="male_fade">
        <path d="M139 83 C138 60 153 42 180 41 C207 41 221 58 221 81 C211 69 203 65 191 62 C180 58 169 60 160 64 C151 68 145 74 139 83Z" />
        <path d="M145 70 C153 49 172 42 192 47 C183 52 174 58 169 66 C160 65 152 67 145 70Z" opacity=".68" />
    </g>
    <g class="account-player-character-svg__hair" data-character-hairstyle="male_short" hidden>
        <path d="M138 84 C137 59 151 42 177 39 C200 37 220 50 224 71 C214 64 206 63 199 64 C190 55 174 51 158 57 C150 62 144 71 138 84Z" />
        <path d="M157 55 C166 43 184 38 198 43 C193 49 186 54 177 58Z" opacity=".65" />
    </g>
    <g class="account-player-character-svg__hair" data-character-hairstyle="male_curls" hidden>
        <circle cx="146" cy="67" r="14" /><circle cx="158" cy="53" r="15" /><circle cx="176" cy="48" r="16" />
        <circle cx="195" cy="51" r="16" /><circle cx="211" cy="63" r="15" /><circle cx="218" cy="79" r="12" />
        <circle cx="153" cy="80" r="13" /><circle cx="174" cy="68" r="14" /><circle cx="198" cy="70" r="14" />
    </g>

    <g class="account-player-character-svg__hair" data-character-hairstyle="female_ponytail" hidden>
        <path d="M138 87 C136 60 153 41 180 41 C207 41 223 60 221 86 C210 70 197 62 180 62 C163 62 149 70 138 87Z" />
        <path d="M139 80 C150 70 164 66 178 66 C192 66 204 70 219 82 C211 61 196 50 180 50 C162 50 148 61 139 80Z" opacity=".7" />
    </g>
    <g class="account-player-character-svg__hair" data-character-hairstyle="female_bob" hidden>
        <path d="M136 87 C134 60 151 40 179 40 C207 40 224 61 222 90 L218 126 C211 115 207 101 205 86 C196 70 163 68 151 87 C149 102 145 116 137 128Z" />
    </g>
    <g class="account-player-character-svg__hair" data-character-hairstyle="female_long" hidden>
        <path d="M137 89 C134 60 151 40 179 40 C207 40 225 61 222 91 C211 74 199 65 181 64 C162 64 149 73 137 89Z" />
        <path d="M142 83 C153 65 168 57 185 59 C203 60 213 70 220 83 C212 58 198 47 180 47 C162 47 149 59 142 83Z" opacity=".66" />
    </g>
    <g class="account-player-character-svg__hair" data-character-hairstyle="female_curls" hidden>
        <circle cx="143" cy="71" r="15" /><circle cx="154" cy="54" r="16" /><circle cx="174" cy="47" r="17" />
        <circle cx="195" cy="49" r="17" /><circle cx="212" cy="62" r="16" /><circle cx="220" cy="82" r="14" />
        <circle cx="143" cy="92" r="14" /><circle cx="216" cy="101" r="14" /><circle cx="151" cy="111" r="12" />
        <circle cx="207" cy="119" r="12" />
    </g>
    <g class="account-player-character-svg__hair" data-character-hairstyle="female_braids" hidden>
        <path d="M138 86 C136 60 152 41 179 41 C206 41 222 59 221 84 C208 71 198 65 181 64 C163 64 151 71 138 86Z" />
        <path d="M151 64 C162 72 174 75 186 72 M161 52 C173 62 188 66 201 62 M145 77 C158 84 173 86 188 83" class="account-player-character-svg__braid-line" />
    </g>

    {{-- Facial hair uses the same hair palette and is enabled only for the male preset. --}}
    <g class="account-player-character-svg__facial-hair" data-character-facial-hair="stubble" hidden>
        <path d="M150 105 C154 130 166 141 181 143 C199 142 210 127 214 105 C209 132 197 145 181 147 C164 145 153 132 150 105Z" opacity=".28" />
    </g>
    <g class="account-player-character-svg__facial-hair" data-character-facial-hair="mustache" hidden>
        <path d="M166 119 C172 115 178 116 181 120 C185 116 192 116 198 119 C195 126 187 127 181 123 C175 127 168 126 166 119Z" />
    </g>
    <g class="account-player-character-svg__facial-hair" data-character-facial-hair="goatee" hidden>
        <path d="M166 119 C172 115 178 116 181 120 C185 116 192 116 198 119 C195 126 187 127 181 123 C175 127 168 126 166 119Z" />
        <path d="M172 132 C178 136 186 136 192 131 L190 143 C185 148 177 148 172 143Z" />
    </g>
    <g class="account-player-character-svg__facial-hair" data-character-facial-hair="short_beard" hidden>
        <path d="M151 108 C153 126 160 140 172 147 C179 151 188 150 196 145 C206 137 212 124 214 108 C216 132 209 149 195 158 C185 164 174 163 164 157 C154 149 148 133 151 108Z" opacity=".82" />
    </g>
    <g class="account-player-character-svg__facial-hair" data-character-facial-hair="full_beard" hidden>
        <path d="M148 102 C149 128 156 147 169 158 C178 166 190 165 200 157 C212 147 217 128 216 103 C222 130 217 153 202 169 C190 181 174 181 160 169 C147 157 142 131 148 102Z" />
    </g>
</svg>
