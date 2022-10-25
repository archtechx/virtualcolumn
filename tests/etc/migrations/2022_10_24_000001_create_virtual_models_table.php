<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateVirtualModelsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('virtual_models', function (Blueprint $table) {
            $table->increments('id');

            $table->json('data');
        });
    }

    public function down()
    {
        Schema::dropIfExists('foo_models');
    }
}
