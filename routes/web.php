<?php

use App\Http\Controllers\IndexController;
use Illuminate\Support\Facades\Route;

Route::get('/{player_id?}', IndexController::class);
Route::get('ws', function () {
    return view('profile');
});
