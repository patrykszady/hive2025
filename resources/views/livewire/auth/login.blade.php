<div class="flex min-h-screen">
    <!-- Left side - Login form -->
    <div class="flex-1 flex justify-center items-center">
        <div class="w-96 max-w-96 space-y-6 p-4">
            <div class="flex justify-center opacity-90">
                <a href="{{ route('welcome') }}" class="group">
                    <img class="w-auto h-24 mx-auto" src="{{ asset('favicon.png') }}" alt="{{ config('app.name') }}">
                </a>
            </div>

            <flux:heading class="text-center" size="xl">Sign in to your Hive</flux:heading>

            <div class="space-y-6">
                <form wire:submit="login" class="space-y-6">
                    <flux:input 
                        wire:model="email" 
                        label="Email"
                        type="email"
                        placeholder="email@example.com"
                        required
                    />

                    <flux:field>
                        <div class="mb-3 flex justify-between">
                            <flux:label>Password</flux:label>
                            <flux:link href="{{ route('cant.login') }}" variant="subtle" class="text-sm">Can't Login?</flux:link>
                        </div>
                        <flux:input 
                            wire:model="password" 
                            type="password"
                            placeholder="Your password"
                            required
                        />
                    </flux:field>

                    <flux:switch wire:model.live="remember" label="Remember Me" align="left" />
                        {{-- <flux:field variant="inline">
                            <flux:label>Remember Me</flux:label>
                            <flux:switch wire:model.live="remember"/>
                            <flux:error name="remember" />
                        </flux:field> --}}

                    <flux:button type="submit" variant="primary" class="w-full">
                        Sign in
                    </flux:button>
                </form>
            </div>

            <flux:separator text="or"/>

            <flux:button 
                href="{{ route('registration') }}"
                class="w-full"
                {{-- Remove disabled attribute --}}
            >
                {{-- your Hive --}}
                Register
            </flux:button>

            {{-- <flux:subheading class="text-center">
                First time around here? <flux:link href="{{ route('registration') }}">Create a new hive, free forever</flux:link>
            </flux:subheading> --}}
        </div>
    </div>

    <!-- Right side - Testimonial -->
    <div class="flex-1 p-4 max-lg:hidden">
        <!-- style="background-image: url('https://images.unsplash.com/photo-1566041510639-8d95a2490bfb?q=80&w=1887&auto=format&fit=crop'); background-size: cover; background-position: center;" -->
        <div class="text-white relative rounded-lg h-full w-full bg-blue-900 flex flex-col items-start justify-end p-16">
            <div class="flex gap-2 mb-4">
                <flux:icon.star variant="solid" />
                <flux:icon.star variant="solid" />
                <flux:icon.star variant="solid" />
                <flux:icon.star variant="solid" />
                <flux:icon.star variant="solid" />
            </div>

            <div class="mb-6 italic font-base text-3xl xl:text-4xl">
                "Hive has transformed how we organize our projects and collaborate with our subcontractors."
            </div>

            <div class="flex gap-4">
                <flux:avatar src="{{ asset('favicon.png') }}" size="xl" />

                <div class="flex flex-col justify-center font-medium">
                    <div class="text-lg">Grzegorz Szady</div>
                    <div class="text-zinc-300">Boss</div>
                </div>
            </div>
        </div>
    </div>
</div>
