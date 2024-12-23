<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Models\Cart;
use App\Models\ExternalConfiguration;
use App\Models\User;
use App\Models\Guest;
use Carbon\CarbonInterval;
use Firebase\JWT\JWT;
use GuzzleHttp\Client;
use Illuminate\Http\Request;
use App\CentralLogics\Helpers;
use Illuminate\Support\Carbon;
use App\Mail\EmailVerification;
use App\Mail\LoginVerification;
use App\Models\BusinessSetting;
use App\CentralLogics\SMS_module;
use App\Models\WalletTransaction;
use App\Models\EmailVerifications;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use App\Http\Controllers\Controller;
use App\Jobs\CustomerSignUp;
use Exception;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Modules\Gateways\Traits\SmsGateway;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use PhpParser\Node\Stmt\Catch_;

class CustomerAuthController extends Controller
{
    public function verifySignUpOtp(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'otp' => 'required',
            'email' => 'required',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => Helpers::error_processor($validator)], 403);
        }

        $cachedOtp = Cache::get('otp_' . $request->email);
        if ($cachedOtp != $request->otp) {
            return response()->json(['message' => 'Invalid OTP.'], 400);
        }
        // Retrieve cached user data
        $cacheKey = 'user_registration_' . $request->email;
        $userData = Cache::get($cacheKey);

        if (!$userData) {
            return response()->json(['message' => 'User data not found or expired.Please again signup'], 400);
        }

        try {
            DB::beginTransaction();
            $userData['is_email_verified'] = 1;
            $user = new User($userData);
            $user->save();
            $token = null;
            if (auth()->loginUsingId($user->id)) {
                $token = auth()->user()->createToken('RestaurantCustomerAuth')->accessToken;
            }

            CustomerSignUp::dispatch($user)->afterCommit();

            DB::commit();
            // Clear cached data
            Cache::forget($cacheKey);
            Cache::forget('otp_' . $request->email);
            return response()->json(['token' => $token, 'user' => $user]);
        } catch (Exception $ex) {
            DB::rollBack();
            logs()->error('Signup Error', [$ex]);
            return response()->json(['message' => 'something went wrong on server'], 500);
        }
    }
    public function verify_phone_or_email(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'otp'=>'required',
            'verification_type' => 'required|in:phone,email',
            'phone' => 'required_if:verification_type,phone|min:9|max:14',
            'email' => 'required_if:verification_type,email|email',
            'login_type' => 'required|in:manual,otp'
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => Helpers::error_processor($validator)], 403);
        }

        if($request->phone){
            $user = User::where('phone', $request->phone)->first();
        }
        if($request->email){
            $user = User::where('email', $request->email)->first();
        }
        $temporaryToken = null;

        if($user && $request->login_type== 'manual')
        {
            if($request->verification_type == 'phone' && $user->is_phone_verified)
            {
                return response()->json([
                    'message' => translate('messages.phone_number_is_already_verified')
                ], 200);

            }
            if($request->verification_type == 'email' && $user->is_email_verified)
            {
                return response()->json([
                    'message' => translate('messages.email_number_is_already_verified')
                ], 200);

            }

            if(env('APP_MODE')=='test')
            {
                if($request['otp']=="123456")
                {
                    if($request->verification_type == 'email'){
                        $user->is_email_verified = 1;
                    }elseif ($request->verification_type == 'phone'){
                        $user->is_phone_verified = 1;
                    }
                    $user->save();
                    $is_personal_info = 0;

                    if($user->f_name){
                        $is_personal_info = 1;
                    }

                    $user_email = null;
                    if($user->email){
                        $user_email = $user->email;
                    }
                    if (auth()->loginUsingId($user->id)) {
                        $token = auth()->user()->createToken('RestaurantCustomerAuth')->accessToken;
                        if(isset($request['guest_id'])){
                            $this->check_guest_cart($user, $request['guest_id']);
                        }
                    }

                    return response()->json(['token' => isset($token)?$token:$temporaryToken, 'is_phone_verified'=>1, 'is_email_verified'=>1, 'is_personal_info' => $is_personal_info, 'is_exist_user' =>null, 'login_type' => $request->login_type, 'email' => $user_email], 200);
                }
                return response()->json([
                    'message' => translate('OTP does not match')
                ], 404);
            }

            if($request->verification_type == 'email'){
                $data = DB::table('email_verifications')->where([
                    'email' => $request['email'],
                    'token' => $request['otp'],
                ])->first();
            }elseif ($request->verification_type == 'phone'){
                $data = DB::table('phone_verifications')->where([
                    'phone' => $request['phone'],
                    'token' => $request['otp'],
                ])->first();
            }


            if($data)
            {
                if($request->verification_type == 'email'){
                    DB::table('email_verifications')->where([
                        'email' => $request['email'],
                        'token' => $request['otp'],
                    ])->delete();

                    $user->is_email_verified = 1;
                }elseif ($request->verification_type == 'phone'){
                    DB::table('phone_verifications')->where([
                        'phone' => $request['phone'],
                        'token' => $request['otp'],
                    ])->delete();

                    $user->is_phone_verified = 1;
                }

                $user->save();
                $is_personal_info = 0;

                if($user->f_name){
                    $is_personal_info = 1;
                }
                $user_email = null;
                if($user->email){
                    $user_email = $user->email;
                }
                if ($is_personal_info == 1 && auth()->loginUsingId($user->id)) {
                    $token = auth()->user()->createToken('RestaurantCustomerAuth')->accessToken;
                    if(isset($request['guest_id'])){
                        $this->check_guest_cart($user, $request['guest_id']);
                    }
                }
                return response()->json(['token' => isset($token)?$token:$temporaryToken, 'is_phone_verified'=>1, 'is_email_verified'=>1, 'is_personal_info' => $is_personal_info, 'is_exist_user' =>null, 'login_type' => $request->login_type, 'email' => $user_email], 200);
            }
            else{
                if ($request->verification_type == 'phone') {
                    $max_otp_hit = 5;

                    $max_otp_hit_time = 60; // seconds
                    $temp_block_time = 600; // seconds

                    $verification_data = DB::table('phone_verifications')->where('phone', $request['phone'])->first();

                    if (isset($verification_data)) {
                        if (isset($verification_data->temp_block_time) && Carbon::parse($verification_data->temp_block_time)->DiffInSeconds() <= $temp_block_time) {
                            $time = $temp_block_time - Carbon::parse($verification_data->temp_block_time)->DiffInSeconds();

                            $errors = [];
                            array_push($errors, ['code' => 'otp_block_time',
                                'message' => translate('messages.please_try_again_after_') . CarbonInterval::seconds($time)->cascade()->forHumans()
                            ]);
                            return response()->json([
                                'errors' => $errors
                            ], 405);
                        }

                        if ($verification_data->is_temp_blocked == 1 && Carbon::parse($verification_data->updated_at)->DiffInSeconds() >= $max_otp_hit_time) {
                            DB::table('phone_verifications')->updateOrInsert(['phone' => $request['phone']],
                                [
                                    'otp_hit_count' => 0,
                                    'is_temp_blocked' => 0,
                                    'temp_block_time' => null,
                                    'created_at' => now(),
                                    'updated_at' => now(),
                                ]);
                        }

                        if ($verification_data->otp_hit_count >= $max_otp_hit && Carbon::parse($verification_data->updated_at)->DiffInSeconds() < $max_otp_hit_time && $verification_data->is_temp_blocked == 0) {

                            DB::table('phone_verifications')->updateOrInsert(['phone' => $request['phone']],
                                [
                                    'is_temp_blocked' => 1,
                                    'temp_block_time' => now(),
                                    'created_at' => now(),
                                    'updated_at' => now(),
                                ]);
                            $errors = [];
                            array_push($errors, ['code' => 'otp_temp_blocked', 'message' => translate('messages.Too_many_attemps')]);
                            return response()->json([
                                'errors' => $errors
                            ], 405);
                        }
                    }


                    DB::table('phone_verifications')->updateOrInsert(['phone' => $request['phone']],
                        [
                            'otp_hit_count' => DB::raw('otp_hit_count + 1'),
                            'updated_at' => now(),
                            'temp_block_time' => null,
                        ]);
                }
                return response()->json([
                    'message' => translate('OTP does not match')
                ], 404);
            }
        }
        if($request->login_type== 'otp'){
            $data = DB::table('phone_verifications')->where([
                'phone' => $request['phone'],
                'token' => $request['otp'],
            ])->first();

            if($data){
                if($user && $user->is_phone_verified == 0 && $user->is_from_pos == 0){
                    $is_exist_user = $this->exist_user($user);
                    $user_email = null;
                    if($user->email){
                        $user_email = $user->email;
                    }
                    return response()->json(['token' => $temporaryToken, 'is_phone_verified'=>1, 'is_email_verified'=>1, 'is_personal_info' => 1, 'is_exist_user' =>$is_exist_user, 'login_type' => 'otp', 'email' => $user_email], 200);
                }elseif (($user && $user->is_phone_verified == 1) || ($user && $user->is_phone_verified == 0 && $user->is_from_pos == 1)){
                    DB::table('phone_verifications')->where([
                        'phone' => $request['phone'],
                        'token' => $request['otp'],
                    ])->delete();
                    $is_personal_info = 0;
                    if($user->f_name){
                        $is_personal_info = 1;
                    }
                    $user_email = null;
                    if($user->email){
                        $user_email = $user->email;
                    }
                    if ($is_personal_info == 1 && auth()->loginUsingId($user->id)) {
                        $token = auth()->user()->createToken('RestaurantCustomerAuth')->accessToken;
                        if(isset($request['guest_id'])){
                            $this->check_guest_cart($user, $request['guest_id']);
                        }
                    }
                    return response()->json(['token' => isset($token)?$token:$temporaryToken, 'is_phone_verified'=>1, 'is_email_verified'=>1, 'is_personal_info' => $is_personal_info, 'is_exist_user' =>null, 'login_type' => $request->login_type, 'email' => $user_email], 200);
                }
                else{
                    $user = new User();
                    $user->phone = $request['phone'];
                    $user->password = bcrypt($request['phone']);
                    $user->is_phone_verified = 1;
                    $user->login_medium = 'otp';
                    $user->save();

                    $this->refer_code_check($user);

                    $is_personal_info = 0;
                    $user_email = null;
                    if($user->email){
                        $user_email = $user->email;
                    }

                    return response()->json(['token' => $temporaryToken, 'is_phone_verified'=>1, 'is_email_verified'=>1, 'is_personal_info' => $is_personal_info, 'is_exist_user' => null, 'login_type' => 'otp', 'email' => $user_email], 200);
                }
            }else{
                return response()->json([
                    'message' => translate('OTP does not match')
                ], 404);
            }
        }
        return response()->json([
            'message' => translate('messages.not_found')
        ], 404);

    }

    public function firebase_auth_verify(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'session_info' => 'required',
            'phone' => 'required',
            'otp' => 'required',
            'login_type' => 'required|in:manual,otp'
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => Helpers::error_processor($validator)], 403);
        }

        $webApiKey = BusinessSetting::where('key', 'firebase_web_api_key')->first()?->value??'';

        $response = Http::post('https://identitytoolkit.googleapis.com/v1/accounts:signInWithPhoneNumber?key='. $webApiKey, [
            'sessionInfo' => $request->session_info,
            'phoneNumber' => $request->phone,
            'code' => $request->otp,
        ]);

        $responseData = $response->json();

        if (isset($responseData['error'])) {
            $errors = [];
            $errors[] = ['code' => "403", 'message' => $responseData['error']['message']];
            return response()->json(['errors' => $errors], 403);
        }

        $user = User::Where(['phone' => $request->phone])->first();

        $temporaryToken = null;

        if (isset($user) && $request->login_type== 'manual'){

            if ($user->is_phone_verified) {
                return response()->json([
                    'message' => translate('messages.phone_number_is_already_verified')
                ], 200);
            }
            $user->is_phone_verified = 1;
            $user->save();
            $is_personal_info = 0;

            if($user->f_name){
                $is_personal_info = 1;
            }
            $user_email = null;
            if($user->email){
                $user_email = $user->email;
            }
            if ($is_personal_info == 1 && auth()->loginUsingId($user->id)) {
                $token = auth()->user()->createToken('RestaurantCustomerAuth')->accessToken;
                if(isset($request['guest_id'])){
                    $this->check_guest_cart($user, $request['guest_id']);
                }
            }

            return response()->json(['token' => isset($token)?$token:$temporaryToken, 'is_phone_verified'=>1, 'is_email_verified'=>1, 'is_personal_info' => $is_personal_info, 'is_exist_user' =>null, 'login_type' => $request->login_type, 'email' => $user_email], 200);

        }

        if($request->login_type== 'otp'){
            DB::table('phone_verifications')->updateOrInsert(['phone' => $request['phone']],
                [
                    'token' => $request->otp,
                    'otp_hit_count' => 0,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

            if($user && $user->is_phone_verified == 0 && $user->is_from_pos == 0){
                $is_exist_user = $this->exist_user($user);
                $user_email = null;
                if($user->email){
                    $user_email = $user->email;
                }
                return response()->json(['token' => $temporaryToken, 'is_phone_verified'=>1, 'is_email_verified'=>1, 'is_personal_info' => 1, 'is_exist_user' =>$is_exist_user, 'login_type' => 'otp', 'email' => $user_email], 200);
            }elseif (($user && $user->is_phone_verified == 1) || ($user && $user->is_phone_verified == 0 && $user->is_from_pos == 1)){
                DB::table('phone_verifications')->where([
                    'phone' => $request['phone'],
                    'token' => $request['otp'],
                ])->delete();
                $is_personal_info = 0;
                if($user->f_name){
                    $is_personal_info = 1;
                }
                $user_email = null;
                if($user->email){
                    $user_email = $user->email;
                }
                if ($is_personal_info == 1 && auth()->loginUsingId($user->id)) {
                    $token = auth()->user()->createToken('RestaurantCustomerAuth')->accessToken;
                    if(isset($request['guest_id'])){
                        $this->check_guest_cart($user, $request['guest_id']);
                    }
                }
                return response()->json(['token' => isset($token)?$token:$temporaryToken, 'is_phone_verified'=>1, 'is_email_verified'=>1, 'is_personal_info' => $is_personal_info, 'is_exist_user' =>null, 'login_type' => $request->login_type, 'email' => $user_email], 200);
            }else{
                $user = new User();
                $user->phone = $request['phone'];
                $user->password = bcrypt($request['phone']);
                $user->is_phone_verified = 1;
                $user->login_medium = 'otp';
                $user->save();

                $this->refer_code_check($user);

                $is_personal_info = 0;
                $user_email = null;
                if($user->email){
                    $user_email = $user->email;
                }
                return response()->json(['token' => $temporaryToken, 'is_phone_verified'=>1, 'is_email_verified'=>1, 'is_personal_info' => $is_personal_info, 'is_exist_user' => null, 'login_type' => 'otp', 'email' => $user_email], 200);
            }
        }

        return response()->json([
            'message' => translate('messages.not_found')
        ], 404);
    }
    public function guest_request(Request $request)
    {
        $guest = new Guest();
        $guest->ip_address = $request->ip();
        $guest->fcm_token = $request->fcm_token;

        if ($guest->save()) {
            return response()->json([
                'message' => translate('messages.guest_verified'),
                'guest_id' => $guest->id,
            ], 200);
        }

        return response()->json([
            'message' => translate('messages.failed')
        ], 404);
    }
    public function register(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required',
            'email' => 'required|email|unique:users',
            'phone' => 'required|unique:users',
            'password' => ['required', Password::min(8)],

        ], [
            'name.required' => translate('The name field is required.'),
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => Helpers::error_processor($validator)], 403);
        }
        $ref_by= null ;
        //Save point to refeer
        if ($request->ref_code) {
            $ref_status = BusinessSetting::where('key', 'ref_earning_status')->first()->value;
            if ($ref_status != '1') {
                return response()->json(['errors' => Helpers::error_formater('ref_code', translate('messages.referer_disable'))], 403);
            }

            $referar_user = User::where('ref_code', '=', $request->ref_code)->first();
            if (!$referar_user || !$referar_user->status) {
                return response()->json(['errors' => Helpers::error_formater('ref_code', translate('messages.referer_code_not_found'))], 405);
            }

            if (WalletTransaction::where('reference', $request->phone)->first()) {
                return response()->json(['errors' => Helpers::error_formater('phone', translate('Referrer code already used'))], 203);
            }


            $notification_data = [
                'title' => translate('messages.Your_referral_code_is_used_by') . ' ' . $request->f_name . ' ' . $request->l_name,
                'description' => translate('Be prepare to receive when they complete there first purchase'),
                'order_id' => 1,
                'image' => '',
                'type' => 'referral_code',
            ];

            if (Helpers::getNotificationStatusData('customer', 'customer_new_referral_join', 'push_notification_status') && $referar_user?->cm_firebase_token) {
                Helpers::send_push_notif_to_device($referar_user?->cm_firebase_token, $notification_data);
                DB::table('user_notifications')->insert([
                    'data' => json_encode($notification_data),
                    'user_id' => $referar_user?->id,
                    'created_at' => now(),
                    'updated_at' => now()
                ]);
            }


            $ref_by = $referar_user->id;
        }

        $name = $request->name;
        $nameParts = explode(' ', $name, 2);
        $firstName = $nameParts[0];
        $lastName = $nameParts[1] ?? '';

        $user = [
            'f_name' => $firstName,
            'l_name' => $lastName,
            'email' => $request->email,
            'phone' => $request->phone,
            'ref_by' =>   $ref_by,
            'password' => bcrypt($request->password),
            'ref_code' => Helpers::generate_referer_code(),
        ];

        $cacheKey = 'user_registration_' . $request->email;

        Cache::put($cacheKey, $user, now()->addMinutes(15));
    

        $login_settings = array_column(BusinessSetting::whereIn('key',['manual_login_status','otp_login_status','social_login_status','google_login_status','facebook_login_status','apple_login_status','email_verification_status','phone_verification_status'
        ])->get(['key','value'])->toArray(), 'value', 'key');

        $firebase_otp_verification = BusinessSetting::where('key', 'firebase_otp_verification')->first()?->value??0;
        $phone = 1;
        $mail = 1;
        if(isset($login_settings['phone_verification_status']) && $login_settings['phone_verification_status'] == 1){
            $phone =0;
            if(!$firebase_otp_verification){
                $otp_interval_time= 60; //seconds
                $verification_data= DB::table('phone_verifications')->where('phone', $request['phone'])->first();

                if(isset($verification_data) &&  Carbon::parse($verification_data->updated_at)->DiffInSeconds() < $otp_interval_time){
                    $time= $otp_interval_time - Carbon::parse($verification_data->updated_at)->DiffInSeconds();
                    $errors = [];
                    array_push($errors, ['code' => 'otp', 'message' =>  translate('messages.please_try_again_after_').$time.' '.translate('messages.seconds')]);
                    return response()->json([
                        'errors' => $errors
                    ], 405);
                }

                $otp = rand(100000, 999999);
                if(env('APP_MODE') == 'test'){
                    $otp = '123456';
                }
                DB::table('phone_verifications')->updateOrInsert(['phone' => $request['phone']],
                    [
                        'token' => $otp,
                        'otp_hit_count' => 0,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);



                $published_status = 0;
                $payment_published_status = config('get_payment_publish_status');
                if (isset($payment_published_status[0]['is_published'])) {
                    $published_status = $payment_published_status[0]['is_published'];
                }

                if($published_status == 1){
                    $response = SmsGateway::send($request['phone'],$otp);
                }else{
                    $response = SMS_module::send($request['phone'],$otp);
                }

                $token = null;
                if(env('APP_MODE') != 'test' && $response !== 'success') {
                    $errors = [];
                    array_push($errors, ['code' => 'otp', 'message' => translate('messages.failed_to_send_sms')]);
                    return response()->json([
                        'errors' => $errors
                    ], 405);
                }
            }

        }elseif (isset($login_settings['email_verification_status']) && $login_settings['email_verification_status'] == 1){
            $mail =0;
            $otp = rand(100000, 999999);
            if(env('APP_MODE') == 'test'){
                $otp = '123456';
            }

            Cache::put('otp_' . $request->email, $otp, now()->addMinutes(15));
            // DB::table('email_verifications')->updateOrInsert(['email' => $request['email']],
            //     [
            //         'token' => $otp,
            //         'created_at' => now(),
            //         'updated_at' => now(),
            //     ]);

            try {
                $mailResponse= null;
                $mail_status = Helpers::get_mail_status('registration_otp_mail_status_user');

                if( config('mail.status') && $mail_status == '1') {
                    Mail::to($request['email'])->send(new EmailVerification($otp,$request->name));
                    $mailResponse='success';
                }
            }catch(\Exception $ex){
                info($ex->getMessage());
                $mailResponse=null;
            }
            $token = null;
            if(env('APP_MODE') != 'test' && $mailResponse !== 'success') {
                $errors = [];
                array_push($errors, ['code' => 'otp', 'message' => translate('messages.failed_to_send_mail')]);
                return response()->json([
                    'errors' => $errors
                ], 405);
            }
        }

        $user_email = null;

        return response()->json(['token' => null, 'is_phone_verified' => $phone, 'is_email_verified' => $mail, 'is_personal_info' => 1, 'is_exist_user' => null, 'login_type' => 'manual', 'email' => $request->email], 200);
    }

    public function login(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'login_type' => 'required|in:manual,otp,social',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => Helpers::error_processor($validator)], 403);
        }

        $login_settings = array_column(BusinessSetting::whereIn('key',['manual_login_status','otp_login_status','social_login_status','google_login_status','facebook_login_status','apple_login_status','email_verification_status','phone_verification_status'
        ])->get(['key','value'])->toArray(), 'value', 'key');

        if($request->login_type == 'manual'){
            $validator = Validator::make($request->all(), [
                'email_or_phone' => 'required',
                'password' => 'required|min:6',
                'field_type' => 'required|in:phone,email'
            ]);

            if ($validator->fails()) {
                return response()->json(['errors' => Helpers::error_processor($validator)], 403);
            }

            $request_data = [
                'email_or_phone' => $request->email_or_phone,
                'password' => $request->password,
                'guest_id' => $request->guest_id,
                'field_type' => $request->field_type,
            ];

            return $this->manual_login($request_data);
        }

        if($request->login_type == 'otp'){

            $validator = Validator::make($request->all(), [
                'phone' => 'required',
            ]);

            if ($validator->fails()) {
                return response()->json(['errors' => Helpers::error_processor($validator)], 403);
            }

            $phone = $request->phone;
            $user = User::query()
                ->when(filter_var($phone, FILTER_VALIDATE_EMAIL), function ($query) use ($phone) {
                    return $query->where('email', $phone);
                }, function ($query) use ($phone) {
                    return $query->where('phone', $phone);
                })
                ->first();

            if (!isset($user)) {
                return response()->json(['errors' => [
                    ['code' => 'not-found', 'message' => translate('Email_or_Phone_number_not_found!')]
                ]], 404);
            }


            if(isset($request['verified']) && $request['verified']){

                if (!$request->otp) {
                    $errors = [];
                    array_push($errors, ['code' => 'otp', 'message' => translate('messages.otp_id_required')]);
                    return response()->json([
                        'errors' => $errors
                    ], 403);
                }

                
                $request_data = [
                    'email' => $user->email,
                    'guest_id' => $request->guest_id,
                    'otp' =>$request->otp,
                    'verified' => $request['verified']??'default'
                ];

                return $this->otp_login($request_data);
            }

            $request_data = [
                'email' => $user->email,
                'name' => $user->full_name
            ];

            return $this->send_otp($request_data);
        }

        if($request->login_type == 'social'){

            $validator = Validator::make($request->all(), [
                'token' => 'required',
                'unique_id' => 'required',
                'email' => 'required_if:medium,google,facebook',
                'medium' => 'required|in:google,facebook,apple',
            ]);

            if ($validator->fails()) {
                return response()->json(['errors' => Helpers::error_processor($validator)], 403);
            }

            $client = new Client();
            $token = $request['token'];
            $email = $request['email'];
            $unique_id = $request['unique_id'];
            try {
                if ($request['medium'] == 'google') {
                    if($request->access_token  == 1){
                        $res = $client->request('GET',  'https://www.googleapis.com/oauth2/v3/userinfo?access_token=' . $token);
                    } else{
                        $res = $client->request('GET', 'https://www.googleapis.com/oauth2/v3/tokeninfo?id_token=' . $token);
                    }
                    $data = json_decode($res->getBody()->getContents(), true);
                } elseif ($request['medium'] == 'facebook') {
                    $res = $client->request('GET', 'https://graph.facebook.com/' . $unique_id . '?access_token=' . $token . '&&fields=name,email');
                    $data = json_decode($res->getBody()->getContents(), true);
                } elseif ($request['medium'] == 'apple') {
                    if($request->has('verified')){
                        $user = EmailVerifications::where('token', $unique_id)->first();
                        $data = [
                            'email' => $user->email
                        ];
                    }else{
                        $apple_login=\App\Models\BusinessSetting::where(['key'=>'apple_login'])->first();
                        if($apple_login){
                            $apple_login = json_decode($apple_login->value)[0];
                        }
                        $teamId = $apple_login->team_id;
                        $keyId = $apple_login->key_id;
                        if($request->platform == 'flutter_app'){
                            $sub = $apple_login->client_id_app;
                        }else{
                            $sub = $apple_login->client_id;
                        }
                        $aud = 'https://appleid.apple.com';
                        $iat = strtotime('now');
                        $exp = strtotime('+60days');
                        $keyContent = file_get_contents('storage/app/public/apple-login/'.$apple_login->service_file);

                        $token = JWT::encode([
                            'iss' => $teamId,
                            'iat' => $iat,
                            'exp' => $exp,
                            'aud' => $aud,
                            'sub' => $sub,
                        ], $keyContent, 'ES256', $keyId);

                        if($request->platform == 'flutter_web'){
                            $redirect_uri = $apple_login->redirect_url_flutter??'www.example.com/apple-callback';
                        }else{
                            $redirect_uri = $apple_login->redirect_url_react??'www.example.com/apple-callback';
                        }

                        $res = Http::asForm()->post('https://appleid.apple.com/auth/token', [
                            'grant_type' => 'authorization_code',
                            'code' => $unique_id,
                            'redirect_uri' => $redirect_uri,
                            'client_id' => $sub,
                            'client_secret' => $token,
                        ]);


                        $claims = explode('.', $res['id_token'])[1];
                        $data = json_decode(base64_decode($claims),true);
                    }

                }
            } catch (\Exception $e) {
                return response()->json(['error' => 'wrong credential.','message'=>$e->getMessage()],403);
            }
            if(!isset($claims)){

                if (strcmp($email, $data['email']) != 0 && (!isset($data['id']) && !isset($data['kid']))) {
                    return response()->json(['error' => translate('messages.email_does_not_match')],403);
                }
            }else{
                $email = $data['email'];
            }

            $request_data = [
                'token' => $token,
                'email' => $email,
                'unique_id' => $unique_id,
                'medium' => $request['medium'],
                'verified' => $request['verified']??'default',
                'guest_id' => $request['guest_id']
            ];

            return $this->social_login($data, $request_data);
        }


        $errors = [];
        array_push($errors, ['code' => 'auth-001', 'message' => translate('messages.User_Not_Found!!!')]);
        return response()->json([
            'errors' => $errors
        ], 401);

    }

    private function manual_login($request_data){

        if($request_data['field_type'] == 'email'){
            $data = [
                'email' => $request_data['email_or_phone'],
                'password' => $request_data['password'],
            ];
        }elseif ($request_data['field_type'] == 'phone'){
            $data = [
                'phone' => $request_data['email_or_phone'],
                'password' => $request_data['password'],
            ];
        }

        if (auth()->attempt($data)) {
            $token = null;
            if(!auth()->user()->status)
            {
                $errors = [];
                array_push($errors, ['code' => 'auth-003', 'message' => translate('messages.your_account_is_blocked')]);
                return response()->json([
                    'errors' => $errors
                ], 403);
            }
            $user = auth()->user();

            $this->refer_code_check($user);

            $is_personal_info = 0;
            if($user->f_name){
                $is_personal_info = 1;
            }

            $user->login_medium = 'manual';
            $user->save();

            $user_email = null;
            if($user->email){
                $user_email = $user->email;
            }

            if ($is_personal_info == 1 && auth()->loginUsingId($user->id)) {
                $token = auth()->user()->createToken('RestaurantCustomerAuth')->accessToken;
                if(isset($request_data['guest_id'])){
                    $this->check_guest_cart($user, $request_data['guest_id']);
                }
            }

            return response()->json(['token' => $token, 'is_phone_verified'=> 1, 'is_email_verified'=> 1, 'is_personal_info' => $is_personal_info, 'is_exist_user' => null, 'login_type' => 'manual', 'email' => $user_email], 200);
        } else {
            $errors = [];
            array_push($errors, ['code' => 'auth-001', 'message' => translate('User_credential_does_not_match')]);
            return response()->json([
                'errors' => $errors
            ], 401);
        }
    }
    private function otp_login($request_data){
        $data = DB::table('email_verifications')->where([
            'email' => $request_data['email'],
            'token' => $request_data['otp'],
        ])->first();

        if($data){
            if($request_data['verified'] == 'no'){
                $user = User::where('email', $request_data['email'])->first();
                $user->email = null;
                $user->save();

                $user = new User();
                $user->email = $request_data['email'];
                $user->password = bcrypt($request_data['email']);
                $user->save();

                dd($user);
            }
            $user = User::where('email', $request_data['email'])->first();

            if(!$user->status)
            {
                $errors = [];
                array_push($errors, ['code' => 'auth-003', 'message' => translate('messages.your_account_is_blocked')]);
                return response()->json([
                    'errors' => $errors
                ], 403);
            }

            $this->refer_code_check($user);

            $user->login_medium = 'otp';
            $user->is_email_verified = 1;
            $user->save();

            $is_personal_info = 0;
            if($user->f_name){
                $is_personal_info = 1;
            }


            DB::table('email_verifications')->where([
                'email' => $request_data['email'],
                'token' => $request_data['otp'],
            ])->delete();

            $token = null;
            if ($is_personal_info == 1 && auth()->loginUsingId($user->id)) {
                $token = auth()->user()->createToken('RestaurantCustomerAuth')->accessToken;
                if(isset($request_data['guest_id'])){
                    $this->check_guest_cart($user, $request_data['guest_id']);
                }
            }

            $user_email = null;
            if($user->email){
                $user_email = $user->email;
            }

            return response()->json(['token' => $token, 'is_phone_verified' => 0, 'is_email_verified' => 1, 'is_personal_info' => $is_personal_info, 'is_exist_user' => null, 'login_type' => 'otp', 'email' => $user_email], 200);
        }

        return response()->json([
            'message' => translate('OTP does not match')
        ], 404);


    }
    private function social_login($data, $request_data){

        $user = User::where('email', $data['email'])->first();
        $is_exist_user = null;

        if($user && $request_data['verified'] == 'default' && $user->is_email_verified == 0 && $user->is_from_pos == 0){
            $is_exist_user = $this->exist_user($user);
            $temporaryToken = null;
            if($request_data['medium'] == 'apple'){
                DB::table('email_verifications')->updateOrInsert(['email' => $data['email']],
                    [
                        'token' => $request_data['unique_id'],
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
            }
            $user_email = null;
            if($user->email){
                $user_email = $user->email;
            }
            return response()->json(['token' => $temporaryToken, 'is_phone_verified'=>1, 'is_email_verified'=>1, 'is_personal_info' => 1, 'is_exist_user' =>$is_exist_user, 'login_type' => 'social', 'email' => $user_email], 200);
        }

        if(($user && $request_data['verified'] == 'no') || (!$user && $request_data['verified'] == 'default')){

            if(strcmp($request_data['email'], $data['email']) === 0){
                if($request_data['medium'] != 'apple'){
                    if(!isset($data['id']) && !isset($data['kid']) &&  !isset($data['sub']) ){
                        return response()->json(['error' => 'wrong credential.'],403);
                    }
                    $pk = isset($data['id'])?$data['id']:(  isset($data['kid']) ?  $data['kid'] : $data['sub'] );
                }
                try {
                    if(($user && $request_data['verified'] == 'no')){
                        $user->email = null;
                        $user->save();
                    }

                    $user = new User();
                    $user->email = $data['email'];
                    $user->login_medium = $request_data['medium'];
                    $user->temp_token = $request_data['unique_id'];
                    if($request_data['medium'] != 'apple'){
                        $user->social_id = $pk;
                    }
                    $user->save();
                } catch (\Throwable $e) {
                    return response()->json(['error' => 'wrong credential.','message'=>$e->getMessage()],403);
                }
            }
        }

        if($request_data['medium'] == 'apple'){
            DB::table('email_verifications')->where([
                'email' => $data['email'],
                'token' => $request_data['unique_id'],
            ])->delete();
        }

        if (auth()->loginUsingId($user->id)) {
            if(!auth()->user()->status)
            {
                $errors = [];
                array_push($errors, ['code' => 'auth-003', 'message' => translate('messages.your_account_is_blocked')]);
                return response()->json([
                    'errors' => $errors
                ], 403);
            }
            $this->refer_code_check($user);


            $user->login_medium = $request_data['medium'];
            $user->is_email_verified = 1;
            $user->save();

            $is_personal_info = 0;
            if($user->f_name){
                $is_personal_info = 1;
            }

            $token = null;
            if ($is_personal_info == 1 && auth()->loginUsingId($user->id)) {
                $token = auth()->user()->createToken('RestaurantCustomerAuth')->accessToken;
                if(isset($request_data['guest_id'])){
                    $this->check_guest_cart($user, $request_data['guest_id']);
                }
            }
            $user_email = null;
            if($user->email){
                $user_email = $user->email;
            }
            return response()->json(['token' => $token, 'is_phone_verified'=>1, 'is_email_verified'=>1, 'is_personal_info' => $is_personal_info, 'is_exist_user' =>$is_exist_user, 'login_type' => 'social', 'email' => $user_email], 200);
        } else {
            $errors = [];
            array_push($errors, ['code' => 'auth-001', 'message' => 'Unauthorized.']);
            return response()->json([
                'errors' => $errors
            ], 401);
        }
    }

    private function verification_check($request_data){
        $firebase_otp_verification = BusinessSetting::where('key', 'firebase_otp_verification')->first()?->value??0;
        if(!$firebase_otp_verification)
        {
            $otp_interval_time= 60; //seconds

            $verification_data = DB::table('email_verifications')->where('email', $request_data['email'])->first();

            if(isset($verification_data) &&  Carbon::parse($verification_data->updated_at)->DiffInSeconds() < $otp_interval_time){

                $time= $otp_interval_time - Carbon::parse($verification_data->updated_at)->DiffInSeconds();
                $errors = [];
                array_push($errors, ['code' => 'otp', 'message' =>  translate('messages.please_try_again_after_').$time.' '.translate('messages.seconds')]);

                return $errors;
            }

            $otp = rand(100000, 999999);
            if(env('APP_MODE') == 'test'){
                $otp = '123456';
            }
            DB::table('email_verifications')->updateOrInsert(
                ['email' => $request_data['email']],
                [
                    'token' => $otp,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

            $response= null;


            // $published_status = 0;
            // $payment_published_status = config('get_payment_publish_status');
            // if (isset($payment_published_status[0]['is_published'])) {
            //     $published_status = $payment_published_status[0]['is_published'];
            // }

            // if($published_status == 1){
            //     $response = SmsGateway::send($request_data['phone'],$otp);
            // }else{
            //     $response = SMS_module::send($request_data['phone'],$otp);
            // }

            try {

                $mail_status = Helpers::get_mail_status('registration_otp_mail_status_user');

                if (config('mail.status') && $mail_status == '1') {
                    Mail::to($request_data['email'])->send(new LoginVerification($otp, $request_data['name']));
                    $response = 'success';
                }
            } catch (\Exception $ex) {
                info($ex->getMessage());
               
            }

            if((env('APP_MODE') != 'test') && $response !== 'success')
            {
                $errors = [];
                array_push($errors, ['code' => 'otp', 'message' => translate('messages.failed_to_send_sms')]);
                return $errors;
            }
        }
        return true;
    }

    private function refer_code_check($user){
        if($user->ref_code == null && isset($user->id)){
            $ref_code = Helpers::generate_referer_code($user);
            DB::table('users')->where('id', $user->id)->update(['ref_code' => $ref_code]);
        }
        return true;
    }

    private function check_guest_cart($user, $guest_id){
        if($guest_id  && isset($user->id)){

            $userStoreIds = Cart::where('user_id', $guest_id)
                ->join('items', 'carts.item_id', '=', 'items.id')
                ->pluck('items.store_id')
                ->toArray();

            Cart::where('user_id', $user->id)
                ->whereHas('item', function ($query) use ($userStoreIds) {
                    $query->whereNotIn('store_id', $userStoreIds);
                })
                ->delete();

            Cart::where('user_id', $guest_id)->update(['user_id' => $user->id, 'is_guest' => 0]);
        }
        return true;
    }

    private function exist_user($exist_user){
        return [
            'id' => $exist_user->id,
            'name' => $exist_user->f_name.' '.$exist_user->l_name,
            'image' => $exist_user->image_full_url
        ];
    }

    public function update_info(Request $request)
    {
        $rules = [
            'name' => 'required',
            'login_type' => 'required|in:otp,social,manual',
            'phone' => 'required|min:9|max:14',
            'email' => 'required|email',
        ];

        if ($request->login_type == 'social') {
            $rules['phone'] .= '|unique:users,phone';
        }

        if ($request->login_type == 'otp' || $request->login_type == 'manual') {
            $rules['email'] .= '|unique:users,email';
        }

        $validator = Validator::make($request->all(), $rules);

        if ($validator->fails()) {
            return response()->json(['errors' => Helpers::error_processor($validator)], 403);
        }

        $ref_by= null ;
        //Save point to refeer
        if ($request->ref_code) {
            $ref_status = BusinessSetting::where('key', 'ref_earning_status')->first()->value;
            if ($ref_status != '1') {
                return response()->json(['errors' => Helpers::error_formater('ref_code', translate('messages.referer_disable'))], 403);
            }

            $referar_user = User::where('ref_code', '=', $request->ref_code)->first();
            if (!$referar_user || !$referar_user->status) {
                return response()->json(['errors' => Helpers::error_formater('ref_code', translate('messages.referer_code_not_found'))], 405);
            }

            if (WalletTransaction::where('reference', $request->phone)->first()) {
                return response()->json(['errors' => Helpers::error_formater('phone', translate('Referrer code already used'))], 203);
            }


            $notification_data = [
                'title' => translate('messages.Your_referral_code_is_used_by') . ' ' . $request->f_name . ' ' . $request->l_name,
                'description' => translate('Be prepare to receive when they complete there first purchase'),
                'order_id' => 1,
                'image' => '',
                'type' => 'referral_code',
            ];

            if (Helpers::getNotificationStatusData('customer', 'customer_new_referral_join', 'push_notification_status') && $referar_user?->cm_firebase_token) {
                Helpers::send_push_notif_to_device($referar_user?->cm_firebase_token, $notification_data);
                DB::table('user_notifications')->insert([
                    'data' => json_encode($notification_data),
                    'user_id' => $referar_user?->id,
                    'created_at' => now(),
                    'updated_at' => now()
                ]);
            }


            $ref_by = $referar_user->id;
        }

        $name = $request->name;
        $nameParts = explode(' ', $name, 2);
        $firstName = $nameParts[0];
        $lastName = $nameParts[1] ?? '';

        if($request->login_type == 'otp' || $request->login_type == 'manual'){
            $user = User::where(['phone' => $request->phone])->first();
        }else{
            $user = User::where(['email' => $request->email])->first();
        }
        if(!$user){
            return response()->json([
                'message' => translate('messages.user_not_found')
            ], 404);
        }
        if($user->f_name){
            return response()->json([
                'message' => translate('messages.already_exists')
            ], 403);
        }
        $user->f_name = $firstName;
        $user->l_name = $lastName;
        $user->email = $request->email??$user->email;
        $user->phone = $request->phone??$user->phone;
        $user->ref_by = $ref_by;
        $user->save();
        $token = null;
        if (auth()->loginUsingId($user->id)) {
            $token = auth()->user()->createToken('RestaurantCustomerAuth')->accessToken;

            if(isset($request['guest_id'])){
                $this->check_guest_cart($user, $request['guest_id']);
            }
        }

        $user_email = null;
        if($user->email){
            $user_email = $user->email;
        }
        return response()->json(['token' => $token, 'is_phone_verified'=>1, 'is_email_verified'=>1, 'is_personal_info' => 1, 'is_exist_user' => null, 'login_type' => $request->login_type, 'email' => $user_email], 200);
    }

    private function send_otp($request_data){
        $verification_check = $this->verification_check($request_data);
        if(is_array($verification_check))
        {
            return response()->json([
                'errors' => $verification_check
            ], 405);
        }
        $temporaryToken = null;
        return response()->json(['token' => $temporaryToken, 'is_phone_verified'=>0, 'is_email_verified'=>1, 'is_personal_info' => 1, 'is_exist_user' => null, 'login_type' => 'otp', 'email' => null], 200);
    }

    #handshake
    public function customerLoginFromDrivemond(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'phone' => 'required',
            'token' => 'required|min:6'
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => Helpers::error_processor($validator)], 403);
        }

        $user = User::where('phone', $request->phone)->first();

        if (Helpers::checkSelfExternalConfiguration()) {
            $driveMondBaseUrl = ExternalConfiguration::where('key', 'drivemond_base_url')->first()->value;
            $driveMondToken = ExternalConfiguration::where('key', 'drivemond_token')->first()->value;
            $systemSelfToken = ExternalConfiguration::where('key', 'system_self_token')->first()->value;
            if (!$user){
                $response = Http::withToken($request->token)->post($driveMondBaseUrl . '/api/customer/get-data',
                    [
                        'token' => $driveMondToken,
                        'external_base_url' => url('/'),
                        'external_token' => $systemSelfToken,
                    ]);
                if ($response->successful()) {
                    $drivemondCustomerResponse = $response->json();
                    if ($drivemondCustomerResponse['status']) {
                        $drivemondCustomer = $drivemondCustomerResponse['data'];
                        if (User::where('email', $drivemondCustomer['email'])->first()) {
                            $errors = [];
                            array_push($errors, ['code' => 'email_unique_402', 'message' => translate('messages.Email already exists, Please update mart email and switch 6ammart')]);
                            return response()->json([
                                'errors' => $errors
                            ], 403);
                        }
                        $user = User::create([
                            'f_name' => $drivemondCustomer['first_name'],
                            'l_name' => $drivemondCustomer['last_name'],
                            'email' => $drivemondCustomer['email'],
                            'phone' => $drivemondCustomer['phone'],
                            'password' => $drivemondCustomer['password'],
                            'is_phone_verified' => $drivemondCustomer['phone_verified_at'] ? 1 : 0,
                        ]);
                        $user->ref_code = Helpers::generate_referer_code($user);
                        $user->save();
                    } else {
                        $drivemondCustomer = $drivemondCustomerResponse['data'];
                        if ($drivemondCustomer['error_code'] == 402) {
                            $errors = [];
                            array_push($errors, ['code' => 'drivemond_external_configuration_402', 'message' => translate('messages.Drivemond external authentication failed')]);
                            return response()->json([
                                'errors' => $errors
                            ], 403);
                        }
                    }

                } else {
                    $errors = [];
                    array_push($errors, ['code' => 'drivemond_user_404', 'message' => translate('messages.Drivemond user not found')]);
                    return response()->json([
                        'errors' => $errors
                    ], 403);
                }
            }


            if (Auth::loginUsingId($user->id)) {
                $token = auth()->user()->createToken('RestaurantCustomerAuth')->accessToken;
                if (!auth()->user()->status) {
                    $errors = [];
                    array_push($errors, ['code' => 'auth-003', 'message' => translate('messages.your_account_is_blocked')]);
                    return response()->json([
                        'errors' => $errors
                    ], 403);
                }
                $user = auth()->user();
                if ($user->ref_code == null && isset($user->id)) {
                    $ref_code = Helpers::generate_referer_code($user);
                    DB::table('users')->where('phone', $user->phone)->update(['ref_code' => $ref_code]);
                }
                return response()->json(['token' => $token, 'is_phone_verified' => auth()->user()->is_phone_verified], 200);
            }

            $errors = [];
            array_push($errors, ['code' => 'auth-001', 'message' => translate('messages.Unauthorized')]);
            return response()->json([
                'errors' => $errors
            ], 401);

        }
        $errors = [];
        array_push($errors, ['code' => 'external_config_404', 'message' => translate('messages.External configuration not found')]);
        return response()->json([
            'errors' => $errors
        ], 403);
    }
}