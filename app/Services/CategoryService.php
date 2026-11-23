<?php

namespace App\Services;

use App\Models\Category;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CategoryService
{
    /**
     * Mengambil seluruh kategori untuk admin.
     */
    public function getCategories(): Collection
    {
        return Category::query()
            ->withCount('products')
            ->orderBy('name')
            ->get();
    }

    /**
     * Mengambil detail kategori berdasarkan UUID.
     */
    public function getCategoryByUuid(string $uuid): Category
    {
        return Category::query()
            ->withCount('products')
            ->where('uuid', $uuid)
            ->firstOrFail();
    }

    /**
     * Membuat kategori baru.
     */
    public function createCategory(array $data): Category
    {
        $data['slug'] = $this->generateUniqueSlug(
            $data['slug'] ?? $data['name']
        );

        $category = Category::create($data);

        return $category->loadCount('products');
    }

    /**
     * Memperbarui kategori.
     */
    public function updateCategory(Category $category, array $data): Category
    {
        if (array_key_exists('slug', $data)) {
            $data['slug'] = $this->generateUniqueSlug(
                $data['slug'] ?? ($data['name'] ?? $category->name),
                $category->id
            );
        } elseif (array_key_exists('name', $data)) {
            $data['slug'] = $this->generateUniqueSlug(
                $data['name'],
                $category->id
            );
        }

        $category->update($data);

        return $category->refresh()->loadCount('products');
    }


    /**
     * Menghapus kategori jika belum memiliki produk.
     */
    public function deleteCategory(Category $category): void
    {
        if ($category->products()->exists()) {
            throw ValidationException::withMessages([
                'category' => [
                    'Kategori tidak dapat dihapus karena masih memiliki produk.',
                ],
            ]);
        }

        $category->delete();
    }

    /**
     * Membuat slug unik.
     */
    private function generateUniqueSlug(
        string $value,
        ?int $ignoreId = null
    ): string {
        $baseSlug = Str::slug($value);

        if ($baseSlug === '') {
            $baseSlug = 'category';
        }

        $slug = $baseSlug;
        $counter = 1;

        while (
            Category::query()
            ->where('slug', $slug)
            ->when(
                $ignoreId !== null,
                fn($query) => $query->where('id', '!=', $ignoreId)
            )
            ->exists()
        ) {
            $slug = $baseSlug . '-' . $counter;
            $counter++;
        }

        return $slug;
    }
}
