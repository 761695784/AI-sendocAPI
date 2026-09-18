<?php

namespace App\Http\Controllers;

use Carbon\Carbon;
use App\Models\Document;
use App\Models\EmailLog;
use App\Models\Notification;
use Illuminate\Http\Request;
use App\Models\DeclarationDePerte;
use Illuminate\Support\Facades\Auth;
use App\Http\Requests\StoreNotificationRequest;
use App\Http\Requests\UpdateNotificationRequest;

class NotificationController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index() {
        // Récupérer toutes les notifications
        $notifications = Notification::all();
        return response()->json(['data' => $notifications]);
    }

    public function markAsRead($id) {
        $notification = Notification::find($id);

        if (!$notification) {
            return response()->json(['message' => 'Notification not found.'], 404);
        }

        // Marquez la notification comme lue
        $notification->is_read = true;
        $notification->save();

        // Supprimez la notification de la base de données
        $notification->delete();

        return response()->json(['message' => 'Notification marked as read and deleted.']);
    }

     public function showAllEmails()
     {
         // Récupérer tous les logs d'emails
         $emailLogs = EmailLog::with(['document', 'requester', 'publisher', 'declarant'])->get();

         return response()->json([
             'success' => true,
             'data' => $emailLogs->map(function($emailLog) {
                 return [
                     'id' => $emailLog->id,
                     'from' => $emailLog->from,
                     'to' => $emailLog->to,
                     'subject' => $emailLog->subject,
                     'body' => $emailLog->body,
                     'document_id' => $emailLog->document->id ?? null,
                     'requester_user_id' => $emailLog->requester->id ?? null,
                     'publisher_user_id' => $emailLog->publisher->id ?? null,
                     'declarant_user_id' => $emailLog->declarant->id ?? null,
                     'created_at' => $emailLog->created_at,
                 ];
             })
         ], 200);
     }


    public function showAllCorrespondenceEmails()
    {
        // Récupérer tous les logs d'emails de correspondance
        $correspondenceEmails = EmailLog::with(['document', 'requester', 'publisher', 'declarant'])
                                        ->where('subject', 'like', 'Correspondance%')
                                        ->get();

        return response()->json([
            'success' => true,
            'data' => $correspondenceEmails->map(function($emailLog) {
                return [
                    'id' => $emailLog->id,
                    'from' => $emailLog->from,
                    'to' => $emailLog->to,
                    'subject' => $emailLog->subject,
                    'body' => $emailLog->body,
                    'document_id' => $emailLog->document->id ?? null,
                    'requester_user_id' => $emailLog->requester->id ?? null,
                    'publisher_user_id' => $emailLog->publisher->id ?? null,
                    'declarant_user_id' => $emailLog->declarant->id ?? null,
                    'created_at' => $emailLog->created_at,
                ];
            })
        ], 200);
    }

    public function showAllRestitutionEmails()
    {
        // Récupérer tous les logs d'emails de demande de restitution
        $restitutionEmails = EmailLog::with(['document', 'requester', 'publisher', 'declarant'])
                                    ->where('subject', 'like', 'Demande de restitution%')
                                    ->get();

        return response()->json([
            'success' => true,
            'data' => $restitutionEmails->map(function($emailLog) {
                return [
                    'id' => $emailLog->id,
                    'from' => $emailLog->from,
                    'to' => $emailLog->to,
                    'subject' => $emailLog->subject,
                    'body' => $emailLog->body,
                    'document_id' => $emailLog->document->id ?? null,
                    'requester_user_id' => $emailLog->requester->id ?? null,
                    'publisher_user_id' => $emailLog->publisher->id ?? null,
                    'declarant_user_id' => $emailLog->declarant->id ?? null,
                    'created_at' => $emailLog->created_at,
                ];
            })
        ], 200);
    }

    // API Controller in Laravel (Example)
    public function getAllData()
    {
        // Récupère toutes les déclarations (y compris les supprimées avec soft delete)
        $declarations = DeclarationDePerte::withTrashed()->with(['user', 'documentType'])->get();

        // Récupère toutes les publications (y compris les supprimées avec soft delete)
        $publications = Document::withTrashed()->with(['user', 'documentType'])->get();

        // Récupère tous les emails envoyés liés aux documents avec les relations associées
        $emailsSent = EmailLog::with(['document', 'requester', 'publisher', 'declarant'])->get();

        return response()->json([
            'declarations' => $declarations,
            'publications' => $publications,
            'emailsSent' => $emailsSent->map(function($emailLog) {
                return [
                    'id' => $emailLog->id,
                    'from' => $emailLog->from,
                    'to' => $emailLog->to,
                    'subject' => $emailLog->subject,
                    'body' => $emailLog->body,
                    'document_id' => $emailLog->document->id ?? null,
                    'requester_user_id' => $emailLog->requester->id ?? null,
                    'publisher_user_id' => $emailLog->publisher->id ?? null,
                    'declarant_user_id' => $emailLog->declarant->id ?? null,
                    'created_at' => $emailLog->created_at,
                ];
            }),
        ]);
    }



    public function getRestitutionRequestCount()
    {
        // Compte le nombre d'enregistrements dans la table des logs d'emails liés à une restitution
        $count = EmailLog::where('subject', 'LIKE', '%Demande de restitution%')->count();

        return response()->json(['count' => $count]);
    }



    public function getNewNotifications(Request $request)
    {
        // Récupérer le paramètre lastChecked
        $lastChecked = $request->query('lastChecked');

        // Convertir en instance de Carbon pour s'assurer que c'est une date valide
        try {
            $lastCheckedDate = Carbon::parse($lastChecked);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Invalid date format'
            ], 400);
        }

        // Récupérer les nouvelles publications
        $newDocuments = Document::where('created_at', '>', $lastCheckedDate)->get();

        // Récupérer les nouvelles déclarations
        $newDeclarations = DeclarationDePerte::where('created_at', '>', $lastCheckedDate)->get();

        return response()->json([
            'newDocuments' => $newDocuments,
            'newDeclarations' => $newDeclarations,
        ]);
    }

}
