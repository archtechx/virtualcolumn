<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateMyModelsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('my_models', function (Blueprint $table) {
            $table->increments('id');

            $table->string('custom1')->nullable();
            $table->string('custom2')->nullable();

            $table->json('data');
        });
    }

    public function down()
    {
        Schema::dropIfExists('my_models');
    }
}
