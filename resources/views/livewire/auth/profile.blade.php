<div class="min-h-screen bg-gradient-to-br from-slate-50 to-blue-50 py-12 px-4">
    <div class="max-w-2xl mx-auto space-y-6">
        <h1 class="text-2xl font-bold text-gray-900">My Profile</h1>

        <!-- Profile Info -->
        <div class="bg-white rounded-2xl shadow border border-gray-100 p-8">
            <h2 class="text-lg font-semibold text-gray-800 mb-5">Account Details</h2>

            @if($profileSuccess)
                <div class="mb-4 p-3 rounded-lg bg-green-50 border border-green-200 text-green-700 text-sm">{{ $profileSuccess }}</div>
            @endif

            <form wire:submit="updateProfile" class="space-y-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Name</label>
                    <input wire:model="name" type="text"
                        class="w-full px-4 py-2.5 rounded-xl border border-gray-300 focus:outline-none focus:ring-2 focus:ring-blue-500 text-sm">
                    @error('name') <p class="mt-1 text-xs text-red-500">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Email</label>
                    <input wire:model="email" type="email"
                        class="w-full px-4 py-2.5 rounded-xl border border-gray-300 focus:outline-none focus:ring-2 focus:ring-blue-500 text-sm">
                    @error('email') <p class="mt-1 text-xs text-red-500">{{ $message }}</p> @enderror
                </div>
                <div class="flex justify-end">
                    <button type="submit" class="px-6 py-2.5 bg-amber-600 hover:bg-amber-700 text-white font-semibold rounded-xl text-sm transition">
                        Save Changes
                    </button>
                </div>
            </form>
        </div>

        <!-- Change Password -->
        <div id="change-password" class="bg-white rounded-2xl shadow border border-gray-100 p-8">
            <h2 class="text-lg font-semibold text-gray-800 mb-5">Change Password</h2>

            @if($passwordSuccess)
                <div class="mb-4 p-3 rounded-lg bg-green-50 border border-green-200 text-green-700 text-sm">{{ $passwordSuccess }}</div>
            @endif
            @if($passwordError)
                <div class="mb-4 p-3 rounded-lg bg-red-50 border border-red-200 text-red-700 text-sm">{{ $passwordError }}</div>
            @endif

            <form wire:submit="updatePassword" class="space-y-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Current Password</label>
                    <input wire:model="current_password" type="password"
                        class="w-full px-4 py-2.5 rounded-xl border border-gray-300 focus:outline-none focus:ring-2 focus:ring-blue-500 text-sm">
                    @error('current_password') <p class="mt-1 text-xs text-red-500">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">New Password</label>
                    <input wire:model="new_password" type="password"
                        class="w-full px-4 py-2.5 rounded-xl border border-gray-300 focus:outline-none focus:ring-2 focus:ring-blue-500 text-sm">
                    @error('new_password') <p class="mt-1 text-xs text-red-500">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Confirm New Password</label>
                    <input wire:model="new_password_confirmation" type="password"
                        class="w-full px-4 py-2.5 rounded-xl border border-gray-300 focus:outline-none focus:ring-2 focus:ring-blue-500 text-sm">
                </div>
                <div class="flex justify-end">
                    <button type="submit" class="px-6 py-2.5 bg-gray-800 hover:bg-gray-900 text-white font-semibold rounded-xl text-sm transition">
                        Update Password
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
