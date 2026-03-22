<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Tenant Route Middleware
|--------------------------------------------------------------------------
|
| Front-end routes live in web.php (single set, named routes).
| This file applies InitializeTenancyByDomain middleware to ALL web
| routes when the request comes from a tenant domain.
|
| On central domains, these middleware are skipped (PreventAccessFromCentralDomains).
|
*/

// Tenant-specific middleware is applied via TenancyServiceProvider::mapRoutes()
// and the InitializeTenancyByDomain identification middleware.
// No route declarations needed here — web.php serves all domains.
