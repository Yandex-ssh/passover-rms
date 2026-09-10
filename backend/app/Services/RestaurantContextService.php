<?php

namespace App\Services;

use App\Models\Category;
use App\Models\MenuItem;
use Illuminate\Support\Collection;

class RestaurantContextService
{
    public function contextFor(string $question): string
    {
        $categories = $this->activeCategories();
        $matchedItems = $this->matchingItems($question, $categories);
        $matchedCategories = $this->matchingCategories($question, $categories);

        if ($matchedItems->isNotEmpty()) {
            $categories = $categories->filter(fn (Category $category) => $matchedItems->contains('category_id', $category->id));
        } elseif ($matchedCategories->isNotEmpty()) {
            $categories = $matchedCategories;
        }

        return $this->buildContext($categories);
    }

    /** @return Collection<int, Category> */
    public function activeCategories(): Collection
    {
        return Category::query()
            ->where('is_active', true)
            ->with(['menuItems' => fn ($query) => $query->orderBy('name')])
            ->orderBy('name')
            ->get();
    }

    /** @return Collection<int, MenuItem> */
    public function matchingItems(string $question, ?Collection $categories = null): Collection
    {
        $lower = mb_strtolower($question);
        $items = ($categories ?? $this->activeCategories())->flatMap->menuItems;

        return $items->filter(fn (MenuItem $item) => str_contains($lower, mb_strtolower($item->name)))->values();
    }

    /** @return Collection<int, Category> */
    public function matchingCategories(string $question, ?Collection $categories = null): Collection
    {
        $lower = mb_strtolower($question);

        return ($categories ?? $this->activeCategories())
            ->filter(fn (Category $category) => str_contains($lower, mb_strtolower($category->name)))
            ->values();
    }

    public function containsMenuItem(string $question): bool
    {
        return $this->matchingItems($question)->isNotEmpty();
    }

    /** @param Collection<int, Category> $categories */
    private function buildContext(Collection $categories): string
    {
        $lines = [
            'Restaurant: Pass-over Cafe.',
            'Current menu data follows. Prices are current and availability is the customer-facing MenuItem availability flag.',
        ];

        foreach ($categories as $category) {
            $lines[] = "Category: {$category->name}".($category->description ? " — {$category->description}" : '');
            foreach ($category->menuItems as $item) {
                $availability = $item->is_available ? 'available' : 'unavailable';
                $description = $item->description ? ", description: {$item->description}" : '';
                $lines[] = "- {$item->name}; price: {$item->price}; status: {$availability}{$description}";
            }
        }

        $knowledgePath = resource_path('knowledge/passover_cafe.md');
        if (is_file($knowledgePath)) {
            $lines[] = 'Verified restaurant guidance:';
            $lines[] = file_get_contents($knowledgePath);
        }

        return implode("\n", $lines);
    }
}
