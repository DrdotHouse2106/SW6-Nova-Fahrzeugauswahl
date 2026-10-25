# 1.13.0
- More compact bar: "More vehicles" toggle inline at the end of the row on tablet/desktop, slightly tighter pills
- New: strip a word at the end of pill labels (e.g. "Umbau")
- Arrow of the opened "More vehicles" toggle points up

# 1.12.5
- Performance: "only vehicles with products" uses a direct SQL query and is cached (was ~30 s on every uncached page)

# 1.12.4
- Diagnostics: header shows timings of vehicle list and menu filter as HTML comment

# 1.12.3
- Performance: categories with dynamic product groups are checked in the same single query (via product_stream_mapping) instead of one query each

# 1.12.2
- Performance: empty-category check uses a direct SQL query instead of the DAL aggregation (30 s+ per vehicle on large catalogues)
- New: scheduled task pre-computes the check hourly for all vehicles
- New: setting for the cache lifetime in hours (default 24)

# 1.12.1
- Performance: empty-category check uses one aggregation per vehicle (cached 15 min) instead of one query per category

# 1.12.0
- New: collapse filter group 2 behind a "More vehicles" toggle

# 1.11.1
- Vehicle link: redirect to original SEO URL; short names matched at word boundaries when unique

# 1.11.0
- New: select vehicle via link `?fahrzeug=<name|id|alle>` (sets cookie, redirects to clean URL)
- Footer description: part number links were invisible (white footer link color)

# 1.10.1
- Label prefix stripping ignores accents and case
- Footer description rendered as a light card for readability on dark footers

# 1.10.0
- New: "fits all vehicles" option – products with it are shown for every selected vehicle

# 1.9.0
- New: hide categories without products for the selected vehicle in the main and mobile menu

# 1.8.6
- "Only vehicles with products" now checks the option/product assignment directly

# 1.8.5
- Category heading now renders: data is attached to the navigation page instead of the header (rendered as a separate sub-request)

# 1.8.4
- "Only vehicles with products": if the check finds no assignment at all (usually an outdated index), nothing is hidden anymore

# 1.8.3
- New: status HTML comment in the header explaining why the switcher is (not) shown

# 1.8.2
- Fixed: switcher missing when "active sales channels" was decoupled (e.g. empty) on the sales channel level; the global list always counts

# 1.8.1
- Fixed: theme compilation failed after 1.8.0 ("Incompatible units vw and rem")

# 1.8.0
- New: optional category heading including the selected vehicle (e.g. "Spheres for Citroën XM")
- New: CSS class for the vehicle description block (e.g. `nova-fitment`)

# 1.7.1
- New plugin icon in Nova style
- New display name "Nova Vehicle Selector"
- Repository renamed to SW6-Nova-Fahrzeugauswahl
