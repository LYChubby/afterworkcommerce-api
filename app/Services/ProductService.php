<?php

namespace App\Services;

use App\Http\Requests\ProductIndexRequest;
use App\Models\Product;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

class ProductService
{
    /**
     * Mengambil daftar produk publik beserta filter dan pagination.
     */
    public function getProducts(
        ProductIndexRequest $request
    ): LengthAwarePaginator {
        $filters = $request->validated();

        $query = Product::query()
            ->where('is_active', true)
            ->with([
                'category',
                'images' => function ($query) {
                    $query->orderByDesc('is_primary')
                        ->orderBy('sort_order');
                },
                'variants' => function ($query) {
                    $query->where('is_active', true)
                        ->with([
                            'size',
                            'color',
                        ]);
                },
            ])
            ->whereHas('category', function (Builder $query) {
                $query->where('is_active', true);
            });

        $this->applySearchFilter($query, $filters);
        $this->applyCategoryFilter($query, $filters);
        $this->applyPriceFilter($query, $filters);
        $this->applyAvailabilityFilter($query, $filters);
        $this->applySorting($query, $filters);

        return $query->paginate(
            perPage: $filters['per_page'] ?? 12,
        )->withQueryString();
    }

    /**
     * Filter pencarian nama produk.
     */
    private function applySearchFilter(
        Builder $query,
        array $filters
    ): void {
        if (empty($filters['search'])) {
            return;
        }

        $search = $filters['search'];

        $query->where('name', 'like', "%{$search}%");
    }

    /**
     * Filter berdasarkan slug kategori.
     */
    private function applyCategoryFilter(
        Builder $query,
        array $filters
    ): void {
        if (empty($filters['category'])) {
            return;
        }

        $categorySlug = $filters['category'];

        $query->whereHas('category', function (Builder $query) use ($categorySlug) {
            $query->where('slug', $categorySlug)
                ->where('is_active', true);
        });
    }

    /**
     * Filter berdasarkan harga efektif.
     */
    private function applyPriceFilter(
        Builder $query,
        array $filters
    ): void {
        if (
            ! isset($filters['min_price']) &&
            ! isset($filters['max_price'])
        ) {
            return;
        }

        $effectivePrice = 'COALESCE(discount_price, price)';

        if (isset($filters['min_price'])) {
            $query->whereRaw(
                "{$effectivePrice} >= ?",
                [$filters['min_price']]
            );
        }

        if (isset($filters['max_price'])) {
            $query->whereRaw(
                "{$effectivePrice} <= ?",
                [$filters['max_price']]
            );
        }
    }

    /**
     * Filter berdasarkan ketersediaan stok varian aktif.
     */
    private function applyAvailabilityFilter(
        Builder $query,
        array $filters
    ): void {
        if (! isset($filters['availability'])) {
            return;
        }

        $hasAvailableStock = function (Builder $query) {
            $query->where('is_active', true)
                ->whereRaw('stock_on_hand > stock_reserved');
        };

        if ($filters['availability'] === 'available') {
            $query->whereHas('variants', $hasAvailableStock);

            return;
        }

        $query->whereDoesntHave('variants', $hasAvailableStock);
    }

    /**
     * Pengurutan daftar produk.
     */
    private function applySorting(
        Builder $query,
        array $filters
    ): void {
        $sort = $filters['sort'] ?? 'latest';

        match ($sort) {
            'price_asc' => $query->orderByRaw(
                'COALESCE(discount_price, price) ASC'
            ),

            'price_desc' => $query->orderByRaw(
                'COALESCE(discount_price, price) DESC'
            ),

            'name_asc' => $query->orderBy('name'),

            default => $query->orderByDesc('created_at'),
        };

        $query->orderBy('id');
    }
}
