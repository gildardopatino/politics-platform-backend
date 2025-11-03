<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class EmailNotificationService
{
    protected string $webhookUrl;

    public function __construct()
    {
        $this->webhookUrl = config('services.n8n.webhook_url', 'https://n8n.appcoresas.cloud/webhook/notifications/emails');
    }

    /**
     * Send welcome email with credentials to new user
     */
    public function sendWelcomeEmail(string $email, string $name, string $password, string $userToken): bool
    {
        try {
            $message = $this->buildWelcomeMessage($name, $email, $password);
            
            return $this->sendEmail($email, $message, $userToken);
        } catch (\Exception $e) {
            Log::error('Failed to send welcome email', [
                'email' => $email,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Send email via n8n webhook
     * 
     * @param string $email Recipient email
     * @param string $message Email message (HTML)
     * @param string $userToken JWT token of the authenticated user making the request
     */
    public function sendEmail(string $email, string $message, string $userToken): bool
    {
        try {
            Log::info('Attempting to send email via webhook', [
                'email' => $email,
                'webhook_url' => $this->webhookUrl,
                'token_length' => strlen($userToken)
            ]);
            
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $userToken,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ])->post($this->webhookUrl, [
                'email' => $email,
                'message' => $message,
            ]);

            Log::info('Email webhook response received', [
                'email' => $email,
                'status' => $response->status(),
                'successful' => $response->successful(),
                'body_preview' => substr($response->body(), 0, 200)
            ]);

            if ($response->successful()) {
                Log::info('Email sent successfully', ['email' => $email]);
                return true;
            }

            Log::error('Email webhook returned non-success status', [
                'email' => $email,
                'status' => $response->status(),
                'headers' => $response->headers(),
                'body' => $response->body()
            ]);
            
            return false;
        } catch (\Exception $e) {
            Log::error('Failed to send email via webhook', [
                'email' => $email,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return false;
        }
    }

    /**
     * Build welcome message HTML
     */
    protected function buildWelcomeMessage(string $name, string $email, string $password): string
    {
        return "
            <!DOCTYPE html>
            <html>
            <head>
                <meta charset='UTF-8'>
                <style>
                    body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
                    .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                    .header { background-color: #4F46E5; color: white; padding: 20px; text-align: center; border-radius: 5px 5px 0 0; }
                    .content { background-color: #f9fafb; padding: 30px; border: 1px solid #e5e7eb; }
                    .credentials { background-color: white; padding: 20px; margin: 20px 0; border-left: 4px solid #4F46E5; border-radius: 4px; }
                    .credential-item { margin: 10px 0; }
                    .credential-label { font-weight: bold; color: #6b7280; }
                    .credential-value { color: #1f2937; font-size: 16px; }
                    .password { background-color: #fef3c7; padding: 8px 12px; border-radius: 4px; font-family: monospace; font-size: 18px; letter-spacing: 2px; }
                    .footer { text-align: center; margin-top: 20px; color: #6b7280; font-size: 12px; }
                    .button { display: inline-block; padding: 12px 24px; background-color: #4F46E5; color: white; text-decoration: none; border-radius: 5px; margin: 20px 0; }
                    .warning { background-color: #fef2f2; border-left: 4px solid #ef4444; padding: 15px; margin: 20px 0; border-radius: 4px; }
                </style>
            </head>
            <body>
                <div class='container'>
                    <div class='header'>
                        <h1>🎉 Bienvenido a Campaign Manager</h1>
                    </div>
                    <div class='content'>
                        <p>Hola <strong>{$name}</strong>,</p>
                        
                        <p>Tu cuenta en <strong>Campaign Manager</strong> ha sido creada exitosamente. Ya puedes acceder a la plataforma con las siguientes credenciales:</p>
                        
                        <div class='credentials'>
                            <div class='credential-item'>
                                <div class='credential-label'>📧 Email:</div>
                                <div class='credential-value'>{$email}</div>
                            </div>
                            <div class='credential-item'>
                                <div class='credential-label'>🔑 Contraseña temporal:</div>
                                <div class='password'>{$password}</div>
                            </div>
                        </div>
                        
                        <div class='warning'>
                            <strong>⚠️ Importante:</strong> Por razones de seguridad, te recomendamos cambiar tu contraseña después del primer inicio de sesión.
                        </div>
                        
                        <p style='text-align: center;'>
                            <a href='#' class='button'>Iniciar Sesión</a>
                        </p>
                        
                        <p>Si tienes alguna pregunta o necesitas ayuda, no dudes en contactar a tu supervisor o al equipo de soporte.</p>
                        
                        <p>Saludos,<br><strong>Equipo de Campaign Manager</strong></p>
                    </div>
                    <div class='footer'>
                        <p>Este es un correo automático, por favor no respondas a este mensaje.</p>
                        <p>&copy; " . date('Y') . " Campaign Manager. Todos los derechos reservados.</p>
                    </div>
                </div>
            </body>
            </html>
        ";
    }
}
