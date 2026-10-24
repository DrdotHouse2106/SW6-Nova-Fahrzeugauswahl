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
