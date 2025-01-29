<?php

namespace App\Http\Controllers;

use App\Models\CompanyEmail;
use App\Models\Lead;
use App\Models\User;
use App\Services\ChatGPTService;
use Carbon\Carbon;
use Exception;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Microsoft\Graph\Graph;
use Microsoft\Graph\Model\MailFolder;
use Microsoft\Graph\Model\Message;

class LeadController extends Controller
{
    protected $chatGPTService;

    public function __construct(ChatGPTService $chatGPTService)
    {
        $this->chatGPTService = $chatGPTService;
    }

    // public function ms_graph_auth($company_emails)
    // {
    //     foreach($company_emails as $company_email){
    //         //check if access_token is expired, if so get new access_token and refresh_token
    //         // app(\App\Http\Controllers\PrintReportContoller::class)->getPrintReport();
    //         try{
    //             $guzzle = new Client();
    //             $url = 'https://login.microsoftonline.com/' . env('MS_GRAPH_TENANT_ID') . '/oauth2/v2.0/token';
    //             $email_account_tokens = json_decode($guzzle->post($url, [
    //                 'form_params' => [
    //                     'client_id' => env('MS_GRAPH_CLIENT_ID'),
    //                     'scope' => env('MS_GRAPH_USER_SCOPES'),
    //                     'refresh_token' => $company_email->api_json['refresh_token'],
    //                     'redirect_uri' => env('MS_GRAPH_REDIRECT_URI'),
    //                     'grant_type' => 'refresh_token',
    //                     'client_secret' => env('MS_GRAPH_SECRET_ID'),
    //                 ],
    //             ])->getBody()->getContents());
    //         }catch(RequestException $e){
    //             if($e->hasResponse()) {
    //                 $response = $e->getResponse();
    //                 $responseBody = $response->getBody()->getContents();
    //                 $error = $responseBody;
    //             }else{
    //                 $error = $e->getMessage();
    //             }

    //             $company_email->api_json += ['errors' => json_decode($error, true)];
    //             $company_email->save();

    //             //add to $company_email json ('api') errors
    //             Log::channel('company_emails_login_error')->error($error);
    //             continue;
    //         }

    //         //json
    //         $api_data = $company_email->api_json;
    //         $api_data['access_token'] = $email_account_tokens->access_token;
    //         $api_data['refresh_token'] = $email_account_tokens->refresh_token;

    //         $company_email->update([
    //             'api_json' => $api_data,
    //         ]);

    //         $this->ms_graph = new Graph();
    //         $this->ms_graph->setAccessToken($company_email->api_json['access_token']);

    //         // FOLDER name Test etc
    //         // $user_hive_folder =
    //         //     $this->ms_graph->createCollectionRequest("GET", "/me/mailFolders?filter=displayName eq 'Home Depot Rebates'&expand=childFolders")
    //         //         ->setReturnType(MailFolder::class)
    //         //         ->execute();
    //         // dd($user_hive_folder);

    //         if(env('APP_ENV') == 'production'){
    //             //6-12-2023 6-27-2023 6-6-2024 exclude ones already read ... save $message->getId() to a (temp) database/log file?...
    //             $messages_inbox = $this->ms_graph->createCollectionRequest("GET", "/me/mailFolders/inbox/messages?top=20")
    //                 ->setReturnType(Message::class)
    //                 ->execute();

    //             $messages_inbox_retry = $this->ms_graph->createCollectionRequest("GET", "/me/mailFolders/" . $company_email->api_json['hive_folder'] . "/childFolders/" . $company_email->api_json['hive_folder_retry'] . "/messages?top=20")
    //                 ->setReturnType(Message::class)
    //                 ->execute();

    //             $messages = Arr::collapse([$messages_inbox, $messages_inbox_retry]);
    //         }else{
    //             //if array key exists
    //             if(isset($company_email->api_json['hive_folder_test'])){
    //                 $messages = $this->ms_graph->createCollectionRequest("GET", "/me/mailFolders/" . $company_email->api_json['hive_folder'] . "/childFolders/" . $company_email->api_json['hive_folder_test'] . "/messages?top=20")
    //                 ->setReturnType(Message::class)
    //                 ->execute();
    //             }else{
    //                 continue;
    //             }
    //         }

    //         return $messages;
    //     }
    // }

    public function leads_in_email()
    {
        $company_emails = CompanyEmail::withoutGlobalScopes()->whereNotNull('api_json->user_id')->get();
        // $messages = $this->ms_graph_auth($company_emails);
        foreach ($company_emails as $company_email) {
            //check if access_token is expired, if so get new access_token and refresh_token
            // app(\App\Http\Controllers\PrintReportContoller::class)->getPrintReport();
            try {
                $guzzle = new Client;
                $url = 'https://login.microsoftonline.com/'.env('MS_GRAPH_TENANT_ID').'/oauth2/v2.0/token';
                $email_account_tokens = json_decode($guzzle->post($url, [
                    'form_params' => [
                        'client_id' => env('MS_GRAPH_CLIENT_ID'),
                        'scope' => env('MS_GRAPH_USER_SCOPES'),
                        'refresh_token' => $company_email->api_json['refresh_token'],
                        'redirect_uri' => env('MS_GRAPH_REDIRECT_URI'),
                        'grant_type' => 'refresh_token',
                        'client_secret' => env('MS_GRAPH_SECRET_ID'),
                    ],
                ])->getBody()->getContents());
            } catch (RequestException $e) {
                if ($e->hasResponse()) {
                    $response = $e->getResponse();
                    $responseBody = $response->getBody()->getContents();
                    $error = $responseBody;
                } else {
                    $error = $e->getMessage();
                }

                $company_email->api_json += ['errors' => json_decode($error, true)];
                $company_email->save();

                //add to $company_email json ('api') errors
                Log::channel('company_emails_login_error')->error($error);

                continue;
            }

            //json
            $api_data = $company_email->api_json;
            $api_data['access_token'] = $email_account_tokens->access_token;
            $api_data['refresh_token'] = $email_account_tokens->refresh_token;

            $company_email->update([
                'api_json' => $api_data,
            ]);

            $this->ms_graph = new Graph;
            $this->ms_graph->setAccessToken($company_email->api_json['access_token']);

            // FOLDER name Test etc
            // $user_hive_folder =
            //     $this->ms_graph->createCollectionRequest("GET", "/me/mailFolders?filter=displayName eq 'HIVE_CONTRACTORS_RECEIPTS'&expand=childFolders")
            //         ->setReturnType(MailFolder::class)
            //         ->execute();
            // dd($user_hive_folder);

            if (env('APP_ENV') == 'production') {
                //6-12-2023 6-27-2023 6-6-2024 exclude ones already read ... save $message->getId() to a (temp) database/log file?...
                $messages_inbox = $this->ms_graph->createCollectionRequest('GET', '/me/mailFolders/inbox/messages?top=20')
                    ->setReturnType(Message::class)
                    ->execute();

                $messages_inbox_retry = $this->ms_graph->createCollectionRequest('GET', '/me/mailFolders/'.$company_email->api_json['hive_folder'].'/childFolders/'.$company_email->api_json['hive_folder_retry'].'/messages?top=20')
                    ->setReturnType(Message::class)
                    ->execute();

                $messages = Arr::collapse([$messages_inbox, $messages_inbox_retry]);
            } else {
                //if array key exists
                if (isset($company_email->api_json['hive_folder_test'])) {
                    $messages = $this->ms_graph->createCollectionRequest('GET', '/me/mailFolders/'.$company_email->api_json['hive_folder'].'/childFolders/'.$company_email->api_json['hive_folder_test'].'/messages?top=20')
                    // ->addHeaders(["Prefer" => "outlook.body-content-type=\"text\""])
                        ->setReturnType(Message::class)
                        ->execute();
                } else {
                    continue;
                }
            }
            // dd($messages);

            foreach ($messages as $key => $message) {
                if (! isset($message->getToRecipients()[0])) {
                    continue;
                }

                $string = $message->getBody()->getContent();
                // print_r($string);
                // print_r(htmlspecialchars($string));
                // dd();

                $email_from = $message->getFrom()->getEmailAddress()->getAddress();
                $email_from_domain = substr($email_from, strpos($email_from, '@'));
                $email_subject = $message->getSubject();
                $email_date =
                    Carbon::parse($message->getReceivedDateTime())
                        ->setTimezone('America/Chicago')
                        ->format('Y-m-d H:i:s');
                // dd($message, [$email_from, $email_from_domain, $email_subject, $email_date]);

                //find the right Lead/type:: that belongs to this email....
                $from_email_leads = collect(
                    [
                        0 => [
                            'from_email' => 'no-reply-forms@webflow.com',
                            'from_subject' => ['You have a new form submission on your Webflow site!'],
                            // origin = Website, Houzz, Angi, Yelp...
                            'origin' => 'Website',
                            'options' => [
                                'address' => null,
                                'email' => 'regex',
                                'email_regex' => ["/Email 2:\s*([^<]+)/m"],
                                'name' => 'regex',
                                'name_regex' => ["/Name 2:\s*([^<]+)/m"],
                                'phone' => 'regex',
                                'phone_regex' => ["/Phone:\s*([^<]+)/m"],
                                'message' => 'regex',
                                'message_regex' => ["/Message 2:\s*([^<]+)/m"],
                            ],
                        ],
                        1 => [
                            'from_email' => 'notification@houzz.com',
                            'from_subject' => ['[Houzz] We’ve matched you with a new project!', 'You have a new message on Houzz'],
                            'origin' => 'Houzz',
                            'options' => [
                                'address' => 'regex',
                                'address_regex' => ["/highlighted-details-text.*?<span>(.*?)<\/span>/", "/https:\/\/st.hzcdn.com\/static\/proLeadEmail\/location.png.*?<span>(.*?)<\/span>/"],
                                'email' => null,
                                'name' => 'message_data.name',
                                'phone' => null,
                                'message' => 'regex',
                                'message_regex' => ['/Still Evaluating(.*?)Reply/s', '/Message(.*?)Reply/s'],
                            ],
                        ],
                    ],
                );

                $email_found =
                    $from_email_leads
                        ->where('from_email', $message->getFrom()->getEmailAddress()->getAddress())
                        ->first();
                // ->where('from_subject', $message->getSubject())
                // ->get();

                if (! is_null($email_found)) {
                    foreach ($email_found['from_subject'] as $from_subject) {
                        if (strpos($from_subject, $message->getSubject()) !== false) {
                            $email_subject_found = $email_found;
                        } else {
                            //continue... email Subject not found
                            //move the failed email?
                            continue;
                        }
                    }
                } else {
                    continue;
                }

                if (isset($email_subject_found)) {
                    $lead_data = collect();
                    $string = $message->getBody()->getContent();
                    // print_r(htmlspecialchars($string));
                    // print_r($string);
                    // dd();

                    $inputs = ['address', 'phone', 'message', 'email', 'name'];

                    foreach ($inputs as $input) {
                        if ($email_found['options'][$input] === 'regex') {
                            $pattern = $email_found['options'][$input.'_regex'];
                            if (is_array($pattern)) {
                                foreach ($pattern as $pattern_single) {
                                    //preg_match($re, $string, $matches, PREG_OFFSET_CAPTURE, 0);
                                    preg_match($pattern_single, $string, $matches);
                                    if ($matches) {
                                        $plain_text = strip_tags($matches[1]);
                                        $plain_text_with_breaks = nl2br($plain_text);
                                        $plain_text_with_layout = html_entity_decode($plain_text_with_breaks);

                                        $lead_data[$input] = $plain_text_with_layout;
                                    } else {
                                        continue;
                                    }
                                }
                            }

                            // print_r($plain_text_with_layout);
                            // dd();
                        } else {
                            if ($email_found['options'][$input] === 'message_data.'.$input) {
                                $lead_data[$input] = $message->getFrom()->getEmailAddress()->getName();
                            } else {
                                $lead_data[$input] = null;
                            }
                        }
                    }
                } else {
                    break;
                }

                if (isset($lead_data['email'])) {
                    $lead_data['reply_to_email'] = $lead_data['email'];
                } else {
                    $lead_data['reply_to_email'] = $message->getReplyTo()[0]['emailAddress']['address'];
                }

                if (! isset($lead_data['date'])) {
                    $lead_data['date'] = null;
                }

                try {
                    // Code that may throw an exception
                    $details = $this->chatGPTService->extractDetails(htmlspecialchars($string));
                } catch (Exception $e) {
                    // Handle the exception and continue
                    // Log::channel('leads_in_email_error')->error(['error', $e->getMessage()]);
                    // continue;
                }

                if (! isset($details)) {

                } else {
                    while (is_null($details)) {
                        sleep(5);
                        $details = $this->chatGPTService->extractDetails(htmlspecialchars($string));
                    }

                    foreach ($details as $detail_name => $text_detail) {
                        // $detail_test[] = isset($lead_data[$detail_name]);
                        if (! isset($lead_data[$detail_name])) {
                            $lead_data[$detail_name] = $text_detail;
                        } else {
                            $lead_data[$detail_name] = $text_detail;
                        }
                    }
                }

                $existing_lead = Lead::where('date', $email_date)->first();
                if ($existing_lead) {
                    $this->ms_graph
                        ->createRequest('POST', '/users/'.$company_email->api_json['user_id'].'/messages/'.$message->getId().'/move')
                        ->attachBody(
                            [
                                'destinationId' => $company_email->api_json['hive_folder_LEADS'],
                            ]
                        )
                        ->execute();

                    continue;

                } else {
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
                            $name = preg_replace('/\s+/', ' ', trim($lead_data['name']));
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
                        'date' => $email_date,
                        'origin' => $email_found['origin'],
                        'user_id' => $user_id,
                        'lead_data' => $lead_data,
                        'belongs_to_vendor_id' => $company_email->vendor_id,
                        'created_by_user_id' => 0, //AUTOMATED
                    ]);

                    //2024-12-30 MOVE TO Observer because it repeats and always gets called with creating a Lead
                    $lead->statuses()->create([
                        'title' => 'New',
                        'belongs_to_vendor_id' => $lead->belongs_to_vendor_id,
                        'created_at' => $email_date,
                    ]);

                    // dd($lead_data);

                    //12-31-2024 ..How to reset in one go //$refresh?
                    // $this->resetVariables();
                    $details = null;
                    $lead_data = null;
                    $lead = null;
                    $user = null;
                    $lead_data = null;
                    $name = null;
                    $nameParts = null;
                    $lastName = null;
                    $firstName = null;
                    $string = null;
                    $email_from = null;
                    $email_found = null;
                    $email_from_domain = null;
                    $email_subject = null;
                    $email_date = null;
                }

                $this->ms_graph->createRequest('POST', '/users/'.$company_email->api_json['user_id'].'/messages/'.$message->getId().'/move')
                    ->attachBody(
                        [
                            'destinationId' => $company_email->api_json['hive_folder_LEADS'],
                        ]
                    )
                    ->execute();

                continue;
            } //foreach messages
        }
    }

    protected function resetVariables()
    {
        $reflection = new \ReflectionClass($this);
        $properties = $reflection->getProperties(\ReflectionProperty::IS_PROTECTED);

        foreach ($properties as $property) {
            $property->setAccessible(true);
            $property->setValue($this, null);
        }
    }
}
