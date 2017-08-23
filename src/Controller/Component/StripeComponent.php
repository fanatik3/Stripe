<?php
namespace Stripe\Controller\Component;

use Cake\Controller\Component;
use Cake\Controller\ComponentRegistry;
use Cake\Core\Configure;
use Stripe\Stripe;
use Stripe\Plan;
use Stripe\Customer;
use Stripe\Subscription;
use Stripe\Charge;
use Stripe\Coupon;
use Stripe\Error\InvalidRequest;
use Stripe\Error\Card;

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

    public function initialize(array $config){
        $this->apiKey = Configure::read('Stripe.apiKey');
        Stripe::setApiKey($this->apiKey);
    }

    /**
     * Create Plan in Stripe if not exist
     * @param  Array  $plan [id, name, amount, interval]
     * @return Boolean
     */
    public function createPlanifIfNotExist(Array $plan){
        //test si plan existe
        try {
            $plan = Plan::retrieve($plan['id']);
            return true;
        } catch (InvalidRequest $e) {
            //création du plan
            try {
                $plan = Plan::create(array(
                    "amount" => $plan['amount'] * 100,
                    "interval" => $plan['interval'],
                    "name" => $plan['name'],
                    "currency" => "eur",
                    "id" => $plan['id'])
                );
            } catch (InvalidRequest $e) {
                return false;
            }
            return true;
        }
    }

    public function createCustomerIfNotExist($customer, $token){
        if($customer->cus_id){
            try {
                $cus = Customer::retrieve($customer->cus_id);
                return $cus->id;
            } catch (\Stripe\Error\InvalidRequest $e) {
                return false;
            }
        }
        else {
            try {
                $cus = Customer::create(array(
                    "source" => $token,
                    "email" => $customer->email)
                );
                return $cus->id;

            } catch (\Stripe\Error\InvalidRequest $e) {
                return false;
            }
        }
    }

    public function addSubscription($cus_id = null, $plan_id = null, $qte = 0, $coupon = null, $trial_end = null){
        try {
            $options = array(
              "customer" => $cus_id,
              "plan" => $plan_id,
              "quantity" => $qte,
            );

            if($trial_end){
                $options['trial_end'] = $trial_end;
            }

            if($coupon){
                $options['coupon'] = $coupon;
            }

            $sub = Subscription::create($options);
            return $sub->id;

        } catch (\Stripe\Error\Base $e) {
            return false;
        }
    }

    public function removeSubscription($sub_id){
        try {
            $sub = Subscription::retrieve($sub_id);
            $sub->cancel();

            return true;
        } catch (\Stripe\Error\InvalidRequest $e) {
            return false;
        }
    }

    public function chargeByCustomerId($cus_id = null, $amount = null, $description = null, $statement_descriptor = null){
        try {
            $charge = Charge::create(array(
              "amount" => $amount,
              "currency" => "eur",
              "customer" => $cus_id, // obtained with Stripe.js
              "description" => $description,
              "statement_descriptor" => substr($statement_descriptor, 0, 22)
            ));

            return $charge->id;
        } catch (\Stripe\Error\Base $e) {

            return false;
        }

    }

    public function createCoupons($coupon){
        try {
            $couponStripe = Coupon::create($coupon);

            return $couponStripe->id;

        } catch (\Stripe\Error\InvalidRequest $e) {

            return false;
        }

    }

    public function updateCoupons($stripeId, $metadata){
        try {

            $couponStripe = Coupon::retrieve($stripeId);
            $couponStripe->metadata["redeem_by"] = $metadata["redeem_by"];
            $couponStripe->save();
            return $couponStripe->id;

        } catch (\Stripe\Error\InvalidRequest $e) {

            return false;
        }

    }

    public function updateCard($customer = null, $token = null)
    {
        if ($token) {
            try {
                $cu = Customer::retrieve($customer->cus_id);
                $cu->source = $token;
                $cu->save;

                return true;
            } catch (\Stripe\Error\Card $e) {

                return false;
            }
        }
    }
}
