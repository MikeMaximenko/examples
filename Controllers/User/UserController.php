<?php

namespace App\Http\Controllers\User;

use App\Http\Requests\Admin\CreateUserRequest;
use App\Http\Requests\Admin\UpdateUserRequest;
use App\Http\Requests\RegisterRequest;
use App\Models\Company;
use App\Models\CompanyQuestion;
use App\Models\User;
use App\Notifications\ChangePassword;
use App\Notifications\CustomNotificationFromTemplate;
use Hash;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Str;
use Tenancy\Facades\Tenancy;

class UserController extends AbstractUserController
{
    protected $filters = ['id', 'is_active'];

    protected $filtersSoft = ['name', 'phone_number', 'email'];

    public function current() {
        return auth()->user();
    }

    public function view(User $user): JsonResponse
    {
        if ($this->checkPermissions($user)) {
            return response()->json(['code' => 403, 'message' => 'Access denied.']);
        }

        $user->mapQuestions = $user->questionAnswers()->getResults()->map(function ($questionAnswer) {
            return [
                'question' => $questionAnswer->question ? $questionAnswer->question->question : null,
                'answer' => $questionAnswer->answer,
                ];
        });

        $user->product_purchased = $user->orders_count;
        $user->count_feedbacks = $user->orders()->where('is_done', true)->count();

        return response()->json(['item' => $user]);
    }

    public function update(UpdateUserRequest $request, User $user): JsonResponse
    {
        if ($this->checkPermissions($user)) {
            return response()->json(['code' => 403, 'message' => 'Access denied.']);
        }

        $input = $request->validated();
        $user->fill($input);
        $user->save();

        $item = User::where('users.id', $user->id)->first();

        return response()->json(['item' => $item]);
    }

    public function approveUser(User $user) {
        if ($this->checkPermissions($user)) {
            return response()->json(['code' => 403, 'message' => 'Access denied.']);
        }

        if (!$user->is_active && !$user->is_banned) {
            $user->is_active = true;
            $user->save();
            $user->notify(new CustomNotificationFromTemplate(
                $user->company,
                $user,
                'approved_user_questionnaire'));
        }

        return response()->json(['item' => $user]);
    }

    public function register(RegisterRequest $request): JsonResponse
    {
        $this->checkTenancy();
        $requestJson = optional($request->json())->all();
        $additionalParams = [
            'is_active' => false,
            'is_admin' => false,
            'is_super_admin' => false,
        ];

        $password = Str::random(10);

        $user = new User;
        $user->fill(array_merge($requestJson, $additionalParams));
        $user->company_id = Tenancy::identifyTenant()->id;
        $user->password = Hash::make($password);
        $userQualified = true;
        $user->save();

        foreach ($requestJson['answers'] as $answer) {
            /** @var CompanyQuestion $question */
            $question = CompanyQuestion::withTrashed()->findOrFail($answer['question_id']);
            $answerCorrect = $question->isCorrectAnswer($answer['answer']);

            if (!$answerCorrect) {
                $userQualified = false;
            }

            $user->questionAnswers()->create([
                'question_id' => $question->id,
                'answer' => $answer['answer'],
                'is_correct' => $answerCorrect,
            ]);
        }

        /** @var Company $company */
        $company = Tenancy::identifyTenant();

        if ($userQualified) {
            $user->is_active = true;
            $user->save();
            $user->notify(new CustomNotificationFromTemplate($company, $user, 'welcome_qualified_user'));
        } else {
            $user->notify(new CustomNotificationFromTemplate($company, $user, 'welcome_non_qualified_user'));
        }

        return response()->json(['item' => $user->toArray()]);
    }

    public function create(CreateUserRequest $request): Response
    {
        $this->checkTenancy();
        $input = $request->validated();
        $existingUser = User::where('email', $input['email'])->first();

        if (isset($existingUser)) {
            abort(409, "User {$input['email']} already exists");
        }

        /** @var Company $company */
        $company = Tenancy::identifyTenant();

        $user = new User($input);
        $user->password = $input['password'];
        $user->is_active = true;
        $user->is_admin = false;
        $user->company_id = $company->id;
        $user->save();

        $user->notify(
            new CustomNotificationFromTemplate(
                $company,
                $user,
                'email_welcome_new_customer_created_by_admin')
        );

        return response('', 201);
    }

    /**
     * @param User $user
     * @return Response
     * @throws \Exception
     */
    public function delete(User $user): Response
    {
        if (!$user->is_active) {
            $user->notify(
                new CustomNotificationFromTemplate(
                    $user->company,
                    $user,
                    'declined_user_questionnaire'));
        }

        $user->delete();

        return response('', 201);
    }

    public function handleBanUser(User $user) {
        if ($this->checkPermissions($user)) {
            return response()->json(['code' => 403, 'message' => 'Access denied.']);
        }

        $user->is_banned = !$user->is_banned;
        $user->save();

        return response()->json(['item' => $user]);
    }

    public function resetPassword(User $user): Response
    {
        if ($this->checkPermissions($user)) {
            return response('Access denied', 403);
        }

        $password = Str::random(20);

        $user->password = Hash::make($password);
        $user->save();

        $user->notify(new ChangePassword($password));

        return response('', 201);
    }

    protected function createQuery(): Builder
    {
        return User::query()->where('is_admin', false)->where('company_id', \Auth::user()->company_id);
    }

    protected function getItems(Builder $query, int $itemsPerPage, int $page): Collection
    {
        return $query->skip($itemsPerPage * $page)
            ->skip($itemsPerPage * $page)
            ->take($itemsPerPage)
            ->select(['users.*'])
            ->get();
    }

    protected function checkPermissions(User $user) {
        return $user->is_admin || $user->is_super_admin || $user->company_id !== \Auth::user()->company_id;
    }
}
