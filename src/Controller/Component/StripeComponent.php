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
use Stripe\BalanceTransaction;
use Stripe\Payout;
use Stripe\Product;
use Stripe\Checkout\Session;
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
     * Create Product in Stripe if not exist
     * @param  array  $prod [id, name]  [description]
     * @return bool
     */
    public function createProductIfNotExist(Array $prod)
    {
         Stripe::setVerifySslCerts(false);
        //test si plan existe
        try {
            $prod = Product::retrieve($prod['id']);

            return true;
        } catch (InvalidRequest $e) {
            //création du plan
            try {
                $prod = Product::create(
                    [
                        "id" => $prod['id'],
                        "name" => $prod['name'],
                        "type" => 'service'
                    ]
                );
            } catch (InvalidRequest $e) {
                return false;
            }

            return true;
        }
    }

    /**
     * Create Checkout session in Stripe
     * @param $customer_email
     * @param $plan
     * @param $success
     * @param $error
     * @return mixed
     */
    public function createCheckoutSession($customer_id,$customer_email, $plan, $success, $error)
    {
           
        if($customer_id != null)
        $session = Session::create([
            'customer' => $customer_id,
            'payment_method_types' => ['card'],
            'subscription_data' => [
                'items' => [[
                    'plan' => $plan
                ]],
            ],
            'success_url' => $success,
            'cancel_url' => $error,
        ]);
        else
            $session = Session::create([
                'customer_email' => $customer_email,
                'payment_method_types' => ['card'],
                'subscription_data' => [
                    'items' => [[
                        'plan' => $plan
                    ]],
                ],
                'success_url' => $success,
                'cancel_url' => $error,
            ]);


        return $session;
    }

    public function getAllBalance($start)
    {

        $start = str_replace('/', '-', $start);
            
        Stripe::setVerifySslCerts(false);
        $balance = BalanceTransaction::all(['type' => 'charge','created >' => strtotime($start),'limit'=> 40]);
        return $balance;

    }
    public function getAllPayout($start,$end)
    {

        $start = str_replace('/', '-', $start);
        $end = str_replace('/', '-', $end);

        Stripe::setVerifySslCerts(false);
        $balance = BalanceTransaction::all(['limit'=> 400, 'created' => ['gte' =>strtotime($start), 'lte'=>strtotime($end)],'type' => 'charge']);
        return $balance;

    }
    /**
     * Create Plan in Stripe if not exist
     * @param  array  $plan [id, name, amount, interval]  [description]
     * @return bool
     */
    public function createPlanIfNotExist(Array $plan)
    {
         Stripe::setVerifySslCerts(false);
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
                        "nickname" => $plan['name'],
                        "currency" => "eur",
                        "product" => $plan['product'],
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
     * Delete Plan in Stripe
     * @param  array  $plan [id, name, amount, interval]
     * @return bool
     */
    public function deletePlan($id)
    {
       Stripe::setVerifySslCerts(false);
            $plan = Plan::retrieve($id);


            if($plan != null)
                $plan->delete();

            return true;
    }

    /**
     * Create Customer in Stripe if not exist
     * @param  [type]   $customer [description]
     * @param  [type]   $token [description]
     * @return Customer Id
     */
    public function createCustomerIfNotExist($customer, $coupon)
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
                        "coupon" => $coupon,
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
          \Stripe\Stripe::setVerifySslCerts(false);
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
     * @param [type]  $cusId    Customer Identifier
     * @param [type]  $planId   Plan Identifier
     * @param int $qte      Quantite
     * @param [type]  $coupon   Coupon identifier
     * @param [type]  $trialEnd Date end trial
     * @param [type]  $subId    Subscription identifier
     * @return bool
     */
    public function addOrUpdateSubscription($cusId = null, $planId = null, $qte = 0, $coupon = null, $trialEnd = null, $subId = null)
    {
        try {
            if ($subId) {
                return $this->updatePlan($subId, $planId);
            }

            return $this->addSubscription($cusId, $planId, $qte, $coupon, $trialEnd);
        } catch (\Stripe\Error\Base $e) {
            return false;
        }
    }

    /**
     * updatePlan
     * @param [type]  $subId     Subscription identifier
     * @param [type]  $newPlan     New Plan Identifier
     * @return int Id
     */
    public function updatePlan($subId, $newPlan)
    {
        try {
            $subscription = Subscription::retrieve($subId);
            $subscription->plan = $newPlan;
            $subscription->save();

            return $subscription->id;
        } catch (\Stripe\Error\InvalidRequest $e) {
            return false;
        }
    }
}
