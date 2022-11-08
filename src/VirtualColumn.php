<?php

declare(strict_types=1);

namespace Stancl\VirtualColumn;

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

    /**
     * We need this property, because both created & saved event listeners
     * decode the data (to take precedence before other created & saved)
     * listeners, but we don't want the data to be decoded twice.
     *
     * @var string
     */
    public $dataEncodingStatus = 'decoded';

    protected static function decodeVirtualColumn(self $model): void
    {
        if ($model->dataEncodingStatus === 'decoded') {
            return;
        }

        foreach ($model->getAttribute(static::getDataColumn()) ?? [] as $key => $value) {
            $model->setAttribute($key, $value);
            $model->syncOriginalAttribute($key);
        }

        $model->setAttribute(static::getDataColumn(), null);

        $model->dataEncodingStatus = 'decoded';
    }

    protected static function encodeAttributes(self $model): void
    {
        if ($model->dataEncodingStatus === 'encoded') {
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

        $model->dataEncodingStatus = 'encoded';
    }

    public static function bootVirtualColumn()
    {
        static::registerAfterListener('retrieved', function ($model) {
            // We always decode after model retrieval.
            $model->dataEncodingStatus = 'encoded';

            static::decodeVirtualColumn($model);
        });

        // Encode if writing
        static::registerAfterListener('saving', [static::class, 'encodeAttributes']);
        static::registerAfterListener('creating', [static::class, 'encodeAttributes']);
        static::registerAfterListener('updating', [static::class, 'encodeAttributes']);
    }

    protected function decodeIfEncoded()
    {
        if ($this->dataEncodingStatus === 'encoded') {
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
