#!/usr/bin/env python3
"""
Regenerate the expected values the PHP tests compare against, using the
reference grade script (pass its path; it is not part of this repo).

    python3 tests/fixtures/generate_reference.py ~/projects/claude-files/grade.py

Writes tests/fixtures/house-grade-pillow.json (exact per-pixel grade of a
synthetic image) and, when tests/fixtures/featured-fixture.jpg exists, prints
the mean RGB of the full pipeline for FeaturedImagePipelineTest.
"""
import importlib.util, json, os, random, sys
from PIL import Image, ImageStat

here = os.path.dirname(os.path.abspath(__file__))
spec = importlib.util.spec_from_file_location("grade", sys.argv[1])
grade = importlib.util.module_from_spec(spec)
spec.loader.exec_module(grade)

cases = []
for seed, bias in ((1, 0), (2, -70), (3, 80)):
    random.seed(seed)
    pixels = [tuple(max(0, min(255, random.randrange(256) + bias)) for _ in range(3)) for _ in range(48 * 32)]
    im = Image.new("RGB", (48, 32))
    im.putdata(pixels)
    out = grade.mute_and_tint(grade.normalize_exposure(im))
    cases.append({
        "input": [c for p in pixels for c in p],
        "expected": [c for p in out.getdata() for c in p],
    })

with open(os.path.join(here, "house-grade-pillow.json"), "w") as f:
    json.dump(cases, f)
print("wrote house-grade-pillow.json")

fixture = os.path.join(here, "featured-fixture.jpg")
if os.path.exists(fixture):
    im = Image.open(fixture).convert("RGB")
    out = grade.mute_and_tint(grade.normalize_exposure(grade.crop_to_aspect(im, grade.OUT_W, grade.OUT_H)))
    print("featured-fixture.jpg mean RGB:", [round(m, 2) for m in ImageStat.Stat(out).mean])
