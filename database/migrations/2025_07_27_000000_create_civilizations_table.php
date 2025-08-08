<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateCivilizationsTable extends Migration
{
    public function up()
    {
        Schema::create('civilizations', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('abbr')->nullable();
            $table->integer('number');
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('civilizations');
    }
}
