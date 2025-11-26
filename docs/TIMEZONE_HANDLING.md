# Timezone Handling Documentation

## Overview

The application now properly handles timezones:
- **Database**: All dates stored in UTC
- **Display**: Dates shown in browser's local timezone
- **Vendor Default**: Each vendor has a default timezone (America/Chicago)

## Configuration

- `APP_TIMEZONE=UTC` - All database dates are stored in UTC
- `vendors.timezone` - Each vendor's default timezone (defaults to America/Chicago)

## Frontend Usage

### Blade Component (Recommended)

```blade
{{-- Basic usage --}}
<x-datetime :date="$expense->created_at" />

{{-- Date only --}}
<x-datetime :date="$expense->created_at" format="date" />

{{-- Time only --}}
<x-datetime :date="$expense->created_at" format="time" />

{{-- Relative time (e.g., "2 hours ago") --}}
<x-datetime :date="$expense->created_at" format="relative" />

{{-- Custom format --}}
<x-datetime 
    :date="$expense->created_at" 
    format='{"month":"long","day":"numeric","year":"numeric"}' 
/>

{{-- With additional attributes --}}
<x-datetime :date="$expense->created_at" class="text-sm text-gray-500" />
```

### Manual Usage

```blade
<time 
    data-utc="{{ $expense->created_at->toIso8601String() }}"
    data-format="default"
>
    {{ $expense->created_at->format('M d, Y g:i A') }}
</time>
```

### Format Options

- `default` - Nov 26, 2025 3:45 PM
- `date` - Nov 26, 2025
- `time` - 3:45 PM
- `relative` - "2 hours ago", "3 days ago", etc.
- Custom JSON - Pass Intl.DateTimeFormat options as JSON

## Backend Usage

### Using the HandlesTimezones Trait

```php
use App\Traits\HandlesTimezones;

class PlaidTransactionSyncController extends Controller
{
    use HandlesTimezones;
    
    public function syncTransactions($transactions)
    {
        foreach ($transactions as $transaction) {
            // Convert Plaid date (in Chicago timezone) to UTC
            $date = $this->parseApiDate($transaction['date'], 'plaid');
            
            Transaction::create([
                'date' => $date, // Stored as UTC in database
                'amount' => $transaction['amount'],
            ]);
        }
    }
}
```

### API Source Conversions

```php
// Plaid dates (America/Chicago)
$utcDate = $this->parseApiDate($plaidDate, 'plaid');

// Nylas dates (already UTC)
$utcDate = $this->parseApiDate($nylasDate, 'nylas');

// Microsoft dates (uses MSGRAPH_PREFER_TIMEZONE config)
$utcDate = $this->parseApiDate($microsoftDate, 'microsoft');

// Google dates (UTC)
$utcDate = $this->parseApiDate($googleDate, 'google');
```

### Manual Conversion

```php
// Convert from specific timezone to UTC
$utcDate = $this->convertToUtc($someDate, 'America/New_York');

// Convert UTC to vendor's timezone (for backend logic)
$vendorDate = $this->convertToVendorTimezone($utcDate);
```

## Migration Guide

### Existing Data

All existing dates in your database are currently in `America/Chicago` timezone. After changing `APP_TIMEZONE=UTC`, you need to:

1. **Option 1: Keep as-is** (Recommended for now)
   - Don't migrate existing data
   - New data will be stored in UTC
   - Old data will be interpreted as UTC (will appear 5-6 hours off)
   - Eventually run migration to convert all dates

2. **Option 2: Migrate all existing dates**
   ```php
   // One-time migration script
   DB::statement("
       UPDATE transactions 
       SET date = CONVERT_TZ(date, 'America/Chicago', 'UTC')
   ");
   ```

### Converting API Dates

**Before:**
```php
Transaction::create([
    'date' => $plaidTransaction['date'], // Stored as Chicago time
]);
```

**After:**
```php
use App\Traits\HandlesTimezones;

Transaction::create([
    'date' => $this->parseApiDate($plaidTransaction['date'], 'plaid'), // Converted to UTC
]);
```

## Common Patterns

### Livewire Components

```php
use App\Traits\HandlesTimezones;
use Livewire\Component;

class ExpenseForm extends Component
{
    use HandlesTimezones;
    
    public function save()
    {
        // If date comes from Plaid API
        $utcDate = $this->parseApiDate($this->plaidDate, 'plaid');
        
        Expense::create([
            'date' => $utcDate,
            // ...
        ]);
    }
}
```

### API Controllers

```php
use App\Traits\HandlesTimezones;

class NylasWebhookController extends Controller
{
    use HandlesTimezones;
    
    protected function handleMessageOpened(array $payload): void
    {
        // Nylas dates are already in UTC
        $openedAt = $this->parseApiDate(
            $payload['data']['object']['opened_at'], 
            'nylas'
        );
        
        EmailTracking::create([
            'opened_at' => $openedAt,
            // ...
        ]);
    }
}
```

## Testing

The browser timezone is automatically detected and stored in:
```javascript
document.body.dataset.timezone // e.g., "America/New_York"
```

You can test different timezones by:
1. Changing your browser's timezone in DevTools
2. Refreshing the page
3. Dates should display in the new timezone

## Troubleshooting

### Dates appear X hours off

- Check that `APP_TIMEZONE=UTC` in `.env`
- Run `php artisan config:clear`
- Verify dates are being converted when stored: `dd($this->parseApiDate($date, 'plaid'))`

### JavaScript not converting dates

- Check browser console for errors
- Verify `data-utc` attribute has ISO 8601 format
- Run `npm run build` to compile assets
- Check that `timezone.js` is loaded

### Vendor timezone not applying

- Verify vendor has timezone set: `$vendor->timezone`
- Default is `America/Chicago` if not set
- Update vendor: `$vendor->update(['timezone' => 'America/New_York'])`
