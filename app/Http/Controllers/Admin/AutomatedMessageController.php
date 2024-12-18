<?php

namespace App\Http\Controllers\Admin;

use App\Models\Translation;
use Illuminate\Http\Request;
use App\CentralLogics\Helpers;
use App\Models\AutomatedMessage;
use App\Http\Controllers\Controller;
use Brian2694\Toastr\Facades\Toastr;

class AutomatedMessageController extends Controller
{

    public function store(Request $request)
    {
        $request->validate([
            'message>*'=>'max:255',
            'message.0' => 'required',
        ],[
            'message.0.required'=>translate('default_message_is_required'),
        ]);
        $automatedMessage = new AutomatedMessage();
        $automatedMessage->message = $request->message[array_search('default', $request->lang)];
        $automatedMessage->save();

        Helpers::add_or_update_translations(request: $request, key_data:'message' , name_field:'message' , model_name: 'AutomatedMessage' ,data_id: $automatedMessage->id,data_value: $automatedMessage->message);
        Toastr::success(translate('messages.Automated_message_added_successfully'));
        return back();
    }
    public function destroy($automatedMessage)
    {
        $automatedMessage = AutomatedMessage::findOrFail($automatedMessage);
        $automatedMessage?->translations()?->delete();
        $automatedMessage?->delete();
        Toastr::success(translate('messages.Automated_message_deleted_successfully'));
        return back();
    }

    public function status(Request $request)
    {
        $automatedMessage = AutomatedMessage::findOrFail($request->id);
        $automatedMessage->status = $request->status;
        $automatedMessage->save();
        Toastr::success(translate('messages.status_updated'));
        return back();
    }
    public function update(Request $request)
    {
        $request->validate([
            'message.*' => 'max:255',
            'message.0' => 'required',
        ],[
            'message.0.required'=>translate('default_message_is_required'),
        ]);
        $automatedMessage = AutomatedMessage::findOrFail($request->message_id);
        $automatedMessage->message = $request->message[array_search('default', $request->lang1)];
        $automatedMessage?->save();


        $default_lang = str_replace('_', '-', app()->getLocale());
        foreach ($request->lang1 as $index => $key) {
            if($default_lang == $key && !($request->message[$index])){
                if ($key != 'default') {
                    Translation::updateOrInsert(
                        [
                            'translationable_type' => 'App\Models\AutomatedMessage',
                            'translationable_id' => $automatedMessage->id,
                            'locale' => $key,
                            'key' => 'message'
                        ],
                        ['value' => $automatedMessage->message]
                    );
                }
            }else{
                if ($request->message[$index] && $key != 'default') {
                    Translation::updateOrInsert(
                        [
                            'translationable_type' => 'App\Models\AutomatedMessage',
                            'translationable_id' => $automatedMessage->id,
                            'locale' => $key,
                            'key' => 'message'
                        ],
                        ['value' => $request->message[$index]]
                    );
                }
            }
        }
        Toastr::success(translate('Automated_message_updated_successfully'));
        return back();
    }
}
