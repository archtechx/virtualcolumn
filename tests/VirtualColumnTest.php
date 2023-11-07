<?php

namespace Stancl\VirtualColumn\Tests;

use Orchestra\Testbench\TestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Database\Eloquent\Model;
use Stancl\VirtualColumn\VirtualColumn;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;

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

        // Model can be retrieved after update & is structured correctly
        $model = MyModel::first();

        $this->assertSame('baz', $model->foo);
        $this->assertSame('xyz', $model->abc);
        $this->assertSame(null, $model->data);
    }

    /** @test */
    public function model_is_always_decoded_when_accessed_by_user_event()
    {
        MyModel::retrieved(function (MyModel $model) {
            $this->assertFalse($model->dataEncoded);
        });
        MyModel::saving(function (MyModel $model) {
            $this->assertFalse($model->dataEncoded);
        });
        MyModel::updating(function (MyModel $model) {
            $this->assertFalse($model->dataEncoded);
        });
        MyModel::creating(function (MyModel $model) {
            $this->assertFalse($model->dataEncoded);
        });
        MyModel::saved(function (MyModel $model) {
            $this->assertFalse($model->dataEncoded);
        });
        MyModel::updated(function (MyModel $model) {
            $this->assertFalse($model->dataEncoded);
        });
        MyModel::created(function (MyModel $model) {
            $this->assertFalse($model->dataEncoded);
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
    public function models_extending_a_parent_model_using_virtualcolumn_get_encoded_correctly()
    {
        // Create a model that extends a parent model using VirtualColumn
        // 'foo' is a custom column, 'data' is the virtual column
        FooChild::create(['foo' => 'foo']);
        $encodedFoo = DB::select('select * from foo_childs limit 1')[0];
        // Assert that the model was encoded correctly
        $this->assertNull($encodedFoo->data);
        $this->assertSame($encodedFoo->foo, 'foo');

        // Create another child model of the same parent
        // 'bar' is a custom column, 'data' is the virtual column
        BarChild::create(['bar' => 'bar']);
        $encodedBar = DB::select('select * from bar_childs limit 1')[0];

        $this->assertNull($encodedBar->data);
        $this->assertSame($encodedBar->bar, 'bar');
    }

    // maybe add an explicit test that the saving() and updating() listeners don't run twice?

    /** @test */
    public function encrypted_casts_work_with_virtual_column() {
        // Custom encrypted castables have to be specified in the $customEncryptedCastables static property
        MyModel::$customEncryptedCastables = [EncryptedCast::class];

        /** @var MyModel $model */
        $model = MyModel::create($encryptedAttributes = [
            'password' => 'foo', // 'encrypted'
            'array' => ['foo', 'bar'], // 'encrypted:array'
            'collection' => collect(['foo', 'bar']), // 'encrypted:collection'
            'json' => json_encode(['foo', 'bar']), // 'encrypted:json'
            'object' => (object) json_encode(['foo', 'bar']), // 'encrypted:object'
            'custom' => 'foo', // Custom castable – 'EncryptedCast::class'
        ]);

        foreach($encryptedAttributes as $key => $expectedValue) {
            $savedValue = $model->getAttributes()[$key]; // Encrypted

            $this->assertTrue($model->valueEncrypted($savedValue));
            $this->assertNotEquals($expectedValue, $savedValue);

            $retrievedValue = $model->$key; // Decrypted

            $this->assertEquals($expectedValue, $retrievedValue);
        }

        // Reset static property
        MyModel::$customEncryptedCastables = [];
    }
}

class ParentModel extends Model
{
    use VirtualColumn;

    protected $guarded = [];
    public $timestamps = false;

    public function getCustomColumns(): array
    {
        return [
            'id',
            'custom1',
            'custom2',
        ];
    }
}

class MyModel extends ParentModel
{
    public $casts = [
        'password' => 'encrypted',
        'array' => 'encrypted:array',
        'collection' => 'encrypted:collection',
        'json' => 'encrypted:json',
        'object' => 'encrypted:object',
        'custom' => EncryptedCast::class,
    ];
}

class FooModel extends ParentModel
{
    public function getDataColumn(): string
    {
        return 'virtual';
    }
}

class EncryptedCast implements CastsAttributes
{
    public function get($model, $key, $value, $attributes)
    {
        return Crypt::decryptString($value);
    }

    public function set($model, $key, $value, $attributes)
    {
        return Crypt::encryptString($value);
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
