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
     * @param  Array  $config
     * @return void
     */
    public function initialize(array $config)
    {
        $this->apiKey = Configure::read('Stripe.apiKey');
        Stripe::setApiKey($this->apiKey);
    }

    /**
     * Create Plan in Stripe if not exist
     * @param  Array  $plan [id, name, amount, interval]
     * @return boolean
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
     * @param  customer, token
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
     * @param  cusId, planId, qte = 0, coupon, trialEnd
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
     * @param  subId
     * @return boolean
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
     * @param  cusId, amount, description, statementDescriptor
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
     * @param  coupon
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
     * @param  stripeId, metadata
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
     * @param  customer, token
     * @return boolean
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
     * @param  customer, token
     * @return boolean
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
     * @param  customer, token
     * @return boolean
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
     * @param  customer, iban, ibanOwner
     * @return boolean
     */
     public function updateSource($customer = null, $iban = null, $ibanOwner = null)
     {
         if ($token) {
             try {
                 $customer = Customer::retrieve($customer->cus_id);
                 $customer->source = $this->createSourceByIban($iban, $ibanOwner);
                 $customer->save();
 
                 return $customer->source;
             } catch (\Stripe\Error\Card $e) {
                 return false;
             }
         }
     }
}
