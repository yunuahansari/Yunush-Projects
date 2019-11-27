<?php

namespace App\Http\Middleware;

use Closure;
use JWTAuth;
use Tymon\JWTAuth\Exceptions\JWTException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use App\UserDevice;

class VerifyJWTToken {

    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @return mixed
     */
    public function handle($request, Closure $next) {
         $token = \Request::header('access-token');
        try {
            $user = JWTAuth::toUser($token);
            $check = UserDevice::where('access_token', $request->header('access-token'))->first();
                if( !empty($check)){
                    $user = JWTAuth::toUser($request->header('access-token'));
                    if(!empty($user)){
                        if($user->status == 'inactive'){
                             return response()->json(['success' => false, 'error' => ['message' => 'Your account  is deactivated'], 'code' => 401],401);
                        }
                    }
                }else{
                    throw new \Tymon\JWTAuth\Exceptions\TokenExpiredException;
                }
        } catch (JWTException $e) {
            if ($e instanceof \Tymon\JWTAuth\Exceptions\TokenExpiredException) {
                return response()->json(['success' => false, 'error' => ['message' => 'Token expired'], 'code' => $e->getStatusCode()],401);
            } else if ($e instanceof \Tymon\JWTAuth\Exceptions\TokenInvalidException) {
                return response()->json(['success' => false, 'error' => ['message' => 'Invalid Token'], 'code' => $e->getStatusCode()],401);
            } else {
                return response()->json(['success' => false, 'error' => ['message' => 'token is required'], 'code' => 401],401);
            }
        }
        return $next($request);
    }

}
