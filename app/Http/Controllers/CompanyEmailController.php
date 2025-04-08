<?php

namespace App\Http\Controllers;

use App\Models\CompanyEmail;

use App\Services\NylasService;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class CompanyEmailController extends Controller
{
    private $nylasService;

    /**
     * Inject the NylasService into the controller.
     */
    public function __construct(NylasService $nylasService)
    {
        $this->nylasService = $nylasService;
    }

    /**
     * Redirect the user to the Nylas authentication page.
     *
     * @return \Illuminate\Http\RedirectResponse
     */
    public function nylasLogin()
    {
        try {
            $authUrl = $this->nylasService->getAuthUrl();
            return redirect($authUrl['authentication_url']);
        } catch (\Exception $e) {
            Log::error(["Failed to retrieve Nylas authentication URL: ", $e->getMessage()]);
            return redirect()->back()->withErrors(['error' => 'Unable to initiate authentication with Nylas.']);
        }
    }

    /**
     * Handle the authentication response from Nylas.
     *
     * @param Request $request
     * @return \Illuminate\Http\RedirectResponse
     */
    public function nylasAuthResponse(Request $request)
    {
        if ($request->has('error')) {
            Log::error(["Failed to nylasAuthResponse: ", $request->all()]);
            return redirect()->back()->withErrors(['error' => $request->query('error')]);
        }

        $code = $request->query('code');

        try {
            // Exchange auth code for a token
            $nylasAccount = $this->nylasService->exchangeAuthCodeForToken($code);

            if (isset($nylasAccount['email'])) {
                // Save the account to the database
                $this->saveAccountToDatabase($nylasAccount);

                // Ensure required folders exist and get their data
                $folders = $this->nylasService->ensureFoldersExist($nylasAccount['grant_id']);

                // Update the CompanyEmail record's api_json column with the folder data
                CompanyEmail::where('grant_id', $nylasAccount['grant_id'])
                    ->update(['api_json' => json_encode(['folders' => $folders])]);

                return redirect(route('company_emails.index'))->with('success', 'Nylas account connected successfully.');
            } else {
                return redirect()->back()->withErrors(['error' => 'Failed to retrieve account details from Nylas.']);
            }
        } catch (\Exception $e) {
            Log::error(["Failed to handle Nylas authentication response:", $e->getMessage()]);
            return redirect()->back()->withErrors(['error' => 'An error occurred during Nylas authentication.']);
        }
    }

    /**
     * Save the Nylas account details to the database.
     *
     * @param array $nylasAccount
     * @return void
     */
    private function saveAccountToDatabase(array $nylasAccount)
    {
        // Check if the account already exists in the database
        $existingCompanyEmail = CompanyEmail::withoutGlobalScopes()
            ->where('email', $nylasAccount['email'])
            ->first();

        if ($existingCompanyEmail) {
            //redirect to company_emails.index
            //4-2-2025 this error only if email in vendor->compnay_emails, otherwise "cannot add email"
            // ->with('error', 'Email already exists in the database.');
            // Handle the case where the email already exists
            Log::warning(["Nylas account already exists:", $nylasAccount]);
            return redirect(route('company_emails.index'));
        } else {
            CompanyEmail::create([
                'email' => $nylasAccount['email'],
                'grant_id' => $nylasAccount['grant_id'],
                // 'api_json' => $nylasAccount, // Store all account details as JSON
                'vendor_id' => auth()->user()->vendor->id, // Associate with the authenticated user's vendor
            ]);
        }
    }


    /**
     * Fetch consolidated orders for all emails with grant_id.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function fetchConsolidatedOrders()
    {
        // Fetch all CompanyEmail records with a grant_id
        $companyEmails = CompanyEmail::withoutGlobalScopes()->whereNotNull('grant_id')->get();

        $results = []; // Array to store responses

        foreach ($companyEmails as $companyEmail) {
            $grantId = $companyEmail->grant_id; // Extract the grant_id

            // Call the NylasService's method for each grant_id
            $consolidatedOrder = $this->nylasService->getConsolidatedOrder($grantId);

            dd($consolidatedOrder); // Debugging: dump the consolidated order
            // Append the result
            $results[] = [
                'email_id' => $companyEmail->id,
                'grant_id' => $grantId,
                'consolidated_order' => $consolidatedOrder,
            ];
        }

        // Return results as a JSON response
        return response()->json([
            'success' => true,
            'data' => $results,
        ]);
    }


    public function fetchMessagesForGrantId()
    {
        // Retrieve all CompanyEmail records with a grant_id
        $companyEmails = CompanyEmail::withoutGlobalScopes()->whereNotNull('grant_id')->get();

        $results = []; // Initialize an array to store the results

        foreach ($companyEmails as $companyEmail) {
            $grantId = $companyEmail->grant_id; // Extract the grant_id

            // Define query parameters for the Nylas API
            $queryParams = [
                'limit' => 99, // Fetch 5 messages
                'in' => 'inbox', // Fetch messages from the inbox,
                'from' => 'HomeDepot@order.homedepot.com',
                'subject' => 'Your Electronic Receipt',
            ];

            // Fetch messages using the NylasService
            $messages = $this->nylasService->getMessages($queryParams, $grantId);
        }

        dd($messages); // Debugging: dump the results
        // Return the results as a JSON response
        return response()->json([
            'success' => true,
            'data' => $results,
        ]);
    }


}
