<?php

namespace Database\Seeders;

use App\Models\Civilization;
use Illuminate\Database\Seeder;
use App\Models\Civilitation;

class CivilizationSeeder extends Seeder
{
    public function run()
    {
        $civilizations = [
            ['name' => 'Armenians', 'abbr' => null, 'number' => 45],
            ['name' => 'Aztecs', 'abbr' => null, 'number' => 0],
            ['name' => 'Bengalis', 'abbr' => null, 'number' => 42],
            ['name' => 'Berbers', 'abbr' => null, 'number' => 1],
            ['name' => 'Bohemians', 'abbr' => null, 'number' => 39],
            ['name' => 'Britons', 'abbr' => null, 'number' => 2],
            ['name' => 'Bulgarians', 'abbr' => null, 'number' => 3],
            ['name' => 'Burgundians', 'abbr' => null, 'number' => 35],
            ['name' => 'Burmese', 'abbr' => null, 'number' => 4],
            ['name' => 'Byzantines', 'abbr' => null, 'number' => 5],
            ['name' => 'Celts', 'abbr' => null, 'number' => 6],
            ['name' => 'Chinese', 'abbr' => null, 'number' => 7],
            ['name' => 'Cumans', 'abbr' => null, 'number' => 8],
            ['name' => 'Dravidians', 'abbr' => null, 'number' => 41],
            ['name' => 'Ethiopians', 'abbr' => null, 'number' => 9],
            ['name' => 'Franks', 'abbr' => null, 'number' => 10],
            ['name' => 'Georgians', 'abbr' => null, 'number' => 46],
            ['name' => 'Goths', 'abbr' => null, 'number' => 11],
            ['name' => 'Gurjaras', 'abbr' => null, 'number' => 43],
            ['name' => 'Hindustanis', 'abbr' => null, 'number' => 40],
            ['name' => 'Huns', 'abbr' => null, 'number' => 12],
            ['name' => 'Incas', 'abbr' => null, 'number' => 13],
            ['name' => 'Indians', 'abbr' => null, 'number' => 14],
            ['name' => 'Italians', 'abbr' => null, 'number' => 15],
            ['name' => 'Japanese', 'abbr' => null, 'number' => 16],
            ['name' => 'Jurchens', 'abbr' => null, 'number' => 53],
            ['name' => 'Khitans', 'abbr' => null, 'number' => 54],
            ['name' => 'Khmer', 'abbr' => null, 'number' => 17],
            ['name' => 'Koreans', 'abbr' => null, 'number' => 18],
            ['name' => 'Lithuanians', 'abbr' => null, 'number' => 19],
            ['name' => 'Magyars', 'abbr' => null, 'number' => 20],
            ['name' => 'Malay', 'abbr' => null, 'number' => 21],
            ['name' => 'Malians', 'abbr' => null, 'number' => 22],
            ['name' => 'Mayans', 'abbr' => null, 'number' => 23],
            ['name' => 'Mongols', 'abbr' => null, 'number' => 24],
            ['name' => 'Persians', 'abbr' => null, 'number' => 25],
            ['name' => 'Poles', 'abbr' => null, 'number' => 38],
            ['name' => 'Portuguese', 'abbr' => null, 'number' => 26],
            ['name' => 'Romans', 'abbr' => null, 'number' => 44],
            ['name' => 'Saracens', 'abbr' => null, 'number' => 27],
            ['name' => 'Shu', 'abbr' => null, 'number' => 50],
            ['name' => 'Sicilians', 'abbr' => null, 'number' => 36],
            ['name' => 'Slavs', 'abbr' => null, 'number' => 28],
            ['name' => 'Spanish', 'abbr' => null, 'number' => 29],
            ['name' => 'Tatars', 'abbr' => null, 'number' => 30],
            ['name' => 'Teutons', 'abbr' => null, 'number' => 31],
            ['name' => 'Turks', 'abbr' => null, 'number' => 32],
            ['name' => 'Vietnamese', 'abbr' => null, 'number' => 33],
            ['name' => 'Vikings', 'abbr' => null, 'number' => 34],
            ['name' => 'Wei', 'abbr' => null, 'number' => 52],
            ['name' => 'Wu', 'abbr' => null, 'number' => 51],
        ];

        foreach ($civilizations as $civ) {
            Civilization::create($civ);
        }
    }
}
