# Test checklist — WooCommerce store management + plugin management tools

Setup: WooCommerce active, tools enabled in **MCP > Settings** (all are OFF by default), client reconnected.
Every new tool returns `{ success, data, error }`. For each tool also check the shared negatives:
**(N1)** WooCommerce deactivated → `success:false`, "WooCommerce is not active" (Woo tools aren't even listed when it's off);
**(N2)** an Application-Password user without the capability → "You do not have permission (…)".

## Delete
| Tool | Checks |
|---|---|
| `wsp_woo_delete_product` | Create simple → delete (no force) → `result:trashed`, product in Trash. Repeat call → "already in the trash". `force=true` → `permanently_deleted`. Also run on a variable product (`type:variable`) and on a variation ID. Unknown id → "Product not found." |
| `wsp_woo_delete_variation` | Delete with wrong `product_id` → "does not belong". Correct ids, no force → trashed; `force=true` → permanent. |
| `wsp_woo_delete_coupon` | Create coupon → delete → trashed; `force=true` → permanent. A non-coupon post id → "Coupon not found." |
| `wsp_woo_delete_category` / `_tag` | Without `force` → refused ("cannot be moved to the trash"). `force=true` → gone, products keep existing. Default product category → refused. |

## Categories, tags, attributes
| Tool | Checks |
|---|---|
| `wsp_woo_create_product_category` → `get_` → `update_` → `delete_` | Create with `parent`, `description`, `image_id`; appears in `get`; update name/slug/parent (self-parent and missing parent rejected); non-attachment `image_id` rejected; delete needs force. |
| `wsp_woo_create_product_tag` → `get_product_tags` → `delete_tag` | Duplicate name → WooCommerce's "already exists" error surfaced in `error`. |
| `wsp_woo_create_attribute` → `get_attributes` → `update_attribute` → `delete_attribute` | Response includes `taxonomy` (`pa_color`). Bad `type` / `order_by` rejected. Delete needs force and removes its terms. |
| `wsp_woo_create_attribute_term` → `get_attribute_terms` → `delete_attribute_term` | Terms listed per attribute; delete needs force. |
| `wsp_woo_create_product` / `update_product` | `categories:[id]`, `tags:[id]` stored; bogus id → error and **nothing saved**. Custom attr `{name,options}` and global attr `{attribute_id|taxonomy,options}` (missing term auto-created). Variable product: attributes default `variation:true`; simple product: `false`. Then `create_variation` works with those attributes. |
| `wsp_create_category` | Default → `category`. `taxonomy:product_cat` → appears under Products > Categories. Other taxonomy → rejected. |

## Settings, tax, shipping, gateways
| Tool | Checks |
|---|---|
| `wsp_woo_get_settings` | Each group returns options; password-type values show `********`. Missing/invalid group → error listing valid groups. |
| `wsp_woo_update_settings` | Change `woocommerce_currency`, `woocommerce_currency_pos`, `woocommerce_price_decimal_sep`, `woocommerce_price_thousand_sep`, `woocommerce_store_address`, `woocommerce_allowed_countries` (+ `woocommerce_specific_allowed_countries`), `woocommerce_ship_to_countries`, `woocommerce_calc_taxes: "yes"`; re-read to confirm. Unknown id → listed in `errors`; invalid select value → rejected. Sending `********` back leaves the stored secret unchanged. |
| `wsp_woo_get_tax_classes` | Lists classes, `taxes_enabled`; `include_rates:true` adds rates. |
| `wsp_woo_create_tax_rate` → `update_` → `delete_tax_rate` | Create (country, state, rate, name, priority, compound, shipping, class); `rate` > 100 or bad country rejected; delete needs force. |
| `wsp_woo_create_shipping_zone` → `get_shipping_zones` | Locations `["US","US:CA","postcode:90210","continent:EU"]` all stored; bad location type rejected. |
| `wsp_woo_add_shipping_method` → `get_shipping_methods` → `update_` → `delete_shipping_method` | Add `flat_rate` with `{"cost":"10"}`, `free_shipping`, `local_pickup`; unknown `method_id` lists the valid ones; update `enabled`/`settings`; zone `0` works for get/add; delete needs force. |
| `wsp_woo_get_payment_gateways` / `update_payment_gateway` | `bacs`/`cheque`/`cod` enable/disable + title + description. For Stripe/PayPal: key/secret/token fields come back as `********` and never appear in the audit log or response; updating other fields doesn't clear them. |

## Plugins
| Tool | Checks |
|---|---|
| `wsp_install_plugin` | `slug:"hello-dolly"` (or any small plugin) → installed, `activated:false`; with `activate:true` → active. Same slug again → "already installed". Bad slug → error; nonexistent slug → wordpress.org lookup error. |
| `wsp_install_plugin_from_url` | `http://…` → rejected; URL with `user:pass@` → rejected; private/loopback host → rejected; valid https zip installs; non-plugin zip → "could not find a valid plugin". |
| `wsp_delete_plugin` | Active plugin → "Deactivate it first". Deactivate → delete succeeds, files gone. This plugin → refused. Unknown path → "Plugin not found". |
| `wsp_update_plugin` | Plugin with update → `updated:true`, new version, active state preserved. Up-to-date plugin → `updated:false`. |
| `wsp_get_plugins` | `plugins` lists active **and** inactive with `active`, `update_available`, `new_version`; `active_plugins`/`total` unchanged in meaning. |
| Environment | With `DISALLOW_FILE_MODS` true → install/delete/update return the file-mods error. Without write access / FS credentials → `WordPress cannot write…` message (no FTP prompt in the response). |

## Settings size / cleanup / zones (v2.9.5 follow-up)
| # | Check |
|---|---|
| a | `wsp_woo_get_settings {group:"general"}` with no other params → response JSON under 50,000 characters; `woocommerce_currency`, `woocommerce_currency_pos`, `woocommerce_price_thousand_sep`, `woocommerce_price_decimal_sep`, `woocommerce_price_num_decimals`, `woocommerce_default_country`, `woocommerce_allowed_countries`, `woocommerce_ship_to_countries`, `woocommerce_calc_taxes` all present with a `value`; dropdowns show `options_count` but no `options`. |
| b | `{group:"general", setting_id:"woocommerce_currency"}` → exactly one setting, `value` = the store currency (e.g. `USD`). Also try `setting_ids:[...]` and an unknown id (`not_found` / error). |
| c | `{group:"general", setting_id:"woocommerce_default_country", include_options:true}` → that one setting with its full `options`. `include_options:true` with no `setting_id` → either options for all (if under the limit) or options dropped with `truncated_options:true`. |
| d | `wsp_woo_get_tax_classes` (and `include_rates:true`) → no `_links` anywhere; classes are `{slug,name}` only. Also confirm none in shipping zones/methods, gateways, categories, tags, attributes. |
| e1 | `wsp_woo_update_product_tag {id, name, slug, description}` → updated tag returned; unknown id → "Product tag not found."; no fields → error. |
| e2 | `wsp_woo_update_shipping_zone {id, name, order, locations:[{code:"US",type:"country"},{code:"CA",type:"state"}]}` → new list replaces the old one; `locations:[]` clears; bad `type` rejected; unknown id → "Shipping zone not found."; `id:0` → clear error. |
| e3 | `wsp_woo_delete_shipping_zone {id}` → returns `name` and `removed_methods` (matches the methods the zone had); zone and its methods gone. `id:0` → "cannot be deleted" error; unknown id → not found. |

## Shipping zone locations persistence (bug fix)
1. `wsp_woo_create_shipping_zone {name:"Test", locations:["NO"]}` → response `locations` = `[{code:"NO",type:"country"}]`; then `wsp_woo_get_shipping_zones` shows the same for that zone.
2. `wsp_woo_update_shipping_zone {id, locations:[{code:"NO",type:"country"},{code:"DK",type:"country"}]}` → both returned; `get_shipping_zones` shows both (replaced, not appended).
3. `wsp_woo_update_shipping_zone {id, locations:[]}` → `locations` = `[]`; `get_shipping_zones` confirms it is empty.
4. String forms on create: `["US:CA","postcode:90210","continent:EU"]` → types `state`, `postcode`, `continent`. Also check WooCommerce > Settings > Shipping in wp-admin shows the saved regions.
