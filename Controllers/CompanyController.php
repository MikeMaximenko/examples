<?php

namespace App\Http\Controllers;

use App\Http\Requests\UploadCompanyImage;
use App\Models\Company;
use App\Models\User;
use App\Http\Requests\UpdateCompanyRequest;
use App\Models\CompanyQuestion;
use App\Notifications\CompanyFeedback;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Storage;
use Tenancy\Facades\Tenancy;

class CompanyController extends Controller
{
    /**
     * @return JsonResponse
     * @throws AuthorizationException
     */
    public function view(): JsonResponse
    {
        /** @var Company $company */
        $company = Tenancy::identifyTenant();
        if (!$company) {
            abort(404, 'Domain not found');
        }

        $this->authorize('view', $company);

        $company->load('questions');

        return response()->json([
            'item' => array_merge($company->toArray(), [
                'allowed_mail_variables' => config('app.allowed_mail_variables'),
                'allowed_mail_actions' => config('app.allowed_mail_actions')
            ])
        ]);
    }

    /**
     * @param UpdateCompanyRequest $request
     * @return Response
     * @throws AuthorizationException
     */
    public function update(UpdateCompanyRequest $request): Response
    {
        /** @var Company $company */
        $company = Tenancy::identifyTenant();
        if (!$company) {
            abort(404, 'Domain not found');
        }

        $this->authorize('update', $company);

        $requestJson = optional($request->json())->all();
        $company->fill($requestJson);
        $company->save();

        if (isset($requestJson['questions'])) {
            $company->questions()->delete();
            $company->questions()->createMany($requestJson['questions']);
        }

        return response('', 204);
    }

    /**
     * @param UploadCompanyImage $request
     * @return JsonResponse
     * @throws AuthorizationException
     */
    public function uploadImage(UploadCompanyImage $request): JsonResponse
    {
        /** @var Company $company */
        $company = Tenancy::identifyTenant();
        if (!$company) {
            abort(404, 'Domain not found');
        }

        $this->authorize('update', $company);

        if ($request->file('upload')) {
            $fileName = optional($request->file('upload'))->storePublicly(sha1($company->id), 'public');

            return response()->json([
                'name'=> $fileName,
                'url' => Storage::drive('public')->url($fileName),
            ]);
        }

        $filesBase64 = $request->toArray();
        $res = [];
        foreach ($filesBase64 as $pageName => $keysOfSections) {
            foreach ($keysOfSections as $key => $SectionfileBase64) {
                foreach($SectionfileBase64 as $sectionId => $fileBase64) {
                    $file = base64_decode(explode( ',', $fileBase64 )[1]);
                    $fileName = sha1($company->id . $pageName . $sectionId);
                    $fileData = Storage::disk('public')->put($fileName . '.png', $file);
                    $res[$pageName][$sectionId] = ['fileName' => $fileName, 'url' => Storage::drive('public')->url($fileName . '.png')];
                }
            }
        }

        return response()->json(collect($res));
    }

    public function current(): JsonResponse
    {
        /** @var Company $company */
        $company = Tenancy::identifyTenant();

        if (!$company) {
            abort(404, 'Domain not found');
        }

        return response()->json([
            'item' => array_filter(optional($company)->toArray(), static function ($el) {
                return in_array($el, [
                    'id',
                    'logo',
                    'general',
                    'home_page',
                    'about_page',
                    'contact_page',
                    'available_payment_methods',
                    'general',
                    'payment',
                    'short_feedbacks',
                    'payout_1star',
                    'payout_2star',
                    'payout_3star',
                    'payout_4star',
                    'payout_5star',
                ], true);
            }, ARRAY_FILTER_USE_KEY)
        ]);
    }

    public function questions(): JsonResponse
    {
        $company = Tenancy::identifyTenant();

        if (!$company) {
            abort(404, 'Domain not found');
        }

        $items = [];
        foreach ($company->questions as $question) {
            /** @var CompanyQuestion $question */
            $items[] = $question->simplify();
        }

        return response()->json(['items' => $items]);
    }

    public function sendCompanyFeedback(UpdateCompanyRequest $request): JsonResponse
    {
        /** @var Company $company */
        $company = Tenancy::identifyTenant();

        if (!$company) {
            abort(404, 'Domain not found');
        }
        
        $name = $request['name'] ?? '';
        $email = $request['email'] ?? '';
        $message = $request['message'] ?? '';

        try {
            /** @var User $admin */
            $admin = User::where('id', 1)->first();
            $admin->notify(new CompanyFeedback($name, $email, $message));
            
        } catch (Exception $e) {

            return response()->json(['success' => false, 'error' => $e->getMessage()]);
        }

        return response()->json(['success' => true]);
    }
}
