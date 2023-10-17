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
    public static array $customEncryptedCastables = [];

    /**
     * We need this property, because both created & saved event listeners
     * decode the data (to take precedence before other created & saved)
     * listeners, but we don't want the data to be decoded twice.
     */
    public bool $dataEncoded = false;

    protected function decodeVirtualColumn(self $model): void
    {
        if (! $model->dataEncoded) {
            return;
        }

        $encryptedCastables = array_merge(
            static::$customEncryptedCastables,
            ['encrypted', 'encrypted:array', 'encrypted:collection', 'encrypted:json', 'encrypted:object'], // Default encrypted castables
        );

        foreach ($model->getAttribute($this->getDataColumn()) ?? [] as $key => $value) {
            $attributeHasEncryptedCastable = in_array(data_get($model->getCasts(), $key), $encryptedCastables);

            if ($attributeHasEncryptedCastable && $this->valueEncrypted($value)) {
                $model->attributes[$key] = $value;
            } else {
                $model->setAttribute($key, $value);
            }

            $model->syncOriginalAttribute($key);
        }

        $model->setAttribute($this->getDataColumn(), null);

        $model->dataEncoded = false;
    }

    protected function encodeAttributes(self $model): void
    {
        if ($model->dataEncoded) {
            return;
        }

        $dataColumn = $this->getDataColumn();
        $customColumns = $this->getCustomColumns();
        $attributes = array_filter($model->getAttributes(), fn ($key) => ! in_array($key, $customColumns), ARRAY_FILTER_USE_KEY);

        // Remove data column from the attributes
        unset($attributes[$dataColumn]);

        foreach ($attributes as $key => $value) {
            // Remove attribute from the model
            unset($model->attributes[$key]);
            unset($model->original[$key]);
        }

        // Add attribute to the data column
        $model->setAttribute($dataColumn, $attributes);

        $model->dataEncoded = true;
    }

    public function valueEncrypted(string $value): bool
    {
        try {
            Crypt::decryptString($value);

            return true;
        } catch (DecryptException) {
            return false;
        }
    }

    protected function decodeAttributes(self $model)
    {
        $model->dataEncoded = true;

        $this->decodeVirtualColumn($model);
    }

    protected function getAfterListeners(): array
    {
        return [
            'retrieved' => [
                function ($model) {
                    // Always decode after model retrieval
                    $model->dataEncoded = true;

                    $this->decodeVirtualColumn($model);
                },
            ],
            'saving' => [
                [$this, 'encodeAttributes'],
            ],
            'creating' => [
                [$this, 'encodeAttributes'],
            ],
            'updating' => [
                [$this, 'encodeAttributes'],
            ],
        ];
    }

    protected function decodeIfEncoded()
    {
        if ($this->dataEncoded) {
            $this->decodeVirtualColumn($this);
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
        $listeners = $this->getAfterListeners()[$event] ?? [];

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

    public function getCasts()
    {
        return array_merge(parent::getCasts(), [
            $this->getDataColumn() => 'array',
        ]);
    }

    /**
     * Get the name of the column that stores additional data.
     */
    public function getDataColumn(): string
    {
        return 'data';
    }

    public function getCustomColumns(): array
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
        if (in_array($column, $this->getCustomColumns(), true)) {
            return $column;
        }

        return $this->getDataColumn() . '->' . $column;
    }
}
