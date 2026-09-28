<?php

namespace App\Http\Controllers;

use App\Http\Resources\DocumentResource;
use App\Models\DeclarationDePerte;
use App\Models\Document;
use App\Models\EmailLog;
use App\Models\Notification;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Notifications et journaux d'emails.
 *
 * Règle : l'Admin voit l'ensemble de la plateforme ; un citoyen ne voit que
 * ce qui le concerne (ses déclarations, ses publications, les emails dont il
 * est destinataire, demandeur, publicateur ou déclarant).
 */
class NotificationController extends Controller
{
    private function currentUser(): User
    {
        return Auth::user();
    }

    private function isAdmin(): bool
    {
        return $this->currentUser()->hasRole('Admin');
    }

    /** Notifications visibles par l'utilisateur courant. */
    private function notificationsQuery(): Builder
    {
        $query = Notification::query()->latest();

        if (! $this->isAdmin()) {
            $userId = $this->currentUser()->id;
            $query->whereHas('declaration', fn ($q) => $q->withTrashed()->where('user_id', $userId));
        }

        return $query;
    }

    /** Journaux d'emails visibles par l'utilisateur courant. */
    private function emailLogsQuery(): Builder
    {
        $query = EmailLog::query()->latest();

        if (! $this->isAdmin()) {
            $user = $this->currentUser();
            $query->where(function ($q) use ($user) {
                $q->where('to', $user->email)
                    ->orWhere('requester_user_id', $user->id)
                    ->orWhere('publisher_user_id', $user->id)
                    ->orWhere('declarant_user_id', $user->id);
            });
        }

        return $query;
    }

    private function formatEmailLog(EmailLog $emailLog): array
    {
        return [
            'id'                => $emailLog->id,
            'from'              => $emailLog->from,
            'to'                => $emailLog->to,
            'subject'           => $emailLog->subject,
            // Le corps peut contenir des numéros de téléphone : Admin uniquement.
            'body'              => $this->isAdmin() ? $emailLog->body : null,
            'document_id'       => $emailLog->document_id,
            'requester_user_id' => $emailLog->requester_user_id,
            'publisher_user_id' => $emailLog->publisher_user_id,
            'declarant_user_id' => $emailLog->declarant_user_id,
            'created_at'        => $emailLog->created_at,
        ];
    }

    public function index()
    {
        return response()->json(['data' => $this->notificationsQuery()->get()]);
    }

    /**
     * Marque une notification comme lue puis la retire de la liste
     * (comportement V1 conservé). Seul le propriétaire (ou l'Admin) peut le faire.
     */
    public function markAsRead($id)
    {
        $notification = $this->notificationsQuery()
            ->where(ctype_digit((string) $id) ? 'id' : 'uuid', $id)
            ->first();

        if (! $notification) {
            return response()->json(['message' => 'Notification not found.'], 404);
        }

        $notification->is_read = true;
        $notification->save();
        $notification->delete();

        return response()->json(['message' => 'Notification marked as read and deleted.']);
    }

    public function showAllEmails()
    {
        $logs = $this->emailLogsQuery()->get();

        return response()->json([
            'success' => true,
            'data'    => $logs->map(fn ($log) => $this->formatEmailLog($log)),
        ], 200);
    }

    public function showAllCorrespondenceEmails()
    {
        $logs = $this->emailLogsQuery()->where('subject', 'like', 'Correspondance%')->get();

        return response()->json([
            'success' => true,
            'data'    => $logs->map(fn ($log) => $this->formatEmailLog($log)),
        ], 200);
    }

    public function showAllRestitutionEmails()
    {
        $logs = $this->emailLogsQuery()->where('subject', 'like', 'Demande de restitution%')->get();

        return response()->json([
            'success' => true,
            'data'    => $logs->map(fn ($log) => $this->formatEmailLog($log)),
        ], 200);
    }

    public function getAllData(Request $request)
    {
        $user = $this->currentUser();

        $declarations = DeclarationDePerte::withTrashed()->with(['user', 'documentType'])
            ->when(! $this->isAdmin(), fn ($q) => $q->where('user_id', $user->id))
            ->get();

        $publications = Document::withTrashed()->with(['user', 'documentType', 'media'])
            ->when(! $this->isAdmin(), fn ($q) => $q->where('user_id', $user->id))
            ->get();

        return response()->json([
            'declarations' => $declarations,
            'publications' => DocumentResource::collection($publications)->resolve($request),
            'emailsSent'   => $this->emailLogsQuery()->get()->map(fn ($log) => $this->formatEmailLog($log)),
        ]);
    }

    public function getRestitutionRequestCount()
    {
        $count = $this->emailLogsQuery()->where('subject', 'LIKE', '%Demande de restitution%')->count();

        return response()->json(['count' => $count]);
    }

    public function getNewNotifications(Request $request)
    {
        try {
            $lastCheckedDate = Carbon::parse($request->query('lastChecked'));
        } catch (\Exception $e) {
            return response()->json(['error' => 'Invalid date format'], 400);
        }

        $user = $this->currentUser();

        $newDocuments = Document::where('created_at', '>', $lastCheckedDate)
            ->with('media')
            ->when(! $this->isAdmin(), fn ($q) => $q->where('user_id', $user->id))
            ->get();

        $newDeclarations = DeclarationDePerte::where('created_at', '>', $lastCheckedDate)
            ->when(! $this->isAdmin(), fn ($q) => $q->where('user_id', $user->id))
            ->get();

        return response()->json([
            'newDocuments'    => DocumentResource::collection($newDocuments)->resolve($request),
            'newDeclarations' => $newDeclarations,
        ]);
    }
}
