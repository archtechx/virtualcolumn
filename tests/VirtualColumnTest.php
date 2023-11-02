<?php

namespace Stancl\VirtualColumn\Tests;

use Exception;
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
        $this->assertSame('bar', $model->getOriginal('foo'));
        $this->assertSame(null, $model->data);

        // Model can be updated
        $model->update([
            'foo' => 'baz',
            'abc' => 'xyz',
        ]);

        $this->assertSame('baz', $model->foo);
        $this->assertSame('baz', $model->getOriginal('foo'));
        $this->assertSame('xyz', $model->abc);
        $this->assertSame('xyz', $model->getOriginal('abc'));
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

    /** @test */
    public function column_names_are_generated_correctly()
    {
        // FooModel's virtual data column name is 'virtual'
        $virtualColumnName = 'virtual->foo';
        $customColumnName = 'custom1';

        /** @var FooModel $model */
        $model = FooModel::create([
            'custom1' => $customColumnName,
            'foo' => $virtualColumnName
        ]);

        $this->assertSame($customColumnName, $model->getColumnForQuery('custom1'));
        $this->assertSame($virtualColumnName, $model->getColumnForQuery('foo'));
    }

    /** @test */
    public function models_extending_a_parent_using_virtualcolumn_get_encoded_incorrectly()
    {
        // todo1 Fix this unintended behavior

        // Create a model that extends a parent model using VirtualColumn
        // 'foo' is a custom column, 'data' is the virtual column
        FooChild::create(['foo' => 'foo']);
        $encodedFoo = DB::select('select * from foo_childs limit 1')[0];
        // Assert that the model was encoded correctly
        $this->assertNull($encodedFoo->data);
        $this->assertSame($encodedFoo->foo, 'foo');

        // Creating another child model of the same parent doesn't encode the attributes correctly
        // 'bar' is a custom column, 'data' is the virtual column
        BarChild::create(['bar' => 'bar']);
        $encodedBar = DB::select('select * from bar_childs limit 1')[0];

        /*
         * Each child model gets encoded using the first child model's encoding listener.
         * The encodeAttributes event listeners get registered for each child model
         * in $afterListeners – a static property, so the state is shared between all child models.
         *
         * The runAfterListeners method runs all listeners for the registered event,
         * including the listener for encoding the first child model before attempting to encode the second child.
         *
         * However, after encoding the second child model's attributes using the first listener,
         * $dataEncodingStatus changes to 'encoded', meaning the next listener (the one intended for the second child)
         * won't encode the attributes.
         *
         * That results in the second child model being encoded using the first child model's custom columns,
         * and the second child model's custom columns won't be recognized as "real"/custom columns.
         *
         * The intended behavior would be
         * $this->assertNull($encodedBar->data);
         * $this->assertSame($encodedBar->bar, 'bar');
         */

        // Assert that the second child model was encoded incorrectly
        $this->assertNotNull($encodedBar->data);
        $this->assertNull($encodedBar->bar);
        $this->assertSame($encodedBar->data, json_encode(['bar' => 'bar']));
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

class FooModel extends Model
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

    public static function getDataColumn(): string
    {
        return 'virtual';
    }
}

class ParentModel extends Model
{
    use VirtualColumn;

    public $timestamps = false;
    protected $guarded = [];
}


class FooChild extends ParentModel
{
    public $table = 'foo_childs';

    public static function getCustomColumns(): array
    {
        return [
            'id',
            'foo',
        ];
    }
}
class BarChild extends ParentModel
{
    public $table = 'bar_childs';

    public static function getCustomColumns(): array
    {
        return [
            'id',
            'bar',
        ];
    }
}
