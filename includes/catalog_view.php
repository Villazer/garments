<?php
declare(strict_types=1);

/* Session 3 rendering helpers. Every value printed here goes through e(). */

/** "₱245.00" or "₱245.00 to ₱275.00" when sizes are priced differently. */
function catalog_price_text($min, $max): string
{
    if ((float) $min === (float) $max) {
        return money($min);
    }
    return money($min) . ' to ' . money($max);
}

/**
 * Status badge. In stock, Low stock and Out of stock reuse the Session 1 stock_badge()
 * so they match the rest of the site; the sample numbers below only steer it to the
 * wanted label (stock_badge compares available quantity with a low-stock threshold).
 * Walk-in only, Made to order and Service are catalog-specific badges.
 */
function catalog_status_badge(string $key): string
{
    $steer = ['in' => [999, 0], 'low' => [1, 99], 'out' => [0, 0]];
    if (isset($steer[$key])) {
        return stock_badge($steer[$key][0], $steer[$key][1]);
    }
    $labels = ['walkin' => 'Walk-in only', 'made' => 'Made to order', 'service' => 'Service'];
    $label = $labels[$key] ?? 'Ask the shop';
    return '<span class="cat-badge cat-badge--' . e($key) . '">' . e($label) . '</span>';
}

/** Clean garment placeholder, inline SVG so it needs no image file and no network. */
function catalog_placeholder(): string
{
    return '<span class="cat-placeholder" role="img" aria-label="No photo yet">'
         . '<svg viewBox="0 0 64 64" width="64" height="64" aria-hidden="true" focusable="false">'
         . '<path d="M22 8 8 18l6 11 6-3v28h24V26l6 3 6-11L42 8c-1 5-5 8-10 8s-9-3-10-8z" '
         . 'fill="none" stroke="currentColor" stroke-width="2.5" stroke-linejoin="round"/></svg>'
         . '<span>No photo yet</span></span>';
}

function catalog_item_url(int $item_id): string
{
    return url('item.php', ['id' => $item_id]);
}

/** One item card for the catalog grid and the home page. $image is ['url','alt'] or null. */
function catalog_render_card(array $item, ?array $image): string
{
    $id     = (int) $item['item_id'];
    $href   = e(catalog_item_url($id));
    $status = catalog_item_status($item);
    $meta   = array_filter([(string) $item['category_name'], (string) ($item['level_name'] ?? '')]);
    $sizes  = (int) $item['size_count'];
    $prefix = $item['item_type'] === 'rental' ? 'Rental fee ' : '';

    $media = $image
        ? '<img src="' . e($image['url']) . '" alt="' . e($image['alt']) . '" loading="lazy">'
        : catalog_placeholder();

    return '<article class="cat-card">'
         . '<a class="cat-card__media" href="' . $href . '" tabindex="-1" aria-hidden="true">' . $media . '</a>'
         . '<div class="cat-card__body">'
         . '<p class="cat-card__meta">' . e(implode(', ', $meta)) . '</p>'
         . '<h3 class="cat-card__title"><a href="' . $href . '" title="' . e($item['name']) . '">' . e($item['name']) . '</a></h3>'
         . '<p class="cat-card__price">' . e($prefix . catalog_price_text($item['min_price'], $item['max_price'])) . '</p>'
         . '<p class="cat-card__sizes">' . e($sizes . ($sizes === 1 ? ' size' : ' sizes')) . '</p>'
         . '<div class="cat-card__foot">' . catalog_status_badge($status)
         . '<a class="cat-link" href="' . $href . '">View sizes</a></div>'
         . '</div></article>';
}

/** Marks a <select> option as chosen. */
function catalog_selected($current, $value): string
{
    return (string) $current === (string) $value ? ' selected' : '';
}
