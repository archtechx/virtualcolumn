<?php

namespace Stancl\VirtualColumn\Tests;

use Orchestra\Testbench\TestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Database\Eloquent\Model;
use Stancl\VirtualColumn\VirtualColumn;
use Illuminate\Contracts\Encryption\DecryptException;

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

    // maybe add an explicit test that the saving() and updating() listeners don't run twice?

    /** @test */
    public function encrypted_casts_work_with_virtual_column() {
        $model = MyModel::create([
            'password' => $password = 'foo',
            'array' => $array = ['foo', 'bar'],
            'collection' => $collection = collect(['foo', 'bar']),
            'json' => $json = json_encode(['foo', 'bar']),
            'object' => $object = (object) json_encode(['foo', 'bar']),
            // todo1 'custom' => 'foo',
        ]);

        $attributeGetsCastCorrectly = function (string $attribute, mixed $expectedValue) use ($model): void {
            $savedValue = $model->getAttributes()[$attribute]; // Encrypted

            $this->assertTrue(VirtualColumn::valueEncrypted($savedValue));
            $this->assertNotEquals($savedValue, $expectedValue);

            $retrievedValue = $model->$attribute; // Decrypted

            $this->assertEquals($retrievedValue, $expectedValue);
        };

        $attributeGetsCastCorrectly('password', $password); // 'encrypted'
        $attributeGetsCastCorrectly('array', $array); // 'encrypted:array'
        $attributeGetsCastCorrectly('collection', $collection); // 'encrypted:collection'
        $attributeGetsCastCorrectly('json', $json); // 'encrypted:json'
        $attributeGetsCastCorrectly('object', $object); // 'encrypted:object'
        // todo1 $attributeGetsCastCorrectly('custom', $custom); // 'CustomCast::class'
    }
}

class MyModel extends Model
{
    use VirtualColumn;

    protected $guarded = [];
    public $timestamps = false;
    public $casts = [
        'password' => 'encrypted',
        'array' => 'encrypted:array',
        'collection' => 'encrypted:collection',
        'json' => 'encrypted:json',
        'object' => 'encrypted:object',
        // todo1 'custom' => CustomCast::class,
    ];

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
