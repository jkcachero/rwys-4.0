<x-auth.layout title="Register">
    <h1 class="text-3xl font-semibold">Create your account</h1>
    <p class="mb-8 mt-2 text-slate-500">Start with a secure RWYS account.</p>
    <form method="POST" action="{{ route('register') }}" class="space-y-4">
        @csrf
        <label class="block text-sm font-medium">Name<input name="name" value="{{ old('name') }}" required class="mt-2 w-full rounded-lg border border-slate-300 px-3 py-2.5">@error('name')<span class="text-xs text-red-600">{{ $message }}</span>@enderror</label>
        <label class="block text-sm font-medium">Email<input name="email" type="email" value="{{ old('email') }}" required class="mt-2 w-full rounded-lg border border-slate-300 px-3 py-2.5">@error('email')<span class="text-xs text-red-600">{{ $message }}</span>@enderror</label>
        <label class="block text-sm font-medium">Password<input name="password" type="password" required class="mt-2 w-full rounded-lg border border-slate-300 px-3 py-2.5">@error('password')<span class="text-xs text-red-600">{{ $message }}</span>@enderror</label>
        <label class="block text-sm font-medium">Confirm password<input name="password_confirmation" type="password" required class="mt-2 w-full rounded-lg border border-slate-300 px-3 py-2.5"></label>
        <button class="w-full rounded-lg bg-slate-950 px-4 py-3 font-medium text-white">Create account</button>
    </form>
    <p class="mt-6 text-center text-sm"><a href="{{ route('login') }}" class="underline">Already registered? Sign in</a></p>
</x-auth.layout>
