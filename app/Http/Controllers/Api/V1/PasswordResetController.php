<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\EmailNotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;

class PasswordResetController extends Controller
{
    /**
     * Request a password reset link. This generates a token and sends an email via n8n webhook.
     */
    public function forgot(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => 'required|email'
        ]);

        $user = User::where('email', $data['email'])->first();

        // Always return success response to avoid user enumeration
        if (!$user) {
            return response()->json(['message' => 'If the email exists, a reset link will be sent.']);
        }

        // Generate a one-time token and store hashed in DB
        $rawToken = Str::random(64);
        $hashed = Hash::make($rawToken);

        DB::table('password_resets')->updateOrInsert(
            ['email' => $user->email],
            ['token' => $hashed, 'created_at' => now()]
        );

        // Build reset URL (frontend handles the form). Include raw token in URL.
        $resetUrl = rtrim(config('app.frontend_url', config('app.url')), '/')
            . '/reset-password?token=' . urlencode($rawToken) . '&email=' . urlencode($user->email);

        $message = "<p>Hola {$user->name},</p>"
            . "<p>Haz solicitado restablecer tu contraseña. Haz click en el siguiente enlace para cambiarla:</p>"
            . "<p><a href=\"{$resetUrl}\">Restablecer contraseña</a></p>"
            . "<p>Si no solicitaste esto, ignora este correo.</p>";

        $n8nToken = (string) config('services.n8n.auth_token', '');
        $emailService = app(EmailNotificationService::class);

        $sent = $emailService->sendEmail(
            $user->email, 
            $message, 
            $n8nToken,
            'Recuperación de contraseña - Campaign Manager'
        );

        if (!$sent) {
            Log::warning('Failed to send password reset email via webhook', [
                'email' => $user->email,
                'environment' => config('app.env')
            ]);
            
            // In production, return error. In development, allow to continue for testing
            if (config('app.env') === 'production') {
                return response()->json(['message' => 'Error sending reset email'], 500);
            }
        }

        return response()->json(['message' => 'If the email exists, a reset link will be sent.']);
    }

    /**
     * Perform the password reset using token and new password
     */
    public function reset(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => 'required|email',
            'token' => 'required|string',
            'password' => 'required|string|confirmed|min:8',
        ]);

        $record = DB::table('password_resets')->where('email', $data['email'])->first();

        if (!$record) {
            return response()->json(['message' => 'Invalid token or email'], 422);
        }

        // Verify token matches stored hash
        if (!Hash::check($data['token'], $record->token)) {
            return response()->json(['message' => 'Invalid token or email'], 422);
        }

        // Verify token not expired (use password broker expiry or default 60 minutes)
        $expires = config('auth.passwords.users.expire', 60);
        try {
            $createdAt = \Carbon\Carbon::parse($record->created_at);
            if (now()->diffInMinutes($createdAt, false) > $expires) {
                return response()->json(['message' => 'Token expired. Please request a new reset link.'], 422);
            }
        } catch (\Exception $e) {
            // If parsing fails, proceed (token may be invalidated later)
        }

        $user = User::where('email', $data['email'])->first();
        if (!$user) {
            return response()->json(['message' => 'User not found'], 404);
        }

        $user->password = Hash::make($data['password']);
        $user->save();

        DB::table('password_resets')->where('email', $data['email'])->delete();

        return response()->json(['message' => 'Password reset successfully.']);
    }
}
