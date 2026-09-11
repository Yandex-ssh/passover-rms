<?php

namespace App\Services;

use App\Models\Category;
use App\Models\MenuItem;
use Illuminate\Support\Collection;

class ChatbotService
{
    public function __construct(
        private readonly RestaurantContextService $context,
        private readonly AiProviderService $provider,
        private readonly BestSellingService $bestSelling,
    ) {}

    public function ask(string $question): array
    {
        $question = trim($question);
        $lower = mb_strtolower($question);
        $language = $this->language($lower);

        if ($this->isInjectionOrOutOfScope($lower) || ! $this->isRestaurantQuestion($lower)) {
            return $this->answer($this->scopeFallback($language));
        }

        if ($this->hasAny($lower, ['peanut', 'allergen', 'allergy', 'ingredient', 'contain', 'mani', 'sangkap', 'halal', 'vegan'])) {
            return $this->answer($this->allergenFallback($language));
        }

        if ($this->hasAny($lower, ['what time', 'opening hours', 'business hours', 'when do you open', 'when do you close', 'address', 'location', 'oras', 'bukas', 'sarado', 'asa mo', 'diin mo'])) {
            return $this->answer($this->unknownFallback($language));
        }

        $categories = $this->context->activeCategories();
        $items = $this->context->matchingItems($question, $categories);
        $matchedCategories = $this->context->matchingCategories($question, $categories);

        if ($this->isBestSellerQuestion($lower)) {
            return $this->answer($this->bestSellerAnswer($language, $matchedCategories));
        }

        if ($this->isPaymentQuestion($lower)) {
            return $this->answer($this->paymentAnswer($language));
        }
        if ($this->isOrderingQuestion($lower)) {
            return $this->answer($this->orderingAnswer($language));
        }
        if ($this->isRecommendationQuestion($lower)) {
            return $this->answer($this->recommendationAnswer($question, $categories, $matchedCategories, $language));
        }
        if ($this->isPriceQuestion($lower)) {
            return $this->answer($items->isNotEmpty() ? $this->priceAnswer($items, $language) : $this->unknownMenuItem($language));
        }
        if ($this->isAvailabilityQuestion($lower) && $items->isNotEmpty()) {
            return $this->answer($this->availabilityAnswer($items, $language));
        }
        if ($this->isMenuQuestion($lower)) {
            return $this->answer($this->menuAnswer($categories, $matchedCategories, $language));
        }

        return $this->answer($this->provider->answer($question, $this->context->contextFor($question)));
    }

    private function answer(string $answer): array
    {
        return ['answer' => $answer, 'scope' => 'restaurant'];
    }

    private function isRestaurantQuestion(string $question): bool
    {
        return $this->isBestSellerQuestion($question) || $this->hasAny($question, [
            'menu', 'food', 'drink', 'coffee', 'price', 'available', 'availability', 'order', 'pay', 'payment', 'cash', 'gcash', 'qr', 'table', 'restaurant', 'cafe', 'category', 'have', 'latte', 'burger',
            'pagkaon', 'inumin', 'inom', 'kape', 'magkano', 'tagpila', 'pila', 'unsa', 'naa', 'naay', 'available pa', 'pag-order', 'orderon', 'unsaon', 'bayad', 'pwede',
        ]) || $this->context->containsMenuItem($question);
    }

    private function isInjectionOrOutOfScope(string $question): bool
    {
        return preg_match('/ignore (all|any|previous)|system prompt|show (me )?the (database )?password|dump (the )?database|reveal (your )?(instructions|prompt)|do my .*homework|programming assignment|write .*python|hack.*wi-?fi|world war|balewala.*instruction|ipakita.*(prompt|password)|i-?dump.*database|assignment|homework/i', $question) === 1;
    }

    private function isPriceQuestion(string $question): bool
    {
        return $this->hasAny($question, ['how much', 'price', 'cost', 'magkano', 'tagpila', 'pila', 'tag pila']);
    }

    private function isAvailabilityQuestion(string $question): bool
    {
        return $this->hasAny($question, ['available', 'availability', 'naa pa', 'naay', 'mayroon pa', 'meron pa']);
    }

    private function isMenuQuestion(string $question): bool
    {
        return $this->hasAny($question, ['menu', 'food', 'drink', 'coffee', 'category', 'what do you have', 'pagkaon', 'inumin', 'inom', 'kape', 'unsa inyong', 'ano ang']);
    }

    private function isPaymentQuestion(string $question): bool
    {
        return $this->hasAny($question, ['payment', 'pay', 'cash', 'gcash', 'bayad', 'magbayad', 'pagbayad']);
    }

    private function isOrderingQuestion(string $question): bool
    {
        return $this->hasAny($question, ['how do i order', 'how to order', 'qr', 'scan', 'unsaon', 'paano', 'pag-order', 'orderon']);
    }

    private function isRecommendationQuestion(string $question): bool
    {
        return $this->hasAny($question, ['recommend', 'suggest', 'under', 'budget', 'barato', 'murag', 'ma-recommend']);
    }

    private function isBestSellerQuestion(string $question): bool
    {
        return $this->hasAny($question, ['best seller', 'best-sell', 'best sell', 'bestsell', 'popular', 'mabenta', 'halinon']);
    }

    private function bestSellerAnswer(string $language, Collection $categories): string
    {
        $today = now('UTC');
        $ranked = $this->bestSelling->ranked($today->copy()->subDays(29)->toDateString(), $today->toDateString(), true, $categories->pluck('id')->all());
        // Public answers deliberately omit sales counts, amounts, staff and payment data.
        $names = implode(', ', array_column(array_slice($ranked, 0, 5), 'name'));
        if ($names === '') {
            return match ($language) {
                'tagalog' => 'Wala pang sapat na verified sales history sa nakaraang 30 araw para magrekomenda ng available na best sellers.',
                'bisaya' => 'Wala pay igo nga verified sales history sa miaging 30 ka adlaw para makarekomenda og available nga best sellers.',
                default => 'There is not enough verified sales history from the last 30 days to recommend currently available best sellers.',
            };
        }

        return match ($language) {
            'tagalog' => "Ito ang available na best sellers batay sa dami ng nabenta sa fully paid transactions sa nakaraang 30 araw: {$names}.",
            'bisaya' => "Mao ni ang available nga best sellers base sa gidaghanon nga nabaligya sa fully paid transactions sa miaging 30 ka adlaw: {$names}.",
            default => "Currently available best sellers by units sold in fully paid transactions over the last 30 days: {$names}.",
        };
    }

    /** @param Collection<int, MenuItem> $items */
    private function priceAnswer(Collection $items, string $language): string
    {
        return $items->map(fn (MenuItem $item) => match ($language) {
            'tagalog' => "Ang {$item->name} ay ".$this->money($item->price).' ngayon.',
            'bisaya' => "Ang {$item->name} kay ".$this->money($item->price).' karon.',
            default => "The {$item->name} is currently ".$this->money($item->price).'.',
        })->implode(' ');
    }

    /** @param Collection<int, MenuItem> $items */
    private function availabilityAnswer(Collection $items, string $language): string
    {
        return $items->map(function (MenuItem $item) use ($language) {
            if ($language === 'tagalog') {
                return $item->is_available ? "Available pa ang {$item->name}." : "Hindi available ang {$item->name} ngayon.";
            }
            if ($language === 'bisaya') {
                return $item->is_available ? "Available pa ang {$item->name}." : "Dili available ang {$item->name} karon.";
            }

            return $item->is_available ? "{$item->name} is currently available." : "{$item->name} is currently unavailable.";
        })->implode(' ');
    }

    /** @param Collection<int, Category> $categories */
    /** @param Collection<int, Category> $matchedCategories */
    private function menuAnswer(Collection $categories, Collection $matchedCategories, string $language): string
    {
        $selected = $matchedCategories->isNotEmpty() ? $matchedCategories : $categories;
        $items = $selected->flatMap->menuItems->filter(fn (MenuItem $item) => $item->is_available)->values();
        if ($items->isEmpty()) {
            return $this->unknownMenuItem($language);
        }
        $list = $items->map(fn (MenuItem $item) => "{$item->name} (".$this->money($item->price).')')->implode(', ');

        return match ($language) {
            'tagalog' => "Ito ang mga available na item: {$list}.",
            'bisaya' => "Mao ni ang available nga mga item: {$list}.",
            default => "These items are currently available: {$list}.",
        };
    }

    /** @param Collection<int, Category> $categories */
    /** @param Collection<int, Category> $matchedCategories */
    private function recommendationAnswer(string $question, Collection $categories, Collection $matchedCategories, string $language): string
    {
        $items = ($matchedCategories->isNotEmpty() ? $matchedCategories : $categories)->flatMap->menuItems
            ->filter(fn (MenuItem $item) => $item->is_available);
        if (preg_match('/(?:₱|php\s*|pesos?\s*)?(\d+(?:\.\d{1,2})?)/iu', $question, $matches)) {
            $items = $items->filter(fn (MenuItem $item) => (float) $item->price <= (float) $matches[1]);
        }
        $items = $items->sortBy('price')->take(5)->values();
        if ($items->isEmpty()) {
            return $this->unknownMenuItem($language);
        }
        $list = $items->map(fn (MenuItem $item) => "{$item->name} (".$this->money($item->price).')')->implode(', ');

        return match ($language) {
            'tagalog' => "Pwede mong subukan ang mga available na item na ito: {$list}.",
            'bisaya' => "Pwede nimo sulayan kining available nga mga item: {$list}.",
            default => "You could try these available items: {$list}.",
        };
    }

    private function paymentAnswer(string $language): string
    {
        return match ($language) {
            'tagalog' => 'Tumatanggap ang Pass-over Cafe ng cash at GCash. Mano-manong kino-confirm ng cashier ang GCash payment.',
            'bisaya' => 'Modawat ang Pass-over Cafe og cash ug GCash. Mano-manong i-confirm sa cashier ang GCash payment.',
            default => 'Pass-over Cafe accepts Cash and GCash. GCash payment is manually confirmed by the cashier.',
        };
    }

    private function orderingAnswer(string $language): string
    {
        return match ($language) {
            'tagalog' => 'I-scan ang table QR code, mag-browse ng menu, at isumite ang order. Ire-review at iko-confirm ito ng cashier bago ihanda at i-serve. Maaari kang magbayad gamit ang cash o manually confirmed GCash.',
            'bisaya' => 'I-scan ang table QR code, tan-awa ang menu, ug isumite ang order. I-review ug i-confirm kini sa cashier bago iandam ug i-serve. Pwede ka mobayad gamit cash o manually confirmed GCash.',
            default => 'Scan the table QR code, browse the menu, and submit your order. The cashier reviews and confirms it before preparation and service. You can pay with Cash or manually confirmed GCash.',
        };
    }

    private function scopeFallback(string $language): string
    {
        return match ($language) {
            'tagalog' => 'Makakatulong lang ako sa mga tanong tungkol sa Pass-over Cafe gaya ng menu, pag-order, payment, at iba pang impormasyon tungkol sa restaurant.',
            'bisaya' => 'Makatabang ra ko sa mga pangutana bahin sa Pass-over Cafe sama sa menu, pag-order, payment, ug uban pang restaurant information.',
            default => 'I can only help with Pass-over Cafe menu, ordering, payment, and restaurant-related questions.',
        };
    }

    private function allergenFallback(string $language): string
    {
        return match ($language) {
            'tagalog' => 'Wala akong verified ingredient o allergen information para sa item na iyon. Pakitanong muna sa restaurant staff bago um-order.',
            'bisaya' => 'Wala koy verified ingredient o allergen information para ana nga item. Palihog pangutana sa restaurant staff before ordering.',
            default => 'I do not have verified ingredient or allergen information for that item. Please ask restaurant staff before ordering.',
        };
    }

    private function unknownFallback(string $language): string
    {
        return match ($language) {
            'tagalog' => 'Wala akong verified information tungkol diyan. Pakitanong sa Pass-over Cafe staff.',
            'bisaya' => 'Wala koy verified information bahin ana. Palihog pangutana sa Pass-over Cafe staff.',
            default => 'I do not have verified information about that. Please ask Pass-over Cafe staff.',
        };
    }

    private function unknownMenuItem(string $language): string
    {
        return match ($language) {
            'tagalog' => 'Wala akong verified current menu information para sa item na iyon. Pakitanong sa Pass-over Cafe staff.',
            'bisaya' => 'Wala koy verified current menu information para ana nga item. Palihog pangutana sa Pass-over Cafe staff.',
            default => 'I do not have verified current menu information for that item. Please ask Pass-over Cafe staff.',
        };
    }

    private function language(string $question): string
    {
        if ($this->hasAny($question, ['pila', 'unsa', 'naa', 'naay', 'inyong', 'unsaon', 'barato', 'ug ', 'moy'])) {
            return 'bisaya';
        }
        if ($this->hasAny($question, ['magkano', 'paano', 'ano ', 'yung', 'pa ba', ' pa ', ' ang ', 'ako', 'ninyo', 'pakitanong', 'pwede', 'may '])) {
            return 'tagalog';
        }

        return 'english';
    }

    private function hasAny(string $question, array $phrases): bool
    {
        foreach ($phrases as $phrase) {
            if (str_contains($question, $phrase)) {
                return true;
            }
        }

        return false;
    }

    private function money(mixed $value): string
    {
        return '₱'.number_format((float) $value, 2);
    }
}
