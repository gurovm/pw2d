<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Gone - {{ tenant('brand_name') ?? 'pw2d' }}</title>
    @vite(['resources/css/app.css'])
</head>
<body class="antialiased bg-gray-50 flex items-center justify-center min-h-screen">
    <div class="text-center px-6">
        <h1 class="text-6xl font-bold text-gray-300 mb-4">410</h1>
        <h2 class="text-xl font-semibold text-gray-700 mb-2">Product No Longer Available</h2>
        <p class="text-gray-500 mb-8">This product has been removed from our catalog.</p>
        <a href="{{ route('home') }}" class="inline-block px-6 py-3 rounded-lg text-white font-medium transition-colors" style="background: var(--color-primary, #6366f1);">
            Browse Categories
        </a>
    </div>
</body>
</html>
