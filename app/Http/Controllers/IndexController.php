<?php

namespace App\Http\Controllers;

use App\Models\Civilization;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class IndexController extends Controller
{
    public function __invoke(Request $request)
    {
        set_time_limit(3000); // Aumentar tiempo de ejecución para múltiples solicitudes

        // Establecer valores por defecto si no vienen en la request
        $request->merge([
            'player_id' => $request->input('player_id', 8621659),
            'played_civilization' => $request->input('played_civilization'),
            'opponent_civ' => $request->input('opponent_civ'),
            'leaderboard' => $request->input('leaderboard', 'rm_1v1'),
            'pages' => $request->input('pages', 1),
        ]);

        $stats = $this->getPlayerStats($request);
        Log::info($stats['lose_openings'] ?? 'No openings found');

        Log::info('Estadísticas finales', $stats);
        return view('partials.aoe2_overlay', ['stats' => $stats]);
    }

    public function getPlayerStats($request)
    {
        $playerId = $request->input('player_id');
        $playedCivName = $request->input('played_civilization');
        $playedCivName = $playedCivName ? Str::lower(trim($playedCivName)) : null;

        $opponentCivOpt = $request->input('opponent_civ');
        $leaderboard = $request->input('leaderboard');
        $maxPages = intval($request->input('pages'));

        // Resolver nombres de civilizaciones a números
        $playedCivNum = $this->resolveCivNumber($playedCivName);
        $opponentCivNum = $this->resolveCivNumber($opponentCivOpt);

        Log::info('Iniciando fetch de partidas', compact('playerId', 'leaderboard', 'maxPages'));
        $matches = $this->fetchMatches($playerId, $leaderboard, $maxPages, $playedCivName);

        if (empty($matches)) {
            Log::warning('fetchMatches retornó vacío');
            return [
                'error' => 'No se encontraron partidas con los criterios especificados',
                'total' => 0
            ];
        }

        Log::info('Total de partidas obtenidas', ['count' => count($matches)]);
        return $this->analyzeMatches($matches, $playerId, $playedCivNum, $opponentCivNum);
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
        $maxSearchPages = 10; // Máximo de páginas cuando se busca por civ específica

        while (true) {
            // Condiciones de salida
            if ($playedCivName === null && $page > $maxPages)
                break;
            if ($playedCivName !== null && $page > $maxSearchPages)
                break;

            try {
                $response = Http::withHeaders([
                    "user-Agent" => "eduardr10-stats-script",
                ])->get('https://data.aoe2companion.com/api/matches', [
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
                $pageMatches = $payload['matches'] ?? [];
                $matchesFound = 0;

                foreach ($pageMatches as $m) {
                    $profile_team_index = $m['teams'][0]['players'][0]['profileId'] == $playerId ? 0 : 1;
                    $opponent_team_index = $profile_team_index == 0 ? 1 : 0;

                    $playerCivName = $m['teams'][$profile_team_index]['players'][0]['civName'] ?? null;

                    // Solo agregar si coincide con la civ buscada o no se especificó civ
                    if ($playedCivName === null || Str::lower($playerCivName) === $playedCivName) {
                        $matches[] = [
                            'match_id' => $m['matchId'],
                            'map_name' => $m['mapName'] ?? null,
                            'player_name' => $m['teams'][$profile_team_index]['players'][0]['name'] ?? null,
                            'player_civ' => $playerCivName,
                            'opponent_civ' => $m['teams'][$opponent_team_index]['players'][0]['civName'] ?? null,
                            'won' => $m['teams'][$profile_team_index]['players'][0]['won'] ?? false,
                            'started' => $m['started'] ?? null,
                            'finished' => $m['finished'] ?? null,
                        ];
                        $matchesFound++;
                    }
                }

                Log::info('Página fetchMatches procesada', [
                    'page' => $page,
                    'matches_on_page' => count($pageMatches),
                    'matches_found' => $matchesFound,
                    'total_matches' => count($matches)
                ]);

                // Salir si ya tenemos suficientes partidas (5) para civ específica
                $enoughMatches = $playedCivName === null || count($matches) >= 5;
                $hasMorePages = count($pageMatches) === $perPage;

                if (!$hasMorePages || $enoughMatches) {
                    break;
                }

                $page++;

            } catch (\Throwable $e) {
                Log::error('Error en fetchMatches', ['error' => $e->getMessage()]);
                break;
            }
        }

        if ($playedCivName !== null && empty($matches)) {
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
            'player_name' => $matches[0]['player_name'] ?? 'Unknown',
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
            Log::debug('Analizando partida', ['match_id' => $matchId]);

            $maxRetries = 6;
            $analysisSuccess = false;

            for ($i = 0; $i < $maxRetries; $i++) {
                try {
                    // Paso 1: Solicitar análisis
                    $analysisRequest = Http::withHeaders([
                        'accept' => 'application/json, text/plain, */*',
                        'referer' => "https://www.aoe2insights.com/match/{$matchId}/",
                        'user-agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0.0.0 Safari/537.36',
                    ])->get("https://www.aoe2insights.com/match/{$matchId}/analyze/");

                    if (!$analysisRequest->successful() || $analysisRequest->header('Content-Length') <= 0) {
                        Log::warning('Fallo en solicitud de análisis', [
                            'match_id' => $matchId,
                            'intento' => $i + 1,
                            'status' => $analysisRequest->status()
                        ]);
                        sleep(0.3);
                        if ($analysisRequest->status() == 404) {
                            Log::warning('Partida no encontrada o no analizada', ['match_id' => $matchId]);
                            $stats['skipped']++;
                            $analysisSuccess = true; // Marcar como éxito para evitar reintentos
                            break;
                        }
                        continue;
                    }

                    // Paso 2: Obtener datos de análisis
                    $dataResponse = Http::withHeaders([
                        'accept' => 'application/json, text/plain, */*',
                        'referer' => "https://www.aoe2insights.com/match/{$matchId}/",
                        'user-agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0.0.0 Safari/537.36',
                    ])->get("https://aoe2insights.s3.amazonaws.com/media/public/matches/analysis/analysis-{$matchId}.json");

                    if (!$dataResponse->successful()) {
                        Log::warning('Fallo al obtener datos de análisis', [
                            'match_id' => $matchId,
                            'intento' => $i + 1,
                            'status' => $dataResponse->status()
                        ]);
                        sleep(0.3);
                        continue;
                    }

                    $data = $dataResponse->json();
                    $analysisSuccess = true;
                    break;

                } catch (\Throwable $e) {
                    Log::warning('Error durante el análisis', [
                        'match_id' => $matchId,
                        'intento' => $i + 1,
                        'error' => $e->getMessage()
                    ]);
                    sleep(0.3);
                }
            }

            if (!$analysisSuccess) {
                Log::warning('No se pudo analizar la partida después de varios intentos', ['match_id' => $matchId]);
                $stats['skipped']++;
                continue;
            }

            // Procesar datos de la partida
            $map = $match['map_name'] ?? null;
            $meWon = $match['won'] ?? false;

            // Contar mapa
            if ($map) {
                $stats['map_counts'][$map] = ($stats['map_counts'][$map] ?? 0) + 1;

                if ($meWon) {
                    $stats['win_maps'][$map] = ($stats['win_maps'][$map] ?? 0) + 1;
                } else {
                    $stats['lose_maps'][$map] = ($stats['lose_maps'][$map] ?? 0) + 1;
                }
            }

            // Contar victoria
            if ($meWon) {
                $stats['victories']++;
            }

            // Procesar aperturas y tiempos de edad
            $players = $data['player'] ?? [];
            $uptimes = $data['uptimes'] ?? [];
            $strategy = $data['strategy'] ?? [];

            // Identificar índices de jugadores
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
                Log::warning('Índices de jugador no identificados', ['match_id' => $matchId]);
                $stats['skipped']++;
                continue;
            }

            // Aperturas
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
            Log::debug('Partida analizada exitosamente', ['match_id' => $matchId, 'analyzed' => $stats['analyzed']]);
        }

        // Calcular estadísticas agregadas
        $avg = fn($arr) => empty($arr) ? null : round(array_sum($arr) / count($arr));

        $stats['win_percent'] = $stats['total'] ? round($stats['victories'] * 100 / $stats['total'], 2) : 0;
        $stats['best_map'] = $stats['win_maps'] ? array_search(max($stats['win_maps']), $stats['win_maps']) : null;
        $stats['most_used_opening'] = $stats['openings'] ? array_search(max($stats['openings']), $stats['openings']) : null;

        foreach (['feudal', 'castle', 'imperial'] as $age) {
            $stats["avg_{$age}"] = $avg($stats['age_times'][$age]);
            $stats["opp_avg_{$age}"] = $avg($stats['opp_age_times'][$age]);
        }

        Log::info('Análisis completado', [
            'total_matches' => $stats['total'],
            'analyzed' => $stats['analyzed'],
            'skipped' => $stats['skipped']
        ]);

        return $stats;
    }

    public function formatHms($seconds)
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