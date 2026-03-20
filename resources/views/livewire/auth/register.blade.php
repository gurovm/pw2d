<div class="min-h-screen bg-gradient-to-br from-slate-50 to-blue-50 flex items-center justify-center p-4">
    <div class="w-full max-w-md">
        <!-- Logo -->
        <div class="text-center mb-8">
            <a href="/"><img src="{{ asset('images/logo.webp') }}" alt="pw2d" class="h-14 mx-auto" width="212" height="56"></a>
            <h1 class="mt-4 text-2xl font-bold text-gray-900">Create your account</h1>
            <p class="text-sm text-gray-500 mt-1">Start comparing products smarter</p>
        </div>

        <!-- Card -->
        <div class="bg-white rounded-2xl shadow-xl border border-gray-100 p-8">
            <form wire:submit="register" class="space-y-5">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Full name</label>
                    <input wire:model="name" type="text" autocomplete="name"
                        class="w-full px-4 py-2.5 rounded-xl border border-gray-300 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent text-sm transition"
                        placeholder="John Doe">
                    @error('name') <p class="mt-1 text-xs text-red-500">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Email address</label>
                    <input wire:model="email" type="email" autocomplete="email"
                        class="w-full px-4 py-2.5 rounded-xl border border-gray-300 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent text-sm transition"
                        placeholder="you@example.com">
                    @error('email') <p class="mt-1 text-xs text-red-500">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Password</label>
                    <input wire:model="password" type="password" autocomplete="new-password"
                        class="w-full px-4 py-2.5 rounded-xl border border-gray-300 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent text-sm transition"
                        placeholder="Min. 8 characters">
                    @error('password') <p class="mt-1 text-xs text-red-500">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Confirm password</label>
                    <input wire:model="password_confirmation" type="password" autocomplete="new-password"
                        class="w-full px-4 py-2.5 rounded-xl border border-gray-300 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent text-sm transition"
                        placeholder="••••••••">
                </div>

                <button type="submit"
                    class="w-full py-3 px-4 bg-amber-600 hover:bg-amber-700 text-white font-semibold rounded-xl transition duration-200 text-sm flex items-center justify-center gap-2"
                    wire:loading.attr="disabled" wire:loading.class="opacity-75">
                    <svg wire:loading class="animate-spin h-4 w-4" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/></svg>
                    <span wire:loading.remove>Create account</span>
                    <span wire:loading>Creating account…</span>
                </button>
            </form>
        </div>

        <p class="text-center text-sm text-gray-500 mt-6">
            Already have an account?
            <a href="/login" class="text-amber-600 font-semibold hover:underline">Sign in</a>
        </p>
    </div>
</div>
