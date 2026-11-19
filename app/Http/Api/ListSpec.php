<?php

declare(strict_types=1);

namespace App\Http\Api;

/**
 * What one list endpoint allows: a default sort and an allow-list, the `q`
 * columns, and the filters it understands. `filters` maps the API field to a
 * column, or to [column, type] where type is string (default) | bool | int | date.
 */
final class ListSpec
{
    /**
     * @param  array<string, string>  $sortable  API field => column
     * @param  list<string>  $searchable  columns searched by `q`
     * @param  array<string, string|array{0: string, 1: string}>  $filters
     */
    public function __construct(
        public readonly array $sortable,
        public readonly string $defaultSort,
        public readonly array $searchable = [],
        public readonly array $filters = [],
        public readonly int $defaultPerPage = 15,
        public readonly int $maxPerPage = 100,
    ) {}
}