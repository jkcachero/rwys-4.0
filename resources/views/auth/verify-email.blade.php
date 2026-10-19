<x-auth.layout title="Verify email">
    <h1 class="text-3xl font-semibold">Verify your email</h1>
    <p class="mt-2 text-slate-500">Check your inbox for the verification link.</p>
    @if (session('status') == 'verification-link-sent')<p class="mt-4 text-sm text-green-600">A new verification link was sent.</p>@endif
    <form method="POST" action="{{ route('verification.send') }}" class="mt-8">@csrf<button class="w-full rounded-lg bg-slate-950 px-4 py-3 font-medium text-white">Resend verification email</button></form>
    <form method="POST" action="{{ route('logout') }}" class="mt-4">@csrf<button class="w-full rounded-lg border border-slate-300 px-4 py-3 font-medium">Log out</button></form>
</x-auth.layout>
