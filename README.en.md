# Vehicle Quick Switcher (Horizontal Vehicle Switcher)

[![Buy me a coffee](https://img.shields.io/badge/☕_Buy_me_a_coffee-PayPal-0070ba?style=for-the-badge&logo=paypal&logoColor=white)](https://paypal.me/DrdotHouse)

> Die deutsche Fassung ist das Original: [README.md](README.md)

Lightweight Shopware 6.6+ plugin for a global, horizontal vehicle quick switcher in
the storefront header. No dropdown – vehicles are shown as tiles / pills side by side.
Single select per click, applied globally across every category.

## How it works

| Step | What happens |
|------|--------------|
| Data source | Plain Shopware **properties**. Two property groups are picked in the admin (e.g. "Suitable for" / "Suitable for A-type"). How those groups get filled – by hand, via import or from an ERP – is irrelevant to the plugin. |
| Rendering | `HeaderPageletSubscriber` attaches the group options as the `vehicleSwitcher` extension on the header pagelet. Twig renders the pill bar below the header. |
| Selection | JS plugin `VehicleSwitcher`: a click activates exactly one vehicle (no stacking). Clicking the active vehicle or "All vehicles" clears the selection. The value is stored in `localStorage` **and** POSTed into the sales channel session. |
| Global filter | `ProductListingSubscriber` listens on `ProductListingCriteriaEvent` / `ProductSearchCriteriaEvent` / `ProductSuggestCriteriaEvent` and, while a vehicle is active, adds `EqualsFilter('product.properties.id', <optionId>)` to the `Criteria` – for every category / search. |
| Multi shop | Field **"Active in these sales channels"** – a multi-select. Bar **and** filter apply only in the chosen channels. No per-channel inheritance fiddling. |

## Directory layout

```
.
├── composer.json
└── src/
    ├── FahrzeugSchnellauswahl.php
    ├── Controller/
    │   └── VehicleSwitcherController.php        # POST /vehicle-switcher/select
    ├── Subscriber/
    │   ├── HeaderPageletSubscriber.php          # options -> template
    │   └── ProductListingSubscriber.php         # global criteria filter
    ├── Service/
    │   ├── VehicleSwitcherConfig.php            # SystemConfig (per sales channel)
    │   ├── VehicleSelectionStorage.php          # session (single select)
    │   └── VehicleOptionLoader.php              # load property_group_option
    ├── Struct/
    │   └── VehicleSwitcherStruct.php
    └── Resources/
        ├── config/
        │   ├── config.xml                       # admin configuration
        │   ├── services.xml
        │   └── routes.php
        ├── snippet/
        │   ├── de_DE/messages.de-DE.json
        │   └── en_GB/messages.en-GB.json        # technical fallback only
        ├── views/storefront/
        │   ├── layout/header/header.html.twig
        │   └── component/vehicle-switcher/vehicle-switcher.html.twig
        └── app/storefront/src/
            ├── main.js
            ├── plugin/vehicle-switcher/vehicle-switcher.plugin.js
            └── scss/base.scss
```

## Installation

```bash
# put the plugin in custom/plugins/FahrzeugSchnellauswahl (folder name = plugin class name)
bin/console plugin:refresh
bin/console plugin:install --activate FahrzeugSchnellauswahl
bin/console cache:clear

# build storefront assets
bin/build-storefront.sh          # or: bin/console theme:compile
```

## Configuration (admin)

Settings → System → Plugins → *Fahrzeug-Schnellauswahl* → *Config*

1. Stay on **"All sales channels"** (top).
2. Field **"Active in these sales channels"**: pick the sales channels where the switcher should appear. Empty = active nowhere.
3. Card **Filter groups**: pick "Filter group 1 – property", optionally "Filter group 2".
4. Save.

The **labelling and appearance settings** can additionally be overridden per sales channel (pick the channel at the top, unlink the field's inheritance). This works fine for text and select fields.

### Filter groups

| Field | Effect |
|---|---|
| **Filter group 1 / 2 – property** | The Shopware property whose options become pills. **Filter group 1 is shown first, then group 2** – regardless of the property groups' own sorting in Shopware. So to get "A-types first", put the A-type property into filter group 1. |
| **Filter group 1 / 2 – heading** | Small text directly before that group's pills. **Empty = no heading** (default – just the vehicle types). The field sits right under its group select. |

### Appearance

| Setting | Effect |
|---|---|
| **Display** | `Everything in one bar` (default): one row, group 1 then group 2. `Filter groups stacked`: each group on its own row, the "all" pill on a row above. |
| **Vehicle sorting** | Within a group: `Manual order` (default) = drag & drop order from the property group. Or `A–Z` / `Z–A`. |
| **Maximum number of pills** | Upper limit for groups with many options. |
| **Show "all" pill** | The reset pill. Off = no reset pill (the filter can still be cleared by clicking the active pill). |
| **"All" pill label** | Free text, e.g. `All` or `Reset`. Empty = default text. |

The pill captions themselves are the **names of the property options** – edit them directly in Shopware under *Catalogues → Properties* (per translation for multiple languages).

### Category heading and description styling (1.8.0)

- **Show category heading**: shows the category name as heading on category pages, extended by the selected vehicle ("Spheres for Citroën XM"). Joining word configurable (default German "für").
- **Description CSS class**: extra class for the vehicle description block, e.g. `nova-fitment` for Nova Theme fitment tables.

## Filling the properties

The plugin only reads `property_group` / `property_group_option` and the
`product.properties` assignment. Who maintains the values is up to you:

- manually in the admin (Catalogues → Properties, then assign on the product),
- via product import (CSV / API),
- from an ERP (e.g. an ERPNext multiselect field → Shopware property) through your
  existing product sync.

Once the options of a configured group are assigned to products, they show up as
pills automatically.

## Notes

- "Hide empty categories" is computed with one SQL query per vehicle, cached (default 24 h, configurable) and pre-computed hourly by the scheduled task `nova_fahrzeugauswahl.warm_category_cache`.

- The filter is applied server-side via the session → the page reloads once after a click.
- There is always **exactly one** vehicle active or none (`""` = "All vehicles").
- The cookie value is only accepted server-side if it is a valid UUID (`VehicleSelectionStorage::sanitize`). Invalid values are ignored and never reach the HTTP cache key or the product filter.
- The vehicle description (custom field) is rendered with `sw_sanitize`, not `raw`.
- If the option stored in the session is not (or no longer) part of the currently
  configured groups it is ignored, so the customer never gets stuck in an empty filter.

## ☕ Support

If this project helps you, I'd be happy about a coffee – voluntary, no support obligation,
just a nice gesture:

[![Buy me a coffee](https://img.shields.io/badge/☕_Buy_me_a_coffee-PayPal-0070ba?style=for-the-badge&logo=paypal&logoColor=white)](https://paypal.me/DrdotHouse)

### For companies: support further development

You use this project in your business and would like to support its further development?
Happy to do so **with an invoice** – one-off or **monthly**. On request I'll list you here under
"Supporters" with company name and link.

Please note: I'm not a professional developer – this project is a hobby. Your contribution
therefore isn't a support contract; I can't offer support, response times or warranty. Feature requests
and suggestions for improvement are always welcome, though.

Contact: [info@francetec.de](mailto:info@francetec.de)

#### Supporters

*None yet – I'd be glad to see your name here.*
