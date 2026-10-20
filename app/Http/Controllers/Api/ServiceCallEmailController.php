<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\ServiceCallFromEmailRequest;
use App\Services\ServiceCalls\ServiceCallEmailIntake;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;

/**
 * gs.construction asks here before it files an email as a lead: a known
 * client writing about a problem on finished work opens a service call in
 * Hive instead (or already did, when Hive's own reader saw it first), and
 * the site then leaves the email alone.
 */
class ServiceCallEmailController extends Controller
{
    public function __invoke(ServiceCallFromEmailRequest $request, ServiceCallEmailIntake $intake): JsonResponse
    {
        $data = $request->validated();

        $decision = $intake->openedFor($data['message_key']) ?? $intake->consider([
            'message_key' => $data['message_key'],
            'from_email' => $data['from_email'],
            'from_name' => $data['from_name'] ?? null,
            'to' => $data['to'] ?? [],
            'cc' => $data['cc'] ?? [],
            'subject' => $data['subject'] ?? '',
            'body' => $data['body'] ?? '',
            'mailbox' => $data['mailbox'] ?? null,
            'rfc_message_id' => $data['rfc_message_id'] ?? null,
            'received_at' => isset($data['received_at']) ? Carbon::parse($data['received_at']) : null,
            'vendor_id' => (int) $request->user()->vendor->id,
        ]);

        return response()->json([
            'service_call' => (bool) $decision?->isOpened(),
            'outcome' => $decision?->outcome,
            'project_id' => $decision?->isOpened() ? $decision->project_id : null,
            'task_id' => $decision?->isOpened() ? $decision->task_id : null,
        ]);
    }
}
