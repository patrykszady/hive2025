<?php

namespace App\Services;

use App\Models\Client;
use App\Models\Lead;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class LeadContactProvisioner
{
    /**
     * Create a User (and Client when an address is present) for the
     * lead's contact data, attach them to the lead's vendor, and
     * link the user to the lead.
     */
    public function provision(Lead $lead): void
    {
        $data = $lead->lead_data;

        $name = $this->stringValue($data['name'] ?? null);
        $email = $this->stringValue($data['email'] ?? null);
        $phone = $this->normalizePhone($data['phone'] ?? null);
        $address = $this->stringValue($data['address'] ?? null);

        if ($name === null || $email === null || $phone === null) {
            return;
        }

        DB::transaction(function () use ($lead, $name, $email, $phone, $address) {
            $user = $this->findOrCreateUser($name, $email, $phone);

            if ($lead->user_id !== $user->id) {
                $lead->user_id = $user->id;
                $lead->saveQuietly();
            }

            if ($address === null) {
                return;
            }

            $vendorId = (int) $lead->belongs_to_vendor_id;
            $client = $this->findOrCreateClient($user, $address, $vendorId);

            if (! $user->clients()->where('clients.id', $client->id)->exists()) {
                $user->clients()->attach($client->id);
            }

            if (! $client->vendors()->where('vendors.id', $vendorId)->exists()) {
                $client->vendors()->attach($vendorId, ['source' => $lead->origin]);
            }
        });
    }

    protected function findOrCreateUser(string $name, string $email, string $phone): User
    {
        $user = User::query()
            ->where('cell_phone', $phone)
            ->orWhere('email', $email)
            ->first();

        if ($user) {
            return $user;
        }

        [$firstName, $lastName] = $this->splitName($name);

        return User::create([
            'first_name' => $firstName,
            'last_name' => $lastName,
            'email' => $email,
            'cell_phone' => $phone,
        ]);
    }

    protected function findOrCreateClient(User $user, string $address, int $vendorId): Client
    {
        $parsed = $this->parseAddress($address);

        $existing = $user->clients()
            ->whereRaw('LOWER(address) = ?', [mb_strtolower($parsed['address'])])
            ->first();

        if ($existing) {
            return $existing;
        }

        return Client::create([
            'address' => $parsed['address'],
            'city' => $parsed['city'],
            'state' => $parsed['state'],
            'zip_code' => $parsed['zip_code'],
        ]);
    }

    /**
     * @return array{address: string, city: ?string, state: ?string, zip_code: ?string}
     */
    protected function parseAddress(string $address): array
    {
        $parts = array_values(array_filter(array_map('trim', explode(',', $address)), fn ($p) => $p !== ''));

        // Drop trailing country tokens like "USA" / "United States".
        if (! empty($parts)) {
            $last = strtolower(end($parts));
            if (in_array($last, ['usa', 'us', 'united states', 'united states of america'], true)) {
                array_pop($parts);
            }
        }

        $street = $parts[0] ?? $address;
        $city = $parts[1] ?? null;
        $stateZip = $parts[2] ?? null;

        $state = null;
        $zip = null;

        if ($stateZip !== null) {
            if (preg_match('/^([A-Za-z]{2})\s*(\d{5}(?:-\d{4})?)?$/', $stateZip, $m)) {
                $state = strtoupper($m[1]);
                $zip = $m[2] ?? null;
            } else {
                $state = $stateZip;
            }
        }

        return [
            'address' => $street,
            'city' => $city,
            'state' => $state,
            'zip_code' => $zip,
        ];
    }

    /**
     * @return array{0: string, 1: string}
     */
    protected function splitName(string $name): array
    {
        $clean = preg_replace('/\s+/', ' ', trim($name));
        $parts = explode(' ', $clean);

        if (count($parts) === 1) {
            return [$parts[0], ''];
        }

        $last = array_pop($parts);

        return [implode(' ', $parts), $last];
    }

    protected function normalizePhone(mixed $phone): ?string
    {
        if (! is_string($phone) && ! is_numeric($phone)) {
            return null;
        }

        $digits = preg_replace('/\D/', '', (string) $phone);

        if (strlen($digits) === 11 && str_starts_with($digits, '1')) {
            $digits = substr($digits, 1);
        }

        return strlen($digits) === 10 ? $digits : null;
    }

    protected function stringValue(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
