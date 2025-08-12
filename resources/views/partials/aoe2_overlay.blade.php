{{-- resources/views/partials/aoe2_overlay.blade.php --}}
@if (isset($stats['error']) || $stats['total'] == 0)
    <div style="display: none;"></div>
@else
    <div
        style="
                                                                        position: fixed;
                                                                        top: 50%;
                                                                        right: 0;
                                                                        transform: translateY(-50%);
                                                                        width: 320px;
                                                                        max-height: 90vh;
                                                                        overflow-y: auto;
                                                                        background-color: rgba(0, 0, 0, 0.9);
                                                                        color: #e0e0e0;
                                                                        padding: 15px;
                                                                        border-top-left-radius: 8px;
                                                                        border-bottom-left-radius: 8px;
                                                                        font-family: 'Segoe UI', Roboto, sans-serif;
                                                                        box-shadow: -2px 0 8px rgba(0,0,0,0.7);
                                                                        z-index: 9999;
                                                                        font-size: 11px;
                                                                        line-height: 1.4;
                                                                    ">

        {{-- Encabezado --}}
        <div
            style="display: flex; align-items: center; margin-bottom: 12px; border-bottom: 1px solid #444; padding-bottom: 8px;">
            <span style="font-size: 18px; font-weight: bold; color: #f0f0f0;">{{ $stats['player_name'] }}</span><small
                style="font-size: 12px; font-weight: light; color: #f0f0f0;">{{ ' (' . $stats['rating'] . ')' ?? '' }}</small>
            <span
                style="margin-left: auto; background: {{ $stats['win_percent'] >= 50 ? '#2e7d32' : '#c62828' }}; 
                                                                              color: white; padding: 2px 6px; border-radius: 10px; font-size: 12px;">
                {{ $stats['win_percent'] }}% WR
            </span>
        </div>

        {{-- Estadísticas principales --}}
        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 8px; margin-bottom: 12px;">
            <div>
                <div style="color: #aaa; font-size: 11px;">PARTIDAS</div>
                <div style="font-weight: bold;">{{ $stats['total'] }}</div>
            </div>
            <div>
                <div style="color: #aaa; font-size: 11px;">VICTORIAS</div>
                <div style="font-weight: bold; color: #4caf50;">{{ $stats['total_wins'] }}</div>
            </div>
            <div>
                <div style="color: #aaa; font-size: 11px;">MEJOR MAPA</div>
                <div style="font-weight: bold;">{{ $stats['best_map'] ?? '-' }}</div>
            </div>
            <div>
                <div style="color: #aaa; font-size: 11px;">APERTURA MÁS USADA</div>
                <div style="font-weight: bold;">
                    {{ $stats['most_used_opening'] ? ucfirst(str_replace('_', ' ', $stats['most_used_opening'])) : '-' }}
                </div>
            </div>
        </div>

        {{-- Tiempos de edad --}}
        <div style="background: rgba(255,255,255,0.05); border-radius: 6px; padding: 8px; margin-bottom: 10px;">
            <div style="display: flex; justify-content: space-between; margin-bottom: 5px;">
                <span style="color: #aaa; font-size: 11px;">UPTIMES</span>
                <span style="color: #aaa; font-size: 11px;">{{ $stats['player_name'] }} vs Rivales</span>
            </div>

            <div style="display: flex; justify-content: space-between; align-items: center; padding: 3px 0;">
                <span>Feudal</span>
                <div style="display: flex; align-items: center; gap: 10px;">
                    <span
                        style="font-weight: bold;">{{ app('App\Http\Controllers\IndexController')->formatHms($stats['avg_feudal']) }}</span>
                    <span style="color: #777;">vs</span>
                    <span>{{ app('App\Http\Controllers\IndexController')->formatHms($stats['opp_avg_feudal']) }}</span>
                </div>
            </div>

            <div style="display: flex; justify-content: space-between; align-items: center; padding: 3px 0;">
                <span>Castillos</span>
                <div style="display: flex; align-items: center; gap: 10px;">
                    <span
                        style="font-weight: bold;">{{ app('App\Http\Controllers\IndexController')->formatHms($stats['avg_castle']) }}</span>
                    <span style="color: #777;">vs</span>
                    <span>{{ app('App\Http\Controllers\IndexController')->formatHms($stats['opp_avg_castle']) }}</span>
                </div>
            </div>

            <div style="display: flex; justify-content: space-between; align-items: center; padding: 3px 0;">
                <span>Imperial</span>
                <div style="display: flex; align-items: center; gap: 10px;">
                    <span
                        style="font-weight: bold;">{{ app('App\Http\Controllers\IndexController')->formatHms($stats['avg_imperial']) }}</span>
                    <span style="color: #777;">vs</span>
                    <span>{{ app('App\Http\Controllers\IndexController')->formatHms($stats['opp_avg_imperial']) }}</span>
                </div>
            </div>
        </div>

        {{-- Mapas --}}
        <div style="margin-bottom: 10px;">
            <div style="color: #aaa; font-size: 11px; margin-bottom: 5px;">RENDIMIENTO POR MAPA</div>
            @foreach ($stats['map_counts'] as $map => $count)
                @php
                    $wins = $stats['win_maps'][$map] ?? 0;
                    $losses = $stats['lose_maps'][$map] ?? 0;
                    $winRate = $count > 0 ? round(($wins / $count) * 100) : 0;
                @endphp
                <div
                    style="display: flex; justify-content: space-between; align-items: center; padding: 4px 0; border-bottom: 1px solid rgba(255,255,255,0.05);">
                    <span>{{ $map }}</span>
                    <div style="display: flex; align-items: center; gap: 8px;">
                        <span style="font-size: 11px; color: #4caf50;">{{ $wins }}W</span>
                        <span style="font-size: 11px; color: #f44336;">{{ $losses }}L</span>
                        <span style="font-size: 11px; color: #aaa;">({{ $winRate }}%)</span>
                    </div>
                </div>
            @endforeach
        </div>

        {{-- Aperturas del jugador --}}
        <div style="margin-bottom: 10px;">
            <div style="color: #aaa; font-size: 11px; margin-bottom: 5px;">APERTURAS</div>
            @foreach ($stats['openings'] as $opening => $count)
                <div style="padding: 4px 0; border-bottom: 1px solid rgba(255,255,255,0.05);">
                    <div style="display: flex; justify-content: space-between; align-items: center;">
                        <span style="font-weight: bold;">{{ ucfirst(str_replace('_', ' ', $opening)) }}</span>
                        <span style="font-size: 11px; color: #aaa;">{{ $count }}</span>
                    </div>
                </div>
            @endforeach
        </div>

        {{-- Aperturas rivales en derrotas --}}
        @if (!empty($stats['lose_openings']))
            <div style="margin-bottom: 10px;">
                <div style="color: #aaa; font-size: 11px; margin-bottom: 5px;">APERTURAS RIVALES EN DERROTAS</div>
                @foreach ($stats['lose_openings'] as $opening => $count)
                    <div
                        style="display: flex; justify-content: space-between; align-items: center; padding: 4px 0; border-bottom: 1px solid rgba(255,255,255,0.05);">
                        <span>{{ ucfirst(str_replace('_', ' ', $opening)) }}</span>
                        <div style="display: flex; align-items: center; gap: 5px;">
                            <span style="font-size: 11px; color: #f44336;">{{ $count }}❌</span>
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
    </div>
@endif
