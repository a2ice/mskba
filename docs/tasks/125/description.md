# Task 125 — Blender-authored male GLB spike

## Goal

Validate the Blender/MPFB -> GLB -> Three.js pipeline on the real Player Character metric stage before investing in a production-quality authored character.

## Scope

- Keep the existing literal 200 cm x 250 cm Three.js stage contract.
- Load the Blender/MPFB male base for male profiles instead of the procedural male mesh.
- Normalize the authored mesh to the requested `height_cm` using a single uniform XYZ scale.
- Preserve the model's authored width/depth so the horizontal 200 cm stage remains physically meaningful.
- Place the lowest visible point at the exact scene floor datum (0 cm).
- Keep pointer rotation, lighting, height marker and human-readable load error handling.
- Female rendering remains on the existing temporary renderer in this spike.
- Do not connect weight/body type to authored morph targets yet; this iteration only validates the asset pipeline and metric presentation.
- The spike asset is a transport-optimized static copy of the Blender export, bundled with the application as compressed payload chunks. It is not the final production mesh.

## Metric contract

- visible scene X = 200 cm (`-1..1` metres)
- visible scene Y = 250 cm (`0..2.5` metres)
- authored model feet = 0 cm
- model top = `height_cm`
- width and depth use the exact same uniform scale as height
- no BMI/body-type X/Z distortion is applied in this spike

## Acceptance

- A 185 cm profile renders the Blender male base at 185 cm on the 0–250 cm vertical scale.
- The viewport still represents exactly 200 cm in X and 250 cm in Y.
- Changing height changes the GLB uniformly without changing its proportions.
- The authored model's visible width is directly comparable to the 200 cm horizontal stage.
- No procedural male mesh is mounted for male profiles.
- The model payload is served with the application rather than fetched from a third-party model host.
