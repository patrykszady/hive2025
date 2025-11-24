# Email Tracking Issues & Fixes

## Problems Identified

### Problem 1: Opens Registered for ALL Recipients (Not Just Who Opened)

**Root Cause:**
When a webhook arrives without `recipient_email` in the payload, the code fell back to fetching ALL recipients from the `email_tracking` table and created an "opened" event for each one.

**Code Location:** `NylasWebhookController::resolveRecipientsForMessage()`

```php
// OLD BEHAVIOR - INCORRECT
if ($recipientFromPayload) {
    return collect([$recipientFromPayload]);
}

// This returned ALL recipients!
return EmailTracking::query()
    ->where('nylas_message_id', $messageId)
    ->where('event_type', 'sent')
    ->pluck('recipient_email');
```

**Fix:**
Now we require `recipient_email` in the payload. If it's missing, we log a warning and skip the event entirely, since we cannot accurately attribute the open to a specific recipient.

```php
// NEW BEHAVIOR - CORRECT
$recipientEmail = $object['recipient_email'] ?? null;

if (!$recipientEmail) {
    Log::channel('nylas')->warning('Missing recipient_email - cannot attribute open');
    return;
}

$recipients = collect([$recipientEmail]);
```

### Problem 2: Mail Client Prefetch Triggers False Opens

**Root Cause:**
Modern mail clients (Apple Mail, Outlook, Gmail) automatically prefetch/preload email content for:
- Security scanning
- Preview generation
- Link validation
- Image caching

These automated opens were being counted as real user opens.

**Old Detection (Insufficient):**
```php
if (($eventDetails['opened_id'] ?? null) === 0 && (int) ($object['message_data']['count'] ?? 0) <= 1) {
    return; // Skip
}
```

**New Detection (Mailtrap-Inspired):**
We now use sophisticated heuristics to detect automated opens:

1. **Nylas's own prefetch indicator**: `opened_id === 0` with low count
2. **User agent analysis**: Detect security scanners, bots, headless browsers, prefetch clients
3. **IP patterns**: Known cloud services, security services (logged for tuning, not rejected alone)
4. **Missing user agents**: Empty or "Unknown" user agents indicate automation

```php
protected function isPrefetchOrAutomatedOpen(array $eventDetails, array $object): bool
{
    // Check 1: Nylas prefetch indicator
    if ($openedId === 0 && $count <= 1) {
        return true;
    }

    // Check 2: User agent patterns
    $automatedPatterns = [
        '/(?:safe|security|scanner|link.*check|threat|virus|malware)/i',
        '/(?:barracuda|proofpoint|mimecast|ironport|forcepoint)/i',
        '/(?:apple.*mail.*prefetch|outlook.*safelink|gmail.*image.*proxy)/i',
        '/(?:bot|crawler|spider|scraper|curl|wget|python-requests)/i',
        '/(?:headless|phantom|selenium|puppeteer)/i',
        // ... more patterns
    ];

    // Check 3: Empty/missing user agent
    if (empty($userAgent) || $userAgent === 'Unknown') {
        return true;
    }

    // Check 4: opened_id of 0 is always suspicious
    if ($openedId === 0) {
        return true;
    }

    return false;
}
```

## How Mailtrap Does It Better

Mailtrap uses multiple signals to distinguish real opens from automated ones:

1. **User Agent Fingerprinting**: Advanced pattern matching for known automated clients
2. **Behavioral Analysis**: Time-based patterns (multiple opens in rapid succession)
3. **IP Reputation**: Database of known security scanner IPs
4. **Browser Characteristics**: Real browsers send additional headers that automated clients don't
5. **Machine Learning**: Trained models to detect new automated patterns

Our implementation now includes:
- ✅ User agent pattern matching (similar to Mailtrap)
- ✅ IP address analysis (logged for tuning)
- ✅ Nylas's built-in indicators (opened_id)
- ✅ Empty user agent detection
- ✅ Logging for continuous improvement

## Impact

**Before:**
- Email to 5 people → 1 person opens → Database shows 5 opens
- Gmail prefetches email → Database shows open (not real user)

**After:**
- Email to 5 people → 1 person opens → Database shows 1 open (correct recipient)
- Gmail prefetches email → Filtered out, not counted

## Testing Recommendations

1. **Send test email to multiple recipients**
   - Have only 1 person open it
   - Verify only 1 "opened" event is created with correct `recipient_email`

2. **Monitor filtered events**
   - Check logs for "Filtered prefetch/automated open" messages
   - Review patterns to tune detection rules

3. **Watch for false positives**
   - If legitimate opens are filtered, adjust user agent patterns
   - IP-based filtering is conservative (logged but not rejected alone)

## Database Schema

The `email_tracking` table correctly stores individual recipient events:

```php
Schema::create('email_tracking', function (Blueprint $table) {
    $table->id();
    $table->string('nylas_message_id')->index();
    $table->string('event_type'); // 'sent', 'opened', 'link_clicked', etc.
    $table->string('recipient_email'); // ← KEY: Each event tied to specific recipient
    $table->string('ip_address')->nullable();
    $table->string('user_agent')->nullable();
    $table->timestamp('event_at');
    // ...
});
```

## Files Modified

1. `/app/Http/Controllers/Api/NylasWebhookController.php`
   - `handleMessageOpened()`: Require recipient_email, add prefetch filtering
   - `handleMessageLinkClicked()`: Require recipient_email, add prefetch filtering  
   - `isPrefetchOrAutomatedOpen()`: New method for sophisticated detection

## Next Steps

1. Deploy changes
2. Monitor logs for "Missing recipient_email" warnings (may indicate Nylas webhook issue)
3. Review "Filtered prefetch/automated open" logs to tune detection
4. Consider adding behavioral analysis (time-based patterns) in future
5. Build reporting to show open rates excluding prefetch events
