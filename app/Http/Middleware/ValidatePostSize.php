<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Exceptions\PostTooLargeException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ValidatePostSize
{
    /**
     * Desktop apps need a higher POST limit than the PHP default (2M).
     * Livewire serializes the full component snapshot on every request,
     * which grows with conversation length.
     */
    private const MAX_POST_SIZE = 100 * 1024 * 1024;

    public function handle(Request $request, Closure $next): Response
    {
        $contentLength = $request->server('CONTENT_LENGTH');

        if ($contentLength > 0 && $contentLength > self::MAX_POST_SIZE) {
            throw new PostTooLargeException('The POST data is too large.');
        }

        return $next($request);
    }
}
