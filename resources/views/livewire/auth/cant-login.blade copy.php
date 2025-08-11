{{-- filepath: /home/patryk/web/hive/resources/views/livewire/auth/cant-login.blade.php --}}
<div class="flex min-h-screen" 
     x-data="{ 
        countdown: @entangle('resend_countdown'),
        canResend: @entangle('can_resend'),
        timer: null,
        startCountdown() {
            this.timer = setInterval(() => {
                if (this.countdown > 0) {
                    this.countdown--;
                    $wire.decrementCountdown();
                } else {
                    this.canResend = true;
                    clearInterval(this.timer);
                }
            }, 1000);
        }
     }"
     @start-countdown.window="startCountdown()">
    <!-- Left side - Recovery form -->
    <div class="flex-1 flex justify-center items-center">
        <div class="w-96 max-w-96 space-y-6 p-4">
            <div class="flex justify-center opacity-90">
                <a href="{{ route('welcome') }}" class="group">
                    <img class="w-auto h-24 mx-auto" src="{{ asset('favicon.png') }}" alt="{{ config('app.name') }}">
                </a>
            </div>

            <flux:heading class="text-center" size="xl">Account Recovery</flux:heading>
            <flux:subheading class="text-center text-zinc-500">
                @if($step === 'identifier')
                    Enter your email or phone number to receive a verification code
                @elseif($step === 'verify_code')
                    Enter the 6-digit code sent to {{ $identifier }}
                @else
                    Create your new password
                @endif
            </flux:subheading>

            <div class="space-y-6">
                @if($step === 'identifier')
                    <form wire:submit="sendVerificationCode" class="space-y-6">
                        <flux:input 
                            wire:model="identifier" 
                            label="Email or Phone Number"
                            placeholder="email@example.com or 1234567890"
                            required
                        />
                        
                        <flux:button type="submit" variant="primary" class="w-full">
                            Send Verification Code
                        </flux:button>
                    </form>

                @elseif($step === 'verify_code')
                    <form wire:submit="verifyCode" class="space-y-6">
                        <flux:input 
                            wire:model="verification_code" 
                            label="Verification Code"
                            placeholder="123456"
                            maxlength="6"
                            class="text-center text-lg tracking-widest"
                            required
                        />
                        
                        <div class="text-center">
                            <button 
                                type="button" 
                                wire:click="resendVerificationCode"
                                x-show="canResend"
                                class="text-sm text-blue-600 hover:text-blue-500"
                            >
                                Didn't receive code? Send again
                            </button>
                            
                            <div x-show="!canResend" class="text-sm text-gray-500">
                                Resend code in <span x-text="countdown"></span> seconds
                            </div>
                        </div>

                        <flux:button type="submit" variant="primary" class="w-full">
                            Verify Code
                        </flux:button>
                    </form>

                @else
                    <form wire:submit="resetPassword" class="space-y-6">
                        <flux:input 
                            wire:model="password" 
                            label="New Password"
                            type="password"
                            placeholder="Enter new password"
                            required
                        />
                        
                        <flux:input 
                            wire:model="password_confirmation" 
                            label="Confirm New Password"
                            type="password"
                            placeholder="Confirm new password"
                            required
                        />
                        
                        <flux:button type="submit" variant="primary" class="w-full">
                            Reset Password
                        </flux:button>
                    </form>
                @endif
            </div>

            <flux:separator text="or"/>

            <div class="text-center space-y-2">
                <flux:subheading>
                    Remember your password? <flux:link href="{{ route('login') }}">Sign in</flux:link>
                </flux:subheading>
                
                @if($step !== 'identifier')
                    <div>
                        <button 
                            type="button" 
                            wire:click="$set('step', 'identifier')" 
                            class="text-sm text-gray-500 hover:text-gray-700"
                        >
                            ← Try different email/phone
                        </button>
                    </div>
                @endif
            </div>
        </div>
    </div>

    <!-- Right side - Help content -->
    <div class="flex-1 p-4 max-lg:hidden">
        <div class="bg-gray-50 rounded-lg h-full w-full flex flex-col items-center justify-center p-16 text-center">
            <div class="mb-6">
                <flux:icon icon="shield-check" class="w-16 h-16 text-blue-600 mx-auto" />
            </div>
            
            <flux:heading size="lg" class="mb-4">Secure Account Recovery</flux:heading>
            
            <flux:subheading class="text-gray-600 max-w-md">
                We'll send a verification code to your email or phone number to ensure it's really you trying to access your account.
            </flux:subheading>

            <div class="mt-8 space-y-3 text-sm text-gray-500">
                <div class="flex items-center justify-center gap-2">
                    <flux:icon icon="check-circle" class="w-4 h-4 text-green-500" />
                    <span>6-digit verification code</span>
                </div>
                <div class="flex items-center justify-center gap-2">
                    <flux:icon icon="check-circle" class="w-4 h-4 text-green-500" />
                    <span>Sent via email or SMS</span>
                </div>
                <div class="flex items-center justify-center gap-2">
                    <flux:icon icon="check-circle" class="w-4 h-4 text-green-500" />
                    <span>Secure password reset</span>
                </div>
            </div>
        </div>
    </div>
</div>
