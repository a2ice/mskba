"""Bake one natural arms-down pose into every male body morph target.

Each target is rigged independently so shoulder and elbow joint centres follow
that target's anatomy.  The deformed coordinates are then written back into a
rig-free mesh with the original shape-key names.  This lets the web preview
interpolate height/BMI/body-type targets without carrying a runtime skeleton.
"""

from __future__ import annotations

import importlib.util
from pathlib import Path

import bpy
from mathutils import Matrix, Vector


SCRIPT_DIR = Path(__file__).resolve().parent
BUILDER_PATH = SCRIPT_DIR / "build_player_character_assets.py"
LAB_PATH = SCRIPT_DIR / "build_body_morph_lab.py"
OUTPUT = SCRIPT_DIR.parent / "mskba-male-player-body-posed-morph-lab.blend"
RUNTIME_OUTPUT = SCRIPT_DIR.parent / "mskba-male-player-body-posed-preview.glb"


def load_builder():
    spec = importlib.util.spec_from_file_location("mskba_player_builder", BUILDER_PATH)
    module = importlib.util.module_from_spec(spec)
    assert spec.loader is not None
    spec.loader.exec_module(module)
    return module


def load_lab():
    spec = importlib.util.spec_from_file_location("mskba_body_morph_lab", LAB_PATH)
    module = importlib.util.module_from_spec(spec)
    assert spec.loader is not None
    spec.loader.exec_module(module)
    return module


def rotate_bone_towards(
    rig: bpy.types.Object,
    bone_name: str,
    desired_direction: Vector,
) -> None:
    """Rotate a pose bone around its current head in armature space."""

    bpy.context.view_layer.update()
    bone = rig.pose.bones[bone_name]
    head = bone.head.copy()
    current_direction = (bone.tail - bone.head).normalized()
    rotation = current_direction.rotation_difference(desired_direction.normalized())
    around_head = (
        Matrix.Translation(head)
        @ rotation.to_matrix().to_4x4()
        @ Matrix.Translation(-head)
    )
    bone.matrix = around_head @ bone.matrix
    bpy.context.view_layer.update()


def lower_arms(rig: bpy.types.Object) -> None:
    """Set a relaxed standing pose with slight abduction and elbow softness."""

    for side in ("l", "r"):
        upper = rig.pose.bones[f"upperarm_{side}"]
        outward = 1.0 if (upper.tail.x - upper.head.x) > 0 else -1.0
        rotate_bone_towards(
            rig,
            f"upperarm_{side}",
            Vector((outward * 0.10, -0.055, -1.0)),
        )
        rotate_bone_towards(
            rig,
            f"lowerarm_{side}",
            Vector((outward * 0.025, -0.095, -1.0)),
        )


def posed_coordinates(
    key_name: str,
    services,
    lab,
) -> list[Vector]:
    """Build, fit, pose and strip one target, then return body coordinates."""

    export_service, human_service, _target_service = services
    if key_name == "Basis":
        values = lab.BASE
        details = None
    else:
        values = {**lab.VARIANTS, **lab.METRIC_GRID}[key_name]
        details = lab.DETAILS.get(key_name)

    review = lab.create_body(
        services,
        values,
        f"PoseBake_{key_name}",
        details,
        remove_helpers=False,
    )
    rig = human_service.add_builtin_rig(review, "game_engine", import_weights=True)
    lower_arms(rig)

    bpy.context.view_layer.objects.active = review
    review.select_set(True)
    rig.select_set(False)
    for modifier in list(review.modifiers):
        if modifier.type == "ARMATURE":
            bpy.ops.object.modifier_apply(modifier=modifier.name)

    world = review.matrix_world.copy()
    review.parent = None
    review.matrix_world = world
    rig_data = rig.data
    bpy.data.objects.remove(rig, do_unlink=True)
    bpy.data.armatures.remove(rig_data)

    export_service.bake_modifiers_remove_helpers(
        review,
        bake_masks=False,
        bake_subdiv=False,
        remove_helpers=True,
        also_proxy=False,
    )
    coordinates = [vertex.co.copy() for vertex in review.data.vertices]

    review_data = review.data
    bpy.data.objects.remove(review, do_unlink=True)
    bpy.data.meshes.remove(review_data)
    return coordinates


def write_coordinates(key, coordinates: list[Vector]) -> None:
    if len(key.data) != len(coordinates):
        raise RuntimeError(
            f"Vertex count changed while posing {key.name}: "
            f"{len(key.data)} != {len(coordinates)}"
        )
    for point, coordinate in zip(key.data, coordinates, strict=True):
        point.co = coordinate


def main() -> None:
    builder = load_builder()
    lab = load_lab()
    services = builder.enable_mpfb()
    bpy.context.preferences.filepaths.save_version = 0

    body = bpy.data.objects["Body"]
    key_names = [key.name for key in body.data.shape_keys.key_blocks]
    posed = {
        key_name: posed_coordinates(key_name, services, lab)
        for key_name in key_names
    }

    for key in body.data.shape_keys.key_blocks:
        write_coordinates(key, posed[key.name])
        key.value = 0.0

    body["mskba_pose"] = "relaxed_arms_down_v1"
    body["mskba_pose_bake"] = "per_morph_fitted_game_engine_rig"
    body.data.update()

    bpy.context.view_layer.objects.active = body
    body.select_set(True)
    bpy.ops.wm.save_as_mainfile(filepath=str(OUTPUT))
    bpy.ops.export_scene.gltf(
        filepath=str(RUNTIME_OUTPUT),
        export_format="GLB",
        use_selection=True,
        export_morph=True,
        export_morph_normal=False,
        export_morph_tangent=False,
        export_materials="EXPORT",
        export_yup=True,
    )


main()
