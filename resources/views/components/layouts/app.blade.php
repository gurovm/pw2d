<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>{{ $metaTitle ?? 'pw2d - Power to Decide | AI Tech Recommendations' }}</title>
    <meta name="description" content="{{ $metaDescription ?? 'Discover the best tech products tailored to your exact needs using our AI-powered recommendation engine.' }}">
    <link rel="canonical" href="{{ $canonicalUrl ?? request()->url() }}">
    @if(isset($schemaJson))
        <script type="application/ld+json">{!! $schemaJson !!}</script>
    @endif
    @if(config('services.posthog.key'))
        <script>
            !function(t,e){var o,n,p,r;e.__SV||(window.posthog=e,e._i=[],e.init=function(i,s,a){function g(t,e){var o=e.split(".");2==o.length&&(t=t[o[0]],e=o[1]),t[e]=function(){t.push([e].concat(Array.prototype.slice.call(arguments,0)))}}(p=t.createElement("script")).type="text/javascript",p.async=!0,p.src=s.api_host+"/static/array.js",(r=t.getElementsByTagName("script")[0]).parentNode.insertBefore(p,r);var u=e;for(void 0!==a?u=e[a]=[]:a="posthog",u.people=u.people||[],u.toString=function(t){var e="posthog";return"posthog"!==a&&(e+="."+a),t||(e+=" (stub)"),e},u.people.toString=function(){return u.toString(1)+".people (stub)"},o="capture identify alias people.set people.set_once set_config register register_once unregister opt_out_capturing has_opted_out_capturing opt_in_capturing reset isFeatureEnabled onFeatureFlags getFeatureFlag getFeatureFlagPayload reloadFeatureFlags group updateEarlyAccessFeatureEnrollment getEarlyAccessFeatures getActiveMatchingSurveys getSurveys onSessionId".split(" "),n=0;n<o.length;n++)g(u,o[n]);e._i.push([i,s,a])},e.__SV=1)}(document,window.posthog||[]);
            posthog.init('{{ config('services.posthog.key') }}', {
                api_host: '{{ config('services.posthog.host') }}',
                person_profiles: 'identified_only' // חוסך בעלויות, מזהה משתמשים בעילום שם בהתחלה
            })
        </script>
    @endif

    @if(config('services.google.analytics_id'))
        <script async src="https://www.googletagmanager.com/gtag/js?id={{ config('services.google.analytics_id') }}"></script>
        <script>
            window.dataLayer = window.dataLayer || [];
            function gtag(){dataLayer.push(arguments);}
            gtag('js', new Date());

            gtag('config', '{{ config('services.google.analytics_id') }}');
        </script>
    @endif
    
    <!-- Fonts -->
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=inter:400,500,600,700&display=swap" rel="stylesheet" />

    <!-- Styles -->
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>
<body class="antialiased bg-white text-deep-blue">
    <!-- Navigation -->
    <livewire:navigation />

    <!-- Main Content -->
    <main>
        {{ $slot }}
    </main>

    <!-- Footer -->
    <footer class="bg-gray-900 mt-20">
        <!-- Affiliate Disclosure Bar -->
        <div class="border-b border-gray-700 bg-gray-800">
            <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-3 text-center">
                <p class="text-amber-400 text-xs">
                    <strong>Affiliate Disclosure:</strong>
                    <span class="text-gray-400 font-normal"> Pw2D is reader-supported. When you buy through links on our site, we may earn an affiliate commission at no extra cost to you.</span>
                </p>
            </div>
        </div>

        <!-- Main Footer Content -->
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-10">
            <div class="flex flex-col sm:flex-row items-center justify-between gap-6">
                <!-- Brand -->
                <div class="flex flex-col items-center sm:items-start">
                    <span class="text-white font-bold text-lg tracking-tight">Pw2D</span>
                    <span class="text-gray-500 text-xs mt-0.5">Power to Decide</span>
                </div>

                <!-- Nav Links -->
                <nav class="flex flex-wrap justify-center gap-x-6 gap-y-2">
                    <a href="{{ route('about') }}" class="text-gray-400 hover:text-white text-sm transition-colors duration-150">About</a>
                    <a href="{{ route('contact') }}" class="text-gray-400 hover:text-white text-sm transition-colors duration-150">Contact</a>
                    <a href="{{ route('privacy-policy') }}" class="text-gray-400 hover:text-white text-sm transition-colors duration-150">Privacy Policy</a>
                    <a href="{{ route('terms-of-service') }}" class="text-gray-400 hover:text-white text-sm transition-colors duration-150">Terms of Service</a>
                </nav>
            </div>

            <!-- Divider + Copyright -->
            <div class="border-t border-gray-800 mt-8 pt-6 text-center">
                <p class="text-gray-600 text-xs">&copy; {{ date('Y') }} Pw2D &mdash; Power to Decide. All rights reserved.</p>
            </div>
        </div>
    </footer>

    @livewireScripts
</body>
</html>
