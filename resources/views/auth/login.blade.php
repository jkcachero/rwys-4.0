<x-auth.layout title="Log in">
    <h1 class="text-3xl font-semibold">Welcome back</h1>
    <p class="mb-8 mt-2 text-slate-500">Sign in to continue.</p>
    @if (session('status'))
        <p class="mb-4 text-sm text-green-600">{{ session('status') }}</p>
    @endif
    <form method="POST" action="{{ route('login') }}" class="space-y-5">
        @csrf
        <label class="block text-sm font-medium">Email<input name="email" type="email" value="{{ old('email') }}" required autofocus class="mt-2 w-full rounded-lg border border-slate-300 px-3 py-2.5">@error('email')<span class="text-xs text-red-600">{{ $message }}</span>@enderror</label>
        <label class="block text-sm font-medium">Password<input name="password" type="password" required class="mt-2 w-full rounded-lg border border-slate-300 px-3 py-2.5">@error('password')<span class="text-xs text-red-600">{{ $message }}</span>@enderror</label>
        <label class="flex items-center gap-2 text-sm text-slate-600"><input name="remember" type="checkbox"> Remember me</label>
        <button class="w-full rounded-lg bg-slate-950 px-4 py-3 font-medium text-white">Sign in</button>
    </form>
    <p class="mt-6 flex justify-between text-sm"><a href="{{ route('password.request') }}" class="underline">Forgot password?</a><a href="{{ route('register') }}" class="underline">Create account</a></p>
</x-auth.layout>
