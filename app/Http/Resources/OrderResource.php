<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OrderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'order_number' => $this->order_number,
            'status' => (int) $this->status,
            'status_label' => $this->status_label,
            'payment_status' => (int) $this->payment_status,
            'payment_status_label' => $this->payment_status_label,
            'cod' => (bool) $this->cod,
            'subtotal' => (float) $this->subtotal,
            'shipping_charge' => (float) $this->shipping_charge,
            'total_tax' => (float) $this->total_tax,
            'discount' => (float) $this->discount,
            'total' => (float) $this->total,
            'note' => $this->note,
            'cancel_reason' => $this->cancel_reason,
            'shipping_address' => $this->shipping_address,
            'shipping_method' => $this->whenLoaded('shippingMethod', fn () => $this->shippingMethod?->name),
            'branch' => $this->whenLoaded('branch', fn () => $this->branch ? [
                'id' => $this->branch->id,
                'name' => $this->branch->name,
                'code' => $this->branch->code,
                'city' => $this->branch->city,
                'phone' => $this->branch->phone,
            ] : null),
            'customer' => $this->whenLoaded('user', fn () => $this->user ? [
                'id' => $this->user->id,
                'name' => $this->user->fullname,
                'email' => $this->user->email,
                'mobile' => $this->user->mobile,
                'username' => $this->user->username,
            ] : null),
            'guest' => $this->whenLoaded('guest', fn () => $this->guest ? [
                'id' => $this->guest->id,
                'name' => trim($this->guest->firstname . ' ' . $this->guest->lastname),
                'email' => $this->guest->email,
                'mobile' => $this->guest->mobile,
            ] : null),
            'processed_by' => $this->whenLoaded('processedBy', fn () => $this->processedBy?->name),
            'items' => $this->whenLoaded('orderItems', fn () => $this->orderItems->map(fn ($item) => [
                'id' => $item->id,
                'product_id' => $item->product_id,
                'product_name' => $item->product_name ?? $item->product?->name,
                'product_slug' => $item->product?->slug,
                'image' => fileUrl('product', $item->product?->main_image, true),
                'sku' => $item->sku,
                'variation_id' => (int) $item->variation_id,
                'variation_label' => $item->variation?->name,
                'quantity' => (int) $item->quantity,
                'price' => (float) $item->price,
                'subtotal' => (float) $item->subtotal,
                'total_tax' => (float) $item->total_tax,
            ])->values()),
            'status_logs' => $this->whenLoaded('statusLogs', fn () => $this->statusLogs->map(fn ($log) => [
                'id' => $log->id,
                'to_status' => (int) $log->to_status,
                'to_status_label' => $log->to_status_label,
                'actor_name' => $log->actor_name,
                'remark' => $log->remark,
                'created_at' => $log->created_at?->toIso8601String(),
            ])->values()),
            'deposits' => $this->whenLoaded('deposits', fn () => $this->deposits->map(fn ($d) => [
                'id' => $d->id,
                'trx' => $d->trx,
                'amount' => (float) $d->amount,
                'charge' => (float) $d->charge,
                'final_amount' => (float) $d->final_amount,
                'method' => $d->gateway?->name,
                'status' => (int) $d->status,
                'created_at' => $d->created_at?->toIso8601String(),
            ])->values()),
            'created_at' => $this->created_at?->toIso8601String(),
            'dispatched_at' => $this->dispatched_at?->toIso8601String(),
            'delivered_at' => $this->delivered_at?->toIso8601String(),
        ];
    }
}
