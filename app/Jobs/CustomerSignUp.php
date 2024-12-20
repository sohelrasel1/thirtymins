<?php

namespace App\Jobs;

use App\CentralLogics\Helpers;
use App\Mail\CustomerRegistration;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;

class CustomerSignUp
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Create a new job instance.
     */
    public function __construct(public User $user)
    {
        //
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {

        try {
            $notification_status = Helpers::getNotificationStatusData('customer', 'customer_registration', 'mail_status');
            if ($notification_status && config('mail.status') && $this->user->email && Helpers::get_mail_status('registration_mail_status_user') == '1') {
                Mail::to($this->user->email)->send(new CustomerRegistration($this->user->full_name));
            }
        } catch (\Exception $ex) {
            info('Failed tp welcome signup email: error is: ' . $ex->getMessage(), [$ex]);
        }
    }
}
