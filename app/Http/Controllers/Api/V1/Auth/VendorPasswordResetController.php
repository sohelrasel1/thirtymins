<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\CentralLogics\Helpers;
use App\Http\Controllers\Controller;
use Carbon\CarbonInterval;
use App\Models\BusinessSetting;
use App\Models\Vendor;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rules\Password;

class VendorPasswordResetController extends Controller
{
    public function reset_password_request(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => Helpers::error_processor($validator)], 403);
        }

        $email = $request['email'];
        $vendor = Vendor::query()
            ->when(filter_var($email, FILTER_VALIDATE_EMAIL), function ($query) use ($email) {
                return $query->where('email', $email);
            }, function ($query) use ($email) {
                return $query->where('phone', $email);
            })
            ->first();

        if (isset($vendor)) {
            $token = rand(1000,9999);
            DB::table('password_resets')->updateOrInsert([
                'email' => $vendor['email'],
                'token' => $token,
                'created_at' => now(),
            ]);
            if (config('mail.status')) {
                Mail::to($vendor['email'])->send(new \App\Mail\PasswordResetMail($token));
            }
            return response()->json(['message' => 'Email sent successfully.'], 200);
        }
        return response()->json(['errors' => [
            ['code' => 'not-found', 'message' => 'Email or Phone not found!']
        ]], 404);
    }

    public function verify_token(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email' => [
                'required', // The field is required
                function ($attribute, $value, $fail) {
                    if (
                        !filter_var($value, FILTER_VALIDATE_EMAIL) &&
                        !preg_match('/^([0-9\s\-\+\(\)]*)$/', $value)
                    ) {
                        $fail('The ' . $attribute . ' must be a valid phone number or email address.');
                    }
                },
            ],
            'reset_token'=> 'required'
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => Helpers::error_processor($validator)], 403);
        }

        $email = $request->email;
        $vendor = Vendor::query()
            ->when(filter_var($email, FILTER_VALIDATE_EMAIL), function ($query) use ($email) {
                return $query->where('email', $email);
            }, function ($query) use ($email) {
                return $query->where('phone', $email);
            })
            ->first();

        if (!isset($vendor)) {
            return response()->json(['errors' => [
                ['code' => 'not-found', 'message' => translate('Email_or_Phone_number_not_found!')]
            ]], 404);
        }

        $data = DB::table('password_resets')->where(['token' => $request['reset_token'], 'email' => $vendor->email])->first();
        if (isset($data) || (env('APP_MODE')=='demo'&& $request['reset_token'] == '123456' )) {
            return response()->json(['message'=>translate("OTP found, you can proceed")], 200);
        } else{
            // $otp_hit = BusinessSetting::where('key', 'max_otp_hit')->first();
            // $max_otp_hit =isset($otp_hit) ? $otp_hit->value : 5 ;
            $max_otp_hit = 5;
            // $otp_hit_time = BusinessSetting::where('key', 'max_otp_hit_time')->first();
            // $max_otp_hit_time = isset($otp_hit_time) ? $otp_hit_time->value : 30 ;
            $max_otp_hit_time = 60; // seconds
            $temp_block_time = 600; // seconds
            $verification_data = DB::table('password_resets')->where('email', $vendor->email)->first();


            if(isset($verification_data)){
                $time= $temp_block_time - Carbon::parse($verification_data->temp_block_time)->DiffInSeconds();

                if(isset($verification_data->temp_block_time ) && Carbon::parse($verification_data->temp_block_time)->DiffInSeconds() <= $temp_block_time){
                    $time= $temp_block_time - Carbon::parse($verification_data->temp_block_time)->DiffInSeconds();

                    $errors = [];
                    array_push($errors, ['code' => 'otp_block_time', 'message' => translate('messages.please_try_again_after_').CarbonInterval::seconds($time)->cascade()->forHumans()
                ]);
                return response()->json([
                    'errors' => $errors
                ], 405);
                }

                if($verification_data->is_temp_blocked == 1 && Carbon::parse($verification_data->created_at)->DiffInSeconds() >= $max_otp_hit_time){
                    DB::table('password_resets')->updateOrInsert(
                        ['email' => $vendor->email],
                        [
                            'otp_hit_count' => 0,
                            'is_temp_blocked' => 0,
                            'temp_block_time' => null,
                            'created_at' => now(),
                        ]);
                    }

                if($verification_data->otp_hit_count >= $max_otp_hit &&  Carbon::parse($verification_data->created_at)->DiffInSeconds() < $max_otp_hit_time &&  $verification_data->is_temp_blocked == 0){

                    DB::table('password_resets')->updateOrInsert(
                        ['email' => $vendor->email],
                        [
                        'is_temp_blocked' => 1,
                        'temp_block_time' => now(),
                        'created_at' => now(),
                        ]);
                    $errors = [];
                    array_push($errors, ['code' => 'otp_temp_blocked', 'message' => translate('messages.Too_many_attemps') ]);
                    return response()->json([
                        'errors' => $errors
                    ], 405);
                }
            }
            DB::table('password_resets')->updateOrInsert(
                ['email' => $vendor->email],
            [
                'otp_hit_count' => DB::raw('otp_hit_count + 1'),
                'created_at' => now(),
                'temp_block_time' => null,
            ]);
        }
        return response()->json(['errors' => [
            ['code' => 'reset_token', 'message' => 'Invalid OTP.']
        ]], 400);
    }

    public function reset_password_submit(Request $request)
    {
        $validator = Validator::make($request->all(), [
                'email' => [
                    'required', // The field is required
                    function ($attribute, $value, $fail) {
                        if (
                            !filter_var($value, FILTER_VALIDATE_EMAIL) &&
                            !preg_match('/^([0-9\s\-\+\(\)]*)$/', $value)
                        ) {
                            $fail('The ' . $attribute . ' must be a valid phone number or email address.');
                        }
                    },
                ],
            'reset_token'=> 'required',
            'password' => ['required', Password::min(8)->mixedCase()->letters()->numbers()->symbols()->uncompromised()],
            'confirm_password'=> 'required|same:password',
        ],

        [
            'password.required' => translate('The password is required'),
            'password.min_length' => translate('The password must be at least :min characters long'),
            'password.mixed' => translate('The password must contain both uppercase and lowercase letters'),
            'password.letters' => translate('The password must contain letters'),
            'password.numbers' => translate('The password must contain numbers'),
            'password.symbols' => translate('The password must contain symbols'),
            'password.uncompromised' => translate('The password is compromised. Please choose a different one'),
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => Helpers::error_processor($validator)], 403);
        }
        if(env('APP_MODE')=='demo') {
            if ($request['reset_token'] != '123456') {
                return response()->json(['errors' => [
                    ['code' => 'invalid', 'message' => trans('messages.invalid_otp')]
                ]], 400);
            }
            if ($request['password'] == $request['confirm_password']) {
                DB::table('vendors')->where(['email' => $request->email])->update([
                    'password' => bcrypt($request['confirm_password'])
                ]);
                DB::table('password_resets')->where(['token' => $request['reset_token']])->delete();
                return response()->json(['message' => translate('Password changed successfully.')], 200);
            }
            return response()->json(['errors' => [
                ['code' => 'mismatch', 'message' => translate('messages.password_mismatch')]
            ]], 401);
        }

        $email = $request->email;
        $vendor = Vendor::query()
            ->when(filter_var($email, FILTER_VALIDATE_EMAIL), function ($query) use ($email) {
                return $query->where('email', $email);
            }, function ($query) use ($email) {
                return $query->where('phone', $email);
            })
            ->first();

        if (!isset($vendor)) {
            return response()->json(['errors' => [
                ['code' => 'not-found', 'message' => translate('Email_or_Phone_number_not_found!')]
            ]], 404);
        }

        $data = DB::table('password_resets')->where(['email' => $vendor->email, 'token' => $request['reset_token']])->first();
        if (isset($data)) {
            if ($request['password'] == $request['confirm_password']) {
                DB::table('vendors')->where(['email' => $data->email])->update([
                    'password' => bcrypt($request['confirm_password'])
                ]);
                DB::table('password_resets')->where(['token' => $request['reset_token']])->delete();
                return response()->json(['message' => translate('Password changed successfully.')], 200);
            }
            return response()->json(['errors' => [
                ['code' => 'mismatch', 'message' => translate('messages.password_mismatch')]
            ]], 401);
        }
        return response()->json(['errors' => [
            ['code' => 'invalid', 'message' => translate('messages.invalid_otp')]
        ]], 400);
    }
}