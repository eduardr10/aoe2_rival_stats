{{-- resources/views/partials/aoe2_overlay.blade.php --}}
@if (isset($stats['error']) || $stats['total'] == 0)
    <div style="display: none;"></div>
@else
    <div style="
                                                        position: fixed;
                                                        top: 50%;
                                                        right: 0;
                                                        transform: translateY(-50%);
                                                        width: 700px;
                                                        background: rgba(15, 15, 25, 0.95);
                                                        backdrop-filter: blur(6px);
                                                        color: #e5e5e5;
                                                        padding: 16px;
                                                        border-top-left-radius: 20px;
                                                        border-bottom-left-radius: 20px;
                                                        font-family: 'Segoe UI', Roboto, sans-serif;
                                                        box-shadow: -6px 0 20px rgba(0,0,0,0.85);
                                                        z-index: 9999;
                                                        font-size: 13px;
                                                        line-height: 1.35;
                                                    ">

        {{-- HEADER --}}
        <div style="display:flex; align-items:center; gap:10px; margin-bottom:10px;">
            <div>
                <div style="font-size:20px; font-weight:700; color:#fff;">{{ $stats['player_name'] }}</div>
                <div style="font-size:12px; color:#aaa;">Rating: {{ $stats['rating'] }}</div>
            </div>

            {{-- WR Badge --}}
            <span style="
                                                                margin-left:auto;
                                                                background: {{ ($stats['win_percent'] ?? 0) >= 50 ? '#2e7d32' : '#c62828' }};
                                                                color: #fff;
                                                                padding: 4px 10px;
                                                                border-radius: 12px;
                                                                font-size:14px;
                                                                font-weight:700;
                                                            ">
                {{ $stats['win_percent'] ?? 0 }}% WR
            </span>
        </div>

        {{-- STATS GRID --}}
        <div style="display:grid; grid-template-columns: repeat(3,1fr); gap:10px; text-align:center; margin-bottom:10px;">
            <div>
                <div style="font-size:11px; color:#aaa;">PARTIDAS</div>
                <div style="font-size:16px; font-weight:700;">{{ $stats['total'] }}</div>
            </div>
            <div>
                <div style="font-size:11px; color:#aaa;">VICTORIAS</div>
                <div style="font-size:16px; font-weight:700; color:#4caf50;">{{ $stats['total_wins'] }}</div>
            </div>
            <div>
                <div style="font-size:11px; color:#aaa;">EAPM</div>
                <div style="font-size:16px; font-weight:700;">{{ $stats['avg_eapm'] ?? '-' }}</div>
            </div>
        </div>

        {{-- MAPA + RANDOM --}}
        <div style="display:flex; justify-content:space-between; gap:12px; margin-bottom:10px;">
            <div style="flex:1;">
                <div style="font-size:11px; color:#aaa;">MEJOR MAPA</div>
                <div style="font-weight:700; color:#ffeb3b;">{{ $stats['best_map'] ?? '-' }}</div>
            </div>
            <div style="width:110px; text-align:right;">
                <div style="font-size:11px; color:#aaa;">% RANDOM</div>
                <div style="font-weight:700;">{{ $stats['percent_prefer_random'] ?? '-' }}%</div>
            </div>
        </div>

        {{-- UPTIMES --}}
        <div style="display:flex; justify-content: space-between">

            <div style="margin-bottom:10px;">
                <div style="font-size:11px; color:#aaa; margin-bottom:6px;">UPTIMES ({{ $stats['player_name'] }} vs rivales)
                </div>
                @foreach(['feudal', 'castle', 'imperial'] as $age)
                    <div style="display:flex; justify-content:space-between; padding:2px 0; align-items:center;">
                        <span style="font-weight:700; width:80px;">{{ ucfirst($age) }}</span>
                        <div style="display:flex; gap:6px; align-items:center;">
                            <span style="font-weight:700; color:#90caf9;">{{ $stats['avg_' . $age . '_hms'] ?? 'N/A' }}</span>
                            <span style="color:#777;">vs</span>
                            <span>{{ $stats['opp_avg_' . $age . '_hms'] ?? 'N/A' }}</span>
                        </div>
                    </div>
                @endforeach
            </div>

            {{-- MAPAS --}}
            <div style="margin-bottom:10px;">
                <div style="font-size:11px; color:#aaa; margin-bottom:6px;">MAPAS MÁS JUGADOS</div>
                @if (!empty($stats['map_played_percent']))
                    @php $maps_shown = 0; @endphp
                    @foreach ($stats['map_played_percent'] as $map => $percent)
                        @if($maps_shown >= 4) @break @endif
                        <div
                            style="display:flex; justify-content:space-between; align-items:center; font-size:12px; padding:2px 0;">
                            <span style="flex:1;">{{ $map }}</span>
                            <span style="width:46px; text-align:right; color:#aaa;">{{ $percent }}%</span>
                            <span style="width:56px; text-align:right; color:#4caf50;">{{ $stats['map_win_percent'][$map] ?? '-' }}%
                                WR</span>
                        </div>
                        @php $maps_shown++; @endphp
                    @endforeach
                @else
                    <span style="color:#aaa; font-size:12px;">Sin datos</span>
                @endif
            </div>
        </div>

        {{-- CIVS --}}
        <div style="margin-bottom:10px;">
            <div style="font-size:11px; color:#aaa; margin-bottom:6px;">CIVILIZACIONES</div>
            @if (!empty($stats['civ_played_percent']))
                <div style="display:flex; flex-wrap:wrap; gap:6px;">
                    @foreach ($stats['civ_played_percent'] as $civ => $percent)
                        <span style="background:rgba(255,255,255,0.06); padding:5px 8px; border-radius:12px; font-size:11px;">
                            {{ $civ }} ({{ $percent }}%)
                        </span>
                    @endforeach
                </div>
            @else
                <span style="color:#aaa; font-size:12px;">Sin datos</span>
            @endif
        </div>

        {{-- MERCADO --}}
        @php
            $market_avg = $stats['market_avg_by_age'] ?? [];
            $market_used = false;
            foreach (['feudal', 'castle', 'imperial'] as $age_check) {
                $avgA = $market_avg[$age_check] ?? null;
                if ($avgA && ((isset($avgA['buy']) && count($avgA['buy'])) || (isset($avgA['sell']) && count($avgA['sell'])))) {
                    $market_used = true;
                }
            }
        @endphp
        <div style="margin-bottom:10px;">
            @foreach (['feudal', 'castle', 'imperial'] as $age)
                @php
                    $avgByAge = $market_avg[$age] ?? null;
                    $top_buys = [];
                    $top_sells = [];
                    if ($avgByAge) {
                        $buys = $avgByAge['buy'] ?? [];
                        $sells = $avgByAge['sell'] ?? [];
                        if (!empty($buys)) {
                            arsort($buys);
                            $top_buys = array_slice($buys, 0, 4, true);
                        }
                        if (!empty($sells)) {
                            arsort($sells);
                            $top_sells = array_slice($sells, 0, 4, true);
                        }
                    }
                @endphp
                <div style="display:flex; gap:8px; align-items:center; padding:2px 0;">
                    <div style="width:72px; font-weight:700; color:#90caf9;">{{ ucfirst($age) }}</div>
                    <div style="display:flex; gap:6px; flex-wrap:wrap;">
                        @foreach($top_buys as $resource => $val)
                            <span
                                style="background:rgba(76,175,80,0.12); border:1px solid rgba(76,175,80,0.22); color:#4caf50; padding:4px 7px; border-radius:10px; font-size:11px;">
                                {{ $resource }}: {{ round($val) }}
                            </span>
                        @endforeach
                        @foreach($top_sells as $resource => $val)
                            <span
                                style="background:rgba(198,40,40,0.08); border:1px solid rgba(198,40,40,0.18); color:#c62828; padding:4px 7px; border-radius:10px; font-size:11px;">
                                {{ $resource }}: {{ round($val) }}
                            </span>
                        @endforeach
                        @if(empty($top_buys) && empty($top_sells))
                            <span style="color:#777; font-size:11px;">—</span>
                        @endif
                    </div>
                </div>
            @endforeach
        </div>

        {{-- TECHS (ordenadas por tiempo) --}}
        <div>
            {{-- Promedio de tiempo para whellbarrow y hand cart --}}
                @php
                    $formatHms = function ($seconds) {
                        if ($seconds === null || $seconds === '-')
                            return '-';
                        $m = floor($seconds / 60);
                        $s = $seconds % 60;
                        return sprintf("%d:%02d", $m, $s);
                    };
                @endphp

            <div style="font-size:11px; color:#aaa; margin-bottom:6px;">ECONOMÍA</div>
            <div style="display:flex; gap:12px; align-items:center; margin-bottom:10px;">
                <div style="display:flex; gap:6px; align-items:center;">
                    <span style="font-weight:700; color:#90caf9; width:100px;">Wheelbarrow:</span>
                    <span
                        style="font-size:14px;">{{ isset($stats['wheel_barrow_avg']) ? $formatHms($stats['wheel_barrow_avg']) : '-' }}</span>
                </div>
                <div style="display:flex; gap:6px; align-items:center;">
                    <span style="font-weight:700; color:#90caf9; width:100px;">Hand Cart:</span>
                    <span
                        style="font-size:14px;">{{ isset($stats['hand_cart_avg']) ? $formatHms($stats['hand_cart_avg']) : '-' }}</span>
                </div>
            </div>
            <div>
                <div style="font-size:11px; color:#aaa; margin-bottom:6px;">TECHS FRECUENTES</div>
                @foreach(['feudal', 'castle', 'imperial'] as $age)
                    @php
                        $topTechs = $stats['techs_top5_after_age'][$age] ?? [];
                        $topTechsAvg = $stats['techs_top5_avg_time'][$age] ?? [];

                        // Ordenar techs por su timestamp promedio
                        uasort($topTechs, function ($a, $b) use ($topTechsAvg) {
                            $ta = $topTechsAvg[$a] ?? PHP_INT_MAX;
                            $tb = $topTechsAvg[$b] ?? PHP_INT_MAX;
                            return $ta <=> $tb;
                        });
                    @endphp
                    <div style="margin-bottom:6px; display:flex; gap:8px; align-items:flex-start;">
                        <div style="width:72px; font-weight:700; color:#90caf9;">{{ ucfirst($age) }}</div>
                        <div style="display:flex; gap:6px; flex-wrap:wrap;">
                            @if(!empty($topTechs))
                                @foreach ($topTechs as $tech)
                                    @php $mmss = isset($topTechsAvg[$tech]) ? $formatHms($topTechsAvg[$tech]) : '-'; @endphp
                                    <span
                                        style="background:rgba(144,202,249,0.12); border:1px solid rgba(144,202,249,0.22); color:#90caf9; padding:4px 7px; border-radius:10px; font-size:11px;">
                                        {{ $tech }} ({{ $mmss }})
                                    </span>
                                @endforeach
                            @else
                                <span style="color:#777; font-size:11px;">Sin datos</span>
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>

        </div>
@endif