<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateBarChildsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('bar_childs', function (Blueprint $table) {
            $table->increments('id');

            $table->string('bar')->nullable();

            $table->json('data')->nullable();
        });
    }

    public function down()
    {
        Schema::dropIfExists('bar_childs');
    }
}
