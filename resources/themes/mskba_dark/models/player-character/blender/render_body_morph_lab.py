"""Render repeatable front/side reviews of the body morph lab."""

from __future__ import annotations

from pathlib import Path

import bpy
from mathutils import Vector


OUTPUT = Path("/tmp/mskba-body-morph-review")
STATES = {
    "neutral": {},
    # 150 cm / 140 kg: BMI is above the supported anatomy lattice and clamps
    # to its heaviest node.
    "short-heavy": {"metric_h150_bmi38": 1.0},
    # 220 cm / 140 kg: BMI 28.9, interpolated between BMI 23 and 38.
    "tall-heavy": {
        "metric_h220_bmi23": 0.607,
        "metric_h220_bmi38": 0.393,
    },
    # 150 cm / 40 kg: BMI 17.8, close to the lean node but not emaciated.
    "short-light": {
        "metric_h150_bmi17": 0.87,
        "metric_h150_bmi23": 0.13,
    },
    # 220 cm / 40 kg: BMI is below the supported lattice and clamps lean.
    "tall-light": {"metric_h220_bmi17": 1.0},
    "athletic": {"body_athletic": 1.0},
    "muscular": {"body_muscle": 1.0},
    "slim": {"body_slim": 1.0},
    "stocky": {"body_stocky": 1.0},
}


def look_at(obj: bpy.types.Object, target: Vector) -> None:
    obj.rotation_euler = (target - obj.location).to_track_quat("-Z", "Y").to_euler()


def add_area(name: str, location: tuple[float, float, float], energy: float, size: float) -> None:
    data = bpy.data.lights.new(name, "AREA")
    data.energy = energy
    data.shape = "DISK"
    data.size = size
    light = bpy.data.objects.new(name, data)
    bpy.context.scene.collection.objects.link(light)
    light.location = location
    look_at(light, Vector((0.0, 0.0, 0.10)))


def set_state(body: bpy.types.Object, weights: dict[str, float]) -> None:
    for key in body.data.shape_keys.key_blocks:
        if key.name != "Basis":
            key.value = weights.get(key.name, 0.0)
    bpy.context.view_layer.update()


def body_frame(body: bpy.types.Object) -> tuple[Vector, float]:
    evaluated = body.evaluated_get(bpy.context.evaluated_depsgraph_get())
    corners = [evaluated.matrix_world @ Vector(corner) for corner in evaluated.bound_box]
    lower = Vector(tuple(min(corner[axis] for corner in corners) for axis in range(3)))
    upper = Vector(tuple(max(corner[axis] for corner in corners) for axis in range(3)))
    return (lower + upper) * 0.5, upper.z - lower.z


def main() -> None:
    OUTPUT.mkdir(parents=True, exist_ok=True)
    scene = bpy.context.scene
    scene.render.engine = "BLENDER_EEVEE_NEXT"
    scene.render.resolution_x = 520
    scene.render.resolution_y = 760
    scene.render.resolution_percentage = 100
    scene.render.image_settings.file_format = "PNG"
    scene.world.color = (0.035, 0.04, 0.045)

    body = bpy.data.objects["Body"]
    camera_data = bpy.data.cameras.new("MorphReviewCamera")
    camera_data.lens = 62
    camera = bpy.data.objects.new("MorphReviewCamera", camera_data)
    scene.collection.objects.link(camera)
    scene.camera = camera

    add_area("MorphKey", (-2.4, -2.8, 2.7), 900, 4.0)
    add_area("MorphFill", (2.5, -1.6, 1.7), 500, 3.0)
    add_area("MorphRim", (0.5, 2.7, 2.3), 700, 3.0)

    for state_name, weights in STATES.items():
        set_state(body, weights)
        target, body_height = body_frame(body)
        distance = body_height * 1.55
        views = {
            "front": target + Vector((0.0, -distance, 0.0)),
            "side": target + Vector((-distance, 0.0, 0.0)),
            "rear": target + Vector((0.0, distance, 0.0)),
        }
        for view_name, location in views.items():
            camera.location = location
            look_at(camera, target)
            scene.render.filepath = str(OUTPUT / f"{state_name}-{view_name}.png")
            bpy.ops.render.render(write_still=True)


main()
