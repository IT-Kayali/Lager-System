<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\Offer;
use App\Models\User;
use App\Services\WarehouseNotificationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class OfferInternalNoteController extends Controller
{
    public function store(Request $request, Offer $offer, WarehouseNotificationService $warehouseNotifications): RedirectResponse
    {
        abort_unless(
            auth()->user()?->hasRole([
                User::ROLE_MANAGER,
                User::ROLE_SALES,
                User::ROLE_WAREHOUSE,
            ]),
            403,
            'Keine Berechtigung für interne Angebotsnotizen.'
        );

        if ($request->user()?->isWarehouse()) {
            abort_unless(in_array($offer->status, [
                Offer::STATUS_IN_PROGRESS,
                Offer::STATUS_READY,
                Offer::STATUS_COMPLETED,
            ], true), 403, 'Diese Bestellung ist für das Lager nicht freigegeben.');
        }

        $data = $request->validate([
            'note' => ['required', 'string', 'max:5000'],
        ]);

        $text = trim($data['note']);
        if ($text === '') {
            throw ValidationException::withMessages(['note' => 'Bitte eine interne Notiz eingeben.']);
        }

        DB::transaction(function () use ($offer, $text, $warehouseNotifications): void {
            $note = $offer->internalNotes()->create([
                'user_id' => auth()->id(),
                'note' => $text,
            ]);

            ActivityLog::record('offer.internal_note.created', $offer, [
                'offer_number' => $offer->offer_number,
                'note_id' => $note->id,
            ]);

            $warehouseNotifications->notifyOfferInternalNote($offer, $note->load('user'));
        });

        return back()->with('success', 'Interne Notiz wurde hinzugefügt.');
    }
}
