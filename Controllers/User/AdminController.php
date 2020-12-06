<?php

namespace App\Http\Controllers\User;

use App\Http\Requests\Admin\CreateAdminRequest;
use App\Http\Requests\Admin\UpdateUserRequest;
use App\Models\Company;
use App\Models\User;
use App\Notifications\AdminCreated;
use App\Notifications\ChangePassword;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Str;

class AdminController extends AbstractUserController
{
    protected $filters = ['id'];

    protected $filtersSoft = ['name', 'convomat_user_id', 'phone_number', 'email', 'domain'];

    public function view(User $user): JsonResponse
    {
        return response()->json(['item' => $user]);
    }

    public function update(UpdateUserRequest $request, User $user): JsonResponse
    {
        $input = $request->validated();
        $user->fill($input);
        $user->save();

        $item = User::where('users.id', $user->id)
            ->join('companies', 'users.company_id', '=', 'companies.id')
            ->select('users.*', 'companies.domain')
            ->first();

        return response()->json(['item' => $item]);
    }

    public function resetPassword(User $user): Response
    {
        $password = Str::random(20);

        $user->password = $password;
        $user->save();

        $user->notify(new ChangePassword($password));

        return response('', 201);
    }

    public function create(CreateAdminRequest $request): Response
    {
        $this->checkTenancy();
        $input = $request->validated();
        $existingUser = User::where('email', $input['email'])->first();

        if (isset($existingUser)) {
            abort(409, "User {$input['email']} already exists");
        }

        $company = Company::create([
            'payment' => [],
        ]);

        $user = new User($input);
        $user->password = $input['password'];
        $user->is_active = true;
        $user->is_admin = true;
        $user->company_id = $company->id;
        $user->save();

        $user->notify(new AdminCreated($input['password']));

        return response('', 201);
    }

    /**
     * @param User $user
     * @return Response
     * @throws \Exception
     */
    public function delete(User $user): Response
    {
        $user->delete();

        return response('', 201);
    }

    protected function createQuery(): Builder
    {
        return User::query()
            ->join('companies', 'users.company_id', '=', 'companies.id')
            ->where('is_admin', true);
    }

    protected function getItems(Builder $query, int $itemsPerPage, int $page): Collection
    {
        return $query->skip($itemsPerPage * $page)
            ->skip($itemsPerPage * $page)
            ->take($itemsPerPage)
            ->select(['users.*', 'companies.domain'])
            ->get();
    }
}
