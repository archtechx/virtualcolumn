<?php

declare(strict_types=1);

namespace Stancl\VirtualColumn;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;

/**
 * This trait lets you add a "data" column functionality to any Eloquent model.
 * It serializes attributes which don't exist as columns on the model's table
 * into a JSON column named data (customizable by overriding getDataColumn).
 *
 * @mixin \Illuminate\Database\Eloquent\Model
 */
trait VirtualColumn
{
    public static $afterListeners = [];
    public static array $customEncryptedCastables = [];

    /**
     * We need this property, because both created & saved event listeners
     * decode the data (to take precedence before other created & saved)
     * listeners, but we don't want the data to be decoded twice.
     */
    public bool $dataEncoded = false;

    protected static function decodeVirtualColumn(self $model): void
    {
        if (! $model->dataEncoded) {
            return;
        }

        $encryptedCastables = array_merge(
            static::$customEncryptedCastables,
            ['encrypted', 'encrypted:array', 'encrypted:collection', 'encrypted:json', 'encrypted:object'], // Default encrypted castables
        );

        foreach ($model->getAttribute(static::getDataColumn()) ?? [] as $key => $value) {
            $attributeHasEncryptedCastable = in_array(data_get($model->getCasts(), $key), $encryptedCastables);

            if ($attributeHasEncryptedCastable && static::valueEncrypted($value)) {
                $model->attributes[$key] = $value;
            } else {
                $model->setAttribute($key, $value);
            }

            $model->syncOriginalAttribute($key);
        }

        $model->setAttribute(static::getDataColumn(), null);

        $model->dataEncoded = false;
    }

    protected static function encodeAttributes(self $model): void
    {
        if ($model->dataEncoded) {
            return;
        }

        foreach ($model->getAttributes() as $key => $value) {
            if (! in_array($key, static::getCustomColumns())) {
                $current = $model->getAttribute(static::getDataColumn()) ?? [];

                $model->setAttribute(static::getDataColumn(), array_merge($current, [
                    $key => $value,
                ]));

                unset($model->attributes[$key]);
                unset($model->original[$key]);
            }
        }

        $model->dataEncoded = true;
    }

    public static function valueEncrypted(string $value): bool
    {
        try {
            Crypt::decryptString($value);

            return true;
        } catch (DecryptException $e) {
            return false;
        }
    }

    public static function bootVirtualColumn()
    {
        static::registerAfterListener('retrieved', function ($model) {
            // We always decode after model retrieval.
            $model->dataEncoded = true;

            static::decodeVirtualColumn($model);
        });

        // Encode if writing
        static::registerAfterListener('saving', [static::class, 'encodeAttributes']);
        static::registerAfterListener('creating', [static::class, 'encodeAttributes']);
        static::registerAfterListener('updating', [static::class, 'encodeAttributes']);
    }

    protected function decodeIfEncoded()
    {
        if ($this->dataEncoded) {
            static::decodeVirtualColumn($this);
        }
    }

    protected function fireModelEvent($event, $halt = true)
    {
        $this->decodeIfEncoded();

        $result = parent::fireModelEvent($event, $halt);

        $this->runAfterListeners($event, $halt);

        return $result;
    }

    public function runAfterListeners($event, $halt = true)
    {
        $listeners = static::$afterListeners[$event] ?? [];

        if (! $event) {
            return;
        }

        foreach ($listeners as $listener) {
            if (is_string($listener)) {
                $listener = app($listener);
                $handle = [$listener, 'handle'];
            } else {
                $handle = $listener;
            }

            $handle($this);
        }
    }

    public static function registerAfterListener(string $event, callable $callback)
    {
        static::$afterListeners[$event][] = $callback;
    }

    public function getCasts()
    {
        return array_merge(parent::getCasts(), [
            static::getDataColumn() => 'array',
        ]);
    }

    /**
     * Get the name of the column that stores additional data.
     */
    public static function getDataColumn(): string
    {
        return 'data';
    }

    public static function getCustomColumns(): array
    {
        return [
            'id',
        ];
    }

    /**
     * Get a column name for an attribute that can be used in SQL queries.
     *
     * (`foo` or `data->foo` depending on whether `foo` is in custom columns)
     */
    public function getColumnForQuery(string $column): string
    {
        if (in_array($column, static::getCustomColumns(), true)) {
            return $column;
        }

        return static::getDataColumn() . '->' . $column;
    }
}
