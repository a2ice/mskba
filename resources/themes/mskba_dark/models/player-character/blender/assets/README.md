# MakeHuman source assets

The source meshes in this directory are used by the reproducible Blender/MPFB
player-character build. They are intentionally kept as MHCLO + OBJ pairs; the
runtime application consumes only the generated GLB files.

| Local directory | Upstream asset | Source pack | License |
| --- | --- | --- | --- |
| `makehuman-short01` | `short01` | MakeHuman system assets | CC0 1.0 |
| `makehuman-ponytail01` | `ponytail01` | MakeHuman system assets | CC0 1.0 |
| `makehuman-shoes05` | `shoes05` | MakeHuman system assets | CC0 1.0 |
| `makehuman-beard-sigmund` | `grinsegold_beard_sigmund_wip` by grinsegold | Bodyparts 05 | CC0 1.0 |
| `makehuman-elvs-male-tankshirt1` | `elvs_male_tankshirt1` by Elvaerwyn | Shirts 03 | CC BY |
| `makehuman-elvs-male-swim-shorts1` | `elvs_male_swim_shorts1` by Elvaerwyn | Pants 03 | CC BY |

Sources:

- <https://static.makehumancommunity.org/assets/assetpacks/makehuman_system_assets.html>
- <https://static.makehumancommunity.org/assets/assetpacks/bodyparts05.html>
- <https://static.makehumancommunity.org/assets/assetpacks/shirts03.html>
- <https://static.makehumancommunity.org/assets/assetpacks/pants03.html>
- <https://static.makehumancommunity.org/about/license.html>

The copied files are unmodified upstream source assets. Their fitted geometry,
stable node names, morph targets and MSKBA materials are produced by
`../build_player_character_assets.py`.
