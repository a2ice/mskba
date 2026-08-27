"""Render a deterministic front preview of an exported player-character GLB."""

from __future__ import annotations

import argparse
import math
import sys
from pathlib import Path

import bpy
from mathutils import Vector


def parse_arguments() -> argparse.Namespace:
    arguments = sys.argv[sys.argv.index("--") + 1 :] if "--" in sys.argv else []
    parser = argparse.ArgumentParser()
    parser.add_argument("--model", type=Path, required=True)
    parser.add_argument("--output", type=Path, required=True)
    parser.add_argument("--morph", default="")
    parser.add_argument("--hair", action="store_true")
    parser.add_argument("--beard", action="store_true")
    parser.add_argument("--azimuth", type=float, default=0.0)
    return parser.parse_args(arguments)


def look_at(camera, target: Vector) -> None:
    camera.rotation_euler = (target - camera.location).to_track_quat("-Z", "Y").to_euler()


def main() -> None:
    arguments = parse_arguments()
    bpy.ops.object.select_all(action="SELECT")
    bpy.ops.object.delete(use_global=False)
    bpy.ops.import_scene.gltf(filepath=str(arguments.model.resolve()))

    renderable = []
    for obj in bpy.context.scene.objects:
        if obj.type != "MESH":
            continue
        is_hair = "MSKBA_Hair_" in obj.name
        is_beard = "MSKBA_Beard_" in obj.name
        obj.hide_render = (is_hair and not arguments.hair) or (is_beard and not arguments.beard)
        if not obj.hide_render:
            renderable.append(obj)
        if arguments.morph and obj.data.shape_keys:
            key = obj.data.shape_keys.key_blocks.get(arguments.morph)
            if key:
                key.value = 1.0

    points = []
    for obj in renderable:
        points.extend(obj.matrix_world @ Vector(corner) for corner in obj.bound_box)
    minimum = Vector((min(p.x for p in points), min(p.y for p in points), min(p.z for p in points)))
    maximum = Vector((max(p.x for p in points), max(p.y for p in points), max(p.z for p in points)))
    center = (minimum + maximum) / 2
    height = maximum.z - minimum.z

    camera_data = bpy.data.cameras.new("PreviewCamera")
    camera = bpy.data.objects.new("PreviewCamera", camera_data)
    bpy.context.collection.objects.link(camera)
    azimuth = math.radians(arguments.azimuth)
    distance = height * 2.4
    camera.location = (
        center.x + math.sin(azimuth) * distance,
        center.y - math.cos(azimuth) * distance,
        center.z,
    )
    camera.data.type = "ORTHO"
    camera.data.ortho_scale = height * 1.10
    look_at(camera, center)
    bpy.context.scene.camera = camera

    world = bpy.context.scene.world or bpy.data.worlds.new("PreviewWorld")
    bpy.context.scene.world = world
    world.color = (0.018, 0.022, 0.019)

    for name, energy, rotation in (
        ("Key", 1100, (math.radians(38), 0, math.radians(-32))),
        ("Fill", 650, (math.radians(55), 0, math.radians(145))),
    ):
        light_data = bpy.data.lights.new(name, "AREA")
        light_data.energy = energy
        light_data.shape = "DISK"
        light_data.size = height * 1.2
        light = bpy.data.objects.new(name, light_data)
        bpy.context.collection.objects.link(light)
        light.location = center + Vector((0, -height, height * 0.7))
        light.rotation_euler = rotation

    scene = bpy.context.scene
    scene.render.engine = "BLENDER_WORKBENCH"
    scene.display.shading.light = "STUDIO"
    scene.display.shading.color_type = "MATERIAL"
    scene.display.shading.show_shadows = True
    scene.display.shading.show_cavity = True
    scene.render.resolution_x = 640
    scene.render.resolution_y = 900
    scene.render.resolution_percentage = 100
    scene.render.image_settings.file_format = "PNG"
    scene.render.film_transparent = False
    scene.render.filepath = str(arguments.output.resolve())
    scene.view_settings.look = "AgX - Medium High Contrast"
    bpy.ops.render.render(write_still=True)


if __name__ == "__main__":
    main()
