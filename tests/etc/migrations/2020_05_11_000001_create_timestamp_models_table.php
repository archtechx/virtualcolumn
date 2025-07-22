<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateTimestampModelsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('timestamp_models', function (Blueprint $table) {
            $table->increments('id');

            $table->timestamps();
            $table->json('data');
        });
    }

    public function down()
    {
        Schema::dropIfExists('timestamp_models');
    }
}
