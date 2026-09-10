<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Inventory;
use App\Models\MenuItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MenuItemApiTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create(['role' => 'admin', 'is_active' => true]));
    }

    use RefreshDatabase;

    public function test_menu_items_support_full_crud_with_their_category(): void
    {
        $category = Category::create(['name' => 'Coffee']);

        $created = $this->postJson('/api/menu-items', [
            'category_id' => $category->id,
            'name' => 'Americano',
            'description' => 'Freshly brewed espresso with hot water',
            'price' => 120.00,
            'is_available' => true,
        ]);

        $created->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Menu item created successfully.')
            ->assertJsonPath('data.category.id', $category->id)
            ->assertJsonPath('data.category.name', 'Coffee')
            ->assertJsonPath('data.price', '120.00');

        $menuItem = MenuItem::firstOrFail();

        $this->assertDatabaseHas('inventories', [
            'menu_item_id' => $menuItem->id,
            'quantity' => 0,
            'low_stock_threshold' => 5,
        ]);
        $this->assertFalse($menuItem->is_available);

        $inventory = Inventory::firstOrFail();

        $this->getJson('/api/inventories')
            ->assertOk()
            ->assertJsonPath('data.0.id', $inventory->id)
            ->assertJsonPath('data.0.menu_item.id', $menuItem->id);

        $this->getJson('/api/menu-items')
            ->assertOk()
            ->assertJsonPath('data.0.id', $menuItem->id)
            ->assertJsonPath('data.0.category.name', 'Coffee');

        $this->getJson("/api/menu-items/{$menuItem->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $menuItem->id)
            ->assertJsonPath('data.category.id', $category->id);

        $this->patchJson("/api/menu-items/{$menuItem->id}", [
            'price' => 135.00,
            'description' => 'Espresso diluted with hot water',
        ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Menu item updated successfully.')
            ->assertJsonPath('data.price', '135.00')
            ->assertJsonPath('data.description', 'Espresso diluted with hot water');

        $this->deleteJson("/api/menu-items/{$menuItem->id}")
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Menu item deleted successfully.');

        $this->assertDatabaseMissing('menu_items', ['id' => $menuItem->id]);
    }

    public function test_creating_a_menu_item_requires_an_existing_category(): void
    {
        $this->postJson('/api/menu-items', [
            'category_id' => 999999,
            'name' => 'Invalid Item',
            'price' => 100.00,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('category_id');
    }

    public function test_creating_a_menu_item_rejects_a_negative_price(): void
    {
        $category = Category::create(['name' => 'Coffee']);

        $this->postJson('/api/menu-items', [
            'category_id' => $category->id,
            'name' => 'Negative Price Item',
            'price' => -50,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('price');
    }
}
