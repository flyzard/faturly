<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class SetTenantContext
{
    /**
     * The GUC is written unconditionally, empty string included: skipping it would leave a
     * persistent connection carrying the previous request's tenant. The policies wrap the
     * setting in NULLIF, so the empty string matches no rows rather than raising a cast error.
     *
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $connection = DB::connection();

        if ($connection->getDriverName() === 'pgsql') {
            $connection->select('select set_config(?, ?, false)', [
                'app.tenant_id',
                $this->resolveTenantId($request) ?? '',
            ]);
        }

        return $next($request);
    }

    public function resolveTenantId(Request $request): ?string
    {
        $user = $request->user();

        return $user instanceof User ? $user->tenant_id : null;
    }
}
