"""Build reproducible male and female MSKBA player-character assets with MPFB.

Run with Blender, not the system Python:

    /Applications/Blender.app/Contents/MacOS/Blender --background \
        --python build_player_character_assets.py -- --gender all

The script enables the installed MPFB extension, creates each sex from its own
MakeHuman macro phenotype, bakes helper-free meshes on a shared per-sex topology,
adds the application-facing morph targets, and exports editable .blend plus .glb
files next to this directory.
"""

from __future__ import annotations

import argparse
import math
import sys
from pathlib import Path

import bpy


SCRIPT_DIR = Path(__file__).resolve().parent
OUTPUT_DIR = SCRIPT_DIR.parent
ASSET_DIR = SCRIPT_DIR / "assets"

ACCESSORY_ASSETS = {
    "male": {
        "hair": ASSET_DIR / "makehuman-short01" / "short01.mhclo",
        "shoes": ASSET_DIR / "makehuman-shoes05" / "shoes05.mhclo",
        "jersey": (
            ASSET_DIR
            / "makehuman-elvs-male-tankshirt1"
            / "elvs_male_tankshirt1.mhclo"
        ),
        "shorts": (
            ASSET_DIR
            / "makehuman-elvs-male-swim-shorts1"
            / "elvs_male_swim_shorts1.mhclo"
        ),
        "beard": (
            ASSET_DIR
            / "makehuman-beard-sigmund"
            / "grinsegold_beard_sigmund_wip.mhclo"
        ),
    },
    "female": {
        "hair": ASSET_DIR / "makehuman-ponytail01" / "ponytail01.mhclo",
        "shoes": ASSET_DIR / "makehuman-shoes05" / "shoes05.mhclo",
        "jersey": (
            ASSET_DIR
            / "makehuman-elvs-male-tankshirt1"
            / "elvs_male_tankshirt1.mhclo"
        ),
        "shorts": (
            ASSET_DIR
            / "makehuman-elvs-male-swim-shorts1"
            / "elvs_male_swim_shorts1.mhclo"
        ),
    },
}

MORPH_VARIANTS = {
    "male": {
        "body_slim": {"weight": 0.22, "muscle": 0.48, "proportions": 0.56},
        "body_heavy": {"weight": 0.88, "muscle": 0.50, "proportions": 0.48},
        "body_muscular": {"weight": 0.62, "muscle": 0.94, "proportions": 0.56},
        "body_stocky": {"weight": 0.76, "muscle": 0.72, "proportions": 0.30},
    },
    "female": {
        "body_slim": {"weight": 0.16, "muscle": 0.42, "proportions": 0.58},
        "body_heavy": {"weight": 0.78, "muscle": 0.46, "proportions": 0.48},
        "body_muscular": {"weight": 0.48, "muscle": 0.86, "proportions": 0.56},
        "body_stocky": {"weight": 0.64, "muscle": 0.64, "proportions": 0.32},
    },
}

BASE_PHENOTYPE = {
    "male": {"weight": 0.50, "muscle": 0.64, "proportions": 0.52},
    "female": {"weight": 0.34, "muscle": 0.56, "proportions": 0.54},
}


def parse_arguments() -> argparse.Namespace:
    arguments = sys.argv[sys.argv.index("--") + 1 :] if "--" in sys.argv else []
    parser = argparse.ArgumentParser()
    parser.add_argument("--gender", choices=("male", "female", "all"), default="all")
    return parser.parse_args(arguments)


def enable_mpfb():
    module_name = "bl_ext.blender_org.mpfb"
    if module_name not in bpy.context.preferences.addons:
        bpy.ops.preferences.addon_enable(module=module_name)

    from bl_ext.blender_org.mpfb.services import ExportService, HumanService, TargetService

    return ExportService, HumanService, TargetService


def clear_scene() -> None:
    bpy.ops.object.select_all(action="SELECT")
    bpy.ops.object.delete(use_global=False)

    for collection in list(bpy.data.collections):
        if collection.users == 0:
            bpy.data.collections.remove(collection)


def macro_details(target_service, gender: str, overrides: dict[str, float]):
    details = target_service.get_default_macro_info_dict()
    details["gender"] = 1.0 if gender == "male" else 0.0
    details["age"] = 0.50
    details["height"] = 0.50
    details["weight"] = overrides["weight"]
    details["muscle"] = overrides["muscle"]
    details["proportions"] = overrides["proportions"]
    details["cupsize"] = 0.45 if gender == "female" else 0.50
    details["firmness"] = 0.62 if gender == "female" else 0.50
    details["race"] = {"african": 1 / 3, "asian": 1 / 3, "caucasian": 1 / 3}
    return details


def build_clean_human(services, gender: str, phenotype: dict[str, float], name: str):
    export_service, human_service, target_service = services
    human = human_service.create_human(
        mask_helpers=False,
        detailed_helpers=True,
        extra_vertex_groups=True,
        feet_on_ground=False,
        scale=0.1,
        macro_detail_dict=macro_details(target_service, gender, phenotype),
    )
    target_service.bake_targets(human)
    pose_arms_close_to_body(human)
    accessories = {}
    for role, asset_path in ACCESSORY_ASSETS[gender].items():
        if not asset_path.exists():
            raise RuntimeError(f"Required authored asset is missing: {asset_path}")
        accessories[role] = human_service.add_mhclo_asset(
            str(asset_path),
            human,
            asset_type="Hair" if role == "hair" else "Clothes",
            subdiv_levels=0,
            material_type="NONE",
            set_up_rigging=False,
            interpolate_weights=False,
            import_subrig=False,
            import_weights=False,
        )
        if role == "shoes":
            accessories["shoes_accent"] = build_shoe_ankle_support(
                accessories[role], f"{name}_shoes_accent"
            )
        elif role in {"jersey", "shorts"}:
            shape_uniform_asset(accessories[role], role)
    export_service.bake_modifiers_remove_helpers(
        human,
        bake_masks=False,
        bake_subdiv=False,
        remove_helpers=True,
        also_proxy=False,
    )
    human.name = name
    human.data.name = f"{name}_Mesh"
    for polygon in human.data.polygons:
        polygon.use_smooth = True
    human.data.update()
    for role, accessory in accessories.items():
        accessory.name = f"{name}_{role}"
        accessory.data.name = f"{name}_{role}_Mesh"
        for polygon in accessory.data.polygons:
            polygon.use_smooth = True
        accessory.data.update()
    return human, accessories


def shape_uniform_asset(garment: bpy.types.Object, role: str) -> None:
    """Give fitted MHCLO sources a loose sports cut and mark side trim panels."""
    coordinates = [vertex.co for vertex in garment.data.vertices]
    minimum_x = min(point.x for point in coordinates)
    maximum_x = max(point.x for point in coordinates)
    minimum_y = min(point.y for point in coordinates)
    maximum_y = max(point.y for point in coordinates)
    minimum_z = min(point.z for point in coordinates)
    maximum_z = max(point.z for point in coordinates)
    center_x = (minimum_x + maximum_x) / 2
    center_y = (minimum_y + maximum_y) / 2
    height = maximum_z - minimum_z

    for vertex in garment.data.vertices:
        vertical = (vertex.co.z - minimum_z) / height if height else 0.5
        if role == "jersey":
            # A basketball jersey hangs more freely at its hem than at shoulders.
            loosen = 1.055 + (1.0 - vertical) * 0.055
            vertex.co.x = center_x + (vertex.co.x - center_x) * loosen
            vertex.co.y = center_y + (vertex.co.y - center_y) * (loosen + 0.015)
            vertex.co.z = maximum_z - (maximum_z - vertex.co.z) * 1.12
        else:
            # Keep a comfortable gap around hips and thighs without changing waist.
            loosen = 1.025 + (1.0 - vertical) * 0.075
            vertex.co.x = center_x + (vertex.co.x - center_x) * loosen
            vertex.co.y = center_y + (vertex.co.y - center_y) * (loosen + 0.020)

    garment.data.update()
    bpy.context.view_layer.objects.active = garment
    garment.select_set(True)
    subdivision = garment.modifiers.new("UniformSurface", "SUBSURF")
    subdivision.subdivision_type = "CATMULL_CLARK"
    subdivision.levels = 1
    subdivision.render_levels = 1
    bpy.ops.object.modifier_apply(modifier=subdivision.name)
    garment.select_set(False)
    append_uniform_trim_planes(
        garment,
        role,
        center_x,
        minimum_z,
        maximum_z,
    )


def append_uniform_trim_planes(
    garment: bpy.types.Object,
    role: str,
    center_x: float,
    minimum_z: float,
    maximum_z: float,
) -> None:
    coordinates = [tuple(vertex.co) for vertex in garment.data.vertices]
    faces = [tuple(polygon.vertices) for polygon in garment.data.polygons]
    height = maximum_z - minimum_z
    trim_faces = []
    bottom = minimum_z + height * 0.035
    top = maximum_z - height * (0.24 if role == "jersey" else 0.035)
    half_width = max(abs(point[0] - center_x) for point in coordinates)
    stripe_width = half_width * (0.10 if role == "jersey" else 0.08)

    for side in (-1, 1):
        side_points = [point for point in coordinates if (point[0] - center_x) * side > 0]
        bottom_points = [point for point in side_points if abs(point[2] - bottom) < height * 0.08]
        top_points = [point for point in side_points if abs(point[2] - top) < height * 0.08]

        def outside(points):
            return min(point[0] for point in points) if side < 0 else max(point[0] for point in points)

        outer_bottom = outside(bottom_points) - side * 0.012
        outer_top = outside(top_points) - side * 0.012
        for front, reverse in ((True, False), (False, True)):
            y_bottom = (
                min(point[1] for point in bottom_points) - 0.003
                if front
                else max(point[1] for point in bottom_points) + 0.003
            )
            y_top = (
                min(point[1] for point in top_points) - 0.003
                if front
                else max(point[1] for point in top_points) + 0.003
            )
            first = len(coordinates)
            coordinates.extend(
                (
                    (outer_bottom - side * stripe_width, y_bottom, bottom),
                    (outer_bottom, y_bottom, bottom),
                    (outer_top, y_top, top),
                    (outer_top - side * stripe_width, y_top, top),
                )
            )
            face = (first, first + 1, first + 2, first + 3)
            faces.append(tuple(reversed(face)) if reverse else face)
            trim_faces.append(len(faces) - 1)

    mesh = bpy.data.meshes.new(f"{garment.data.name}_WithTrim")
    mesh.from_pydata(coordinates, [], faces)
    mesh.update()
    old_mesh = garment.data
    garment.data = mesh
    bpy.data.meshes.remove(old_mesh)
    for polygon in mesh.polygons:
        polygon.use_smooth = True
    garment["mskba_trim_faces"] = trim_faces


def build_shoe_ankle_support(
    shoes: bpy.types.Object, name: str
) -> bpy.types.Object:
    """Build a short, close-fitting shoe cuff over the unchanged fitted sock."""
    components = connected_vertex_components(shoes.data)
    sock_components = []
    # shoes05 has two large shoe islands and two smaller fitted sock islands.
    for component in components:
        if len(component) <= 300:
            sock_components.append(
                [shoes.data.vertices[index] for index in component]
            )

    sock_components.sort(
        key=lambda vertices: sum(vertex.co.x for vertex in vertices) / len(vertices)
    )
    coordinates = []
    faces = []
    segments = 24
    front_gap_segment = segments * 3 // 4 - 1
    for vertices in sock_components:
        minimum_z = min(vertex.co.z for vertex in vertices)
        maximum_z = max(vertex.co.z for vertex in vertices)
        center_x = sum(vertex.co.x for vertex in vertices) / len(vertices)
        center_y = sum(vertex.co.y for vertex in vertices) / len(vertices)
        half_x = (max(vertex.co.x for vertex in vertices) - min(vertex.co.x for vertex in vertices)) / 2
        half_y = (max(vertex.co.y for vertex in vertices) - min(vertex.co.y for vertex in vertices)) / 2
        bottom_z = minimum_z + 0.034
        collar_height = min(0.050, (maximum_z - minimum_z) * 0.31)
        rings = []

        for progress in (0.0, 0.50, 1.0):
            radius_x = half_x * (1.035 - progress * 0.015)
            radius_y = half_y * (1.030 - progress * 0.015)
            ring = []
            for segment in range(segments):
                angle = 2 * math.pi * (segment + 0.5) / segments
                direction_y = math.sin(angle)
                collar_profile = (
                    max(0.0, direction_y) * 0.005
                    - max(0.0, -direction_y) * 0.006
                ) * progress
                ring.append(len(coordinates))
                coordinates.append(
                    (
                        center_x + math.cos(angle) * radius_x,
                        center_y + math.sin(angle) * radius_y,
                        bottom_z + collar_height * progress + collar_profile,
                    )
                )
            rings.append(ring)

        for lower, upper in zip(rings, rings[1:]):
            for segment in range(segments):
                if segment == front_gap_segment:
                    continue
                following = (segment + 1) % segments
                faces.append((lower[segment], lower[following], upper[following], upper[segment]))

        inner_ring = []
        for segment, outer_index in enumerate(rings[-1]):
            angle = 2 * math.pi * (segment + 0.5) / segments
            inner_ring.append(len(coordinates))
            coordinates.append(
                (
                    center_x + math.cos(angle) * half_x * 1.005,
                    center_y + math.sin(angle) * half_y * 1.005,
                    coordinates[outer_index][2] - 0.0015,
                )
            )
        for segment in range(segments):
            if segment == front_gap_segment:
                continue
            following = (segment + 1) % segments
            faces.append(
                (rings[-1][segment], rings[-1][following], inner_ring[following], inner_ring[segment])
            )

    mesh = bpy.data.meshes.new(f"{name}_Mesh")
    mesh.from_pydata(coordinates, [], faces)
    mesh.update()
    support = bpy.data.objects.new(name, mesh)
    bpy.context.collection.objects.link(support)
    for polygon in mesh.polygons:
        polygon.use_smooth = True
    return support


def connected_vertex_components(mesh: bpy.types.Mesh) -> list[list[int]]:
    neighbours = [set() for _ in mesh.vertices]
    for edge in mesh.edges:
        first, second = edge.vertices
        neighbours[first].add(second)
        neighbours[second].add(first)

    visited = set()
    components = []
    for vertex in mesh.vertices:
        if vertex.index in visited:
            continue
        pending = [vertex.index]
        visited.add(vertex.index)
        component = []
        while pending:
            index = pending.pop()
            component.append(index)
            for neighbour in neighbours[index]:
                if neighbour not in visited:
                    visited.add(neighbour)
                    pending.append(neighbour)
        components.append(component)

    return components


def select_uniform_faces(body: bpy.types.Object) -> dict[str, list[int]]:
    z_values = [vertex.co.z for vertex in body.data.vertices]
    minimum_z = min(z_values)
    height = max(z_values) - minimum_z
    jersey_faces = []
    shorts_faces = []

    for polygon in body.data.polygons:
        points = [body.data.vertices[index].co for index in polygon.vertices]
        center_x = sum(point.x for point in points) / len(points)
        center_y = sum(point.y for point in points) / len(points)
        center_z = sum(point.z for point in points) / len(points)
        relative_z = (center_z - minimum_z) / height

        if 0.525 <= relative_z <= 0.82 and abs(center_x) <= height * 0.115:
            neckline_width = height * (
                0.012 + max(0.0, relative_z - 0.735) / 0.085 * 0.040
            )
            cuts_neckline = (
                relative_z > 0.735
                and center_y < -0.055
                and abs(center_x) < neckline_width
            )
            cuts_armhole = relative_z > 0.72 and abs(center_x) > height * 0.098
            if not cuts_neckline and not cuts_armhole:
                jersey_faces.append(polygon.index)

        if 0.32 <= relative_z <= 0.535:
            shorts_faces.append(polygon.index)

    return {
        "jersey": central_connected_face_regions(body.data, jersey_faces, height * 0.085),
        "shorts": central_connected_face_regions(body.data, shorts_faces, height * 0.105),
    }


def central_connected_face_regions(
    mesh: bpy.types.Mesh, face_indices: list[int], maximum_center_x: float
) -> list[int]:
    remaining = set(face_indices)
    vertex_faces = {}
    for face_index in face_indices:
        for vertex_index in mesh.polygons[face_index].vertices:
            vertex_faces.setdefault(vertex_index, set()).add(face_index)

    regions = []
    while remaining:
        pending = [remaining.pop()]
        region = []
        while pending:
            face_index = pending.pop()
            region.append(face_index)
            neighbours = set()
            for vertex_index in mesh.polygons[face_index].vertices:
                neighbours.update(vertex_faces[vertex_index])
            attached = neighbours & remaining
            remaining.difference_update(attached)
            pending.extend(attached)
        regions.append(region)
    selected = []
    for region in regions:
        region_vertices = {
            vertex_index
            for face_index in region
            for vertex_index in mesh.polygons[face_index].vertices
        }
        center_x = sum(mesh.vertices[index].co.x for index in region_vertices) / len(region_vertices)
        if abs(center_x) <= maximum_center_x:
            selected.extend(region)
    return sorted(selected)


def build_uniform_shell(
    body: bpy.types.Object,
    face_indices: list[int],
    name: str,
    scale_x: float,
    scale_y: float,
    clearance: float,
    trim_threshold: float,
) -> bpy.types.Object:
    used_vertices = sorted(
        {
            vertex_index
            for face_index in face_indices
            for vertex_index in body.data.polygons[face_index].vertices
        }
    )
    remap = {source: target for target, source in enumerate(used_vertices)}
    center_y = (
        min(body.data.vertices[index].co.y for index in used_vertices)
        + max(body.data.vertices[index].co.y for index in used_vertices)
    ) / 2
    coordinates = []
    for index in used_vertices:
        vertex = body.data.vertices[index]
        coordinates.append(
            (
                vertex.co.x * scale_x + vertex.normal.x * clearance,
                center_y + (vertex.co.y - center_y) * scale_y + vertex.normal.y * clearance,
                vertex.co.z + vertex.normal.z * clearance,
            )
        )

    faces = [
        tuple(remap[index] for index in body.data.polygons[face_index].vertices)
        for face_index in face_indices
    ]
    mesh = bpy.data.meshes.new(f"{name}_Mesh")
    mesh.from_pydata(coordinates, [], faces)
    mesh.update()
    shell = bpy.data.objects.new(name, mesh)
    bpy.context.collection.objects.link(shell)

    for polygon, source_face_index in zip(mesh.polygons, face_indices):
        polygon.use_smooth = True
        source = body.data.polygons[source_face_index]
        points = [body.data.vertices[index].co for index in source.vertices]
        center_x = sum(point.x for point in points) / len(points)
        polygon.material_index = 1 if abs(center_x) > trim_threshold else 0
    bpy.context.view_layer.objects.active = shell
    shell.select_set(True)
    subdivision = shell.modifiers.new("UniformSurface", "SUBSURF")
    subdivision.subdivision_type = "CATMULL_CLARK"
    subdivision.levels = 1
    subdivision.render_levels = 1
    bpy.ops.object.modifier_apply(modifier=subdivision.name)
    shell.select_set(False)
    return shell


def add_uniform(
    body: bpy.types.Object,
    accessories: dict[str, bpy.types.Object],
    name: str,
) -> None:
    accessories["jersey"] = build_loose_jersey(body, f"{name}_jersey")
    accessories["shorts"] = build_loose_shorts(body, f"{name}_shorts")


def body_dimensions(body: bpy.types.Object):
    points = [vertex.co for vertex in body.data.vertices]
    minimum_z = min(point.z for point in points)
    maximum_z = max(point.z for point in points)
    return points, minimum_z, maximum_z, maximum_z - minimum_z


def build_loose_jersey(body: bpy.types.Object, name: str) -> bpy.types.Object:
    points, minimum_z, _, height = body_dimensions(body)
    hem_z = minimum_z + height * 0.525
    armhole_z = minimum_z + height * 0.715
    top_z = minimum_z + height * 0.805
    front_neck_z = minimum_z + height * 0.745
    back_neck_z = minimum_z + height * 0.785

    torso = [
        point
        for point in points
        if hem_z - 0.03 <= point.z <= top_z + 0.02
        and abs(point.x) < height * 0.13
    ]
    lower = [point for point in torso if abs(point.z - hem_z) < height * 0.035]
    upper = [point for point in torso if abs(point.z - armhole_z) < height * 0.035]
    hem_half_width = max(max(abs(point.x) for point in lower) + 0.040, height * 0.095)
    arm_half_width = max(max(abs(point.x) for point in upper) + 0.032, hem_half_width * 0.98)
    shoulder_outer = arm_half_width * 0.72
    neck_half_width = arm_half_width * 0.34
    front_y = min(point.y for point in torso) - 0.035
    back_y = max(point.y for point in torso) + 0.035

    outline_front = (
        (-hem_half_width, front_y, hem_z),
        (-arm_half_width, front_y, armhole_z),
        (-shoulder_outer, front_y, top_z),
        (-neck_half_width, front_y, top_z),
        (0.0, front_y, front_neck_z),
        (neck_half_width, front_y, top_z),
        (shoulder_outer, front_y, top_z),
        (arm_half_width, front_y, armhole_z),
        (hem_half_width, front_y, hem_z),
    )
    outline_back = tuple(
        (x, back_y, back_neck_z if index == 4 else z)
        for index, (x, _, z) in enumerate(outline_front)
    )
    coordinates = list(outline_front) + list(outline_back)
    front = list(range(9))
    back = list(range(9, 18))
    faces = [tuple(reversed(front)), tuple(back)]
    trim_faces = []

    for first, second in ((0, 1), (7, 8), (2, 3), (5, 6)):
        faces.append((front[first], front[second], back[second], back[first]))
        if (first, second) in ((0, 1), (7, 8)):
            trim_faces.append(len(faces) - 1)

    mesh = bpy.data.meshes.new(f"{name}_Mesh")
    mesh.from_pydata(coordinates, [], faces)
    mesh.update()
    jersey = bpy.data.objects.new(name, mesh)
    bpy.context.collection.objects.link(jersey)
    jersey["mskba_trim_faces"] = trim_faces
    for polygon in mesh.polygons:
        polygon.use_smooth = True
    return jersey


def build_loose_shorts(body: bpy.types.Object, name: str) -> bpy.types.Object:
    points, minimum_z, _, height = body_dimensions(body)
    bottom_z = minimum_z + height * 0.325
    top_z = minimum_z + height * 0.535
    thigh_points = [
        point
        for point in points
        if bottom_z <= point.z <= top_z and abs(point.x) < height * 0.14
    ]
    coordinates = []
    faces = []
    trim_faces = []
    segments = 24

    for side in (-1, 1):
        side_points = [point for point in thigh_points if point.x * side > 0]
        minimum_x = min(point.x for point in side_points)
        maximum_x = max(point.x for point in side_points)
        center_x = (minimum_x + maximum_x) / 2
        half_x = (maximum_x - minimum_x) / 2 + 0.030
        minimum_y = min(point.y for point in side_points)
        maximum_y = max(point.y for point in side_points)
        center_y = (minimum_y + maximum_y) / 2
        half_y = (maximum_y - minimum_y) / 2 + 0.032
        rings = []

        for progress, z in ((0.0, bottom_z), (0.52, (bottom_z + top_z) / 2), (1.0, top_z)):
            ring = []
            radius_x = half_x * (1.00 + progress * 0.05)
            radius_y = half_y * (1.00 + progress * 0.04)
            for segment in range(segments):
                angle = 2 * math.pi * segment / segments
                ring.append(len(coordinates))
                coordinates.append(
                    (
                        center_x + math.cos(angle) * radius_x,
                        center_y + math.sin(angle) * radius_y,
                        z,
                    )
                )
            rings.append(ring)

        for lower_ring, upper_ring in zip(rings, rings[1:]):
            for segment in range(segments):
                following = (segment + 1) % segments
                faces.append(
                    (
                        lower_ring[segment],
                        lower_ring[following],
                        upper_ring[following],
                        upper_ring[segment],
                    )
                )
                middle_angle = 2 * math.pi * (segment + 0.5) / segments
                if side * math.cos(middle_angle) > 0.82:
                    trim_faces.append(len(faces) - 1)

    waist_points = [
        point
        for point in points
        if abs(point.z - top_z) < height * 0.035 and abs(point.x) < height * 0.13
    ]
    waist_x = max(abs(point.x) for point in waist_points) + 0.032
    waist_y_min = min(point.y for point in waist_points)
    waist_y_max = max(point.y for point in waist_points)
    waist_center_y = (waist_y_min + waist_y_max) / 2
    waist_y = (waist_y_max - waist_y_min) / 2 + 0.028
    waistband = []
    for z in (top_z - 0.022, top_z + 0.006):
        ring = []
        for segment in range(segments):
            angle = 2 * math.pi * segment / segments
            ring.append(len(coordinates))
            coordinates.append(
                (
                    math.cos(angle) * waist_x,
                    waist_center_y + math.sin(angle) * waist_y,
                    z,
                )
            )
        waistband.append(ring)
    for segment in range(segments):
        following = (segment + 1) % segments
        faces.append(
            (waistband[0][segment], waistband[0][following], waistband[1][following], waistband[1][segment])
        )

    mesh = bpy.data.meshes.new(f"{name}_Mesh")
    mesh.from_pydata(coordinates, [], faces)
    mesh.update()
    shorts = bpy.data.objects.new(name, mesh)
    bpy.context.collection.objects.link(shorts)
    shorts["mskba_trim_faces"] = trim_faces
    for polygon in mesh.polygons:
        polygon.use_smooth = True
    return shorts


def pose_arms_close_to_body(human: bpy.types.Object) -> None:
    """Move the unrigged MPFB A-pose closer to a neutral standing pose."""
    z_values = [vertex.co.z for vertex in human.data.vertices]
    minimum_z = min(z_values)
    height = max(z_values) - minimum_z
    shoulder_z = minimum_z + height * 0.81
    shoulder_x = height * 0.145
    blend_start_x = height * 0.10
    blend_end_x = height * 0.17
    minimum_arm_z = minimum_z + height * 0.46
    maximum_arm_z = minimum_z + height * 0.89
    lower_arm_guard_z = minimum_z + height * 0.66
    angle = math.radians(32)
    backward_angle = math.radians(22)

    for vertex in human.data.vertices:
        x, y, z = vertex.co
        side = 1 if x >= 0 else -1
        distance_x = abs(x)
        if distance_x <= blend_start_x or not minimum_arm_z <= z <= maximum_arm_z:
            continue
        if z < lower_arm_guard_z and distance_x < blend_end_x:
            continue

        weight = min(1.0, (distance_x - blend_start_x) / (blend_end_x - blend_start_x))
        signed_angle = angle * side * weight
        cosine = math.cos(signed_angle)
        sine = math.sin(signed_angle)
        relative_x = x - shoulder_x * side
        relative_z = z - shoulder_z
        rotated_x = relative_x * cosine + relative_z * sine
        rotated_z = -relative_x * sine + relative_z * cosine
        backward_cosine = math.cos(backward_angle * weight)
        backward_sine = math.sin(backward_angle * weight)
        rotated_y = y * backward_cosine - rotated_z * backward_sine
        rotated_z = y * backward_sine + rotated_z * backward_cosine
        vertex.co = (
            shoulder_x * side + rotated_x,
            rotated_y,
            shoulder_z + rotated_z,
        )
    human.data.update()


def add_shape_keys(base, variants: dict[str, bpy.types.Object]) -> None:
    base.shape_key_add(name="Basis", from_mix=False)

    for morph_name, variant in variants.items():
        if len(variant.data.vertices) != len(base.data.vertices):
            raise RuntimeError(
                f"Topology mismatch for {morph_name}: "
                f"{len(variant.data.vertices)} != {len(base.data.vertices)}"
            )

        shape_key = base.shape_key_add(name=morph_name, from_mix=False)
        coordinates = [0.0] * (len(variant.data.vertices) * 3)
        variant.data.vertices.foreach_get("co", coordinates)
        shape_key.data.foreach_set("co", coordinates)
        shape_key.slider_min = 0.0
        shape_key.slider_max = 1.0


def material(name: str, color: tuple[float, float, float, float], roughness: float):
    result = bpy.data.materials.get(name) or bpy.data.materials.new(name)
    result.diffuse_color = color
    result.use_nodes = True
    shader = result.node_tree.nodes.get("Principled BSDF")
    shader.inputs["Base Color"].default_value = color
    shader.inputs["Roughness"].default_value = roughness
    shader.inputs["Metallic"].default_value = 0.0
    return result


def add_head_anchor(body: bpy.types.Object):
    coordinates = [vertex.co for vertex in body.data.vertices]
    min_z = min(point.z for point in coordinates)
    max_z = max(point.z for point in coordinates)
    height = max_z - min_z
    head = [point for point in coordinates if point.z > min_z + height * 0.82]

    anchor = bpy.data.objects.new("Head", None)
    anchor.empty_display_type = "SPHERE"
    anchor.empty_display_size = 0.025
    anchor.location = (
        sum(point.x for point in head) / len(head),
        sum(point.y for point in head) / len(head),
        min_z + height * 0.91,
    )
    bpy.context.collection.objects.link(anchor)
    return anchor


def prepare_accessory(
    accessory: bpy.types.Object,
    variant_accessories: dict[str, bpy.types.Object],
    node_name: str,
    accessory_material: bpy.types.Material,
    player_root: bpy.types.Object,
) -> None:
    root = bpy.data.objects.new(node_name, None)
    bpy.context.collection.objects.link(root)
    root.parent = player_root

    accessory.name = f"{node_name}_Mesh"
    accessory.data.name = f"{node_name}_Geometry"
    accessory.parent = root
    accessory.data.materials.clear()
    accessory.data.materials.append(accessory_material)
    add_shape_keys(accessory, variant_accessories)


def prepare_shoes(
    shoes: bpy.types.Object,
    variant_shoes: dict[str, bpy.types.Object],
    primary_material: bpy.types.Material,
    sock_material: bpy.types.Material,
    player_root: bpy.types.Object,
) -> None:
    root = bpy.data.objects.new("MSKBA_Shoes_Primary", None)
    bpy.context.collection.objects.link(root)
    root.parent = player_root

    shoes.name = "MSKBA_Shoes_Primary_Mesh"
    shoes.data.name = "MSKBA_Shoes_Primary_Geometry"
    shoes.parent = root
    shoes.data.materials.clear()
    shoes.data.materials.append(primary_material)
    shoes.data.materials.append(sock_material)
    sock_vertices = {
        index
        for component in connected_vertex_components(shoes.data)
        if len(component) <= 300
        for index in component
    }
    for polygon in shoes.data.polygons:
        polygon.material_index = (
            1 if all(index in sock_vertices for index in polygon.vertices) else 0
        )
    add_shape_keys(shoes, variant_shoes)


def prepare_uniform_part(
    garment: bpy.types.Object,
    variant_garments: dict[str, bpy.types.Object],
    node_name: str,
    base_material: bpy.types.Material,
    trim_material: bpy.types.Material,
    player_root: bpy.types.Object,
) -> None:
    root = bpy.data.objects.new(node_name, None)
    bpy.context.collection.objects.link(root)
    root.parent = player_root
    garment.name = f"{node_name}_Mesh"
    garment.data.name = f"{node_name}_Geometry"
    garment.parent = root
    garment.data.materials.clear()
    garment.data.materials.append(base_material)
    garment.data.materials.append(trim_material)
    trim_faces = set(garment.get("mskba_trim_faces", []))
    for polygon in garment.data.polygons:
        polygon.material_index = 1 if polygon.index in trim_faces else 0
    add_shape_keys(garment, variant_garments)


def prepare_root(body, variants, accessories, variant_accessories, gender: str):
    title = gender.capitalize()
    root = bpy.data.objects.new(f"MSKBA_Player_{title}", None)
    bpy.context.collection.objects.link(root)
    body.name = "Body"
    body.data.name = f"MSKBA_{title}_Body_Mesh"
    body.parent = root

    skin = material("MSKBA_Skin", (0.51, 0.28, 0.16, 1.0), 0.72)
    hair = material("MSKBA_Hair", (0.035, 0.025, 0.020, 1.0), 0.88)
    beard = material("MSKBA_Beard", (0.035, 0.025, 0.020, 1.0), 0.94)
    shoes_primary = material("MSKBA_Shoes_Primary", (0.92, 0.92, 0.90, 1.0), 0.42)
    shoes_accent = material("MSKBA_Shoes_Accent", (0.92, 0.92, 0.90, 1.0), 0.46)
    socks = material("MSKBA_Socks", (0.66, 0.68, 0.69, 1.0), 0.78)
    uniform_base = material("MSKBA_Uniform_Base", (0.30, 0.32, 0.34, 1.0), 0.38)
    uniform_trim = material("MSKBA_Uniform_Trim", (0.012, 0.014, 0.016, 1.0), 0.54)
    body.data.materials.clear()
    body.data.materials.append(skin)

    hair_node = "MSKBA_Hair_Male_Fade" if gender == "male" else "MSKBA_Hair_Female_Ponytail"
    prepare_accessory(
        accessories["hair"],
        {name: assets["hair"] for name, assets in variant_accessories.items()},
        hair_node,
        hair,
        root,
    )
    prepare_shoes(
        accessories["shoes"],
        {name: assets["shoes"] for name, assets in variant_accessories.items()},
        shoes_primary,
        socks,
        root,
    )
    prepare_accessory(
        accessories["shoes_accent"],
        {name: assets["shoes_accent"] for name, assets in variant_accessories.items()},
        "MSKBA_Shoes_Accent",
        shoes_accent,
        root,
    )
    prepare_uniform_part(
        accessories["jersey"],
        {name: assets["jersey"] for name, assets in variant_accessories.items()},
        "MSKBA_Uniform_Jersey",
        uniform_base,
        uniform_trim,
        root,
    )
    prepare_uniform_part(
        accessories["shorts"],
        {name: assets["shorts"] for name, assets in variant_accessories.items()},
        "MSKBA_Uniform_Shorts",
        uniform_base,
        uniform_trim,
        root,
    )
    if gender == "male":
        prepare_accessory(
            accessories["beard"],
            {name: assets["beard"] for name, assets in variant_accessories.items()},
            "MSKBA_Beard_Short",
            beard,
            root,
        )

    head_anchor = add_head_anchor(body)
    head_anchor.parent = root

    root["mskba_asset_contract"] = 1
    root["mskba_gender"] = gender
    root["mskba_units"] = "meters"
    return root


def delete_variant_objects(variants, variant_accessories) -> None:
    for accessories in variant_accessories.values():
        for accessory in accessories.values():
            bpy.data.objects.remove(accessory, do_unlink=True)
    for variant in variants.values():
        bpy.data.objects.remove(variant, do_unlink=True)


def export_character(root, gender: str) -> None:
    title = gender.capitalize()
    for obj in bpy.context.scene.objects:
        obj.select_set(False)
    root.select_set(True)
    for child in root.children_recursive:
        child.select_set(True)
    bpy.context.view_layer.objects.active = root

    blend_path = OUTPUT_DIR / f"mskba-{gender}-player-source.blend"
    glb_path = OUTPUT_DIR / f"mskba-{gender}-player-v1.glb"
    bpy.ops.wm.save_as_mainfile(filepath=str(blend_path), check_existing=False)
    bpy.ops.export_scene.gltf(
        filepath=str(glb_path),
        export_format="GLB",
        use_selection=True,
        export_yup=True,
        export_morph=True,
        export_morph_normal=False,
        export_materials="EXPORT",
        export_cameras=False,
        export_lights=False,
        export_extras=True,
    )
    print(f"Built {title} player: {glb_path}")


def build_gender(services, gender: str) -> None:
    clear_scene()
    body, accessories = build_clean_human(
        services, gender, BASE_PHENOTYPE[gender], f"{gender}_base"
    )
    variants = {}
    variant_accessories = {}
    for morph_name, phenotype in MORPH_VARIANTS[gender].items():
        variants[morph_name], variant_accessories[morph_name] = build_clean_human(
            services, gender, phenotype, f"{gender}_{morph_name}"
        )
    add_shape_keys(body, variants)
    root = prepare_root(body, variants, accessories, variant_accessories, gender)
    delete_variant_objects(variants, variant_accessories)
    export_character(root, gender)


def main() -> None:
    arguments = parse_arguments()
    bpy.context.preferences.filepaths.save_version = 0
    services = enable_mpfb()
    genders = ("male", "female") if arguments.gender == "all" else (arguments.gender,)
    for gender in genders:
        build_gender(services, gender)


if __name__ == "__main__":
    main()
