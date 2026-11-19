<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Api\ApiResponse;
use App\Http\Api\ListQuery;
use App\Http\Api\ListSpec;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\UserResource;
use App\Models\User;
use App\Tenancy\CurrentFirm;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class UserController extends Controller
{
    public function __construct(private readonly CurrentFirm $currentFirm) {}

    /**
     * GET /users: the current firm's members. Open to every signed-in user
     * (the responsible-advocate dropdown reads
     * `?filter[is_advocate]=true&per_page=100&sort=name`).
     */
    public function index(Request $request): JsonResponse
    {
        $firm = $this->currentFirm->get();

        abort_if($firm === null, 403, 'You are not a member of a firm yet.');

        $query = User::query()
            ->join('firm_user', 'firm_user.user_id', '=', 'users.id')
            ->where('firm_user.firm_id', $firm->id)
            ->select('users.*', 'firm_user.role as membership_role');

        $spec = new ListSpec(
            sortable: ['name' => 'users.name', 'email' => 'users.email', 'created_at' => 'users.created_at'],
            defaultSort: 'name',
            searchable: ['users.name', 'users.email'],
            filters: ['is_advocate' => ['users.is_advocate', 'bool'], 'active' => ['users.active', 'bool']],
        );

        return ApiResponse::paginated(ListQuery::paginate($query, $request, $spec), UserResource::class);
    }
}