
it('says when EWCCV matched by name but lists a policy number other than the certificate on file', function () {
    $doc = new \App\Models\VendorDoc(['type' => 'workers', 'number' => 'WC-OLD-1', 'options' => ['ewccv' => [
        'status' => 'tracking', 'checked_at' => '2026-10-13T00:15:00+00:00', 'match_type' => 'employerName',
        'matched_name' => 'SMARTECH ELECTRIC LLC', 'matched_policy' => 'WC-NEW-2',
    ]]]);

    expect($doc->ewccv_summary['tip'])
        ->toContain('matched by name (SMARTECH ELECTRIC LLC); EWCCV lists policy WC-NEW-2, the certificate on file says WC-OLD-1');

    $same = new \App\Models\VendorDoc(['type' => 'workers', 'number' => 'WC5-39S-342438-046', 'options' => ['ewccv' => [
        'status' => 'tracking', 'checked_at' => '2026-10-13T00:15:00+00:00', 'match_type' => 'policyNumber',
        'matched_name' => 'JW GREEN DUMP INC', 'matched_policy' => 'WC539S342438046',
    ]]]);
    expect($same->ewccv_summary['tip'])->toBe('EWCCV coverage tracking enabled · checked 10/13/2026');
});
