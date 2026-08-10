<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Invoice {{ $order->order_number }}</title>
    <style>
        * { font-family: DejaVu Sans, sans-serif; }
        body { font-size: 12px; color: #1c1c1c; margin: 0; padding: 24px; }
        .head { width: 100%; margin-bottom: 24px; }
        .head td { vertical-align: top; }
        .brand { font-size: 26px; font-weight: 700; letter-spacing: 1px; color: #ff7a00; }
        .muted { color: #6b7280; }
        h2 { font-size: 15px; margin: 0 0 6px; }
        table.items { width: 100%; border-collapse: collapse; margin-top: 18px; }
        table.items th { background: #f3f4f6; text-align: left; padding: 8px; font-size: 11px; text-transform: uppercase; }
        table.items td { padding: 8px; border-bottom: 1px solid #e5e7eb; }
        .right { text-align: right; }
        .totals { width: 46%; margin-left: auto; margin-top: 14px; border-collapse: collapse; }
        .totals td { padding: 6px 8px; }
        .totals tr.grand td { border-top: 2px solid #1c1c1c; font-weight: 700; font-size: 14px; }
        .badge { display: inline-block; padding: 3px 10px; border-radius: 4px; background: #f3f4f6; font-size: 11px; }
        .footer { margin-top: 30px; font-size: 11px; color: #6b7280; text-align: center; }
    </style>
</head>
<body>
    @php
        $address = $order->shipping_address;
    @endphp

    <table class="head">
        <tr>
            <td>
                <div class="brand">{{ $company->name ?? 'VIPURI' }}</div>
                <div class="muted">
                    {{ $company->address }}<br>
                    {{ $company->city }}{{ $company->region ? ', ' . $company->region : '' }}<br>
                    {{ $company->country_name }}<br>
                    @if($company->phone) Tel: {{ $company->phone }}<br> @endif
                    @if($company->email) {{ $company->email }}<br> @endif
                    @if($company->tin) TIN: {{ $company->tin }} @endif
                </div>
            </td>
            <td class="right">
                <h2>INVOICE</h2>
                <div><strong>{{ $order->order_number }}</strong></div>
                <div class="muted">{{ $order->created_at?->format('d M Y, H:i') }}</div>
                <div style="margin-top:8px">
                    <span class="badge">{{ $order->status_label }}</span>
                    <span class="badge">{{ $order->payment_status_label }}</span>
                </div>
                @if($order->branch)
                    <div class="muted" style="margin-top:8px">
                        Branch: {{ $order->branch->name }} ({{ $order->branch->code }})
                    </div>
                @endif
            </td>
        </tr>
    </table>

    <table class="head">
        <tr>
            <td>
                <h2>Bill to</h2>
                <div>
                    {{ trim(($address->firstname ?? '') . ' ' . ($address->lastname ?? '')) }}<br>
                    {{ $address->address ?? '' }}<br>
                    {{ $address->city ?? '' }}{{ isset($address->state) && $address->state ? ', ' . $address->state : '' }}<br>
                    {{ $address->country_name ?? 'Tanzania' }}<br>
                    {{ ($address->dial_code ?? '') . ($address->mobile ?? '') }}<br>
                    {{ $address->email ?? '' }}
                </div>
            </td>
            <td class="right">
                <h2>Delivery</h2>
                <div>{{ $order->shippingMethod?->name ?? 'Not selected' }}</div>
                @if($order->cod)
                    <div class="muted">Cash on delivery</div>
                @endif
            </td>
        </tr>
    </table>

    <table class="items">
        <thead>
            <tr>
                <th>#</th>
                <th>Item</th>
                <th>SKU</th>
                <th class="right">Qty</th>
                <th class="right">Unit price</th>
                <th class="right">Tax</th>
                <th class="right">Subtotal</th>
            </tr>
        </thead>
        <tbody>
            @foreach($order->orderItems as $index => $item)
                <tr>
                    <td>{{ $index + 1 }}</td>
                    <td>{{ $item->product_name ?? $item->product?->name }}</td>
                    <td>{{ $item->sku ?? $item->product?->sku ?? '—' }}</td>
                    <td class="right">{{ $item->quantity }}</td>
                    <td class="right">{{ showAmount($item->price) }}</td>
                    <td class="right">{{ showAmount($item->total_tax) }}</td>
                    <td class="right">{{ showAmount($item->subtotal) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <table class="totals">
        <tr>
            <td>Subtotal</td>
            <td class="right">{{ showAmount($order->subtotal) }}</td>
        </tr>
        <tr>
            <td>Tax</td>
            <td class="right">{{ showAmount($order->total_tax) }}</td>
        </tr>
        <tr>
            <td>Delivery</td>
            <td class="right">{{ showAmount($order->shipping_charge) }}</td>
        </tr>
        @if($order->discount > 0)
            <tr>
                <td>Discount</td>
                <td class="right">- {{ showAmount($order->discount) }}</td>
            </tr>
        @endif
        <tr class="grand">
            <td>Total</td>
            <td class="right">{{ showAmount($order->total) }}</td>
        </tr>
    </table>

    <div class="footer">
        Thank you for shopping with {{ $company->name ?? 'VIPURI' }}.<br>
        All amounts are in {{ currencyText() }}.
    </div>
</body>
</html>
