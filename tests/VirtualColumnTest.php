<?php

namespace Stancl\VirtualColumn\Tests;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Orchestra\Testbench\TestCase;
use Stancl\VirtualColumn\VirtualColumn;

class VirtualColumnTest extends TestCase
{
    public function setUp(): void
    {
        parent::setUp();

        $this->loadMigrationsFrom(__DIR__ . '/etc/migrations');
    }

    /** @test */
    public function keys_which_dont_have_their_own_column_go_into_data_json_column()
    {
        $model = MyModel::create([
            'foo' => 'bar',
        ]);

        // Test that model works correctly
        $this->assertSame('bar', $model->foo);
        $this->assertSame(null, $model->data);

        // Low level test to assert database structure
        $this->assertSame(['foo' => 'bar'], json_decode(DB::table('my_models')->where('id', $model->id)->first()->data, true));
        $this->assertSame(null, DB::table('my_models')->where('id', $model->id)->first()->foo ?? null);

        // Model has the correct structure when retrieved
        $model = MyModel::first();
        $this->assertSame('bar', $model->foo);
        $this->assertSame(null, $model->data);

        // Model can be updated
        $model->update([
            'foo' => 'baz',
            'abc' => 'xyz',
        ]);

        $this->assertSame('baz', $model->foo);
        $this->assertSame('xyz', $model->abc);
        $this->assertSame(null, $model->data);

        // Model can be retrieved after update & is structure correctly
        $model = MyModel::first();

        $this->assertSame('baz', $model->foo);
        $this->assertSame('xyz', $model->abc);
        $this->assertSame(null, $model->data);
    }

    /** @test */
    public function model_is_always_decoded_when_accessed_by_user_event()
    {
        MyModel::retrieved(function (MyModel $model) {
            $this->assertSame('decoded', $model->dataEncodingStatus);
        });
        MyModel::saving(function (MyModel $model) {
            $this->assertSame('decoded', $model->dataEncodingStatus);
        });
        MyModel::updating(function (MyModel $model) {
            $this->assertSame('decoded', $model->dataEncodingStatus);
        });
        MyModel::creating(function (MyModel $model) {
            $this->assertSame('decoded', $model->dataEncodingStatus);
        });
        MyModel::saved(function (MyModel $model) {
            $this->assertSame('decoded', $model->dataEncodingStatus);
        });
        MyModel::updated(function (MyModel $model) {
            $this->assertSame('decoded', $model->dataEncodingStatus);
        });
        MyModel::created(function (MyModel $model) {
            $this->assertSame('decoded', $model->dataEncodingStatus);
        });


        $model = MyModel::create(['foo' => 'bar']);
        $model->update(['foo' => 'baz']);
        MyModel::first();
    }

    // maybe add an explicit test that the saving() and updating() listeners don't run twice?
}

class MyModel extends Model
{
    use VirtualColumn;

    protected $guarded = [];
    public $timestamps = false;

    public static function getCustomColumns(): array
    {
        return [
            'id',
            'custom1',
            'custom2',
        ];
    }
}
