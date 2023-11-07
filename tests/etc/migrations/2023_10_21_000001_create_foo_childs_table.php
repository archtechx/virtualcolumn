<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateFooChildsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('foo_childs', function (Blueprint $table) {
            $table->increments('id');

            $table->string('foo')->nullable();

            $table->json('data')->nullable();
        });
    }

    public function down()
    {
        Schema::dropIfExists('foo_childs');
    }
}
