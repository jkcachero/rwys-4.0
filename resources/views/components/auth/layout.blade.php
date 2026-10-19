<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title ?? config('app.name', 'Laravel') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-slate-100 px-6 py-12 text-slate-950">
    <main class="mx-auto max-w-md rounded-2xl bg-white p-8 shadow-xl">
        <p class="mb-8 text-sm font-semibold uppercase tracking-[0.2em] text-slate-500">RWYS</p>
        {{ $slot }}
    </main>
</body>
</html>
