<?php

namespace Amsgames\LaravelShop\Traits;

/**
 * This file is part of LaravelShop,
 * A shop solution for Laravel.
 *
 * @author Alejandro Mostajo
 * @copyright Amsgames, LLC
 * @license MIT
 * @package Amsgames\LaravelShop
 */

use Shop;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Session;
use InvalidArgumentException;

trait ShopCartTrait
{
    /**
     * Property used to stored calculations.
     * @var array
     */
    private $cartCalculations = null;

    /**
     * Boot the user model
     * Attach event listener to remove the relationship records when trying to delete
     * Will NOT delete any records if the user model uses soft deletes.
     *
     * @return void|bool
     */
    public static function boot()
    {
        parent::boot();

        static::deleting(function($user) {
            if (!method_exists(config('shop.user'), 'bootSoftDeletingTrait')) {
                $user->items()->sync([]);
            }

            return true;
        });
    }

    /**
     * One-to-One relations with the user model.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsToMany
     */
    public function user()
    {
        return $this->belongsTo(config('shop.user'), 'user_id');
    }
    
    public function sessionUser()
    {
        return $this->where('session_id', Session::getId());
    }

    /**
     * One-to-Many relations with Item.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsToMany
     */
    public function items()
    {
        return $this->hasMany(Config::get('shop.item'), 'cart_id');
    }

    /**
     * Adds item to cart.
     *
     * @param mixed $item     Item to add, can be an Store Item, a Model with ShopItemTrait or an array.
     * @param int   $quantity Item quantity in cart.
     */
    public function add($item, $quantity = 1, $quantityReset = false)
    {
        if (!is_array($item) && !$item->isShoppable) return;
        // Get item
        $cartItem = $this->getItem(is_array($item) ? $item['sku'] : $item->sku);
        // Add new or sum quantity
        if (empty($cartItem)) {
            $reflection = null;
            if (is_object($item)) {
                $reflection = new \ReflectionClass($item);
            }
            $cartClass = config('shop.item');
            $cartItem = new $cartClass;
            
            if (Auth::guard(config('shop.user_auth_provider'))->guest()) {
                $cartItem->session_id = Session::getId();
            } else {
                $cartItem->user_id = $this->user->shopId;
            }
            $cartItem->cart_id = $this->attributes['id'];
            $cartItem->sku = is_array($item) ? $item['sku'] : $item->sku;
            $cartItem->price = is_array($item) ? $item['price'] : $item->price;
            $cartItem->tax = is_array($item) 
                ? (array_key_exists('tax', $item)
                    ?   $item['tax']
                    :   0
                ) 
                : (isset($item->tax) && !empty($item->tax)
                    ?   $item->tax
                    :   0
                );
            $cartItem->shipping = is_array($item) 
                ? (array_key_exists('shipping', $item)
                    ?   $item['shipping']
                    :   0
                ) 
                : (isset($item->shipping) && !empty($item->shipping)
                    ?   $item->shipping
                    :   0
                );
            $cartItem->currency = config('shop.currency');
            $cartItem->quantity = $quantity;
            $cartItem->class = is_array($item) ? null : $reflection->getName();
            $cartItem->reference_id = is_array($item) ? null : $item->shopId;
        } else {
            $cartItem->quantity = $quantityReset 
                ? $quantity 
                : $cartItem->quantity + $quantity;
        }
        $cartItem->save();
        $this->resetCalculations();
        return $this;
    }

    /**
     * Removes an item from the cart or decreases its quantity.
     * Returns flag indicating if removal was successful.
     *
     * @param mixed $item     Item to remove, can be an Store Item, a Model with ShopItemTrait or an array.
     * @param int   $quantity Item quantity to decrease. 0 if wanted item to be removed completly.
     *
     * @return bool
     */
    public function remove($item, $quantity = 0)
    {
        // Get item
        $cartItem = $this->getItem(is_array($item) ? $item['sku'] : $item->sku);
        // Remove or decrease quantity
        if (!empty($cartItem)) {
            if (!empty($quantity)) {
                $cartItem->quantity -= $quantity;
                $cartItem->save();
                if ($cartItem->quantity > 0) return true;
            }
            $cartItem->delete();
        }
        $this->resetCalculations();
        return $this;
    }

    /**
     * Checks if the user has a role by its name.
     *
     * @param string|array $name       Role name or array of role names.
     * @param bool         $requireAll All roles in the array are required.
     *
     * @return bool
     */
    public function hasItem($sku, $requireAll = false)
    {
        if (is_array($sku)) {
            foreach ($sku as $skuSingle) {
                $hasItem = $this->hasItem($skuSingle);

                if ($hasItem && !$requireAll) {
                    return true;
                } elseif (!$hasItem && $requireAll) {
                    return false;
                }
            }

            // If we've made it this far and $requireAll is FALSE, then NONE of the roles were found
            // If we've made it this far and $requireAll is TRUE, then ALL of the roles were found.
            // Return the value of $requireAll;
            return $requireAll;
        } else {
            foreach ($this->items as $item) {
                if ($item->sku == $sku) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Scope class by a given user ID.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query  Query.
     * @param mixed                                 $userId User ID.
     *
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeWhereUser($query, $userId)
    {
        return $query->where('user_id', $userId);
    }
    
    /**
     * Scope class by a given session ID.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query  Query.
     * @param mixed                                 $sessionId User ID.
     *
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeWhereSession($query, $sessionId)
    {
        return $query->where('session_id', $sessionId);
    }

    /**
     * Scope to current user cart.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query  Query.
     *
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeWhereCurrent($query)
    {
        if (Auth::guard(config('shop.user_auth_provider'))->guest()) {
            return $query->whereSession(Session::getId());
        } else {
            return $query->whereUser(Auth::guard(config('shop.user_auth_provider'))->user()->shopId);
        }
    }

    /**
     * Scope to current user cart and returns class model.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query  Query.
     *
     * @return this
     */
    public function scopeCurrent($query)
    {
        $cart = $query->whereCurrent()->first();
        if (empty($cart)) {
            $cartClass = config('shop.cart');
            $cart = new $cartClass;
            if (Auth::guard(config('shop.user_auth_provider'))->guest()) {
                $cart->session_id = Session::getId();
            } else {
                $cart->user_id = Auth::guard(config('shop.user_auth_provider'))->user()->shopId;
            }
            $cart->save();
        }
        return $cart;
    }

    /**
     * Scope to current user cart and returns class model.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query  Query.
     *
     * @return this
     */
    public function scopeFindByUser($query, $userId)
    {
        if (empty($userId)) return;
        $cart = $query->whereUser($userId)->first();
        if (empty($cart)) {
            $cartClass = config('shop.cart');
            $cart = new $cartClass;
            $cart->user_id = $userId;
            $cart->save();
        }
        return $cart;
    }

    /**
     * Transforms cart into an order.
     * Returns created order.
     *
     * @param string $statusCode Order status to create order with.
     *
     * @return Order
     */
    public function placeOrder($statusCode = null)
    {
        if (empty($statusCode)) $statusCode = Config::get('shop.order_status_placement');
        // Create order
        $order = call_user_func( Config::get('shop.order') . '::create', [
            'user_id'       => $this->user_id,
            'statusCode'    => $statusCode
        ]);
        // Map cart items into order
        for ($i = count($this->items) - 1; $i >= 0; --$i) {
            // Attach to order
            $this->items[$i]->order_id  = $order->id;
            // Remove from cart
            $this->items[$i]->cart_id   = null;
            // Update
            $this->items[$i]->save();
        }
        $this->resetCalculations();
        return $order;
    }

    /**
     * Whipes put cart
     */
    public function clear()
    {
        DB::table(Config::get('shop.item_table'))
            ->where('cart_id', $this->attributes['id'])
            ->delete();
        $this->resetCalculations();
        return $this;
    }

    /**
     * Retrieves item from cart;
     *
     * @param string $sku SKU of item.
     *
     * @return mixed
     */
    private function getItem($sku)
    {
        $className  = Config::get('shop.item');
        $item       = new $className();
        return $item->where('sku', $sku)
            ->where('cart_id', $this->attributes['id'])
            ->first();
    }

}
