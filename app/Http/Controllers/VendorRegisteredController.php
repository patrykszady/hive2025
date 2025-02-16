<?php

namespace App\Http\Controllers;

use App\Models\Payment;
use App\Models\ProjectStatus;
use App\Models\Vendor;

class VendorRegisteredController extends Controller
{
    //create payment for each check
    // ?? payments/expenses / paid_by employee
    public function create_payment_from_check($check, $check_expenses, $vendor)
    {
        if (isset($check['paid_by'])) {
            $check_info = $check['invoice'];
        } elseif ($check['check_type'] == 'Check') {
            $check_info = $check['check_number'];
        } else {
            $check_info = $check['check_type'];
        }

        //if payment with this check already exists...
        $existing_payment = Payment::withoutGlobalScopes()->where('check_id', $check->id)->where('belongs_to_vendor_id', $vendor->id)->get();

        foreach ($check_expenses as $key => $expense) {
            if ($key == 0) {
                if ($existing_payment->isEmpty()) {
                    $parent_payment_id = null;
                } else {
                    $parent_payment_id = $existing_payment->first()->id;
                }
            } else {
                $parent_payment_id = $parent_payment;
            }

            //project vs distribution
            if (! is_null($expense['project_id'])) {
                $project_id = $expense['project_id'];
                $distribution_id = null;
            } else {
                $project_id = null;
                $distribution_id = $expense['distribution_id'];
            }

            $payment = Payment::create([
                'amount' => $expense['amount'],
                'project_id' => $project_id,
                'distribution_id' => $distribution_id,
                'date' => $expense['date'],
                'reference' => $check_info,
                'belongs_to_vendor_id' => $vendor->id,
                'created_by_user_id' => 0,
                'parent_client_payment_id' => $parent_payment_id,
                'check_id' => $check->id,
            ]);

            if ($key == 0 and is_null($parent_payment_id)) {
                $parent_payment = $payment->id;
            } else {
                $parent_payment = $parent_payment_id;
            }

            if (! is_null($expense->project->belongs_to_vendor_id)) {
                if ($expense->project->belongs_to_vendor_id != $vendor->id) {
                    //if not duplicate
                    $project_vendor = $expense->project->vendors()->where('vendors.id', $vendor->id)->get();

                    if ($project_vendor->isEmpty()) {
                        $expense->project->vendors()->attach($vendor->id,
                            ['client_id' => Vendor::where('id', $expense->project->belongs_to_vendor_id)
                                ->first()
                                ->client()
                                ->withoutGlobalScopes()
                                ->first()->id,
                            ]);

                        $this->add_project_status($expense->project->id, $vendor->id, 'Active');
                    }
                }
            }
        }
    }

    public function add_project_status($project_id, $vendor_id, $title)
    {
        ProjectStatus::create([
            'project_id' => $project_id,
            'belongs_to_vendor_id' => $vendor_id,
            'title' => $title,
            'start_date' => today()->format('Y-m-d'),
        ]);
    }
}
