# Session 9: small manual edits to files this session did NOT overwrite

I could not see the current text of these files in full, so each change is a find-and-replace of one
or two lines. Do them once after unzipping. All four are optional only if you accept the limits noted.

## 1. includes/reserve_lib.php  (enforces "Maximum quantity per online request")
In `res_evaluate()`, find:

    $cap = max(1, (int)$r['max_qty_per_request']);

replace with:

    $cap = max(1, min((int)$r['max_qty_per_request'], max(1, min(50, (int)get_setting('max_qty_per_request_limit', 50)))));

Without this, the maximum in Settings is not applied to the reservation list.

## 2. includes/catalog_data.php  (public pages: closed notice and item page cap)
a) In `catalog_announcement()`, add as the FIRST lines of the function body:

    if (function_exists('shop_reservations_open') && !shop_reservations_open()) {
        return ['text' => shop_closed_message(), 'style' => 'warning'];
    }

b) In `catalog_find_item()`, replace `return $row ?: null;` with:

    if ($row) {
        $row['max_qty_per_request'] = min((int) $row['max_qty_per_request'], function_exists('shop_qty_limit') ? shop_qty_limit() : 50);
    }
    return $row ?: null;

## 3. includes/inventory_catalog.php  (staff item form respects the maximum)
In `inv_item_validate()`, find:

    $max = inv_int($post['max_qty_per_request'] ?? '', 1, 50);

replace with:

    $ceiling = max(1, min(50, (int) get_setting('max_qty_per_request_limit', 50)));
    $max = inv_int($post['max_qty_per_request'] ?? '', 1, $ceiling);

and change the error text on the next lines to `'Maximum quantity per online request must be 1 to ' . $ceiling . '.'`.

## 4. admin/item_edit.php  (new items start with the default quantity limit)
In the `$form = $item ?: [ ... ]` array, change `'max_qty_per_request' => 3` to:

    'max_qty_per_request' => (int) get_setting('default_max_qty_per_request', 3)
