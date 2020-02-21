<?php

namespace App\Repositories\Api;

/**
 * Description: this repository is used for transaction related operations 
 * Author : Codiant- A Yash Technologies Company 
 * Date :8 march 2019
 * 
 */
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Models\Transaction;
use App\Models\Card;
use App\User;
use Stripe\Stripe;
use Stripe\Customer;
use Stripe\Charge;
use Stripe\Token;
use Stripe\Error;
use Stripe\Account;
use Stripe\FileUpload;
use Stripe\Transfer;
use JWTAuth;
use App\Models\Country;
use App\Models\State;
use App\Models\City;

Class TransactionRepository {

    public function __construct(Transaction $transaction) {
        $this->transaction = $transaction;
    }

    /**
     * add card
     * @param type $user(obj)
     * @param type $request(obj)
     * @return type obj
     */
    public function addCard($user, $request) {
        // Set secret key
        Stripe::setApiKey(getSetting('stripe_test_secret_key'));
        if ($user->customer_id) {
            // Get Customer data
            $customer = Customer::retrieve($user->customer_id);
            if ($customer) {
                // Create new source token for a customer
                $newSourceToken = Token::create([
                            "card" => [
                                "number" => $request->card_number,
                                "exp_month" => $request->expiry_month,
                                "exp_year" => $request->expiry_year,
                                "cvc" => $request->cvv
                            ]
                ]);
                // Save card to database
                if ($newSourceToken) {
                    $newCardSource = $customer->sources->create(["source" => $newSourceToken['id']]);
                    if ($newCardSource) {
                        $data['user_id'] = $user->id;
                        $data['card_id'] = $newCardSource['id'];
                        $data['card_type'] = $newCardSource['brand'];
                        $data['card_number'] = $newCardSource['last4'];
                        $data['exp_month'] = $newCardSource['exp_month'];
                        $data['exp_year'] = $newCardSource['exp_year'];
                        $save = Card::create($data);
                        if ($save) {
                            return $this->getCard($user, $request);
                        }
                    }
                }
            }
        }
        return [];
    }

    /**
     * Get Card
     * @param type $user(obj)
     * @param type $request(obj)
     * @return type array of object
     */
    public function getCard($user, $request) {
        // Set secret key
        Stripe::setApiKey(getSetting('stripe_test_secret_key'));

        // Get all card
        if ($user->id) {
            return Card::where('user_id', $user->id)->get();
            //return Customer::retrieve($user->customer_id)->sources->all(['object' => 'card']);
        }
        return [];
    }

    /**
     * Delete Card
     * @param type $user(obj)
     * @param type $request(obj)
     * @return type boolean
     */
    public function deleteCard($user, $request) {
        // Set secret key
        Stripe::setApiKey(getSetting('stripe_test_secret_key'));

        // Get all card
        if ($user->customer_id) {
            $customer = Customer::retrieve($user->customer_id);
            $deleteCard = $customer->sources->retrieve($request->card_id)->delete();
            if ($deleteCard) {
                return Card::where('card_id', $request->card_id)->delete();
            }
        }
        return [];
    }

    /**
     * Make Payment
     * @param type $user
     * @param type $request
     * @return type json
     */
    public function payment($user, $request) {
        // Set secret key
        Stripe::setApiKey(getSetting('stripe_test_secret_key'));

        // Get all card		
        if ($user->customer_id) {
            $mentorAccountId = getUserData($request->mentor_id, 'account_id');
            $commisionPercent = getSetting('commision_percent');
            $amount = round($request->amount);
            $applicationFee = round(( $amount * $commisionPercent ) / 100, 2);
            $charge = Charge::create([
                        "customer" => $user->customer_id,
                        "amount" => $amount * 100,
                        "currency" => "usd",
                        "source" => $request->card_id,
                            //"capture" => false,
//                        "application_fee" => $applicationFee * 100,
//                        "destination" => $mentorAccountId, // The account ID, retrieved from your database
//                        "metadata" => array(
//                            // The remainder of your application fee is the commission for your platform
//                            "application fee" => '$' . $applicationFee
//                        )
            ]);

            if ($charge['status'] == 'succeeded') {
                try {
                    $data['user_id'] = $user->id;
                    $data['mentor_id'] = $request->mentor_id;
                    $data['appointment_id'] = $request->appointment_id;
                    $data['transaction_id'] = $charge['id'];
                    $data['total_amount'] = $amount;
                    $data['commission'] = $applicationFee;
                    $data['type'] = 'pending';
                    $data['status'] = $charge['status'];
                    $res = $this->transaction->create($data);
                    if ($res) {
                        return response()->json(['success' => true, 'data' => [], 'message' => 'Payment Success.']);
                    } else {
                        return response()->json(['success' => false, 'data' => [], 'message' => 'Something went wrong! Please try again.']);
                    }
                } catch (Error\Card $e) {
                    return response()->json(['success' => false, 'error' => $e->getMessage()]);
                } catch (Error\RateLimit $e) {
                    return response()->json(['success' => false, 'error' => $e->getMessage()]);
                } catch (Error\InvalidRequest $e) {
                    return response()->json(['success' => false, 'error' => $e->getMessage()]);
                } catch (Error\Authentication $e) {
                    return response()->json(['success' => false, 'error' => $e->getMessage()]);
                } catch (Error\ApiConnection $e) {
                    return response()->json(['success' => false, 'error' => $e->getMessage()]);
                } catch (\Exception $e) {
                    return response()->json(['success' => false, 'error' => $e->getMessage()]);
                }
            }
        }
        return [];
    }

    /**
     * Create Connect Account
     * @param type $request
     * @return type
     */
    public function createConnectAccount($request) {
        $token = \Request::header('access_token'); // to authenticate token
        $user = JWTAuth::toUser($token);
//            echo "<pre>";
//            print_r($user);die;
        // Set secret key
        Stripe::setApiKey(getSetting('stripe_test_secret_key'));
        $country = Country::where(['id' => $user->country_id])->first();
        $state = State::where(['id' => $user->state_id])->first();
        $city = City::where(['id' => $user->city])->first();

        try {
            $data = array(
                "country" => $country->code,
                "email" => $user->email,
                "type" => "custom",
                'external_account' => array(
                    "object" => "bank_account",
                    "country" => "US",
                    "currency" => "usd",
                    "account_holder_name" => $request->account_holder_name,
                    "account_holder_type" => 'individual',
                    "routing_number" => $request->routing_number,
                    "account_number" => $request->account_number
                ),
                "legal_entity" => array(
                    'address' => array(
                        'city' => $city['name'],
                        'country' => $country->code,
                        "line1" => $user->address,
                        "line2" => '',
                        "postal_code" => $request->postal_code,
                        "state" => $state['name']
                    ),
                    'dob' => array(
                        'day' => date("d", strtotime($user->date_of_birth)),
                        'month' => date("m", strtotime($user->date_of_birth)),
                        'year' => date("Y", strtotime($user->date_of_birth))
                    ),
                    'first_name' => $user->first_name,
                    'last_name' => $user->last_name,
                    'personal_id_number' => $request->ssn_number,
                    'type' => 'individual'
                ),
                'tos_acceptance' => array(
                    'date' => time(),
                    'ip' => $_SERVER['REMOTE_ADDR']
                ),
            );

            $account = Account::create($data);
            if ($account) {
                User::where('email', $user->email)->update(['account_id' => $account->id]);
                // Identity document
                if ($request->hasFile('personal_id')) {
                    $file = $request->personal_id;
                    $fileName = $file->getClientOriginalName();
                    $file->move('public/uploads', time() . $fileName);
                    $filePath = public_path() . '/uploads/' . time() . $fileName;
                    // Save File
                    $uploadedFile = FileUpload::create([
                                "purpose" => "identity_document",
                                "file" => fopen($filePath, 'r')
                                    ], [
                                "stripe_account" => $account->id
                    ]);
                    // Verify File
                    $account->legal_entity->verification->document = $uploadedFile->id;
                    $account->save();
                    // Delete file
                    unlink($filePath);
                }
            }
            return $account;
        } catch (\Exception $e) {
            echo "<pre>";
            print_r($e->getMessage());
            die;
            return response()->json(['success' => false, 'data' => [], 'error' => $e->getMessage()]);
        }
    }

//    public function createConnectAccount($request) {
//        $token = \Request::header('access_token'); // to authenticate token
//            $user = JWTAuth::toUser($request->header('access_token'));
//            echo "<pre>";
//            print_r($user);die;
//        // Set secret key
//        Stripe::setApiKey(getSetting('stripe_test_secret_key'));
//
//        $data = array(
//            "country" => "US",
//            "email" => $request->email,
//            "type" => "custom",
//            'external_account' => array(
//                "object" => "bank_account",
//                "country" => "US",
//                "currency" => "usd",
//                "account_holder_name" => $request->account_holder_name,
//                "account_holder_type" => 'individual',
//                "routing_number" => $request->routing_number,
//                "account_number" => $request->account_number
//            ),
//            "legal_entity" => array(
//                'address' => array(
//                    'city' => $request->city,
//                    'country' => 'US',
//                    "line1" => $request->address,
//                    "line2" => '',
//                    "postal_code" => $request->postal_code,
//                    "state" => $request->state
//                ),
//                'dob' => array(
//                    'day' => date("d", strtotime($request->dob)),
//                    'month' => date("m", strtotime($request->dob)),
//                    'year' => date("Y", strtotime($request->dob))
//                ),
//                'first_name' => $request->first_name,
//                'last_name' => $request->last_name,
//                'personal_id_number' => 123456789,
//                'type' => 'individual'
//            ),
//            'tos_acceptance' => array(
//                'date' => time(),
//                'ip' => $_SERVER['REMOTE_ADDR']
//            ),
//        );
//
//        $account = Account::create($data);
//        if ($account) {
//            User::where('email', $request->email)->update(['account_id' => $account->id]);
//            // Identity document
//            if ($request->hasFile('personal_id')) {
//                $file = $request->personal_id;
//                $fileName = $file->getClientOriginalName();
//                $file->move('public/uploads', time() . $fileName);
//                $filePath = public_path() . '/uploads/' . time() . $fileName;
//                // Save File
//                $uploadedFile = FileUpload::create([
//                            "purpose" => "identity_document",
//                            "file" => fopen($filePath, 'r')
//                                ], [
//                            "stripe_account" => $account->id
//                ]);
//                // Verify File
//                $account->legal_entity->verification->document = $uploadedFile->id;
//                $account->save();
//                // Delete file
//                unlink($filePath);
//            }
//        }
//        return $account;
//    }

    /**
     * Delete Connect Account
     */
    public function deleteConnectAccount($request) {
        // Set secret key
        Stripe::setApiKey(getSetting('stripe_test_secret_key'));

        // Delete connect account
        $account = Account::retrieve($request->account_id);
        return $account->delete();
    }

    /**
     * Refund Payment
     */
    public function refundPayment($user, $request) {
        // Set secret key
        Stripe::setApiKey(getSetting('stripe_test_secret_key'));



        // Retrieve charge
//        $ch = Charge::retrieve($request->transaction_id);
//        $request_json = array(
//            "amount" => $request->amount * 100
//        );
        $re = \Stripe\Refund::create([
            "charge" => $request->transaction_id
          ]);
//        $captured = $ch->capture($request_json);
        if ($re) {
            $transaction = $this->transaction->where('transaction_id', $request->transaction_id)->first();
            if ($transaction) {
                // Save in Transactions
                $data['user_id'] = $transaction->user_id;
                $data['mentor_id'] = $transaction->mentor_id;
                $data['appointment_id'] = $transaction->appointment_id;
                $data['transaction_id'] = $request->transaction_id;
                $data['total_amount'] = $request->amount;
                $data['commission'] = $transaction->commission;
                $data['type'] = 'refund';
                $data['status'] = $re['status'];
                $this->transaction->where('transaction_id', $request->transaction_id)->update($data);
            }
            return $re;
        }
        return [];
    }

    /*
     * Get All Transactions
     */

    public function getAllTransactions($user, $request) {
        return $this->transaction->where('user_id', $user->id)->get();
    }

    /**
     * Capture Payment
     */
    public function capturePayment($user, $request) {
        // Set secret key
        Stripe::setApiKey(getSetting('stripe_test_secret_key'));

        // Retrieve charge
        $ch = Charge::retrieve($request->transaction_id);
        $captured = $ch->capture();
        if ($captured) {
            $transaction = $this->transaction->where('transaction_id', $request->transaction_id)->first();
            if ($transaction) {
                // Save in Transactions
                $data['user_id'] = $user->id;
                $data['mentor_id'] = $transaction->mentor_id;
                $data['appointment_id'] = $transaction->appointment_id;
                $data['transaction_id'] = $request->transaction_id;
                $data['total_amount'] = $transaction->total_amount;
                $data['commission'] = $transaction->commission;
                $data['type'] = 'completed';
                $data['status'] = $captured['status'];
                $this->transaction->where('transaction_id', $request->transaction_id)->update($data);
            }
            return $captured;
        }
        return [];
    }

    /**
     * payment transfer
     */
    public function paymentTransfer($request) {
        $amount = $request->amount;
        $mentor = User::where(['id' => $request->mentor_id])->first();
        $transaction = Transaction::where(['appointment_id' => $request->appointment_id])->first();
        // Set secret key
        Stripe::setApiKey(getSetting('stripe_test_secret_key'));
        try {
            if (!empty($mentor->account_id)) {
                $transfer = Transfer::create(
                                [
                                    "amount" => $amount * 100,
                                    "currency" => "usd",
                                    "source_transaction" => $transaction->transaction_id,
                                    "destination" => $mentor->account_id,
                ]);
                if ($transfer) {
                    return response()->json(['success' => true, 'data' => [], 'message' => 'Fund transfered successfully.']);
                }
            }
        } catch (\Exception $e) {
            echo "<pre>";
            print_r($e->getMessage());
            die;
            return response()->json(['success' => false, 'data' => [], 'error' => $e->getMessage()]);
        }
    }

}
