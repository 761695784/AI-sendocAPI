<?php

namespace App\Http\Controllers\V2;

use App\Http\Controllers\Controller;
use App\Models\InboxMessage;
use App\Services\Access\OrganizationAccess;
use Illuminate\Http\Request;

/**
 * Boîte de réception de l'agent : messages de ses structures + messages personnels.
 */
class InboxController extends Controller
{
    public function __construct(private OrganizationAccess $access) {}

    public function index(Request $request)
    {
        $user = $request->user();

        $page = InboxMessage::query()
            ->with('organization:id,uuid,name')
            ->withExists(['readers as is_read' => fn ($q) => $q->where('users.id', $user->id)])
            ->visibleTo($user, $this->organizationIds($request))
            ->when($request->boolean('unread'), fn ($q) => $q->unreadBy($user))
            ->orderByDesc('priority')
            ->latest('id')
            ->paginate(30);

        $page->getCollection()->transform(fn (InboxMessage $m) => [
            'uuid'         => $m->uuid,
            'event'        => $m->event,
            'title'        => $m->title,
            'body'         => $m->body,
            'link'         => $m->link,
            'priority'     => $m->priority,
            'is_read'      => (bool) $m->is_read,
            'organization' => $m->organization ? ['uuid' => $m->organization->uuid, 'name' => $m->organization->name] : null,
            'created_at'   => $m->created_at,
        ]);

        return response()->json($page);
    }

    /** Compteur pour la pastille de l'interface. */
    public function unreadCount(Request $request)
    {
        $user = $request->user();

        return response()->json([
            'unread' => InboxMessage::visibleTo($user, $this->organizationIds($request))->unreadBy($user)->count(),
        ]);
    }

    public function markRead(Request $request, InboxMessage $message)
    {
        $user = $request->user();
        abort_unless(
            InboxMessage::whereKey($message->id)->visibleTo($user, $this->organizationIds($request))->exists(),
            404
        );

        $message->readers()->syncWithoutDetaching([$user->id => ['read_at' => now()]]);

        return response()->json(['uuid' => $message->uuid, 'is_read' => true]);
    }

    public function markAllRead(Request $request)
    {
        $user = $request->user();
        $ids  = InboxMessage::visibleTo($user, $this->organizationIds($request))->unreadBy($user)->pluck('id');

        $user->belongsToMany(InboxMessage::class, 'inbox_message_reads')
            ->syncWithoutDetaching($ids->mapWithKeys(fn ($id) => [$id => ['read_at' => now()]])->all());

        return response()->json(['marked' => $ids->count()]);
    }

    /** Structures dont l'utilisateur est membre actif (pas toute la descendance : trop de bruit). */
    private function organizationIds(Request $request): array
    {
        return $this->access->memberships($request->user())->pluck('organization_id')->unique()->values()->all();
    }
}
