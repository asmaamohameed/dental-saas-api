<?php

namespace Database\Factories;

use App\Models\InventoryItem;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<InventoryItem>
 */
class InventoryItemFactory extends Factory
{
    protected $model = InventoryItem::class;

    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'name' => fake()->unique()->words(3, true),
            'unit' => fake()->randomElement(['box', 'piece', 'pack', 'bottle', 'tube']),
            'minimum_threshold' => 10,
            'is_active' => true,
        ];
    }

    /**
     * current_quantity مش موجودة في $fillable بتاعة InventoryItem عمدًا
     * (بتتغير بس عن طريق InventoryTransactionService)، فمينفعش تتحط في
     * definition(). بنحطها هنا كـ direct attribute assignment بعد make() -
     * ده بيبقى برة نطاق الـ $fillable guard لأنه مش mass-assignment.
     */
    public function configure(): static
    {
        return $this->afterMaking(function (InventoryItem $item) {
            $item->current_quantity ??= '0.00';
        });
    }

    /**
     * لضبط current_quantity بقيمة معينة صراحةً، بدل الـ default (0.00).
     */
    public function withQuantity(string|float $quantity): static
    {
        return $this->afterMaking(function (InventoryItem $item) use ($quantity) {
            $item->current_quantity = (string) $quantity;
        });
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }

    /**
     * عنصر تحت الـ minimum_threshold - لاختبار الـ low-stock scope/alert.
     */
    public function lowStock(): static
    {
        return $this->state(fn () => ['minimum_threshold' => 10])
            ->withQuantity('5.00');
    }
}
