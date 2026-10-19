<x-auth.layout title="Reset password">
    <h1 class="text-3xl font-semibold">Choose a new password</h1>
    <form method="POST" action="{{ route('password.update') }}" class="mt-8 space-y-4">
        @csrf
        <input type="hidden" name="token" value="{{ $request->route('token') }}">
        <label class="block text-sm font-medium">Email<input name="email" type="email" value="{{ old('email', $request->email) }}" required class="mt-2 w-full rounded-lg border border-slate-300 px-3 py-2.5"></label>
        <label class="block text-sm font-medium">New password<input name="password" type="password" required class="mt-2 w-full rounded-lg border border-slate-300 px-3 py-2.5">@error('password')<span class="text-xs text-red-600">{{ $message }}</span>@enderror</label>
        <label class="block text-sm font-medium">Confirm password<input name="password_confirmation" type="password" required class="mt-2 w-full rounded-lg border border-slate-300 px-3 py-2.5"></label>
        <button class="w-full rounded-lg bg-slate-950 px-4 py-3 font-medium text-white">Reset password</button>
    </form>
</x-auth.layout>
