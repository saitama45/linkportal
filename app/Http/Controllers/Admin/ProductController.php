<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Services\AuditLogger;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Uom;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class ProductController extends Controller
{
    public function index(Request $request)
    {
        $query = Product::with(['category:id,name', 'uom:id,code'])->latest();

        if ($request->filled('search')) {
            $query->where(function ($q) use ($request) {
                $q->where('name', 'like', "%{$request->search}%")
                    ->orWhere('code', 'like', "%{$request->search}%");
            });
        }

        if ($request->filled('product_type')) {
            $query->where('product_type', $request->product_type);
        }

        return Inertia::render('Products/Index', [
            'products' => Inertia::scroll($query->paginate(20)->withQueryString()),
            'categories' => ProductCategory::active()->select('id', 'name', 'parent_id')->orderBy('name')->get(),
            'uoms' => Uom::active()->select('id', 'code', 'name')->get(),
            'productTypes' => Product::TYPES,
            'filters' => $request->only(['search', 'product_type']),
        ]);
    }

    public function store(Request $request)
    {
        $validated = $this->validateProduct($request);

        $product = Product::create([
            ...$validated,
            'created_by' => $request->user()->id,
        ]);

        AuditLogger::log('product_created', $product);

        return redirect()->back()->with('success', 'Product created.');
    }

    public function update(Request $request, Product $product)
    {
        $validated = $this->validateProduct($request, $product->id);

        $before = $product->only(['name', 'code', 'default_price', 'is_active']);
        $product->update([...$validated, 'updated_by' => $request->user()->id]);

        AuditLogger::log('product_updated', $product, $before, $product->only(['name', 'code', 'default_price', 'is_active']));

        return redirect()->back()->with('success', 'Product updated.');
    }

    public function destroy(Product $product)
    {
        AuditLogger::log('product_deleted', $product);
        $product->delete();

        return redirect()->back()->with('success', 'Product deleted.');
    }

    public function storeCategory(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'code' => 'nullable|string|max:30',
            'parent_id' => 'nullable|exists:portal_product_categories,id',
            'description' => 'nullable|string|max:1000',
        ]);

        ProductCategory::create([...$validated, 'is_active' => true]);

        return redirect()->back()->with('success', 'Category created.');
    }

    private function validateProduct(Request $request, ?int $productId = null): array
    {
        return $request->validate([
            'code' => ['required', 'string', 'max:50', Rule::unique('portal_products', 'code')->ignore($productId)],
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:2000',
            'product_type' => ['required', Rule::in(Product::TYPES)],
            'category_id' => 'nullable|exists:portal_product_categories,id',
            'uom_id' => 'nullable|exists:portal_units_of_measure,id',
            'default_price' => 'nullable|numeric|min:0',
            'currency' => 'nullable|string|max:3',
            'tax_rate' => 'nullable|numeric|min:0|max:100',
            'attributes' => 'nullable|array',
            'is_active' => 'boolean',
        ]);
    }
}
