<?php

use App\Models\Customer;
use App\Models\CustomerGroup;
use App\Models\Offer;
use App\Models\OfferInternalNote;
use App\Models\User;
use App\Models\WarehouseNotification;

beforeEach(function () {
    $this->manager = User::factory()->create(['role' => User::ROLE_MANAGER, 'is_active' => true]);
    $this->sales = User::factory()->create(['role' => User::ROLE_SALES, 'is_active' => true]);
    $this->warehouseA = User::factory()->create(['role' => User::ROLE_WAREHOUSE, 'is_active' => true]);
    $this->warehouseB = User::factory()->create(['role' => User::ROLE_WAREHOUSE, 'is_active' => true]);

    $group = CustomerGroup::query()->create([
        'name' => 'ERP05 Kunden',
        'slug' => 'erp05-kunden',
        'color' => '#D4AD16',
    ]);
    $this->customer = Customer::query()->create([
        'customer_group_id' => $group->id,
        'company_name' => 'ERP05 Kunde',
    ]);
});

function erp05NoteOffer(Customer $customer, User $creator, string $status = Offer::STATUS_IN_PROGRESS): Offer
{
    return Offer::query()->create([
        'customer_id' => $customer->id,
        'user_id' => $creator->id,
        'status' => $status,
        'template_type' => 'with_company',
        'document_type' => 'offer',
        'subtotal' => 0,
        'total' => 0,
        'reserved_until' => now()->addHours(72),
    ]);
}

it('benachrichtigt bei einer neuen internen Notiz alle aktiven Lager-Mitarbeiter separat', function () {
    $offer = erp05NoteOffer($this->customer, $this->sales);
    User::factory()->create(['role' => User::ROLE_WAREHOUSE, 'is_active' => false]);

    $this->actingAs($this->sales)
        ->post(route('offer-notes.store', $offer), ['note' => "Bitte zuerst Palette 2 prüfen.\nDanke!"])
        ->assertRedirect();

    expect(OfferInternalNote::query()->count())->toBe(1)
        ->and(WarehouseNotification::query()->count())->toBe(2);

    foreach ([$this->warehouseA, $this->warehouseB] as $warehouse) {
        $notification = WarehouseNotification::query()
            ->where('user_id', $warehouse->id)->firstOrFail();

        expect($notification->type)->toBe(WarehouseNotification::TYPE_OFFER_NOTE)
            ->and($notification->subject_id)->toBe($offer->id)
            ->and($notification->read_at)->toBeNull()
            ->and($notification->title)->toContain($offer->offer_number)
            ->and($notification->message)->toContain('Bitte zuerst Palette 2 prüfen.')
            ->and($notification->message)->toContain($this->sales->name);
    }

    $this->assertDatabaseMissing('warehouse_notifications', ['user_id' => $this->sales->id]);
});

it('zeigt neue Notizen über den Zähler an und markiert nur die angeklickte Notiz für diesen Empfänger als gelesen', function () {
    $offer = erp05NoteOffer($this->customer, $this->sales);

    $this->actingAs($this->manager)->post(route('offer-notes.store', $offer), ['note' => 'Erste Info']);
    $this->post(route('offer-notes.store', $offer), ['note' => 'Zweite Info']);

    $this->actingAs($this->warehouseA)
        ->getJson(route('warehouse.notifications.index'))
        ->assertOk()
        ->assertJsonPath('unread_count', 2)
        ->assertJsonPath('items.0.type', WarehouseNotification::TYPE_OFFER_NOTE);

    $first = WarehouseNotification::query()
        ->where('user_id', $this->warehouseA->id)->oldest()->firstOrFail();

    $this->get(route('warehouse.notifications.open', $first))
        ->assertRedirect(route('warehouse.offers.show', $offer) . '#warehouse-internal-notes');

    expect($first->fresh()->read_at)->not->toBeNull();

    $this->getJson(route('warehouse.notifications.index'))
        ->assertOk()
        ->assertJsonPath('unread_count', 1);

    expect(WarehouseNotification::query()
        ->where('user_id', $this->warehouseB->id)
        ->whereNull('read_at')->count())->toBe(2);
});

it('benachrichtigt bei einer Lagernotiz alle anderen Lager-Mitarbeiter, aber nicht den Verfasser', function () {
    $offer = erp05NoteOffer($this->customer, $this->sales);

    $this->actingAs($this->warehouseA)
        ->post(route('offer-notes.store', $offer), ['note' => 'Im Lager geprüft'])
        ->assertRedirect();

    expect(WarehouseNotification::query()->count())->toBe(1);
    $this->assertDatabaseHas('warehouse_notifications', [
        'user_id' => $this->warehouseB->id,
        'type' => WarehouseNotification::TYPE_OFFER_NOTE,
        'subject_id' => $offer->id,
    ]);
    $this->assertDatabaseMissing('warehouse_notifications', ['user_id' => $this->warehouseA->id]);
});

it('meldet Notizen vor der Lagerfreigabe nicht und verweigert Lagermitarbeitern den Zugriff darauf', function () {
    $offer = erp05NoteOffer($this->customer, $this->sales, Offer::STATUS_OFFER);

    $this->actingAs($this->sales)
        ->post(route('offer-notes.store', $offer), ['note' => 'Vor der Übergabe']);

    expect(OfferInternalNote::query()->count())->toBe(1)
        ->and(WarehouseNotification::query()->count())->toBe(0);

    $this->actingAs($this->warehouseA)
        ->post(route('offer-notes.store', $offer), ['note' => 'Zugriff vor Übergabe'])
        ->assertForbidden();

    expect(OfferInternalNote::query()->count())->toBe(1);
});

it('blendet offene Notizmeldungen aus, wenn die Bestellung an den Verkauf zurückgeht', function () {
    $offer = erp05NoteOffer($this->customer, $this->sales);
    $this->actingAs($this->sales)
        ->post(route('offer-notes.store', $offer), ['note' => 'Bitte Versand prüfen']);

    $notification = WarehouseNotification::query()
        ->where('user_id', $this->warehouseA->id)->firstOrFail();

    $this->actingAs($this->warehouseA)
        ->put(route('warehouse.offers.status', $offer), [
            'status' => Offer::STATUS_OFFER,
        ])
        ->assertRedirect(route('warehouse.offers.index'));

    expect($notification->fresh()->dismissed_at)->not->toBeNull();

    $this->getJson(route('warehouse.notifications.index'))
        ->assertOk()
        ->assertJsonPath('unread_count', 0);

    $this->get(route('warehouse.notifications.open', $notification))->assertNotFound();
});

it('weist leere interne Notizen zurück ohne Lagerbenachrichtigungen zu erzeugen', function () {
    $offer = erp05NoteOffer($this->customer, $this->sales);

    $this->actingAs($this->sales)
        ->post(route('offer-notes.store', $offer), ['note' => "  \n  "])
        ->assertSessionHasErrors('note');

    expect(OfferInternalNote::query()->count())->toBe(0)
        ->and(WarehouseNotification::query()->count())->toBe(0);
});

it('zeigt die neue Notizbenachrichtigung in der Lager-Sidebar, nicht im Verkauf', function () {
    $offer = erp05NoteOffer($this->customer, $this->sales);
    $this->actingAs($this->manager)
        ->post(route('offer-notes.store', $offer), ['note' => 'Bitte Palette kontrollieren']);

    $this->actingAs($this->warehouseA)
        ->get(route('warehouse.offers.index'))
        ->assertOk()
        ->assertSee('Neue Lageraufträge und Notizen')
        ->assertSee('Neue interne Notiz')
        ->assertSee('Bitte Palette kontrollieren');

    $this->actingAs($this->sales)
        ->get(route('offers.index'))
        ->assertOk()
        ->assertDontSee('warehouse-notification-button');
});
