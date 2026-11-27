<?php

declare(strict_types=1);

namespace App\Http\Api;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * Applies the contract's list parameters to an Eloquent query:
 *   page, per_page (default 15, max 100), sort (field, -field, comma list),
 *   q (case-insensitive contains over explicit columns),
 *   filter[field]=a,b (any of), filter[field][gte|lte|gt|lt]=value (ranges).
 * Unknown sort fields fall back to the default; unknown filters are ignored.
 */
final class ListQuery
{
    private const OPERATORS = ['gte' => '>=', 'lte' => '<=', 'gt' => '>', 'lt' => '<'];

    /**
     * @param  Builder<Model>  $query
     * @return LengthAwarePaginator<int, Model>
     */
    public static function paginate(Builder $query, Request $request, ListSpec $spec): LengthAwarePaginator
    {
        self::search($query, (string) $request->query('q', ''), $spec);
        self::filter($query, $request->query('filter'), $spec);
        self::sort($query, (string) $request->query('sort', ''), $spec);

        $perPage = max(1, min($spec->maxPerPage, (int) $request->query('per_page', $spec->defaultPerPage)));

        return $query->paginate($perPage)->withQueryString();
    }

    /** @param  Builder<Model>  $query */
    private static function search(Builder $query, string $term, ListSpec $spec): void
    {
        $term = trim($term);

        if ($term === '' || ($spec->searchable === [] && $spec->extraSearch === null)) {
            return;
        }

        $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], mb_strtolower($term)).'%';

        $grammar = $query->getQuery()->getGrammar();

        $query->where(function (Builder $q) use ($spec, $like, $grammar): void {
            foreach ($spec->searchable as $column) {
                $q->orWhereRaw('LOWER('.$grammar->wrap($column).") LIKE ? ESCAPE '\\'", [$like]);
            }
            if ($spec->extraSearch !== null) {
                $q->orWhere(fn (Builder $inner) => ($spec->extraSearch)($inner, $like));
            }
        });
    }

    /** @param  Builder<Model>  $query */
    private static function filter(Builder $query, mixed $filters, ListSpec $spec): void
    {
        if (! is_array($filters)) {
            return;
        }

        foreach ($filters as $field => $value) {
            if (! isset($spec->filters[$field])) {
                continue;
            }

            [$column, $type] = is_array($spec->filters[$field])
                ? $spec->filters[$field]
                : [$spec->filters[$field], 'string'];

            if (is_array($value)) {
                foreach ($value as $operator => $operand) {
                    if (isset(self::OPERATORS[$operator]) && is_scalar($operand) && $operand !== '') {
                        $query->where($column, self::OPERATORS[$operator], self::cast((string) $operand, $type));
                    }
                }

                continue;
            }

            if (! is_scalar($value) || $value === '') {
                continue;
            }

            $values = array_map(fn (string $v) => self::cast($v, $type), explode(',', (string) $value));
            $query->whereIn($column, $values);
        }
    }

    /** @param  Builder<Model>  $query */
    private static function sort(Builder $query, string $sort, ListSpec $spec): void
    {
        $applied = 0;

        foreach (array_filter(array_map('trim', explode(',', $sort))) as $token) {
            $descending = str_starts_with($token, '-');
            $field = ltrim($token, '-');

            if (isset($spec->sortable[$field])) {
                $query->orderBy($spec->sortable[$field], $descending ? 'desc' : 'asc');
                $applied++;
            }
        }

        if ($applied === 0) {
            $descending = str_starts_with($spec->defaultSort, '-');
            $field = ltrim($spec->defaultSort, '-');
            $query->orderBy($spec->sortable[$field] ?? $field, $descending ? 'desc' : 'asc');
        }

        // A stable tie-breaker so pages never overlap or skip rows.
        $query->orderBy($query->getModel()->getQualifiedKeyName());
    }

    private static function cast(string $value, string $type): string|int|bool
    {
        return match ($type) {
            'bool' => in_array(strtolower($value), ['1', 'true', 'yes', 'on'], true),
            'int' => (int) $value,
            default => $value,
        };
    }
}