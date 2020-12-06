<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UserListRequest;
use App\Http\Requests\LinkAmazonRequest;
use App\Models\Company;
use App\Models\User;
use App\Services\ConvomatService;
use \Illuminate\Database\Eloquent\Builder;
use \Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;

abstract class AbstractUserController extends Controller
{
    protected $filters;

    protected $filtersSoft;

    public function list(UserListRequest $request): JsonResponse
    {
        $itemsPerPage = (int) $request->input('per_page', 10);
        $page = (int) $request->input('page', 1) - 1;
        $sort = $request->input('sort', 'id');
        $sortDir = $request->input('sort_dir', 'desc');
        $filters = $request->input('filters', []);
        $search = $request->input('search', '');

        $query = $this->createQuery();

        $query = $this->addFilters($query, $filters);
        $query = $this->addSearch($query, $search);
        $query = $this->addOrder($query, $sort, $sortDir);
        $count = $query->count();
        $items = $this->getItems($query, $itemsPerPage, $page);

        return response()->json([
            'items' => $items,
            'total_count' => $count,
        ]);
    }

    public function linkAmazon(LinkAmazonRequest $request) {
        $input = $request->validated();
        $amazonUrl = $input['amazon_profile_url'];
        /** @var User $user */
        $user = auth()->user();
        $convomatService = new ConvomatService();
        $response = $convomatService->getAmazonProfileByUrl($amazonUrl);

        if (!empty($response['user_id'])) {
            $user->amazon_id = $response['user_id'];
            $user->save();

            return response()->json(['item' => $user]);
        } else {
            return response('Amazon profile not found.', 400);
        }
    }

    abstract protected function createQuery(): Builder;

    protected function addFilters(Builder $query, $filters): Builder
    {
        if (!empty($filters)) {
            $filters = json_decode($filters, true, 512);

            foreach ($filters as $filter) {
                $value = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $filter['value']);

                if (in_array($filter['key'], $this->filtersSoft)) {
                    $query = $query->where($filter['key'], 'like', "%$value%");
                }

                if (in_array($filter['key'], $this->filters)) {
                    $query = $query->where($filter['key'], 'like', "$value");
                }
            }
        }

        return $query;
    }

    protected function addSearch(Builder $query, $search): Builder
    {
        if (!empty($search)) {
            $search = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search);
            $query = $query->where(static function ($query) use ($search) {
                $query
                    ->where('name', 'like', "%$search%")
                    ->orWhere('convomat_user_id', 'like', "%$search%");
            });
        }

        return $query;
    }

    protected function addOrder(Builder $query, $sort, string $sortDir): Builder
    {
        if (empty($sort)) {
            $sort = 'id';
        }

        return $query->orderBy($sort, $sortDir);
    }

    protected function getItems(Builder $query, int $itemsPerPage, int $page): Collection
    {
        return $query->skip($itemsPerPage * $page)
            ->take($itemsPerPage)
            ->select(['users.*'])
            ->get();
    }

    protected function checkTenancy() {
        /** @var Company $company */
        $company = \Tenancy::identifyTenant();

        if (!$company) {
            abort(404, 'Domain not found');
        }
    }
}
