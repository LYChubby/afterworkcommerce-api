<?php

namespace App\Services;

use App\Http\Requests\ProductIndexRequest;
use App\Models\Category;
use App\Models\Product;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

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
     * Mengambil detail produk publik berdasarkan slug.
     */
    public function getProductBySlug(string $slug): Product
    {
        return Product::query()
            ->where('slug', $slug)
            ->where('is_active', true)
            ->whereHas('category', function (Builder $query) {
                $query->where('is_active', true);
            })
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
            ->firstOrFail();
    }

    /**
     * Mengambil seluruh produk untuk kebutuhan admin.
     */
    public function getAdminProducts(): LengthAwarePaginator
    {
        return Product::query()
            ->with([
                'category',
                'images' => function ($query) {
                    $query->orderByDesc('is_primary')
                        ->orderBy('sort_order');
                },
                'variants' => function ($query) {
                    $query->with([
                        'size',
                        'color',
                    ]);
                },
            ])
            ->orderByDesc('created_at')
            ->orderBy('id')
            ->paginate(15);
    }

    /**
     * Mengambil detail produk untuk kebutuhan admin berdasarkan UUID.
     */
    public function getProductByUuid(string $uuid): Product
    {
        return Product::query()
            ->where('uuid', $uuid)
            ->with([
                'category',
                'images' => function ($query) {
                    $query->orderByDesc('is_primary')
                        ->orderBy('sort_order');
                },
                'variants' => function ($query) {
                    $query->with([
                        'size',
                        'color',
                    ]);
                },
            ])
            ->firstOrFail();
    }

    /**
     * Membuat produk baru.
     */
    public function createProduct(array $data): Product
    {
        $category = Category::query()
            ->where('uuid', $data['category_uuid'])
            ->firstOrFail();

        $data['category_id'] = $category->id;

        unset($data['category_uuid']);

        $data['slug'] = $this->generateUniqueSlug(
            $data['slug'] ?? $data['name']
        );

        $product = Product::create($data);

        return $product->load('category');
    }

    /**
     * Memperbarui produk.
     */
    public function updateProduct(Product $product, array $data): Product
    {
        if (array_key_exists('category_uuid', $data)) {
            $category = Category::query()
                ->where('uuid', $data['category_uuid'])
                ->firstOrFail();

            $data['category_id'] = $category->id;

            unset($data['category_uuid']);
        }

        if (array_key_exists('slug', $data)) {
            $data['slug'] = $this->generateUniqueSlug(
                $data['slug'] ?? ($data['name'] ?? $product->name),
                $product->id
            );
        } elseif (array_key_exists('name', $data)) {
            $data['slug'] = $this->generateUniqueSlug(
                $data['name'],
                $product->id
            );
        }

        $product->update($data);

        return $product->refresh()->load('category');
    }

    /**
     * Menghapus produk jika tidak memiliki stok atau
     * riwayat inventaris dan reservasi.
     */
    public function deleteProduct(Product $product): void
    {
        DB::transaction(function () use ($product): void {
            $product = Product::query()
                ->whereKey($product->id)
                ->lockForUpdate()
                ->firstOrFail();

            $variants = $product->variants();

            $hasStock = (clone $variants)
                ->where(function ($query) {
                    $query->where('stock_on_hand', '>', 0)
                        ->orWhere('stock_reserved', '>', 0);
                })
                ->exists();

            $hasInventoryHistory = (clone $variants)
                ->whereHas('inventoryMovements')
                ->exists();

            $hasReservations = (clone $variants)
                ->whereHas('inventoryReservations')
                ->exists();

            if ($hasStock || $hasInventoryHistory || $hasReservations) {
                throw ValidationException::withMessages([
                    'product' => [
                        'Produk tidak dapat dihapus karena masih memiliki stok atau riwayat inventaris dan reservasi. Nonaktifkan produk jika tidak ingin menampilkannya.',
                    ],
                ]);
            }

            $product->delete();
        });
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
     * Membuat slug produk yang unik.
     */
    private function generateUniqueSlug(
        string $value,
        ?int $ignoreId = null
    ): string {
        $baseSlug = Str::slug($value);

        if ($baseSlug === '') {
            $baseSlug = 'product';
        }

        $slug = $baseSlug;
        $counter = 1;

        while (
            Product::query()
                ->where('slug', $slug)
                ->when(
                    $ignoreId !== null,
                    fn ($query) => $query->where('id', '!=', $ignoreId)
                )
                ->exists()
        ) {
            $slug = $baseSlug.'-'.$counter;
            $counter++;
        }

        return $slug;
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
