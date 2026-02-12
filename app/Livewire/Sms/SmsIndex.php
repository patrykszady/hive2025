<?php

namespace App\Livewire\Sms;

use App\Models\SmsGroupThread;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

class SmsIndex extends Component
{
    #[Url(except: '')]
    public string $search = '';

    #[Url(except: null)]
    public ?int $threadId = null;

    protected $listeners = [
        'threadCreated' => 'selectThread',
        'threadSelected' => 'selectThread',
        'messageSent' => '$refresh',
        'threadRead' => '$refresh',
    ];

    public function selectThread(int|null $threadId): void
    {
        $this->threadId = $threadId;
    }

    public function clearThread(): void
    {
        $this->threadId = null;
    }

    #[Title('Messages')]
    public function render()
    {
        return view('livewire.sms.index')->layout('components.layouts.app', [
            'fullscreenClasses' => '!p-0 h-full overflow-hidden flex flex-col',
        ]);
    }
}
