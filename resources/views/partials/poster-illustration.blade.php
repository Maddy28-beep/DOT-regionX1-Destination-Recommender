@props(['scene'])

{{--
    Flat-vector "travel poster" scenes shared by every listing card, the
    destination detail hero, and the homepage featured banner.

    Scenes are keyed by a short slug rather than a listing name, so renaming a
    destination or resort falls back to the generic scene instead of silently
    breaking the mapping. Destinations and packages map name -> slug via their
    models' illustrationScene(); the other listing types pick a per-kind
    variant in posterScene(), because they have no curated per-record artwork.

    Palette is the site's own tokens only -- ocean teal, forest, sunset gold,
    stamp red, cream -- so a card reads the same whichever listing it came
    from.
--}}

@switch($scene)
    @case('eagle')
        {{-- Philippine Eagle Center: forest + eagle on a branch --}}
        <svg viewBox="0 0 400 300" preserveAspectRatio="xMidYMid slice" xmlns="http://www.w3.org/2000/svg" role="img" aria-hidden="true">
            <rect width="400" height="300" fill="#12836F"/>
            <path d="M0,300 L0,190 Q100,150 200,185 Q300,150 400,190 L400,300 Z" fill="#0B5E52"/>
            <path d="M0,300 L0,230 Q120,200 220,225 Q320,195 400,225 L400,300 Z" fill="#1E5C43"/>
            <rect x="150" y="150" width="120" height="10" rx="4" fill="#7a5230"/>
            <g fill="#2a1e17">
                <path d="M225,150 C195,158 168,150 148,168 C172,176 205,172 225,168 Z"/>
                <ellipse cx="225" cy="115" rx="34" ry="46"/>
                <circle cx="225" cy="70" r="24"/>
            </g>
            <circle cx="216" cy="65" r="4" fill="#FFF7E9"/>
            <path d="M198,72 L176,78 L198,84 Z" fill="#D9472C"/>
        </svg>
        @break

    @case('island')
        {{-- Samal Island: big sun, clear island + palms, outrigger boat --}}
        <svg viewBox="0 0 400 300" preserveAspectRatio="xMidYMid slice" xmlns="http://www.w3.org/2000/svg" role="img" aria-hidden="true">
            <rect width="400" height="300" fill="#7fd4d8"/>
            <circle cx="320" cy="70" r="46" fill="#FFE07A" opacity=".35"/>
            <circle cx="320" cy="70" r="30" fill="#FFE07A"/>
            <rect y="185" width="400" height="115" fill="#12836F"/>
            <path d="M0,300 L0,205 Q90,160 190,195 Q280,222 400,190 L400,300 Z" fill="#1E5C43"/>
            <g fill="#0B5E52">
                <path d="M110,195 C107,165 122,142 114,118" stroke="#0B5E52" stroke-width="5" fill="none" stroke-linecap="round"/>
                <ellipse cx="96" cy="112" rx="22" ry="9" transform="rotate(-28 96 112)"/>
                <ellipse cx="132" cy="110" rx="22" ry="9" transform="rotate(24 132 110)"/>
                <ellipse cx="114" cy="98" rx="20" ry="9"/>
                <path d="M250,190 C247,160 262,138 254,116" stroke="#0B5E52" stroke-width="5" fill="none" stroke-linecap="round"/>
                <ellipse cx="236" cy="110" rx="20" ry="9" transform="rotate(-28 236 110)"/>
                <ellipse cx="270" cy="108" rx="20" ry="9" transform="rotate(24 270 108)"/>
            </g>
            <path d="M50,240 Q140,228 230,242 Q300,252 380,240" stroke="#e6f4ef" stroke-width="4" fill="none" opacity=".55"/>
            <path d="M40,265 Q150,252 260,266 Q330,275 390,264" stroke="#e6f4ef" stroke-width="4" fill="none" opacity=".4"/>
            <path d="M50,262 L150,262 L128,278 L68,278 Z" fill="#0B5E52"/>
            <line x1="98" y1="262" x2="98" y2="222" stroke="#0B5E52" stroke-width="4"/>
            <path d="M98,222 L138,236 L98,244 Z" fill="#0B5E52"/>
        </svg>
        @break

    @case('zipline')
        {{-- Eden Nature Park: cool-climate pines + zipline cable --}}
        <svg viewBox="0 0 400 300" preserveAspectRatio="xMidYMid slice" xmlns="http://www.w3.org/2000/svg" role="img" aria-hidden="true">
            <rect width="400" height="300" fill="#BFE3E0"/>
            <path d="M0,300 L60,140 L120,300 Z" fill="#0B5E52"/>
            <path d="M90,300 L160,110 L230,300 Z" fill="#12836F"/>
            <path d="M200,300 L270,150 L340,300 Z" fill="#1E5C43"/>
            <line x1="30" y1="120" x2="370" y2="200" stroke="#5b6b64" stroke-width="3"/>
            <circle cx="200" cy="160" r="6" fill="#D9472C"/>
            <line x1="200" y1="160" x2="200" y2="178" stroke="#3b2a1f" stroke-width="2"/>
            <path d="M188,178 L212,178 L206,192 L194,192 Z" fill="#D9472C"/>
        </svg>
        @break

    @case('garden')
        {{-- Malagos Garden Resort: flower rows + cacao pod --}}
        <svg viewBox="0 0 400 300" preserveAspectRatio="xMidYMid slice" xmlns="http://www.w3.org/2000/svg" role="img" aria-hidden="true">
            <rect width="400" height="300" fill="#FFF3DD"/>
            <rect y="190" width="400" height="110" fill="#8fae3a"/>
            <g fill="#D9472C">
                <circle cx="60" cy="210" r="9"/><circle cx="110" cy="215" r="9"/><circle cx="160" cy="208" r="9"/>
                <circle cx="240" cy="216" r="9"/><circle cx="290" cy="209" r="9"/><circle cx="340" cy="214" r="9"/>
            </g>
            <g fill="#FFC15C">
                <circle cx="85" cy="230" r="7"/><circle cx="135" cy="234" r="7"/><circle cx="265" cy="232" r="7"/><circle cx="315" cy="236" r="7"/>
            </g>
            <path d="M320,150 C300,160 296,190 316,204 C336,190 332,160 320,150 Z" fill="#7a4b1e"/>
            <path d="M320,150 C320,168 320,190 320,204" stroke="#5c3814" stroke-width="2" fill="none"/>
        </svg>
        @break

    @case('heritage')
        {{-- People's Park: monument + trees, city-park feel --}}
        <svg viewBox="0 0 400 300" preserveAspectRatio="xMidYMid slice" xmlns="http://www.w3.org/2000/svg" role="img" aria-hidden="true">
            <rect width="400" height="300" fill="#e6f4ef"/>
            <rect y="230" width="400" height="70" fill="#1E5C43"/>
            <rect x="185" y="140" width="30" height="90" fill="#c9932f"/>
            <path d="M170,140 L230,140 L200,110 Z" fill="#916b19"/>
            <rect x="175" y="225" width="50" height="10" fill="#7a5230"/>
            <path d="M90,230 C86,195 108,175 100,150" stroke="#0B5E52" stroke-width="5" fill="none" stroke-linecap="round"/>
            <circle cx="98" cy="140" r="26" fill="#12836F"/>
            <path d="M300,230 C296,195 318,175 310,150" stroke="#0B5E52" stroke-width="5" fill="none" stroke-linecap="round"/>
            <circle cx="308" cy="140" r="26" fill="#12836F"/>
        </svg>
        @break

    @case('crocodile')
        {{-- Davao Crocodile Park: pond + croc silhouette --}}
        <svg viewBox="0 0 400 300" preserveAspectRatio="xMidYMid slice" xmlns="http://www.w3.org/2000/svg" role="img" aria-hidden="true">
            <rect width="400" height="300" fill="#cfe8c9"/>
            <rect y="190" width="400" height="110" fill="#12836F"/>
            <path d="M40,205 Q120,192 200,206 Q280,190 370,206" stroke="#0B5E52" stroke-width="4" fill="none" opacity=".5"/>
            <g fill="#3f6b2c">
                <path d="M110,220 C90,216 70,222 60,214 C74,232 100,236 118,232 C160,238 220,236 250,224 C238,218 224,222 214,216 C238,208 250,196 244,186 C232,196 214,200 200,198 C188,192 176,196 168,204 C150,208 128,216 110,220 Z"/>
                <path d="M244,186 L262,176 L250,196 Z"/>
            </g>
            <circle cx="230" cy="196" r="3" fill="#FFE07A"/>
        </svg>
        @break

    @case('surf')
        {{-- Dahican Beach: big sun, textured waves, planted surfboard, turtle --}}
        <svg viewBox="0 0 400 300" preserveAspectRatio="xMidYMid slice" xmlns="http://www.w3.org/2000/svg" role="img" aria-hidden="true">
            <rect width="400" height="170" fill="#FFC15C"/>
            <circle cx="320" cy="65" r="44" fill="#FFE07A" opacity=".35"/>
            <circle cx="320" cy="65" r="28" fill="#FFE07A"/>
            <rect y="170" width="400" height="60" fill="#12836F"/>
            <path d="M0,196 Q100,182 200,196 Q300,210 400,196" stroke="#e6f4ef" stroke-width="4" fill="none" opacity=".6"/>
            <path d="M0,212 Q100,200 200,212 Q300,224 400,212" stroke="#e6f4ef" stroke-width="4" fill="none" opacity=".4"/>
            <rect y="230" width="400" height="70" fill="#F0D9A8"/>
            <g transform="rotate(-12 140 260)">
                <rect x="122" y="212" width="36" height="96" rx="18" fill="#FFF7E9" stroke="#D9472C" stroke-width="3"/>
                <line x1="140" y1="222" x2="140" y2="298" stroke="#D9472C" stroke-width="2" opacity=".6"/>
            </g>
            <ellipse cx="270" cy="272" rx="22" ry="13" fill="#1E5C43"/>
            <ellipse cx="257" cy="265" rx="7" ry="6" fill="#1E5C43"/>
            <ellipse cx="255" cy="264" rx="2" ry="2" fill="#FFF7E9"/>
        </svg>
        @break

    @case('mountain-peak')
        {{-- Mount Apo, dramatic sunset summit -- featured banner scene --}}
        <svg viewBox="0 0 500 400" preserveAspectRatio="xMidYMid slice" xmlns="http://www.w3.org/2000/svg" role="img" aria-hidden="true">
            <rect width="500" height="400" fill="#FF9A5C"/>
            <rect width="500" height="400" fill="url(#dpostSkyFade)"/>
            <defs>
                <linearGradient id="dpostSkyFade" x1="0" y1="0" x2="0" y2="1">
                    <stop offset="0%" stop-color="#FFC15C"/>
                    <stop offset="55%" stop-color="#FF7A59" stop-opacity=".6"/>
                    <stop offset="100%" stop-color="#FF7A59" stop-opacity="0"/>
                </linearGradient>
            </defs>
            <circle cx="250" cy="130" r="70" fill="#FFE07A" opacity=".9"/>
            <g opacity=".5" stroke="#FFE07A" stroke-width="3">
                <line x1="250" y1="30" x2="250" y2="10"/>
                <line x1="330" y1="60" x2="345" y2="48"/>
                <line x1="170" y1="60" x2="155" y2="48"/>
            </g>
            <path d="M0,400 L60,270 L120,400 Z" fill="#0B5E52"/>
            <path d="M380,400 L440,260 L500,400 Z" fill="#0B5E52"/>
            <path d="M80,400 L250,120 L420,400 Z" fill="#1E5C43"/>
            <path d="M250,120 L215,185 L285,185 Z" fill="#FFF7E9"/>
            <path d="M225,170 L212,190 L238,190 Z" fill="#e6f4ef"/>
        </svg>
        @break

    @case('resort')
        {{-- Accommodations: beachfront lodging, sea band + palms --}}
        <svg viewBox="0 0 400 300" preserveAspectRatio="xMidYMid slice" xmlns="http://www.w3.org/2000/svg" role="img" aria-hidden="true">
            <rect width="400" height="300" fill="#BFE3E0"/>
            <circle cx="66" cy="54" r="26" fill="#FFE07A"/>
            <rect y="150" width="400" height="60" fill="#12836F"/>
            <path d="M0,178 Q80,168 160,178 Q240,188 320,178 Q360,173 400,178 L400,210 L0,210 Z" fill="#0B5E52" opacity=".45"/>
            <rect y="210" width="400" height="90" fill="#F0D9A8"/>
            <rect x="150" y="112" width="150" height="98" fill="#FFF7E9"/>
            <path d="M138,112 L312,112 L294,84 L156,84 Z" fill="#D9472C"/>
            <g fill="#0B5E52">
                <rect x="166" y="128" width="24" height="26"/><rect x="204" y="128" width="24" height="26"/><rect x="242" y="128" width="24" height="26"/>
                <rect x="166" y="168" width="24" height="26"/><rect x="242" y="168" width="24" height="26"/>
            </g>
            <rect x="204" y="164" width="26" height="46" fill="#7a5230"/>
            <path d="M64,232 C60,200 78,182 70,158" stroke="#0B5E52" stroke-width="5" fill="none" stroke-linecap="round"/>
            <g fill="#1E5C43">
                <ellipse cx="52" cy="152" rx="20" ry="8" transform="rotate(-28 52 152)"/>
                <ellipse cx="88" cy="150" rx="20" ry="8" transform="rotate(24 88 150)"/>
                <ellipse cx="70" cy="140" rx="18" ry="8"/>
            </g>
            <path d="M344,238 C340,206 358,188 350,164" stroke="#0B5E52" stroke-width="5" fill="none" stroke-linecap="round"/>
            <g fill="#1E5C43">
                <ellipse cx="332" cy="158" rx="19" ry="8" transform="rotate(-28 332 158)"/>
                <ellipse cx="368" cy="156" rx="19" ry="8" transform="rotate(24 368 156)"/>
                <ellipse cx="350" cy="146" rx="17" ry="8"/>
            </g>
        </svg>
        @break

    @case('resort-pool')
        {{-- Accommodations variant: poolside deck, umbrella + lounger --}}
        <svg viewBox="0 0 400 300" preserveAspectRatio="xMidYMid slice" xmlns="http://www.w3.org/2000/svg" role="img" aria-hidden="true">
            <rect width="400" height="300" fill="#BFE3E0"/>
            <rect x="40" y="40" width="320" height="80" fill="#FFF7E9"/>
            <path d="M28,40 L372,40 L352,16 L48,16 Z" fill="#1E5C43"/>
            <g fill="#0B5E52" opacity=".8">
                <rect x="66" y="58" width="26" height="26"/><rect x="118" y="58" width="26" height="26"/>
                <rect x="256" y="58" width="26" height="26"/><rect x="308" y="58" width="26" height="26"/>
            </g>
            <rect y="120" width="400" height="180" fill="#F0D9A8"/>
            <rect x="56" y="172" width="288" height="106" rx="16" fill="#0B5E52"/>
            <rect x="66" y="182" width="268" height="86" rx="10" fill="#7fd4d8"/>
            <g stroke="#FFF7E9" stroke-width="5" fill="none" stroke-linecap="round" opacity=".7">
                <path d="M86,212 q26,-14 52,0 t52,0 t52,0 t52,0"/>
                <path d="M86,244 q26,-14 52,0 t52,0 t52,0 t52,0"/>
            </g>
            <path d="M300,146 L390,146 L345,120 Z" fill="#D9472C"/>
            <rect x="342" y="146" width="6" height="42" fill="#7a5230"/>
            <rect x="176" y="132" width="72" height="10" rx="5" fill="#FFF7E9"/>
            <path d="M176,142 L164,158 M248,142 L260,158" stroke="#FFF7E9" stroke-width="6" stroke-linecap="round"/>
        </svg>
        @break

    @case('dining')
        {{-- Restaurants: laid table, plate + cutlery --}}
        <svg viewBox="0 0 400 300" preserveAspectRatio="xMidYMid slice" xmlns="http://www.w3.org/2000/svg" role="img" aria-hidden="true">
            <rect width="400" height="300" fill="#FFF3DD"/>
            <circle cx="200" cy="160" r="128" fill="#7a5230"/>
            <circle cx="200" cy="158" r="116" fill="#8a5f38"/>
            <circle cx="200" cy="158" r="58" fill="#FFF7E9" stroke="#e1e8e4" stroke-width="3"/>
            <circle cx="200" cy="158" r="36" fill="#D9472C" opacity=".88"/>
            <path d="M200,128 Q224,139 218,158 Q200,151 200,128 Z" fill="#1E5C43"/>
            <g fill="#e1e8e4">
                <rect x="112" y="112" width="5" height="34" rx="2"/>
                <rect x="122" y="112" width="5" height="34" rx="2"/>
                <rect x="132" y="112" width="5" height="34" rx="2"/>
                <path d="M108,144 h34 v11 q0,9 -11,11 v56 a6,6 0 0 1 -12,0 v-56 q-11,-2 -11,-11 z"/>
                <path d="M282,112 q15,30 11,60 h-21 q-2,-38 10,-60 z"/>
                <rect x="278" y="172" width="9" height="50" rx="4"/>
            </g>
            <path d="M296,96 h26 l-4,30 h-18 z" fill="#7fd4d8" opacity=".85"/>
            <rect x="302" y="126" width="14" height="18" fill="#7fd4d8" opacity=".6"/>
        </svg>
        @break

    @case('dining-cafe')
        {{-- Restaurants variant: cup and saucer with steam --}}
        <svg viewBox="0 0 400 300" preserveAspectRatio="xMidYMid slice" xmlns="http://www.w3.org/2000/svg" role="img" aria-hidden="true">
            <rect width="400" height="300" fill="#e6f4ef"/>
            <ellipse cx="200" cy="248" rx="126" ry="22" fill="#0B5E52" opacity=".12"/>
            <ellipse cx="200" cy="232" rx="102" ry="24" fill="#FFF7E9" stroke="#0B5E52" stroke-width="3"/>
            <path d="M136,148 h128 l-12,66 a22,22 0 0 1 -22,15 h-60 a22,22 0 0 1 -22,-15 z" fill="#FFF7E9" stroke="#0B5E52" stroke-width="4"/>
            <ellipse cx="200" cy="150" rx="63" ry="13" fill="#5c3814"/>
            <path d="M266,166 a27,27 0 0 1 0,46" stroke="#0B5E52" stroke-width="8" fill="none" stroke-linecap="round"/>
            <g stroke="#0B5E52" stroke-width="4" fill="none" stroke-linecap="round" opacity=".45">
                <path d="M180,118 q10,-14 0,-28"/>
                <path d="M200,112 q10,-16 0,-32"/>
                <path d="M220,118 q10,-14 0,-28"/>
            </g>
        </svg>
        @break

    @case('market')
        {{-- Souvenir centres: stall row with striped awnings --}}
        <svg viewBox="0 0 400 300" preserveAspectRatio="xMidYMid slice" xmlns="http://www.w3.org/2000/svg" role="img" aria-hidden="true">
            <rect width="400" height="300" fill="#FFF3DD"/>
            <rect y="228" width="400" height="72" fill="#d8e6dc"/>
            <rect x="222" y="128" width="150" height="100" fill="#FFF7E9" stroke="#0B5E52" stroke-width="3"/>
            <path d="M214,104 L380,104 L380,128 L214,128 Z" fill="#12836F"/>
            <g fill="#FFF7E9">
                <rect x="230" y="104" width="18" height="24"/><rect x="266" y="104" width="18" height="24"/>
                <rect x="302" y="104" width="18" height="24"/><rect x="338" y="104" width="18" height="24"/>
            </g>
            <rect x="30" y="150" width="176" height="78" fill="#FFF7E9" stroke="#0B5E52" stroke-width="3"/>
            <path d="M20,124 L212,124 L212,150 L20,150 Z" fill="#D9472C"/>
            <g fill="#FFF7E9">
                <rect x="36" y="124" width="20" height="26"/><rect x="76" y="124" width="20" height="26"/>
                <rect x="116" y="124" width="20" height="26"/><rect x="156" y="124" width="20" height="26"/>
            </g>
            <rect x="22" y="146" width="188" height="9" fill="#7a5230"/>
            <g fill="#FFC15C"><circle cx="62" cy="182" r="11"/><circle cx="88" cy="182" r="11"/><circle cx="75" cy="166" r="11"/></g>
            <g fill="#12836F"><circle cx="140" cy="182" r="10"/><circle cx="164" cy="182" r="10"/><circle cx="152" cy="167" r="10"/></g>
        </svg>
        @break

    @case('market-weave')
        {{-- Souvenir centres variant: woven basket between textile bands --}}
        <svg viewBox="0 0 400 300" preserveAspectRatio="xMidYMid slice" xmlns="http://www.w3.org/2000/svg" role="img" aria-hidden="true">
            <rect width="400" height="300" fill="#e6f4ef"/>
            <rect y="34" width="400" height="26" fill="#D9472C"/>
            <rect y="60" width="400" height="12" fill="#FFC15C"/>
            <rect y="228" width="400" height="12" fill="#FFC15C"/>
            <rect y="240" width="400" height="26" fill="#12836F"/>
            <path d="M124,140 h152 l-18,102 a16,16 0 0 1 -16,14 h-84 a16,16 0 0 1 -16,-14 z" fill="#c9932f"/>
            <g stroke="#8a5f38" stroke-width="4" opacity=".7">
                <line x1="128" y1="166" x2="272" y2="166"/>
                <line x1="132" y1="192" x2="268" y2="192"/>
                <line x1="137" y1="218" x2="263" y2="218"/>
            </g>
            <ellipse cx="200" cy="140" rx="76" ry="16" fill="#8a5f38"/>
            <ellipse cx="200" cy="135" rx="76" ry="16" fill="#e0b256"/>
            <path d="M132,136 a68,54 0 0 1 136,0" fill="none" stroke="#8a5f38" stroke-width="6"/>
        </svg>
        @break

    @case('tour-van')
        {{-- Tour operators: shuttle on a hill road --}}
        <svg viewBox="0 0 400 300" preserveAspectRatio="xMidYMid slice" xmlns="http://www.w3.org/2000/svg" role="img" aria-hidden="true">
            <rect width="400" height="300" fill="#BFE3E0"/>
            <circle cx="330" cy="52" r="26" fill="#FFE07A"/>
            <path d="M0,200 L90,120 L180,200 Z" fill="#0B5E52"/>
            <path d="M120,200 L220,110 L320,200 Z" fill="#12836F"/>
            <path d="M260,200 L340,140 L400,200 Z" fill="#1E5C43"/>
            <rect y="200" width="400" height="100" fill="#8a8f8b"/>
            <g stroke="#FFF7E9" stroke-width="6" stroke-dasharray="26 22" opacity=".85"><line x1="0" y1="268" x2="400" y2="268"/></g>
            <path d="M92,238 L92,190 Q92,176 108,176 L214,176 Q232,176 244,190 L272,222 L272,238 Z" fill="#FFF7E9" stroke="#0B5E52" stroke-width="3"/>
            <rect x="106" y="188" width="46" height="30" fill="#7fd4d8"/>
            <rect x="162" y="188" width="46" height="30" fill="#7fd4d8"/>
            <path d="M218,188 L238,188 L258,214 L218,214 Z" fill="#7fd4d8"/>
            <rect x="92" y="222" width="180" height="8" fill="#D9472C"/>
            <circle cx="132" cy="238" r="18" fill="#1a2420"/><circle cx="132" cy="238" r="7" fill="#FFF7E9"/>
            <circle cx="240" cy="238" r="18" fill="#1a2420"/><circle cx="240" cy="238" r="7" fill="#FFF7E9"/>
        </svg>
        @break

    @case('tour-boat')
        {{-- Tour operators variant: outrigger banca under a low sun --}}
        <svg viewBox="0 0 400 300" preserveAspectRatio="xMidYMid slice" xmlns="http://www.w3.org/2000/svg" role="img" aria-hidden="true">
            <rect width="400" height="300" fill="#FFC15C"/>
            <circle cx="300" cy="68" r="40" fill="#FFE07A" opacity=".4"/>
            <circle cx="300" cy="68" r="26" fill="#FFE07A"/>
            <path d="M0,150 L60,96 L120,150 Z" fill="#1E5C43" opacity=".5"/>
            <path d="M296,150 L352,104 L400,150 Z" fill="#1E5C43" opacity=".5"/>
            <rect y="150" width="400" height="150" fill="#12836F"/>
            <path d="M0,176 Q100,164 200,176 Q300,188 400,176" stroke="#e6f4ef" stroke-width="4" fill="none" opacity=".55"/>
            <path d="M0,258 Q100,246 200,258 Q300,270 400,258" stroke="#e6f4ef" stroke-width="4" fill="none" opacity=".4"/>
            <g stroke="#7a5230" stroke-width="5" stroke-linecap="round">
                <line x1="122" y1="216" x2="72" y2="234"/>
                <line x1="278" y1="216" x2="328" y2="234"/>
            </g>
            <rect x="50" y="230" width="62" height="10" rx="5" fill="#8a5f38"/>
            <rect x="288" y="230" width="62" height="10" rx="5" fill="#8a5f38"/>
            <path d="M96,212 L304,212 L272,240 L128,240 Z" fill="#FFF7E9" stroke="#0B5E52" stroke-width="3"/>
            <rect x="188" y="148" width="6" height="64" fill="#7a5230"/>
            <path d="M194,148 L252,182 L194,196 Z" fill="#D9472C"/>
        </svg>
        @break

    @default
        {{-- Generic fallback for any listing not in the curated map --}}
        <svg viewBox="0 0 400 300" preserveAspectRatio="xMidYMid slice" xmlns="http://www.w3.org/2000/svg" role="img" aria-hidden="true">
            <rect width="400" height="300" fill="#BFE3E0"/>
            <circle cx="330" cy="60" r="30" fill="#FFE07A"/>
            <path d="M0,300 L80,160 L160,300 Z" fill="#0B5E52"/>
            <path d="M120,300 L220,130 L320,300 Z" fill="#12836F"/>
            <path d="M260,300 L340,190 L400,300 Z" fill="#1E5C43"/>
        </svg>
@endswitch
