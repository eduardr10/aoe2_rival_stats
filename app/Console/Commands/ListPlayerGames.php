<?php

namespace App\Console\Commands;

use App\Models\Civilization;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class ListPlayerGames extends Command
{
    protected $signature = 'games:list \
                        {--player_id=8621659} \
                        {--played_civilization=} \
                        {--opponent_civ=} \
                        {--leaderboard=rm_1v1} \
                        {--pages=1}';
    protected $description = 'Lista y analiza los juegos de un jugador usando API oficiales con logging para debugging';

    public function handle()
    {
        $playerId = $this->option('player_id');
        $playedCivName = $this->option('played_civilization');
        $playedCivName = $playedCivName ? Str::lower(trim($playedCivName)) : null;

        $opponentCivOpt = $this->option('opponent_civ');
        $leaderboard = $this->option('leaderboard');
        $maxPages = intval($this->option('pages'));

        // Resolver nombres de civilizaciones a números si es necesario
        $playedCivNum = $this->resolveCivNumber($playedCivName);
        $opponentCivNum = $this->resolveCivNumber($opponentCivOpt);

        Log::info('Iniciando fetch de partidas', compact('playerId', 'leaderboard', 'maxPages'));
        $matches = $this->fetchMatches($playerId, $leaderboard, $maxPages, $playedCivName);
        if (empty($matches)) {
            $this->error('No se obtuvieron partidas del jugador.');
            Log::warning('fetchMatches retornó vacío');
            return 1;
        }

        Log::info('Total de partidas obtenidas', ['count' => count($matches)]);
        $stats = $this->analyzeMatches($matches, $playerId, $playedCivNum, $opponentCivNum);

        $this->printReport($stats);
        if (app()->runningInConsole()) {
            return 0; // se sigue comportando igual si se llama desde la consola
        }

        return $stats; // se devuelve el array si se llama desde la web

    }

    private function resolveCivNumber($civOpt)
    {
        if (empty($civOpt))
            return null;
        if (is_numeric($civOpt))
            return intval($civOpt);
        $civ = Civilization::firstWhere('name', $civOpt);
        return $civ ? intval($civ->number) : null;
    }

    /**
     * Obtiene las partidas con metadatos desde la API Companion
     */
    private function fetchMatches(int $playerId, string $leaderboard, int $maxPages, ?string $playedCivName = null): array
    {
        $matches = [];
        $page = 1;
        $perPage = 10;
        $maxSearchPages = 10; // cuando se busca por civ específica

        while (true) {
            if ($playedCivName === null && $page > $maxPages) {
                break;
            }

            if ($playedCivName !== null && $page > $maxSearchPages) {
                break;
            }

            $response = Http::withHeaders([
                "User-Agent" => "eduardr10-stats-script",
            ])
                ->throw()
                ->get('https://data.aoe2companion.com/api/matches', [
                    'direction' => 'forward',
                    'profile_ids' => $playerId,
                    'leaderboard_ids' => $leaderboard,
                    'page' => $page,
                    'per_page' => $perPage
                ]);

            if (!$response->successful()) {
                Log::error('Error HTTP en fetchMatches', ['page' => $page, 'status' => $response->status()]);
                break;
            }

            $payload = $response->json();
            $pageMatches = [];

            foreach ($payload['matches'] as $m) {
                $profile_team_index = $m['teams'][0]['players'][0]['profileId'] == $playerId ? 0 : 1;
                $opponent_team_index = $profile_team_index == 0 ? 1 : 0;

                $playerCivName = $m['teams'][$profile_team_index]['players'][0]['civName'] ?? null;
                $matchData = [
                    'match_id' => $m['matchId'],
                    'map_name' => $m['mapName'] ?? null,
                    'player_name' => $m['teams'][$profile_team_index]['players'][0]['name'] ?? null,
                    'player_civ' => $playerCivName,
                    'opponent_civ' => $m['teams'][$opponent_team_index]['players'][0]['civName'] ?? null,
                    'won' => $m['teams'][$profile_team_index]['players'][0]['won'] ?? false,
                    'started' => $m['started'] ?? null,
                    'finished' => $m['finished'] ?? null,
                ];

                // Filtrar por civ jugada si corresponde
                if ($playedCivName === null || Str::lower($playerCivName) === $playedCivName) {
                    $matches[] = $matchData;
                }

                $pageMatches[] = $matchData;
            }

            Log::info('Página fetchMatches procesada', ['page' => $page, 'matches' => count($pageMatches)]);

            $hasMore = count($pageMatches) === $perPage;
            $enoughMatches = $playedCivName === null || count($matches) >= 5;

            if (!$hasMore || $enoughMatches) {
                break;
            }

            $page++;
        }

        if ($playedCivName !== null && count($matches) < 1) {
            Log::warning("No se encontraron partidas con la civ especificada", ['civ_name' => $playedCivName]);
        }

        return $matches;
    }


    /**
     * Analiza las partidas usando los JSON de insights y consolida estadísticas
     */
    private function analyzeMatches(array $matches, int $playerId, ?int $playedCiv, ?int $opponentCiv): array
    {
        $stats = [
            'total' => count($matches),
            'player_name' => $matches[0]['player_name'],
            'victories' => 0,
            'map_counts' => [],
            'win_maps' => [],
            'lose_maps' => [],
            'openings' => [],
            'lose_openings' => [],
            'age_times' => ['feudal' => [], 'castle' => [], 'imperial' => []],
            'opp_age_times' => ['feudal' => [], 'castle' => [], 'imperial' => []],
            'analyzed' => 0,
            'skipped' => 0,
        ];

        foreach ($matches as $match) {
            $matchId = $match['match_id'];
            Log::debug('Solicitando análisis de: ', ['match_id' => $matchId]);
            $maxRetries = 6;
            $request = null;
            for ($i = 0; $i < $maxRetries; $i++) {
                try {
                    $url = "https://www.aoe2insights.com/match/{$matchId}/analyze/";
                    $request = Http::withHeaders([
                        'accept' => 'application/json, text/plain, */*',
                        'referer' => "https://www.aoe2insights.com/match/" . $matchId . "/",
                        'user-agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0.0.0 Safari/537.36',
                    ])
                        ->get($url);

                    if ($request && $request->status() !== 404 && $request->header('Content-Length') > 0) {
                        Log::debug('Analizando partida', ['match_id' => $matchId]);

                        $url = "https://aoe2insights.s3.amazonaws.com/media/public/matches/analysis/analysis-{$matchId}.json";
                        $resp = Http::withHeaders([
                            'accept' => 'application/json, text/plain, */*',
                            'referer' => "https://www.aoe2insights.com/match/" . $matchId . "/",
                            'user-agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0.0.0 Safari/537.36',
                        ])
                            ->get($url);

                        if (!$resp->successful()) {
                            Log::warning('JSON de insights no disponible', ['match_id' => $matchId, 'status' => $resp?->status()]);
                            $stats['skipped']++;
                            continue;
                        }
                        $data = $resp->json();

                        // Contar mapa
                        if ($match['map_name']) {
                            $map = $match['map_name'];
                            $stats['map_counts'][$map] = ($stats['map_counts'][$map] ?? 0) + 1;
                        }

                        // Victoria
                        $meWon = $match['won'];
                        if ($meWon) {
                            $stats['victories']++;
                            if (isset($map)) {
                                $stats['win_maps'][$map] = ($stats['win_maps'][$map] ?? 0) + 1;
                            }
                        } else {
                            if (isset($map)) {
                                $stats['lose_maps'][$map] = ($stats['lose_maps'][$map] ?? 0) + 1;
                            }
                        }

                        // Aperturas del main y oponente
                        $players = $data['player'] ?? [];
                        $uptimes = $data['uptimes'] ?? [];
                        $strategy = $data['strategy'] ?? [];

                        // Identificar índices basados en playerId
                        $meIdx = null;
                        $oppIdx = null;
                        foreach ($players as $idx => $p) {
                            if (($p['profile_id'] ?? null) == $playerId) {
                                $meIdx = $idx;
                            } else {
                                $oppIdx = $idx;
                            }
                        }
                        if ($meIdx === null || $oppIdx === null) {
                            Log::warning('No se pudo identificar índices de jugador', ['match_id' => $matchId]);
                            $stats['skipped']++;
                            continue;
                        }

                        $opening = $strategy[$meIdx] ?? null;
                        if ($opening) {
                            $stats['openings'][$opening] = ($stats['openings'][$opening] ?? 0) + 1;
                        }
                        if (!$meWon) {
                            $oppOpening = $strategy[$oppIdx] ?? null;
                            if ($oppOpening) {
                                $stats['lose_openings'][$oppOpening] = ($stats['lose_openings'][$oppOpening] ?? 0) + 1;
                            }
                        }

                        // Tiempos de edad
                        foreach (['feudal', 'castle', 'imperial'] as $age) {
                            if (isset($uptimes[$meIdx][$age])) {
                                $stats['age_times'][$age][] = $uptimes[$meIdx][$age];
                            }
                            if (isset($uptimes[$oppIdx][$age])) {
                                $stats['opp_age_times'][$age][] = $uptimes[$oppIdx][$age];
                            }
                        }

                        $stats['analyzed']++;
                        break;
                    }

                } catch (\Throwable $e) {
                    Log::warning('Se reintentará el análisis: ', ['match_id' => $matchId, 'status' => $request->status()]);
                    sleep(0.3);
                }
            }
        }

        // Calcular promedios y porcentajes
        $avg = fn($arr) => empty($arr) ? null : round(array_sum($arr) / count($arr));
        $stats['win_percent'] = $stats['total'] ? round($stats['victories'] * 100 / $stats['total'], 2) : 0;
        $stats['best_map'] = $stats['win_maps'] ? array_search(max($stats['win_maps']), $stats['win_maps']) : null;
        $stats['most_used_opening'] = $stats['openings'] ? array_search(max($stats['openings']), $stats['openings']) : null;
        $stats['avg_feudal'] = $avg($stats['age_times']['feudal']);
        $stats['avg_castle'] = $avg($stats['age_times']['castle']);
        $stats['avg_imperial'] = $avg($stats['age_times']['imperial']);
        $stats['opp_avg_feudal'] = $avg($stats['opp_age_times']['feudal']);
        $stats['opp_avg_castle'] = $avg($stats['opp_age_times']['castle']);
        $stats['opp_avg_imperial'] = $avg($stats['opp_age_times']['imperial']);

        return $stats;
    }

    private function printReport(array $stats)
    {
        $this->info(str_repeat('=', 60));
        $this->info("Datos de {$stats['player_name']}");
        $this->info("Partidas totales: {$stats['total']}");
        $this->info("Victorias: {$stats['victories']} ({$stats['win_percent']}%)");
        $this->info("Mejor mapa: " . ($stats['best_map'] ?: 'N/A'));
        $this->info("Apertura más usada: " . ($stats['most_used_opening'] ?: 'N/A'));
        $this->warn(str_repeat('-', 60));

        $this->warn('Resumen de mapas:');
        foreach ($stats['map_counts'] as $map => $count) {
            $this->line("- {$map}: {$count}");
        }

        $this->warn('Victorias por mapa:');
        foreach ($stats['win_maps'] as $map => $count) {
            // $pct = $stats['victories'] ? round($count * 100 / $stats['victories'], 2) : 0;
            $this->line("- {$map}: {$count}");
        }

        $this->warn('Aperturas usadas:');
        foreach ($stats['openings'] as $op => $count) {
            $pct = $stats['total'] ? round($count * 100 / $stats['total'], 2) : 0;
            $this->line("- {$op}: {$count} ({$pct}%)");
        }

        $this->warn('Aperturas enemigas en derrotas:');
        foreach ($stats['lose_openings'] as $op => $count) {
            $this->line("- {$op}: {$count}");
        }

        $this->warn(str_repeat('-', 60));
        $this->info('Promedios de paso de edad:');
        $this->line("Feudal: " . $this->formatHms($stats['avg_feudal']));
        $this->line("Castillo: " . $this->formatHms($stats['avg_castle']));
        $this->line("Imperial: " . $this->formatHms($stats['avg_imperial']));

        $this->warn('Promedios rivales de paso de edad:');
        $this->line("Feudal: " . $this->formatHms($stats['opp_avg_feudal']));
        $this->line("Castillo: " . $this->formatHms($stats['opp_avg_castle']));
        $this->line("Imperial: " . $this->formatHms($stats['opp_avg_imperial']));
        $this->info(str_repeat('=', 60));
    }
    private function formatHms($seconds)
    {
        if ($seconds === null)
            return 'N/A';
        // Si el valor es muy grande, probablemente está en milisegundos
        if ($seconds > 100000) {
            $seconds = round($seconds / 1000);
        }
        $h = floor($seconds / 3600);
        $m = floor(($seconds % 3600) / 60);
        $s = $seconds % 60;
        if ($h > 0) {
            return sprintf("%d:%02d:%02d", $h, $m, $s);
        } else {
            return sprintf("%d:%02d", $m, $s);
        }
    }
}
