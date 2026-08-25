# Task 123 — Male chest profile and stage display offset

## Goal
Refine the existing procedural male Player Character after production visual review without expanding the feature scope.

## Changes
- Flatten the male upper-torso depth profile so the chest reads as a male ribcage/pectorals rather than breast-like volume.
- Keep body-type differences (`slim`, `athletic`, `large`, etc.) in width/mass while reducing forward chest bulge.
- Bring the jersey front closer to the flatter torso so clothing does not recreate the same rounded chest effect.
- Lift the procedural male model visually by 5 cm, which is 2% of the literal 250 cm viewport.
- Apply the same display offset to the model shadow and height marker so they remain visually aligned.
- Keep the anatomical metric contract inside the model unchanged: `floorY = 0`, `crownY = 1.79`; the 5 cm shift is presentation-only.

## Acceptance
- Male torso has no breast-like silhouette from the front or at modest yaw.
- Athletic and large presets remain visibly broader than slim without restoring the breast-like bulge.
- Jersey does not visibly balloon forward over the chest.
- Male model appears about 2% of the stage height above the zero line.
- Height marker follows the visually shifted crown.
- Existing Player Character persistence and business logic are untouched.
