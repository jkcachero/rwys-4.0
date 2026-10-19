<x-auth.layout title="Forgot password">
    <h1 class="text-3xl font-semibold">Reset your password</h1>
    <p class="mb-8 mt-2 text-slate-500">We will email you a reset link.</p>
    @if (session('status'))<p class="mb-4 text-sm text-green-600">{{ session('status') }}</p>@endif
    <form method="POST" action="{{ route('password.email') }}" class="space-y-5">
        @csrf
        <label class="block text-sm font-medium">Email<input name="email" type="email" value="{{ old('email') }}" required class="mt-2 w-full rounded-lg border border-slate-300 px-3 py-2.5">@error('email')<span class="text-xs text-red-600">{{ $message }}</span>@enderror</label>
        <button class="w-full rounded-lg bg-slate-950 px-4 py-3 font-medium text-white">Send reset link</button>
    </form>
    <p class="mt-6 text-center text-sm"><a href="{{ route('login') }}" class="underline">Back to login</a></p>
</x-auth.layout>
