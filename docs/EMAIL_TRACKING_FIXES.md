# Email Tracking Issues & Fixes

## Problems Identified

### Problem 1: Duplicate "Sent" Events for Multi-Recipient Emails

**Root Cause:**
The `StoreEmailTracking` listener was creating **separate tracking records for each recipient** when an email was sent to multiple people. This caused duplicate "sent" events in the database.

**Example:**
- Email sent to `john@example.com` and `jane@example.com`
- Result: 2 separate `email_tracking` records, both with `event_type = 'sent'`
- Expected: 1 record with `recipient_emails = ["john@example.com", "jane@example.com"]`

**Code Location:** `app/Listeners/StoreEmailTracking.php` (lines 63-76)

```php
// OLD BEHAVIOR - INCORRECT
foreach ($recipients as $recipientEmail) {
    EmailTracking::create([
        'project_id' => $projectId,
        'nylas_message_id' => $nylasMessageId,
        'event_type' => 'sent',
        'recipient_emails' => [$recipientEmail], // ← Creates one record per recipient
        // ...
    ]);
}
```

**Fix:**
Create a **single tracking record with all recipients** in a JSON array, matching the webhook controller's behavior.

```php
// NEW BEHAVIOR - CORRECT
if (!empty($recipients)) {
    EmailTracking::create([
        'project_id' => $projectId,
        'nylas_message_id' => $nylasMessageId,
        'event_type' => 'sent',
        'recipient_emails' => $recipients, // ← All recipients in one array
        // ...
    ]);
}
```

**Impact:**
- **Before:** Email to 3 people → 3 "sent" records in database
- **After:** Email to 3 people → 1 "sent" record with all 3 recipients

### Problem 2: Opens Without recipient_email Were Skipped

**Root Cause:**
When Nylas sends a `message.opened` webhook without the `recipient_email` field (which happens with some email clients), the code assumed it was the **sender viewing their own sent email** and skipped tracking it entirely.

**Example Webhooks Being Skipped:**
```json
{
  "type": "message.opened",
  "object": {
    "message_id": "AAkAL...",
    // ← NO recipient_email field
    "recents": [{
      "user_agent": "Mozilla/5.0 (iPhone; CPU iPhone OS 18_6_2...)",
      "opened_id": 25
    }]
  }
}
```

These are **real opens from recipients** (iPhone, Outlook, etc.), not the sender viewing sent email.

**Code Location:** `NylasWebhookController::handleMessageOpened()` (lines 89-105)

```php
// OLD BEHAVIOR - INCORRECT
if ($recipientEmail) {
    $recipients = collect([$recipientEmail]);
    $isMessageLevel = false;
} else {
    $recipients = $this->resolveRecipientsForMessage($messageId);
    
    if ($recipients->isNotEmpty()) {
        return; // ← Skipped all opens without recipient_email!
    }
    
    return;
}
```

**Fix:**
Track these as **message-level opens** (one event for the entire message) instead of skipping them.

```php
// NEW BEHAVIOR - CORRECT
if ($recipientEmail) {
    $recipients = collect([$recipientEmail]);
    $isMessageLevel = false;
} else {
    // Track as message-level open
    $recipients = $this->resolveRecipientsForMessage($messageId);
    $isMessageLevel = true; // ← Track with all recipients
    
    if ($recipients->isEmpty()) {
        Log::warning('Cannot find recipients');
        return;
    }
}
```

**Impact:**
- **Before:** Opens without `recipient_email` → Not tracked at all
- **After:** Opens without `recipient_email` → Tracked as message-level event

### Problem 3: Outgoing Replies (from_self) Were Skipped

**Root Cause:**
When a `thread.replied` webhook had `from_self: true` (meaning we replied in the thread), it was skipped entirely. But we should track when customer service replies to customer emails.

**Example Webhooks Being Skipped:**
```json
{
  "type": "thread.replied",
  "object": {
    "from_self": true, // ← Our reply in the thread
    "thread_id": "AAQkADZh...",
    "reply_data": {"count": 2}
  }
}
```

**Code Location:** `NylasWebhookController::handleThreadReplied()` (line 202)

```php
// OLD BEHAVIOR - INCORRECT
if (!empty($object['from_self'])) {
    return; // ← Skipped all our replies!
}
```

**Fix:**
Track outgoing replies with a new event type `replied_outgoing` to distinguish them from incoming replies.

```php
// NEW BEHAVIOR - CORRECT
$fromSelf = !empty($object['from_self']);
$eventType = $fromSelf ? 'replied_outgoing' : 'replied';
// ... continues to track the event
```

**Impact:**
- **Before:** Our replies → Not tracked at all
- **After:** Our replies → Tracked as `replied_outgoing` events

### Problem 4: Opens Registered for ALL Recipients (Not Just Who Opened)

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

### Problem 5: Mail Client Prefetch Triggers False Opens

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

1. `/app/Listeners/StoreEmailTracking.php`
   - Changed from creating one record per recipient to one record with all recipients
   - Prevents duplicate "sent" events for multi-recipient emails

2. `/app/Http/Controllers/Api/NylasWebhookController.php`
   - `handleMessageOpened()`: Require recipient_email, add prefetch filtering
   - `handleMessageLinkClicked()`: Require recipient_email, add prefetch filtering  
   - `handleBouncedAsReply()`: Fixed to use `recipient_emails` (plural) instead of `recipient_email`
   - `isPrefetchOrAutomatedOpen()`: New method for sophisticated detection

## Next Steps

1. Deploy changes
2. Monitor logs for "Missing recipient_email" warnings (may indicate Nylas webhook issue)
3. Review "Filtered prefetch/automated open" logs to tune detection
4. Consider adding behavioral analysis (time-based patterns) in future
5. Build reporting to show open rates excluding prefetch events
