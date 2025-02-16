<?php

namespace App\Http\Controllers;

use App\Models\Lead;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class WebhookController extends Controller
{
    public function angi_webhook(Request $request)
    {
        $data = json_decode($request->getContent(), true);

        //create new Lead
        // 'ALAccountId' => '28134960', = GS Construction Angi ID
        $lead_data = collect();
        // $string = $data['Description'];

        $inputs = ['phone', 'name', 'address', 'email', 'message'];

        foreach ($inputs as $input) {
            if ($input === 'name') {
                //concat first and last name as $name
                $lead_data[$input] = $data['FirstName'].' '.$data['LastName'];
            } elseif ($input === 'address') {
                //concat first and last name as $name
                $lead_data[$input] = $data['PostalAddress']['AddressFirstLine'].', '.$data['PostalAddress']['City'].', '.$data['PostalAddress']['State'].' '.$data['PostalAddress']['PostalCode'];
            } elseif ($input === 'phone') {
                //concat first and last name as $name
                $lead_data[$input] = $data['PhoneNumber'];
            } elseif ($input === 'email') {
                //concat first and last name as $name
                $lead_data[$input] = $data['Email'];
                $lead_data['reply_to_email'] = $data['Email'];
            } elseif ($input === 'message') {
                //concat first and last name as $name
                $lead_data[$input] = $data['Description'];
            }

            // else{
            //     $lead_data[$input] = $data[$input];
            // }

            $lead_data['date'] = now();
            $lead_data['data'] = $data;
        }

        $digitsOnly = preg_replace('/\D/', '', $lead_data['phone']);

        // Check if the result is exactly 10 digits long
        if (strlen($digitsOnly) == 10) {
            $lead_data['phone'] = $digitsOnly;
        } else {
            $lead_data['phone'] = null; // Return null if the result is not exactly 10 digits
        }

        // dd($lead_data);

        //find OR create Client
        $user = User::where('cell_phone', $lead_data['phone'])->first();
        // dd($user);

        if ($user) {
            $user_id = $user->id;
        } else {
            //create from data
            if (isset($lead_data['phone']) && isset($lead_data['email']) && isset($lead_data['name'])) {
                $name = $lead_data['name'];
                $nameParts = explode(' ', $name);
                $lastName = array_pop($nameParts);
                $firstName = implode(' ', $nameParts);

                $user = User::create([
                    'first_name' => $firstName,
                    'last_name' => $lastName,
                    'email' => $lead_data['email'],
                    'cell_phone' => $lead_data['phone'],
                ]);

                $user_id = $user->id;
            } else {
                $user_id = null;
            }
        }

        $lead = Lead::create([
            'date' => $lead_data['date'],
            'origin' => 'Angi',
            'user_id' => $user_id,
            'lead_data' => $lead_data,
            // 'belongs_to_vendor_id' => auth()->user()->primary_vendor_id,
            // 'created_by_user_id' => auth()->user()->id
            // 'ALAccountId' => '28134960', = GS Construction Angi ID
            'belongs_to_vendor_id' => 1,
            'created_by_user_id' => 1,
        ]);

        //2024-12-30 MOVE TO Observer because it repeats and always gets called with creating a Lead
        $lead->statuses()->create([
            'title' => 'New',
            'belongs_to_vendor_id' => $lead->belongs_to_vendor_id,
            'created_at' => $lead_data['date'],
        ]);

        Log::channel('angi_webhook_results')->info($lead_data);

        return response()->json(['message' => 'success'], 200);
    }
}
