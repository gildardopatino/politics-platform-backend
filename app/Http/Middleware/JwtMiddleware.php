<?php

namespace App\Http\Middleware;

use Closure;
use Exception;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Tymon\JWTAuth\Exceptions\TokenExpiredException;
use Tymon\JWTAuth\Exceptions\TokenInvalidException;
use Tymon\JWTAuth\Exceptions\JWTException;
use Tymon\JWTAuth\Facades\JWTAuth;

class JwtMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        try {
            // Intentar autenticar al usuario con el token
            $user = JWTAuth::parseToken()->authenticate();
            
            if (!$user) {
                return response()->json([
                    'error' => 'user_not_found',
                    'message' => 'User not found'
                ], 401);
            }

        } catch (TokenExpiredException $e) {
            // Token expirado - intentar refresh automático
            try {
                // Intentar refrescar el token
                $newToken = JWTAuth::parseToken()->refresh();
                
                // Autenticar con el nuevo token
                $user = JWTAuth::setToken($newToken)->authenticate();
                
                // Agregar el nuevo token a la respuesta
                $response = $next($request);
                $response->headers->set('Authorization', 'Bearer ' . $newToken);
                $response->headers->set('X-Token-Refreshed', 'true');
                
                return $response;
                
            } catch (JWTException $e) {
                // No se pudo refrescar - el refresh token también expiró
                return response()->json([
                    'error' => 'token_expired',
                    'message' => 'Token has expired and could not be refreshed. Please login again.',
                    'requires_login' => true
                ], 401);
            }
            
        } catch (TokenInvalidException $e) {
            return response()->json([
                'error' => 'token_invalid',
                'message' => 'Token is invalid'
            ], 401);
            
        } catch (JWTException $e) {
            return response()->json([
                'error' => 'token_absent',
                'message' => 'Token not provided'
            ], 401);
            
        } catch (Exception $e) {
            return response()->json([
                'error' => 'authorization_error',
                'message' => 'Authorization error: ' . $e->getMessage()
            ], 401);
        }

        return $next($request);
    }
}
