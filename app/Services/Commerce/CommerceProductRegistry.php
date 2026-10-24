<?php

namespace App\Services\Commerce;

/** E41 - يحلّ معالج المنتج بنوع المنتج المخزَّن في الشراء (config/commerce.php: products). منتج مجهول ⇒ CommerceException('invalid_product'). */
class CommerceProductRegistry
{
    /** @var array<string, CommerceProductHandler> */
    protected array $resolved = [];

    /** @throws CommerceException */
    public function for(string $productType): CommerceProductHandler
    {
        if (isset($this->resolved[$productType])) {
            return $this->resolved[$productType];
        }

        $class = config("commerce.products.{$productType}");

        if (! is_string($class) || ! is_a($class, CommerceProductHandler::class, true)) {
            throw new CommerceException('invalid_product', 'منتج غير مدعوم.');
        }

        return $this->resolved[$productType] = app($class);
    }

    public function register(CommerceProductHandler $handler): void
    {
        $this->resolved[$handler->productType()] = $handler;
    }
}
