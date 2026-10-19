<x-auth.layout title="Dashboard">
    <div class="flex items-start justify-between gap-4">
        <div>
            <p class="text-sm text-slate-500">Signed in as</p>
            <h1 class="mt-1 text-3xl font-semibold">{{ auth()->user()->name }}</h1>
            <p class="text-slate-600">{{ auth()->user()->email }}</p>
        </div>
        <form method="POST" action="{{ route('logout') }}">
            @csrf
            <button class="rounded-lg border border-slate-300 px-3 py-2 text-sm font-medium">Log out</button>
        </form>
    </div>
</x-auth.layout>
