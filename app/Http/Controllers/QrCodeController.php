<?php

namespace App\Http\Controllers;

use App\Mail\FoundDocumentNotification;
use App\Models\User;
use App\Services\SmsService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;

/**
 * Scan d'un QR code DocTrack par un trouveur : il laisse son numéro,
 * le propriétaire est prévenu par email et SMS.
 */
class QrCodeController extends Controller
{
    public function __construct(protected SmsService $smsService) {}

    public function handleFoundQr(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'token'        => 'required|string|max:100',
            'finder_phone' => 'required|string|max:20',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Veuillez fournir des données valides.',
                'errors'  => $validator->errors(),
            ], 422);
        }

        $finderPhone = SmsService::normalizeSenegalPhone($request->input('finder_phone'));
        if (! $finderPhone) {
            return response()->json([
                'success' => false,
                'message' => 'Numéro de téléphone invalide (format sénégalais attendu, ex. 77 123 45 67).',
                'errors'  => ['finder_phone' => ['Numéro invalide.']],
            ], 422);
        }

        $user = User::where('qr_code_token', $request->input('token'))
            ->where('qr_code_expires_at', '>', now())
            ->first();

        if (! $user) {
            return response()->json([
                'success' => false,
                'message' => 'QR code invalide ou expiré.',
            ], 404);
        }

        $emailSent = false;
        try {
            Mail::to($user->email)->send(new FoundDocumentNotification($finderPhone, $user));
            $emailSent = true;
        } catch (\Exception $e) {
            Log::error('QR trouvé : échec email propriétaire.', ['user_id' => $user->id, 'error' => $e->getMessage()]);
        }

        $smsSent = $this->smsService->sendSMS(
            $user->Phone,
            "DocTrack : un de vos documents a été trouvé. Contactez le trouveur au {$finderPhone}."
        );

        activity()
            ->performedOn($user)
            ->withProperties([
                'finder_phone' => SmsService::maskPhone($finderPhone),
                'email_sent'   => $emailSent,
                'sms_sent'     => $smsSent,
                'ip'           => $request->ip(),
            ])
            ->log('QR code scanné par un trouveur');

        return response()->json([
            'success'    => $emailSent || $smsSent,
            'message'    => ($emailSent || $smsSent)
                ? 'Le propriétaire a été prévenu. Merci !'
                : 'Le propriétaire n\'a pas pu être prévenu pour le moment. Veuillez réessayer plus tard.',
            'email_sent' => $emailSent,
            'sms_sent'   => $smsSent,
        ], ($emailSent || $smsSent) ? 200 : 503);
    }
}
