<?php

namespace App\Http\Middleware;

use App\Enums\UserRole;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserHasRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $allowedRoles = array_filter(array_map(
            static fn (string $role): ?UserRole => UserRole::tryFrom($role),
            $roles,
        ));

        if (! in_array($request->user()?->role, $allowedRoles, true)) {
            return new JsonResponse([
                'message' => 'You are not authorized to access this resource.',
            ], Response::HTTP_FORBIDDEN);
        }

        return $next($request);
    }
}
