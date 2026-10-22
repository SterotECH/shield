<?php

declare(strict_types=1);

namespace Stero\Shield\Laravel;

use Closure;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Stero\Shield\Http\NormalizedRequest;
use Stero\Shield\Http\RequestFactory;
use Stero\Shield\Runtime\Guard;
use Stero\Shield\Runtime\Inspection;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/** Thin translation layer between Illuminate requests and the runtime. */
final class ShieldMiddleware
{
    public const string REQUEST_ATTRIBUTE = 'shield.request';

    public const string INSPECTION_ATTRIBUTE = 'shield.inspection';

    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next): Response
    {
        try {
            if (Config::boolean('shield.enabled', false)) {
                $guardValue = app(Guard::class);
                if (! $guardValue instanceof Guard) {
                    throw new \UnexpectedValueException('Invalid Shield guard binding');
                }
                $host = $request->getHost();
                if (! mb_check_encoding($host, 'UTF-8')) {
                    throw new \UnexpectedValueException('Invalid host encoding');
                }
                $normalised = RequestFactory::fromGlobals(
                    $request->server->all(),
                    $request->query->all(),
                    '',
                    trustedProxies: [],
                    tenantId: mb_strtolower($host, 'UTF-8'),
                );
                $inspection = $guardValue->before($normalised);
                $request->attributes->set(self::REQUEST_ATTRIBUTE, $normalised);
                $request->attributes->set(self::INSPECTION_ATTRIBUTE, $inspection);

                if ($inspection->decision->blocked) {
                    return new Response(Guard::BLOCK_BODY, $inspection->decision->responseStatus ?? 404, Guard::blockHeaders());
                }
            }
        } catch (Throwable) {
            // Shield must never prevent the application from responding.
        }

        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        try {
            $normalised = $request->attributes->get(self::REQUEST_ATTRIBUTE);
            $inspection = $request->attributes->get(self::INSPECTION_ATTRIBUTE);
            if (! $normalised instanceof NormalizedRequest || ! $inspection instanceof Inspection) {
                return;
            }

            $userId = null;
            try {
                $user = $request->user();
                if ($user instanceof Authenticatable) {
                    $identifier = $user->getAuthIdentifier();
                    if (is_string($identifier) || is_int($identifier)) {
                        $userId = (string) $identifier;
                    }
                }
            } catch (Throwable) {
                // Authentication failures do not affect the completed response.
            }

            $guard = app(Guard::class);
            if ($guard instanceof Guard) {
                $guard->after($normalised, $userId, $inspection);
            }
        } catch (Throwable) {
            // Termination is a best-effort observation after the response.
        }
    }
}
