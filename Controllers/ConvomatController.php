<?php

namespace App\Http\Controllers;

use App\Exceptions\ConvomatException;
use App\Http\Requests\PostFeedbackRequest;
use App\Http\Requests\PostReviewRequest;
use App\Http\Requests\SendPayoutRequest;
use App\Http\Requests\VerifyOrderRequest;
use App\Http\Resources\Order as OrderResource;
use App\Http\Resources\OrderCollection;
use App\Models\Company;
use App\Models\Order;
use App\Models\User;
use App\Services\ConvomatService;
use Auth;
use Illuminate\Http\JsonResponse;
use Tenancy\Facades\Tenancy;
use Illuminate\Http\Request;

class ConvomatController extends Controller
{
    /**
     * @var ConvomatService
     */
    private $_convomatService;

    /**
     * ConvomatController constructor.
     * @param ConvomatService $convomatService
     */
    public function __construct(ConvomatService $convomatService)
    {
        $this->_convomatService = $convomatService;
    }

    public function getCampaign($id): JsonResponse
    {
        return response()->json(['item' => $this->_convomatService->getCampaign($id)]);
    }

    public function getCampaigns(): JsonResponse
    {
        /** @var Company $company */
        $company = Tenancy::identifyTenant();

        $goods = $this->_convomatService->getCampaigns(
            $company->api_mode,
            null,
            true,
            null,
            'giveaway',
            '',
            '',
            '',
            $company->is_visible_limit ? $company->products_to_display : 10
        );

        if ($company->exclude_brands && $goods) {
            foreach ($goods as $key => $good) {
                if (!empty($good['asin_data']['brand'])) {
                    $brand = mb_strtolower($good['asin_data']['brand']);

                    foreach ($company->exclude_brands as $exclude) {
                        $exclude = mb_strtolower($exclude);

                        if (strpos($exclude, $brand) !== false) {
                            unset($goods[$key]);
                        }
                    }
                }
            }
        }

        return response()->json(['items' => $goods]);
    }

    public function verifyOrder(VerifyOrderRequest $request): OrderResource
    {
        $input = $request->validated();
        $campaignId = (int) $input['campaign_id'];
        $orderId = $input['order_id'];

        $company = Tenancy::identifyTenant();
        if (!isset($company)) {
            abort(404, 'Company not found');
        }

        $customerId = Auth::id();
        $customer = User::findOrFail($customerId);

        try {
            $orderData = $this->_convomatService->getOrder($campaignId, $orderId, $customer->email);
        } catch (ConvomatException $e) {
            if ($e->getMessage() === 'Invalid Order ID' || $e->getMessage() === 'Incorrect order details found.') {
                abort(400, 'Invalid Order ID');
            } else {
                throw $e;
            }
        }

        $campaign = $this->getCampaign($campaignId);
        $product = $campaign->getData()->item;

        $order = Order::updateOrCreate([
            'campaign_id' => $campaignId,
            'order_id' => $orderData['order_id'],
        ], [
            'status' => $orderData['order_status'],
            'asin_id' => $orderData['order_items'][0]['ASIN'],
            'company_id' => $company->id,
            'user_id' => $customerId,
            'product_name' => $product->campaign_name,
            'product_image' => $product->asin_data->image_url
        ]);

        return new OrderResource($order);
    }

    public function tasks(Request $request): OrderCollection
    {
        $customerId = Auth::id();
        $query = Order::where([
            'user_id' => $customerId,
        ]);

        $params = $request->toArray();

        if (!empty($params['sort'] && $params['sort'] == 'asc')) {
            $query->orderBy('id');
        } else {
            $query->orderBy('id', 'desc');
        }

        $orders = $query->get();

        return new OrderCollection($orders);
    }

    public function list(): OrderCollection
    {
        $customerId = Auth::id();
        $orders = Order::where([
            'user_id' => $customerId,
            'has_review' => false,
            'is_done' => false,
        ])->get();

        return new OrderCollection($orders);
    }

    /**
     * @param $campaignId
     * @return OrderResource|void
     */
    public function getOrder($campaignId)
    {
        $customerId = Auth::id();
        $order = Order::where([
            'user_id' => $customerId,
            'campaign_id' => $campaignId,
            'has_review' => false,
            'is_done' => false,
        ])->first();

        if (empty($order)) {
            return;
        }

        return new OrderResource($order);
    }

    public function show($orderId): OrderResource
    {
        $customerId = Auth::id();
        $order = Order::where([
            'order_id' => $orderId,
            'user_id' => $customerId,
            'has_review' => false,
            'is_done' => false,
        ])->firstOrFail();

        return new OrderResource($order);
    }

    public function sendVerification()
    {
        $customerId = Auth::id();
        $customer = User::findOrFail($customerId);
        $this->_convomatService->getEmailVerification($customer->email);

        return response('', 204);
    }

    public function sendPayout(SendPayoutRequest $request, $orderId): OrderResource
    {
        $customerId = Auth::id();
        $customer = User::findOrFail($customerId);

        $order = Order::where([
            'order_id' => $orderId,
            'user_id' => $customerId,
            'status' => 'Shipped',
            'has_review' => false,
            'is_done' => false,
        ])->firstOrFail();

        $input = $request->validated();
        $this->_convomatService->setVerificationCode($input['2FA']);

        switch ($customer->payment_preference) {
            case 'venmo':
                $response = $this->_convomatService->postSendVenmoPayout(
                    $order->campaign_id,
                    $order->order_id,
                    $order->user->email,
                    $order->user->phone_number
                );
                break;

            case 'amazon_gift_card':
                $response = $this->_convomatService->postSendGiftCardByOrderId(
                    $order->campaign_id,
                    $order->order_id,
                    true,
                    $order->user->email,
                    'Amazon'
                );
                break;

            case 'visa_gift_card':
                $response = $this->_convomatService->postSendGiftCardByOrderId(
                    $order->campaign_id,
                    $order->order_id,
                    true,
                    $order->user->email,
                    'VISA'
                );
                break;

            case 'mastercard_gift_card':
                $response = $this->_convomatService->postSendGiftCardByOrderId(
                    $order->campaign_id,
                    $order->order_id,
                    true,
                    $order->user->email,
                    'Master Card'
                );
                break;

            case 'paypal':
                $response = $this->_convomatService->postSendPaypalPayout(
                    $order->campaign_id,
                    $order->order_id,
                    $order->user->email
                );
                break;

            default:
                break;
        }

        $campaign = $this->getCampaign($order->campaign_id);
        $feedbackBonus = $campaign->getData()->item->feedback_bonus;

        $order->reward = (float) $feedbackBonus;
        $order->order_payment_reference = $customer->payment_preference;
        $order->is_paid = true;
        $order->save();

        return new OrderResource($order);
    }

    public function postFeedback(PostFeedbackRequest $request, $orderId): OrderResource
    {
        $input = $request->validated();
        $customerId = Auth::id();

        $order = Order::where([
            'order_id' => $orderId,
            'user_id' => $customerId,
            'has_review' => false,
            'is_done' => false,
        ])->firstOrFail();
        $order->tags = $input['tags'];
        $order->rating = $input['rating'];
        $order->save();

        if (!$this->_eligibleToPostReview($orderId)) {
            $order->is_done = true;
            $order->completed_at = \Carbon\Carbon::now()->toDateTimeString();
            $order->save();

            $user = self::find(Auth::id());
            $user->is_vip = true;
            $user->save();
        }

        return new OrderResource($order);
    }

    public function postReview(PostReviewRequest $request, $orderId): OrderResource
    {
        $input = $request->validated();
        $reviewAuthor = $input['reviewer_name'];
        /** @var User $user */
        $user = auth()->user();

        $order = Order::where([
            'order_id' => $orderId,
            'user_id' => $user->id,
            'has_review' => false,
            'is_done' => false,
        ])->whereNotNull('asin_id')->firstOrFail();

        if (strpos($reviewAuthor, 'http') === 0) {
            $response = $this->_convomatService->getAmazonProfileByUrl($reviewAuthor);
            if (!empty($response['user_id'])) {
                $user->amazon_id = $response['user_id'];
                $user->save();
            } else {
                return response('Amazon profile not found.', 400);
            }
        } else {
            $order->reviewer_name = $reviewAuthor;
        }

        $order->has_review = true;
        $order->save();

        return new OrderResource($order);
    }

    public function eligibleToPostReview($orderId): JsonResponse
    {
        return response()->json(['status' => $this->_eligibleToPostReview($orderId)]);
    }

    private function _eligibleToPostReview($orderId): bool
    {
        $company = auth()->user()->company;

        $companyOrdersTotal = Order::where([
            'company_id' => $company->id,
        ])->get()->count();

        return Order::where(['order_id' => $orderId])->firstOrFail()->rating >= $company->review_from
            && $companyOrdersTotal < $company->review_limit;
    }
}
