<?php

namespace App\Http\Controllers;

use App\Models\Civilization;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class IndexController extends Controller
{
    /**
     * Endpoint to analyze a specific match by matchId
     */
    public function analyze(Request $request, $player_id = null): \Illuminate\Http\JsonResponse
    {
        $matchId = $request->query('matchId');
        if (!$matchId) {
            return response()->json(['error' => 'matchId requerido'], 400);
        }
        // Usar los métodos existentes para obtener el análisis
        $data = [
            'player_id' => $player_id,
            'leaderboard' => $request->input('leaderboard', 'rm_1v1'),
            'pages' => 1,
            'per_page' => 1,
        ];
        // Buscar el match específico
        $matches = $this->fetchMatches($player_id, $data['leaderboard'], 1, 1);
        $match = collect($matches)->firstWhere('match_id', $matchId);
        if (!$match) {
            return response()->json(['error' => 'Match no encontrado'], 404);
        }
        $stats = $this->analyzeMatches([$match], $player_id, null, null);
        return response()->json($stats);
    }
    public function __invoke(Request $request, $player_id)
    {
        set_time_limit(3000);
        $matchId = $request->query('matchId');
        // Si es AJAX y tiene matchId, devolver análisis JSON
        if ($request->ajax() && $matchId) {
            // Use analyze logic
            $data = [
                'player_id' => $player_id,
                'leaderboard' => $request->input('leaderboard', 'rm_1v1'),
                'pages' => 1,
                'per_page' => 1,
            ];
            $matches = $this->fetchMatches($player_id, $data['leaderboard'], 1, 1);
            $match = collect($matches)->firstWhere('match_id', $matchId);
            if (!$match) {
                return response()->json(['error' => 'Match no encontrado'], 404);
            }
            $stats = $this->analyzeMatches([$match], $player_id, null, null);
            return response()->json($stats);
        }
        // Flujo normal: renderizar la vista
        $ongoing = $request->input('ongoing', false);
        $request->merge([
            'player_id' => $player_id ?? 8621659,
            'played_civilization' => $request->input('played_civilization'),
            'opponent_civ' => $request->input('opponent_civ'),
            'leaderboard' => $request->input('leaderboard', 'rm_1v1'),
            'pages' => $request->input('pages', 1),
            'ongoing' => $ongoing,
            'per_page' => $request->input('per_page', $ongoing ? 11 : 10),
        ]);
        $data = $request->all();
        $stats = $this->getPlayerStats($data);
        return view('partials.aoe2_overlay', ['stats' => $stats]);
    }

    public function getRating(string $player_id)
    {
        $useCache = env('USE_CACHE_FILES', false);

        if ($useCache) {
            return 1250;
        }

        $data = Http::withHeaders([
            "user-Agent" => "eduardr10-stats-script",
        ])
            ->get('https://data.aoe2companion.com/api/profiles/' . $player_id, [
                'page' => 1
            ]);

        if (!$data->successful()) {
            Log::error('Error al obtener el rating del jugador', ['player_id' => $player_id, 'status' => $data->status()]);
            return null;
        }

        $profile = $data->json();
        if (empty($profile) || !isset($profile['leaderboards'][0]['rating'])) {
            Log::warning('Perfil no encontrado o sin rating', ['player_id' => $player_id]);
            return null;
        }

        return $profile['leaderboards'][0]['rating'];
    }

    public function getPlayerStats($data_main_player)
    {
        $playerId = $data_main_player['player_id'];
        $playedCivName = $data_main_player['played_civilization'];
        $playedCivName = $playedCivName ? Str::lower(trim($playedCivName)) : null;
        $opponentCivOpt = $data_main_player['opponent_civ'];
        $leaderboard = $data_main_player['leaderboard'];
        $per_page = intval($data_main_player['per_page']);
        $pages = intval($data_main_player['pages']);
        $playedCivNum = $this->resolveCivNumber($playedCivName);
        $opponentCivNum = $this->resolveCivNumber($opponentCivOpt);
        $matches = $this->fetchMatches($playerId, $leaderboard, $per_page, $pages, $playedCivName, $opponentCivOpt);

        if (empty($matches)) {
            Log::warning('getPlayerStats: No se encontraron partidas con los criterios especificados', $data_main_player);
            return [
                'error' => 'No se encontraron partidas con los criterios especificados',
                'total' => 0
            ];
        }
        if ($data_main_player['ongoing']) {
            $matches = array_filter($matches, function ($m) {
                return $m['finished'] !== null;
            });
        }

        $stats = $this->analyzeMatches($matches, $playerId, $playedCivNum, $opponentCivNum);
        $stats['total_wins'] = collect($matches)->where('won', true)->count();
        $stats['win_percent'] = $stats['total'] ? round($stats['total_wins'] * 100 / $stats['total'], 2) : 0;
        $stats['rating'] = $this->getRating($playerId);
        return $stats;
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
    private function fetchMatches(int $playerId, string $leaderboard, int $per_page, int $pages, ?string $playedCivName = null, ?string $opponentCivOpt = null): array
    {
        $useCache = env('USE_CACHE_FILES', false);
        $matches = [];
        $cachePath = base_path('storage/app/match_analysis');
        if (!is_dir($cachePath)) {
            mkdir($cachePath, 0777, true);
        }
        $civKey = $playedCivName ? Str::slug($playedCivName) : 'all';
        $cacheFile = $cachePath . "/matches_{$playerId}_{$leaderboard}_{$civKey}.json";
        if ($useCache && file_exists($cacheFile)) {
            $json = @file_get_contents($cacheFile);
            $matches = @json_decode($json, true);
            foreach ($matches as &$match) {
                if (isset($match['match_id'])) {
                    $fileName = 'match_' . $match['match_id'] . '.json';
                    $match['analysis_path'] = $cachePath . DIRECTORY_SEPARATOR . $fileName;
                }
            }
            unset($match);
        } else {
            $page = 1;
            $maxSearchPages = 10;
            while (true) {
                if ($playedCivName === null && $opponentCivOpt === null && $page > $pages) {
                    break;
                } else if ($page > $maxSearchPages) {
                    break;
                }

                try {
                    $response = Http::withHeaders([
                        "user-agent" => "eduardr10-stats-script",
                    ])
                        ->get('https://data.aoe2companion.com/api/matches', [
                            'direction' => 'forward',
                            'profile_ids' => $playerId,
                            'leaderboard_ids' => $leaderboard,
                            'page' => $page,
                            'per_page' => $per_page
                        ]);

                    if (!$response->successful()) {
                        Log::error('fetchMatches: respuesta no exitosa', ['status' => $response->status()]);
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
                            $match = [
                                'match_id' => $m['matchId'],
                                'map_name' => $m['mapName'] ?? null,
                                'player_name' => $m['teams'][$profile_team_index]['players'][0]['name'] ?? null,
                                'player_civ' => $playerCivName,
                                'opponent_civ' => $m['teams'][$opponent_team_index]['players'][0]['civName'] ?? null,
                                'won' => $m['teams'][$profile_team_index]['players'][0]['won'] ?? false,
                                'started' => $m['started'] ?? null,
                                'finished' => $m['finished'] ?? null,
                            ];
                            // Agregar analysis_path
                            $match['analysis_path'] = $cachePath . "/analysis_{$match['match_id']}.json";
                            $matches[] = $match;
                            $matchesFound++;
                        }
                    }
                    // Salir si ya tenemos suficientes partidas (5) para civ específica
                    $enoughMatches = $playedCivName === null || count($matches) >= 5;
                    $hasMorePages = count($pageMatches) === $per_page;
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
            if ($useCache) {
                try {
                    file_put_contents($cacheFile, json_encode($matches, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
                } catch (\Throwable $e) {
                    Log::error('fetchMatches: error guardando cache', ['error' => $e->getMessage()]);
                }
            }
        }
        if (!is_array($matches)) {
            Log::error('fetchMatches: matches no es array', ['matches' => $matches]);
            $matches = [];
        }
        return $matches;
    }

    /**
     * Convierte timestamp tipo "0:09:26.720000" a segundos
     */
    private function parseTimestamp($timestamp)
    {
        if (!$timestamp)
            return null;
        $parts = explode(':', $timestamp);
        if (count($parts) < 3)
            return null;
        $h = intval($parts[0]);
        $m = intval($parts[1]);
        $s = floatval($parts[2]);
        return intval($h * 3600 + $m * 60 + $s);
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

    private function analyzeMatches(array $matches, int $playerId, ?int $playedCiv, ?int $opponentCiv): array
    {
        $useCache = env('USE_CACHE_FILES', false);
        $marketSums = ['feudal' => ['buy' => [], 'sell' => []], 'castle' => ['buy' => [], 'sell' => []], 'imperial' => ['buy' => [], 'sell' => []]];
        $marketCounts = ['feudal' => ['buy' => [], 'sell' => []], 'castle' => ['buy' => [], 'sell' => []], 'imperial' => ['buy' => [], 'sell' => []]];
        $techTimesGlobal = ['feudal' => [], 'castle' => [], 'imperial' => []];
        $wheelBarrow = [];
        $handCart = [];
        $stats = [
            'total' => count($matches),
            'player_name' => $matches[0]['player_name'] ?? 'Unknown',
            'victories' => 0,
            'map_counts' => [],
            'win_maps' => [],
            'lose_maps' => [],
            'age_times' => ['feudal' => [], 'castle' => [], 'imperial' => []],
            'opp_age_times' => ['feudal' => [], 'castle' => [], 'imperial' => []],
            'eapm' => [],
            'prefer_random' => [],
            'techs_after_age' => ['feudal' => [], 'castle' => [], 'imperial' => []],
            'tech_times_after_age' => ['feudal' => [], 'castle' => [], 'imperial' => []],
            'techs_top5_after_age' => ['feudal' => [], 'castle' => [], 'imperial' => []],
            'techs_full_after_age' => ['feudal' => [], 'castle' => [], 'imperial' => []],
            'map_played' => [],
            'map_win_percent' => [],
            'civ_played' => [],
            'market_resources_by_age' => ['feudal' => ['buy' => [], 'sell' => []], 'castle' => ['buy' => [], 'sell' => []], 'imperial' => ['buy' => [], 'sell' => []]],
            'market_times_by_age' => ['feudal' => [], 'castle' => [], 'imperial' => []],
            'analyzed' => 0,
            'skipped' => 0,
        ];
        $ageTechFilter = ['feudal age', 'castle age', 'imperial age'];
        $ages = ['feudal', 'castle', 'imperial'];
        foreach ($matches as $match) {
            if ($useCache) {
                $analysisPath = $match['analysis_path'] ?? null;
                $data = null;
                if ($analysisPath && file_exists($analysisPath)) {
                    $json = @file_get_contents($analysisPath);
                    $data = @json_decode($json, true);
                }
                if (!$data) {
                    Log::warning('analyzeMatches: sin datos analysis', ['match' => $match]);
                    $stats['skipped']++;
                    continue;
                }
            } else {

                $matchId = $match['match_id'];

                $analysisRequest = Http::withHeaders([
                    "user-agent" => "eduardr10-stats-script",
                ])
                    ->timeout(60)
                    ->get("https://data.aoe2companion.com/api/matches/{$matchId}/analysis?language=es");


                if ($analysisRequest->failed()) {
                    Log::warning('Fallo en solicitud de análisis', [
                        'match_id' => $matchId,
                        'status' => $analysisRequest->status()
                    ]);
                    Log::warning('Partida no encontrada o no analizada', ['match_id' => $matchId]);
                    $stats['skipped']++;
                    continue;
                }
                $data = $analysisRequest->json();
                Log::info('analyzeMatches: análisis obtenido', ['match_id' => $matchId]);
            }
            $players = $data['players'] ?? [];
            $mePlayer = null;
            $oppPlayer = null;
            foreach ($players as $p) {
                if (isset($p['profileId']) && $p['profileId'] == $playerId) {
                    $mePlayer = $p;
                } else {
                    $oppPlayer = $p;
                }
            }
            if (!$mePlayer) {
                $mePlayer = $players[0] ?? [];
            }
            if (!$oppPlayer && count($players) > 1) {
                $oppPlayer = $players[1];
            }
            $meCiv = $mePlayer['civilization'] ?? null;
            $meEapm = $mePlayer['eapm'] ?? null;
            $mePreferRandom = $mePlayer['preferRandom'] ?? null;
            $mapName = $data['map']['name'] ?? ($data['map'] ?? ($match['map_name'] ?? null));
            $winner = (bool) ($mePlayer['winner'] ?? false);

            $queuedTechs = $mePlayer['queuedTechs'] ?? [];
            foreach ($queuedTechs as $t) {

                $tSec = $this->parseTimestamp($t['timestamp'] ?? null);
                $unit = $t['unit'] ?? null;
                if ($unit === 'Wheelbarrow' && $tSec !== null) {
                    $wheelBarrow[] = $tSec;
                }

                if ($unit === 'Hand Cart' && $tSec !== null) {
                    $handCart[] = $tSec;
                }
            }

            if (!empty($mapName) && is_string($mapName)) {
                if (!isset($stats['map_played'][$mapName]) || !is_numeric($stats['map_played'][$mapName]))
                    $stats['map_played'][$mapName] = 0;
                $stats['map_played'][$mapName]++;
                if ($winner) {
                    if (!isset($stats['win_maps'][$mapName]) || !is_numeric($stats['win_maps'][$mapName]))
                        $stats['win_maps'][$mapName] = 0;
                    $stats['win_maps'][$mapName]++;
                } else {
                    if (!isset($stats['lose_maps'][$mapName]) || !is_numeric($stats['lose_maps'][$mapName]))
                        $stats['lose_maps'][$mapName] = 0;
                    $stats['lose_maps'][$mapName]++;
                }
            }
            // Uptimes jugador principal
            $meUptimes = [];
            if (isset($mePlayer['uptimes']) && is_array($mePlayer['uptimes'])) {
                foreach ($mePlayer['uptimes'] as $uptime) {
                    if (isset($uptime['age']) && isset($uptime['timestamp'])) {
                        $ageKey = strtolower(str_replace(['_age', 'age'], '', $uptime['age']));
                        $seconds = $this->parseTimestamp($uptime['timestamp']);
                        if ($seconds !== null) {
                            $meUptimes[$ageKey] = $seconds;
                            $stats['age_times'][$ageKey][] = $seconds;
                        }
                    }
                }
            }
            // Si no se detectó imperial, buscar en queuedTechs
            if (!isset($meUptimes['imperial']) && isset($mePlayer['queuedTechs']) && is_array($mePlayer['queuedTechs'])) {
                foreach ($mePlayer['queuedTechs'] as $t) {
                    if (isset($t['unit']) && strtolower($t['unit']) === 'imperial age' && isset($t['timestamp'])) {
                        $seconds = $this->parseTimestamp($t['timestamp']);
                        if ($seconds !== null) {
                            $meUptimes['imperial'] = $seconds;
                            $stats['age_times']['imperial'][] = $seconds;
                        }
                    }
                }
            }
            // Uptimes rival
            if ($oppPlayer && isset($oppPlayer['uptimes']) && is_array($oppPlayer['uptimes'])) {
                foreach ($oppPlayer['uptimes'] as $uptime) {
                    if (isset($uptime['age']) && isset($uptime['timestamp'])) {
                        $ageKey = strtolower(str_replace(['_age', 'age'], '', $uptime['age']));
                        $seconds = $this->parseTimestamp($uptime['timestamp']);
                        if ($seconds !== null) {
                            $stats['opp_age_times'][$ageKey][] = $seconds;
                        }
                    }
                }
            }
            if ($oppPlayer && !isset($stats['opp_age_times']['imperial']) && isset($oppPlayer['queuedTechs']) && is_array($oppPlayer['queuedTechs'])) {
                foreach ($oppPlayer['queuedTechs'] as $t) {
                    if (isset($t['unit']) && strtolower($t['unit']) === 'imperial age' && isset($t['timestamp'])) {
                        $seconds = $this->parseTimestamp($t['timestamp']);
                        if ($seconds !== null) {
                            $stats['opp_age_times']['imperial'][] = $seconds;
                        }
                    }
                }
            }
            // Techs after each age
            $techs = $mePlayer['queuedTechs'] ?? [];
            foreach ($ages as $idx => $age) {
                if (isset($meUptimes[$age])) {
                    $ageTime = $meUptimes[$age];
                    $nextAgeTime = null;
                    if ($idx < count($ages) - 1 && isset($meUptimes[$ages[$idx + 1]])) {
                        $nextAgeTime = $meUptimes[$ages[$idx + 1]];
                    }
                    $techsAfter = [];
                    $techTimes = [];
                    $techsFull = [];
                    foreach ($techs as $t) {
                        if (isset($t['timestamp']) && isset($t['unit'])) {
                            $tSec = $this->parseTimestamp($t['timestamp']);
                            $unit = $t['unit'];
                            if ($tSec !== null && !in_array(strtolower($unit), $ageTechFilter)) {
                                $inRange = $tSec > $ageTime && ($nextAgeTime === null || $tSec < $nextAgeTime);
                                if ($inRange) {
                                    $techsAfter[] = $unit;
                                    $techTimes[] = $tSec - $ageTime;
                                    $techsFull[] = ['unit' => $unit, 'time' => $tSec - $ageTime, 'abs_time' => $tSec];
                                    if (!array_key_exists($unit, $techTimesGlobal[$age]) || !is_array($techTimesGlobal[$age][$unit]))
                                        $techTimesGlobal[$age][$unit] = [];
                                    $techTimesGlobal[$age][$unit][] = $tSec - $ageTime;
                                }
                            }
                        }
                    }
                    $stats['techs_after_age'][$age][] = $techsAfter;
                    $stats['tech_times_after_age'][$age][] = $techTimes;
                    $stats['techs_full_after_age'][$age][] = $techsFull;
                }
            }
            // Top 5 techs más frecuentes por edad
            foreach ($ages as $age) {
                $allTechs = [];
                $techTimesAbs = [];
                foreach ($stats['techs_full_after_age'][$age] as $arr) {
                    foreach ($arr as $techData) {
                        $tech = $techData['unit'];
                        $absTime = isset($techData['abs_time']) ? $techData['abs_time'] : null;
                        if (!isset($allTechs[$tech]))
                            $allTechs[$tech] = 0;
                        $allTechs[$tech]++;
                        if (!isset($techTimesAbs[$tech]))
                            $techTimesAbs[$tech] = [];
                        if ($absTime !== null)
                            $techTimesAbs[$tech][] = $absTime;
                    }
                }
                arsort($allTechs);
                $stats['techs_top5_after_age'][$age] = array_slice(array_keys($allTechs), 0, 5);
                $stats['techs_top5_avg_time'][$age] = [];
                foreach ($stats['techs_top5_after_age'][$age] as $tech) {
                    $times = $techTimesAbs[$tech] ?? [];
                    $stats['techs_top5_avg_time'][$age][$tech] = !empty($times) ? round(array_sum($times) / count($times), 2) : null;
                }
            }
            // Market usage
            $marketUses = $mePlayer['market'] ?? [];
            foreach ($marketUses as $mu) {
                if (isset($mu['timestamp']) && isset($mu['type']) && isset($mu['unit']) && isset($mu['amount'])) {
                    $marketSec = $this->parseTimestamp($mu['timestamp']);
                    $marketAge = null;
                    foreach ($ages as $idx => $age) {
                        if (isset($meUptimes[$age])) {
                            $ageTime = $meUptimes[$age];
                            $nextAgeTime = null;
                            if ($idx < count($ages) - 1 && isset($meUptimes[$ages[$idx + 1]])) {
                                $nextAgeTime = $meUptimes[$ages[$idx + 1]];
                            }
                            $inRange = $marketSec >= $ageTime && ($nextAgeTime === null || $marketSec < $nextAgeTime);
                            if ($inRange) {
                                $marketAge = $age;
                                break;
                            }
                        }
                    }
                    if ($marketAge) {
                        if (!isset($stats['market_resources_by_age'][$marketAge][$mu['type']][$mu['unit']]))
                            $stats['market_resources_by_age'][$marketAge][$mu['type']][$mu['unit']] = 0;
                        $stats['market_resources_by_age'][$marketAge][$mu['type']][$mu['unit']] += $mu['amount'];
                        $stats['market_times_by_age'][$marketAge][] = $marketSec;
                        if (!isset($marketSums[$marketAge][$mu['type']][$mu['unit']]))
                            $marketSums[$marketAge][$mu['type']][$mu['unit']] = 0;
                        if (!isset($marketCounts[$marketAge][$mu['type']][$mu['unit']]))
                            $marketCounts[$marketAge][$mu['type']][$mu['unit']] = 0;
                        $marketSums[$marketAge][$mu['type']][$mu['unit']] += $mu['amount'];
                        $marketCounts[$marketAge][$mu['type']][$mu['unit']]++;
                    }
                }
            }
            $stats['analyzed']++;
            if ($meEapm !== null)
                $stats['eapm'][] = $meEapm;
            if ($mePreferRandom !== null)
                $stats['prefer_random'][] = $mePreferRandom ? 1 : 0;
            if ($meCiv) {
                if (!isset($stats['civ_played'][$meCiv]))
                    $stats['civ_played'][$meCiv] = 0;
                $stats['civ_played'][$meCiv]++;
            }
        }

        $avg = function ($arr) {
            return empty($arr) ? null : round(array_sum($arr) / count($arr), 2);
        };

        foreach ($ages as $age) {
            $stats["avg_{$age}"] = $avg($stats['age_times'][$age]);
            $stats["avg_{$age}_hms"] = $stats["avg_{$age}"] !== null ? $this->formatHms($stats["avg_{$age}"]) : 'N/A';
            $stats["opp_avg_{$age}"] = $avg($stats['opp_age_times'][$age]);
            $stats["opp_avg_{$age}_hms"] = $stats["opp_avg_{$age}"] !== null ? $this->formatHms($stats["opp_avg_{$age}"]) : 'N/A';
            $stats["avg_techs_after_{$age}"] = $avg(array_map('count', $stats['techs_after_age'][$age]));
            $stats["avg_tech_time_after_{$age}"] = $avg(array_merge(...$stats['tech_times_after_age'][$age]));
        }
        $stats['avg_eapm'] = $avg($stats['eapm']);
        $stats['percent_prefer_random'] = $stats['prefer_random'] ? round(array_sum($stats['prefer_random']) * 100 / count($stats['prefer_random']), 2) : null;
        $totalMaps = array_sum($stats['map_played']);
        $stats['map_played_percent'] = [];
        $stats['map_win_percent'] = [];
        if ($totalMaps > 0) {
            foreach ($stats['map_played'] as $map => $count) {
                $winCount = $stats['win_maps'][$map] ?? 0;
                $stats['map_win_percent'][$map] = $count ? round($winCount * 100 / $count, 2) : 0;
                $stats['map_played_percent'][$map] = round($count * 100 / $totalMaps, 2);
            }
        }
        $totalCivs = array_sum($stats['civ_played']);
        $stats['civ_played_percent'] = [];
        foreach ($stats['civ_played'] as $civ => $count) {
            if ($count >= 2) {
                $stats['civ_played_percent'][$civ] = $totalCivs ? round($count * 100 / $totalCivs, 2) : 0;
            }
        }
        $stats['best_map'] = $stats['win_maps'] ? array_search(max($stats['win_maps']), $stats['win_maps']) : null;
        // Calcular promedios de compra/venta por edad y tipo
        $stats['market_avg_by_age'] = [];
        foreach (["feudal", "castle", "imperial"] as $age) {
            $stats['market_avg_by_age'][$age] = ['buy' => [], 'sell' => []];
            foreach (['buy', 'sell'] as $type) {
                if (isset($stats['market_resources_by_age'][$age][$type])) {
                    foreach ($stats['market_resources_by_age'][$age][$type] as $resource => $total) {
                        // Contar ocurrencias
                        $count = 0;
                        if (isset($marketCounts[$age][$type][$resource])) {
                            $count = $marketCounts[$age][$type][$resource];
                        }
                        $avg = ($count > 0) ? round($total / $count, 2) : null;
                        $stats['market_avg_by_age'][$age][$type][$resource] = $avg;
                    }
                }
            }
        }
        // Calcular las 5 techs más rápidas (menor timestamp absoluto) por edad, globales
        $stats['techs_first5_after_age'] = [];
        foreach (["feudal", "castle", "imperial"] as $age) {
            $allTechs = [];
            foreach ($stats['techs_full_after_age'][$age] as $techArr) {
                foreach ($techArr as $tech) {
                    if (isset($tech['unit']) && isset($tech['abs_time'])) {
                        $allTechs[] = $tech;
                    }
                }
            }
            usort($allTechs, function ($a, $b) {
                return ($a['abs_time'] ?? 0) <=> ($b['abs_time'] ?? 0);
            });
            $stats['techs_first5_after_age'][$age] = array_slice($allTechs, 0, 5);
        }
        $stats['wheel_barrow_avg'] = count($wheelBarrow) ? array_sum($wheelBarrow) / count($wheelBarrow) : null;
        $stats['hand_cart_avg'] = count($handCart) ? array_sum($handCart) / count($handCart) : null;


        return $stats;
    }
}