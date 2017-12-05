<?php
namespace Stripe\Controller\Component;

use Cake\Controller\Component;
use Cake\Controller\ComponentRegistry;
use Cake\Core\Configure;
use Stripe\Charge;
use Stripe\Coupon;
use Stripe\Customer;
use Stripe\Error\Card;
use Stripe\Error\InvalidRequest;
use Stripe\Plan;
use Stripe\Source;
use Stripe\Stripe;
use Stripe\Subscription;

/**
 * Stripe component
 */
class StripeComponent extends Component
{

    /**
     * Default configuration.
     *
     * @var array
     */
    protected $_defaultConfig = [];
    protected $apiKey = '';

    /**
     * initialize
     * @param  array  $config  [description]
     * @return void
     */
    public function initialize(array $config)
    {
        $this->apiKey = Configure::read('Stripe.apiKey');
        Stripe::setApiKey($this->apiKey);
    }

    /**
     * Create Plan in Stripe if not exist
     * @param  array  $plan [id, name, amount, interval]  [description]
     * @return bool
     */
    public function createPlanIfNotExist(Array $plan)
    {
        //test si plan existe
        try {
            $plan = Plan::retrieve($plan['id']);

            return true;
        } catch (InvalidRequest $e) {
            //création du plan
            try {
                $plan = Plan::create(
                    [
                        "amount" => $plan['amount'] * 100,
                        "interval" => $plan['interval'],
                        "name" => $plan['name'],
                        "currency" => "eur",
                        "id" => $plan['id']
                    ]
                );
            } catch (InvalidRequest $e) {
                return false;
            }

            return true;
        }
    }

    /**
     * Create Customer in Stripe if not exist
     * @param  [type]   $customer [description]
     * @param  [type]   $token [description]
     * @return Customer Id
     */
    public function createCustomerIfNotExist($customer, $token)
    {
        if ($customer->cus_id) {
            try {
                $cus = Customer::retrieve($customer->cus_id);

                return $cus->id;
            } catch (\Stripe\Error\InvalidRequest $e) {
                return false;
            }
        } else {
            try {
                $cus = Customer::create(
                    [
                        "source" => $token,
                        "email" => $customer->email
                    ]
                );

                return $cus->id;
            } catch (\Stripe\Error\InvalidRequest $e) {
                return false;
            }
        }
    }

    /**
     * addSubscription
     * @param  [type]   $cusId [description]
     * @param  [type]   $planId [description]
     * @param  [type]   $qte [description]
     * @param  [type]   $coupon [description]
     * @param  [type]   $trialEnd [description]
     * @return Subscription Id
     */
    public function addSubscription($cusId = null, $planId = null, $qte = 0, $coupon = null, $trialEnd = null)
    {
        try {
            $options = 
                [
                    "customer" => $cusId,
                    "plan" => $planId,
                    "quantity" => $qte
                ];

            if ($trialEnd) {
                $options['trial_end'] = $trialEnd;
            }

            if ($coupon) {
                $options['coupon'] = $coupon;
            }

            $sub = Subscription::create($options);

            return $sub->id;
        } catch (\Stripe\Error\Base $e) {
            return false;
        }
    }

    /**
     * removeSubscription
     * @param  [type]   $subId [description]
     * @return bool
     */
    public function removeSubscription($subId)
    {
        try {
            $sub = Subscription::retrieve($subId);
            $sub->cancel();

            return true;
        } catch (\Stripe\Error\InvalidRequest $e) {
            return false;
        }
    }

    /**
     * chargeByCustomerId
     * @param  [type]   $cusId [description]
     * @param  [type]   $amount [description]
     * @param  [type]   $description [description]
     * @param  [type]   $statementDescriptor [description]
     * @return Charge Id
     */
    public function chargeByCustomerId($cusId = null, $amount = null, $description = null, $statementDescriptor = null)
    {
        try {
            $charge = Charge::create(
                [
                    "amount" => $amount,
                    "currency" => "eur",
                    "customer" => $cusId, // obtained with Stripe.js
                    "description" => $description,
                    "statement_descriptor" => substr($statementDescriptor, 0, 22)
                ]
            );

            return $charge->id;
        } catch (\Stripe\Error\Base $e) {
            return false;
        }
    }

    /**
     * createCoupons
     * @param  [type]   $coupon [description]
     * @return Coupon Id
     */
    public function createCoupons($coupon)
    {
        try {
            $couponStripe = Coupon::create($coupon);

            return $couponStripe->id;
        } catch (\Stripe\Error\InvalidRequest $e) {
            return false;
        }
    }

    /**
     * updateCoupons
     * @param [type]  $stripeId     [description]
     * @param [type]  $metadata     [description]
     * @return Coupon Id
     */
    public function updateCoupons($stripeId, $metadata)
    {
        try {
            $couponStripe = Coupon::retrieve($stripeId);
            $couponStripe->metadata["redeem_by"] = $metadata["redeem_by"];
            $couponStripe->save();

            return $couponStripe->id;
        } catch (\Stripe\Error\InvalidRequest $e) {
            return false;
        }
    }

    /**
     * updateCard
     * @param [type]  $customer [description]
     * @param [type]  $token    [description]
     * @return bool
     */
    public function updateCard($customer = null, $token = null)
    {
        if ($token) {
            try {
                $customer = Customer::retrieve($customer->cus_id);
                $customer->source = $token;
                $customer->save();

                return true;
            } catch (\Stripe\Error\Card $e) {
                return false;
            }
        }
    }

    /**
     * createSourceByIban
     * @param [type] $iban [description]
     * @param [type] $ibanOwner [description]
     * @return bool
     */
    public function createSourceByIban($iban, $ibanOwner)
    {
        try {
            $source = Source::create(
                [
                    "type" => "sepa_debit",
                    "sepa_debit" => ["iban" => $iban],
                    "currency" => "eur",
                    "owner" => ["name" => $ibanOwner]
                ]
            );

            return $source->id;
        } catch (\Stripe\Error\Base $e) {
            return false;
        }
    }

    /**
     * chargeSepaByCustomerIdAndSourceId
     * @param [type]  $cusId                    [description]
     * @param [type]  $srcId                    [description]
     * @param [type]  $amount                   [description]
     * @param [type]  $description              [description]
     * @param [type]  $statementDescriptor      [description]
     * @return bool
     */
    public function chargeSepaByCustomerIdAndSourceId($cusId = null, $srcId = null, $amount = null, $description = null, $statementDescriptor = null)
    {
        try {
            $charge = Charge::create(
                [
                    "amount" => $amount,
                    "currency" => "eur",
                    "customer" => $cusId, // obtained with Stripe.js
                    "source" => $srcId,
                    "description" => $description,
                    "statement_descriptor" => substr($statementDescriptor, 0, 22)
                ]
            );

            return $charge->id;
        } catch (\Stripe\Error\Base $e) {
            return false;
        }
    }

    /**
     * updateSource
     * @param [type]  $customer    [description]
     * @param [type]  $iban    [description]
     * @param [type]  $ibanOwner    [description]
     * @return bool
     */
    public function updateSource($customer = null, $iban = null, $ibanOwner = null)
    {
        try {
            $customer = Customer::retrieve($customer->cus_id);
            $srcId = $this->createSourceByIban($iban, $ibanOwner);
            $customer->source = $srcId;
            $customer->save();

            return $srcId;
        } catch (\Stripe\Error\Card $e) {
            return false;
        }
    }

    /**
     * [addOrUpdateSubscription description]
     * @param [type]  $cusId    [description]
     * @param [type]  $planId   [description]
     * @param int $qte      [description]
     * @param [type]  $coupon   [description]
     * @param [type]  $trialEnd [description]
     * @param [type]  $subId    [description]
     * @return bool           [description]
     */
    public function addOrUpdateSubscription($cusId = null, $planId = null, $qte = 0, $coupon = null, $trialEnd = null, $subId = null)
    {
        try {
            if ($subId) {
                $this->removeSubscription($subId);
            }

            return $this->addSubscription($cusId, $planId, $qte, $coupon, $trialEnd);
        } catch (\Stripe\Error\Base $e) {
            return false;
        }
    }
}
