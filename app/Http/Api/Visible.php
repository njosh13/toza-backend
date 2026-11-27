<?php

declare(strict_types=1);

namespace App\Http\Api;

use App\Models\User;
use App\Tenancy\CurrentFirm;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * The two controller-side halves of the ownership scope:
 *  - scopedList(): what a list endpoint starts from (visibility + advocate_id);
 *  - find():       one record by id, or 404 when it does not exist OR is not
 *                  visible to this user (existence is never leaked).
 */
final class Visible
{
    /**
     * One record of the current firm by id, for models with no ownership scope
     * (clients): 404 when it does not exist, is malformed, or is another firm's.
     *
     * @template TModel of Model
     *
     * @param  class-string<TModel>  $modelClass
     * @return TModel
     */
    public static function scopedList(string $modelClass, Request $request, User $user): Builder
    {
        /** @var Builder<TModel> $query */
        $query = $modelClass::query();
        $query->visibleTo($user);

        // `advocate_id` only narrows for roles that see everything. For an
        // associate visibleTo() already pins the list to themselves.
        if (! self::seesOnlyOwn($user)) {
            self::narrowToAdvocate($query, $request);
        }

        return $query;
    }

    /**
     * @template TModel of Model
     *
     * @param  class-string<TModel>  $modelClass
     * @param  list<string>  $with
     * @return TModel
     */
    public static function find(string $modelClass, string $id, User $user, array $with = []): Model
    {
        // A malformed id can never match; Postgres would raise on a bad uuid.
        if (in_array(HasUuids::class, class_uses_recursive($modelClass), true) && ! Str::isUuid($id)) {
            abort(404, 'Not found.');
        }

        return $modelClass::query()
            ->visibleTo($user)
            ->with($with)
            ->whereKey($id)
            ->firstOr(fn () => abort(404, 'Not found.'));
    }

    /** The advocate named by `filter[advocate_id]` (lists) or `advocate_id` (dashboard), if any. */
    public static function advocateParameter(Request $request): ?string
    {
        $filter = $request->query('filter');
        $value = is_array($filter) ? ($filter['advocate_id'] ?? null) : null;
        $value ??= $request->query('advocate_id');

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * @param  Builder<Model>  $query
     */
    private static function narrowToAdvocate(Builder $query, Request $request): void
    {
        $publicId = self::advocateParameter($request);

        if ($publicId === null) {
            return;
        }

        $advocateId = self::memberId($publicId);

        // An unknown advocate (or one from another firm) matches nothing.
        $advocateId === null ? $query->whereRaw('1 = 0') : $query->ownWork($advocateId);
    }

    /** Integer id of the current firm's member with this public id. */
    private static function memberId(string $publicId): ?int
    {
        $firm = app(CurrentFirm::class)->get();

        if ($firm === null) {
            return null;
        }

        $id = User::query()
            ->join('firm_user', 'firm_user.user_id', '=', 'users.id')
            ->where('firm_user.firm_id', $firm->id)
            ->where('users.public_id', $publicId)
            ->value('users.id');

        return $id === null ? null : (int) $id;
    }

    private static function seesOnlyOwn(User $user): bool
    {
        $firm = app(CurrentFirm::class)->get();

        return ($firm === null ? null : $user->roleIn($firm))?->seesOnlyOwnWork() ?? true;
    }

    public static function open(string $modelClass, string $id): Model
    {
        if (! Str::isUuid($id)) {
            abort(404, 'Not found.');
        }

        return $modelClass::query()->whereKey($id)->firstOr(fn () => abort(404, 'Not found.'));
    }

    /** The integer id of the current firm's member with this public id (null when there is none). */
    public static function memberIdFor(string $publicId): ?int
    {
        return self::memberId($publicId);
    }
}