"""Build a clean male MPFB body with independent anatomy review morphs.

This file is deliberately separate from the production character builder.  It
keeps the authored A-pose and contains no garments or accessories, so anatomy
and morph combinations can be approved before rigging and export.
"""

from __future__ import annotations

import importlib.util
from pathlib import Path

import bpy


SCRIPT_DIR = Path(__file__).resolve().parent
BUILDER_PATH = SCRIPT_DIR / "build_player_character_assets.py"
OUTPUT = SCRIPT_DIR.parent / "mskba-male-player-body-morph-lab.blend"

BASE = {
    "height": 0.50,
    "weight": 0.40,
    "muscle": 0.70,
    "proportions": 0.56,
}

VARIANTS = {
    "height_short": {**BASE, "height": 0.00},
    "height_tall": {**BASE, "height": 1.00},
    "body_slim": {**BASE, "weight": 0.16, "muscle": 0.42, "proportions": 0.62},
    "body_fat": {**BASE, "weight": 0.98, "muscle": 0.38, "proportions": 0.48},
    "body_athletic": {**BASE, "weight": 0.38, "muscle": 0.84, "proportions": 0.60},
    "body_muscle": {**BASE, "weight": 0.58, "muscle": 1.00, "proportions": 0.58},
    "body_stocky": {**BASE, "weight": 0.70, "muscle": 0.74, "proportions": 0.16},
}

# A small two-dimensional metric lattice. Runtime height/weight controls will
# interpolate between neighbouring nodes by height and BMI, preserving the
# interaction between stature and body mass instead of adding independent
# vertex deltas or uniformly scaling the whole character.
HEIGHT_NODES = {
    150: 0.00,
    185: 0.50,
    220: 1.00,
}
BMI_NODES = {
    17: {"weight": 0.12, "muscle": 0.42, "proportions": 0.62},
    23: {"weight": 0.40, "muscle": 0.70, "proportions": 0.56},
    38: {"weight": 0.98, "muscle": 0.30, "proportions": 0.48},
}
METRIC_GRID = {
    f"metric_h{height}_bmi{bmi}": {
        **BASE,
        "height": height_macro,
        **composition,
    }
    for height, height_macro in HEIGHT_NODES.items()
    for bmi, composition in BMI_NODES.items()
    if not (height == 185 and bmi == 23)
}

ATHLETIC_DETAILS = {
    "torso-vshape-incr": 0.34,
    "torso-muscle-pectoral-incr": 0.34,
    "torso-muscle-dorsi-incr": 0.32,
    "measure-shoulder-dist-incr": 0.12,
    "l-upperarm-shoulder-muscle-incr": 0.38,
    "r-upperarm-shoulder-muscle-incr": 0.38,
    "l-upperarm-muscle-incr": 0.34,
    "r-upperarm-muscle-incr": 0.34,
    "l-lowerarm-muscle-incr": 0.22,
    "r-lowerarm-muscle-incr": 0.22,
    "l-upperleg-muscle-incr": 0.20,
    "r-upperleg-muscle-incr": 0.20,
    "l-lowerleg-muscle-incr": 0.16,
    "r-lowerleg-muscle-incr": 0.16,
}

DETAILS = {
    "body_athletic": ATHLETIC_DETAILS,
    "body_muscle": {
        name: min(weight * 2.15, 0.82)
        for name, weight in ATHLETIC_DETAILS.items()
    },
}


def load_builder():
    spec = importlib.util.spec_from_file_location("mskba_player_builder", BUILDER_PATH)
    module = importlib.util.module_from_spec(spec)
    assert spec.loader is not None
    spec.loader.exec_module(module)
    return module


def macro_details(target_service, values: dict[str, float]):
    details = target_service.get_default_macro_info_dict()
    details.update(
        {
            "gender": 1.0,
            "age": 0.50,
            "height": values["height"],
            "weight": values["weight"],
            "muscle": values["muscle"],
            "proportions": values["proportions"],
            "cupsize": 0.50,
            "firmness": 0.50,
            "race": {"african": 1 / 3, "asian": 1 / 3, "caucasian": 1 / 3},
        }
    )
    return details


def create_body(
    services,
    values: dict[str, float],
    name: str,
    details: dict[str, float] | None = None,
    *,
    remove_helpers: bool = True,
):
    export_service, human_service, target_service = services
    body = human_service.create_human(
        mask_helpers=False,
        detailed_helpers=True,
        extra_vertex_groups=True,
        feet_on_ground=False,
        scale=0.1,
        macro_detail_dict=macro_details(target_service, values),
    )
    for target_name, weight in (details or {}).items():
        target_path = target_service.target_full_path(target_name)
        if not target_path:
            raise RuntimeError(f"MPFB detail target is missing: {target_name}")
        target_service.load_target(body, target_path, weight=weight, name=target_name)
    target_service.bake_targets(body)
    if remove_helpers:
        export_service.bake_modifiers_remove_helpers(
            body,
            bake_masks=False,
            bake_subdiv=False,
            remove_helpers=True,
            also_proxy=False,
        )
    body.name = name
    body.data.name = f"{name}_Mesh"
    for polygon in body.data.polygons:
        polygon.use_smooth = True
    body.data.update()
    return body


def main() -> None:
    builder = load_builder()
    bpy.context.preferences.filepaths.save_version = 0
    services = builder.enable_mpfb()
    builder.clear_scene()

    body = create_body(services, BASE, "Body")
    variants = {
        name: create_body(services, values, f"Review_{name}", DETAILS.get(name))
        for name, values in {**VARIANTS, **METRIC_GRID}.items()
    }
    builder.add_shape_keys(body, variants)

    for variant in variants.values():
        bpy.data.objects.remove(variant, do_unlink=True)

    body.data.materials.clear()
    body.data.materials.append(
        builder.material("Body_Skin_Review", (0.48, 0.285, 0.19, 1.0), 0.72)
    )
    body["mskba_body_lab"] = True
    body["mskba_gender"] = "male"
    body["mskba_pose"] = "mpfb_apose"

    bpy.context.view_layer.objects.active = body
    body.select_set(True)
    bpy.ops.wm.save_as_mainfile(filepath=str(OUTPUT))


if __name__ == "__main__":
    main()
